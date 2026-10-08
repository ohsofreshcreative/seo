<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Workspace;

use OsfSeo\Ai\AiAnalysisService;
use OsfSeo\Ai\AiPlan;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Analysis\ActionCompatibility;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Analysis\ReadinessEvaluator;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Context\AiTopicContextBuilder;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\PageIntelligence\PageJobService;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Sync\SyncScheduler;

/**
 * Przestrzeń robocza AI w panelu (STEP 17, faza D — docs/ARCHITECTURE.md, sekcja 25): wyłącznie odczyt i przygotowanie — zero wywołań
 * modelu, pobrań stron, SERP i DataForSEO. Logika analiz pozostaje w usługach fazy C (`AiAnalysisService`, `ReadinessEvaluator`,
 * `ActionCompatibility`); tu tylko składanie widoku:
 *
 * - sekcja „Analiza AI” tematu — jedno źródło danych tematu dla gotowości trzech typów, typ zalecany według działania Strategii, stan
 *   dowodów i ostatnie analizy z dokładną aktualnością (kontekst składany z tego samego źródła, bez żadnego żądania),
 * - przygotowanie analizy — plan fazy C (koszt maksymalny, budżet, blokady, odcisk planu) i podgląd danych wejściowych,
 * - historia — jedno zapytanie z paginacją SQL i tanim wskaźnikiem zmian Strategii (bez odbudowy kontekstu dla każdego wiersza),
 * - raport — wynik zwalidowany z etykietami dowodów i eksportem tekstowym; aktualność dokładna (jedno źródło na raport).
 *
 * Bez uprawnienia `osf_seo_manage_ai` (klient, menedżer): wyłącznie gotowe, zwalidowane i nieodrzucone analizy tematów widocznych dla
 * użytkownika — bez kosztów, dostawcy, modelu, błędów technicznych, surowej odpowiedzi i diagnostyki (egzekwowane tutaj, nie w widoku).
 */
final class AiWorkspaceService
{
	public const HISTORY_PER_PAGE = 20;

	/** Typ zalecany według działania Strategii (pozostałe zgodne typy do wyboru; „Do sprawdzenia” — tylko świadomy wybór). */
	public const RECOMMENDED = [
		'optimize' => AnalysisType::PAGE_OPTIMIZATION,
		'recover' => AnalysisType::PAGE_OPTIMIZATION,
		'consolidate' => AnalysisType::PAGE_OPTIMIZATION,
		'monitor' => AnalysisType::PAGE_OPTIMIZATION,
		'create' => AnalysisType::NEW_PAGE_BRIEF,
	];

	public function __construct(
		private readonly AiTopicContextBuilder $contexts,
		private readonly AiRunRepository $runs,
		private readonly AiAnalysisService $ai,
		private readonly StrategyService $strategy,
		private readonly PageJobService $pageJobs,
	) {
	}

	/**
	 * Sekcja „Analiza AI” tematu. Bez uprawnienia AI — tylko zapisane, gotowe analizy tematu (bez odczytu źródła i bez gotowości).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws StrategyNotFound
	 */
	public function topicSection(ProjectContext $context, string $topic): array
	{
		if (! $context->can(Capabilities::MANAGE_AI)) {
			return $this->section($context, $this->strategy->topic($context, $topic)['topic'], null);
		}

		return $this->topicSectionFromView($context, $this->strategy->topicView($context, $topic), $this->strategy->scopeSummary($context));
	}

	/**
	 * Sekcja „Analiza AI” z odczytów Strategii, które ekran tematu już wykonał (bez drugiego `topicView` i `panelState`). `$view` i `$state`
	 * wyłącznie z `StrategyService::topicView` / `panelState` (albo `scopeSummary`) dla tego samego `ProjectContext`.
	 *
	 * @param array<string, mixed> $view
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	public function topicSectionFromView(ProjectContext $context, array $view, array $state): array
	{
		$source = $context->can(Capabilities::MANAGE_AI) ? $this->contexts->sourceFromView($context, $view, $state) : null;

		return $this->section($context, $view['topic'], $source);
	}

	/**
	 * Ekran przygotowania analizy: plan fazy C (zero żądań) — gotowość, zgodność, koszt maksymalny, budżet, blokady, odcisk planu — oraz
	 * podgląd danych wejściowych, dostępni dostawcy, analiza w toku, duplikat planu i sekcja typów tematu. Jedno źródło danych tematu
	 * dla sekcji i planu (plan z tego źródła ma ten sam odcisk co `planAnalysis`). Bez typu — typ zalecany według działania Strategii.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused
	 */
	public function prepare(ProjectContext $context, string $topic, ?string $type, bool $explicit = false, string $provider = FakeProvider::ID): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		if ($type !== null) {
			$type = AnalysisType::fromInput($type) ?? throw new AiRefused('analysis_type_unknown');
		}

