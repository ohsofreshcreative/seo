<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\SerpKeywordRules;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;
use OsfSeo\Serp\SerpDictionary;
use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Serp\SerpFreshness;
use OsfSeo\Strategy\Serp\SerpIntelligence;
use OsfSeo\Strategy\Serp\SerpOverlap;
use OsfSeo\Strategy\Topics\TopicContextBuilder;
use OsfSeo\Strategy\Topics\TopicEvent;
use OsfSeo\Strategy\Topics\TopicEventRepository;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicRepository;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Ulid;
use OsfSeo\Support\ValidationException;

/**
 * Strategia dla CLI i (od fazy D) panelu — wyłącznie przez ProjectContext (docs/ARCHITECTURE.md, sekcje 15 i 15.14). Żadna metoda nie
 * wysyła żądań do API. Przeliczenie (i jego zlecenie z panelu), wpisy ręczne, status pracy, notatka, ręczna strona docelowa i przypięcia
 * wymagają `osf_seo_manage_strategy`; odczyt — dostępu do projektu (bez tego uprawnienia: bez odrzuconych tematów, notatek wewnętrznych
 * i identyfikatorów użytkowników, D62).
 */
final class StrategyService
{
	/** Najwięcej fraz tematu porównywanych z frazą odniesienia w overlapie SERP szczegółów tematu (kolejność — wolumen). */
	public const TOPIC_OVERLAP_MEMBERS = 30;

	/** Najwięcej tekstów fraz w jednym zapytaniu o tematy (odnośniki z innych modułów). */
	public const LINK_LOOKUP_LIMIT = 500;

	/** Przez tyle sekund po zakończeniu zadania panel pokazuje „Zakończono” (potem „Aktualne dane”). */
	public const DONE_WINDOW = 900;

	/** Brak kroku Strategii w tle przez tyle sekund — administrator widzi ostrzeżenie o niedziałającym cronie. */
	public const WORKER_STALE_AFTER = 600;

	public function __construct(
		private readonly StrategyRefresher $refresher,
		private readonly StrategyKeywordRepository $keywords,
		private readonly StrategySettingsRepository $settings,
		private readonly StrategyConfig $config,
		private readonly KeywordMetricsProvider $provider,
		private readonly MarketMetricsRepository $metrics,
		private readonly SerpIntelligence $intelligence,
		private readonly Clock $clock,
		private readonly Logger $logger,
		private readonly TopicRepository $topics,
		private readonly TopicEventRepository $events,
		private readonly SerpDictionary $dictionary,
		private readonly StrategyFreshness $freshness,
		private readonly StrategyRefreshQueue $queue,
		private readonly StrategyRefreshRunner $runner,
	) {
	}

	public function config(): StrategyConfig
	{
		return $this->config;
	}

	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	/**
	 * Stan Strategii projektu: limity, rynek, ostatnie przeliczenie, aktualność klucza danych, okno GSC, liczby kandydatów.
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context): array
	{
		$settings = $this->settings->get($context->projectId());
		$scope = $this->refresher->scope($context->projectId(), true);
		$key = $scope === null ? null : $this->refresher->dataKey($scope);

		return [
			'market' => $scope?->market->label(),
			'config' => $this->config->effective(),
			'revision' => $settings->revision,
			'refreshed_at' => $settings->refreshedAt,
			'refresh_ms' => $settings->refreshMs,
			'up_to_date' => $key !== null && $settings->dataKey === $key,
			'gsc' => $scope === null ? null : $this->refresher->gscState($scope),
			'counts' => $this->keywords->counts($context->projectId()),
			'topics' => $this->topics->counts($context->projectId()),
			'last_refresh' => $settings->stats,
			'queue' => [
				'status' => $settings->refreshStatus,
				'source' => $settings->refreshSource,
				'requested_at' => $settings->refreshRequestedAt,
				'due_at' => $settings->refreshDueAt,
				'started_at' => $settings->refreshStartedAt,
				'finished_at' => $settings->refreshFinishedAt,
				'attempts' => $settings->refreshAttempts,
				'error' => $settings->refreshError,
				'error_at' => $settings->refreshErrorAt,
				'dirty_since' => $settings->refreshDirtySince,
				'running' => $this->refresher->running($context->projectId()),
			],
		];
	}

	/**
	 * Podgląd kandydatów ze wszystkich źródeł bez żadnego zapisu.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(ProjectContext $context, int $sample = 20): array
	{
		return $this->refresher->preview($context->projectId(), $sample);
	}

	/**
	 * Przeliczenie kandydatów, faktów i dowodów (bez API; tylko po zmianie klucza danych albo z `$force`).
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(ProjectContext $context, bool $force = false): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		// Ta sama blokada i ten sam zapis stanu zadania co krok w tle (źródło `cli`) — oczekujące zlecenie sprzed startu zostaje wykonane.
		$report = $this->runner->runNow($context->projectId(), $force);

		if ($report['skipped'] === null) {
			$this->logger->info('Strategy candidates of project {project} refreshed: {inserted} new, {updated} updated, {deactivated} deactivated; {topics} topics in {ms} ms.', [
				'project' => $context->publicId(),
				'inserted' => $report['inserted'],
				'updated' => $report['updated'],
				'deactivated' => $report['deactivated'],
				'topics' => $report['topics']['topics'] ?? 0,
				'ms' => $report['duration_ms'],
			]);
		}

		return $report;
	}

	/**
	 * @return array{rows: list<CandidateRow>, total: int}
	 */
	public function candidates(ProjectContext $context, CandidateFilters $filters): array
	{
		return $this->keywords->list($context->projectId(), $filters);
	}

