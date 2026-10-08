<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Analysis;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Strategy\Serp\SerpFreshness;

/**
 * Ocena gotowości analizy (D106) na źródle kontekstu AI tematu (`AiTopicContextBuilder::source`) — czysta funkcja zapisanego stanu:
 * decyzja Strategii, strona docelowa, frazy i rynek, pomiar SERP, snapshoty strony projektu i stron konkurencji z Page Intelligence.
 * Zero żądań: brakujące dane są wskazywane jako jawne komendy do rozważenia (`hints`), nigdy pobierane.
 */
final class ReadinessEvaluator
{
	/** Pobranie strony i pomiar SERP dalej od siebie niż tyle dni — dwie różne chwile. */
	public const DATES_DIFFER_DAYS = 7;

	private const USABLE_QUALITY = ['good', 'partial'];

	/**
	 * @param array<string, mixed> $source
	 */
	public function evaluate(array $source, string $type, bool $explicit = false): Readiness
	{
		$ctx = (array) ($source['context'] ?? []);
		$action = is_string($ctx['decision']['action'] ?? null) ? $ctx['decision']['action'] : null;
		$rule = ActionCompatibility::rule($action, $type);
		$now = $source['now'] instanceof DateTimeImmutable ? $source['now'] : new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$target = (array) ($ctx['target'] ?? []);
		$targetUrl = is_string($target['url'] ?? null) && $target['url'] !== '' ? $target['url'] : null;
		$pages = (array) ($source['pages'] ?? []);
		$project = is_array($pages['project'] ?? null) ? $pages['project'] : null;
		$competitors = array_values(array_filter((array) ($pages['competitors'] ?? []), 'is_array'));
		$keywords = array_values(array_filter((array) ($ctx['keywords'] ?? []), 'is_array'));
		$detail = is_array($source['serp']['detail'] ?? null) ? $source['serp']['detail'] : null;
		$serpFreshness = $detail['freshness'] ?? null;
		$reasons = [];
		$limitations = [];
		$notes = [];
		$hints = [];
		$topicId = is_string($ctx['topic']['id'] ?? null) ? $ctx['topic']['id'] : '<topic>';

		[$pageReason, $pageLimitations] = $this->projectPage($project);
		[$usable, $competitorLimitations] = $this->competitors($competitors, $now);
		$requirements = [];

		foreach ($rule['requires'] as $requirement) {
			$requirements[$requirement] = match ($requirement) {
				'target_page' => $targetUrl !== null,
				'page_snapshot' => $pageReason === null,
				'competitor_snapshot' => $usable > 0,
				'keywords' => $keywords !== [],
				default => false,
			};
		}

		// Blokady (zgodność ze Strategią).
		if ($action === null) {
			$reasons[] = 'no_strategy_decision';
		} elseif (($ctx['topic']['active'] ?? true) === false) {
			$reasons[] = 'topic_inactive';
		} elseif ($rule['mode'] === ActionCompatibility::BLOCKED) {
			$reasons[] = 'action_not_supported';
		} elseif ($rule['mode'] === ActionCompatibility::EXPLICIT && ! $explicit) {
			$reasons[] = 'explicit_choice_required';
		}

		if ($reasons !== []) {
			return new Readiness($type, Readiness::BLOCKED, $action, $rule['mode'], $rule['constraints'], $reasons, [], [], $requirements);
		}

		// Wymagane dane i ograniczenia według typu.
		if ($type === AnalysisType::PAGE_OPTIMIZATION || $type === AnalysisType::CONTENT_GAP) {
			if ($type === AnalysisType::PAGE_OPTIMIZATION && $targetUrl === null) {
				$reasons[] = 'target_page_unknown';
			} elseif ($pageReason !== null) {
				$reasons[] = $pageReason;
				$hints[] = 'wp osf-seo pages:fetch --project=<project> --topic=' . $topicId . ' (explicit fetch of the target page)';
			}

			array_push($limitations, ...$pageLimitations);
		}

		if ($type === AnalysisType::CONTENT_GAP) {
			if ($usable === 0) {
				$reasons[] = 'no_competitor_snapshots';
				$hints[] = 'wp osf-seo pages:fetch --project=<project> --keyword=<keyword> --ranks=1,2,3 (explicit fetch of organic results of a stored SERP measurement; requires a SERP measurement)';
			} else {
				array_push($limitations, ...$competitorLimitations);
			}
		} elseif ($usable === 0) {
			$notes[] = 'no_competitor_pages';
		}

		if ($type === AnalysisType::NEW_PAGE_BRIEF) {
			if ($keywords === []) {
				$reasons[] = 'no_keywords';
			}

			$intent = array_filter($keywords, static fn (array $keyword): bool => ($keyword['market']['intent'] ?? null) !== null) !== []
				|| (SerpFreshness::usableForClassification($serpFreshness) && ($detail['profile']['intent_signal'] ?? null) !== null);

			if (! $intent) {
				$limitations[] = 'intent_unknown';
			}

			if ($keywords !== [] && array_filter($keywords, static fn (array $keyword): bool => ($keyword['market']['volume'] ?? null) !== null) === []) {
				$limitations[] = 'no_market_data';
			}

			if (in_array($serpFreshness, [SerpFreshness::STALE, SerpFreshness::EXPIRED], true)) {
				$limitations[] = 'serp_' . $serpFreshness;
			}

			if (in_array($target['state'] ?? null, ['confirmed', 'probable'], true)) {
				$limitations[] = 'known_page_may_cover_topic';
			}

			$notes[] = ($target['manual_none'] ?? false) === true ? 'missing_page_confirmed_manually' : 'candidate_page';
		} elseif (in_array($serpFreshness, [SerpFreshness::STALE, SerpFreshness::EXPIRED], true)) {
			$notes[] = 'serp_' . $serpFreshness;
		}

		if ($detail === null) {
			$notes[] = 'no_serp_measurement';
		}

		if (! is_array($ctx['gsc'] ?? null)) {
			$notes[] = 'no_gsc_data';
		}

		if (($source['page_index_complete'] ?? false) !== true) {
			$notes[] = 'page_index_incomplete';
		}

		$state = match (true) {
			$reasons !== [] => Readiness::INSUFFICIENT,
			$limitations !== [] => Readiness::PARTIAL,
			default => Readiness::READY,
		};

		return new Readiness($type, $state, $action, $rule['mode'], $rule['constraints'], array_values(array_unique($reasons)), array_values(array_unique($limitations)), array_values(array_unique($notes)), $requirements, array_values(array_unique($hints)));
	}

