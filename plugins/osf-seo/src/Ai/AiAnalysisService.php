<?php

declare(strict_types=1);

namespace OsfSeo\Ai;

use OsfSeo\Ai\Budget\AiBudget;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Context\AiContext;
use OsfSeo\Ai\Context\AiTopicContextBuilder;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Contract\OutputValidator;
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

		return $this->planFor($context, $this->contexts->build($context, $topic), $provider, $focus);
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
		$plan = $this->planFor($context, $aiContext, $provider, $focus);

		if (! $plan->runnable()) {
			throw new AiRefused($plan->blockers[0], $plan->blockers);
		}

		if ($plan->paid && ! $confirmed) {
			throw new AiRefused('confirmation_required');
		}

		$adapter = $this->providers->get($plan->provider) ?? throw new AiRefused('provider_unknown');
		$input = PromptTemplate::input($aiContext, $plan->focus);
		$data = [
			'project_id' => $context->projectId(),
			'topic_id' => $aiContext->topicId,
			'task' => PromptTemplate::TASK,
			'provider' => $adapter->id(),
			'model' => (string) $plan->model,
			'paid' => $plan->paid ? 1 : 0,
			'prompt_version' => PromptTemplate::VERSION,
			'context_version' => (int) ($aiContext->body['context_version'] ?? 0),
			'contract_version' => AnalysisContract::VERSION,
			'context_fingerprint' => $aiContext->fingerprint(),
			'evidence_fingerprint' => $aiContext->evidenceFingerprint(),
			'evidence_hash' => $aiContext->evidenceHash(),
			'trigger_type' => $trigger === AiRun::TRIGGER_PANEL ? AiRun::TRIGGER_PANEL : AiRun::TRIGGER_CLI,
			'requested_by' => $context->userId() > 0 ? $context->userId() : null,
			'input_tokens_estimate' => $plan->inputTokensEstimate,
			'estimated_cost' => round((float) $plan->maxCost, 6),
			'reserved_cost' => $plan->paid ? round((float) $plan->maxCost, 6) : 0.0,
		];
		$inputDocument = (string) json_encode(['focus' => $plan->focus, 'context' => $aiContext->toArray()], AiContext::JSON_FLAGS);
		$create = fn (): AiRun => $this->runs->create($data, $inputDocument, PromptTemplate::inputHash($input));
		$run = $plan->paid ? $this->budget->reserve($context->projectId(), (float) $plan->maxCost, $create) : $create();

		if (! $this->runs->markRunning($run)) {
			throw new AiRefused('run_conflict');
		}

		$request = new AiRequest(
			(string) $plan->model,
			PromptTemplate::instructions(),
			$input,
			AnalysisContract::NAME,
			AnalysisContract::schema(),
			$plan->maxOutputTokens,
			$plan->paid ? $this->config->temperature() : null,
			['refs' => $aiContext->refs(), 'data_gaps' => $aiContext->dataGaps(), 'action' => $aiContext->action()],
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

		return $this->completed($run, $plan, $aiContext, $response);
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
	 * @return array{run: AiRun, payload: ?array<string, mixed>}
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 */
	public function show(ProjectContext $context, string $runId, bool $withPayload = true): array
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		return ['run' => $run, 'payload' => $withPayload ? $this->runs->payload($run) : null];
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
			'versions' => ['prompt' => PromptTemplate::VERSION, 'context' => Context\TopicContextAssembler::VERSION, 'contract' => AnalysisContract::VERSION],
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

	private function planFor(ProjectContext $context, AiContext $aiContext, string $providerId, ?string $focus): AiPlan
	{
		$provider = $this->providers->get($providerId);
		$focus = PromptTemplate::focus($focus);
		$input = PromptTemplate::input($aiContext, $focus);
		$maxOutput = $this->config->maxOutputTokens();
		$tokens = AiPricing::estimateInputTokens(PromptTemplate::instructions(), $input, (string) json_encode(AnalysisContract::schema()));
		$blockers = [];

		if ($provider === null) {
			return new AiPlan(strtolower(trim($providerId)), null, true, $aiContext, $focus, $tokens, $maxOutput, null, ['provider_unknown'], null);
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

		return new AiPlan($provider->id(), $model, $paid, $aiContext, $focus, $tokens, $maxOutput, $maxCost, array_values(array_unique($blockers)), $budget);
	}

	private function completed(AiRun $run, AiPlan $plan, AiContext $context, AiResponse $response): AiRun
	{
		$validation = $this->validator->validate($response->text, $context->refs());
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
