<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Database\Connection;
use OsfSeo\Discovery\DiscoverySettingsRepository;
use OsfSeo\Gap\BrandMatcher;
use OsfSeo\Gap\GapSettingsRepository;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Strategy\Sources\GscSource;
use OsfSeo\Strategy\Sources\MarketKeywordLookup;
use OsfSeo\Strategy\Topics\TopicRefresher;
use OsfSeo\Support\Clock;
use OsfSeo\Support\DateRange;

/**
 * Przeliczenie kandydatów Strategii projektu (faza A — docs/ARCHITECTURE.md, sekcje 15.2–15.6) — bez żadnego żądania do API,
 * wyłącznie w CLI albo w tle (nigdy przy renderowaniu strony):
 *
 * 1. klucz danych: wersja reguł, dzień, rynek, projekt, stan GSC, filtry (marka, wykluczenia, konkurenci), konfiguracja i odciski
 *    źródeł — także mutacje ręczne we wszystkich modułach i rewizja wpisów ręcznych Strategii; bez zmian → nic do zrobienia,
 * 2. sygnały źródeł → scalenie, filtry i limit (`CandidateCollector`),
 * 3. brakujące frazy rynkowe (frazy GSC) → `market_keywords` (bez danych, bez wzbogacania),
 * 4. fakty i dowody (`EvidenceBuilder`) → zapis przyrostowy (`StrategyKeywordRepository::sync`),
 * 5. tematy (faza C): strona docelowa, grupowanie, stabilne ID, działanie, pewność, priorytet, zdarzenia (`TopicRefresher`).
 *
 * Podgląd (`preview`) wykonuje kroki 1–2 bez żadnego zapisu.
 */
final class StrategyRefresher
{
	/** Wersja reguł — zmiana unieważnia klucz danych wszystkich projektów (przeliczenie lokalne, bez API). */
	public const VERSION = 5;

	public const SKIPPED_UNCHANGED = 'unchanged';

	public const SKIPPED_LOCKED = 'locked';

	public const SKIPPED_UNSUPPORTED_MARKET = 'unsupported_market';

	public const SKIPPED_NO_PROJECT = 'no_project';

	/**
	 * @param list<CandidateSource> $sources
	 */
	public function __construct(
		private readonly Connection $db,
		private readonly array $sources,
		private readonly GscSource $gsc,
		private readonly StrategyKeywordRepository $keywords,
		private readonly StrategySettingsRepository $settings,
		private readonly KeywordMetricsProvider $provider,
		private readonly MarketMetricsRepository $metrics,
		private readonly MarketKeywordLookup $lookup,
		private readonly MarketKeyBackfill $backfill,
		private readonly DiscoverySettingsRepository $discoverySettings,
		private readonly GapSettingsRepository $gapSettings,
		private readonly CompetitorRepository $competitors,
		private readonly StrategyConfig $config,
		private readonly Clock $clock,
		private readonly TopicRefresher $topics,
	) {
	}

	/**
	 * Zakres przeliczenia projektu albo null (brak projektu, rynek nieobsługiwany).
	 */
	public function scope(int $projectId, bool $dryRun = false): ?SourceScope
	{
		$project = $this->project($projectId);
		$market = $project === null ? null : $this->provider->resolveMarket((string) $project['country'], (string) $project['language']);

		if ($project === null || $market === null) {
			return null;
		}

		$latest = $this->db->fetchValue("SELECT MAX(date) FROM `{$this->db->table('gsc_query_daily')}` WHERE project_id = %d", [$projectId]);

		return new SourceScope(
			$projectId,
			$market,
			DomainFamily::normalize((string) $project['domain']),
			(string) $project['name'],
			$latest === null ? null : [DateRange::shift($latest, -($this->config->windowDays() - 1)), $latest],
			$this->config,
			$dryRun,
		);
	}

	/**
	 * Klucz danych (hex) — tanie zapytania, bez zapisu. Zawiera mutacje ręczne wszystkich modułów (odciski źródeł) i rewizję
	 * wpisów ręcznych Strategii — nie tylko czas ostatniego importu.
	 */
	public function dataKey(SourceScope $scope): string
	{
		$project = $this->project($scope->projectId) ?? [];
		$gap = $this->gapSettings->get($scope->projectId);
		$fingerprints = [];

		foreach ($this->sources as $source) {
			$fingerprints[$source->source()->value] = $source->fingerprint($scope);
		}

		ksort($fingerprints);

		return md5((string) json_encode([
			self::VERSION,
			// Intencja i metryki dostawcy zmieniają się bez importu GSC — przeliczenie co najmniej raz dziennie.
			$this->clock->now()->format('Y-m-d'),
			$scope->market->id(),
			$project['name'] ?? '',
			$project['domain'] ?? '',
			$scope->window,
			$this->discoverySettings->exclusions($scope->projectId)->hash(),
			$gap->brandTerms,
			array_map(
				static fn (Competitor $competitor): array => [$competitor->id, $competitor->domain, $competitor->name, $competitor->status, $competitor->brandTerms],
				$this->competitors->all($scope->projectId, true),
			),
			$this->config->effective(),
			$fingerprints,
		]));
	}

