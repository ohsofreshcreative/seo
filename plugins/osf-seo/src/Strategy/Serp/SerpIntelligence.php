<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Market\Market;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Serp\SerpReports;
use OsfSeo\Serp\SerpSettingsRepository;
use OsfSeo\Support\Clock;

/**
 * SERP Intelligence (STEP 16, faza B — docs/ARCHITECTURE.md, sekcja 15.12): interpretacja zapisanych pomiarów STEP 14 projektu —
 * bez żadnych żądań do API i bez drugiego magazynu SERP.
 *
 * - **Kontekst analizy** = kontekst pomiarów projektu (rynek projektu, urządzenie i głębokość z ustawień Pozycji) — pomiar analizy jest
 *   porównywalny z historią monitorowania.
 * - **Zgodny pomiar**: ta sama wyszukiwarka, typ, lokalizacja, język i urządzenie oraz głębokość ≥ min(głębokość kontekstu, 20)
 *   (TOP20 potrzebne do kompozycji). Używany jest najnowszy zakończony zgodny pomiar frazy (dowolny status monitorowania).
 * - **Świeżość** (`SerpFreshness`): Pozycja SERP projektu tylko z pomiaru ≤ 30 dni; 31–90 dni — kształt z obniżoną pewnością; > 90 — brak.
 * - Wszystkie odczyty zawężone do projektu (`project_id`).
 */
final class SerpIntelligence
{
	/** Głębokość potrzebna do kompozycji TOP20. */
	public const ANALYSIS_DEPTH = 20;

	private const CHUNK = 500;

	public function __construct(
		private readonly Connection $db,
		private readonly SerpProfileRepository $profiles,
		private readonly SerpSettingsRepository $settings,
		private readonly CompetitorRepository $competitors,
		private readonly SerpReports $reports,
		private readonly Clock $clock,
	) {
	}

	/** Kontekst analizy projektu: rynek projektu, urządzenie i głębokość z ustawień Pozycji (bez zapisu). */
	public function analysisContext(int $projectId, Market $market): SerpContext
	{
		$settings = $this->settings->get($projectId);

		return SerpContext::forMarket($market, $settings->device, $settings->depth);
	}

	/** Klucz zgodności kontekstów (bez głębokości) — do porównań overlapu. */
	public static function contextKey(int $locationCode, string $languageCode, string $device): string
	{
		return $locationCode . '|' . $languageCode . '|' . $device;
	}