	/**
	 * Kandydat z faktami i dowodami — po ULID albo tekście frazy (klucz rynkowy na rynku projektu).
	 *
	 * @throws StrategyNotFound
	 */
	public function keyword(ProjectContext $context, string $value): CandidateRow
	{
		$value = trim($value);

		if (Ulid::normalize($value) !== null) {
			return $this->keywords->find($context->projectId(), $value) ?? throw new StrategyNotFound();
		}

		$market = $this->market($context) ?? throw new StrategyNotFound();

		return $this->keywords->findByKey($context->projectId(), $market, bin2hex(MarketKeyword::key($value))) ?? throw new StrategyNotFound();
	}

	/**
	 * Wpisy ręczne — bez żadnego żądania do API i bez wzbogacania (fakty uzupełni przeliczenie). Podbija rewizję (klucz danych).
	 *
	 * @param list<string>|string $input
	 * @return array{added: int, existing: int, rejected: list<array{keyword: string, reason: string}>}
	 *
	 * @throws ValidationException
	 */
	public function addKeywords(ProjectContext $context, array|string $input): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$market = $this->market($context) ?? throw new ValidationException(['keywords' => 'Rynek projektu nie jest obsługiwany.']);
		$texts = is_array($input) ? $input : (preg_split('/[\r\n,;]+/u', $input) ?: []);
		$keywords = [];
		$rejected = [];

		foreach ($texts as $raw) {
			if (! is_string($raw) || trim($raw) === '') {
				continue;
			}

			$keyword = MarketKeyword::normalize($raw);
			$reason = SerpKeywordRules::rejection($keyword);

			if ($reason !== null) {
				$rejected[] = ['keyword' => mb_substr($keyword, 0, 200), 'reason' => $reason];

				continue;
			}

			$keywords[bin2hex(MarketKeyword::key($keyword))] = $keyword;
		}

		if ($keywords === []) {
			if ($rejected === []) {
				throw new ValidationException(['keywords' => 'Podaj co najmniej jedną frazę.']);
			}

			return ['added' => 0, 'existing' => 0, 'rejected' => $rejected];
		}

		$ids = array_values($this->metrics->ensure($market, array_map('strval', array_values($keywords))));
		$result = $this->keywords->addManual($context->projectId(), array_map('intval', $ids), $context->userId(), $this->now());

		if ($result['added'] > 0) {
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return $result + ['rejected' => $rejected];
	}

	/**
	 * Zdjęcie wpisów ręcznych (ULID kandydatów albo teksty fraz). Podbija rewizję (klucz danych).
	 *
	 * @param list<string> $values
	 */
	public function removeKeywords(ProjectContext $context, array $values): int
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$publicIds = [];

		foreach ($values as $value) {
			try {
				$publicIds[] = $this->keyword($context, (string) $value)->publicId;
			} catch (StrategyNotFound) {
				continue;
			}
		}

		$removed = $this->keywords->removeManual($context->projectId(), array_values(array_unique($publicIds)), $this->now());

		if ($removed > 0) {
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return $removed;
	}

	/**
	 * SERP Intelligence kandydata (faza B): najnowszy zgodny pomiar w kontekście projektu, świeżość, profil, TOP20 z kształtami wyników.
	 * Bez żadnego żądania; null — kandydat bez zgodnego pomiaru (kwalifikuje się do analizy).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws StrategyNotFound
	 */
	public function serp(ProjectContext $context, string $value): ?array
	{
		$row = $this->keyword($context, $value);
		$market = $this->market($context) ?? throw new StrategyNotFound();
		$project = $context->project();

		return $this->intelligence->detail($context->projectId(), $market, $row->marketKeywordId, DomainFamily::normalize($project->domain) ?? $project->domain);
	}

	/**
	 * Overlap SERP dwóch kandydatów projektu (najnowsze zgodne pomiary; bez żadnego żądania).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws StrategyNotFound
	 */
	public function serpOverlap(ProjectContext $context, string $first, string $second): array
	{
		$a = $this->keyword($context, $first);
		$b = $this->keyword($context, $second);
		$market = $this->market($context) ?? throw new StrategyNotFound();

		return $this->intelligence->overlap($context->projectId(), $market, $a->marketKeywordId, $b->marketKeywordId);
	}

	/**
	 * Tematy Strategii (backlog). Bez `osf_seo_manage_strategy` (np. klient) — bez odrzuconych tematów i notatek wewnętrznych (D62).
	 *
	 * @return array{rows: list<TopicRow>, total: int}
	 */
	public function topics(ProjectContext $context, TopicFilters $filters): array
	{
		return $this->topics->list($context->projectId(), $filters, ! $context->can(Capabilities::MANAGE_STRATEGY));
	}

