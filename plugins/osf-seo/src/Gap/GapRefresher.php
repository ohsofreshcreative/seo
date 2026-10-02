<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\Discovery\DiscoverySettingsRepository;
use OsfSeo\Discovery\ExclusionList;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Support\Clock;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Ulid;

/**
 * Bezpłatne przeliczenie Luk SEO projektu z zapisanych danych (docs/ARCHITECTURE.md, sekcja 14) — nigdy przy renderowaniu
 * strony, tylko w tle, z CLI albo po imporcie:
 *
 * 1. frazy, na które aktywni konkurenci projektu rankują w progu znaczącej pozycji (zbiory domen, Labs),
 * 2. widoczność projektu: pomiar SERP → GSC → punkt odniesienia Labs (`VisibilityResolver`), typ luki, priorytet,
 * 3. filtry: marka projektu i konkurentów, wykluczenia projektu (wspólne z Nowymi frazami), słowa tematyczne,
 *    minimalny wolumen, maksymalna KD, inny język — wiersze zostają (`listed = 0`, powód),
 * 4. grupy fraz (lider grupy, stabilne identyfikatory), luka treści grupy, strony konkurencji.
 *
 * Wiersze luk nie są usuwane: fraza bez aktualnych dowodów jest „Nieaktualna” (status pracy zostaje).
 * Przeliczenie tylko po zmianie danych wejściowych (klucz danych) albo raz dziennie (metryki rynkowe).
 */
final class GapRefresher
{
	private const VERSION = 1;

	private const CHUNK = 1000;

	/** @var array<int, int> zbiór → id konkurenta (bieżące przeliczenie) */
	private array $datasetCompetitors = [];

