<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Strategy\StrategyRefresher;
use OsfSeo\Strategy\StrategyRefreshQueue;
use OsfSeo\Strategy\StrategyScheduler;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\StrategySettings;
use OsfSeo\Sync\SyncConfig;
use OsfSeo\Sync\SyncScheduler;
use RuntimeException;

/**
 * Przeliczenie Strategii w tle (STEP 16, faza E) na prawdziwej bazie: zlecenie z panelu → zadanie → krok w tle → wynik, bez duplikatów
 * i bez równoległego przeliczenia, nowe dane w trakcie, brak zapisów bez zmian, ponowienia z odstępem, odzyskanie przerwanego zadania,
 * uprawnienia (bez capability, klient, IDOR), statusy panelu, debounce importu wielu źródeł — i zero żądań do API.
 */
final class StrategyBackgroundTest extends StrategyTestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	protected function setUp(): void
	{
		parent::setUp();
		add_filter('wp_doing_cron', '__return_true');
		delete_transient('osf_seo_strategy_checked');
		delete_option(StrategyScheduler::HEARTBEAT_OPTION);
	}

	protected function tearDown(): void
	{
		remove_filter('wp_doing_cron', '__return_true');
		$this->duringRefresh = null;
		// L: żaden scenariusz nie wysyła żądań do DataForSEO ani nie tworzy płatnych zadań (krok w tle — zero żądań HTTP, `background()`).
		self::assertSame([], $this->dataForSeoRequests());
		$db = self::db();

		foreach (['market_tasks', 'serp_runs', 'gap_runs', 'discovery_runs'] as $table) {
			self::assertSame('0', $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`"), "Bez płatnych zadań ({$table}).");
		}

		parent::tearDown();
	}

	public function test_panel_request_queues_one_job_and_the_worker_refreshes_it(): void
	{
		$context = $this->scenario();
		self::assertSame('pending', $this->phase($context), 'Nigdy nieprzeliczona Strategia z danymi czeka na przeliczenie.');

		// A: kliknięcie zapisuje zadanie — bez przeliczenia w żądaniu.
		$this->strategy->requestRefresh($context);
		$settings = $this->settings($context);
		self::assertSame(
			[StrategyRefreshQueue::STATUS_QUEUED, StrategyRefreshQueue::SOURCE_MANUAL, '2026-01-15 12:00:00', 0, $context->userId()],
			[$settings->refreshStatus, $settings->refreshSource, $settings->refreshDueAt, $settings->refreshAttempts, $settings->refreshRequestedBy],
		);
		self::assertSame(0, $this->topicCount($context), 'Zlecenie niczego nie przelicza.');
		self::assertNull($settings->refreshedAt);
		self::assertSame('queued', $this->phase($context));

		// C: ponowne kliknięcie nie tworzy drugiego zadania.
		$this->clock->advance(5);
		$this->strategy->requestRefresh($context);
		self::assertCount(1, $this->refreshQueue->dueWithProjects(10));
		self::assertSame('2026-01-15 12:00:05', $this->settings($context)->refreshDueAt);

		// B: krok w tle przelicza projekt (zlecenie ręczne — wymuszone).
		$report = $this->background();
		self::assertSame([$context->projectId() => 'refreshed'], $report['jobs']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_IDLE, 0, null, null], [$settings->refreshStatus, $settings->refreshAttempts, $settings->refreshRequestedAt, $settings->refreshDueAt]);
		self::assertNotNull($settings->refreshedAt);
		self::assertSame(3, $this->topicCount($context));
		self::assertSame(['collect', 'evidence', 'key', 'save', 'topics'], self::sortedKeys($settings->stats['timings']), 'Czasy faz przeliczenia w statystykach.');
		$state = $this->strategy->panelState($context);
		self::assertSame(['done', true], [$state['job']['phase'], $state['up_to_date']]);
		self::assertSame(3, $state['last_refresh']['topics']['topics']);
		self::assertNotNull(get_option(StrategyScheduler::HEARTBEAT_OPTION));

		// Bez cyklu przeliczenie → unieważnienie → przeliczenie: kolejny krok nic nie robi.
		$this->clock->advance(60);
		$report = $this->background();
		self::assertSame([], $report['jobs']);
		self::assertSame(['current' => 1], $report['detected']);
		self::assertSame($settings->refreshedAt, $this->settings($context)->refreshedAt);

		$this->clock->advance(StrategyService::DONE_WINDOW + 1);
		self::assertSame('current', $this->phase($context));
	}

	public function test_click_during_a_running_refresh_queues_exactly_one_follow_up(): void
	{
		$context = $this->scenario();
		$this->strategy->requestRefresh($context);
		$clicks = 0;
		// Kliknięcie w trakcie przeliczenia (po jego starcie).
		$this->duringRefresh = function () use ($context, &$clicks): void {
			if ($clicks++ === 0) {
				$this->clock->advance(2);
				$this->strategy->requestRefresh($context);
				self::assertSame(StrategyRefreshQueue::STATUS_RUNNING, $this->settings($context)->refreshStatus, 'Zlecenie w trakcie nie zmienia statusu.');
			}
		};

		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_QUEUED, StrategyRefreshQueue::SOURCE_MANUAL, 0], [$settings->refreshStatus, $settings->refreshSource, $settings->refreshAttempts], 'Nowsze zlecenie nie ginie.');
		self::assertNotNull($settings->refreshRequestedAt);
		self::assertNull($settings->dataKey, 'Klucz danych nadal unieważniony nowszym zleceniem.');

		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs'], 'Jedno kolejne przeliczenie.');
		self::assertSame([StrategyRefreshQueue::STATUS_IDLE, null], [$this->settings($context)->refreshStatus, $this->settings($context)->refreshRequestedAt]);
		self::assertSame([], $this->background()['jobs']);
		self::assertSame(2, $clicks);
	}

	public function test_parallel_processes_never_refresh_the_same_project_at_once(): void
	{
		$context = $this->scenario();
		$this->strategy->requestRefresh($context);
		$lock = StrategyRefresher::lock($context->projectId());
		// D: projekt przelicza inny proces (CLI albo drugi worker WP-Cron).
		$other = $this->holdLock($lock);

		try {
			self::assertSame([$context->projectId() => 'busy'], $this->background()['jobs']);
			$settings = $this->settings($context);
			self::assertSame([StrategyRefreshQueue::STATUS_QUEUED, 0, '2026-01-15 12:01:00'], [$settings->refreshStatus, $settings->refreshAttempts, $settings->refreshDueAt], 'Przesunięte bez liczenia próby.');
			self::assertSame(StrategyRefresher::SKIPPED_LOCKED, $this->strategy->refresh($context, true)['skipped'], 'CLI też czeka na blokadę.');
			self::assertSame('running', $this->phase($context), 'Blokada innego procesu = przeliczanie.');
			self::assertSame(0, $this->topicCount($context));
		} finally {
			$this->releaseHeldLock($other, $lock);
		}

		self::assertSame([], $this->background()['jobs'], 'Przed terminem — bez zadania.');
		$this->clock->advance(60);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);

		// Atomowe przejęcie: drugie przejęcie tego samego zadania się nie udaje.
		$this->clock->advance(10);
		$this->strategy->requestRefresh($context);
		self::assertNotNull($this->refreshQueue->claim($context->projectId()));
		self::assertNull($this->refreshQueue->claim($context->projectId()));
	}

	public function test_data_changed_during_a_refresh_triggers_one_follow_up_after_the_quiet_period(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$imported = false;
		// E: import GSC kończy się w trakcie przeliczenia (po wyliczeniu klucza danych).
		$this->duringRefresh = function () use ($context, &$imported): void {
			if (! $imported) {
				$imported = true;
				$this->gscKeyword($context, 'pozycjonowanie sklepu', 150, 12.0, self::PAGE, 0, '2026-01-14');
			}
		};
		$this->clock->advance(30);
		$this->strategy->requestRefresh($context);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		$settings = $this->settings($context);
		self::assertSame(StrategyRefreshQueue::STATUS_IDLE, $settings->refreshStatus);
		self::assertNotNull($settings->refreshSeenKey, 'Zmiana w trakcie zauważona.');
		self::assertSame('2026-01-15 12:00:30', $settings->refreshDirtySince);
		self::assertSame('pending', $this->phase($context));
		self::assertNull($this->candidateRow($context, 'pozycjonowanie sklepu'), 'Fraza z importu w trakcie — w kolejnym przeliczeniu.');

		$this->clock->advance(60);
		self::assertSame(['waiting' => 1], $this->background()['detected'], 'Okno ciszy.');
		$this->clock->advance(StrategyScheduler::QUIET);
		$report = $this->background();
		self::assertSame(['queued' => 1], $report['detected']);
		self::assertSame([$context->projectId() => 'refreshed'], $report['jobs']);
		self::assertSame(StrategyRefreshQueue::SOURCE_AUTO, $this->settings($context)->refreshSource);
		self::assertNotNull($this->candidateRow($context, 'pozycjonowanie sklepu'));
		self::assertNull($this->settings($context)->refreshSeenKey);

		$this->clock->advance(60);
		$report = $this->background();
		self::assertSame([['current' => 1], []], [$report['detected'], $report['jobs']], 'Jedno przeliczenie — bez pętli.');
	}

	public function test_unchanged_data_writes_nothing_and_adds_no_events(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$before = $this->strategySnapshot($context);
		$settingsRow = $this->settingsRow($context);

		// F: wykrywanie bez zmian danych — zero zapisów (także wiersza stanu).
		$this->clock->advance(600);
		$report = $this->background();
		self::assertSame([['current' => 1], []], [$report['detected'], $report['jobs']]);
		self::assertSame($before, $this->strategySnapshot($context));
		self::assertSame($settingsRow, $this->settingsRow($context));

		// Wymuszone przeliczenie (zlecenie ręczne) bez zmian danych: kandydaci, tematy i zdarzenia nietknięte.
		$this->strategy->requestRefresh($context);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		self::assertSame($before, $this->strategySnapshot($context), 'Bez zapisów kandydatów, tematów i zdarzeń.');
		$stats = $this->settings($context)->stats;
		self::assertSame(['inserted' => 0, 'updated' => 0, 'unchanged' => 4, 'deactivated' => 0], $stats['keywords']);
		self::assertSame([0, 0, 0, 3], [$stats['topics']['inserted'], $stats['topics']['updated'], $stats['topics']['events'], $stats['topics']['unchanged']]);
	}

	public function test_failures_retry_with_bounded_backoff_and_stop_until_new_data_or_a_manual_request(): void
	{
		$context = $this->scenario();
		$this->duringRefresh = static function (): void {
			throw new RuntimeException('Synthetic failure with internal details /var/www/secret-path');
		};
		$this->strategy->requestRefresh($context);

		// G: próba 1 → ponowienie po 1 min, próba 2 → po 5 min, próba 3 → błąd bez kolejnych ponowień.
		self::assertSame([$context->projectId() => 'failed'], $this->background()['jobs']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_QUEUED, 1, 'error:RuntimeException', '2026-01-15 12:01:00'], [$settings->refreshStatus, $settings->refreshAttempts, $settings->refreshError, $settings->refreshDueAt]);
		self::assertSame('retrying', $this->phase($context));
		self::assertSame([], $this->background()['jobs'], 'Odstęp ponowienia.');
		$this->clock->advance(60);
		self::assertSame([$context->projectId() => 'failed'], $this->background()['jobs']);
		self::assertSame([2, '2026-01-15 12:06:00'], [$this->settings($context)->refreshAttempts, $this->settings($context)->refreshDueAt]);
		$this->clock->advance(300);
		self::assertSame([$context->projectId() => 'failed'], $this->background()['jobs']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_FAILED, 3, null], [$settings->refreshStatus, $settings->refreshAttempts, $settings->refreshDueAt]);
		self::assertSame($this->strategyRefresher->currentKey($context->projectId()), $settings->refreshFailedKey);

		// Panel: kod błędu dla zarządzającego, bez treści wyjątku; szczegóły wyłącznie w logu.
		$job = $this->strategy->panelState($context)['job'];
		self::assertSame(['failed', 'error:RuntimeException'], [$job['phase'], $job['error']]);
		self::assertStringNotContainsString('secret-path', (string) wp_json_encode($this->strategy->panelState($context)));
		self::assertStringContainsString('Synthetic failure', implode("\n", $this->logLines));

		// Te same dane — bez automatycznego ponowienia (żadnej nieskończonej pętli).
		for ($i = 0; $i < 5; $i++) {
			$this->clock->advance(3600);
			$report = $this->background();
			self::assertSame([], $report['jobs']);
		}

		self::assertSame(['failed_same_data' => 1], $report['detected']);

		// Nowe dane → nowe automatyczne zlecenie (po oknie ciszy) z nowym limitem prób.
		$this->duringRefresh = null;
		$this->gscKeyword($context, 'pozycjonowanie sklepu', 150, 12.0, self::PAGE);
		self::assertSame(['seen' => 1], $this->background()['detected']);
		$this->clock->advance(StrategyScheduler::QUIET);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_IDLE, 0, null], [$settings->refreshStatus, $settings->refreshAttempts, $settings->refreshFailedKey]);
		self::assertSame('error:RuntimeException', $settings->refreshError, 'Ostatni błąd zostaje w diagnostyce.');
		self::assertSame('done', $this->phase($context));
		self::assertNull($this->strategy->panelState($context)['job']['error'], 'Po udanym przeliczeniu panel nie pokazuje starego błędu.');

		// Zlecenie ręczne po wyczerpaniu prób — zawsze możliwe.
		$this->duringRefresh = static function (): void {
			throw new RuntimeException('again');
		};

		for ($i = 0; $i < 3; $i++) {
			$this->clock->advance(400);
			$i === 0 ? $this->strategy->requestRefresh($context) : null;
			$this->background();
		}

		self::assertSame(StrategyRefreshQueue::STATUS_FAILED, $this->settings($context)->refreshStatus);
		$this->duringRefresh = null;
		$this->clock->advance(5);
		$this->strategy->requestRefresh($context);
		self::assertSame([0, StrategyRefreshQueue::STATUS_QUEUED], [$this->settings($context)->refreshAttempts, $this->settings($context)->refreshStatus]);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
	}

	public function test_interrupted_job_is_recovered_and_a_live_process_is_left_alone(): void
	{
		$context = $this->scenario();
		$this->strategy->requestRefresh($context);
		$lock = StrategyRefresher::lock($context->projectId());

		// H: żywy proces trzyma blokadę i zadanie — krok w tle go nie przejmuje ani nie odzyskuje.
		$other = $this->holdLock($lock);
		$token = $this->refreshQueue->claim($context->projectId());
		self::assertNotNull($token);

		try {
			$report = $this->background();
			self::assertSame([[], []], [$report['recovered'], $report['jobs']]);
			self::assertSame(StrategyRefreshQueue::STATUS_RUNNING, $this->settings($context)->refreshStatus);
			self::assertSame('running', $this->phase($context));
		} finally {
			// Proces PHP ginie (limit czasu, pamięci) — serwer bazy zwalnia jego blokadę; status `running` zostaje.
			$this->releaseHeldLock($other, $lock);
		}

		$this->clock->advance(5);
		$report = $this->background();
		self::assertSame([$context->projectId() => StrategyRefreshQueue::STATUS_QUEUED], $report['recovered']);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::ERROR_INTERRUPTED, 1, '2026-01-15 12:01:05'], [$settings->refreshError, $settings->refreshAttempts, $settings->refreshDueAt], 'Przerwanie liczy się jako próba.');
		self::assertSame([], $report['jobs']);
		self::assertStringContainsString('was interrupted', implode("\n", $this->logLines));

		$this->clock->advance(60);
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		self::assertSame(StrategyRefreshQueue::STATUS_IDLE, $this->settings($context)->refreshStatus);
		self::assertSame(3, $this->topicCount($context));

		// Trzy przerwania z rzędu → `failed` (bez nieskończonych prób).
		$this->clock->advance(10);
		$this->strategy->requestRefresh($context);

		for ($attempt = 1; $attempt <= StrategyRefreshQueue::MAX_ATTEMPTS; $attempt++) {
			$this->clock->advance(400);
			self::assertNotNull($this->refreshQueue->claim($context->projectId()));
			$this->background();
		}

		self::assertSame([StrategyRefreshQueue::STATUS_FAILED, StrategyRefreshQueue::ERROR_INTERRUPTED], [$this->settings($context)->refreshStatus, $this->settings($context)->refreshError]);
	}

	public function test_only_managers_can_request_and_the_job_never_crosses_projects(): void
	{
		$context = $this->scenario();
		$other = $this->gapProject([], 'drugi.pl');
		$this->gscKeyword($other, 'sklep internetowy', 300, 8.0);

		// I: członek projektu z rolą managera, ale bez capability — brak zlecenia.
		$manager = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $manager, ProjectRole::Manager);
		// J: klient — tylko odczyt.
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);

		foreach (['manager bez capability' => $manager, 'klient' => $client] as $label => $user) {
			try {
				$this->strategy->requestRefresh($this->guard->authorize($context->publicId(), $user));
				self::fail('Bez osf_seo_manage_strategy: ' . $label);
			} catch (AccessDenied) {
			}
		}

		self::assertSame(StrategyRefreshQueue::STATUS_IDLE, $this->settings($context)->refreshStatus);
		self::assertNull($this->settings($context)->refreshRequestedAt);

		// Klient widzi stan bez kodu błędu, źródła zlecenia, identyfikatora użytkownika i stanu workera.
		$this->strategy->requestRefresh($context);
		$job = $this->strategy->panelState($this->guard->authorize($context->publicId(), $client))['job'];
		self::assertSame(['queued', null, null, null, false], [$job['phase'], $job['source'], $job['error'], $job['worker_heartbeat'], $job['worker_stale']]);

		// K: IDOR — klient projektu A nie widzi projektu B.
		try {
			$this->guard->authorize($other->publicId(), $client);
			self::fail('Projekt nieprzypisany.');
		} catch (ProjectNotFound) {
		}

		// Zadanie projektu A nie przelicza projektu B (projekt rozwiązany z identyfikatora zapisanego w bazie).
		self::assertSame([$context->projectId() => 'refreshed'], $this->background()['jobs']);
		self::assertSame(0, $this->topicCount($other));
		self::assertNull($this->settings($other)->refreshedAt);

		// Krok w tle działa wyłącznie w procesie systemowym (WP-CLI, WP-Cron) — nigdy w żądaniu WWW.
		remove_filter('wp_doing_cron', '__return_true');
		$this->strategy->requestRefresh($context);
		self::assertSame([], $this->background()['jobs']);
		self::assertSame(StrategyRefreshQueue::STATUS_QUEUED, $this->settings($context)->refreshStatus);
		add_filter('wp_doing_cron', '__return_true');
	}

	public function test_cli_refresh_records_the_job_and_executes_a_pending_request(): void
	{
		$context = $this->scenario();
		$this->strategy->requestRefresh($context);
		$this->clock->advance(10);

		// M: `wp osf-seo strategy:refresh` — ta sama blokada i stan zadania (źródło `cli`); zlecenie sprzed startu wykonane.
		$report = $this->strategy->refresh($context);
		self::assertSame([null, 'refreshed'], [$report['skipped'], $report['outcome']]);
		$settings = $this->settings($context);
		self::assertSame([StrategyRefreshQueue::STATUS_IDLE, StrategyRefreshQueue::SOURCE_CLI, null], [$settings->refreshStatus, $settings->refreshSource, $settings->refreshRequestedAt]);
		self::assertSame([], $this->background()['jobs'], 'Zlecenie już wykonane.');
		self::assertSame(StrategyRefresher::SKIPPED_UNCHANGED, $this->strategy->refresh($context)['skipped']);
		self::assertSame('idle', $this->strategy->status($context)['queue']['status']);

		// Błąd CLI: przekazany wywołującemu i zapisany jak w tle (ponowienie z odstępem).
		$this->duringRefresh = static function (): void {
			throw new RuntimeException('cli failure');
		};

		try {
			$this->strategy->refresh($context, true);
			self::fail('Błąd przekazany do CLI.');
		} catch (RuntimeException) {
		}

		self::assertSame([StrategyRefreshQueue::STATUS_QUEUED, 1, 'error:RuntimeException'], [$this->settings($context)->refreshStatus, $this->settings($context)->refreshAttempts, $this->settings($context)->refreshError]);
	}

	public function test_panel_phases_follow_the_queue(): void
	{
		$context = $this->scenario();
		$phases = [$this->phase($context)];
		$this->strategy->requestRefresh($context);
		$phases[] = $this->phase($context);
		$lock = StrategyRefresher::lock($context->projectId());
		$other = $this->holdLock($lock);
		$token = $this->refreshQueue->claim($context->projectId());
		$phases[] = $this->phase($context);
		self::assertNotNull($token);
		self::assertSame('2026-01-15 12:00:00', $this->strategy->panelState($context)['job']['started_at']);
		$this->releaseHeldLock($other, $lock);
		self::assertSame(StrategyRefreshQueue::STATUS_QUEUED, $this->refreshQueue->fail($context->projectId(), $token, 'error:Test', null));
		$phases[] = $this->phase($context);
		$this->clock->advance(60);
		$this->background();
		$phases[] = $this->phase($context);
		$this->clock->advance(StrategyService::DONE_WINDOW + 1);
		$phases[] = $this->phase($context);
		$this->gscKeyword($context, 'pozycjonowanie sklepu', 150, 12.0, self::PAGE);
		$phases[] = $this->phase($context);

		// N: statusy panelu odpowiadają stanowi kolejki.
		self::assertSame(['pending', 'queued', 'running', 'retrying', 'done', 'current', 'pending'], $phases);
		self::assertSame(['seen' => 1], $this->background()['detected']);
		$job = $this->strategy->panelState($context)['job'];
		self::assertSame(['pending', false], [$job['phase'], $job['worker_stale']], 'Krok w tle działał przed chwilą.');
		$this->clock->advance(StrategyService::WORKER_STALE_AFTER + 1);
		self::assertTrue($this->strategy->panelState($context)['job']['worker_stale'], 'Brak kroku w tle — ostrzeżenie dla administratora.');

		// Rynek nieobsługiwany — bez zadań i bez wykrywania.
		$unsupported = $this->gapProject([], 'trzeci.pl');
		self::db()->execute("UPDATE `" . self::db()->table('projects') . "` SET country = 'XX' WHERE id = %d", [$unsupported->projectId()]);
		$unsupported = $this->guard->authorizeSystem($unsupported->publicId());
		self::assertSame('unsupported', $this->phase($unsupported));
	}

	public function test_import_across_sources_triggers_a_single_refresh(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$refreshedAt = $this->settings($context)->refreshedAt;
		$db = self::db();
		// GSC: zadanie importu oczekuje (seria zapisów), w tym czasie kolejne moduły zapisują dane.
		$runId = $db->insert($db->table('sync_runs'), [
			'project_id' => $context->projectId(), 'dataset' => 'query', 'trigger_type' => 'schedule', 'window_start' => '2026-01-12', 'window_end' => '2026-01-14',
			'status' => 'queued', 'priority' => 1, 'attempt' => 0, 'queued_at' => '2026-01-15 12:00:00', 'available_at' => '2026-01-15 12:00:00',
		]);
		$writes = [
			fn () => $this->gscKeyword($context, 'pozycjonowanie sklepu', 150, 12.0, self::PAGE),
			fn () => $this->opportunity($context, 'ctr', self::PAGE, 'pozycjonowanie stron', 'pozycjonowanie stron', 70),
			fn () => $this->discoveryCandidate($context, 'pozycjonowanie lokalne', 'new', 72),
			fn () => $this->gscKeyword($context, 'pozycjonowanie bloga', 120, 13.0, self::PAGE),
			fn () => $this->strategy->addKeywords($context, ['agencja seo warszawa']),
		];
		$refreshes = 0;

		// O: zapis co minutę z kilku źródeł — żadnego przeliczenia w trakcie (klucz się zmienia, import oczekuje).
		foreach ($writes as $write) {
			$write();
			$this->clock->advance(60);
			$report = $this->background();
			$refreshes += count(array_filter($report['jobs'], static fn (string $outcome): bool => $outcome === 'refreshed'));
		}

		// Klucz stabilny, ale import GSC nadal oczekuje — dalej bez przeliczenia.
		for ($i = 0; $i < 3; $i++) {
			$this->clock->advance(60);
			$report = $this->background();
			$refreshes += count($report['jobs']);
		}

		self::assertSame(['waiting' => 1], $report['detected']);
		self::assertSame(0, $refreshes);
		self::assertSame($refreshedAt, $this->settings($context)->refreshedAt);

		// Koniec importu → jedno przeliczenie obejmujące wszystkie źródła.
		$db->execute("UPDATE `{$db->table('sync_runs')}` SET status = 'success' WHERE id = %d", [$runId]);
		$this->clock->advance(60);
		$report = $this->background();
		self::assertSame([$context->projectId() => 'refreshed'], $report['jobs']);

		foreach (['pozycjonowanie sklepu', 'pozycjonowanie lokalne', 'pozycjonowanie bloga', 'agencja seo warszawa'] as $keyword) {
			self::assertNotNull($this->candidateRow($context, $keyword), $keyword);
		}

		for ($i = 0; $i < 3; $i++) {
			$this->clock->advance(60);
			self::assertSame([], $this->background()['jobs']);
		}

		// Długi import (stale zmieniające się dane) — przeliczenie najpóźniej po MAX_DEFER.
		$db->execute("UPDATE `{$db->table('sync_runs')}` SET status = 'queued' WHERE id = %d", [$runId]);
		$refreshes = 0;

		for ($minute = 1; $minute <= 70; $minute++) {
			$this->gscKeyword($context, 'fraza importu ' . $minute, 30, 40.0, null, 0, '2026-01-14');
			$this->clock->advance(60);
			$report = $this->background();
			$refreshes += count(array_filter($report['jobs'], static fn (string $outcome): bool => $outcome === 'refreshed'));
		}

		self::assertSame(1, $refreshes, 'Jedno przeliczenie w ciągu godziny trwającego importu.');
	}

	public function test_strategy_step_runs_after_the_sync_queue_in_the_plugin_container(): void
	{
		$context = $this->scenario();
		$this->strategy->requestRefresh($context);
		$scheduler = osf_seo()->get(SyncScheduler::class);
		self::assertLessThanOrEqual((float) osf_seo()->get(SyncConfig::class)->timeBudget(), $scheduler->remainingBudget());
		$requests = count($this->google->requests);

		// Kroki po kolejce (WP-Cron `osf_seo_sync_tick` i `wp osf-seo sync:run`) — Strategia jako ostatni krok, wspólny limit czasu (D63).
		$scheduler->runFollowUps();
		self::assertNotFalse(get_option(StrategyScheduler::HEARTBEAT_OPTION));
		self::assertSame(StrategyRefreshQueue::STATUS_IDLE, $this->settings($context)->refreshStatus);
		self::assertSame(3, $this->topicCount($context));
		self::assertSame($requests, count($this->google->requests));
	}

	/**
	 * Jeden krok Strategii w tle (WP-Cron) — bez żadnego żądania HTTP (Google, DataForSEO).
	 *
	 * @return array<string, mixed>
	 */
	private function background(): array
	{
		$requests = count($this->google->requests);
		$report = $this->strategyScheduler->runBackground(20.0, true);
		self::assertSame($requests, count($this->google->requests), 'Krok Strategii w tle bez żadnych żądań HTTP.');

		return $report;
	}

	private function scenario(): ProjectContext
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'pozycjonowanie stron', 600, 14.0, self::PAGE);
		$this->gscKeyword($context, 'pozycjonowanie stron www', 300, 16.0, self::PAGE);
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://example.pl/audyt/');
		$this->strategy->addKeywords($context, ['agencja seo łódź']);

		return $context;
	}

	private function settings(ProjectContext $context): StrategySettings
	{
		return $this->strategySettings->get($context->projectId());
	}

	private function phase(ProjectContext $context): string
	{
		return (string) $this->strategy->panelState($context)['job']['phase'];
	}

	private function topicCount(ProjectContext $context): int
	{
		$db = self::db();

		return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('strategy_topics')}` WHERE project_id = %d", [$context->projectId()]);
	}

	/**
	 * @return array<string, list<array<string, string|null>>>
	 */
	private function strategySnapshot(ProjectContext $context): array
	{
		$db = self::db();
		$snapshot = [];

		foreach (['strategy_keywords', 'strategy_topics', 'strategy_topic_events'] as $table) {
			$snapshot[$table] = $db->fetchAll("SELECT * FROM `{$db->table($table)}` WHERE project_id = %d ORDER BY id", [$context->projectId()]);
		}

		return $snapshot;
	}

	/**
	 * @return array<string, string|null>|null
	 */
	private function settingsRow(ProjectContext $context): ?array
	{
		$db = self::db();

		return $db->fetchRow("SELECT * FROM `{$db->table('strategy_settings')}` WHERE project_id = %d", [$context->projectId()]);
	}

	/**
	 * @param array<string, mixed> $values
	 * @return list<string>
	 */
	private static function sortedKeys(array $values): array
	{
		$keys = array_keys($values);
		sort($keys);

		return $keys;
	}
}
