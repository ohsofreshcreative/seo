<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Database\Connection;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Pobieranie stron z panelu w tle (STEP 17, faza D — docs/ARCHITECTURE.md, sekcja 25.5). Żadnego drugiego fetchera: plan to
 * `PageIntelligenceService::plan` (zero HTTP i DNS), a każda pozycja zlecenia jest pobierana przez `PageIntelligenceService::fetch`
 * (ta sama polityka adresów, transport z przypięciem IP, robots.txt, limity hosta, cache i snapshoty co w CLI).
 *
 * - Zlecenie: wyłącznie jawne działanie z uprawnieniem `osf_seo_manage_page_intelligence`, najwyżej MAX_URLS adresów, każda pozycja
 *   dozwolona przez plan (inaczej odmowa całego zlecenia); ten sam wybór w toku → to samo zlecenie (bez duplikatów); najwyżej
 *   MAX_ACTIVE zleceń projektu naraz.
 * - Wykonanie: krok w tle (`SyncScheduler::onAfterRun`), pozycja po pozycji w limicie czasu kroku; odstęp hosta, zajęty host albo strona →
 *   pozycja wraca do kolejki z terminem (`run_after`), najwyżej MAX_ITEM_ATTEMPTS prób; `Retry-After`, limit dzienny, robots.txt i błędy
 *   strony to wynik pozycji (bez automatycznych ponowień). Nigdy przy renderowaniu panelu ani po przeliczeniu Strategii.
 */
final class PageJobService
{
	/** Najwięcej adresów w jednym zleceniu z panelu (niezależnie od wyższego limitu CLI). */
	public const MAX_URLS = 5;

	/** Najwięcej aktywnych zleceń projektu naraz. */
	public const MAX_ACTIVE = 3;

	/** Najwięcej prób jednej pozycji przy odstępie hosta / zajętym hoście. */
	public const MAX_ITEM_ATTEMPTS = 5;

	/** Najwięcej przejęć zlecenia (odstępy, przerwane procesy) — dalej `failed`. */
	public const MAX_JOB_ATTEMPTS = 20;

	/** Brak znaku życia przebiegu dłużej niż tyle sekund — przerwany proces (zlecenie wraca do kolejki). */
	public const STALE_SECONDS = 300;

	/** Odmowy przed żądaniem, po których pozycja wraca do kolejki (stan chwilowy, nie wynik strony). */
	public const RETRYABLE = ['domain_cooldown', 'host_busy', 'fetch_in_progress'];

	public const PURGE_TRANSIENT = 'osf_seo_page_jobs_purge';

	/** Zlecenie nieodebrane przez krok w tle dłużej niż tyle sekund → `failed` (`job_expired`) — kolejka nie zostaje zablokowana. */
	public const QUEUE_TTL = 86400;

	/** Minimalny czas na kolejną pozycję w kroku (sekundy) poza limitem czasu pobrania. */
	private const ITEM_MARGIN = 3;

	public function __construct(
		private readonly PageIntelligenceService $pages,
		private readonly PageJobRepository $jobs,
		private readonly PageIntelligenceConfig $config,
		private readonly StrategyService $strategy,
		private readonly ProjectGuard $guard,
		private readonly Connection $db,
		private readonly Logger $logger,
	) {
	}

	/** Limit adresów zlecenia z panelu. */
	public function maxUrls(): int
	{
		return min(self::MAX_URLS, $this->config->maxUrls());
	}

	/**
	 * Plan zlecenia — bez HTTP i DNS (plan Page Intelligence + limit panelu i powód odmowy całego zlecenia).
	 *
	 * @return array{items: list<array<string, mixed>>, fetches: int, max_urls: int, refused: ?string}
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 */
	public function plan(ProjectContext $context, PageSelection $selection, bool $force = false): array
	{
		$plan = $this->pages->plan($context, $selection, $force);
		$plan['max_urls'] = $this->maxUrls();
		$plan['refused'] ??= self::refusal($plan['items'], $this->maxUrls());

		return $plan;
	}

	/**
	 * Zakolejkowanie zlecenia (bez żadnego żądania). Ten sam wybór w toku → istniejące zlecenie (`existing`).
	 *
	 * @return array{job: PageJob, existing: bool}
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws PageRefused
	 */
	public function queue(ProjectContext $context, PageSelection $selection, bool $force = false, ?string $topic = null): array
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$plan = $this->plan($context, $selection, $force);

		if ($plan['refused'] !== null) {
			throw new PageRefused($plan['refused']);
		}

		$items = [];