	/**
	 * Snapshot strony projektu: powód braku (albo null) i ograniczenia.
	 *
	 * @param array<string, mixed>|null $page `topicEvidence()['project']`
	 * @return array{0: ?string, 1: list<string>}
	 */
	private function projectPage(?array $page): array
	{
		$snapshot = is_array($page['snapshot'] ?? null) ? $page['snapshot'] : null;

		if ($snapshot === null) {
			return [($page['cache'] ?? null) === 'failed' ? 'page_fetch_failed' : 'page_not_fetched', []];
		}

		$quality = $snapshot['content_quality'] ?? null;

		if ($quality === 'empty') {
			return ['page_content_empty', []];
		}

		$limitations = [];

		if ($quality === 'incomplete') {
			$limitations[] = 'page_content_incomplete';
		} elseif ($quality === 'partial') {
			$limitations[] = 'page_content_partial';
		}

		if (($page['cache'] ?? null) === 'stale') {
			$limitations[] = 'page_snapshot_stale';
		}

		if (($page['target']['last_error'] ?? null) !== null) {
			$limitations[] = 'page_last_fetch_failed';
		}

		return [null, $limitations];
	}

	/**
	 * Strony konkurencji: liczba przydatnych snapshotów i ograniczenia porównania.
	 *
	 * @param list<array<string, mixed>> $competitors
	 * @return array{0: int, 1: list<string>}
	 */
	private function competitors(array $competitors, DateTimeImmutable $now): array
	{
		$usable = 0;
		$limitations = [];

		foreach ($competitors as $page) {
			$snapshot = is_array($page['snapshot'] ?? null) ? $page['snapshot'] : null;

			if (! self::usable($snapshot)) {
				$limitations[] = 'competitor_content_incomplete';

				continue;
			}

			$usable++;
			$measured = is_string($page['serp']['checked_at'] ?? null) ? $page['serp']['checked_at'] : null;
			$freshness = SerpFreshness::of($measured, $now);

			if ($freshness === SerpFreshness::STALE || $freshness === SerpFreshness::EXPIRED) {
				$limitations[] = 'competitor_serp_' . $freshness;
			}

			if ($measured !== null && is_string($snapshot['fetched_at'] ?? null) && self::daysApart($measured, $snapshot['fetched_at']) > self::DATES_DIFFER_DAYS) {
				$limitations[] = 'serp_and_page_dates_differ';
			}
		}

		if ($usable === 1) {
			$limitations[] = 'single_competitor';
		}

		return [$usable, array_values(array_unique($limitations))];
	}

	/**
	 * @param array<string, mixed>|null $snapshot
	 */
	public static function usable(?array $snapshot): bool
	{
		return $snapshot !== null && in_array($snapshot['content_quality'] ?? null, self::USABLE_QUALITY, true) && (int) ($snapshot['http_status'] ?? 0) === 200;
	}

	private static function daysApart(string $a, string $b): float
	{
		return abs((int) strtotime($a . ' UTC') - (int) strtotime($b . ' UTC')) / 86400;
	}
}
