<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use Closure;
use OsfSeo\Ai\Analysis\ActionCompatibility;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Context\AiTopicContextBuilder;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Contract\OutputValidator;
use OsfSeo\Ai\Contract\RecommendationContract;
use OsfSeo\Ai\Contract\RecommendationValidator;
use OsfSeo\Ai\Contract\ValidationResult;
use OsfSeo\Ai\Prompt\AnalysisPrompts;
use OsfSeo\Ai\Prompt\PromptTemplate;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Provider\AiProviderRegistry;
use OsfSeo\Ai\Provider\AiRequest;
use OsfSeo\Ai\Provider\AiResponse;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Ai\Run\AiRunRepository;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Analizy AI tematów Strategii (STEP 17, faza A — docs/ARCHITECTURE.md, sekcja 22). Jedyne miejsce, z którego wychodzi wywołanie modelu:
 * kontekst (odczyt Strategii w obrębie projektu) → plan bez żadnego żądania → rezerwacja kosztu pod blokadą budżetu → jedno wywołanie
 * dostawcy (bez ponowień) → walidacja odpowiedzi po stronie PHP → rozliczenie i zapis historii.
 *
 * Wywoływana wyłącznie jawnie (CLI; w przyszłości akcja w panelu) z uprawnieniem `osf_seo_manage_ai` — nigdy przy renderowaniu panelu,
 * przeliczeniu Strategii, synchronizacji GSC ani w kroku w tle (krok w tle tylko porządkuje historię: `maintenance`, bez wywołań).
 * Płatny dostawca wymaga: włączonego wyłącznika, zgodnego dostawcy w konfiguracji, modelu, klucza, cen, limitów > 0 i potwierdzenia.
 * Historia nigdy nie zmienia danych Strategii ani statusu pracy tematu.
 *
 * Analizy rekomendacji (faza C, sekcja 24): typ zgodny z działaniem Strategii → gotowość z zapisanych danych → kontekst v3 → plan
 * z odciskiem → (płatne) zatwierdzenie dokładnie tego planu → blokada zlecenia i kontrola duplikatów → ta sama ścieżka wykonania co analiza
 * tematu (rezerwacja pod blokadą budżetu, jedno wywołanie, walidacja `RecommendationValidator`, rozliczenie, historia).
 */
final class AiAnalysisService
{
	/** Uruchomienie w toku dłużej niż timeout HTTP + tyle sekund = porzucone przez przerwany proces. */
	public const STALE_GRACE = 300;

	public const MAINTENANCE_TRANSIENT = 'osf_seo_ai_maintenance';

	public function __construct(
		private readonly AiTopicContextBuilder $contexts,
		private readonly AiProviderRegistry $providers,
		private readonly AiConfig $config,
		private readonly AiPricing $pricing,
		private readonly AiBudget $budget,
		private readonly AiRunRepository $runs,
		private readonly OutputValidator $validator,
		private readonly Clock $clock,
		private readonly Logger $logger,
		private readonly RecommendationValidator $recommendations = new RecommendationValidator(),
	) {
	}