		foreach (self::selections($selection) as $index => $single) {
			$planned = $plan['items'][$index] ?? [];
			$items[] = [
				'selection' => $single,
				'url' => $planned['url'] ?? null,
				'host' => $planned['host'] ?? null,
				'kind' => $planned['kind'] ?? null,
				'source' => $planned['source'] ?? null,
				'state' => PageJob::ITEM_PENDING,
				'outcome' => null,
				'error' => null,
				'http_status' => null,
				'page' => $planned['page'] ?? null,
				'snapshot' => $planned['snapshot'] ?? null,
				'attempts' => 0,
				'retry_in' => null,
				'finished_at' => null,
			];
		}

		$topicId = $topic === null || $topic === '' ? null : $this->strategy->topic($context, $topic)['topic']->id;
		$topicId ??= isset($plan['items'][0]['topic_id']) && is_int($plan['items'][0]['topic_id']) ? $plan['items'][0]['topic_id'] : null;
		$key = hash('sha256', $context->projectId() . '|' . ($force ? 1 : 0) . '|' . json_encode(array_column($items, 'selection')));
		$lock = 'page_job_queue_' . $context->projectId();

		if (! $this->db->acquireLock($lock, 5)) {
			throw new PageRefused('queue_busy');
		}

		try {
			$existing = $this->jobs->activeByKey($context->projectId(), $key);

			if ($existing !== null) {
				return ['job' => $existing, 'existing' => true];
			}

			if ($this->jobs->activeCount($context->projectId()) >= self::MAX_ACTIVE) {
				throw new PageRefused('too_many_jobs');
			}

			$job = $this->jobs->create($context->projectId(), $topicId, $force, $items, $key, $context->userId() > 0 ? $context->userId() : null);
		} finally {
			$this->db->releaseLock($lock);
		}

		$this->logger->info('Page job {job} queued for project {project}: {count} URL(s).', ['job' => $job->publicId, 'project' => $context->publicId(), 'count' => count($items)]);

