<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\ReportCache;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetrics;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Support\DateRange;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;

/**
 * Szanse SEO dla panelu i CLI: lista, szczegóły, praca nad szansą (status, notatka, data wdrożenia),
 * ręczne przeliczenie i obserwacja po wdrożeniu. Wyłącznie przez ProjectContext (ProjectGuard);
 * zmiany wymagają osf_seo_manage_opportunities.
 */
final class OpportunityService
{
	/** „Przelicz szanse” — minimalny odstęp dla projektu (s). */
	public const MANUAL_COOLDOWN = 60;

	public const NOTE_MAX_LENGTH = 2000;

	private const MANUAL_TRANSIENT = 'osf_seo_opp_manual_';

	public function __construct(
		private readonly OpportunityRepository $repository,
		private readonly OpportunityAnalyzer $analyzer,
		private readonly OpportunityDataSource $data,
		private readonly Logger $logger,
		private readonly ?ReportCache $cache = null,
		private readonly ?KeywordMetricsProvider $markets = null,
		private readonly ?MarketMetricsRepository $marketMetrics = null,
	) {
	}

	/**
	 * Dane rynkowe fraz z dowodów szansy (wolumen, trudność SEO) — wyłącznie do wyświetlenia jako dodatkowy kontekst.
	 * Wykrywanie, priorytet i pewność szans nie zależą od danych rynkowych (brak, nieaktualne albo awaria dostawcy
	 * nie zmieniają szansy). Jedno zapytanie po kluczach rynkowych; brak danych — fraza nie występuje w wyniku.
	 *
	 * @return array<string, MarketMetrics> fraza z dowodów → metryki
	 */
	public function marketMetrics(ProjectContext $context, Opportunity $opportunity): array
	{
		$project = $context->project();
		$market = $this->markets?->resolveMarket($project->country, $project->language);

		if ($market === null || $this->marketMetrics === null) {
			return [];
		}

		$keywords = [];

		foreach ((array) ($opportunity->evidence['keywords'] ?? []) as $item) {
			if (is_array($item) && is_string($item['keyword'] ?? null)) {
				$keywords[$item['keyword']] = MarketKeyword::key($item['keyword']);
			}
		}

		if ($keywords === []) {
			return [];
		}

		try {
			$found = $this->marketMetrics->findByKeys($market, array_values($keywords));
		} catch (\Throwable $exception) {
			$this->logger->warning('Market data for opportunity {opportunity} unavailable: {message}', ['opportunity' => $opportunity->publicId, 'message' => $exception->getMessage()]);

			return [];
		}

		$result = [];

		foreach ($keywords as $keyword => $key) {
			if (isset($found[bin2hex($key)])) {
				$result[$keyword] = $found[bin2hex($key)];
			}
		}

		return $result;
	}

	public function list(ProjectContext $context, OpportunityFilters $filters): OpportunityPage
	{
		if ($filters->groupsByPage()) {
			$result = $this->repository->pageGroups($context->projectId(), $filters);

			return new OpportunityPage($filters, [], $result['total'], $result['groups']);
		}

		$result = $this->repository->list($context->projectId(), $filters);

		return new OpportunityPage($filters, $result['rows'], $result['total']);
	}

	/**
	 * @return array{types: array<string, array{total: int, open: int}>, statuses: array<string, int>, states: array<string, int>}
	 */
	public function summary(ProjectContext $context, int $days): array
	{
		return $this->repository->summary($context->projectId(), $days);
	}

	/**
	 * @return array<int, array<string, string|null>>
	 */
	public function analyses(ProjectContext $context): array
	{
		return $this->repository->analyses($context->projectId());
	}

	/**
	 * @throws OpportunityNotFound
	 */
	public function find(ProjectContext $context, string $publicId, int $days = OpportunityConfig::CANONICAL_DAYS): Opportunity
	{
		return $this->repository->find($context->projectId(), $publicId, $days) ?? throw new OpportunityNotFound();
	}

	/**
	 * @return list<int>
	 */
	public function detectedPeriods(ProjectContext $context, Opportunity $opportunity): array
	{
		return $this->repository->detectedPeriods($context->projectId(), $opportunity->id);
	}