	/**
	 * Temat (po ULID tematu albo frazie / ULID kandydata) z frazami i ostatnimi zdarzeniami.
	 *
	 * @return array{topic: TopicRow, members: list<array<string, mixed>>, events: list<array<string, mixed>>}
	 *
	 * @throws StrategyNotFound
	 */
	public function topic(ProjectContext $context, string $value): array
	{
		$topic = $this->resolveTopic($context, $value);

		return [
			'topic' => $topic,
			'members' => $this->topics->members($context->projectId(), $topic->id),
			'events' => $this->topicEvents($context, $topic),
		];
	}

	/**
	 * Deterministyczny pakiet kontekstu tematu (pod STEP 17 — bez wywołań AI).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws StrategyNotFound
	 */
	public function context(ProjectContext $context, string $value): array
	{
		$topic = $this->resolveTopic($context, $value);
		$members = $this->topics->members($context->projectId(), $topic->id);
		$evidence = $this->keywords->evidence($context->projectId(), array_map(static fn (array $member): string => (string) $member['id'], $members));

		return (new TopicContextBuilder())->build($topic, $members, $evidence, $context->can(Capabilities::MANAGE_STRATEGY));
	}

	/**
	 * Zmiana statusu pracy (tylko decyzja użytkownika; przeliczenie nigdy jej nie zmienia). Zapisuje podstawę decyzji i — przy pierwszym
	 * przejściu do realizacji albo zrealizowania — punkt odniesienia do późniejszej oceny efektu.
	 *
	 * @throws StrategyNotFound
	 */
	public function setStatus(ProjectContext $context, string $value, TopicStatus $status, ?string $note = null): TopicRow
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$topic = $this->resolveTopic($context, $value);
		$now = $this->now();
		$analysis = $topic->analysis ?? [];
		$basis = [
			'at' => $now,
			'action' => $topic->action,
			'action_reason' => $topic->actionReason,
			'target_state' => $topic->targetState,
			'target_url' => $topic->targetUrl,
			'priority' => $topic->priority,
			'confidence_level' => $topic->confidenceLevel,
			'evidence_hash' => $topic->evidenceHash,
		];
		$baseline = $status->capturesBaseline() ? [
			'captured_at' => $now,
			'status' => $status->value,
			'action' => $topic->action,
			'priority' => $topic->priority,
			'keywords' => $topic->keywordsCount,
			'demand' => $topic->demand,
			'gsc' => $analysis['facts']['gsc'] ?? null,
			'serp' => $analysis['serp'] ?? null,
			'target' => ['state' => $topic->targetState, 'url' => $topic->targetUrl],
		] : null;
		$this->topics->setStatus($context->projectId(), $topic->id, $status, $note, $context->userId(), $now, $basis, $baseline);

		if ($status->value !== $topic->status) {
			$this->events->add($context->projectId(), [['topic_id' => $topic->id, 'type' => TopicEvent::STATUS_CHANGED, 'from' => $topic->status, 'to' => $status->value, 'user' => $context->userId()]], $now);
		}