	/**
	 * Podgląd kandydatów bez żadnego zapisu: liczby źródeł, filtry, limit, próbka najważniejszych kandydatów.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(int $projectId, int $sample = 20): array
	{
		$scope = $this->scope($projectId, true);

		if ($scope === null) {
			return ['skipped' => $this->project($projectId) === null ? self::SKIPPED_NO_PROJECT : self::SKIPPED_UNSUPPORTED_MARKET];
		}

		$set = $this->collect($scope);

		return [
			'skipped' => null,
			'dry_run' => true,
			'api_requests' => 0,
			'market' => $scope->market->label(),
			'gsc' => $this->gscState($scope),
			'stats' => $set->stats(),
			'sample' => array_map(static fn (Candidate $candidate): array => [
				'keyword' => $candidate->keyword,
				'sources' => $candidate->sourceCodes(),
				'tier' => $candidate->tier,
				'weight' => $candidate->weight,
				'new_market_keyword' => $candidate->marketKeywordId === null,
			], array_slice($set->selected, 0, max(0, $sample))),
		];
	}

	/**
	 * Przeliczenie i zapis kandydatów (tylko po zmianie klucza danych albo z `$force`) pod blokadą projektu — bez równoległego przeliczenia
	 * tego samego projektu (inny proces → `locked`).
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(int $projectId, bool $force = false): array
	{
		$lock = self::lock($projectId);

		if (! $this->db->acquireLock($lock, 0)) {
			// Ten projekt przelicza właśnie inny proces (CLI albo krok w tle) — bez równoległego przeliczenia.
			return ['skipped' => self::SKIPPED_LOCKED];
		}

		try {
			return $this->refreshLocked($projectId, $force);
		} finally {
			$this->db->releaseLock($lock);
		}
	}

	/**
	 * Przeliczenie, gdy wywołujący trzyma już blokadę `self::lock($projectId)` (zadanie przeliczenia: przejęcie, przeliczenie i zakończenie
	 * pod jedną blokadą — `StrategyRefreshRunner`). Klucz danych liczony przed pracą: dane zapisane w trakcie zostawiają klucz nieaktualny
	 * i wykrywa je kolejne sprawdzenie. Statystyki zawierają czasy faz (zbieranie, fakty i dowody, zapis kandydatów, tematy).
	 *
	 * @return array<string, mixed>
	 */
	public function refreshLocked(int $projectId, bool $force = false): array
	{
		$started = microtime(true);
		$startedAt = $this->clock->now()->format('Y-m-d H:i:s');
		$scope = $this->scope($projectId);

		if ($scope === null) {
			return ['skipped' => $this->project($projectId) === null ? self::SKIPPED_NO_PROJECT : self::SKIPPED_UNSUPPORTED_MARKET];
		}

		$key = $this->dataKey($scope);

		if (! $force && $this->settings->get($projectId)->dataKey === $key) {
			return ['skipped' => self::SKIPPED_UNCHANGED, 'data_key' => $key];
		}

		// Klucze rynkowe fraz GSC (wyliczane także w tle) — przed zbieraniem, potem klucz danych od nowa (inna liczba fraz bez klucza).
		$this->backfill->fillProject($projectId);
		$key = $this->dataKey($scope);
		$mark = microtime(true);
		$timings = ['key' => self::ms($started, $mark)];
		$set = $this->collect($scope);
		$selected = $this->resolveMarketKeywords($scope, $set->selected);
		$timings['collect'] = self::ms($mark, $mark = microtime(true));
		$facts = (new EvidenceBuilder($this->sources))->build($scope, $selected);
		$timings['evidence'] = self::ms($mark, $mark = microtime(true));
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$report = $this->keywords->sync($projectId, $scope->market, $facts, $this->inactiveReasons($scope, $set, $facts), $now);
		$timings['save'] = self::ms($mark, $mark = microtime(true));
		$gsc = $this->gscState($scope);
		// Tematy (faza C) — po zapisie kandydatów, pod tą samą blokadą.
		$topics = $this->topics->refresh($scope, $gsc['complete']['query'] && $gsc['complete']['query_page'], $now);
		$timings['topics'] = self::ms($mark, microtime(true));
		$durationMs = self::ms($started, microtime(true));
		$stats = $set->stats() + ['gsc' => $gsc, 'market' => $scope->market->label(), 'topics' => $topics, 'keywords' => $report, 'timings' => $timings];
		$this->settings->recordRefresh($projectId, $key, $durationMs, $stats, $scope->market, $startedAt);

		return ['skipped' => null, 'data_key' => $key, 'duration_ms' => $durationMs, 'stats' => $stats, 'topics' => $topics] + $report;
	}

	/**
	 * Aktualny klucz danych projektu (hex) albo null (brak projektu, rynek nieobsługiwany) — bez zapisu.
	 */
	public function currentKey(int $projectId): ?string
	{
		$scope = $this->scope($projectId, true);

		return $scope === null ? null : $this->dataKey($scope);
	}