	/**
	 * Najnowszy zakończony zgodny pomiar każdej frazy rynkowej projektu.
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array<int, array{snapshot_id: int, public_id: string, tracked_keyword_id: int, tracked_status: string, checked_at: string, depth: int, device: string, context_key: string, project_rank: ?int, project_url: ?string, project_featured: bool, item_types: int, spell_type: ?string}>
	 */
	public function latest(int $projectId, SerpContext $required, array $marketKeywordIds): array
	{
		$contexts = $this->compatibleContexts($required);
		$marketKeywordIds = array_values(array_unique(array_map('intval', $marketKeywordIds)));

		if ($contexts === [] || $marketKeywordIds === []) {
			return [];
		}

		$tracked = [];

		foreach (array_chunk($marketKeywordIds, self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT id, market_keyword_id, status FROM `{$this->db->table('serp_tracked_keywords')}`
				WHERE project_id = %d AND market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$projectId, ...$chunk],
			) as $row) {
				$tracked[(int) $row['id']] = ['market_keyword_id' => (int) $row['market_keyword_id'], 'status' => (string) $row['status']];
			}
		}

		$result = [];

		foreach (array_chunk(array_keys($tracked), self::CHUNK) as $chunk) {
			// Indeks `tracked_history` (fraza, kontekst, data) — bez skanowania wszystkich pomiarów projektu.
			foreach ($this->db->fetchAll(
				"SELECT x.* FROM (
					SELECT s.id, s.public_id, s.tracked_keyword_id, s.context_id, s.checked_at, s.project_rank, s.project_featured, s.item_types, s.spell_type,
						u.url AS project_url,
						ROW_NUMBER() OVER (PARTITION BY s.tracked_keyword_id ORDER BY s.checked_at DESC, s.id DESC) AS rn
					FROM `{$this->db->table('serp_snapshots')}` s LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = s.project_url_id
					WHERE s.project_id = %d AND s.tracked_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')
						AND s.context_id IN (' . Connection::placeholders(array_keys($contexts), '%d') . ") AND s.status = 'completed' AND s.checked_at IS NOT NULL
				) x WHERE x.rn = 1",
				[$projectId, ...$chunk, ...array_keys($contexts)],
			) as $row) {
				$context = $contexts[(int) $row['context_id']];
				$track = $tracked[(int) $row['tracked_keyword_id']];
				$result[$track['market_keyword_id']] = [
					'snapshot_id' => (int) $row['id'],
					'public_id' => (string) $row['public_id'],
					'tracked_keyword_id' => (int) $row['tracked_keyword_id'],
					'tracked_status' => $track['status'],
					'checked_at' => (string) $row['checked_at'],
					'depth' => $context->depth,
					'device' => $context->device->value,
					'context_key' => self::contextKey($context->locationCode, $context->languageCode, $context->device->value),
					'project_rank' => $row['project_rank'] === null ? null : (int) $row['project_rank'],
					'project_url' => $row['project_url'],
					'project_featured' => (int) $row['project_featured'] === 1,
					'item_types' => (int) $row['item_types'],
					'spell_type' => $row['spell_type'],
				];
			}
		}

		return $result;
	}

	/**
	 * Dowody SERP Intelligence fraz projektu (Strategia): najnowszy zgodny pomiar, świeżość, profil (kształt, kompozycja, sygnał
	 * intencji), obecność projektu (Pozycja SERP tylko ze świeżego pomiaru; z domeną projektu — także wszystkie adresy projektu
	 * w TOP10 świeżego pomiaru), aktywnych konkurentów w TOP10/TOP20 i korektę pisowni wyszukiwarki (`spell`).
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array<int, array<string, mixed>> id frazy rynkowej → dowód (`_facts` z identyfikatorem pomiaru)
	 */
	public function evidence(int $projectId, Market $market, array $marketKeywordIds, bool $persist = true, ?string $projectDomain = null): array
	{
		$context = $this->analysisContext($projectId, $market);
		$latest = $this->latest($projectId, $context, $marketKeywordIds);

		if ($latest === []) {
			return [];
		}

		$now = $this->clock->now();
		$usable = array_filter($latest, static fn (array $row): bool => SerpFreshness::usableForClassification(SerpFreshness::of($row['checked_at'], $now)));
		$profiles = $this->profiles->ensure($projectId, array_values(array_map(static fn (array $row): int => $row['snapshot_id'], $usable)), $persist);
		$competitors = $this->competitors->active($projectId);
		$found = $this->reports->familyResults(array_values(array_map(static fn (array $row): int => $row['snapshot_id'], $usable)), $this->families($competitors));
		$own = $projectDomain === null ? [] : $this->reports->familyResults(
			array_values(array_map(static fn (array $row): int => $row['snapshot_id'], array_filter($latest, static fn (array $row): bool => SerpFreshness::allowsProjectRank(SerpFreshness::of($row['checked_at'], $now))))),
			['project' => $this->reports->familyDomainIds($projectDomain)],
		);
		$names = [];

		foreach ($competitors as $competitor) {
			$names[$competitor->publicId] = $competitor->name;
		}

		$result = [];

		foreach ($latest as $marketKeywordId => $row) {
			$freshness = SerpFreshness::of($row['checked_at'], $now);
			$profile = $profiles[$row['snapshot_id']] ?? null;
			$rivals = [];

			foreach ($found[$row['snapshot_id']] ?? [] as $publicId => $ranks) {
				if ($ranks[0]['rank'] <= 20) {
					$rivals[] = ['id' => (string) $publicId, 'name' => $names[$publicId] ?? '', 'rank' => $ranks[0]['rank']];
				}
			}

			usort($rivals, static fn (array $a, array $b): int => [$a['rank'], $a['id']] <=> [$b['rank'], $b['id']]);
			$rankAllowed = SerpFreshness::allowsProjectRank($freshness);
			$top10 = [];

			foreach ($own[$row['snapshot_id']]['project'] ?? [] as $result10) {
				if ($result10['rank'] <= 10) {
					$top10[$result10['url']] ??= $result10['rank'];
				}
			}

			$result[$marketKeywordId] = [
				'_facts' => ['snapshot_id' => $row['snapshot_id']],
				'snapshot' => $row['public_id'],
				'checked_at' => $row['checked_at'],
				'freshness' => $freshness,
				'tracking' => $row['tracked_status'],
				'context' => ['device' => $row['device'], 'depth' => $row['depth']],
				'profile' => $profile?->toArray($freshness),
				'project' => $rankAllowed
					? ['found' => $row['project_rank'] !== null, 'rank' => $row['project_rank'], 'url' => $row['project_url'], 'featured' => $row['project_featured']]
						+ ($projectDomain === null ? [] : ['top10' => array_map(static fn (string $url, int $rank): array => ['url' => $url, 'rank' => $rank], array_keys($top10), array_values($top10))])
					: null,
				'spell' => SerpFreshness::usableForClassification($freshness) && $row['spell_type'] !== null && $row['spell_type'] !== '' ? (string) $row['spell_type'] : null,
				'competitors' => SerpFreshness::usableForClassification($freshness) ? [
					'top10' => count(array_filter($rivals, static fn (array $rival): bool => $rival['rank'] <= 10)),
					'top20' => count($rivals),
					'best' => $rivals === [] ? null : $rivals[0],
				] : null,
			];
		}

		return $result;
	}

	/**
	 * Szczegóły SERP Intelligence frazy: dowód (jak w `evidence`) i TOP20 wyników organicznych najnowszego zgodnego pomiaru z kształtem,
	 * pewnością i powodem klasyfikacji oraz oznaczeniem projektu i konkurentów. Null — fraza bez zgodnego pomiaru.
	 *
	 * @return array<string, mixed>|null
	 */
	public function detail(int $projectId, Market $market, int $marketKeywordId, string $projectDomain, bool $persist = true): ?array
	{
		$evidence = $this->evidence($projectId, $market, [$marketKeywordId], $persist)[$marketKeywordId] ?? null;

		if ($evidence === null) {
			return null;
		}

		$snapshotId = (int) $evidence['_facts']['snapshot_id'];
		$profile = SerpFreshness::usableForClassification($evidence['freshness']) ? ($this->profiles->ensure($projectId, [$snapshotId], $persist)[$snapshotId] ?? null) : null;
		$shapes = [];

		foreach ($profile?->results ?? [] as [$rank, $shape, $confidence, $reason]) {
			$shapes[(int) $rank] = ['shape' => $shape, 'confidence' => $confidence, 'reason' => $reason];
		}

		$competitors = $this->competitors->active($projectId);
		$results = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN r.rank_group, d.host, u.url FROM `{$this->db->table('serp_results')}` r
			JOIN `{$this->db->table('serp_domains')}` d ON d.id = r.domain_id JOIN `{$this->db->table('serp_urls')}` u ON u.id = r.url_id
			WHERE r.snapshot_id = %d AND r.result_type = %d AND r.rank_group <= 20 ORDER BY r.rank_group",
			[$snapshotId, SerpItem::TYPE_ORGANIC],
		) as $row) {
			$host = (string) $row['host'];
			$competitor = null;

			foreach ($competitors as $candidate) {
				if (DomainFamily::matches($host, $candidate->domain)) {
					$competitor = $candidate->name;

					break;
				}
			}

			$results[] = [
				'rank' => (int) $row['rank_group'],
				'host' => $host,
				'url' => (string) $row['url'],
				'project' => DomainFamily::matches($host, $projectDomain),
				'competitor' => $competitor,
			] + ($shapes[(int) $row['rank_group']] ?? ['shape' => null, 'confidence' => null, 'reason' => null]);
		}

		unset($evidence['_facts']);

		return $evidence + ['results' => $results];
	}

	/**
	 * Overlap SERP dwóch fraz projektu (najnowsze zgodne pomiary, domeny wszechobecne wśród świeżych pomiarów projektu w kontekście).
	 *
	 * @return array<string, mixed>
	 */
	public function overlap(int $projectId, Market $market, int $first, int $second, bool $persist = true): array
	{
		$context = $this->analysisContext($projectId, $market);
		$latest = $this->latest($projectId, $context, [$first, $second]);
		$sides = [];

		foreach ([$first, $second] as $marketKeywordId) {
			$sides[] = $this->side($projectId, $marketKeywordId, $latest[$marketKeywordId] ?? null, $persist);
		}

		$ubiquity = $this->ubiquity($projectId, $context);
		$result = SerpOverlap::compare($sides[0], $sides[1], $ubiquity['domains'], $ubiquity['known']);
		$urls = $this->urls(array_column($result['urls'], 'url_id'));
		$result['urls'] = array_map(static fn (array $url): array => [
			'url' => $urls[$url['url_id']] ?? null,
			'rank_a' => $url['rank_a'],
			'rank_b' => $url['rank_b'],
			'counted' => $url['counted'],
		], $result['urls']);

		return $result + [
			'keywords' => array_map(static fn (OverlapSide $side): array => [
				'keyword' => $side->keyword,
				'snapshot' => $side->snapshotId === null ? null : ($latest[$side->marketKeywordId]['public_id'] ?? null),
				'checked_at' => $latest[$side->marketKeywordId]['checked_at'] ?? null,
				'freshness' => $side->freshness,
				'serp_intent' => $side->serpIntent?->value,
				'provider_intent' => $side->providerIntent,
			], $sides),
			'ubiquitous' => ['known' => $ubiquity['known'], 'measurements' => $ubiquity['measurements'], 'threshold' => $ubiquity['threshold'], 'domains' => $this->hosts(array_keys($ubiquity['domains']))],
		];
	}

	/**
	 * Domeny wszechobecne: z TOP10 najnowszych świeżych zgodnych pomiarów wszystkich fraz projektu (każdy status monitorowania).
	 *
	 * @return array{domains: array<int, bool>, known: bool, measurements: int, threshold: int}
	 */
	public function ubiquity(int $projectId, SerpContext $context): array
	{
		$ids = array_map(static fn (array $row): int => (int) $row['market_keyword_id'], $this->db->fetchAll(
			"SELECT market_keyword_id FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d AND last_checked_at >= %s",
			[$projectId, SerpFreshness::since($this->clock->now(), SerpFreshness::FRESH_DAYS)],
		));
		$now = $this->clock->now();
		$fresh = array_filter($this->latest($projectId, $context, $ids), static fn (array $row): bool => SerpFreshness::of($row['checked_at'], $now) === SerpFreshness::FRESH);
		$sets = [];

		foreach (array_chunk(array_values(array_map(static fn (array $row): int => $row['snapshot_id'], $fresh)), self::CHUNK) as $chunk) {
			$bySnapshot = [];

			foreach ($this->db->fetchAll(
				'SELECT snapshot_id, domain_id FROM `' . $this->db->table('serp_results') . '` WHERE snapshot_id IN (' . Connection::placeholders($chunk, '%d') . ') AND result_type = %d AND rank_group <= 10',
				[...$chunk, SerpItem::TYPE_ORGANIC],
			) as $row) {
				$bySnapshot[(int) $row['snapshot_id']][(int) $row['domain_id']] = true;
			}

			$sets = [...$sets, ...array_values($bySnapshot)];
		}

		return SerpOverlap::ubiquitous($sets);
	}

	/**
	 * @param array{snapshot_id: int, checked_at: string, context_key: string}|null $latest
	 */
	private function side(int $projectId, int $marketKeywordId, ?array $latest, bool $persist): OverlapSide
	{
		$market = $this->db->fetchRow("SELECT keyword, search_intent FROM `{$this->db->table('market_keywords')}` WHERE id = %d", [$marketKeywordId]) ?? [];
		$keyword = (string) ($market['keyword'] ?? '');
		$intent = $market['search_intent'] ?? null;

		if ($latest === null) {
			return new OverlapSide($marketKeywordId, $keyword, null, null, null, [], null, null, $intent);
		}

		$freshness = SerpFreshness::of($latest['checked_at'], $this->clock->now());
		$profile = SerpFreshness::usableForClassification($freshness) ? ($this->profiles->ensure($projectId, [$latest['snapshot_id']], $persist)[$latest['snapshot_id']] ?? null) : null;
		$homes = [];

		foreach ($profile?->results ?? [] as [$rank, $shape]) {
			$homes[(int) $rank] = $shape === ResultShape::Home->value;
		}

		$top10 = [];

		foreach ($this->db->fetchAll(
			'SELECT rank_group, domain_id, url_id FROM `' . $this->db->table('serp_results') . '` WHERE snapshot_id = %d AND result_type = %d AND rank_group <= 10 ORDER BY rank_group',
			[$latest['snapshot_id'], SerpItem::TYPE_ORGANIC],
		) as $row) {
			$top10[(int) $row['url_id']] ??= ['rank' => (int) $row['rank_group'], 'domain_id' => (int) $row['domain_id'], 'home' => $homes[(int) $row['rank_group']] ?? false];
		}

		return new OverlapSide(
			$marketKeywordId,
			$keyword,
			$latest['snapshot_id'],
			$latest['context_key'],
			$freshness,
			$top10,
			$profile?->intent,
			$profile === null ? null : SerpConfidence::forFreshness($profile->intentConfidence, $freshness),
			$intent,
		);
	}

	/**
	 * Konteksty zgodne z wymaganym (bez głębokości mniejszej niż potrzebna do kompozycji).
	 *
	 * @return array<int, SerpContext>
	 */
	private function compatibleContexts(SerpContext $required): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT * FROM `{$this->db->table('serp_contexts')}` WHERE engine = %s AND serp_type = %s AND location_code = %d AND language_code = %s AND device = %s AND depth >= %d",
			[$required->engine, $required->serpType, $required->locationCode, $required->languageCode, $required->device->value, min($required->depth, self::ANALYSIS_DEPTH)],
		) as $row) {
			$result[(int) $row['id']] = SerpContext::fromRow($row);
		}

		return $result;
	}

	/**
	 * @param list<Competitor> $competitors
	 * @return array<string, list<int>>
	 */
	private function families(array $competitors): array
	{
		$families = [];

		foreach ($competitors as $competitor) {
			$families[$competitor->publicId] = $this->reports->familyDomainIds($competitor->domain);
		}

		return $families;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, string>
	 */
	private function urls(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$result = [];

		foreach ($this->db->fetchAll('SELECT id, url FROM `' . $this->db->table('serp_urls') . '` WHERE id IN (' . Connection::placeholders($ids, '%d') . ')', $ids) as $row) {
			$result[(int) $row['id']] = (string) $row['url'];
		}

		return $result;
	}

	/**
	 * @param list<int> $ids
	 * @return list<string>
	 */
	private function hosts(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$hosts = array_map(static fn (array $row): string => (string) $row['host'], $this->db->fetchAll(
			'SELECT host FROM `' . $this->db->table('serp_domains') . '` WHERE id IN (' . Connection::placeholders($ids, '%d') . ')',
			$ids,
		));
		sort($hosts);

		return $hosts;
	}
}
