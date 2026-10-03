<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Strategy\Decision\ActionClassifier;
use OsfSeo\Strategy\Decision\ConfidenceModel;
use OsfSeo\Strategy\Decision\Decision;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Decision\TopicFacts;
use OsfSeo\Strategy\Serp\SerpIntelligence;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\Target\ProjectPageIndex;
use OsfSeo\Strategy\Target\SlugMatcher;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Target\TargetResolution;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Przeliczenie tematów Strategii (faza C — docs/ARCHITECTURE.md, sekcja 15.13) — wyłącznie lokalnie, bez żadnego żądania do API,
 * po zapisie kandydatów w tym samym przeliczeniu (pod blokadą projektu):
 *
 * 1. strona docelowa i sygnały konfliktu URL każdej frazy,
 * 2. grupowanie wokół liderów (przypięcia, ta sama strona docelowa, silny overlap SERP) i sugestie „możliwej grupy”,
 * 3. stabilne ID (głosowanie ≥ 50%, remis → poprzedni lider, przypięcia pierwsze),
 * 4. strona docelowa tematu (z ręcznym wskazaniem), działanie, pewność, Priorytet Strategii, pasmo Pozycji SERP, odcisk dowodów,
 * 5. zapis tylko zmian (kolumny wyliczane — nigdy status pracy) i zdarzenia wyłącznie istotnych zmian.
 */
final class TopicRefresher
{
	/** Wersja reguł rdzenia (w analizie tematu i odcisku dowodów). */
	public const VERSION = 1;