	/**
	 * Podgląd kontekstu AI tematu (bez żadnego żądania i bez zapisu).
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 */
	public function context(ProjectContext $context, string $topic): AiContext
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		return $this->contexts->build($context, $topic);
	}

	/**
	 * Plan analizy: kontekst, tokeny, koszt maksymalny i powody blokady — zero żądań.
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 */
	public function plan(ProjectContext $context, string $topic, string $provider = FakeProvider::ID, ?string $focus = null): AiPlan
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$aiContext = $this->contexts->build($context, $topic);

		return $this->planFor($context, $aiContext, $provider, $this->topicSpec($aiContext, PromptTemplate::focus($focus)));
	}

	/**
	 * Uruchomienie analizy (dostawca płatny — tylko z `$confirmed` i bez powodów blokady). Zwraca zapisane uruchomienie (także nieudane).
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused odmowa przed jakimkolwiek żądaniem
	 */
	public function run(ProjectContext $context, string $topic, string $provider = FakeProvider::ID, ?string $focus = null, bool $confirmed = false, string $trigger = AiRun::TRIGGER_CLI): AiRun
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$aiContext = $this->contexts->build($context, $topic);
		$spec = $this->topicSpec($aiContext, PromptTemplate::focus($focus));
		$plan = $this->planFor($context, $aiContext, $provider, $spec);

		if (! $plan->runnable()) {
			throw new AiRefused($plan->blockers[0], $plan->blockers);
		}

		if ($plan->paid && ! $confirmed) {
			throw new AiRefused('confirmation_required');
		}

		return $this->execute($context, $plan, $aiContext, $spec, $trigger);
	}

	/**
	 * Typy analiz rekomendacji z tabelą zgodności z działaniami Strategii (bez żadnego żądania).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function analysisTypes(): array
	{
		return array_map(static fn (string $type): array => [
			'type' => $type,
			'label' => AnalysisType::label($type),
			'prompt_version' => AnalysisPrompts::version($type),
			'contract_version' => RecommendationContract::VERSION,
			'actions' => array_map(static fn (array $rule): array => ['mode' => $rule['mode'], 'constraints' => $rule['constraints'], 'requires' => $rule['requires']], array_combine(array_keys(ActionCompatibility::TABLE), array_map(static fn (string $action): array => ActionCompatibility::rule($action, $type), array_keys(ActionCompatibility::TABLE)))),
		], AnalysisType::RECOMMENDATIONS);
	}

	/**
	 * Gotowość analizy rekomendacji z zapisanych danych — zero żądań (bez pobierania stron, SERP i DataForSEO).
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused nieznany typ analizy
	 */
	public function readiness(ProjectContext $context, string $topic, string $type, bool $explicit = false): Readiness
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		return $this->contexts->readiness($context, $topic, self::type($type), $explicit);
	}

	/**
	 * Podgląd kontekstu analizy rekomendacji (wersja 3) — bez żadnego żądania i bez zapisu.
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused
	 */
	public function analysisContext(ProjectContext $context, string $topic, string $type, bool $explicit = false): AiContext
	{
		$context->assertCan(Capabilities::MANAGE_AI);

		return $this->contexts->analysis($context, $topic, self::type($type), $explicit)['context'];
	}

	/**
	 * Plan analizy rekomendacji: gotowość, kontekst, tokeny, koszt maksymalny, blokady i odcisk planu — zero żądań.
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused
	 */
	public function planAnalysis(ProjectContext $context, string $topic, string $type, string $provider = FakeProvider::ID, ?string $focus = null, bool $explicit = false): AiPlan
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$type = self::type($type);
		['context' => $aiContext, 'readiness' => $readiness] = $this->contexts->analysis($context, $topic, $type, $explicit);

		return $this->planFor($context, $aiContext, $provider, $this->analysisSpec($type, $aiContext, PromptTemplate::focus($focus)), $readiness);
	}

	/**
	 * Wygenerowanie analizy rekomendacji (D108): ponowna weryfikacja planu w chwili wykonania, gotowość (niewystarczająca albo zablokowana
	 * → odmowa bez żadnego żądania), dostawca płatny tylko z potwierdzeniem i zatwierdzonym odciskiem planu (inny odcisk — kontekst, model,
	 * ceny albo koszt się zmieniły → `plan_changed`), blokada zlecenia (równoległe wykonanie → `run_in_progress`), ten sam odcisk już
	 * wygenerowany → `already_generated` (chyba że `$repeat`). Zwraca zapisane uruchomienie (także nieudane albo niepewne).
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused odmowa przed jakimkolwiek żądaniem
	 */
	public function generate(
		ProjectContext $context,
		string $topic,
		string $type,
		string $provider = FakeProvider::ID,
		?string $focus = null,
		bool $explicit = false,
		?string $approvedPlan = null,
		bool $confirmed = false,
		bool $repeat = false,
		string $trigger = AiRun::TRIGGER_CLI,
	): AiRun {
		$plan = $this->planAnalysis($context, $topic, $type, $provider, $focus, $explicit);
		$readiness = $plan->readiness ?? throw new AiRefused('readiness_unknown');

		if ($readiness->state === Readiness::BLOCKED || $readiness->state === Readiness::INSUFFICIENT) {
			throw new AiRefused('readiness_' . $readiness->state, $readiness->reasons);
		}

		if (! $plan->runnable()) {
			throw new AiRefused($plan->blockers[0], $plan->blockers);
		}

		if ($approvedPlan !== null && ! hash_equals($plan->fingerprint(), strtolower(trim($approvedPlan)))) {
			throw new AiRefused('plan_changed');
		}

		if ($plan->paid && (! $confirmed || $approvedPlan === null)) {
			throw new AiRefused($confirmed ? 'plan_approval_required' : 'confirmation_required');
		}

		$aiContext = $plan->context;
		$spec = $this->analysisSpec($plan->task, $aiContext, $plan->focus);
		$spec['data'] = [
			'plan_fingerprint' => $plan->fingerprint(),
			'readiness' => $readiness->state,
			'sources' => (string) json_encode(self::sources($aiContext, $readiness, $explicit), AiContext::JSON_FLAGS),
		];

		if (! $this->runs->acquireGenerationLock($context->projectId(), $aiContext->topicId, $plan->task)) {
			throw new AiRefused('run_in_progress');
		}

		try {
			if ($this->runs->activeFor($context->projectId(), $aiContext->topicId, $plan->task) !== null) {
				throw new AiRefused('run_in_progress');
			}

			if (! $repeat && $this->runs->succeededWithPlan($context->projectId(), $plan->fingerprint()) !== null) {
				throw new AiRefused('already_generated');
			}

			return $this->execute($context, $plan, $aiContext, $spec, $trigger);
		} finally {
			$this->runs->releaseGenerationLock($context->projectId(), $aiContext->topicId, $plan->task);
		}
	}

	/**
	 * Wspólna ścieżka wykonania (faza A i C): rezerwacja kosztu pod blokadą budżetu → `running` → jedno wywołanie dostawcy (bez ponowień)
	 * → walidacja → rozliczenie i historia.
	 *
	 * @param array<string, mixed> $spec
	 */
	private function execute(ProjectContext $context, AiPlan $plan, AiContext $aiContext, array $spec, string $trigger): AiRun
	{
		$adapter = $this->providers->get($plan->provider) ?? throw new AiRefused('provider_unknown');
		$data = [
			'project_id' => $context->projectId(),
			'topic_id' => $aiContext->topicId,
			'task' => $plan->task,
			'provider' => $adapter->id(),
			'model' => (string) $plan->model,
			'paid' => $plan->paid ? 1 : 0,
			'prompt_version' => $plan->promptVersion,
			'context_version' => (int) ($aiContext->body['context_version'] ?? 0),
			'contract_version' => $plan->contractVersion,
			'context_fingerprint' => $aiContext->fingerprint(),
			'evidence_fingerprint' => $aiContext->evidenceFingerprint(),
			'evidence_hash' => $aiContext->evidenceHash(),
			'trigger_type' => $trigger === AiRun::TRIGGER_PANEL ? AiRun::TRIGGER_PANEL : AiRun::TRIGGER_CLI,
			'requested_by' => $context->userId() > 0 ? $context->userId() : null,
			'input_tokens_estimate' => $plan->inputTokensEstimate,
			'estimated_cost' => round((float) $plan->maxCost, 6),
			'reserved_cost' => $plan->paid ? round((float) $plan->maxCost, 6) : 0.0,
		] + (array) ($spec['data'] ?? []);
		$inputDocument = (string) json_encode(['focus' => $plan->focus, 'context' => $aiContext->toArray()], AiContext::JSON_FLAGS);
		$create = fn (): AiRun => $this->runs->create($data, $inputDocument, (string) $spec['input_hash']);
		$run = $plan->paid ? $this->budget->reserve($context->projectId(), (float) $plan->maxCost, $create) : $create();

		if (! $this->runs->markRunning($run)) {
			throw new AiRefused('run_conflict');
		}

		$request = new AiRequest(
			(string) $plan->model,
			(string) $spec['instructions'],
			(string) $spec['input'],
			(string) $spec['contract_name'],
			(array) $spec['schema'],
			$plan->maxOutputTokens,
			$plan->paid ? $this->config->temperature() : null,
			(array) $spec['hints'],
		);

		try {
			$response = $adapter->generate($request);
		} catch (AiProviderException $exception) {
			return $this->failed($run, $plan, $exception);
		} catch (Throwable $exception) {
			// Nieoczekiwany błąd po oznaczeniu `running` — nie wiadomo, czy żądanie wyszło: wynik niepewny (liczy się rezerwacja).
			$this->logger->error('AI run {run} interrupted by {class}.', ['run' => $run->publicId, 'class' => $exception::class]);

			return $this->settle($run, [
				'status' => AiRun::STATUS_UNCERTAIN,
				'actual_cost' => $plan->paid ? null : 0.0,
				'cost_basis' => $plan->paid ? AiRun::COST_RESERVATION : AiRun::COST_FREE,
				'error_code' => 'internal_error',
			]);
		}

		return $this->completed($run, $plan, $response, $spec['validate']);
	}

	/**
	 * Historia projektu (opcjonalnie tematu) — same metadane.
	 *
	 * @return list<AiRun>
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 */
	public function runs(ProjectContext $context, ?string $topic = null, int $limit = 20): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$topicId = $topic === null ? null : $this->contexts->topicId($context, $topic);

		return $this->runs->list($context->projectId(), $topicId, $limit);
	}

	/**
	 * Uruchomienie z danymi (wejście, odpowiedź, wynik, walidacja) — wyłącznie w obrębie projektu.
	 *
	 * @return array{run: AiRun, payload: ?array<string, mixed>, freshness: array<string, mixed>}
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 */
	public function show(ProjectContext $context, string $runId, bool $withPayload = true): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		return ['run' => $run, 'payload' => $withPayload ? $this->runs->payload($run) : null, 'freshness' => $this->freshnessOf($context, $run)];
	}

	/**
	 * Aktualność wyników listy uruchomień względem bieżących dowodów (odbudowa kontekstu bez żadnego żądania; raz na temat i typ).
	 *
	 * @param list<AiRun> $runs uruchomienia projektu (z `runs()`)
	 * @return array<string, array<string, mixed>> identyfikator uruchomienia → aktualność
	 *
	 * @throws AccessDenied
	 */
	public function freshness(ProjectContext $context, array $runs): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$current = [];
		$result = [];

		foreach ($runs as $run) {
			if ($run->projectId === $context->projectId()) {
				$result[$run->publicId] = $this->freshnessOf($context, $run, $current);
			}
		}

		return $result;
	}

	/**
	 * Czy dowody tematu zmieniły się od uruchomienia: odcisk dowodów (bez stanu pracy tematu) i wersja kontekstu. Wynik nieaktualny
	 * nie jest usuwany ani zmieniany — tylko oznaczany.
	 *
	 * @param array<string, array{fingerprint: ?string, version: ?int}> $current pamięć bieżących odcisków (temat × typ × wybór jawny)
	 * @return array{stale: ?bool, reason: ?string, current_evidence_fingerprint: ?string}
	 */
	private function freshnessOf(ProjectContext $context, AiRun $run, array &$current = []): array
	{
		if ($run->topicPublicId === null) {
			return ['stale' => true, 'reason' => 'topic_not_found', 'current_evidence_fingerprint' => null];
		}

		if ($run->evidenceFingerprint === null) {
			return ['stale' => null, 'reason' => 'not_recorded', 'current_evidence_fingerprint' => null];
		}

		$explicit = ($run->sources['explicit'] ?? false) === true;
		$key = $run->topicPublicId . '|' . $run->task . '|' . ($explicit ? '1' : '0');

		if (! array_key_exists($key, $current)) {
			try {
				$aiContext = in_array($run->task, AnalysisType::RECOMMENDATIONS, true)
					? $this->contexts->analysis($context, $run->topicPublicId, $run->task, $explicit)['context']
					: $this->contexts->build($context, $run->topicPublicId);
				$current[$key] = ['fingerprint' => $aiContext->evidenceFingerprint(), 'version' => (int) ($aiContext->body['context_version'] ?? 0)];
			} catch (StrategyNotFound) {
				$current[$key] = ['fingerprint' => null, 'version' => null];
			}
		}

		$now = $current[$key];

		return match (true) {
			$now['fingerprint'] === null => ['stale' => true, 'reason' => 'topic_not_found', 'current_evidence_fingerprint' => null],
			$now['version'] !== $run->contextVersion => ['stale' => true, 'reason' => 'context_version_changed', 'current_evidence_fingerprint' => $now['fingerprint']],
			$now['fingerprint'] !== $run->evidenceFingerprint => ['stale' => true, 'reason' => 'evidence_changed', 'current_evidence_fingerprint' => $now['fingerprint']],
			default => ['stale' => false, 'reason' => null, 'current_evidence_fingerprint' => $now['fingerprint']],
		};
	}

	/**
	 * Decyzja użytkownika o wyniku (zaakceptowany / odrzucony / cofnięcie) — osobno od wyniku; nie zmienia Strategii ani statusu pracy tematu.
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 * @throws AiRefused
	 */
	public function decide(ProjectContext $context, string $runId, ?string $decision): AiRun
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		if ($decision !== null && ! in_array($decision, AiRun::DECISIONS, true)) {
			throw new AiRefused('decision_invalid');
		}

		if ($run->status !== AiRun::STATUS_SUCCEEDED) {
			throw new AiRefused('decision_not_allowed');
		}

		return $this->runs->decide($run, $decision, $context->userId() > 0 ? $context->userId() : null);
	}

	/**
	 * Usunięcie uruchomienia z danymi (nie w toku — koszt w toku musi zostać rozliczony).
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 * @throws AiRefused
	 */
	public function delete(ProjectContext $context, string $runId): void
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		if (! $this->runs->delete($run)) {
			throw new AiRefused('run_active');
		}

		$this->logger->info('AI run {run} deleted.', ['run' => $run->publicId]);
	}

	/**
	 * Budżet AI (bez DataForSEO): limity, wydatki i stan w obrębie projektu albo globalnie (operator systemu).
	 *
	 * @return array<string, mixed>
	 */
	public function budget(?ProjectContext $context = null): array
	{
		$context?->assertCan(Capabilities::MANAGE_AI);

		return $this->budget->snapshot($context?->projectId()) + ['prices_configured' => $this->pricing->known(), 'enabled' => $this->config->enabled()];
	}

	/**
	 * Stan konfiguracji AI (bez sekretów): wyłącznik, dostawcy z brakami, wersje instrukcji, kontekstu i kontraktu, liczniki historii.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array
	{
		$configured = $this->config->provider();
		$providers = [];

		foreach ($this->providers->all() as $provider) {
			$providers[] = [
				'id' => $provider->id(),
				'paid' => $provider->isPaid(),
				'model' => $provider->model(),
				'configured' => ! $provider->isPaid() || $configured === $provider->id(),
				'problems' => $provider->problems(),
			];
		}

		return [
			'config' => $this->config->effective(),
			'provider_known' => $configured === null || $this->providers->get($configured) !== null,
			'providers' => $providers,
			'prices_configured' => $this->pricing->known(),
			'versions' => [
				'prompt' => PromptTemplate::VERSION,
				'context' => Context\TopicContextAssembler::VERSION,
				'contract' => AnalysisContract::VERSION,
				'analysis_prompts' => AnalysisPrompts::VERSIONS,
				'analysis_context' => Context\TopicContextAssembler::ANALYSIS_VERSION,
				'analysis_contract' => RecommendationContract::VERSION,
			],
			'runs' => $this->runs->statusCounts(),
		];
	}

	/**
	 * Porządki (krok w tle albo CLI) — bez żadnego wywołania AI: odzyskanie porzuconych uruchomień (`reserved` → failed bez kosztu,
	 * `running` → uncertain z rezerwacją) i retencja historii (raz na dobę, chyba że `$force`).
	 *
	 * @return array{recovered: int, purged: ?int}
	 */
	public function maintenance(bool $force = false): array
	{
		if (! $force && ! ProjectGuard::isSystemProcess()) {
			return ['recovered' => 0, 'purged' => null];
		}

		$recovered = $this->recoverStale();
		$purged = null;

		if ($force || get_transient(self::MAINTENANCE_TRANSIENT) === false) {
			set_transient(self::MAINTENANCE_TRANSIENT, '1', DAY_IN_SECONDS);
			$purged = $this->runs->purgeBefore($this->clock->now()->modify('-' . $this->config->retentionDays() . ' days')->format('Y-m-d H:i:s'));

			if ($purged > 0) {
				$this->logger->info('Purged {count} AI runs older than {days} days.', ['count' => $purged, 'days' => $this->config->retentionDays()]);
			}
		}

		return ['recovered' => $recovered, 'purged' => $purged];
	}

	/** Uruchomienia porzucone przez przerwany proces (status w toku dłużej niż timeout + STALE_GRACE). */
	public function recoverStale(): int
	{
		$before = $this->clock->now()->modify('-' . ($this->config->timeout() + self::STALE_GRACE) . ' seconds')->format('Y-m-d H:i:s');
		$recovered = 0;

		foreach ($this->runs->staleActive($before) as $run) {
			$sent = $run->status === AiRun::STATUS_RUNNING;
			$done = $this->runs->finish($run, [
				'status' => $sent ? AiRun::STATUS_UNCERTAIN : AiRun::STATUS_FAILED,
				'actual_cost' => $run->paid && $sent ? null : 0.0,
				'cost_basis' => ! $run->paid ? AiRun::COST_FREE : ($sent ? AiRun::COST_RESERVATION : AiRun::COST_NOT_CHARGED),
				'error_code' => $sent ? 'interrupted' : 'not_sent',
			], $run->status);

			if ($done !== null) {
				$recovered++;
				$this->logger->warning('AI run {run} recovered as {status} (abandoned by an interrupted process).', ['run' => $run->publicId, 'status' => $done->status]);
			}
		}

		return $recovered;
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private function planFor(ProjectContext $context, AiContext $aiContext, string $providerId, array $spec, ?Readiness $readiness = null): AiPlan
	{
		$provider = $this->providers->get($providerId);
		$focus = $spec['focus'];
		$maxOutput = $this->config->maxOutputTokens();
		$tokens = AiPricing::estimateInputTokens((string) $spec['instructions'], (string) $spec['input'], (string) json_encode($spec['schema']));
		$versions = [$spec['task'], $spec['prompt_version'], $spec['contract_version'], $spec['language'], $readiness, $this->pricing->prices()];
		// Gotowość niewystarczająca albo zablokowana — żadnego wywołania (także dostawcy testowego).
		$blockers = $readiness !== null && ! $readiness->runnable() ? ['readiness_' . $readiness->state] : [];

		if ($provider === null) {
			return new AiPlan(strtolower(trim($providerId)), null, true, $aiContext, $focus, $tokens, $maxOutput, null, ['provider_unknown'], null, ...$versions);
		}

		$paid = $provider->isPaid();
		$model = $provider->model();

		if ($paid) {
			if (! $this->config->enabled()) {
				$blockers[] = 'ai_disabled';
			}

			if ($this->config->provider() !== $provider->id()) {
				$blockers[] = 'provider_not_configured';
			}

			array_push($blockers, ...$provider->problems());

			if (! $this->pricing->known()) {
				$blockers[] = 'missing_prices';
			}
		} elseif ($model === null) {
			$blockers[] = 'model_not_configured';
		}

		if (! $aiContext->withinBudget()) {
			$blockers[] = 'context_too_large';
		}

		$maxCost = $paid ? $this->pricing->maxCost($tokens, $maxOutput) : 0.0;
		$budget = $paid ? $this->budget->snapshot($context->projectId()) : null;

		if ($paid && $maxCost !== null) {
			array_push($blockers, ...$this->budget->blockers($context->projectId(), $maxCost));
		}

		return new AiPlan($provider->id(), $model, $paid, $aiContext, $focus, $tokens, $maxOutput, $maxCost, array_values(array_unique($blockers)), $budget, ...$versions);
	}

	/**
	 * Analiza tematu (faza A): instrukcje `topic-analysis.v2`, kontrakt v1, `OutputValidator`.
	 *
	 * @return array<string, mixed>
	 */
	private function topicSpec(AiContext $aiContext, ?string $focus): array
	{
		$input = PromptTemplate::input($aiContext, $focus);

		return [
			'task' => PromptTemplate::TASK,
			'prompt_version' => PromptTemplate::VERSION,
			'contract_version' => AnalysisContract::VERSION,
			'contract_name' => AnalysisContract::NAME,
			'schema' => AnalysisContract::schema(),
			'language' => null,
			'focus' => $focus,
			'instructions' => PromptTemplate::instructions(),
			'input' => $input,
			'input_hash' => PromptTemplate::inputHash($input),
			'hints' => ['refs' => $aiContext->refs(), 'data_gaps' => $aiContext->dataGaps(), 'action' => $aiContext->action()],
			'validate' => fn (string $text): ValidationResult => $this->validator->validate($text, $aiContext->refs()),
		];
	}

	/**
	 * Analiza rekomendacji (faza C): instrukcje typu w języku projektu, kontrakt v2, `RecommendationValidator` z kontekstem uruchomienia.
	 *
	 * @return array<string, mixed>
	 */
	private function analysisSpec(string $type, AiContext $aiContext, ?string $focus): array
	{
		$language = AnalysisPrompts::language(is_string($aiContext->body['analysis']['language'] ?? null) ? $aiContext->body['analysis']['language'] : null);
		$input = AnalysisPrompts::input($type, $aiContext, $focus);

		return [
			'task' => $type,
			'prompt_version' => AnalysisPrompts::version($type),
			'contract_version' => RecommendationContract::VERSION,
			'contract_name' => RecommendationContract::NAME,
			'schema' => RecommendationContract::schema(),
			'language' => $language,
			'focus' => $focus,
			'instructions' => AnalysisPrompts::instructions($type, $language),
			'input' => $input,
			'input_hash' => AnalysisPrompts::inputHash($type, $language, $input),
			'hints' => [
				'analysis_type' => $type,
				'refs' => $aiContext->refs(),
				'data_gaps' => $aiContext->dataGaps(),
				'action' => $aiContext->action(),
				'constraints' => $aiContext->constraints(),
				'limitations' => array_values(array_filter(array_column((array) ($aiContext->body['analysis']['limitations'] ?? []), 'code'), 'is_string')),
			],
			'validate' => fn (string $text): ValidationResult => $this->recommendations->validate($text, $aiContext),
		];
	}

	/**
	 * Źródła analizy zapisywane w historii (bez treści): snapshot strony projektu, snapshoty konkurencji, pomiar SERP, gotowość, język.
	 *
	 * @return array<string, mixed>
	 */
	private static function sources(AiContext $aiContext, Readiness $readiness, bool $explicit): array
	{
		$body = $aiContext->body;
		$page = (array) ($body['target_page']['page_content'] ?? []);
		$serp = is_array($body['evidence']['serp'] ?? null) ? $body['evidence']['serp'] : null;

		return [
			'language' => $body['analysis']['language'] ?? null,
			'strategy_action' => $readiness->action,
			'compatibility' => $readiness->mode,
			'explicit' => $explicit,
			'readiness' => ['state' => $readiness->state, 'reasons' => $readiness->reasons, 'limitations' => $readiness->limitations],
			'target_url' => $body['target_page']['url'] ?? null,
			'page_snapshot' => ($page['available'] ?? false) === true ? [
				'ref' => $page['ref'] ?? null,
				'fetched_at' => $page['provenance']['as_of'] ?? null,
				'freshness' => $page['provenance']['freshness'] ?? null,
				'content_quality' => $page['content_quality'] ?? null,
			] : null,
			'competitor_snapshots' => array_values(array_map(static fn (array $item): array => [
				'ref' => $item['ref'] ?? null,
				'usable' => $item['usable'] ?? null,
				'serp_measured_at' => $item['serp']['measured_at'] ?? null,
				'fetched_at' => $item['fetch']['fetched_at'] ?? null,
			], array_filter((array) ($body['evidence']['competitor_pages']['items'] ?? []), 'is_array'))),
			'serp' => $serp === null ? null : ['measured_at' => $serp['provenance']['as_of'] ?? null, 'freshness' => $serp['provenance']['freshness'] ?? null],
			'site_pages' => count((array) ($body['site']['pages'] ?? [])),
		];
	}

	private static function type(string $type): string
	{
		return AnalysisType::fromInput($type) ?? throw new AiRefused('analysis_type_unknown');
	}

	/**
	 * @param Closure(string): ValidationResult $validate
	 */
	private function completed(AiRun $run, AiPlan $plan, AiResponse $response, Closure $validate): AiRun
	{
		$validation = $validate($response->text);
		$this->runs->storeOutput($run, $response->text, $validation->result, $validation->errors);
		$usage = $response->usage;
		$actual = ! $plan->paid ? 0.0 : ($usage === null ? null : $this->pricing->actualCost($usage));
		$finished = $this->settle($run, [
			'status' => $validation->valid() ? AiRun::STATUS_SUCCEEDED : AiRun::STATUS_INVALID,
			'input_tokens' => $usage?->inputTokens,
			'cached_tokens' => $usage?->cachedTokens,
			'output_tokens' => $usage?->outputTokens,
			'actual_cost' => $actual,
			'cost_basis' => ! $plan->paid ? AiRun::COST_FREE : ($actual === null ? AiRun::COST_RESERVATION : AiRun::COST_USAGE),
			'provider_response_id' => $response->responseId,
			'error_code' => $validation->valid() ? null : 'contract_invalid',
			'validation_errors' => min(65535, count($validation->errors)),
		]);
		$this->logger->info('AI run {run} {status}: provider {provider}, model {model}, tokens {input}/{output}, cost {cost} USD.', [
			'run' => $finished->publicId,
			'status' => $finished->status,
			'provider' => $finished->provider,
			'model' => $finished->model,
			'input' => $finished->inputTokens ?? 0,
			'output' => $finished->outputTokens ?? 0,
			'cost' => $finished->chargedCost(),
		]);

		return $finished;
	}

	private function failed(AiRun $run, AiPlan $plan, AiProviderException $exception): AiRun
	{
		$usage = $exception->usage();
		$actual = match (true) {
			! $plan->paid || $exception->notExecuted() => 0.0,
			$usage !== null => $this->pricing->actualCost($usage),
			default => null,
		};
		$finished = $this->settle($run, [
			'status' => $exception->uncertain() ? AiRun::STATUS_UNCERTAIN : AiRun::STATUS_FAILED,
			'input_tokens' => $usage?->inputTokens,
			'cached_tokens' => $usage?->cachedTokens,
			'output_tokens' => $usage?->outputTokens,
			'actual_cost' => $actual,
			'cost_basis' => match (true) {
				! $plan->paid => AiRun::COST_FREE,
				$exception->notExecuted() => AiRun::COST_NOT_CHARGED,
				$actual !== null => AiRun::COST_USAGE,
				default => AiRun::COST_RESERVATION,
			},
			'provider_response_id' => $exception->responseId(),
			'error_code' => $exception->kind(),
		]);
		$this->logger->warning('AI run {run} {status}: provider {provider} error {kind} (HTTP {http}, provider code {provider_error}).', [
			'run' => $finished->publicId,
			'status' => $finished->status,
			'provider' => $finished->provider,
			'kind' => $exception->kind(),
			'http' => $exception->httpStatus() ?? 0,
			'provider_error' => $exception->providerCode() ?? '-',
		]);

		return $finished;
	}

	/**
	 * @param array<string, int|float|string|null> $data
	 */
	private function settle(AiRun $run, array $data): AiRun
	{
		return $this->runs->finish($run, $data)
			?? $this->runs->find($run->projectId, $run->publicId)
			?? throw new AiRunNotFound();
	}
}