	public function __construct(
		private readonly Connection $db,
		private readonly CompetitorKeywordsProvider $provider,
		private readonly GapDomainRepository $domains,
		private readonly GapSettingsRepository $settings,
		private readonly CompetitorRepository $competitors,
		private readonly DiscoverySettingsRepository $discoverySettings,
		private readonly SerpDictionary $dictionary,
		private readonly MarketKeyBackfill $backfill,
		private readonly GapConfig $config,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{skipped: bool, keywords: int, listed: int, clusters: int, pages: int}
	 */
	public function refresh(int $projectId, bool $force = false): array
	{
		$report = ['skipped' => true, 'keywords' => 0, 'listed' => 0, 'clusters' => 0, 'pages' => 0];
		$project = $this->db->fetchRow(
			"SELECT id, name, domain, country, language, last_synced_at, gsc_data_property FROM `{$this->db->table('projects')}` WHERE id = %d",
			[$projectId],
		);
		$market = $project === null ? null : $this->provider->resolveMarket((string) $project['country'], (string) $project['language']);

		if ($project === null || $market === null) {
			return $report;
		}

		$settings = $this->settings->get($projectId);
		$projectDomain = DomainFamily::normalize((string) $project['domain']);
		$competitors = array_values(array_filter(
			$this->competitors->active($projectId),
			static fn (Competitor $competitor): bool => DomainFamily::normalize($competitor->domain) !== null
				&& ($projectDomain === null || ! DomainFamily::overlaps((string) DomainFamily::normalize($competitor->domain), $projectDomain)),
		));
		$datasets = $this->domains->forDomains($market, [...array_map(static fn (Competitor $competitor): string => (string) DomainFamily::normalize($competitor->domain), $competitors), ...($projectDomain === null ? [] : [$projectDomain])]);
		$byDataset = [];

		foreach ($competitors as $competitor) {
			$dataset = $datasets[(string) DomainFamily::normalize($competitor->domain)] ?? null;

			if ($dataset !== null && $dataset->wasImported()) {
				$byDataset[$dataset->id] = $competitor;
			}
		}

		$baseline = $projectDomain === null ? null : ($datasets[$projectDomain] ?? null);
		$baseline = $baseline !== null && $baseline->wasImported() ? $baseline : null;
		$exclusions = $this->discoverySettings->exclusions($projectId);
		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d", [$projectId]);
		$serpVersion = $this->db->fetchValue(
			"SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(last_snapshot_id), 0), ':', COALESCE(SUM(last_snapshot_id), 0)) FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = 'active'",
			[$projectId],
		);
		$key = md5((string) json_encode([
			self::VERSION,
			$this->clock->now()->format('Y-m-d'),
			// Tylko ustawienia analizy (nie zakres pobierania, harmonogram ani znaczniki przeliczenia).
			[$settings->competitorMaxRank, $settings->minVolume, $settings->maxDifficulty, $settings->includeTerms, $settings->brandTerms],
			array_map(static fn (Competitor $competitor): array => [$competitor->id, $competitor->domain, $competitor->name, $competitor->brandTerms], $competitors),
			array_map(static fn (GapDomain $dataset): array => [$dataset->id, $dataset->importedAt, $dataset->rowsPresent, $dataset->status, $dataset->complete], array_values($datasets)),
			$exclusions->hash(),
			$latest,
			$project['last_synced_at'],
			$project['gsc_data_property'],
			$project['name'],
			$project['domain'],
			$serpVersion,
			$this->config->effective(),
		]));

		if (! $force && $settings->dataKey === $key) {
			return $report;
		}

		$lock = 'gap_refresh_' . $projectId;

		if (! $this->db->acquireLock($lock, 0)) {
			// Ten projekt przelicza właśnie inny proces (CLI albo krok tła) — bez równoległego przeliczenia.
			return $report;
		}

		try {
			$report['skipped'] = false;
			$marker = $this->clock->now()->format('Y-m-d H:i:s');
			$this->backfill->fillProject($projectId);
			$context = new RefreshContext(
				projectId: $projectId,
				market: $market,
				settings: $settings,
				competitorsByDataset: $byDataset,
				baseline: $baseline,
				exclusions: $exclusions,
				includes: ExclusionList::parse($settings->includeTerms),
				ownBrand: BrandMatcher::build($projectDomain === null ? [] : [$projectDomain], [(string) $project['name']], $settings->brandTerms),
				competitorBrands: array_map(
					static fn (Competitor $competitor): BrandMatcher => BrandMatcher::build([(string) DomainFamily::normalize($competitor->domain)], [$competitor->name], $competitor->brandTerms),
					$competitors,
				),
				window: $latest === null ? null : [DateRange::shift($latest, -($this->config->windowDays() - 1)), $latest],
				serpSince: $this->clock->now()->modify('-' . $this->config->serpFreshDays() . ' days')->format('Y-m-d H:i:s'),
				marker: $marker,
			);
			$this->datasetCompetitors = array_map(static fn (Competitor $competitor): int => $competitor->id, $byDataset);
			$keep = [];
			// Znacznik przeliczenia: wiersze nieocenione w tym przebiegu (scored_at NULL) staną się nieaktualne. Sam czas nie
			// wystarcza — dwa przeliczenia w tej samej sekundzie miałyby ten sam znacznik.
			$this->db->execute("UPDATE `{$this->table()}` SET scored_at = NULL WHERE project_id = %d AND active = 1", [$projectId]);

			if ($byDataset !== []) {
				$ids = $this->keywordIds(array_keys($byDataset), $settings->competitorMaxRank);
				$report['keywords'] = count($ids);

				foreach (array_chunk($ids, self::CHUNK) as $chunk) {
					foreach ($this->scoreChunk($context, $chunk) as $id => $entry) {
						$keep[$id] = $entry;
					}
				}
			}

			$this->db->execute(
				"UPDATE `{$this->table()}` SET active = 0, listed = 0, filter_reason = 'inactive', cluster_id = NULL, content_gap = NULL, updated_at = %s
				WHERE project_id = %d AND active = 1 AND scored_at IS NULL",
				[$marker, $projectId],
			);
			$report['listed'] = count($keep);
			$report['clusters'] = $this->clusters($context, $keep);
			$report['pages'] = $this->pages($context);
			$this->settings->recordRecalculation($projectId, $key);
		} finally {
			$this->db->releaseLock($lock);
		}

		return $report;
	}

	/**
	 * Frazy, na które co najmniej jeden aktywny konkurent rankuje w progu znaczącej pozycji.
	 *
	 * @param list<int> $datasetIds
	 * @return list<int>
	 */
	private function keywordIds(array $datasetIds, int $maxRank): array
	{
		$ids = array_map('intval', array_column($this->db->fetchAll(
			"SELECT DISTINCT market_keyword_id FROM `{$this->rowsTable()}` WHERE domain_id IN (" . Connection::placeholders($datasetIds, '%d') . ') AND present = 1 AND rank_group <= %d',
			[...$datasetIds, $maxRank],
		), 'market_keyword_id'));
		sort($ids);

		return $ids;
	}

	/**
	 * Przeliczenie paczki fraz i zapis luk. Zwraca dane fraz z listy (do grupowania).
	 *
	 * @param list<int> $ids
	 * @return array<int, array<string, mixed>>
	 */
	private function scoreChunk(RefreshContext $context, array $ids): array
	{
		$datasetIds = array_keys($context->competitorsByDataset);
		$evidence = $this->competitorEvidence($datasetIds, $ids, $context->settings->competitorMaxRank);
		$metrics = $this->metrics($ids);
		$baseline = $context->baseline === null ? [] : $this->baselineRows($context->baseline->id, $ids);
		$hexes = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['hex'], $metrics)));
		$gsc = $context->window === null ? [] : $this->gsc($context->projectId, $context->window, $hexes);
		$gscPages = $context->window === null ? [] : $this->gscPages($context->projectId, $context->window, $hexes);
		$serp = $this->serp($context->projectId, $ids, $context->serpSince);
		$existing = $this->existing($context->projectId, $ids);
		$resolver = VisibilityResolver::fromConfig($this->config);
		$classifier = new GapClassifier($this->config->visiblePosition());
		$scorer = new GapScorer();
		$now = $context->marker;
		$upsert = new BulkInsert(
			$this->db,
			$this->table(),
			[
				'public_id', 'project_id', 'market_keyword_id', 'status', 'active', 'listed', 'filter_reason', 'gap_type', 'visibility',
				'visibility_source', 'sporadic', 'project_position', 'project_labs_rank', 'serp_rank', 'serp_checked_at', 'gsc_impressions',
				'gsc_clicks', 'gsc_position', 'competitors_count', 'competitors_top10', 'best_competitor_id', 'best_competitor_rank',
				'best_url_id', 'search_volume', 'keyword_difficulty', 'cpc', 'intent', 'target_url_id', 'target_source', 'priority', 'score',
				'evidence_on', 'first_seen_at', 'scored_at', 'created_at', 'updated_at',
			],
			[
				'%s', '%d', '%d', '%s', '%d', '%d', "NULLIF(%s, '')", '%s', '%s',
				"NULLIF(%s, '')", '%d', "NULLIF(%s, '')", 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', "NULLIF(%s, '')", 'NULLIF(%d, -1)',
				'NULLIF(%d, -1)', "NULLIF(%s, '')", '%d', '%d', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)',
				'NULLIF(%d, 0)', 'NULLIF(%d, -1)', 'NULLIF(%d, -1)', "NULLIF(%s, '')", "NULLIF(%s, '')", 'NULLIF(%d, 0)', "NULLIF(%s, '')", '%d', '%s',
				"NULLIF(%s, '')", '%s', '%s', '%s', '%s',
			],
			'ON DUPLICATE KEY UPDATE active = VALUES(active), listed = VALUES(listed), filter_reason = VALUES(filter_reason), gap_type = VALUES(gap_type),
				visibility = VALUES(visibility), visibility_source = VALUES(visibility_source), sporadic = VALUES(sporadic),
				project_position = VALUES(project_position), project_labs_rank = VALUES(project_labs_rank), serp_rank = VALUES(serp_rank),
				serp_checked_at = VALUES(serp_checked_at), gsc_impressions = VALUES(gsc_impressions), gsc_clicks = VALUES(gsc_clicks),
				gsc_position = VALUES(gsc_position), competitors_count = VALUES(competitors_count), competitors_top10 = VALUES(competitors_top10),
				best_competitor_id = VALUES(best_competitor_id), best_competitor_rank = VALUES(best_competitor_rank), best_url_id = VALUES(best_url_id),
				search_volume = VALUES(search_volume), keyword_difficulty = VALUES(keyword_difficulty), cpc = VALUES(cpc), intent = VALUES(intent),
				target_url_id = VALUES(target_url_id), target_source = VALUES(target_source), priority = VALUES(priority), score = VALUES(score),
				evidence_on = VALUES(evidence_on), scored_at = VALUES(scored_at), updated_at = VALUES(updated_at)',
			500,
		);
		$keep = [];
		$targets = $this->gscTargetUrls($context->projectId, $gscPages);

		foreach ($ids as $id) {
			$competitor = $evidence[$id] ?? null;
			$market = $metrics[$id] ?? null;

			if ($competitor === null || $market === null) {
				continue;
			}

			$volume = $market['search_volume'] === null ? null : (int) $market['search_volume'];
			$difficulty = $market['keyword_difficulty'] === null ? null : (int) $market['keyword_difficulty'];
			$cpc = $market['cpc'] === null ? null : (float) $market['cpc'];
			$intent = $market['search_intent'];
			$hex = (string) $market['hex'];
			$keyword = (string) $market['keyword'];
			$reason = $this->filterReason($context, $keyword, $intent, $volume, $difficulty, $market['other_language']);
			$tracked = $serp[$id] ?? null;
			$labsRow = $baseline[$id] ?? null;
			$gscRow = $gsc[$hex] ?? ['impressions' => 0, 'clicks' => 0, 'position_sum' => 0.0];
			$project = $resolver->resolve(
				$tracked === null ? null : ['found' => (int) $tracked['last_found'] === 1, 'rank' => $tracked['last_rank'] === null ? null : (int) $tracked['last_rank'], 'depth' => (int) $tracked['last_depth']],
				['has_data' => $context->window !== null, 'impressions' => $gscRow['impressions'], 'position_sum' => $gscRow['position_sum']],
				$context->baseline === null ? null : ['rank' => $labsRow === null ? null : (int) $labsRow['rank_group'], 'absence_reliable' => $context->baseline->provesNoVisibility($volume)],
				$volume,
			);
			$type = $classifier->classify($project, $competitor['rank']);
			$score = $scorer->score($type, $project, $competitor['rank'], $competitor['count'], $volume, $difficulty, $cpc, $intent);
			[$targetUrl, $targetSource] = $this->keywordTarget($tracked, $gscPages[$hex] ?? [], $targets, $labsRow);
			$old = $existing[$id] ?? null;
			$upsert->add([
				$old === null ? Ulid::generate() : (string) $old['public_id'],
				$context->projectId,
				$id,
				'new',
				1,
				$reason === null ? 1 : 0,
				(string) $reason,
				$type->value,
				$project->visibility->value,
				(string) $project->source,
				$project->sporadic ? 1 : 0,
				$project->position === null ? '' : number_format($project->position, 2, '.', ''),
				$labsRow === null ? 0 : (int) $labsRow['rank_group'],
				$tracked !== null && (int) $tracked['last_found'] === 1 ? (int) $tracked['last_rank'] : 0,
				$tracked === null ? '' : (string) $tracked['last_checked_at'],
				$context->window === null ? -1 : $gscRow['impressions'],
				$context->window === null ? -1 : $gscRow['clicks'],
				$context->window === null || $gscRow['impressions'] <= 0 ? '' : number_format($gscRow['position_sum'] / $gscRow['impressions'], 2, '.', ''),
				min(255, $competitor['count']),
				min(255, $competitor['top10']),
				$competitor['competitor_id'],
				$competitor['rank'],
				$competitor['url_id'],
				$volume ?? -1,
				$difficulty ?? -1,
				$cpc === null ? '' : number_format($cpc, 4, '.', ''),
				(string) $intent,
				(int) $targetUrl,
				(string) $targetSource,
				$score->priority,
				(string) json_encode($score->toArray()),
				(string) $competitor['evidence_on'],
				$old === null ? $now : (string) $old['first_seen_at'],
				$now,
				$now,
				$now,
			]);

			if ($reason === null) {
				$keep[$id] = [
					'id' => $id,
					'keyword' => $keyword,
					'volume' => $volume ?? 0,
					'priority' => $score->priority,
					'type' => $type,
					'core' => $market['core'],
					'gsc_pages' => $gscPages[$hex] ?? [],
					'serp_url' => $tracked !== null && (int) $tracked['last_found'] === 1 && $tracked['last_url_id'] !== null ? (int) $tracked['last_url_id'] : null,
					'serp_snapshot' => $tracked === null || $tracked['last_snapshot_id'] === null ? null : (int) $tracked['last_snapshot_id'],
					'labs_url' => $labsRow === null || $labsRow['url_id'] === null ? null : (int) $labsRow['url_id'],
					'source' => $project->source,
					'visibility' => $project->visibility->value,
					'target' => $targetUrl,
				];
			}
		}

		$upsert->flush();

		return $keep;
	}

	private function filterReason(RefreshContext $context, string $keyword, ?string $intent, ?int $volume, ?int $difficulty, ?string $otherLanguage): ?string
	{
		if ($context->ownBrand->match($keyword, $intent) !== null) {
			return 'brand_own';
		}

		foreach ($context->competitorBrands as $brand) {
			if ($brand->match($keyword, $intent) !== null) {
				return 'brand_competitor';
			}
		}

		return match (true) {
			$context->exclusions->match($keyword) !== null => 'excluded',
			! $context->includes->isEmpty() && $context->includes->match($keyword) === null => 'not_included',
			$otherLanguage === '1' => 'foreign_language',
			$context->settings->minVolume > 0 && ($volume === null || $volume < $context->settings->minVolume) => 'low_volume',
			$context->settings->maxDifficulty !== null && $difficulty !== null && $difficulty > $context->settings->maxDifficulty => 'high_difficulty',
			default => null,
		};
	}

	/**
	 * Strona docelowa frazy: adres projektu z pomiaru SERP → strona z GSC (≥ 60% wyświetleń frazy) → adres z Labs.
	 *
	 * @param array<string, string|null>|null $tracked
	 * @param array<int, int> $pages id strony GSC → wyświetlenia
	 * @param array<int, int> $targets id strony GSC → id adresu w słowniku
	 * @param array<string, string|null>|null $labs
	 * @return array{0: ?int, 1: ?string}
	 */
	private function keywordTarget(?array $tracked, array $pages, array $targets, ?array $labs): array
	{
		if ($tracked !== null && (int) $tracked['last_found'] === 1 && $tracked['last_url_id'] !== null) {
			return [(int) $tracked['last_url_id'], ProjectEvidence::SOURCE_SERP];
		}

		$total = array_sum($pages);

		if ($total > 0) {
			arsort($pages);
			$page = (int) array_key_first($pages);

			if ($pages[$page] >= $this->config->minImpressions() && $pages[$page] / $total >= GapConfig::TARGET_SHARE && isset($targets[$page])) {
				return [$targets[$page], ProjectEvidence::SOURCE_GSC];
			}
		}

		if ($labs !== null && $labs['url_id'] !== null) {
			return [(int) $labs['url_id'], ProjectEvidence::SOURCE_LABS];
		}

		return [null, null];
	}

	/**
	 * Grupy fraz z listy (najwyżej `max_cluster_keywords` wg priorytetu), luka treści grupy, stabilne identyfikatory.
	 *
	 * @param array<int, array<string, mixed>> $keep
	 */
	private function clusters(RefreshContext $context, array $keep): int
	{
		$projectId = $context->projectId;
		$previous = [];

		foreach ($this->db->fetchAll("SELECT market_keyword_id, cluster_id FROM `{$this->table()}` WHERE project_id = %d AND cluster_id IS NOT NULL", [$projectId]) as $row) {
			$previous[(int) $row['market_keyword_id']] = (int) $row['cluster_id'];
		}

		$this->db->execute("UPDATE `{$this->table()}` SET cluster_id = NULL, content_gap = NULL WHERE project_id = %d AND cluster_id IS NOT NULL", [$projectId]);

		uasort($keep, static fn (array $a, array $b): int => [$b['priority'], $b['volume'], $a['id']] <=> [$a['priority'], $a['volume'], $b['id']]);
		$keep = array_slice($keep, 0, $this->config->maxClusterKeywords(), true);

		if ($keep === []) {
			$this->db->execute("UPDATE `{$this->db->table('gap_clusters')}` SET active = 0, updated_at = %s WHERE project_id = %d AND active = 1", [$context->marker, $projectId]);

			return 0;
		}

		$datasetIds = array_keys($context->competitorsByDataset);
		$competitorUrls = $this->competitorUrls($datasetIds, array_keys($keep), $context->settings->competitorMaxRank);
		$hubs = $this->hubUrls($datasetIds);
		$urlIds = [];

		foreach ($competitorUrls as $rows) {
			foreach ($rows as [, $urlId]) {
				$urlIds[$urlId] = true;
			}
		}

		foreach ($keep as $entry) {
			foreach (['serp_url', 'labs_url', 'target'] as $field) {
				if ($entry[$field] !== null) {
					$urlIds[(int) $entry[$field]] = true;
				}
			}
		}

		$urls = $this->urls(array_keys($urlIds));
		$serpTop10 = $this->serpTop10(array_values(array_filter(array_map(static fn (array $entry): ?int => $entry['serp_snapshot'], $keep))));
		$items = [];

		foreach ($keep as $id => $entry) {
			$target = $entry['target'] !== null && isset($urls[(int) $entry['target']]) && ! TextFold::isRoot($urls[(int) $entry['target']]) ? (int) $entry['target'] : null;
			$items[] = new ClusterItem(
				$id,
				(int) $entry['volume'],
				array_values(array_unique(array_filter(
					array_map(static fn (array $pair): int => $pair[1], $competitorUrls[$id] ?? []),
					static fn (int $urlId): bool => ! isset($hubs[$urlId]) && isset($urls[$urlId]) && ! TextFold::isRoot($urls[$urlId]),
				))),
				$entry['core'] === null || $entry['core'] === '' ? null : (string) $entry['core'],
				$target,
				$entry['serp_snapshot'] === null ? null : ($serpTop10[(int) $entry['serp_snapshot']] ?? null),
				TextFold::tokenKey((string) $entry['keyword']),
			);
		}

		$result = (new KeywordClusterer())->cluster($items);
		$members = [];

		foreach ($result['assignments'] as $id => $index) {
			$members[$index][] = $id;
		}

		$projectPages = $this->projectPages($projectId, $context->window);
		$classifier = new ContentGapClassifier();
		$gscTargets = $this->gscTargetUrls($projectId, array_map(static fn (array $entry): array => $entry['gsc_pages'], $keep));
		$clusters = [];

		foreach ($result['leaders'] as $index => $leaderId) {
			$ids = $members[$index] ?? [$leaderId];
			$leader = $keep[$leaderId];
			$serpCounts = [];
			$gscImpressions = [];
			$labsWeights = [];
			$competitorPages = [];
			$dedicated = [];
			$gapCount = 0;
			$gapVolume = 0;
			$totalVolume = 0;
			$best = null;
			$hasSerp = false;
			$leaderTokens = TextFold::tokens((string) $leader['keyword']);

			foreach ($ids as $id) {
				$entry = $keep[$id];
				$totalVolume += (int) $entry['volume'];

				if ($entry['type']->isGap()) {
					$gapCount++;
					$gapVolume += (int) $entry['volume'];
				}

				if ($entry['serp_url'] !== null) {
					$serpCounts[(int) $entry['serp_url']] = ($serpCounts[(int) $entry['serp_url']] ?? 0) + 1;
				}

				$hasSerp = $hasSerp || $entry['source'] === ProjectEvidence::SOURCE_SERP;

				foreach ($entry['gsc_pages'] as $pageId => $impressions) {
					if (isset($gscTargets[$pageId])) {
						$gscImpressions[$gscTargets[$pageId]] = ($gscImpressions[$gscTargets[$pageId]] ?? 0) + $impressions;
					}
				}

				if ($entry['labs_url'] !== null) {
					$labsWeights[(int) $entry['labs_url']] = ($labsWeights[(int) $entry['labs_url']] ?? 0) + max(1, (int) $entry['volume']);
				}

				foreach ($competitorUrls[$id] ?? [] as [$datasetId, $urlId, $rank]) {
					$url = $urls[$urlId] ?? null;

					if ($url !== null && ! TextFold::isRoot($url)) {
						$competitorPages[$datasetId] = true;

						if (TextFold::slugCoverage($leaderTokens, $url) >= GapConfig::DEDICATED_SLUG_COVERAGE) {
							$dedicated[$datasetId] = true;
						}
					}

					if ($best === null || $rank < $best[0]) {
						$best = [$rank, $datasetId];
					}
				}
			}

			$slug = $this->slugTarget($projectPages, $leaderTokens);
			[$targetUrl, $targetSource, $scattered] = ContentGapClassifier::target($serpCounts, $gscImpressions, $labsWeights, $slug, $this->config->minImpressions());
			$targetIsRoot = $targetUrl !== null && isset($urls[$targetUrl]) ? TextFold::isRoot($urls[$targetUrl]) : ($targetUrl !== null && $this->isRootUrl($targetUrl));
			$content = $classifier->classify(new ClusterEvidence(
				hasProjectData: $context->window !== null || $context->baseline !== null || $hasSerp,
				targetUrlId: $targetUrl,
				targetSource: $targetSource,
				targetIsRoot: $targetIsRoot,
				scattered: $scattered,
				competitorPages: count($competitorPages),
				dedicatedPages: count($dedicated),
				leaderType: $leader['type'],
				gapShare: $gapCount / max(1, count($ids)),
				gapVolume: $gapVolume,
				baselineReliable: $context->baseline !== null && $context->baseline->provesNoVisibility($leader['volume'] === null ? null : (int) $leader['volume']),
				hasGsc: $context->window !== null,
			));
			$clusters[] = [
				'ids' => $ids,
				'leader' => $leaderId,
				'label' => (string) $leader['keyword'],
				'keywords' => count($ids),
				'gap_keywords' => $gapCount,
				'total_volume' => $totalVolume,
				'gap_volume' => $gapVolume,
				'competitors' => count(array_unique(array_merge(...array_map(static fn (int $id): array => array_map(static fn (array $pair): int => $pair[0], $competitorUrls[$id] ?? []), $ids)))),
				'best_rank' => $best[0] ?? null,
				'best_competitor' => $best === null ? null : ($context->competitorsByDataset[$best[1]]->id ?? null),
				'competitor_pages' => count($competitorPages),
				'dedicated_pages' => count($dedicated),
				'visibility' => (string) $leader['visibility'],
				'content' => $content,
				'target' => $targetUrl,
				'target_source' => $targetSource,
				'priority' => GapScorer::clusterPriority(max(array_map(static fn (int $id): int => (int) $keep[$id]['priority'], $ids)), $gapVolume),
			];
		}

		$this->saveClusters($context, $clusters, $previous);

		return count($clusters);
	}

	/**
	 * Zapis grup ze stabilnymi identyfikatorami: nowa grupa przejmuje id (i status pracy) starej, jeśli co najmniej połowa
	 * jej fraz należała do tej samej starej grupy. Stare grupy bez następcy → nieaktywne.
	 *
	 * @param list<array<string, mixed>> $clusters
	 * @param array<int, int> $previous fraza → poprzednia grupa
	 */
	private function saveClusters(RefreshContext $context, array $clusters, array $previous): void
	{
		$table = $this->db->table('gap_clusters');
		$active = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM `{$table}` WHERE project_id = %d", [$context->projectId]), 'id'));
		$known = array_flip($active);
		$taken = [];
		$assign = [];
		$now = $context->marker;

		// Największe grupy najpierw — przy konflikcie stary identyfikator dostaje grupa z największą częścią jej fraz.
		usort($clusters, static fn (array $a, array $b): int => [$b['keywords'], $a['leader']] <=> [$a['keywords'], $b['leader']]);

		foreach ($clusters as $cluster) {
			$votes = [];

			foreach ($cluster['ids'] as $id) {
				if (isset($previous[$id], $known[$previous[$id]])) {
					$votes[$previous[$id]] = ($votes[$previous[$id]] ?? 0) + 1;
				}
			}

			arsort($votes);
			$reuse = null;

			foreach ($votes as $clusterId => $count) {
				if (! isset($taken[$clusterId]) && $count * 2 >= count($cluster['ids'])) {
					$reuse = (int) $clusterId;
				}

				break;
			}

			/** @var ContentGapResult $content */
			$content = $cluster['content'];
			$values = [
				'leader_market_keyword_id' => $cluster['leader'],
				'label' => mb_substr((string) $cluster['label'], 0, 255, 'UTF-8'),
				'active' => 1,
				'keywords_count' => $cluster['keywords'],
				'gap_keywords_count' => $cluster['gap_keywords'],
				'total_volume' => min(4294967295, $cluster['total_volume']),
				'gap_volume' => min(4294967295, $cluster['gap_volume']),
				'competitors_count' => min(255, $cluster['competitors']),
				'best_competitor_id' => $cluster['best_competitor'],
				'best_competitor_rank' => $cluster['best_rank'],
				'competitor_pages' => min(65535, $cluster['competitor_pages']),
				'dedicated_pages' => min(65535, $cluster['dedicated_pages']),
				'visibility' => $cluster['visibility'],
				'content_gap' => $content->gap->value,
				'content_reason' => $content->reason,
				'confidence' => $content->confidence,
				'target_url_id' => $cluster['target'],
				'target_source' => $cluster['target_source'],
				'priority' => $cluster['priority'],
				'updated_at' => $now,
			];

			if ($reuse !== null) {
				$taken[$reuse] = true;
				$this->db->update($table, $values, ['id' => $reuse]);
				$clusterId = $reuse;
			} else {
				$clusterId = $this->db->insert($table, $values + [
					'public_id' => Ulid::generate(),
					'project_id' => $context->projectId,
					'label_key' => md5((string) $cluster['label'], true),
					'status' => GapStatus::New->value,
					'first_seen_at' => $now,
					'created_at' => $now,
				]);
			}

			foreach ($cluster['ids'] as $id) {
				$assign[$id] = [$clusterId, $content->gap->value];
			}
		}

		$stale = array_values(array_diff($active, array_keys($taken)));

		foreach (array_chunk($stale, self::CHUNK) as $chunk) {
			$this->db->execute(
				"UPDATE `{$table}` SET active = 0, updated_at = %s WHERE project_id = %d AND active = 1 AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$now, $context->projectId, ...$chunk],
			);
		}

		foreach (array_chunk($assign, self::CHUNK, true) as $chunk) {
			$cases = [];
			$caseParams = [];
			$gapParams = [];

			foreach ($chunk as $id => [$clusterId, $gap]) {
				$cases[] = 'WHEN %d THEN %d';
				array_push($caseParams, $id, $clusterId);
				array_push($gapParams, $id, $gap);
			}

			$this->db->execute(
				"UPDATE `{$this->table()}` SET cluster_id = CASE market_keyword_id " . implode(' ', $cases) . ' END,
					content_gap = CASE market_keyword_id ' . str_repeat('WHEN %d THEN %s ', count($chunk)) . 'END
				WHERE project_id = %d AND market_keyword_id IN (' . Connection::placeholders(array_keys($chunk), '%d') . ')',
				[...$caseParams, ...$gapParams, $context->projectId, ...array_keys($chunk)],
			);
		}
	}

	/**
	 * Strony konkurencji projektu (agregaty z zaimportowanych fraz zbiorów aktywnych konkurentów).
	 */
	private function pages(RefreshContext $context): int
	{
		$table = $this->db->table('gap_competitor_pages');
		$count = 0;

		$this->db->transaction(function () use ($context, $table, &$count): void {
			$this->db->execute("DELETE FROM `{$table}` WHERE project_id = %d", [$context->projectId]);

			foreach ($context->competitorsByDataset as $datasetId => $competitor) {
				$rows = $this->db->fetchAll(
					"SELECT STRAIGHT_JOIN dk.url_id, u.url, LOWER(HEX(u.url_hash)) AS url_key, p.title, COUNT(*) AS keywords,
						SUM(dk.rank_group <= 3) AS top3, SUM(dk.rank_group <= 10) AS top10, SUM(dk.rank_group <= 20) AS top20,
						COALESCE(SUM(m.search_volume), 0) AS total_volume, COALESCE(SUM(dk.etv), 0) AS etv, MIN(dk.rank_group) AS best_rank,
						MIN(CONCAT(LPAD(dk.rank_group, 3, '0'), ':', dk.market_keyword_id)) AS best,
						SUM(g.listed = 1 AND g.gap_type IN ('missing', 'weak', 'unknown')) AS gap_keywords,
						COALESCE(SUM(IF(g.listed = 1 AND g.gap_type IN ('missing', 'weak', 'unknown'), m.search_volume, 0)), 0) AS gap_volume,
						SUM(g.listed = 1 AND g.gap_type IN ('competitive', 'stronger')) AS overlap_keywords
					FROM `{$this->rowsTable()}` dk
					JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
					JOIN `{$this->db->table('serp_urls')}` u ON u.id = dk.url_id
					LEFT JOIN `{$this->db->table('gap_domain_pages')}` p ON p.domain_id = dk.domain_id AND p.url_id = dk.url_id
					LEFT JOIN `{$this->table()}` g ON g.project_id = %d AND g.market_keyword_id = dk.market_keyword_id
					WHERE dk.domain_id = %d AND dk.present = 1 AND dk.url_id IS NOT NULL
					GROUP BY dk.url_id",
					[$context->projectId, $datasetId],
				);

				if ($rows === []) {
					continue;
				}

				$intents = $this->pageIntents($datasetId);
				$clusters = $this->pageClusters($context->projectId, $datasetId);
				$insert = new BulkInsert(
					$this->db,
					$table,
					['project_id', 'competitor_id', 'url_id', 'url_key', 'title', 'page_type', 'keywords', 'top3', 'top10', 'top20', 'total_volume', 'etv',
						'gap_keywords', 'gap_volume', 'overlap_keywords', 'main_intent', 'best_rank', 'best_market_keyword_id', 'cluster_id', 'updated_at'],
					['%d', '%d', '%d', 'UNHEX(%s)', "NULLIF(%s, '')", '%d', '%d', '%d', '%d', '%d', '%d', '%s',
						'%d', '%d', '%d', "NULLIF(%s, '')", '%d', 'NULLIF(%d, 0)', 'NULLIF(%d, 0)', '%s'],
					'',
					500,
				);

				foreach ($rows as $row) {
					$urlId = (int) $row['url_id'];
					$insert->add([
						$context->projectId,
						$competitor->id,
						$urlId,
						(string) $row['url_key'],
						(string) $row['title'],
						TextFold::pageType((string) $row['url']),
						(int) $row['keywords'],
						(int) $row['top3'],
						(int) $row['top10'],
						(int) $row['top20'],
						min(4294967295, (int) $row['total_volume']),
						number_format((float) $row['etv'], 2, '.', ''),
						(int) $row['gap_keywords'],
						min(4294967295, (int) $row['gap_volume']),
						(int) $row['overlap_keywords'],
						(string) ($intents[$urlId] ?? ''),
						(int) $row['best_rank'],
						(int) explode(':', (string) $row['best'])[1],
						(int) ($clusters[$urlId] ?? 0),
						$context->marker,
					]);
					$count++;
				}

				$insert->flush();
			}
		});

		return $count;
	}

	/**
	 * Główna intencja strony (najczęstsza wśród jej fraz).
	 *
	 * @return array<int, string>
	 */
	private function pageIntents(int $datasetId): array
	{
		$best = [];

		foreach ($this->db->fetchAll(
			"SELECT dk.url_id, m.search_intent, COUNT(*) AS n FROM `{$this->rowsTable()}` dk JOIN `{$this->db->table('market_keywords')}` m ON m.id = dk.market_keyword_id
			WHERE dk.domain_id = %d AND dk.present = 1 AND dk.url_id IS NOT NULL AND m.search_intent IS NOT NULL GROUP BY dk.url_id, m.search_intent",
			[$datasetId],
		) as $row) {
			$urlId = (int) $row['url_id'];

			if (! isset($best[$urlId]) || (int) $row['n'] > $best[$urlId][0]) {
				$best[$urlId] = [(int) $row['n'], (string) $row['search_intent']];
			}
		}

		return array_map(static fn (array $value): string => $value[1], $best);
	}

	/**
	 * Dominująca grupa fraz z luką strony konkurenta.
	 *
	 * @return array<int, int>
	 */
	private function pageClusters(int $projectId, int $datasetId): array
	{
		$best = [];

		foreach ($this->db->fetchAll(
			"SELECT dk.url_id, g.cluster_id, COUNT(*) AS n FROM `{$this->rowsTable()}` dk
			JOIN `{$this->table()}` g ON g.project_id = %d AND g.market_keyword_id = dk.market_keyword_id
			WHERE dk.domain_id = %d AND dk.present = 1 AND dk.url_id IS NOT NULL AND g.cluster_id IS NOT NULL AND g.listed = 1
			GROUP BY dk.url_id, g.cluster_id",
			[$projectId, $datasetId],
		) as $row) {
			$urlId = (int) $row['url_id'];

			if (! isset($best[$urlId]) || (int) $row['n'] > $best[$urlId][0]) {
				$best[$urlId] = [(int) $row['n'], (int) $row['cluster_id']];
			}
		}

		return array_map(static fn (array $value): int => $value[1], $best);
	}

	/**
	 * @param list<int> $datasetIds
	 * @param list<int> $ids
	 * @return array<int, array{count: int, top10: int, rank: int, competitor_id: int, url_id: int, evidence_on: ?string}>
	 */
	private function competitorEvidence(array $datasetIds, array $ids, int $maxRank): array
	{
		$rows = $this->db->fetchAll(
			"SELECT market_keyword_id, COUNT(*) AS n, SUM(rank_group <= 10) AS top10, MAX(serp_on) AS evidence_on,
				MIN(CONCAT(LPAD(rank_group, 3, '0'), ':', LPAD(domain_id, 10, '0'), ':', COALESCE(url_id, 0))) AS best
			FROM `{$this->rowsTable()}`
			WHERE domain_id IN (" . Connection::placeholders($datasetIds, '%d') . ') AND market_keyword_id IN (' . Connection::placeholders($ids, '%d') . ')
				AND present = 1 AND rank_group <= %d
			GROUP BY market_keyword_id',
			[...$datasetIds, ...$ids, $maxRank],
		);
		$result = [];

		foreach ($rows as $row) {
			[$rank, $datasetId, $urlId] = array_map('intval', explode(':', (string) $row['best']));
			$result[(int) $row['market_keyword_id']] = [
				'count' => (int) $row['n'],
				'top10' => (int) $row['top10'],
				'rank' => $rank,
				'competitor_id' => $this->competitorId($datasetId),
				'url_id' => $urlId,
				'evidence_on' => $row['evidence_on'],
			];
		}

		return $result;
	}

	private function competitorId(int $datasetId): int
	{
		return $this->datasetCompetitors[$datasetId] ?? 0;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, string|null>>
	 */
	private function metrics(array $ids): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT id, keyword, LOWER(HEX(keyword_key)) AS hex, search_volume, keyword_difficulty, cpc, search_intent, other_language, LOWER(HEX(core_key)) AS core
			FROM `{$this->db->table('market_keywords')}` WHERE id IN (" . Connection::placeholders($ids, '%d') . ')',
			$ids,
		) as $row) {
			$result[(int) $row['id']] = $row;
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, string|null>>
	 */
	private function baselineRows(int $datasetId, array $ids): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT market_keyword_id, rank_group, url_id FROM `{$this->rowsTable()}` WHERE domain_id = %d AND present = 1 AND market_keyword_id IN (" . Connection::placeholders($ids, '%d') . ')',
			[$datasetId, ...$ids],
		) as $row) {
			$result[(int) $row['market_keyword_id']] = $row;
		}

		return $result;
	}

	/**
	 * Sumy GSC okna dla kluczy rynkowych (wszystkie warianty frazy projektu; pozycja ważona wyświetleniami).
	 *
	 * @param array{0: string, 1: string} $window
	 * @param list<string> $hexes
	 * @return array<string, array{impressions: int, clicks: int, position_sum: float}>
	 */
	private function gsc(int $projectId, array $window, array $hexes): array
	{
		if ($hexes === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, SUM(q.impressions) AS impressions, SUM(q.clicks) AS clicks, SUM(q.position_sum) AS position_sum
			FROM `{$this->db->table('keywords')}` k
			JOIN `{$this->db->table('gsc_query_daily')}` q ON q.project_id = k.project_id AND q.keyword_id = k.id AND q.date BETWEEN %s AND %s
			WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($hexes, 'UNHEX(%s)') . ')
			GROUP BY k.market_key',
			[$window[0], $window[1], $projectId, ...$hexes],
		) as $row) {
			$result[(string) $row['h']] = ['impressions' => (int) $row['impressions'], 'clicks' => (int) $row['clicks'], 'position_sum' => (float) $row['position_sum']];
		}

		return $result;
	}

	/**
	 * Strony projektu z wyświetleniami dla fraz (GSC query × page, okno) — najwyżej 3 na frazę.
	 *
	 * @param array{0: string, 1: string} $window
	 * @param list<string> $hexes
	 * @return array<string, array<int, int>> klucz → id strony → wyświetlenia
	 */
	private function gscPages(int $projectId, array $window, array $hexes): array
	{
		if ($hexes === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN LOWER(HEX(k.market_key)) AS h, qp.page_id, SUM(qp.impressions) AS impressions
			FROM `{$this->db->table('keywords')}` k
			JOIN `{$this->db->table('gsc_query_page_daily')}` qp ON qp.project_id = k.project_id AND qp.keyword_id = k.id AND qp.date BETWEEN %s AND %s
			WHERE k.project_id = %d AND k.market_key IN (" . Connection::placeholders($hexes, 'UNHEX(%s)') . ')
			GROUP BY k.market_key, qp.page_id',
			[$window[0], $window[1], $projectId, ...$hexes],
		) as $row) {
			if ((int) $row['impressions'] > 0) {
				$result[(string) $row['h']][(int) $row['page_id']] = (int) $row['impressions'];
			}
		}

		foreach ($result as $hex => $pages) {
			arsort($pages);
			$result[$hex] = array_slice($pages, 0, 3, true);
		}

		return $result;
	}

	/**
	 * Strony GSC projektu → identyfikatory adresów w słowniku `serp_urls` (ta sama tożsamość adresu co w SERP i Labs).
	 *
	 * @param array<string|int, array<int, int>> $pages
	 * @return array<int, int> id strony → id adresu
	 */
	private function gscTargetUrls(int $projectId, array $pages): array
	{
		$pageIds = [];

		foreach ($pages as $list) {
			foreach (array_keys($list) as $pageId) {
				$pageIds[(int) $pageId] = true;
			}
		}

		if ($pageIds === []) {
			return [];
		}

		$byPage = [];

		foreach (array_chunk(array_keys($pageIds), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$url = DataForSeoSerpProvider::url((string) $row['url']);
				$host = $url === null ? null : DomainFamily::fromUrl($url);

				if ($url !== null && $host !== null) {
					$byPage[(int) $row['id']] = [$url, $host];
				}
			}
		}

		if ($byPage === []) {
			return [];
		}

		$hosts = $this->dictionary->domainIds(array_values(array_unique(array_map(static fn (array $page): string => $page[1], $byPage))));
		$urls = [];

		foreach ($byPage as [$url, $host]) {
			if (isset($hosts[$host])) {
				$urls[md5($url)] = ['url' => $url, 'domain_id' => $hosts[$host]];
			}
		}

		$ids = $this->dictionary->urlIds($urls);
		$result = [];

		foreach ($byPage as $pageId => [$url]) {
			if (isset($ids[md5($url)])) {
				$result[$pageId] = $ids[md5($url)];
			}
		}

		return $result;
	}

	/**
	 * Monitorowane frazy projektu ze świeżym pomiarem SERP (STEP 14).
	 *
	 * @param list<int> $ids
	 * @return array<int, array<string, string|null>>
	 */
	private function serp(int $projectId, array $ids, string $since): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT market_keyword_id, last_found, last_rank, last_depth, last_checked_at, last_url_id, last_snapshot_id
			FROM `{$this->db->table('serp_tracked_keywords')}`
			WHERE project_id = %d AND status = 'active' AND last_snapshot_id IS NOT NULL AND last_found IS NOT NULL AND last_checked_at >= %s
				AND market_keyword_id IN (" . Connection::placeholders($ids, '%d') . ')',
			[$projectId, $since, ...$ids],
		) as $row) {
			$result[(int) $row['market_keyword_id']] = $row;
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, array<string, string|null>>
	 */
	private function existing(int $projectId, array $ids): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT market_keyword_id, public_id, first_seen_at FROM `{$this->table()}` WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($ids, '%d') . ')',
			[$projectId, ...$ids],
		) as $row) {
			$result[(int) $row['market_keyword_id']] = $row;
		}

		return $result;
	}

	/**
	 * Adresy konkurentów dla fraz (grupowanie, strony dedykowane).
	 *
	 * @param list<int> $datasetIds
	 * @param list<int> $ids
	 * @return array<int, list<array{0: int, 1: int, 2: int}>> fraza → [zbiór, adres, pozycja]
	 */
	private function competitorUrls(array $datasetIds, array $ids, int $maxRank): array
	{
		$result = [];

		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT market_keyword_id, domain_id, url_id, rank_group FROM `{$this->rowsTable()}`
				WHERE domain_id IN (" . Connection::placeholders($datasetIds, '%d') . ') AND market_keyword_id IN (' . Connection::placeholders($chunk, '%d') . ')
					AND present = 1 AND rank_group <= %d AND url_id IS NOT NULL',
				[...$datasetIds, ...$chunk, $maxRank],
			) as $row) {
				$result[(int) $row['market_keyword_id']][] = [(int) $row['domain_id'], (int) $row['url_id'], (int) $row['rank_group']];
			}
		}

		return $result;
	}

	/**
	 * Adresy-huby (np. kategoria zbiorcza) nie łączą fraz w grupy: ponad 300 fraz albo 20% fraz zbioru.
	 *
	 * @param list<int> $datasetIds
	 * @return array<int, true>
	 */
	private function hubUrls(array $datasetIds): array
	{
		$result = [];

		foreach ($datasetIds as $datasetId) {
			$present = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `{$this->rowsTable()}` WHERE domain_id = %d AND present = 1", [$datasetId]);
			$threshold = max(GapConfig::HUB_MIN_KEYWORDS, (int) ceil(GapConfig::HUB_SHARE * $present));

			foreach ($this->db->fetchAll(
				"SELECT url_id FROM `{$this->rowsTable()}` WHERE domain_id = %d AND present = 1 AND url_id IS NOT NULL GROUP BY url_id HAVING COUNT(*) > %d",
				[$datasetId, $threshold],
			) as $row) {
				$result[(int) $row['url_id']] = true;
			}
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	private function urls(array $ids): array
	{
		$result = [];

		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll("SELECT id, url FROM `{$this->db->table('serp_urls')}` WHERE id IN (" . Connection::placeholders($chunk, '%d') . ')', $chunk) as $row) {
				$result[(int) $row['id']] = (string) $row['url'];
			}
		}

		return $result;
	}

	private function isRootUrl(int $urlId): bool
	{
		$url = $this->db->fetchValue("SELECT url FROM `{$this->db->table('serp_urls')}` WHERE id = %d", [$urlId]);

		return $url !== null && TextFold::isRoot($url);
	}

	/**
	 * Adresy TOP10 (wyniki organiczne) ostatnich migawek monitorowanych fraz.
	 *
	 * @param list<int> $snapshotIds
	 * @return array<int, list<int>>
	 */
	private function serpTop10(array $snapshotIds): array
	{
		$result = [];

		foreach (array_chunk(array_values(array_unique($snapshotIds)), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT snapshot_id, url_id FROM `{$this->db->table('serp_results')}` WHERE snapshot_id IN (" . Connection::placeholders($chunk, '%d') . ') AND result_type = %d AND rank_group <= 10',
				[...$chunk, SerpItem::TYPE_ORGANIC],
			) as $row) {
				$result[(int) $row['snapshot_id']][] = (int) $row['url_id'];
			}
		}

		return $result;
	}

	/**
	 * Strony projektu znane z GSC (okno danych) — kandydaci dopasowania adresu.
	 *
	 * @param array{0: string, 1: string}|null $window
	 * @return array{pages: array<int, string>, index: array<string, list<int>>}
	 */
	private function projectPages(int $projectId, ?array $window): array
	{
		if ($window === null) {
			return ['pages' => [], 'index' => []];
		}

		$pages = [];
		$index = [];

		foreach ($this->db->fetchAll(
			"SELECT id, url FROM `{$this->db->table('pages')}` WHERE project_id = %d AND last_seen >= %s LIMIT 20000",
			[$projectId, $window[0]],
		) as $row) {
			$id = (int) $row['id'];
			$pages[$id] = (string) $row['url'];

			foreach (array_unique(TextFold::pathTokens((string) $row['url'])) as $token) {
				$index[$token][] = $id;
			}
		}

		return ['pages' => $pages, 'index' => $index];
	}

	/**
	 * Strona projektu, której adres pokrywa co najmniej 60% wyrazów frazy wiodącej (min. 2 wyrazy, chyba że fraza ma jeden).
	 *
	 * @param array{pages: array<int, string>, index: array<string, list<int>>} $projectPages
	 * @param list<string> $tokens
	 */
	private function slugTarget(array $projectPages, array $tokens): ?int
	{
		$tokens = array_values(array_unique($tokens));
		$candidates = [];

		foreach ($tokens as $token) {
			foreach ($projectPages['index'][$token] ?? [] as $pageId) {
				$candidates[$pageId] = ($candidates[$pageId] ?? 0) + 1;
			}
		}

		$best = null;

		foreach ($candidates as $pageId => $hits) {
			$coverage = $hits / max(1, count($tokens));

			if ($coverage >= GapConfig::SLUG_COVERAGE && ($hits >= 2 || count($tokens) === 1) && ($best === null || $hits > $best[0] || ($hits === $best[0] && $pageId < $best[1]))) {
				$best = [$hits, $pageId];
			}
		}

		if ($best === null) {
			return null;
		}

		$url = DataForSeoSerpProvider::url($projectPages['pages'][$best[1]]);
		$host = $url === null ? null : DomainFamily::fromUrl($url);

		if ($url === null || $host === null) {
			return null;
		}

		$hosts = $this->dictionary->domainIds([$host]);

		return isset($hosts[$host]) ? ($this->dictionary->urlIds([md5($url) => ['url' => $url, 'domain_id' => $hosts[$host]]])[md5($url)] ?? null) : null;
	}

	private function table(): string
	{
		return $this->db->table('gap_keywords');
	}

	private function rowsTable(): string
	{
		return $this->db->table('gap_domain_keywords');
	}
}