	public function __construct(
		private readonly TopicInputLoader $loader,
		private readonly TopicRepository $topics,
		private readonly TopicEventRepository $events,
		private readonly TargetPageResolver $resolver,
		private readonly UrlConflictDetector $detector,
		private readonly TopicClusterer $clusterer,
		private readonly TopicIdentity $identity,
		private readonly ActionClassifier $classifier,
		private readonly ConfidenceModel $confidence,
		private readonly PriorityModel $priority,
		private readonly SerpIntelligence $intelligence,
		private readonly ProjectPageIndex $pages,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param bool $gscComplete oba zbiory fraz GSC pokrywają okno
	 * @return array{topics: int, inserted: int, updated: int, unchanged: int, deactivated: int, events: int}
	 */
	public function refresh(SourceScope $scope, bool $gscComplete, string $now): array
	{
		$projectId = $scope->projectId;
		$keywords = $this->loader->load($projectId, $scope->market, $this->clock->now());
		$byId = [];

		foreach ($keywords as $keyword) {
			$byId[$keyword->marketKeywordId] = $keyword;
		}

		$previous = $this->topics->previous($projectId);
		$slugs = new SlugMatcher($this->pages->pages($projectId));
		$targets = [];

		foreach ($keywords as $keyword) {
			$targets[$keyword->marketKeywordId] = $this->resolver->forKeyword($keyword, $slugs);
		}

		$measured = array_values(array_map(static fn (KeywordSignals $keyword): int => $keyword->marketKeywordId, array_filter($keywords, static fn (KeywordSignals $keyword): bool => $keyword->serpUsable())));
		$sides = $measured === [] ? null : $this->intelligence->sides($projectId, $scope->market, $measured, ! $scope->dryRun);
		$overlaps = new OverlapIndex($sides['sides'] ?? [], $sides['ubiquity']['domains'] ?? [], $sides['ubiquity']['known'] ?? false);

		// Przypięcie do tematu, którego już nie ma — fraza grupuje się automatycznie.
		$keywords = array_map(static fn (KeywordSignals $keyword): KeywordSignals => $keyword->pinnedTopicId !== null && ! isset($previous[$keyword->pinnedTopicId])
			? self::unpinned($keyword)
			: $keyword, $keywords);
		['groups' => $groups, 'blocked' => $blocked] = $this->clusterer->cluster($keywords, $targets, $overlaps);
		$identity = $this->identity->assign($groups, array_map(static fn (array $topic): array => [
			'leader' => $topic['leader'],
			'members' => $topic['members'],
			'active' => $topic['active'],
		], $previous), TopicIdentity::pins($keywords));
		$groups = $identity['groups'];
		$this->clusterer->suggest($groups, $keywords, $targets, $overlaps);
		$publicIds = [];

		foreach ($groups as $index => $group) {
			$topicId = $identity['ids'][$index];
			$publicIds[$index] = $topicId === null ? Ulid::generate() : (string) $previous[$topicId]['public_id'];
		}

		$rows = [];
		$assignments = [];
		$events = [];
		$newEvents = [];

		foreach ($groups as $index => $group) {
			$topicId = $identity['ids'][$index];
			$prev = $topicId === null ? null : $previous[$topicId];
			$members = array_map(static fn (int $id): KeywordSignals => $byId[$id], $group->members);
			$target = $this->resolver->forTopic(
				array_map(static fn (int $id): TargetResolution => $targets[$id], $group->members),
				$prev['manual_target_url'] ?? null,
				(bool) ($prev['manual_no_page'] ?? false),
			);
			$conflicts = [];

			foreach ($members as $member) {
				$conflicts = [...$conflicts, ...$this->detector->detect($member, $target)];
			}

			foreach ($blocked as $pair) {
				if (in_array($pair['a'], $group->members, true) || in_array($pair['b'], $group->members, true)) {
					$conflicts[] = new ConflictSignal(
						ConflictSignal::OVERLAP_TARGETS,
						$pair['confirmed'] ? ConflictSignal::STRONG : ConflictSignal::MODERATE,
						array_values(array_filter($pair['targets'], 'is_string')),
						$byId[in_array($pair['a'], $group->members, true) ? $pair['a'] : $pair['b']]->keyword,
						['with' => $byId[in_array($pair['a'], $group->members, true) ? $pair['b'] : $pair['a']]->keyword],
					);
				}
			}

			$conflicts = self::uniqueConflicts($conflicts);
			$facts = new TopicFacts($members[0], $members, $target, $conflicts, $gscComplete, $this->pages->complete());
			$decision = $this->classifier->classify($facts);
			$confidence = $this->confidence->evaluate($facts, $decision);
			$priority = $this->priority->score($facts, $decision, $confidence, $scope->config->windowDays());
			$serpBand = self::serpBand($facts);
			$suggestions = [];

			foreach ($group->suggestions as $other => $signals) {
				$suggestions[] = ['topic' => $publicIds[$other], 'label' => $byId[$groups[$other]->leader]->keyword, 'signals' => $signals];
			}

			$analysis = [
				'v' => self::VERSION,
				'leader' => $members[0]->publicId,
				'members' => array_map(static fn (KeywordSignals $member): array => [
					'id' => $member->publicId,
					'keyword' => $member->keyword,
					'basis' => $group->basis[$member->marketKeywordId],
					'target' => ['state' => $targets[$member->marketKeywordId]->state->value, 'url' => $targets[$member->marketKeywordId]->url],
				], $members),
				'target' => $target->toArray(),
				'decision' => $decision->toArray(),
				'confidence' => $confidence->toArray(),
				'priority' => $priority->toArray(),
				'conflicts' => array_map(static fn (ConflictSignal $signal): array => $signal->toArray(), $conflicts),
				'suggestions' => $suggestions,
				'serp' => self::serpReference($facts, $serpBand),
				'facts' => self::factsSummary($facts),
			];
			$hash = md5((string) json_encode([$analysis, array_map(static fn (KeywordSignals $member): ?string => $member->factsHash, $members)]));
			$changedAfterDecision = self::decisionChanged($prev, $decision, $target);
			$row = [
				'id' => $topicId,
				'public_id' => $publicIds[$index],
				'leader_market_keyword_id' => $members[0]->marketKeywordId,
				'label' => $members[0]->keyword,
				'keywords_count' => count($members),
				'demand' => $facts->demand(),
				'action' => $decision->action->value,
				'action_reason' => $decision->reason,
				'confidence' => $confidence->points,
				'confidence_level' => $confidence->level,
				'priority' => $priority->value,
				'target_state' => $target->state->value,
				'target_url' => $target->url,
				'serp_band' => $serpBand,
				'decision_changed' => $changedAfterDecision ? 1 : 0,
				'analysis' => (string) json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
				'evidence_hash' => $hash,
				'unchanged' => $prev !== null && $prev['active'] && $prev['evidence_hash'] === $hash && $prev['decision_changed'] === $changedAfterDecision,
			];
			$rows[] = $row;

			foreach ($members as $member) {
				$assignments[$member->id] = ['topic' => $publicIds[$index], 'state' => $targets[$member->marketKeywordId]->state->value, 'url' => $targets[$member->marketKeywordId]->url];
			}

			if ($prev === null || $prev['inactive_reason'] === 'pending') {
				$newEvents[$publicIds[$index]][] = ['type' => TopicEvent::CREATED, 'to' => $decision->action->value, 'data' => array_filter([
					'keyword' => $members[0]->keyword,
					'split_from' => isset($identity['splits'][$index]) ? $previous[$identity['splits'][$index]]['public_id'] : null,
				])];

				if (isset($identity['splits'][$index]) && ! isset($identity['retired'][$identity['splits'][$index]])) {
					$events[] = ['topic_id' => $identity['splits'][$index], 'type' => TopicEvent::SPLIT, 'to' => $publicIds[$index], 'data' => ['keyword' => $members[0]->keyword]];
				}
			} else {
				foreach (self::changes($prev, $row, $members[0], $byId) as $event) {
					$events[] = ['topic_id' => (int) $topicId] + $event;
				}
			}
		}

		$urlIds = $this->topics->urlIds(array_values(array_filter([
			...array_column($rows, 'target_url'),
			...array_column($assignments, 'url'),
		], 'is_string')));

		foreach ($rows as $key => $row) {
			$rows[$key]['target_url_id'] = is_string($row['target_url']) ? ($urlIds[$row['target_url']] ?? null) : null;
		}

		$keywordRows = [];

		foreach ($assignments as $id => $assignment) {
			$keywordRows[$id] = ['topic' => $assignment['topic'], 'state' => $assignment['state'], 'url_id' => is_string($assignment['url']) ? ($urlIds[$assignment['url']] ?? null) : null];
		}

		foreach ($identity['retired'] as $topicId => $retire) {
			$events[] = match ($retire['reason']) {
				TopicIdentity::REASON_MERGED => ['topic_id' => $topicId, 'type' => TopicEvent::MERGED, 'to' => $retire['into'] === null ? null : $previous[$retire['into']]['public_id']],
				TopicIdentity::REASON_SPLIT => ['topic_id' => $topicId, 'type' => TopicEvent::SPLIT],
				default => ['topic_id' => $topicId, 'type' => TopicEvent::DEACTIVATED, 'data' => ['reason' => $retire['reason']]],
			};
		}

		$report = $this->topics->save($projectId, $rows, $identity['retired'], $keywordRows, $this->topics->keywordAssignments($projectId), $now);

		foreach ($newEvents as $publicId => $list) {
			foreach ($list as $event) {
				if (isset($report['ids'][$publicId])) {
					$events[] = ['topic_id' => $report['ids'][$publicId]] + $event;
				}
			}
		}

		$count = $this->events->add($projectId, $events, $now);

		return [
			'topics' => count($rows),
			'inserted' => $report['inserted'],
			'updated' => $report['updated'],
			'unchanged' => $report['unchanged'],
			'deactivated' => $report['deactivated'],
			'events' => $count,
		];
	}

	/** Pasmo Pozycji SERP tematu (fraza odniesienia ze świeżym pomiarem; nieaktualny pomiar — `stale`, brak — null). */
	public static function serpBand(TopicFacts $facts): ?string
	{
		$primary = $facts->primarySerp();

		if ($primary === null) {
			return $facts->staleSerpOnly() ? 'stale' : null;
		}

		$rank = $primary->serpRank();

		return match (true) {
			$rank === null => 'out',
			$rank <= 3 => 'top3',
			$rank <= 10 => 'top10',
			$rank <= 20 => 'top20',
			$rank <= 50 => 'top50',
			$rank <= 100 => 'top100',
			default => 'out',
		};
	}

	/**
	 * Podstawa decyzji użytkownika zmieniła się po decyzji (inne działanie albo strona docelowa) — flaga, status bez zmian (D61).
	 *
	 * @param array<string, mixed>|null $prev
	 */
	private static function decisionChanged(?array $prev, Decision $decision, TargetResolution $target): bool
	{
		$basis = $prev['status_basis'] ?? null;

		if ($prev === null || $prev['status'] === TopicStatus::New->value || ! is_array($basis)) {
			return false;
		}

		return ($basis['action'] ?? null) !== $decision->action->value
			|| ($basis['target_state'] ?? null) !== $target->state->value
			|| ($basis['target_url'] ?? null) !== $target->url;
	}

	/**
	 * Istotne zmiany tematu względem poprzedniego przeliczenia.
	 *
	 * @param array<string, mixed> $prev
	 * @param array<string, mixed> $row
	 * @param array<int, KeywordSignals> $byId
	 * @return list<array{type: string, from?: ?string, to?: ?string, data?: array<string, mixed>}>
	 */
	private static function changes(array $prev, array $row, KeywordSignals $leader, array $byId): array
	{
		$events = [];

		if (! $prev['active']) {
			$events[] = ['type' => TopicEvent::ACTIVATED, 'from' => $prev['inactive_reason']];
		}

		if ($prev['leader'] !== null && $prev['leader'] !== $leader->marketKeywordId) {
			$events[] = ['type' => TopicEvent::LEADER_CHANGED, 'data' => ['from' => isset($byId[$prev['leader']]) ? $byId[$prev['leader']]->keyword : $prev['label'], 'to' => $leader->keyword]];
		}

		if ($prev['action'] !== null && $prev['action'] !== $row['action']) {
			$events[] = ['type' => TopicEvent::ACTION_CHANGED, 'from' => $prev['action'], 'to' => $row['action'], 'data' => ['from_reason' => $prev['action_reason'], 'to_reason' => $row['action_reason']]];
		}

		if ($prev['target_state'] !== null && ($prev['target_state'] !== $row['target_state'] || $prev['target_url'] !== $row['target_url'])) {
			$events[] = ['type' => TopicEvent::TARGET_CHANGED, 'from' => $prev['target_state'], 'to' => $row['target_state'], 'data' => ['from_url' => $prev['target_url'], 'to_url' => $row['target_url']]];
		}

		if ($prev['confidence_level'] !== null && $prev['confidence_level'] !== $row['confidence_level']) {
			$events[] = ['type' => TopicEvent::CONFIDENCE_BAND_CHANGED, 'from' => $prev['confidence_level'], 'to' => $row['confidence_level']];
		}

		$before = PriorityModel::band($prev['priority']);
		$after = PriorityModel::band($row['priority']);

		if ($before !== null && $before !== $after) {
			$events[] = ['type' => TopicEvent::PRIORITY_BAND_CHANGED, 'from' => $before, 'to' => $after, 'data' => ['from' => $prev['priority'], 'to' => $row['priority']]];
		}

		if ($prev['action'] !== null && $prev['serp_band'] !== $row['serp_band']) {
			$events[] = ['type' => TopicEvent::SERP_BAND_CHANGED, 'from' => $prev['serp_band'], 'to' => $row['serp_band']];
		}

		return $events;
	}

	/**
	 * @param list<ConflictSignal> $signals
	 * @return list<ConflictSignal>
	 */
	private static function uniqueConflicts(array $signals): array
	{
		$unique = [];

		foreach ($signals as $signal) {
			$unique[$signal->type . '|' . $signal->strength . '|' . $signal->keyword . '|' . implode(' ', $signal->urls)] = $signal;
		}

		return ConflictSignal::sorted(array_values($unique));
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function serpReference(TopicFacts $facts, ?string $band): array
	{
		$reference = $facts->primarySerp() ?? $facts->profiledSerp();

		return [
			'band' => $band,
			'keyword' => $reference?->publicId,
			'snapshot' => $reference?->serpSnapshot(),
			'checked_at' => $reference?->serpCheckedAt(),
			'freshness' => $reference?->serpFreshness,
			'rank' => $facts->primarySerp()?->serpRank(),
			'url' => $facts->primarySerp()?->serpUrl(),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function factsSummary(TopicFacts $facts): array
	{
		return [
			'demand' => $facts->demand(),
			'gsc' => $facts->gscKnown() ? ['impressions' => $facts->gscImpressions(), 'clicks' => $facts->gscClicks(), 'position' => $facts->gscPosition(), 'complete' => $facts->gscComplete] : null,
			'decline' => $facts->serpDecline(),
			'opportunities' => $facts->opportunityTypes(),
			'competitors_top10' => $facts->competitorsTop10(),
			'content_gap' => $facts->contentGap(),
			'gap_weak' => $facts->gapWeak(),
			'accepted_discovery' => $facts->acceptedDiscovery(),
			'intent_mismatch' => $facts->intentMismatch(),
			'spell' => $facts->spellCorrection(),
			'serp_dedicated_pages' => $facts->serpDedicatedPages(),
			'labs_only' => $facts->labsOnly(),
		];
	}

	private static function unpinned(KeywordSignals $keyword): KeywordSignals
	{
		return new KeywordSignals(
			$keyword->id,
			$keyword->publicId,
			$keyword->marketKeywordId,
			$keyword->keyword,
			$keyword->coreKey,
			$keyword->manual,
			$keyword->sources,
			$keyword->tier,
			$keyword->volume,
			$keyword->difficulty,
			$keyword->cpc,
			$keyword->intent,
			$keyword->topicId,
			null,
			$keyword->evidence,
			$keyword->serpFreshness,
			$keyword->factsHash,
		);
	}
}