	/**
	 * Zmiana stanu pracy. Przy przejściu na „Zrealizowana” (albo zmianie daty wdrożenia) zapisywany jest baseline:
	 * sumy fraz z dowodów w okresie tej samej długości przed datą wdrożenia — do późniejszej obserwacji.
	 *
	 * @param array<string, mixed> $input status, note, completed_on
	 * @throws \OsfSeo\Auth\AccessDenied
	 * @throws OpportunityNotFound
	 * @throws ValidationException
	 */
	public function update(ProjectContext $context, string $publicId, array $input, string $today): Opportunity
	{
		$context->assertCan(Capabilities::MANAGE_OPPORTUNITIES);
		$opportunity = $this->find($context, $publicId);
		$errors = [];

		$status = is_string($input['status'] ?? null) ? OpportunityStatus::tryFrom($input['status']) : null;

		if ($status === null) {
			$errors['status'] = 'Wybierz status z listy.';
		}

		$note = is_string($input['note'] ?? null) ? trim(str_replace("\r\n", "\n", $input['note'])) : '';

		if (mb_strlen($note) > self::NOTE_MAX_LENGTH) {
			$errors['note'] = sprintf('Notatka może mieć najwyżej %d znaków.', self::NOTE_MAX_LENGTH);
		}

		$completedOn = null;

		if ($status === OpportunityStatus::Completed) {
			$completedOn = trim((string) ($input['completed_on'] ?? ''));
			$completedOn = $completedOn === '' ? $today : $completedOn;

			if (! DateRange::isDate($completedOn)) {
				$errors['completed_on'] = 'Podaj datę wdrożenia w formacie RRRR-MM-DD.';
			} elseif ($completedOn > $today) {
				$errors['completed_on'] = 'Data wdrożenia nie może być w przyszłości.';
			}
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		$fields = ['note' => $note === '' ? null : $note, 'completed_on' => $completedOn];

		if ($status !== $opportunity->status) {
			$fields['status'] = $status->value;
			$fields['status_changed_at'] = gmdate('Y-m-d H:i:s');
			$fields['status_changed_by'] = $context->userId() > 0 ? $context->userId() : null;
		}

		if ($status !== OpportunityStatus::Completed) {
			$fields['baseline'] = null;
		} elseif ($opportunity->baseline === null || $opportunity->completedOn !== $completedOn) {
			$fields['baseline'] = OpportunityRepository::json($this->baseline($context, $opportunity, (string) $completedOn));
		}

		$this->repository->updateWorkflow($context->projectId(), $opportunity->id, $fields);

		$this->logger->info('SEO opportunity {opportunity} of project {project} updated by user {user}: status {status}.', [
			'opportunity' => $opportunity->publicId,
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'status' => $status->value,
		]);

		return $this->find($context, $publicId);
	}

	/**
	 * Ręczne przeliczenie wszystkich okresów („Przelicz szanse”) — z limitem jednego zlecenia na MANUAL_COOLDOWN s.
	 *
	 * @return array{status: string, results: list<AnalysisResult>}
	 * @throws \OsfSeo\Auth\AccessDenied
	 */
	public function requestAnalysis(ProjectContext $context): array
	{
		$context->assertCan(Capabilities::MANAGE_OPPORTUNITIES);
		$key = self::MANUAL_TRANSIENT . $context->projectId();

		if (get_transient($key) !== false) {
			return ['status' => 'rate_limited', 'results' => []];
		}

		set_transient($key, '1', self::MANUAL_COOLDOWN);

		if (function_exists('set_time_limit') && (int) ini_get('max_execution_time') !== 0) {
			set_time_limit(120);
		}

		$results = $this->analyzer->analyzeAll($context, OpportunityAnalyzer::TRIGGER_MANUAL, true, null, 5);
		$locked = array_filter($results, static fn (AnalysisResult $result): bool => $result->status === AnalysisResult::LOCKED);

		return ['status' => count($locked) === count($results) ? 'busy' : 'done', 'results' => $results];
	}

	/**
	 * Obserwacja po wdrożeniu (nie dowód przyczynowości): te same frazy, okres tej samej długości przed i po dacie wdrożenia.
	 *
	 * @return array{ready: bool, window: array{0: string, 1: string}, before_window: array{0: string, 1: string}, available_days: int, required_days: int, before: ?Stats, after: ?Stats}|null
	 */
	public function afterImplementation(ProjectContext $context, Opportunity $opportunity): ?array
	{
		$baseline = $opportunity->baseline;

		if ($opportunity->status !== OpportunityStatus::Completed || $opportunity->completedOn === null || $baseline === null || empty($baseline['keywords'])) {
			return null;
		}

		$days = max(1, (int) ($baseline['period_days'] ?? OpportunityConfig::CANONICAL_DAYS));
		$start = DateRange::shift($opportunity->completedOn, 1);
		$end = DateRange::shift($opportunity->completedOn, $days);
		$latest = $this->data->latestDate($context);
		$available = $latest === null || $latest < $start ? 0 : min($days, DateRange::diffDays($start, $latest) + 1);
		$result = [
			'ready' => $available >= $days,
			'window' => [$start, $end],
			'before_window' => $baseline['window'] ?? [DateRange::shift($opportunity->completedOn, -$days), DateRange::shift($opportunity->completedOn, -1)],
			'available_days' => $available,
			'required_days' => $days,
			'before' => Stats::fromArray($baseline['before'] ?? null),
			'after' => null,
		];

		if (! $result['ready']) {
			return $result;
		}

		$keywords = array_map('strval', $baseline['keywords']);
		$compute = fn (): array => $this->data->keywordTotals($context, $keywords, $start, $end)->toArray();
		$after = $this->cache === null
			? $compute()
			: $this->cache->remember(['opportunity_after', $context->publicId(), $opportunity->publicId, $opportunity->completedOn, $end, $context->project()->lastSyncedAt?->format('Y-m-d H:i:s')], $compute);
		$result['after'] = Stats::fromArray($after);

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function baseline(ProjectContext $context, Opportunity $opportunity, string $completedOn): array
	{
		$evidence = $opportunity->evidence;
		$days = (int) ($evidence['period']['days'] ?? OpportunityConfig::CANONICAL_DAYS);
		$keywords = array_values(array_unique(array_filter(array_map(
			static fn (array $item): string => (string) ($item['keyword'] ?? ''),
			$evidence['keywords'] ?? [],
		), static fn (string $keyword): bool => $keyword !== '')));
		$window = [DateRange::shift($completedOn, -$days), DateRange::shift($completedOn, -1)];

		return [
			'captured_at' => gmdate('Y-m-d H:i:s'),
			'completed_on' => $completedOn,
			'period_days' => $days,
			'window' => $window,
			'keywords' => $keywords,
			'before' => $keywords === [] ? null : $this->data->keywordTotals($context, $keywords, $window[0], $window[1])->toArray(),
			'priority' => $opportunity->priority,
			'confidence' => $opportunity->confidence->value,
			'evidence_period' => $evidence['period'] ?? null,
			'evidence_metrics' => $evidence['metrics'] ?? null,
		];
	}
}