		return $this->topics->find($context->projectId(), $topic->publicId) ?? throw new StrategyNotFound();
	}

	/**
	 * Ręczna strona docelowa tematu (adres w domenie projektu), ręczne potwierdzenie braku strony (`$noPage`) albo usunięcie wskazania
	 * (oba puste). Podbija rewizję — przeliczenie uwzględni zmianę.
	 *
	 * @throws StrategyNotFound
	 * @throws ValidationException
	 */
	public function setTarget(ProjectContext $context, string $value, ?string $url, bool $noPage = false): TopicRow
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$topic = $this->resolveTopic($context, $value);
		$urlId = null;

		if ($url !== null && $url !== '') {
			if ($noPage) {
				throw new ValidationException(['url' => 'Podaj adres strony albo potwierdź brak strony — nie oba naraz.']);
			}

			$normalized = DataForSeoSerpProvider::url(trim($url));
			$host = $normalized === null ? null : DomainFamily::fromUrl($normalized);
			$project = DomainFamily::normalize($context->project()->domain);

			if ($normalized === null || $host === null || $project === null || ! DomainFamily::matches($host, $project)) {
				throw new ValidationException(['url' => 'Adres musi być stroną w domenie projektu (http/https).']);
			}

			$domains = $this->dictionary->domainIds([$host]);
			$urlId = $this->dictionary->urlIds([md5($normalized) => ['url' => $normalized, 'domain_id' => $domains[$host]]])[md5($normalized)] ?? null;
		}

		$now = $this->now();
		$this->topics->setManualTarget($context->projectId(), $topic->id, $urlId, $noPage, $context->userId(), $now);
		$this->events->add($context->projectId(), [[
			'topic_id' => $topic->id,
			'type' => TopicEvent::MANUAL_TARGET,
			'to' => $urlId !== null ? 'url' : ($noPage ? 'no_page' : 'cleared'),
			'data' => $urlId !== null ? ['url' => DataForSeoSerpProvider::url(trim((string) $url))] : null,
			'user' => $context->userId(),
		]], $now);
		$this->settings->bumpRevision($context->projectId(), $context->userId());

		return $this->topics->find($context->projectId(), $topic->publicId) ?? throw new StrategyNotFound();
	}

	/**
	 * Ręczne przypięcie fraz (kandydatów projektu) do tematu albo do nowego tematu (`$topic` null) — pierwszeństwo przed grupowaniem
	 * automatycznym. Podbija rewizję — przeliczenie przegrupuje tematy.
	 *
	 * @param list<string> $values ULID kandydatów albo teksty fraz
	 * @return array{topic: string, pinned: int, missing: list<string>}
	 *
	 * @throws StrategyNotFound
	 * @throws ValidationException
	 */
	public function pin(ProjectContext $context, array $values, ?string $topic): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$market = $this->market($context) ?? throw new ValidationException(['keywords' => 'Rynek projektu nie jest obsługiwany.']);
		$resolved = $this->keywords->resolve($context->projectId(), $market, $values);
		$found = array_values(array_filter($resolved));

		if ($found === []) {
			throw new ValidationException(['keywords' => 'Podaj co najmniej jednego kandydata Strategii (frazę albo ID).']);
		}

		$now = $this->now();

		if ($topic === null) {
			['id' => $topicId, 'public_id' => $target] = $this->topics->createPending($context->projectId(), $found[0]['keyword'], $now);
		} else {
			$row = $this->resolveTopic($context, $topic);
			$topicId = $row->id;
			$target = $row->publicId;
		}

		$pinned = $this->topics->pin($context->projectId(), array_map(static fn (array $candidate): int => $candidate['id'], $found), $topicId, $context->userId(), $now);

		if ($pinned > 0) {
			$this->events->add($context->projectId(), [[
				'topic_id' => $topicId,
				'type' => TopicEvent::PINNED,
				'data' => ['keywords' => array_values(array_unique(array_map(static fn (array $candidate): string => $candidate['keyword'], $found)))],
				'user' => $context->userId(),
			]], $now);
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return ['topic' => $target, 'pinned' => $pinned, 'missing' => array_values(array_map('strval', array_keys(array_filter($resolved, static fn (?array $candidate): bool => $candidate === null))))];
	}

	/**
	 * Odpięcie fraz — wracają do grupowania automatycznego przy najbliższym przeliczeniu.
	 *
	 * @param list<string> $values
	 */
	public function unpin(ProjectContext $context, array $values): int
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$market = $this->market($context) ?? throw new ValidationException(['keywords' => 'Rynek projektu nie jest obsługiwany.']);
		$found = array_values(array_filter($this->keywords->resolve($context->projectId(), $market, $values)));
		$unpinned = $this->topics->pin($context->projectId(), array_map(static fn (array $candidate): int => $candidate['id'], $found), null, $context->userId(), $this->now());

		if ($unpinned > 0) {
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return $unpinned;
	}

	/**
	 * Stan Strategii dla panelu (faza D) — tanie odczyty zapisanego stanu: rynek, ostatnie przeliczenie, aktualność klucza danych,
	 * przeliczenie w toku (blokada projektu), ręczne zlecenie przeliczenia, liczby kandydatów i statystyki ostatniego przeliczenia
	 * (limit fraz). Bez przeliczania i bez żadnego żądania.
	 *
	 * @return array<string, mixed>
	 */
	public function panelState(ProjectContext $context): array
	{
		$projectId = $context->projectId();
		$settings = $this->settings->get($projectId);
		$scope = $this->refresher->scope($projectId, true);
		$key = $scope === null ? null : $this->refresher->dataKey($scope);

		$upToDate = $key !== null && $settings->dataKey === $key;
		$running = $this->refresher->running($projectId);
		$manage = $context->can(Capabilities::MANAGE_STRATEGY);

		return [
			'supported' => $scope !== null,
			'market' => $scope?->market->label(),
			'gsc_window' => $scope?->window,
			'refreshed_at' => $settings->refreshedAt,
			'refresh_ms' => $settings->refreshMs,
			'up_to_date' => $upToDate,
			'running' => $running,
			'requested_at' => $settings->refreshRequestedAt,
			'requested_by' => $manage ? $settings->refreshRequestedBy : null,
			'candidates' => $this->keywords->counts($projectId),
			'last_refresh' => [
				'selected' => $settings->stats['selected'] ?? null,
				'limit' => $settings->stats['limit'] ?? null,
				'overflow' => $settings->stats['overflow'] ?? null,
				'topics' => is_array($settings->stats['topics'] ?? null) ? array_intersect_key($settings->stats['topics'], array_flip(['topics', 'inserted', 'updated', 'deactivated', 'events'])) : null,
			],
			'job' => $this->jobState($settings, $scope !== null, $upToDate, $running, $manage),
		];
	}

	/**
	 * Stan przeliczenia dla panelu (endpoint statusu i baner): faza `current` (Aktualne dane), `pending` (zmiana danych czeka na krok
	 * w tle), `queued` (zlecone), `retrying` (ponowienie po błędzie), `running` (Przeliczanie), `done` (Zakończono — niedawno), `failed`
	 * (Błąd przeliczenia), `unsupported`. Bez uprawnień zarządzania: bez kodu błędu, źródła zlecenia i stanu zadań w tle.
	 *
	 * @return array<string, mixed>
	 */
	private function jobState(StrategySettings $settings, bool $supported, bool $upToDate, bool $running, bool $manage): array
	{
		$now = $this->clock->now()->getTimestamp();
		$status = $settings->refreshStatus;
		$finishedAgo = $settings->refreshFinishedAt === null ? null : $now - (int) strtotime($settings->refreshFinishedAt . ' UTC');
		$phase = match (true) {
			! $supported => 'unsupported',
			$running || $status === StrategyRefreshQueue::STATUS_RUNNING => 'running',
			$status === StrategyRefreshQueue::STATUS_QUEUED => $settings->refreshAttempts > 0 ? 'retrying' : 'queued',
			$status === StrategyRefreshQueue::STATUS_FAILED => 'failed',
			! $upToDate => 'pending',
			$finishedAgo !== null && $finishedAgo <= self::DONE_WINDOW => 'done',
			default => 'current',
		};
		$heartbeat = $manage ? (get_option(StrategyScheduler::HEARTBEAT_OPTION, null) ?: null) : null;

		return [
			'phase' => $phase,
			'active' => in_array($phase, ['queued', 'retrying', 'running'], true),
			'source' => $manage ? $settings->refreshSource : null,
			'due_at' => in_array($phase, ['queued', 'retrying'], true) ? $settings->refreshDueAt : null,
			'started_at' => $phase === 'running' ? $settings->refreshStartedAt : null,
			'finished_at' => $settings->refreshFinishedAt,
			'attempts' => $settings->refreshAttempts,
			'max_attempts' => StrategyRefreshQueue::MAX_ATTEMPTS,
			'error' => $manage && in_array($phase, ['retrying', 'failed'], true) ? $settings->refreshError : null,
			'error_at' => $manage && in_array($phase, ['retrying', 'failed'], true) ? $settings->refreshErrorAt : null,
			'dirty_since' => $phase === 'pending' ? $settings->refreshDirtySince : null,
			// Diagnostyka administratora (ustawienia Strategii): stan zadania, ostatni błąd (kod), czasy faz ostatniego przeliczenia.
			'status' => $manage ? $status : null,
			'last_error' => $manage ? $settings->refreshError : null,
			'last_error_at' => $manage ? $settings->refreshErrorAt : null,
			'timings' => $manage && is_array($settings->stats['timings'] ?? null) ? $settings->stats['timings'] : null,
			'worker_heartbeat' => $heartbeat,
			// Zadania w tle nie działają (brak crona) — ostrzeżenie dla administratora, gdy coś czeka na przeliczenie.
			'worker_stale' => $manage && in_array($phase, ['pending', 'queued', 'retrying'], true)
				&& ($heartbeat === null || $now - (int) strtotime($heartbeat . ' UTC') > self::WORKER_STALE_AFTER),
		];
	}

	/**
	 * Przegląd Strategii (panel): stan, liczniki tematów (jedno zapytanie grupujące), tematy o najwyższym priorytecie (otwarte, bez
	 * monitorowania), tematy zmienione po decyzji, najnowsze istotne zdarzenia i aktualność źródeł — bez agregacji danych GSC/SERP
	 * przy renderowaniu.
	 *
	 * @return array{state: array<string, mixed>, counts: array<string, mixed>, top: list<TopicRow>, attention: list<TopicRow>, events: list<array<string, mixed>>, freshness: array<string, mixed>}
	 */
	public function overview(ProjectContext $context, int $limit = 5): array
	{
		$projectId = $context->projectId();
		$restricted = ! $context->can(Capabilities::MANAGE_STRATEGY);

		return [
			'state' => $this->panelState($context),
			'counts' => $this->topics->overviewCounts($projectId, PriorityModel::HIGH_BAND, $restricted),
			'top' => $this->topics->list($projectId, new TopicFilters(perPage: $limit), $restricted)['rows'],
			'attention' => $this->topics->list($projectId, new TopicFilters(status: 'all', perPage: $limit, includeMonitor: true, changed: true), $restricted)['rows'],
			'events' => $this->events->forProject($projectId, 10, $restricted),
			'freshness' => $this->freshness->forProject($projectId),
		];
	}

	/**
	 * Szczegóły tematu dla panelu: temat, frazy (metryki rynkowe, źródła), dowody fraz, pakiet kontekstu (sekcje „Dlaczego ten temat
	 * jest w Strategii?”), zdarzenia, SERP Intelligence frazy odniesienia z overlapem fraz tematu i aktualność źródeł — bez żadnego żądania
	 * i bez zapisu (profile pomiarów liczone w pamięci). Bez uprawnień zarządzania: bez notatki i identyfikatorów użytkowników (D62).
	 *
	 * @return array{topic: TopicRow, members: list<array<string, mixed>>, evidence: array<string, array<string, mixed>>, context: array<string, mixed>, events: list<array<string, mixed>>, serp: array<string, mixed>, freshness: array<string, mixed>}
	 *
	 * @throws StrategyNotFound
	 */
	public function topicView(ProjectContext $context, string $value): array
	{
		$topic = $this->resolveTopic($context, $value);
		$manage = $context->can(Capabilities::MANAGE_STRATEGY);
		$members = $this->topics->members($context->projectId(), $topic->id);
		$evidence = $this->keywords->evidence($context->projectId(), array_map(static fn (array $member): string => (string) $member['id'], $members));

		if (! $manage) {
			foreach ($evidence as $id => $proof) {
				unset($proof['manual']['added_by']);
				$evidence[$id] = $proof;
			}
		}

		return [
			'topic' => $topic,
			'members' => $members,
			'evidence' => $evidence,
			'context' => (new TopicContextBuilder())->build($topic, $members, $evidence, $manage),
			'events' => $this->topicEvents($context, $topic),
			'serp' => $this->topicSerp($context, $topic, $members),
			'freshness' => $this->freshness->forProject($context->projectId()),
		];
	}

	/**
	 * Notatka wewnętrzna tematu (bez zmiany statusu pracy).
	 *
	 * @throws StrategyNotFound
	 */
	public function setNote(ProjectContext $context, string $value, string $note): TopicRow
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$topic = $this->resolveTopic($context, $value);
		$this->topics->setNote($context->projectId(), $topic->id, trim($note), $this->now());

		return $this->topics->find($context->projectId(), $topic->publicId) ?? throw new StrategyNotFound();
	}

	/**
	 * Ręczne zlecenie przeliczenia z panelu: unieważnia klucz danych i zapisuje zlecenie. Samo przeliczenie wykona krok w tle Strategii
	 * (faza E) albo `wp osf-seo strategy:refresh` — nigdy żądanie WWW (D74). Status pracy tematów nie zmienia się.
	 */
	/**
	 * Zlecenie przeliczenia z panelu — wyłącznie zapis zadania w kolejce (`StrategyRefreshQueue::request`); przeliczenie wykona krok
	 * w tle (WP-Cron albo `wp osf-seo sync:run`) albo CLI — nigdy żądanie WWW i nigdy żądanie do API. Ponowne kliknięcie nie tworzy
	 * kolejnego zadania; kliknięcie w trakcie przeliczenia zleca jedno kolejne po jego zakończeniu.
	 */
	public function requestRefresh(ProjectContext $context): void
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$this->queue->request($context->projectId(), $context->userId());
		$this->logger->info('Strategy refresh of project {project} requested from the panel.', ['project' => $context->publicId()]);
	}

	/**
	 * Lista SERP Intelligence (panel): aktywni kandydaci według stanu zgodnego pomiaru (`fresh`, `stale`, `measured`, `missing`, `all`)
	 * z dowodem `serp.intel` (profil, obecność projektu, konkurenci), tematem, licznikami fraz i tematów oraz kontekstem analizy.
	 * Pozycja SERP projektu tylko ze świeżego pomiaru (świeżość liczona teraz, nie w chwili przeliczenia). Bez żadnego żądania.
	 *
	 * @return array<string, mixed>
	 */
	public function serpKeywords(ProjectContext $context, string $filter = 'all', int $page = 1, int $perPage = 50): array
	{
		$projectId = $context->projectId();
		$restricted = ! $context->can(Capabilities::MANAGE_STRATEGY);
		$market = $this->market($context);
		$now = $this->clock->now();
		$fresh = SerpFreshness::since($now, SerpFreshness::FRESH_DAYS);
		$expired = SerpFreshness::since($now, SerpFreshness::STALE_DAYS);

		if ($market === null) {
			return ['supported' => false, 'rows' => [], 'total' => 0, 'counts' => ['fresh' => 0, 'stale' => 0, 'missing' => 0, 'all' => 0], 'topics' => $this->topics->serpCounts($projectId, $restricted), 'context' => null];
		}

		$page = max(1, $page);
		$list = $this->keywords->serpCandidates($projectId, $market, $filter, $fresh, $expired, ($page - 1) * $perPage, $perPage, $restricted);
		$rows = [];

		foreach ($list['rows'] as $row) {
			$intel = is_array($row['evidence']['serp']['intel'] ?? null) ? $row['evidence']['serp']['intel'] : null;
			$freshness = SerpFreshness::of($row['serp_intel_at'], $now);

			if ($intel !== null && ! SerpFreshness::allowsProjectRank($freshness)) {
				// Pomiar zestarzał się od przeliczenia — bez Pozycji SERP projektu.
				$intel['project'] = null;
			}

			if ($intel !== null && ! SerpFreshness::usableForClassification($freshness)) {
				$intel['profile'] = null;
				$intel['competitors'] = null;
			}

			$rows[] = [
				'id' => (string) $row['public_id'],
				'keyword' => (string) $row['keyword'],
				'volume' => $row['search_volume'] === null ? null : (int) $row['search_volume'],
				'intent' => $row['search_intent'],
				'checked_at' => $row['serp_intel_at'],
				'freshness' => $freshness,
				'tracked' => $row['tracked_keyword_id'] !== null,
				'intel' => $intel,
				'topic' => $row['topic_public_id'] === null ? null : [
					'id' => (string) $row['topic_public_id'],
					'label' => (string) $row['topic_label'],
					'status' => (string) $row['topic_status'],
					'action' => $row['topic_action'],
					'leader' => (int) $row['leader'] === 1,
				],
			];
		}

		return [
			'supported' => true,
			'rows' => $rows,
			'total' => $list['total'],
			'counts' => $this->keywords->serpCounts($projectId, $fresh, $expired, $restricted),
			'topics' => $this->topics->serpCounts($projectId, $restricted),
			'context' => $this->intelligence->analysisContext($projectId, $market),
		];
	}

	/**
	 * Dominujące domeny w TOP10 świeżych pomiarów projektu (panel SERP Intelligence) z oznaczeniem projektu i konkurentów.
	 *
	 * @return array{measurements: int, domains: list<array<string, mixed>>}
	 */
	public function serpDomains(ProjectContext $context, int $limit = 10): array
	{
		$market = $this->market($context);

		if ($market === null) {
			return ['measurements' => 0, 'domains' => []];
		}

		return $this->intelligence->dominantDomains($context->projectId(), $market, DomainFamily::normalize($context->project()->domain), $limit);
	}

	/**
	 * SERP Intelligence kandydata dla panelu: kandydat, szczegóły najnowszego zgodnego pomiaru (TOP20 z tytułami, kształtami, projektem
	 * i konkurentami; bez zapisu profilu) i temat frazy. Klient nie widzi fraz odrzuconych tematów (404).
	 *
	 * @return array{candidate: CandidateRow, detail: ?array<string, mixed>, topic: ?array<string, mixed>}
	 *
	 * @throws StrategyNotFound
	 */
	public function serpDetail(ProjectContext $context, string $value): array
	{
		$row = $this->keyword($context, $value);
		$market = $this->market($context) ?? throw new StrategyNotFound();
		$projectId = $context->projectId();
		$restricted = ! $context->can(Capabilities::MANAGE_STRATEGY);

		if ($restricted && $this->topics->findByKeyword($projectId, $row->id)?->status === TopicStatus::Dismissed->value) {
			throw new StrategyNotFound();
		}

		$project = $context->project();

		return [
			'candidate' => $row,
			'detail' => $this->intelligence->detail($projectId, $market, $row->marketKeywordId, DomainFamily::normalize($project->domain) ?? $project->domain, false),
			'topic' => $this->topics->byMarketKeywords($projectId, [$row->marketKeywordId], $restricted)[$row->marketKeywordId] ?? null,
		];
	}

	/**
	 * Tematy fraz rynkowych (odnośniki z innych modułów): id frazy rynkowej → temat i jego status. Klient nie widzi odrzuconych tematów.
	 *
	 * @param list<int> $marketKeywordIds
	 * @return array<int, array<string, mixed>>
	 */
	public function topicsForMarketKeywords(ProjectContext $context, array $marketKeywordIds): array
	{
		$ids = array_values(array_filter(array_map('intval', $marketKeywordIds), static fn (int $id): bool => $id > 0));

		return $ids === [] ? [] : $this->topics->byMarketKeywords($context->projectId(), $ids, ! $context->can(Capabilities::MANAGE_STRATEGY));
	}

	/**
	 * Tematy fraz po tekście (klucz rynkowy na rynku projektu — np. frazy GSC z dowodów szansy SEO): tekst → temat. Bez zapisu
	 * (frazy spoza Strategii są pomijane).
	 *
	 * @param list<string> $texts
	 * @return array<string, array<string, mixed>>
	 */
	public function topicsForTexts(ProjectContext $context, array $texts): array
	{
		$market = $this->market($context);
		$texts = array_slice(array_values(array_unique(array_filter(array_map(static fn (mixed $text): string => trim((string) $text), $texts), static fn (string $text): bool => $text !== '' && Ulid::normalize($text) === null))), 0, self::LINK_LOOKUP_LIMIT);

		if ($market === null || $texts === []) {
			return [];
		}

		$resolved = array_filter($this->keywords->resolve($context->projectId(), $market, $texts));
		$topics = $this->topicsForMarketKeywords($context, array_values(array_map(static fn (array $candidate): int => $candidate['market_keyword_id'], $resolved)));
		$result = [];

		foreach ($resolved as $text => $candidate) {
			if (isset($topics[$candidate['market_keyword_id']])) {
				$result[(string) $text] = $topics[$candidate['market_keyword_id']];
			}
		}

		return $result;
	}

	/**
	 * Tematy fraz szansy SEO (odnośniki z Szans SEO): frazy z dowodów wykrycia → kandydat Strategii, a powiązanie potwierdza dowód
	 * Strategii `opportunity.direct` (OpportunityKeywordIndex — dane query × page, D54), nigdy samo `opportunities.keyword`. Fraza, dla której
	 * szansa jest tylko kontekstem (ta sama podstrona), nie jest zwracana. Bez zapisu.
	 *
	 * @param list<string> $texts
	 * @return array<string, array<string, mixed>> tekst frazy → temat
	 */
	public function topicsForOpportunity(ProjectContext $context, string $opportunityId, array $texts): array
	{
		$topics = $this->topicsForTexts($context, $texts);

		if ($topics === []) {
			return [];
		}

		$evidence = $this->keywords->evidence($context->projectId(), array_values(array_unique(array_map(static fn (array $topic): string => (string) $topic['keyword'], $topics))));

		return array_filter($topics, static function (array $topic) use ($evidence, $opportunityId): bool {
			foreach ((array) ($evidence[$topic['keyword']]['opportunity']['direct'] ?? []) as $item) {
				if (is_array($item) && ($item['id'] ?? null) === $opportunityId) {
					return true;
				}
			}

			return false;
		});
	}

	/**
	 * Temat po ULID tematu, a jeśli to nie temat — po kandydacie (ULID albo tekst frazy).
	 *
	 * @throws StrategyNotFound
	 */
	private function resolveTopic(ProjectContext $context, string $value): TopicRow
	{
		$restricted = ! $context->can(Capabilities::MANAGE_STRATEGY);
		$value = trim($value);

		if (Ulid::normalize($value) !== null) {
			$topic = $this->topics->find($context->projectId(), $value, $restricted);

			if ($topic !== null) {
				return $topic;
			}
		}

		$candidate = $this->keyword($context, $value);

		return $this->topics->findByKeyword($context->projectId(), $candidate->id, $restricted) ?? throw new StrategyNotFound();
	}

	/**
	 * Zdarzenia tematu — bez identyfikatorów użytkowników dla klienta (D62).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function topicEvents(ProjectContext $context, TopicRow $topic): array
	{
		$events = $this->events->forTopic($context->projectId(), $topic->id);

		if ($context->can(Capabilities::MANAGE_STRATEGY)) {
			return $events;
		}

		return array_map(static fn (array $event): array => ['user' => null] + $event, $events);
	}

	/**
	 * SERP Intelligence tematu: TOP20 frazy odniesienia (fraza pomiaru z analizy tematu, inaczej fraza główna) i overlap frazy odniesienia
	 * z pozostałymi frazami tematu (najnowsze zgodne pomiary ≤ 90 dni; progi overlapu bez zmian, D64). Bez żadnego żądania i bez zapisu.
	 *
	 * @param list<array<string, mixed>> $members
	 * @return array{keyword: ?array{id: string, keyword: string}, detail: ?array<string, mixed>, overlap: list<array<string, mixed>>, compared: int, measured: int}
	 */
	private function topicSerp(ProjectContext $context, TopicRow $topic, array $members): array
	{
		$market = $this->market($context);
		$result = ['keyword' => null, 'detail' => null, 'overlap' => [], 'compared' => 0, 'measured' => 0];

		if ($market === null || $members === []) {
			return $result;
		}

		$byId = [];

		foreach ($members as $member) {
			$byId[(string) $member['id']] = $member;
		}

		$analysis = $topic->analysis ?? [];
		$reference = $byId[(string) ($analysis['serp']['keyword'] ?? '')] ?? $byId[(string) ($analysis['leader'] ?? '')] ?? $members[0];
		$projectId = $context->projectId();
		$project = $context->project();
		$result['keyword'] = ['id' => (string) $reference['id'], 'keyword' => (string) $reference['keyword']];
		$result['detail'] = $this->intelligence->detail($projectId, $market, (int) $reference['market_keyword_id'], DomainFamily::normalize($project->domain) ?? $project->domain, false);
		$others = array_slice(array_values(array_filter($members, static fn (array $member): bool => $member['id'] !== $reference['id'])), 0, self::TOPIC_OVERLAP_MEMBERS);
		$result['compared'] = count($others);

		if ($others === []) {
			return $result;
		}

		$sides = $this->intelligence->sides($projectId, $market, [(int) $reference['market_keyword_id'], ...array_map(static fn (array $member): int => (int) $member['market_keyword_id'], $others)], false);
		$result['measured'] = count($sides['sides']);
		$lead = $sides['sides'][(int) $reference['market_keyword_id']] ?? null;

		foreach ($others as $member) {
			$side = $sides['sides'][(int) $member['market_keyword_id']] ?? null;

			if ($lead === null || $side === null) {
				$result['overlap'][] = ['id' => (string) $member['id'], 'keyword' => (string) $member['keyword'], 'level' => null, 'shared_urls' => null, 'shared_domains' => null, 'reasons' => [$lead === null ? 'reference_unmeasured' : 'no_measurement']];

				continue;
			}

			$overlap = SerpOverlap::compare($lead, $side, $sides['ubiquity']['domains'], $sides['ubiquity']['known']);
			$result['overlap'][] = [
				'id' => (string) $member['id'],
				'keyword' => (string) $member['keyword'],
				'level' => $overlap['level'],
				'shared_urls' => $overlap['counted_urls'],
				'shared_domains' => $overlap['shared_domains'],
				'reasons' => $overlap['reasons'],
			];
		}

		return $result;
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
