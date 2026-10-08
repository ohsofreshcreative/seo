<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Serp\RankChange;
use OsfSeo\Strategy\Decision\ActionClassifier;
use OsfSeo\Strategy\Decision\ConfidenceModel;
use OsfSeo\Strategy\Decision\ConfidenceResult;
use OsfSeo\Strategy\Decision\Decision;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Decision\PriorityResult;
use OsfSeo\Strategy\Decision\TopicFacts;
use OsfSeo\Strategy\Serp\SerpFreshness;
use OsfSeo\Strategy\Target\SlugMatcher;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Topics\ConflictSignal;
use OsfSeo\Strategy\Topics\KeywordSignals;
use OsfSeo\Strategy\Topics\UrlConflictDetector;

/**
 * Syntetyczne sygnały kandydatów Strategii (dowody w kształcie `strategy_keywords.evidence`, wersja 4) do testów rdzenia — bez bazy
 * i bez prawdziwych danych. Domena projektu: example.pl.
 */
final class StrategyFakes
{
	public const NOW = '2026-01-15 12:00:00';

	private static int $sequence = 0;

	public static function now(): DateTimeImmutable
	{
		return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
	}

	/** Chwila pomiaru sprzed `$days` dni. */
	public static function daysAgo(int $days): string
	{
		return self::now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
	}

	/**
	 * @param array<string, mixed> $o opcje: id, volume, kd, cpc, intent, core, manual, pinned, topic, gsc (null = brak danych GSC projektu),
	 *   serp, opportunities, discovery, gap, content_gap
	 */
	public static function keyword(string $keyword, array $o = []): KeywordSignals
	{
		$id = (int) ($o['id'] ?? ++self::$sequence + 1000);
		$evidence = ['v' => 4, 'keyword' => $keyword, 'sources' => $o['sources'] ?? ['gsc']];
		$evidence['gsc'] = array_key_exists('gsc', $o) ? ($o['gsc'] === null ? null : self::gsc($o['gsc'])) : self::gsc([]);

		if (isset($o['serp'])) {
			$evidence['serp'] = self::serp($o['serp']);
		}

		if (isset($o['opportunities'])) {
			$direct = array_map(static fn (array $item): array => $item + ['id' => 'OPP' . (++self::$sequence), 'status' => 'new', 'priority' => 50, 'confidence' => 2, 'page' => null, 'link' => 'member', 'members_complete' => true], $o['opportunities']);
			$evidence['opportunity'] = ['direct' => $direct, 'context' => [], 'direct_open' => count($direct), 'direct_total' => count($direct), 'context_total' => 0];
		}

		foreach (['discovery', 'gap', 'content_gap'] as $section) {
			if (isset($o[$section])) {
				$evidence[$section] = $o[$section];
			}
		}

		$checked = $evidence['serp']['intel']['checked_at'] ?? null;

		return new KeywordSignals(
			$id,
			'KW' . str_pad((string) $id, 24, '0', STR_PAD_LEFT),
			$id,
			$keyword,
			$o['core'] ?? null,
			(bool) ($o['manual'] ?? false),
			0,
			$o['tier'] ?? 6,
			array_key_exists('volume', $o) ? $o['volume'] : 500,
			array_key_exists('kd', $o) ? $o['kd'] : 30,
			array_key_exists('cpc', $o) ? $o['cpc'] : 2.0,
			array_key_exists('intent', $o) ? $o['intent'] : 'commercial',
			$o['topic'] ?? null,
			$o['pinned'] ?? null,
			$evidence,
			is_string($checked) ? SerpFreshness::of($checked, self::now()) : null,
			md5($keyword),
		);
	}

	/**
	 * @param array<string, mixed> $o impressions, clicks, position, pages: lista [url, wyświetlenia] (udziały liczone automatycznie)
	 * @return array<string, mixed>
	 */
	public static function gsc(array $o): array
	{
		$pages = $o['pages'] ?? [];
		$total = array_sum(array_column($pages, 1));
		$impressions = (int) ($o['impressions'] ?? $total);

		return [
			'impressions' => $impressions,
			'clicks' => (int) ($o['clicks'] ?? 0),
			'position' => $impressions > 0 ? ($o['position'] ?? 12.0) : null,
			'variants' => $impressions > 0 ? 1 : 0,
			'pages' => array_map(static fn (array $page): array => [
				'url' => $page[0],
				'impressions' => $page[1],
				'clicks' => $page[2] ?? 0,
				'share' => $total > 0 ? round($page[1] / $total, 4) : null,
			], $pages),
			'pages_total' => count($pages),
		];
	}

