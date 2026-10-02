<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Sources;

use OsfSeo\Database\Connection;
use OsfSeo\Serp\RankChange;
use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\Serp\SerpIntelligence;
use OsfSeo\Strategy\Serp\SerpProfiler;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategySource;

/**
 * Pozycje (STEP 14): monitorowane frazy projektu są kandydatami (jawny wybór użytkownika; frazy analizowane jednorazowo przez Strategię
 * — `analysis` — nie są źródłem). Dowody — bieżący stan frazy (ostatni pomiar, Pozycja SERP, URL, zmiana względem poprzedniego
 * porównywalnego pomiaru, przejścia pasm TOP3/10/20, sygnał spadku) dla każdego kandydata z wierszem monitorowania (każdy status —
 * pomiar zostaje dowodem) oraz SERP Intelligence (`intel`, faza B): najnowszy zgodny pomiar w kontekście projektu, świeżość, profil
 * (kształt, kompozycja TOP10/TOP20, sygnał intencji z SERP), Pozycja SERP projektu tylko ze świeżego pomiaru, konkurenci.
 * Pozycja SERP jest osobną metryką — nigdy nie zastępuje średniej pozycji (GSC).
 */
final class SerpSource implements CandidateSource
{
	private const CHUNK = 500;

	/** Pasma Pozycji SERP, których przejścia zapisujemy w dowodach. */
	private const BANDS = ['top3' => 3, 'top10' => 10, 'top20' => 20];

	public function __construct(
		private readonly Connection $db,
		private readonly SerpIntelligence $intelligence,
	) {
	}

	public function source(): StrategySource
	{
		return StrategySource::Serp;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, COALESCE(SUM(last_snapshot_id), 0) AS s, COALESCE(MAX(last_snapshot_id), 0) AS mx,
				COALESCE(SUM(CRC32(CONCAT_WS(':', id, status))), 0) AS c
			FROM `{$this->db->table('serp_tracked_keywords')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];
		// Kontekst analizy (urządzenie, głębokość) i reguły profilu zmieniają dowody SERP Intelligence bez nowych pomiarów.
		$settings = $this->db->fetchRow("SELECT device, depth FROM `{$this->db->table('serp_settings')}` WHERE project_id = %d", [$scope->projectId]) ?? [];

		return implode(':', [$row['n'] ?? 0, $row['s'] ?? 0, $row['mx'] ?? 0, $row['c'] ?? 0, $settings['device'] ?? '', $settings['depth'] ?? '', SerpProfiler::VERSION]);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$signals = [];

		foreach ($this->db->fetchAll(
			"SELECT STRAIGHT_JOIN t.market_keyword_id, LOWER(HEX(m.keyword_key)) AS h, m.keyword, m.search_intent
			FROM `{$this->db->table('serp_tracked_keywords')}` t JOIN `{$this->db->table('market_keywords')}` m ON m.id = t.market_keyword_id
			WHERE t.project_id = %d AND t.status = 'active' AND " . MarketKeywordLookup::marketCondition() . ' ORDER BY t.id',
			[$scope->projectId, ...MarketKeywordLookup::params($scope->market)],
		) as $row) {
			$signals[] = new SourceSignal((string) $row['h'], (int) $row['market_keyword_id'], (string) $row['keyword'], $row['search_intent'], StrategySource::Serp, SourceSignal::TIER_SERP, 0.0);
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$result = [];

		foreach (array_chunk(array_keys($keys), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT t.id, t.market_keyword_id, t.public_id, t.status, t.source, t.last_snapshot_id, t.last_checked_at, t.last_found, t.last_rank,
					t.last_rank_absolute, t.last_url_id, t.last_depth, t.last_featured, t.prev_rank, t.change_type, t.change_value, t.top10_change, u.url
				FROM `{$this->db->table('serp_tracked_keywords')}` t LEFT JOIN `{$this->db->table('serp_urls')}` u ON u.id = t.last_url_id
				WHERE t.project_id = %d AND t.market_keyword_id IN (" . Connection::placeholders($chunk, '%d') . ')',
				[$scope->projectId, ...$chunk],
			) as $row) {
				$result[(int) $row['market_keyword_id']] = self::describe($row);
			}
		}

		if ($result !== []) {
			foreach ($this->intelligence->evidence($scope->projectId, $scope->market, array_keys($result), ! $scope->dryRun) as $id => $intel) {
				$result[$id]['intel'] = $intel;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, string|null> $row wiersz `serp_tracked_keywords` (+ `url`)
	 * @return array<string, mixed>
	 */
	public static function describe(array $row): array
	{
		$checked = $row['last_snapshot_id'] !== null && $row['last_checked_at'] !== null;
		$rank = $checked && $row['last_rank'] !== null ? (int) $row['last_rank'] : null;
		$previous = $row['prev_rank'] === null ? null : (int) $row['prev_rank'];
		$change = $checked ? $row['change_type'] : null;
		$comparable = in_array($change, [RankChange::UP, RankChange::DOWN, RankChange::SAME, RankChange::ENTERED, RankChange::LEFT, RankChange::OUT], true);
		$bands = [];

		foreach (self::BANDS as $band => $limit) {
			$was = $previous !== null && $previous <= $limit;
			$is = $rank !== null && $rank <= $limit;
			$bands[$band] = ! $comparable || $was === $is ? null : ($is ? 'entered' : 'left');
		}

		$value = $row['change_value'] === null ? null : (int) $row['change_value'];
		$decline = $comparable && (
			$change === RankChange::LEFT
			|| ($change === RankChange::DOWN && $value !== null && $value <= -StrategyConfig::SERP_DECLINE_POSITIONS)
			|| in_array('left', $bands, true)
		);

		return [
			'_facts' => ['tracked_keyword_id' => (int) $row['id'], 'url_id' => $checked && $row['last_url_id'] !== null ? (int) $row['last_url_id'] : null],
			'id' => (string) $row['public_id'],
			'status' => (string) $row['status'],
			'source' => (string) $row['source'],
			'checked_at' => $checked ? (string) $row['last_checked_at'] : null,
			'found' => $checked ? (int) $row['last_found'] === 1 : null,
			'rank' => $rank,
			'rank_absolute' => $checked && $row['last_rank_absolute'] !== null ? (int) $row['last_rank_absolute'] : null,
			'url' => $checked ? $row['url'] : null,
			'depth' => $checked && $row['last_depth'] !== null ? (int) $row['last_depth'] : null,
			'featured' => $checked ? (int) $row['last_featured'] === 1 : null,
			'prev_rank' => $comparable ? $previous : null,
			'change' => $change,
			'change_value' => $comparable ? $value : null,
			'top10' => $checked ? $row['top10_change'] : null,
			'bands' => $bands,
			'decline' => $decline,
		];
	}
}