	/** Przeliczenie projektu trwa właśnie w innym procesie (CLI albo krok w tle) — tylko odczyt blokady. */
	public function running(int $projectId): bool
	{
		return $this->db->lockInUse(self::lock($projectId));
	}

	public static function lock(int $projectId): string
	{
		return 'strategy_refresh_' . $projectId;
	}

	private static function ms(float $from, float $to): int
	{
		return (int) round(($to - $from) * 1000);
	}

	/**
	 * Stan GSC zakresu: okno, kompletność danych fraz w oknie i frazy bez klucza rynkowego (podgląd ich nie uwzględnia — klucze
	 * wylicza krok w tle albo przeliczenie).
	 *
	 * @return array{window: ?array{0: string, 1: string}, complete: array{query: bool, query_page: bool}, unkeyed: int}
	 */
	public function gscState(SourceScope $scope): array
	{
		return ['window' => $scope->window, 'complete' => $this->gsc->coverage($scope), 'unkeyed' => $this->gsc->unkeyed($scope)];
	}

	private function collect(SourceScope $scope): CandidateSet
	{
		$batches = [];

		foreach ($this->sources as $source) {
			$batches[] = $source->signals($scope);
		}

		return (new CandidateCollector())->collect($batches, $this->filter($scope), $this->config->maxKeywords());
	}

	private function filter(SourceScope $scope): CandidateFilter
	{
		$gap = $this->gapSettings->get($scope->projectId);
		$competitors = [];

		foreach ($this->competitors->active($scope->projectId) as $competitor) {
			$domain = DomainFamily::normalize($competitor->domain);

			if ($domain !== null && ($scope->projectDomain === null || ! DomainFamily::overlaps($domain, $scope->projectDomain))) {
				$competitors[] = BrandMatcher::build([$domain], [$competitor->name], $competitor->brandTerms);
			}
		}

		return new CandidateFilter(
			BrandMatcher::build($scope->projectDomain === null ? [] : [$scope->projectDomain], [$scope->projectName], $gap->brandTerms),
			$competitors,
			$this->discoverySettings->exclusions($scope->projectId),
		);
	}

	/**
	 * Frazy GSC bez wiersza rynkowego dostają go (bez danych i bez wzbogacania — jak dodanie do monitorowania).
	 *
	 * @param list<Candidate> $candidates
	 * @return list<Candidate>
	 */
	private function resolveMarketKeywords(SourceScope $scope, array $candidates): array
	{
		$missing = [];

		foreach ($candidates as $candidate) {
			if ($candidate->marketKeywordId === null) {
				$missing[$candidate->keyHex] = $candidate->keyword;
			}
		}

		if ($missing === []) {
			return $candidates;
		}

		$this->metrics->ensure($scope->market, array_map('strval', array_values($missing)));
		$ids = $this->lookup->byKeys($scope->market, array_map('strval', array_keys($missing)));
		$result = [];

		foreach ($candidates as $candidate) {
			if ($candidate->marketKeywordId === null) {
				$id = $ids[$candidate->keyHex]['id'] ?? null;

				if ($id === null) {
					continue;
				}

				$candidate = $candidate->withMarketKeywordId($id);
			}

			$result[] = $candidate;
		}

		return $result;
	}

	/**
	 * Powody nieaktywności znanych kandydatów (aktywnych i już nieaktywnych) spoza wybranych: odfiltrowani i ponad limit — także
	 * frazy GSC niezwrócone przez źródło, bo i tak nie mieściły się w limicie (reszta — brak źródła).
	 *
	 * @param list<KeywordFacts> $facts
	 * @return array<int, string> id frazy rynkowej → powód
	 */
	private function inactiveReasons(SourceScope $scope, CandidateSet $set, array $facts): array
	{
		$reasons = [];

		if ($set->omitted > 0) {
			$kept = array_flip(array_map(static fn (KeywordFacts $fact): int => $fact->marketKeywordId, $facts));
			$dropped = array_diff_key($this->keywords->marketKeys($scope->projectId, $scope->market), $kept);
			$qualifying = array_flip($this->gsc->qualifying($scope, array_values($dropped)));

			foreach ($dropped as $id => $hex) {
				if (isset($qualifying[$hex])) {
					$reasons[$id] = StrategyKeywordRepository::REASON_OVERFLOW;
				}
			}
		}

		foreach ($set->filtered as $entry) {
			if ($entry['candidate']->marketKeywordId !== null) {
				$reasons[$entry['candidate']->marketKeywordId] = $entry['reason'];
			}
		}

		foreach ($set->overflow as $candidate) {
			if ($candidate->marketKeywordId !== null) {
				$reasons[$candidate->marketKeywordId] = StrategyKeywordRepository::REASON_OVERFLOW;
			}
		}

		return $reasons;
	}

	/**
	 * @return array<string, string|null>|null
	 */
	private function project(int $projectId): ?array
	{
		return $this->db->fetchRow(
			"SELECT id, name, domain, country, language FROM `{$this->db->table('projects')}` WHERE id = %d",
			[$projectId],
		);
	}
}
