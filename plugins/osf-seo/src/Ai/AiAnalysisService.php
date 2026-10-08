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
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Analizy AI tematów Strategii (STEP 17, faza A — docs/ARCHITECTURE.md, sekcja 22). Jedyne miejsce, z którego wychodzi wywołanie modelu:
 * kontekst (odczyt Strategii w obrębie projektu) → plan bez żadnego żądania → rezerwacja kosztu pod blokadą budżetu → jedno wywołanie
 * dostawcy (bez ponowień) → walidacja odpowiedzi po stronie PHP → rozliczenie i zapis historii.
 *
 * Wywoływana wyłącznie jawnie z uprawnieniem `osf_seo_manage_ai`: CLI albo zlecenie zatwierdzone w panelu (faza D: `queue` → krok w tle
 * `runQueued`, sekcja 25.4) — nigdy przy renderowaniu panelu, przeliczeniu Strategii ani synchronizacji GSC; tło nigdy samo nie zleca analiz
 * (`maintenance` tylko porządkuje historię, bez wywołań).
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

	/** Zlecenie z panelu nieodebrane przez krok w tle dłużej niż tyle sekund → `failed` (`queue_expired`), rezerwacja zwolniona. */
	public const QUEUE_TTL = 21600;

	/** Najwięcej zleceń z kolejki w jednym kroku w tle (każde to jedno wywołanie dostawcy). */
	public const QUEUE_PER_TICK = 2;

	/** Blokady budżetu liczone już z rezerwacją zlecenia w kolejce — w kroku w tle nie są ponownie liczone. */
	private const RESERVED_BUDGET_BLOCKERS = [AiBudget::DAILY, AiBudget::MONTHLY, AiBudget::PROJECT];

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
		private readonly ?ProjectGuard $guard = null,
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

		// Ta sama blokada zlecenia co analizy rekomendacji — dwa równoległe `ai:run` tego tematu nie płacą dwa razy.
		if (! $this->runs->acquireGenerationLock($context->projectId(), $aiContext->topicId, $plan->task)) {
			throw new AiRefused('run_in_progress');
		}

		try {
			if ($this->runs->activeFor($context->projectId(), $aiContext->topicId, $plan->task) !== null) {
				throw new AiRefused('run_in_progress');
			}

			return $this->execute($context, $plan, $aiContext, $spec, $trigger);
		} finally {
			$this->runs->releaseGenerationLock($context->projectId(), $aiContext->topicId, $plan->task);
		}
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
	 * Plan z już odczytanego źródła tematu (ekran przygotowania w panelu — jedno źródło dla sekcji typów i planu). Ta sama ścieżka co
	 * `planAnalysis`, więc ten sam odcisk planu; źródło wyłącznie z `AiTopicContextBuilder::source()`/`sourceFromView()` dla tego samego
	 * `ProjectContext`. Wykonanie (`queue`, `generate`) zawsze przelicza plan od nowa.
	 *
	 * @param array<string, mixed> $source
	 * @param list<array{label: ?string, action: ?string, target_url: ?string}> $siteTopics
	 *
	 * @throws AccessDenied
	 * @throws AiRefused
	 */
	public function planAnalysisFrom(ProjectContext $context, array $source, array $siteTopics, string $type, string $provider = FakeProvider::ID, ?string $focus = null, bool $explicit = false): AiPlan
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$type = self::type($type);
		['context' => $aiContext, 'readiness' => $readiness] = $this->contexts->analysisFrom($context, $source, $type, $explicit, $siteTopics);

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
		[$plan, $spec] = $this->prepare($context, $topic, $type, $provider, $focus, $explicit, $approvedPlan, $confirmed);
		$aiContext = $plan->context;

		if (! $this->runs->acquireGenerationLock($context->projectId(), $aiContext->topicId, $plan->task)) {
			throw new AiRefused('run_in_progress');
		}

		try {
			$this->assertNotDuplicate($context, $plan, $repeat);

			return $this->execute($context, $plan, $aiContext, $spec, $trigger);
		} finally {
			$this->runs->releaseGenerationLock($context->projectId(), $aiContext->topicId, $plan->task);
		}
	}

	/**
	 * Zakolejkowanie analizy rekomendacji z panelu (faza D, D111): te same kontrole co `generate` w chwili zatwierdzenia — gotowość,
	 * blokady planu, **zawsze** zatwierdzony odcisk planu (także dla dostawcy testowego), potwierdzenie płatnego wywołania, blokada zlecenia,
	 * brak uruchomienia w toku i duplikatu planu — oraz rezerwacja kosztu maksymalnego przy zakolejkowaniu (pod `GET_LOCK ai_budget`).
	 * Bez żadnego wywołania dostawcy: wykonuje krok w tle (`runQueued`) po ponownej weryfikacji planu.
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws AiRefused
	 */
	public function queue(
		ProjectContext $context,
		string $topic,
		string $type,
		string $approvedPlan,
		string $provider = FakeProvider::ID,
		?string $focus = null,
		bool $explicit = false,
		bool $confirmed = false,
		bool $repeat = false,
		string $trigger = AiRun::TRIGGER_PANEL,
	): AiRun {
		[$plan, $spec] = $this->prepare($context, $topic, $type, $provider, $focus, $explicit, $approvedPlan, $confirmed);
		$aiContext = $plan->context;

		if (! $this->runs->acquireGenerationLock($context->projectId(), $aiContext->topicId, $plan->task)) {
			throw new AiRefused('run_in_progress');
		}

		try {
			$this->assertNotDuplicate($context, $plan, $repeat);
			$run = $this->reserve($context, $plan, $aiContext, $spec, $trigger, AiRun::STATUS_QUEUED);
		} finally {
			$this->runs->releaseGenerationLock($context->projectId(), $aiContext->topicId, $plan->task);
		}

		$this->logger->info('AI run {run} queued: {task}, provider {provider}, reserved {cost} USD.', ['run' => $run->publicId, 'task' => $run->task, 'provider' => $run->provider, 'cost' => $run->reservedCost]);

		return $run;
	}

	/**
	 * Krok w tle (faza D): zlecenia z kolejki — przejęcie pod blokadą zlecenia, ponowne przeliczenie planu z zapisanych danych (inny odcisk
	 * → `plan_changed`, gotowość niewystarczająca → odmowa; rezerwacja zwolniona), jedno wywołanie dostawcy bez ponowień, walidacja,
	 * rozliczenie. Wyłącznie proces systemowy (WP-Cron, WP-CLI); co najmniej jedno zlecenie na wywołanie, jeśli jakieś czeka.
	 *
	 * @return array{processed: int, cancelled: int, recovered: int}
	 */
	public function runQueued(float $budget, int $max = self::QUEUE_PER_TICK): array
	{
		if (! ProjectGuard::isSystemProcess() || $this->guard === null) {
			return ['processed' => 0, 'cancelled' => 0, 'recovered' => 0];
		}

		$recovered = $this->recoverStale();
		$deadline = microtime(true) + max(0.0, $budget);
		$processed = 0;
		$cancelled = 0;

		foreach ($this->runs->queued(10) as $run) {
			if ($processed + $cancelled > 0 && ($processed >= $max || microtime(true) > $deadline)) {
				break;
			}

			try {
				$result = $this->processQueued($run);
			} catch (Throwable $exception) {
				// Błąd jednej pozycji nie blokuje kolejki: zlecenie wciąż `queued` (nic nie wysłano przed `markRunning`) → `failed` bez
				// kosztu; uruchomione (`running`) zostaje dla `recoverStale` (wynik niepewny, bez ponowienia).
				$this->logger->error('Queued AI run {run} failed before execution: {class}.', ['run' => $run->publicId, 'class' => $exception::class]);
				$result = $this->abandon($run, 'internal_error');
			}

			if ($result === null) {
				continue;
			}

			$result->status === AiRun::STATUS_FAILED && $result->startedAt === null ? $cancelled++ : $processed++;
		}

		return ['processed' => $processed, 'cancelled' => $cancelled, 'recovered' => $recovered];
	}

	/**
	 * Anulowanie zlecenia w kolejce (jeszcze niewysłanego) — rezerwacja zwolniona, bez kosztu.
	 *
	 * @throws AccessDenied
	 * @throws AiRunNotFound
	 * @throws AiRefused
	 */
	public function cancel(ProjectContext $context, string $runId): AiRun
	{
		$context->assertCan(Capabilities::MANAGE_AI);
		$run = $this->runs->find($context->projectId(), $runId) ?? throw new AiRunNotFound();

		return $this->abandon($run, 'cancelled') ?? throw new AiRefused('run_not_queued');
	}

	/**
	 * Wspólne kontrole generowania (CLI) i zakolejkowania (panel): plan przeliczony teraz, gotowość, blokady, odcisk, potwierdzenie.
	 *
	 * @return array{0: AiPlan, 1: array<string, mixed>}
	 *
	 * @throws AiRefused
	 */
	private function prepare(ProjectContext $context, string $topic, string $type, string $provider, ?string $focus, bool $explicit, ?string $approvedPlan, bool $confirmed): array
	{
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

		$spec = $this->analysisSpec($plan->task, $plan->context, $plan->focus);
		$spec['data'] = [
			'plan_fingerprint' => $plan->fingerprint(),
			'readiness' => $readiness->state,
			'sources' => (string) json_encode(self::sources($plan->context, $readiness, $explicit), AiContext::JSON_FLAGS),
		];

		return [$plan, $spec];
	}

	/** Uruchomienie w toku (także w kolejce) albo ten sam plan już wygenerowany (bez `$repeat`) → odmowa. Pod blokadą zlecenia. */
	private function assertNotDuplicate(ProjectContext $context, AiPlan $plan, bool $repeat): void
	{
		if ($this->runs->activeFor($context->projectId(), $plan->context->topicId, $plan->task) !== null) {
			throw new AiRefused('run_in_progress');
		}

		if ($repeat) {
			return;
		}

		if ($this->runs->succeededWithPlan($context->projectId(), $plan->fingerprint()) !== null) {
			throw new AiRefused('already_generated');
		}

		// Płatne: jedno zatwierdzenie = jedno wywołanie. Ten sam plan już wysłany (wynik niepewny, niepoprawny albo błąd z kosztem) —
		// ponowne wysłanie tylko jawnie (`--repeat` / „Wygeneruj ponownie”), nigdy przez ponowne przesłanie formularza.
		if ($plan->paid && $this->runs->sentWithPlan($context->projectId(), $plan->fingerprint()) !== null) {
			throw new AiRefused('plan_already_used');
		}
	}

	/**
	 * Przebieg zlecenia z kolejki (null — przejął je inny proces albo nie jest już w kolejce).
	 */
	private function processQueued(AiRun $queued): ?AiRun
	{
		if ($queued->topicId === null || ! $this->runs->acquireGenerationLock($queued->projectId, $queued->topicId, $queued->task)) {
			return $queued->topicId === null ? $this->abandon($queued, 'topic_not_found') : null;
		}

		try {
			$run = $this->runs->reload($queued);

			if ($run === null || $run->status !== AiRun::STATUS_QUEUED) {
				return null;
			}

			try {
				$context = $this->guard?->authorizeSystem((string) $run->projectPublicId) ?? throw new ProjectNotFound();
			} catch (ProjectNotFound | AccessDenied) {
				return $this->abandon($run, 'project_unavailable');
			}

			// Projekt zarchiwizowany po zleceniu — bez płatnego wywołania (rezerwacja zwolniona).
			if ($context->project()->isArchived()) {
				return $this->abandon($run, 'project_unavailable');
			}

			if ($run->topicPublicId === null) {
				return $this->abandon($run, 'topic_not_found');
			}

			$input = json_decode((string) ($this->runs->payload($run)['input'] ?? ''), true);
			$focus = is_array($input) && is_string($input['focus'] ?? null) ? $input['focus'] : null;
			$explicit = ($run->sources['explicit'] ?? false) === true;

			try {
				$plan = $this->planAnalysis($context, $run->topicPublicId, $run->task, $run->provider, $focus, $explicit);
			} catch (StrategyNotFound) {
				return $this->abandon($run, 'topic_not_found');
			} catch (AiRefused $refused) {
				return $this->abandon($run, $refused->code());
			}

			// Nigdy inna (np. droższa) analiza niż zatwierdzona: odcisk planu przeliczony teraz musi być identyczny.
			if ($run->planFingerprint === null || ! hash_equals($run->planFingerprint, $plan->fingerprint())) {
				return $this->abandon($run, 'plan_changed');
			}

			$readiness = $plan->readiness;

			if ($readiness === null || ! $readiness->runnable()) {
				return $this->abandon($run, 'readiness_' . ($readiness->state ?? 'unknown'));
			}

			$blockers = array_values(array_diff($plan->blockers, self::RESERVED_BUDGET_BLOCKERS));

			if ($blockers !== []) {
				return $this->abandon($run, $blockers[0]);
			}

			try {
				return $this->call($run, $plan, $this->analysisSpec($plan->task, $plan->context, $plan->focus), AiRun::STATUS_QUEUED);
			} catch (AiRefused $refused) {
				// Anulowanie albo wygaśnięcie wygrało wyścig przed `markRunning` — nic nie wysłano, stan ustalił inny proces.
				if ($refused->code() === 'run_conflict') {
					return null;
				}

				throw $refused;
			}
		} finally {
			$this->runs->releaseGenerationLock($queued->projectId, (int) $queued->topicId, $queued->task);
		}
	}

	/** Zlecenie z kolejki zakończone bez wysłania żądania (`failed`, bez kosztu — rezerwacja zwolniona). Null — nie było w kolejce. */
	/**
	 * Koszt rzeczywisty powyżej rezerwacji (zaniżone oszacowanie tokenów wejścia) albo poziom przetwarzania inny niż standardowy —
	 * ostrzeżenie w logu (bez treści); koszt zapisany zgodnie ze zużyciem.
	 */
	private function warnOverReservation(AiRun $run, ?string $tier): void
	{
		if ($run->paid && $run->actualCost !== null && $run->actualCost > $run->reservedCost) {
			$this->logger->warning('AI run {run} cost {actual} USD exceeded the reservation {reserved} USD (input estimate too low).', ['run' => $run->publicId, 'actual' => $run->actualCost, 'reserved' => $run->reservedCost]);
		}

		if ($run->paid && $tier !== null && $tier !== 'default') {
			$this->logger->warning('AI run {run} was served with service tier {tier} — configured prices assume the default tier.', ['run' => $run->publicId, 'tier' => $tier]);
		}
	}

	/** Wydłuża limit czasu PHP (tylko gdy jest ustawiony — `0` w CLI oznacza brak limitu) i nie przerywa po rozłączeniu klienta. */
	private static function extendTimeLimit(int $seconds): void
	{
		$current = (int) ini_get('max_execution_time');

		if ($current > 0 && $current < $seconds && function_exists('set_time_limit')) {
			@set_time_limit($seconds);
		}

		if (function_exists('ignore_user_abort')) {
			ignore_user_abort(true);
		}
	}

	private function abandon(AiRun $run, string $code): ?AiRun
	{
		$done = $this->runs->finish($run, [
			'status' => AiRun::STATUS_FAILED,
			'actual_cost' => 0.0,
			'cost_basis' => $run->paid ? AiRun::COST_NOT_CHARGED : AiRun::COST_FREE,
			'error_code' => $code,
		], AiRun::STATUS_QUEUED);

		if ($done !== null) {
			$this->logger->info('AI run {run} not executed: {code} (reservation released).', ['run' => $run->publicId, 'code' => $code]);
		}

		return $done;
	}

	/**
	 * Wspólna ścieżka wykonania (faza A i C): rezerwacja kosztu pod blokadą budżetu → `running` → jedno wywołanie dostawcy (bez ponowień)
	 * → walidacja → rozliczenie i historia.
	 *
	 * @param array<string, mixed> $spec
	 */
	private function execute(ProjectContext $context, AiPlan $plan, AiContext $aiContext, array $spec, string $trigger): AiRun
	{
		return $this->call($this->reserve($context, $plan, $aiContext, $spec, $trigger), $plan, $spec);
	}

	/**
	 * Zapis uruchomienia z wejściem — dla płatnego dostawcy pod blokadą budżetu z rezerwacją kosztu maksymalnego.
	 *
	 * @param array<string, mixed> $spec
	 */
	private function reserve(ProjectContext $context, AiPlan $plan, AiContext $aiContext, array $spec, string $trigger, string $status = AiRun::STATUS_RESERVED): AiRun
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
		$create = fn (): AiRun => $this->runs->create($data, $inputDocument, (string) $spec['input_hash'], $status);

		return $plan->paid ? $this->budget->reserve($context->projectId(), (float) $plan->maxCost, $create) : $create();
	}

	/**
	 * `running` (warunkowo) → jedno wywołanie dostawcy (bez ponowień) → walidacja → rozliczenie i historia.
	 *
	 * @param array<string, mixed> $spec
	 */
	private function call(AiRun $run, AiPlan $plan, array $spec, string $from = AiRun::STATUS_RESERVED): AiRun
	{
		$adapter = $this->providers->get($plan->provider) ?? throw new AiRefused('provider_unknown');

		// Przed oznaczeniem `running`: czas na pełny timeout dostawcy (proces www albo WP-Cron z limitem `max_execution_time`; w CLI
		// limit 0 zostaje) — inaczej przerwanie w trakcie żądania = wynik niepewny i cała rezerwacja bez wyniku.
		self::extendTimeLimit($this->config->timeout() + 60);

		if (! $this->runs->markRunning($run, $from)) {
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
			$plan->options['reasoning_effort'] ?? null,
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
		$sources = [];
		$result = [];

		foreach ($runs as $run) {
			if ($run->projectId === $context->projectId()) {
				$result[$run->publicId] = $this->freshnessOf($context, $run, $current, $sources);
			}
		}

		return $result;
	}

	/**
	 * Czy dowody tematu zmieniły się od uruchomienia: odcisk dowodów (bez stanu pracy tematu) i wersja kontekstu. Wynik nieaktualny
	 * nie jest usuwany ani zmieniany — tylko oznaczany.
	 *
	 * @param array<string, array{fingerprint: ?string, version: ?int}> $current pamięć bieżących odcisków (temat × typ × wybór jawny)
	 * @param array<string, array{source: ?array<string, mixed>, site: ?array<int, mixed>}> $sources pamięć źródeł tematów (jedno źródło
	 *        tematu dla wszystkich typów analiz — bez ponownego odczytu Strategii dla każdego typu)
	 * @return array{stale: ?bool, reason: ?string, current_evidence_fingerprint: ?string}
	 */
	private function freshnessOf(ProjectContext $context, AiRun $run, array &$current = [], array &$sources = []): array
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
			if (! array_key_exists($run->topicPublicId, $sources)) {
				try {
					$sources[$run->topicPublicId] = ['source' => $this->contexts->source($context, $run->topicPublicId), 'site' => null];
				} catch (StrategyNotFound) {
					$sources[$run->topicPublicId] = ['source' => null, 'site' => null];
				}
			}

			$source = $sources[$run->topicPublicId]['source'];

			if ($source === null) {
				$current[$key] = ['fingerprint' => null, 'version' => null];
			} else {
				if (in_array($run->task, AnalysisType::RECOMMENDATIONS, true)) {
					$sources[$run->topicPublicId]['site'] ??= $this->contexts->siteTopics($context, (int) $source['topic_id']);
					$aiContext = $this->contexts->analysisFrom($context, $source, $run->task, $explicit, $sources[$run->topicPublicId]['site'])['context'];
				} else {
					$aiContext = $this->contexts->buildFrom($source);
				}

				$current[$key] = ['fingerprint' => $aiContext->evidenceFingerprint(), 'version' => (int) ($aiContext->body['context_version'] ?? 0)];
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

		// Koszt liczy się do budżetu z wierszy historii — płatnej analizy z bieżącego miesiąca (UTC) nie usuwamy, bo zwolniłoby to budżet.
		if ($run->paid && $run->chargedCost() > 0 && $run->createdAt >= $this->clock->now()->format('Y-m-01 00:00:00')) {
			throw new AiRefused('run_counts_toward_budget');
		}

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
	 * Stan kolejki zleceń z panelu (bez sekretów i bez treści): liczby, wiek najstarszego zlecenia i ostatni przebieg kroków w tle.
	 * `stuck` — zlecenie czeka dłużej niż BACKGROUND_STALE_SECONDS, a tło nie działa (cron) — nigdy niewidoczne „w kolejce” bez końca.
	 *
	 * @return array{queued: int, in_progress: int, oldest_queued: ?string, oldest_queued_age: ?int, oldest_running: ?string, background: array{heartbeat: ?string, age_seconds: ?int, stale: bool}, stuck: bool}
	 */
	public function queueHealth(): array
	{
		$counts = $this->runs->statusCounts();
		$ages = $this->runs->queueAges();
		$now = $this->clock->now()->getTimestamp();
		$age = $ages['oldest_queued'] === null ? null : max(0, $now - (int) strtotime($ages['oldest_queued'] . ' UTC'));
		$background = \OsfSeo\Sync\SyncScheduler::backgroundHealth($now);

		return [
			'queued' => $counts[AiRun::STATUS_QUEUED] ?? 0,
			'in_progress' => ($counts[AiRun::STATUS_RUNNING] ?? 0) + ($counts[AiRun::STATUS_RESERVED] ?? 0),
			'oldest_queued' => $ages['oldest_queued'],
			'oldest_queued_age' => $age,
			'oldest_running' => $ages['oldest_running'],
			'background' => $background,
			'stuck' => $age !== null && $age > \OsfSeo\Sync\SyncScheduler::BACKGROUND_STALE_SECONDS && $background['stale'],
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

		foreach ($this->runs->staleQueued($this->clock->now()->modify('-' . self::QUEUE_TTL . ' seconds')->format('Y-m-d H:i:s')) as $run) {
			if ($this->abandon($run, 'queue_expired') !== null) {
				$recovered++;
			}
		}

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

			// Tryb kontrolowanego testu (faza E): płatne tylko dla wskazanych projektów i typów — sprawdzane też przy wykonaniu z kolejki.
			$projects = $this->config->allowedProjects();
			$types = $this->config->allowedTypes();

			if ($projects !== [] && ! in_array($context->publicId(), $projects, true)) {
				$blockers[] = 'project_not_allowed';
			}

			if ($types !== [] && ! in_array((string) $spec['task'], $types, true)) {
				$blockers[] = 'type_not_allowed';
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

		$options = $paid && $this->config->reasoningEffort() !== null ? ['reasoning_effort' => (string) $this->config->reasoningEffort()] : [];

		return new AiPlan($provider->id(), $model, $paid, $aiContext, $focus, $tokens, $maxOutput, $maxCost, array_values(array_unique($blockers)), $budget, ...$versions, options: $options);
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
			'strategy_hash' => $aiContext->strategyHash,
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
		$this->logger->info('AI run {run} {status}: provider {provider}, model {model} (served {served}, tier {tier}), tokens {input}/{output} (cached {cached}, cache write {written}, reasoning {reasoning}), cost {cost} USD.', [
			'run' => $finished->publicId,
			'status' => $finished->status,
			'provider' => $finished->provider,
			'model' => $finished->model,
			'served' => $response->model ?? '-',
			'tier' => $response->serviceTier ?? '-',
			'input' => $finished->inputTokens ?? 0,
			'output' => $finished->outputTokens ?? 0,
			'cached' => $usage?->cachedTokens ?? 0,
			'written' => $usage?->cacheWriteTokens ?? 0,
			'reasoning' => $usage?->reasoningTokens ?? 0,
			'cost' => $finished->chargedCost(),
		]);
		$this->warnOverReservation($finished, $response->serviceTier);

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
		// Przerwana odpowiedź z powodem dostawcy (limit tokenów, filtr treści) — czytelny kod zamiast ogólnego „incomplete”.
		$code = $exception->kind() === AiProviderException::INCOMPLETE && in_array($exception->providerCode(), ['max_output_tokens', 'content_filter'], true)
			? 'incomplete_' . $exception->providerCode()
			: $exception->kind();
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
			'error_code' => $code,
		]);
		$this->logger->warning('AI run {run} {status}: provider {provider} error {kind} (HTTP {http}, provider code {provider_error}).', [
			'run' => $finished->publicId,
			'status' => $finished->status,
			'provider' => $finished->provider,
			'kind' => $exception->kind(),
			'http' => $exception->httpStatus() ?? 0,
			'provider_error' => $exception->providerCode() ?? '-',
		]);
		$this->warnOverReservation($finished, null);

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