		return ['job' => $job, 'existing' => false];
	}

	/**
	 * Zlecenie projektu (status dla panelu) — wyłącznie w obrębie projektu.
	 *
	 * @throws AccessDenied
	 * @throws PageNotFound
	 */
	public function job(ProjectContext $context, string $jobId): PageJob
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);

		return $this->jobs->find($context->projectId(), $jobId) ?? throw new PageNotFound();
	}

	/**
	 * Ostatnie zlecenia projektu (opcjonalnie tematu).
	 *
	 * @return list<PageJob>
	 *
	 * @throws AccessDenied
	 */
	public function recent(ProjectContext $context, ?int $topicId = null, int $limit = 5): array
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);

		return $this->jobs->recent($context->projectId(), $topicId, $limit);
	}

	/**
	 * Liczniki statusów zleceń (ustawienia administratora, diagnostyka CLI) — bez treści zleceń.
	 *
	 * @return array<string, int>
	 */
	public function statusCounts(?int $projectId = null): array
	{
		return $this->jobs->statusCounts($projectId);
	}

	/**
	 * Anulowanie zlecenia czekającego w kolejce (bez żadnego żądania). Zlecenie w trakcie przebiegu — odmowa (`job_running`); pozycje już
	 * pobrane zostają zapisane.
	 *
	 * @throws AccessDenied
	 * @throws PageNotFound
	 * @throws PageRefused
	 */
	public function cancel(ProjectContext $context, string $jobId): PageJob
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$job = $this->jobs->find($context->projectId(), $jobId) ?? throw new PageNotFound();

		if ($job->status !== PageJob::STATUS_QUEUED) {
			throw new PageRefused($job->status === PageJob::STATUS_RUNNING ? 'job_running' : 'job_finished');
		}

		if (! $this->jobs->finish($job, PageJob::STATUS_CANCELLED, null, 'cancelled', PageJob::STATUS_QUEUED)) {
			throw new PageRefused('job_running');
		}

		$this->logger->info('Page job {job} cancelled.', ['job' => $job->publicId]);

		return $this->jobs->find($context->projectId(), $jobId) ?? $job;
	}

	/**
	 * Krok w tle: odzyskanie przerwanych przebiegów i wykonanie zleceń w limicie czasu (co najmniej jedna pozycja na wywołanie, jeśli
	 * jakieś zlecenie czeka). Wyłącznie proces systemowy (WP-Cron, WP-CLI).
	 *
	 * @return array{recovered: int, jobs: int, items: int}
	 */
	public function runBackground(float $budget): array
	{
		if (! ProjectGuard::isSystemProcess()) {
			return ['recovered' => 0, 'jobs' => 0, 'items' => 0];
		}

		$recovered = $this->recoverStale() + $this->expireQueued();

		if (get_transient(self::PURGE_TRANSIENT) === false) {
			set_transient(self::PURGE_TRANSIENT, '1', DAY_IN_SECONDS);
			$this->jobs->purgeBefore(gmdate('Y-m-d H:i:s', (int) strtotime($this->jobs->now() . ' UTC') - $this->config->retentionDays() * 86400));
		}

		$deadline = microtime(true) + max(0.0, $budget);
		$jobs = 0;
		$items = 0;

		foreach ($this->jobs->due(10) as $job) {
			if ($items > 0 && microtime(true) + $this->itemSeconds() > $deadline) {
				break;
			}

			if (! $this->jobs->claim($job)) {
				continue;
			}

			$jobs++;
			$items += $this->process($job, $deadline, $items === 0);
		}

		return ['recovered' => $recovered, 'jobs' => $jobs, 'items' => $items];
	}

	/** Zlecenia czekające dłużej niż QUEUE_TTL (np. po długiej przerwie crona) → `failed` (`job_expired`); pobrane pozycje zostają. */
	public function expireQueued(): int
	{
		$expired = 0;

		foreach ($this->jobs->queuedBefore(gmdate('Y-m-d H:i:s', (int) strtotime($this->jobs->now() . ' UTC') - self::QUEUE_TTL)) as $job) {
			if ($this->jobs->finish($job, PageJob::STATUS_FAILED, null, 'job_expired', PageJob::STATUS_QUEUED)) {
				$expired++;
				$this->logger->warning('Page job {job} expired in the queue.', ['job' => $job->publicId]);
			}
		}

		return $expired;
	}

	/** Przebiegi bez znaku życia (przerwany proces) wracają do kolejki; po MAX_JOB_ATTEMPTS — `failed`. */
	public function recoverStale(): int
	{
		$before = gmdate('Y-m-d H:i:s', (int) strtotime($this->jobs->now() . ' UTC') - self::STALE_SECONDS);
		$recovered = 0;

		foreach ($this->jobs->staleRunning($before) as $job) {
			if ($job->attempts >= self::MAX_JOB_ATTEMPTS) {
				$this->jobs->finish($job, PageJob::STATUS_FAILED, null, 'interrupted');
			} else {
				// Pozycja w toku mogła się zakończyć albo nie — ponowna próba jest bezpieczna (bez kosztów; świeży snapshot = pamięć).
				$this->jobs->release($job, $job->items, $this->jobs->now());
			}

			$recovered++;
			$this->logger->warning('Page job {job} recovered after an interrupted run.', ['job' => $job->publicId]);
		}

		return $recovered;
	}

	/**
	 * Przebieg zlecenia: pozycje po kolei, postęp po każdej próbie; odstęp hosta → termin kolejnej próby.
	 */
	private function process(PageJob $job, float $deadline, bool $first): int
	{
		try {
			$context = $this->guard->authorizeSystem($job->projectPublicId);
		} catch (ProjectNotFound | AccessDenied) {
			$this->jobs->finish($job, PageJob::STATUS_FAILED, null, 'project_unavailable');

			return 0;
		}

		if ($context->project()->isArchived()) {
			$this->jobs->finish($job, PageJob::STATUS_FAILED, null, 'project_unavailable');

			return 0;
		}

		$items = $job->items;
		$processed = 0;
		$retryIn = null;

		foreach ($items as $index => $item) {
			if (($item['state'] ?? null) !== PageJob::ITEM_PENDING) {
				continue;
			}

			if (($processed > 0 || ! $first) && microtime(true) + $this->itemSeconds() > $deadline) {
				break;
			}

			$result = $this->fetchItem($context, $item, $job->force);
			$processed++;
			$attempts = (int) ($item['attempts'] ?? 0) + 1;
			$retryable = ($result['outcome'] ?? null) === 'refused' && in_array($result['error'] ?? null, self::RETRYABLE, true);
			$items[$index] = [
				'state' => $retryable && $attempts < self::MAX_ITEM_ATTEMPTS ? PageJob::ITEM_PENDING : PageJob::ITEM_DONE,
				'outcome' => $result['outcome'] ?? null,
				'error' => $result['error'] ?? null,
				'http_status' => $result['http_status'] ?? null,
				'page' => $result['page'] ?? ($item['page'] ?? null),
				'snapshot' => $result['snapshot'] ?? ($item['snapshot'] ?? null),
				'url' => $result['url'] ?? ($item['url'] ?? null),
				'attempts' => $attempts,
				'retry_in' => $retryable ? max(1, (int) ($result['retry_in'] ?? 10)) : null,
				'finished_at' => $retryable && $attempts < self::MAX_ITEM_ATTEMPTS ? null : $this->jobs->now(),
			] + $item;

			if ($items[$index]['state'] === PageJob::ITEM_PENDING) {
				$retryIn = min($retryIn ?? PHP_INT_MAX, (int) $items[$index]['retry_in']);
			}

			$this->jobs->progress($job, $items);
		}

		$pending = array_filter($items, static fn (array $item): bool => ($item['state'] ?? null) === PageJob::ITEM_PENDING);

		if ($pending !== []) {
			$this->jobs->release($job, $items, gmdate('Y-m-d H:i:s', (int) strtotime($this->jobs->now() . ' UTC') + max(1, $retryIn ?? 0)));

			return $processed;
		}

		$this->jobs->finish($job, PageJob::STATUS_COMPLETED, $items, null);
		$this->logger->info('Page job {job} completed: {count} URL(s).', ['job' => $job->publicId, 'count' => count($items)]);

		return $processed;
	}

	/**
	 * Jedna pozycja przez `PageIntelligenceService::fetch` (bez czekania na odstęp hosta — zamiast tego termin kolejnej próby). Wybór
	 * tematu albo pozycji SERP rozwiązywany teraz musi wskazywać dokładnie zatwierdzony adres (nowszy pomiar SERP albo zmieniona strona
	 * tematu → odmowa `selection_changed`, bez pobierania innej witryny niż potwierdzona).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function fetchItem(ProjectContext $context, array $item, bool $force): array
	{
		$selection = (array) ($item['selection'] ?? []);

		try {
			$single = self::selection($selection);

			if ($single->type !== PageSelection::URLS && is_string($item['url'] ?? null)) {
				$current = $this->pages->plan($context, $single, $force)['items'][0]['url'] ?? null;

				if ($current !== $item['url']) {
					return ['outcome' => 'refused', 'error' => 'selection_changed'];
				}
			}

			return $this->pages->fetch($context, $single, $force, PageIntelligenceService::TRIGGER_PANEL)[0] ?? ['outcome' => 'refused', 'error' => 'nothing_selected'];
		} catch (PageRefused $refused) {
			return ['outcome' => 'refused', 'error' => $refused->reason()];
		} catch (StrategyNotFound) {
			return ['outcome' => 'refused', 'error' => 'topic_not_found'];
		} catch (Throwable $exception) {
			$this->logger->error('Page job item failed unexpectedly: {class}.', ['class' => $exception::class]);

			return ['outcome' => 'failed', 'error' => 'internal_error'];
		}
	}

	/** Czas potrzebny na pozycję: limit czasu pobrania (z robots.txt) + zapas. */
	private function itemSeconds(): float
	{
		return (float) ($this->config->timeout() + self::ITEM_MARGIN);
	}

	/**
	 * Wybór → pojedyncze pozycje (strona tematu, każda pozycja SERP, każdy adres osobno — z zachowaniem powiązań tematu i SERP).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function selections(PageSelection $selection): array
	{
		return match ($selection->type) {
			PageSelection::TOPIC => [['type' => PageSelection::TOPIC, 'topic' => $selection->topic]],
			PageSelection::SERP => array_map(static fn (int $rank): array => ['type' => PageSelection::SERP, 'keyword' => $selection->keyword, 'rank' => $rank], $selection->ranks),
			default => array_map(static fn (string $url): array => ['type' => PageSelection::URLS, 'url' => $url], $selection->urls),
		};
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function selection(array $item): PageSelection
	{
		return match ($item['type'] ?? null) {
			PageSelection::TOPIC => PageSelection::topic((string) ($item['topic'] ?? '')),
			PageSelection::SERP => PageSelection::serp((string) ($item['keyword'] ?? ''), [(int) ($item['rank'] ?? 0)]),
			default => PageSelection::urls([(string) ($item['url'] ?? '')]),
		};
	}

	/**
	 * Odmowa całego zlecenia z panelu: limit adresów panelu albo niedozwolona pozycja (zakres, polityka adresów, wyłączony rodzaj).
	 *
	 * @param list<array<string, mixed>> $items
	 */
	private static function refusal(array $items, int $max): ?string
	{
		if ($items === []) {
			return 'nothing_selected';
		}

		if (count($items) > $max) {
			return 'too_many_urls';
		}

		foreach ($items as $item) {
			if (($item['allowed'] ?? false) !== true) {
				return (string) ($item['reason'] ?? 'url_not_allowed');
			}
		}

		return null;
	}
}