		$providers = $this->providers();
		$provider = isset($providers[$provider]) ? $provider : FakeProvider::ID;
		$view = $this->strategy->topicView($context, $topic);
		$row = $view['topic'];
		$source = $this->contexts->sourceFromView($context, $view, $this->strategy->scopeSummary($context));
		$siteTopics = $this->contexts->siteTopics($context, $row->id);
		$section = $this->section($context, $row, $source, $siteTopics);
		$type ??= $section['recommended'] ?? AnalysisType::PAGE_OPTIMIZATION;
		$plan = $this->ai->planAnalysisFrom($context, $source, $siteTopics, $type, $provider, null, $explicit);
		$readiness = $plan->readiness ?? throw new AiRefused('readiness_unknown');
		$active = $this->runs->activeFor($context->projectId(), $row->id, $type);
		$duplicate = $this->runs->succeededWithPlan($context->projectId(), $plan->fingerprint());
		// Płatny plan już wysłany bez gotowego wyniku (niepewny, niepoprawny, błąd z kosztem) — ponowienie tylko jawnie (nowy koszt).
		$used = $duplicate === null && $plan->paid ? $this->runs->sentWithPlan($context->projectId(), $plan->fingerprint()) : null;

		return [
			'topic' => ['id' => $row->publicId, 'label' => $row->label, 'action' => $row->action, 'status' => $row->status],
			'type' => $type,
			'type_label' => ReportLabels::type($type),
			'description' => ReportLabels::TYPE_DESCRIPTIONS[$type] ?? '',
			'recommended' => (self::RECOMMENDED[(string) $row->action] ?? null) === $type,
			'mode' => $readiness->mode,
			'explicit' => $explicit && $readiness->mode === ActionCompatibility::EXPLICIT,
			'readiness' => self::readiness($readiness),
			'provider' => $provider,
			'providers' => array_values($providers),
			'plan' => self::plan($plan),
			'preview' => self::preview($plan->context),
			'active' => $active === null ? null : $this->summary(['run' => $active, 'topic_status' => $row->status, 'topic_evidence_hash' => $row->evidenceHash], false),
			'duplicate' => $duplicate === null ? null : $this->summary(['run' => $duplicate, 'topic_status' => $row->status, 'topic_evidence_hash' => $row->evidenceHash], false),
			'used' => $used === null ? null : $this->summary(['run' => $used, 'topic_status' => $row->status, 'topic_evidence_hash' => $row->evidenceHash], false),
			'budget' => $plan->budget ?? $this->ai->budget($context),
			'section' => $section,
		];
	}

	/**
	 * Zakolejkowanie analizy z panelu — kontrole fazy C (`AiAnalysisService::queue`: plan przeliczony teraz, zatwierdzony odcisk planu,
	 * potwierdzenie płatnego wywołania, blokada zlecenia, duplikat, rezerwacja kosztu) oraz osobne potwierdzenie świadomego wyboru typu
	 * niezalecanego przez Strategię (zapisywane w uruchomieniu: `sources.explicit`, użytkownik zlecający). Koszt nie pochodzi z formularza.
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused
	 */
	public function queue(ProjectContext $context, string $topic, string $type, string $approvedPlan, string $provider, bool $explicit, bool $explicitConfirmed, bool $confirmed, bool $repeat): AiRun
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		if (! isset($this->providers()[$provider])) {
			throw new AiRefused('provider_unknown');
		}

		if ($explicit && ! $explicitConfirmed) {
			throw new AiRefused('explicit_confirmation_required');
		}

		if (preg_match('/^[0-9a-f]{64}$/', strtolower(trim($approvedPlan))) !== 1) {
			throw new AiRefused('plan_approval_required');
		}

		return $this->ai->queue($context, $topic, $type, $approvedPlan, $provider, null, $explicit, $confirmed, $repeat, AiRun::TRIGGER_PANEL);
	}

	/**
	 * Historia analiz projektu (panel): filtry i stronicowanie w SQL, tani wskaźnik zmian Strategii.
	 *
	 * @param array{type?: ?string, status?: ?string, topic?: ?string} $filters
	 * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, manage: bool, topic: ?array<string, mixed>}
	 */
	public function history(ProjectContext $context, array $filters, int $page = 1, int $perPage = self::HISTORY_PER_PAGE): array
	{
		$manage = $context->can(Capabilities::MANAGE_AI);
		$page = max(1, $page);
		$perPage = max(5, min(100, $perPage));
		$topic = null;
		$query = [
			'type' => AnalysisType::fromInput($filters['type'] ?? null) ?? (($filters['type'] ?? null) === AnalysisType::TOPIC_ANALYSIS ? AnalysisType::TOPIC_ANALYSIS : null),
			'status' => in_array($filters['status'] ?? null, ['ready', 'active', 'problem'], true) ? $filters['status'] : null,
		];

		if (is_string($filters['topic'] ?? null) && $filters['topic'] !== '') {
			try {
				$row = $this->strategy->topic($context, $filters['topic'])['topic'];
				$topic = ['id' => $row->publicId, 'label' => $row->label];
				$query['topic_id'] = $row->id;
			} catch (StrategyNotFound) {
				return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'manage' => $manage, 'topic' => null];
			}
		}

		$result = $this->runs->history($context->projectId(), $query, ! $manage, $perPage, ($page - 1) * $perPage);

		return [
			'rows' => array_map(fn (array $item): array => $this->summary($item, ! $manage), $result['rows']),
			'total' => $result['total'],
			'page' => $page,
			'per_page' => $perPage,
			'pages' => max(1, (int) ceil($result['total'] / $perPage)),
			'manage' => $manage,
			'topic' => $topic,
		];
	}

	/**
	 * Raport analizy (panel). Bez uprawnienia AI: tylko gotowa, zwalidowana i nieodrzucona analiza tematu widocznego dla użytkownika —
	 * inaczej „nie znaleziono” (bez ujawniania istnienia). Aktualność dokładna: kontekst złożony teraz z zapisanych danych (zero żądań).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AiRunNotFound
	 */
	public function report(ProjectContext $context, string $runId): array
	{
		$manage = $context->can(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();
		$topic = null;

		if ($run->topicPublicId !== null) {
			try {
				$topic = $this->strategy->topic($context, $run->topicPublicId)['topic'];
			} catch (StrategyNotFound) {
				$topic = null;
			}
		}

		if (! $manage && ($run->status !== AiRun::STATUS_SUCCEEDED || $run->decision === AiRun::DECISION_REJECTED || $topic === null)) {
			throw new AiRunNotFound();
		}

		$payload = $this->runs->payload($run);
		$input = json_decode((string) ($payload['input'] ?? ''), true);
		$body = is_array($input['context'] ?? null) ? $input['context'] : [];
		$result = $run->status === AiRun::STATUS_SUCCEEDED && is_array($payload['result'] ?? null) ? $payload['result'] : null;
		$report = $result === null ? null : AiReport::build($run->task, $result, $body, $run->sources);
		$meta = ['topic' => $topic?->label ?? $run->topicLabel, 'date' => substr((string) ($run->finishedAt ?? $run->createdAt), 0, 10)];
		$summary = $this->summary(['run' => $run, 'topic_status' => $topic?->status, 'topic_evidence_hash' => $topic?->evidenceHash], ! $manage);

		return [
			'run' => $summary,
			'topic' => $topic === null ? null : ['id' => $topic->publicId, 'label' => $topic->label, 'action' => $topic->action],
			'report' => $report,
			'freshness' => $report === null || $topic === null ? null : $this->exactFreshness($context, $run, $topic->publicId),
			'test_provider' => $run->provider === FakeProvider::ID,
			'exports' => $report === null ? null : [
				'summary' => AiReportText::summary($report, $meta),
				'recommendations' => AiReportText::recommendations($report, $meta),
				'brief' => AiReportText::brief($report, $meta),
			],
			'admin' => $manage ? $this->adminDetails($run, $payload) : null,
		];
	}

	/**
	 * Status analizy dla odpytywania z panelu (administrator AI) — bez kosztów i treści.
	 *
	 * @return array{id: string, status: string, label: string, active: bool, error: ?string}
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 */
	public function runStatus(ProjectContext $context, string $runId): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		return [
			'id' => $run->publicId,
			'status' => $run->status,
			'label' => ReportLabels::status($run->status, $run->decision),
			'active' => $run->isActive(),
			'error' => ReportLabels::error($run->errorCode),
			'stuck' => $run->status === AiRun::STATUS_QUEUED && self::waiting($run->createdAt),
		];
	}

	/**
	 * Stan AI dla ustawień administratora (bez sekretów): wyłącznik, dostawca, model, obecność klucza (tak/nie), ceny, limity i wydatki,
	 * kolejki analiz i pobrań stron.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array
	{
		$status = $this->ai->status();
		$providers = [];

		foreach ((array) $status['providers'] as $provider) {
			$providers[] = [
				'id' => $provider['id'],
				'label' => ReportLabels::provider((string) $provider['id']),
				'paid' => $provider['paid'],
				'model' => $provider['model'],
				'configured' => $provider['configured'],
				'problems' => array_map(static fn (string $code): string => ReportLabels::error($code) ?? $code, (array) $provider['problems']),
				'api_key' => ! $provider['paid'] ? null : ! in_array('missing_api_key', (array) $provider['problems'], true),
			];
		}

		return [
			'config' => $status['config'],
			'providers' => $providers,
			'prices_configured' => $status['prices_configured'],
			'budget' => $this->ai->budget(),
			'runs' => $status['runs'],
			'page_jobs' => $this->pageJobs->statusCounts(),
			'queue' => $this->ai->queueHealth(),
			'background' => SyncScheduler::backgroundHealth(),
		];
	}

	/** Zlecenie czeka dłużej niż próg, a kroki w tle nie działają (cron) — panel pokazuje ostrzeżenie zamiast „w kolejce” bez końca. */
	public static function waiting(string $createdAt): bool
	{
		$background = SyncScheduler::backgroundHealth();

		return $background['stale'] && time() - (int) strtotime($createdAt . ' UTC') > SyncScheduler::BACKGROUND_STALE_SECONDS;
	}

	/**
	 * Sekcja tematu z jednego źródła: gotowość trzech typów, typ zalecany, stan dowodów, ostatnie analizy z dokładną aktualnością.
	 * `$source === null` — użytkownik bez uprawnienia AI (tylko zapisane, gotowe analizy).
	 *
	 * @param array<string, mixed>|null $source
	 * @param list<array{label: ?string, action: ?string, target_url: ?string}>|null $siteTopics
	 * @return array<string, mixed>
	 */
	private function section(ProjectContext $context, TopicRow $row, ?array $source, ?array $siteTopics = null): array
	{
		$manage = $source !== null && $context->can(Capabilities::MANAGE_AI);
		$canFetch = $context->can(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$history = $this->runs->history($context->projectId(), ['topic_id' => $row->id], ! $manage, 10, 0);
		$runs = array_map(fn (array $item): array => $this->summary($item, ! $manage), $history['rows']);

		if (! $manage) {
			return ['manage' => false, 'can_fetch' => false, 'topic' => $row->publicId, 'runs' => $runs, 'runs_total' => $history['total']];
		}

		$action = is_string($source['context']['decision']['action'] ?? null) ? $source['context']['decision']['action'] : null;
		$recommended = self::RECOMMENDED[(string) $action] ?? null;
		$latest = [];

		foreach ($history['rows'] as $item) {
			$latest[$item['run']->task] ??= $item['run'];
		}

		$types = [];

		foreach (AnalysisType::RECOMMENDATIONS as $type) {
			$readiness = $this->contexts->readinessFrom($source, $type);
			$explicit = $readiness->mode === ActionCompatibility::EXPLICIT ? $this->contexts->readinessFrom($source, $type, true) : null;
			$run = $latest[$type] ?? null;
			$freshness = null;

			if ($run !== null && $run->status === AiRun::STATUS_SUCCEEDED) {
				$siteTopics ??= $this->contexts->siteTopics($context, $row->id);
				$freshness = $this->freshness($run, $this->contexts->analysisFrom($context, $source, $type, ($run->sources['explicit'] ?? false) === true, $siteTopics)['context']);
			}

			$types[$type] = [
				'type' => $type,
				'label' => ReportLabels::type($type),
				'description' => ReportLabels::TYPE_DESCRIPTIONS[$type] ?? '',
				'recommended' => $type === $recommended,
				'mode' => $readiness->mode,
				'readiness' => self::readiness($explicit ?? $readiness),
				'explicit' => $explicit !== null,
				'latest' => $run === null ? null : $this->summary(['run' => $run, 'topic_status' => $row->status, 'topic_evidence_hash' => $row->evidenceHash], false) + ['freshness' => $freshness],
			];
		}

		return [
			'manage' => true,
			'can_fetch' => $canFetch,
			'topic' => $row->publicId,
			'action' => $action,
			'recommended' => $recommended,
			'types' => $types,
			'evidence' => self::evidence($source),
			'serp_options' => self::serpOptions($source),
			'fetch_target' => $row->targetUrl !== null && $row->targetUrl !== '',
			'jobs' => $canFetch ? array_map(static fn ($job): array => $job->toArray(), $this->pageJobs->recent($context, $row->id, 3)) : [],
			'runs' => $runs,
			'runs_total' => $history['total'],
			'enabled' => (bool) ($this->ai->status()['config']['enabled'] ?? false),
		];
	}

	/**
	 * Dostawcy do wyboru w panelu: testowy zawsze, płatny — tylko skonfigurowany na serwerze (wyłącznik, dostawca, klucz, model).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function providers(): array
	{
		$status = $this->ai->status();
		$result = [];

		foreach ((array) $status['providers'] as $provider) {
			if ($provider['paid'] && (! ($status['config']['enabled'] ?? false) || ! $provider['configured'])) {
				continue;
			}

			$result[(string) $provider['id']] = [
				'id' => (string) $provider['id'],
				'label' => ReportLabels::provider((string) $provider['id']),
				'paid' => (bool) $provider['paid'],
				'model' => $provider['model'],
				'problems' => array_map(static fn (string $code): string => ReportLabels::error($code) ?? $code, (array) $provider['problems']),
			];
		}

		return $result;
	}

	/**
	 * Wiersz analizy (lista, sekcja tematu). Bez uprawnienia AI — bez dostawcy, modelu, kosztów i błędów.
	 *
	 * @param array{run: AiRun, topic_status: ?string, topic_evidence_hash: ?string} $item
	 * @return array<string, mixed>
	 */
	private function summary(array $item, bool $restricted): array
	{
		$run = $item['run'];
		// Tani wskaźnik: odcisk dowodów tematu zapisany przez Strategię w chwili analizy vs. bieżący (bez odbudowy kontekstu). Treść stron
		// nie wchodzi do odcisku Strategii — dokładną aktualność pokazuje raport.
		$recorded = is_string($run->sources['strategy_hash'] ?? null) ? $run->sources['strategy_hash'] : null;
		$strategyChanged = $recorded === null || $item['topic_evidence_hash'] === null ? null : ! hash_equals($recorded, (string) $item['topic_evidence_hash']);
		$base = [
			'id' => $run->publicId,
			'topic' => $run->topicPublicId === null ? null : ['id' => $run->topicPublicId, 'label' => $run->topicLabel],
			'type' => $run->task,
			'type_label' => ReportLabels::type($run->task),
			'created_at' => $run->createdAt,
			'finished_at' => $run->finishedAt,
			'status' => $run->status,
			'status_label' => ReportLabels::status($run->status, $run->decision),
			'ready' => $run->status === AiRun::STATUS_SUCCEEDED,
			'active' => $run->isActive(),
			'decision' => $run->decision,
			'strategy_changed' => $strategyChanged,
			'test_provider' => $run->provider === FakeProvider::ID,
		];

		if ($restricted) {
			return $base;
		}

		return $base + [
			'provider' => ReportLabels::provider($run->provider),
			'model' => $run->model,
			'paid' => $run->paid,
			'cost' => $run->paid ? round($run->chargedCost(), 6) : 0.0,
			'cost_estimated' => $run->paid ? round($run->estimatedCost, 6) : 0.0,
			'cost_basis' => ReportLabels::costBasis($run->costBasis),
			'error' => ReportLabels::error($run->errorCode),
			'explicit' => ($run->sources['explicit'] ?? false) === true,
			'uncertain' => $run->status === AiRun::STATUS_UNCERTAIN,
		];
	}

	/**
	 * Szczegóły dla administratora: koszty, tokeny, wynik kontroli jakości (liczba błędów, bez surowej odpowiedzi), gotowość i wybór jawny.
	 *
	 * @param array<string, mixed>|null $payload
	 * @return array<string, mixed>
	 */
	private function adminDetails(AiRun $run, ?array $payload): array
	{
		return [
			'provider' => ReportLabels::provider($run->provider),
			'model' => $run->model,
			'paid' => $run->paid,
			'trigger' => $run->triggerType === AiRun::TRIGGER_PANEL ? 'Panel' : 'WP-CLI',
			'cost' => [
				'estimated' => round($run->estimatedCost, 6),
				'reserved' => round($run->reservedCost, 6),
				'actual' => $run->actualCost === null ? null : round($run->actualCost, 6),
				'charged' => round($run->chargedCost(), 6),
				'basis' => ReportLabels::costBasis($run->costBasis),
			],
			'tokens' => ['input' => $run->inputTokens, 'cached' => $run->cachedTokens, 'output' => $run->outputTokens, 'estimate' => $run->inputTokensEstimate],
			'error' => ReportLabels::error($run->errorCode),
			'validation_errors' => $run->validationErrors > 0 ? $run->validationErrors : count((array) ($payload['validation'] ?? [])),
			'readiness' => ReportLabels::readiness($run->readiness),
			'explicit' => ($run->sources['explicit'] ?? false) === true,
			'compatibility' => ReportLabels::compatibility(is_string($run->sources['compatibility'] ?? null) ? $run->sources['compatibility'] : null),
			'started_at' => $run->startedAt,
			'finished_at' => $run->finishedAt,
		];
	}

	/**
	 * Dokładna aktualność raportu: kontekst tematu złożony teraz z zapisanych danych (bez żadnego żądania) porównany z odciskiem dowodów
	 * uruchomienia. Wynik nieaktualny jest tylko oznaczany.
	 *
	 * @return array{state: string, label: string}
	 */
	private function exactFreshness(ProjectContext $context, AiRun $run, string $topic): array
	{
		try {
			$source = $this->contexts->source($context, $topic);
			$current = in_array($run->task, AnalysisType::RECOMMENDATIONS, true)
				? $this->contexts->analysisFrom($context, $source, $run->task, ($run->sources['explicit'] ?? false) === true, $this->contexts->siteTopics($context, (int) $source['topic_id']))['context']
				: $this->contexts->buildFrom($source);
		} catch (StrategyNotFound) {
			return ['state' => 'stale', 'label' => 'Temat nie istnieje już w Strategii.'];
		}

		return $this->freshness($run, $current);
	}

	/**
	 * @return array{state: string, label: string}
	 */
	private function freshness(AiRun $run, AiContext $current): array
	{
		return match (true) {
			$run->evidenceFingerprint === null => ['state' => 'unknown', 'label' => 'Aktualność nieznana (analiza sprzed zapisu odcisku dowodów).'],
			(int) ($current->body['context_version'] ?? 0) !== $run->contextVersion => ['state' => 'stale', 'label' => 'Zmienił się zakres danych analiz — wynik może być nieaktualny.'],
			! hash_equals($run->evidenceFingerprint, $current->evidenceFingerprint()) => ['state' => 'stale', 'label' => 'Dane tematu zmieniły się od analizy (Strategia, SERP, GSC albo treść stron) — wynik może być nieaktualny.'],
			default => ['state' => 'current', 'label' => 'Aktualna — dane tematu nie zmieniły się od analizy.'],
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function readiness(Readiness $readiness): array
	{
		return [
			'state' => $readiness->state,
			'label' => ReportLabels::readiness($readiness->state),
			'runnable' => $readiness->runnable(),
			'reasons' => array_map(ReportLabels::readinessCode(...), $readiness->reasons),
			'reason_codes' => $readiness->reasons,
			'limitations' => array_map(ReportLabels::readinessCode(...), $readiness->limitations),
			'notes' => array_map(ReportLabels::readinessCode(...), $readiness->notes),
			'compatibility' => ReportLabels::compatibility($readiness->mode),
		];
	}

	/**
	 * Plan bez danych wewnętrznych: koszt maksymalny, tokeny, blokady (po polsku), odcisk planu do zatwierdzenia.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(AiPlan $plan): array
	{
		return [
			'runnable' => $plan->runnable(),
			'paid' => $plan->paid,
			'model' => $plan->model,
			'max_cost' => $plan->maxCost,
			'input_tokens' => $plan->inputTokensEstimate,
			'max_output_tokens' => $plan->maxOutputTokens,
			'blockers' => array_map(static fn (string $code): string => ReportLabels::error($code) ?? $code, $plan->blockers),
			'blocker_codes' => $plan->blockers,
			'fingerprint' => $plan->fingerprint(),
		];
	}

	/**
	 * Podgląd danych wejściowych analizy (z kontekstu planu): strona projektu, strony konkurencji, SERP, GSC, frazy, braki danych.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(AiContext $context): array
	{
		$body = $context->body;
		$page = (array) ($body['target_page']['page_content'] ?? []);
		$evidence = (array) ($body['evidence'] ?? []);
		$serp = is_array($evidence['serp'] ?? null) ? $evidence['serp'] : null;
		$gsc = (array) ($evidence['gsc'] ?? []);

		return [
			'target' => ['url' => $body['target_page']['url'] ?? null, 'state' => $body['target_page']['state'] ?? null, 'label' => $body['target_page']['state_label'] ?? null],
			'page' => ($page['available'] ?? false) !== true ? ['available' => false, 'failed' => ($page['reason'] ?? null) === 'fetch_failed'] : [
				'available' => true,
				'url' => $page['url'] ?? null,
				'fetched_at' => $page['provenance']['as_of'] ?? null,
				'fresh' => ($page['provenance']['freshness'] ?? null) === 'fresh',
				'quality' => $page['content_quality'] ?? null,
				'word_count' => $page['word_count'] ?? null,
				'headings' => $page['headings_total'] ?? null,
			],
			'competitors' => array_map(static fn (array $item): array => [
				'domain' => $item['domain'] ?? null,
				'url' => $item['url'] ?? null,
				'rank' => $item['serp']['serp_rank_group'] ?? null,
				'measured_at' => $item['serp']['measured_at'] ?? null,
				'fetched_at' => $item['fetch']['fetched_at'] ?? null,
				'available' => ($item['fetch']['available'] ?? false) === true,
				'usable' => ($item['usable'] ?? ($item['fetch']['available'] ?? false)) === true,
				'quality' => $item['fetch']['content_quality'] ?? null,
			], array_values(array_filter((array) ($evidence['competitor_pages']['items'] ?? []), 'is_array'))),
			'competitors_linked' => (int) ($evidence['competitor_pages']['linked_total'] ?? 0),
			'serp' => $serp === null ? null : ['measured_at' => $serp['provenance']['as_of'] ?? null, 'freshness' => $serp['provenance']['freshness'] ?? null, 'keyword' => $serp['reference_keyword']['keyword'] ?? null],
			'gsc' => ['connected' => ($gsc['connected'] ?? false) === true, 'as_of' => $gsc['provenance']['as_of'] ?? null, 'totals' => $gsc['topic_totals'] ?? null],
			'keywords' => ['in_context' => $body['topic']['keywords_in_context'] ?? 0, 'total' => $body['topic']['keywords_total'] ?? 0],
			'site_pages' => count((array) ($body['site']['pages'] ?? [])),
			'data_gaps' => array_map(static fn (array $gap): string => ReportLabels::dataGap((string) ($gap['code'] ?? '')), array_values(array_filter((array) ($body['data_gaps'] ?? []), 'is_array'))),
			'reduced' => ((array) ($body['limits']['reductions'] ?? [])) !== [],
		];
	}

	/**
	 * Stan dowodów tematu (sekcja „Analiza AI”) z jednego źródła.
	 *
	 * @param array<string, mixed> $source
	 * @return array<string, mixed>
	 */
	private static function evidence(array $source): array
	{
		$ctx = (array) ($source['context'] ?? []);
		$pages = (array) ($source['pages'] ?? []);
		$project = is_array($pages['project'] ?? null) ? $pages['project'] : null;
		$snapshot = is_array($project['snapshot'] ?? null) ? $project['snapshot'] : null;
		$competitors = array_values(array_filter((array) ($pages['competitors'] ?? []), 'is_array'));
		$detail = is_array($source['serp']['detail'] ?? null) ? $source['serp']['detail'] : null;
		$keywords = array_values(array_filter((array) ($ctx['keywords'] ?? []), 'is_array'));

		return [
			'target' => [
				'state' => $ctx['target']['state'] ?? null,
				'label' => $ctx['target']['state_label'] ?? null,
				'url' => is_string($ctx['target']['url'] ?? null) ? $ctx['target']['url'] : null,
			],
			'project_page' => $project === null ? null : [
				'url' => $project['url'] ?? null,
				'page' => $project['target']['id'] ?? null,
				'cache' => $project['cache'] ?? 'missing',
				'fetched_at' => $snapshot['fetched_at'] ?? null,
				'quality' => $snapshot['content_quality'] ?? null,
				'word_count' => $snapshot['word_count'] ?? null,
				'http_status' => $snapshot['http_status'] ?? null,
				'last_error' => $project['target']['last_error'] ?? null,
			],
			'competitors' => [
				'usable' => count(array_filter($competitors, static fn (array $page): bool => ReadinessEvaluator::usable(is_array($page['snapshot'] ?? null) ? $page['snapshot'] : null))),
				'linked' => (int) ($pages['competitors_total'] ?? 0),
				'items' => array_map(static fn (array $page): array => [
					'url' => $page['url'] ?? null,
					'domain' => $page['target']['host'] ?? null,
					'page' => $page['target']['id'] ?? null,
					'rank' => $page['serp']['rank_group'] ?? null,
					'cache' => $page['cache'] ?? null,
					'quality' => $page['snapshot']['content_quality'] ?? null,
					'usable' => ReadinessEvaluator::usable(is_array($page['snapshot'] ?? null) ? $page['snapshot'] : null),
				], $competitors),
			],
			'serp' => $detail === null ? null : [
				'checked_at' => $detail['checked_at'] ?? null,
				'freshness' => $detail['freshness'] ?? null,
				'keyword' => $source['serp']['keyword']['keyword'] ?? null,
			],
			'gsc' => is_array($ctx['gsc'] ?? null),
			'gsc_newest' => $source['freshness']['gsc']['newest_date'] ?? null,
			'keywords' => count($keywords),
			'with_volume' => count(array_filter($keywords, static fn (array $keyword): bool => ($keyword['market']['volume'] ?? null) !== null)),
			'page_index_complete' => ($source['page_index_complete'] ?? false) === true,
		];
	}

	/**
	 * Wyniki organiczne zapisanego pomiaru SERP tematu do wyboru stron konkurencji (bez strony projektu), najwyżej TOP 10.
	 *
	 * @param array<string, mixed> $source
	 * @return array{keyword: ?array<string, mixed>, results: list<array<string, mixed>>}
	 */
	private static function serpOptions(array $source): array
	{
		$detail = is_array($source['serp']['detail'] ?? null) ? $source['serp']['detail'] : null;
		$keyword = is_array($source['serp']['keyword'] ?? null) ? $source['serp']['keyword'] : null;
		$results = [];

		if ($detail !== null && in_array($detail['freshness'] ?? null, ['fresh', 'stale'], true)) {
			foreach ((array) ($detail['results'] ?? []) as $result) {
				if (! is_array($result) || ($result['project'] ?? false) || ! is_string($result['url'] ?? null) || isset($results[(int) ($result['rank'] ?? 0)])) {
					continue;
				}

				$results[(int) $result['rank']] = ['rank' => (int) $result['rank'], 'url' => $result['url'], 'host' => $result['host'] ?? null];

				if (count($results) >= 10) {
					break;
				}
			}
		}

		ksort($results);

		return ['keyword' => $keyword, 'results' => array_values($results)];
	}
}