	/**
	 * Monitorowana fraza z najnowszym zgodnym pomiarem.
	 *
	 * @param array<string, mixed> $o checked_at, found, rank, url, prev_rank, prev_url, change, change_value, decline, top10, profile,
	 *   competitors_top10, spell, same (pomiar monitorowania = zgodny pomiar)
	 * @return array<string, mixed>
	 */
	public static function serp(array $o): array
	{
		$checked = $o['checked_at'] ?? self::daysAgo(2);
		$freshness = SerpFreshness::of($checked, self::now());
		$found = (bool) ($o['found'] ?? isset($o['rank']));
		$rank = $found ? ($o['rank'] ?? null) : null;
		$url = $found ? ($o['url'] ?? null) : null;
		$change = $o['change'] ?? (isset($o['prev_rank']) ? ($rank === null ? RankChange::LEFT : ($rank > $o['prev_rank'] ? RankChange::DOWN : ($rank < $o['prev_rank'] ? RankChange::UP : RankChange::SAME))) : RankChange::NEW);
		$value = $o['change_value'] ?? (isset($o['prev_rank'], $rank) ? $o['prev_rank'] - $rank : null);

		return [
			'id' => 'TRK' . (++self::$sequence),
			'status' => 'active',
			'source' => 'manual',
			'checked_at' => ($o['same'] ?? true) ? $checked : self::daysAgo(1),
			'found' => $found,
			'rank' => $rank,
			'rank_absolute' => $rank,
			'url' => $url,
			'depth' => 100,
			'featured' => false,
			'prev_rank' => $o['prev_rank'] ?? null,
			'prev_url' => $o['prev_url'] ?? null,
			'change' => $change,
			'change_value' => $value,
			'top10' => null,
			'bands' => [],
			'decline' => (bool) ($o['decline'] ?? ($value !== null && $value <= -5) || $change === RankChange::LEFT),
			'intel' => [
				'snapshot' => 'SNP' . self::$sequence,
				'checked_at' => $checked,
				'freshness' => $freshness,
				'tracking' => 'active',
				'context' => ['device' => 'desktop', 'depth' => 100],
				'profile' => SerpFreshness::usableForClassification($freshness) ? ($o['profile'] ?? self::profile('mixed', 'low', 'commercial', 'low')) : null,
				'project' => SerpFreshness::allowsProjectRank($freshness)
					? ['found' => $found, 'rank' => $rank, 'url' => $url, 'featured' => false, 'top10' => $o['top10'] ?? ($found && $rank !== null && $rank <= 10 ? [['url' => $url, 'rank' => $rank]] : [])]
					: null,
				'competitors' => SerpFreshness::usableForClassification($freshness) ? ['top10' => $o['competitors_top10'] ?? 0, 'top20' => $o['competitors_top10'] ?? 0, 'best' => null] : null,
				'spell' => $o['spell'] ?? null,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function profile(string $shape, string $shapeConfidence, string $intent = 'commercial', string $intentConfidence = 'medium'): array
	{
		return [
			'shape' => $shape,
			'shape_share' => 0.6,
			'shape_confidence' => $shapeConfidence,
			'intent_signal' => $intent,
			'intent_confidence' => $intentConfidence,
			'top10' => ['organic' => 10, 'domains' => 9, 'top_domain_results' => 2, 'home' => 1, 'shapes' => []],
			'top20' => ['organic' => 20, 'domains' => 17, 'shapes' => []],
			'features' => [],
		];
	}

	/**
	 * Pełna ścieżka rdzenia dla tematu z podanych fraz (lider pierwszy): strona docelowa fraz i tematu, konflikty, fakty, działanie,
	 * pewność i priorytet.
	 *
	 * @param list<KeywordSignals> $members
	 * @return array{facts: TopicFacts, decision: Decision, confidence: ConfidenceResult, priority: PriorityResult}
	 */
	public static function evaluate(array $members, ?string $manualUrl = null, bool $manualNone = false, bool $gscComplete = true, bool $pageIndexComplete = false, ?SlugMatcher $slugs = null): array
	{
		$resolver = new TargetPageResolver();
		$targets = array_map(static fn (KeywordSignals $member) => $resolver->forKeyword($member, $slugs), $members);
		$target = $resolver->forTopic($targets, $manualUrl, $manualNone);
		$conflicts = [];

		foreach ($members as $member) {
			$conflicts = [...$conflicts, ...(new UrlConflictDetector())->detect($member, $target)];
		}

		$facts = new TopicFacts($members[0], $members, $target, ConflictSignal::sorted($conflicts), $gscComplete, $pageIndexComplete);
		$decision = (new ActionClassifier())->classify($facts);
		$confidence = (new ConfidenceModel())->evaluate($facts, $decision);
		$priority = (new PriorityModel())->score($facts, $decision, $confidence);

		return ['facts' => $facts, 'decision' => $decision, 'confidence' => $confidence, 'priority' => $priority];
	}
}
