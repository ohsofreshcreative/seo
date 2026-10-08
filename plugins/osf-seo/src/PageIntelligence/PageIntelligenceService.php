<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

use OsfSeo\Ai\Context\PageEvidenceSource;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Database\Connection;
use OsfSeo\PageIntelligence\Extract\HtmlExtractor;
use OsfSeo\PageIntelligence\Fetch\CurlPageFetcher;
use OsfSeo\PageIntelligence\Fetch\FetchRequest;
use OsfSeo\PageIntelligence\Fetch\FetchResult;
use OsfSeo\PageIntelligence\Fetch\PageFetcher;
use OsfSeo\PageIntelligence\Fetch\UrlSafetyPolicy;
use OsfSeo\PageIntelligence\Robots\RobotsPolicy;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use Throwable;

/**
 * Page Intelligence (STEP 17, faza B — docs/ARCHITECTURE.md, sekcja 23): bezpieczne pobieranie publicznych stron projektu i wybranych
 * organicznych wyników SERP, ekstrakcja treści i snapshoty w obrębie projektu.
 *
 * - Odczyt (status, lista, strona, snapshot, kontekst AI) — zero HTTP; plan pobrania — zero HTTP i zero DNS.
 * - Pobranie wyłącznie jawnie (`osf_seo_manage_page_intelligence`), najwyżej `maxUrls` adresów, każdy osobno: TTL (świeży → bez HTTP,
 *   chyba że `force`), blokada strony (bez równoległych pobrań tego samego adresu) i hosta, uprzejmość wobec hosta (odstęp, limit dzienny,
 *   `Retry-After` — wspólne dla wszystkich projektów), robots.txt, bezpieczny transport, ekstrakcja, snapshot tylko przy zmianie treści.
 *   Nieudane pobranie nigdy nie nadpisuje ostatniego poprawnego snapshotu. Bez automatycznych ponowień.
 * - Zakres adresów: rodzina domeny projektu; konkurent — host z listy konkurentów projektu albo adres organicznego wyniku zapisanego
 *   pomiaru SERP projektu. Inne adresy — odmowa (`url_not_allowed`).
 * - Brak pobrania, timeout, 403 czy 404 nie dowodzą, że strony nie ma — stan jest zapisywany jako wynik próby, nie jako fakt o stronie.
 */
final class PageIntelligenceService implements PageEvidenceSource
{
	public const TRIGGER_CLI = 'cli';

	public const TRIGGER_PANEL = 'panel';

	public const MAINTENANCE_TRANSIENT = 'osf_seo_pages_maintenance';

	/** Najdłuższe uwzględniane `crawl-delay` z robots.txt (sekundy). */
	private const MAX_CRAWL_DELAY = 60;

	/** Najdłuższe łączne czekanie na odstęp hosta w jednym zleceniu z czekaniem (CLI), sekundy — dalej odmowa `domain_cooldown`. */
	public const MAX_WAIT_TOTAL = 120;

	/** Czas czekania w bieżącym zleceniu (sekundy). */
	private int $waited = 0;

	public function __construct(
		private readonly PageIntelligenceRepository $pages,
		private readonly PageFetcher $fetcher,
		private readonly RobotsPolicy $robots,
		private readonly HtmlExtractor $extractor,
		private readonly UrlSafetyPolicy $policy,
		private readonly StrategyService $strategy,
		private readonly CompetitorRepository $competitors,
		private readonly PageIntelligenceConfig $config,
		private readonly Connection $db,
		private readonly Clock $clock,
		private readonly Logger $logger,
		private readonly bool $transportAvailable = true,
		/** @var (\Closure(int): void)|null czekanie na odstęp hosta (testy: przesunięcie zegara) */
		private readonly ?\Closure $sleeper = null,
	) {
	}

	/**
	 * Stan modułu w projekcie (bez HTTP): konfiguracja, dostępność transportu, liczniki.
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context): array
	{
		return [
			'config' => $this->config->effective(),
			'transport' => $this->transportAvailable ? 'curl_pinned' : 'unavailable',
			'can_fetch' => $context->can(Capabilities::MANAGE_PAGE_INTELLIGENCE),
			'counts' => $this->pages->counts($context->projectId(), $this->ago(86400)),
		];
	}

	/**
	 * Plan pobrania — bez HTTP i bez DNS: zakres, rodzaj, stan pamięci, limity hosta, co zostanie pobrane.
	 *
	 * @return array{items: list<array<string, mixed>>, fetches: int, max_urls: int, refused: ?string}
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 */
	public function plan(ProjectContext $context, PageSelection $selection, bool $force = false): array
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$items = $this->resolve($context, $selection);
		$fetches = 0;

		foreach ($items as $index => $item) {
			$items[$index] = $this->planItem($context, $item, $force);
			$fetches += $items[$index]['would_fetch'] ? 1 : 0;
		}

		return ['items' => array_map(self::publicItem(...), $items), 'fetches' => $fetches, 'max_urls' => $this->config->maxUrls(), 'refused' => $this->refusal($items)];
	}

	/**
	 * Pobranie wybranych stron (jawne działanie). Zwraca wynik każdego adresu; odmowa całego zlecenia — `PageRefused`.
	 * `$waitForHost` (CLI): kolejne adresy tego samego hosta czekają na odstęp (łącznie najwyżej MAX_WAIT_TOTAL s) zamiast odmowy —
	 * pobrania zawsze po kolei, nigdy równolegle. Bez czekania (np. przyszły panel) — odmowa `domain_cooldown` z `retry_in`.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws AccessDenied
	 * @throws StrategyNotFound
	 * @throws PageRefused
	 */
	public function fetch(ProjectContext $context, PageSelection $selection, bool $force = false, string $trigger = self::TRIGGER_CLI, bool $waitForHost = false): array
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$items = $this->resolve($context, $selection);
		$refusal = $this->refusal($items);

		if ($refusal !== null) {
			throw new PageRefused($refusal);
		}

		$results = [];
		$this->waited = 0;

		foreach ($items as $item) {
			$results[] = self::publicItem($this->fetchItem($context, $this->planItem($context, $item, $force), $force, $trigger, $waitForHost));
		}

		return $results;
	}

	/**
	 * Strony projektu z ostatnim snapshotem (bez treści).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function pages(ProjectContext $context, ?string $kind = null, int $limit = 50): array
	{
		$kind = in_array($kind, [PageTarget::KIND_PROJECT, PageTarget::KIND_COMPETITOR], true) ? $kind : null;
		$targets = $this->pages->targets($context->projectId(), $kind, $limit);
		$links = $this->pages->serpLinks($context->projectId(), array_map(static fn (PageTarget $target): int => $target->id, $targets));

		return array_map(function (PageTarget $target) use ($links): array {
			$snapshot = $this->pages->latestSnapshot($target);

			return $target->toArray() + [
				'cache' => $this->cacheState($target, $snapshot),
				'snapshot' => $snapshot?->toArray(),
				'serp' => $links[$target->id][0] ?? null,
			];
		}, $targets);
	}

	/**
	 * Strona z ostatnim snapshotem (pełna treść), listą snapshotów, próbami i powiązaniami SERP — wyłącznie w obrębie projektu.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws PageNotFound
	 */
	public function page(ProjectContext $context, string $pageId): array
	{
		$target = $this->pages->target($context->projectId(), $pageId) ?? throw new PageNotFound();
		$snapshot = $this->pages->latestSnapshot($target);

		return [
			'page' => $target->toArray(),
			'cache' => $this->cacheState($target, $snapshot),
			'snapshot' => $snapshot?->toArray(true),
			'snapshots' => array_map(static fn (PageSnapshotRecord $record): array => $record->toArray(), $this->pages->snapshots($target)),
			'fetches' => $this->pages->fetches($target),
			'serp' => $this->pages->serpLinks($context->projectId(), [$target->id])[$target->id] ?? [],
		];
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws PageNotFound
	 */
	public function snapshot(ProjectContext $context, string $snapshotId): array
	{
		return ($this->pages->snapshot($context->projectId(), $snapshotId) ?? throw new PageNotFound())->toArray(true);
	}

	/**
	 * Usunięcie strony z zapisanymi treściami i historią prób.
	 *
	 * @throws AccessDenied
	 * @throws PageNotFound
	 */
	public function delete(ProjectContext $context, string $pageId): void
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$target = $this->pages->target($context->projectId(), $pageId) ?? throw new PageNotFound();
		$this->pages->deleteTarget($target);
		$this->logger->info('Page {page} of project {project} deleted with its snapshots.', ['page' => $target->publicId, 'project' => $context->publicId()]);
	}

	/**
	 * Diagnostyka adresu: zakres, składnia i DNS z kontrolą adresów IP — bez HTTP.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws AccessDenied
	 */
	public function checkUrl(ProjectContext $context, string $url): array
	{
		$context->assertCan(Capabilities::MANAGE_PAGE_INTELLIGENCE);
		$item = $this->resolve($context, PageSelection::urls([$url]))[0] ?? null;

		if ($item === null) {
			return ['allowed' => false, 'reason' => 'invalid_url', 'kind' => null, 'host' => null, 'ips' => []];
		}

		if (! $item['allowed']) {
			return ['allowed' => false, 'reason' => $item['reason'], 'kind' => $item['kind'], 'host' => $item['host'], 'ips' => []];
		}

		$check = $this->policy->check((string) $item['url'], $item['families']);

		return ['allowed' => $check['allowed'], 'reason' => $check['reason'], 'kind' => $item['kind'], 'host' => $item['host'], 'ips' => $check['ips']];
	}

	public function forTopic(ProjectContext $context, TopicRow $topic, array $members): array
	{
		return $this->topicEvidence($context, $topic, $members);
	}

	/**
	 * Dane stron dla kontekstu AI tematu — wyłącznie zapisane snapshoty projektu (zero HTTP): strona docelowa tematu i strony konkurencji
	 * powiązane z wynikami SERP fraz tematu.
	 *
	 * @param list<array<string, mixed>> $members frazy tematu (`TopicRepository::members`)
	 * @return array{project: ?array<string, mixed>, competitors: list<array<string, mixed>>, competitors_total: int}
	 */
	public function topicEvidence(ProjectContext $context, TopicRow $topic, array $members, int $competitorLimit = 5): array
	{
		$project = null;
		$url = $topic->targetUrl;

		if (is_string($url) && $url !== '') {
			// Odczyt zapisanych danych nie zależy od bieżącej polityki sieci (porty, DNS): adres znormalizowany albo dokładnie zapisany.
			$target = null;

			foreach (array_unique(array_filter([$this->policy->inspect($url)['url'], $url, strtok($url, '#') ?: null])) as $key) {
				$target ??= $this->pages->targetByUrl($context->projectId(), (string) $key);
			}

			$project = $target === null
				? ['url' => $url, 'target' => null, 'snapshot' => null, 'cache' => 'missing']
				: $this->evidence($target);
		}

		$keywordIds = array_values(array_unique(array_map(static fn (array $member): int => (int) $member['market_keyword_id'], $members)));
		$keywords = [];

		foreach ($members as $member) {
			$keywords[(int) $member['market_keyword_id']] = (string) $member['id'];
		}

		$linked = $this->pages->competitorPagesForKeywords($context->projectId(), $keywordIds, 50);
		$competitors = [];

		foreach (array_slice($linked, 0, $competitorLimit) as $link) {
			$competitors[] = $this->evidence($link['target']) + [
				'serp' => ['keyword_id' => $keywords[$link['market_keyword_id']] ?? null, 'rank_group' => $link['rank_group'], 'checked_at' => $link['serp_checked_at']],
			];
		}

		return ['project' => $project, 'competitors' => $competitors, 'competitors_total' => count($linked)];
	}

	/**
	 * Porządki (krok w tle raz na dobę albo CLI) — bez HTTP: retencja snapshotów i prób.
	 *
	 * @return array{snapshots: int, fetches: int}|null
	 */
	public function maintenance(bool $force = false): ?array
	{
		if (! $force && (! ProjectGuard::isSystemProcess() || get_transient(self::MAINTENANCE_TRANSIENT) !== false)) {
			return null;
		}

		set_transient(self::MAINTENANCE_TRANSIENT, '1', DAY_IN_SECONDS);
		$result = $this->pages->purgeBefore($this->ago($this->config->retentionDays() * 86400));

		if ($result['snapshots'] + $result['fetches'] > 0) {
			$this->logger->info('Page Intelligence retention: {snapshots} snapshots and {fetches} fetch records removed.', $result);
		}

		return $result;
	}

	/**
	 * Wybór → elementy z adresem, rodzajem, źródłem, zakresem hostów i powiązaniem SERP (bez HTTP i DNS).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function resolve(ProjectContext $context, PageSelection $selection): array
	{
		$projectFamily = DomainFamily::normalize($context->project()->domain) ?? '';
		$items = [];

		if ($selection->type === PageSelection::URLS) {
			$competitorFamilies = array_values(array_filter(array_map(static fn ($competitor): ?string => DomainFamily::normalize($competitor->domain), $this->competitors->active($context->projectId()))));

			foreach ($selection->urls as $url) {
				$items[] = $this->manualItem($context, $url, $projectFamily, $competitorFamilies);
			}
		} elseif ($selection->type === PageSelection::TOPIC) {
			$topic = $this->strategy->topic($context, (string) $selection->topic)['topic'];

			if ($topic->targetUrl === null || $topic->targetUrl === '') {
				$items[] = ['topic_id' => $topic->id, 'target_state' => $topic->targetState] + self::item($selection->topic, null, null, PageTarget::KIND_PROJECT, PageTarget::SOURCE_TOPIC, [], 'topic_without_target');
			} else {
				$items[] = ['topic_id' => $topic->id, 'target_state' => $topic->targetState] + $this->urlItem($topic->targetUrl, PageTarget::KIND_PROJECT, PageTarget::SOURCE_TOPIC, [$projectFamily]);
			}
		} else {
			$detail = $this->strategy->serpDetail($context, (string) $selection->keyword);
			$serp = $detail['detail'];

			if ($serp === null) {
				return [self::item($selection->keyword, null, null, PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_SERP, [], 'no_serp_measurement')];
			}

			$snapshotId = $this->pages->serpSnapshotId($context->projectId(), (string) $serp['snapshot']);
			$results = [];

			foreach ((array) ($serp['results'] ?? []) as $result) {
				$results[(int) $result['rank']] ??= $result;
			}

			foreach ($selection->ranks === [] ? [0] : $selection->ranks as $rank) {
				$result = $results[$rank] ?? null;

				if ($result === null) {
					$items[] = self::item('#' . $rank, null, null, PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_SERP, [], $rank === 0 ? 'no_ranks_selected' : 'rank_not_found');

					continue;
				}

				$host = DomainFamily::fromUrl((string) $result['url']);
				$isProject = (bool) ($result['project'] ?? false) || $host !== null && $projectFamily !== '' && DomainFamily::matches($host, $projectFamily);
				$items[] = ['serp' => $snapshotId === null ? null : [
						'snapshot_id' => $snapshotId,
						'snapshot' => (string) $serp['snapshot'],
						'market_keyword_id' => $detail['candidate']->marketKeywordId,
						'keyword' => $detail['candidate']->keyword,
						'rank_group' => $rank,
						'checked_at' => (string) $serp['checked_at'],
						'freshness' => $serp['freshness'] ?? null,
					]] + $this->urlItem((string) $result['url'], $isProject ? PageTarget::KIND_PROJECT : PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_SERP, $isProject ? [$projectFamily] : array_values(array_filter([$host])));
			}
		}

		return $items;
	}

	/**
	 * Ręcznie podany adres: strona projektu, konkurent z listy projektu albo adres wyniku organicznego zapisanego pomiaru SERP projektu.
	 *
	 * @param list<string> $competitorFamilies
	 * @return array<string, mixed>
	 */
	private function manualItem(ProjectContext $context, string $url, string $projectFamily, array $competitorFamilies): array
	{
		$inspected = $this->policy->inspect($url);

		if (! $inspected['allowed']) {
			return self::item($url, null, $inspected['host'], null, PageTarget::SOURCE_MANUAL, [], (string) $inspected['reason']);
		}

		$normalized = (string) $inspected['url'];
		$host = (string) $inspected['host'];

		if ($projectFamily !== '' && UrlSafetyPolicy::inScope($host, [$projectFamily])) {
			return $this->urlItem($normalized, PageTarget::KIND_PROJECT, PageTarget::SOURCE_MANUAL, [$projectFamily]);
		}

		$family = (string) $inspected['family'];

		if (UrlSafetyPolicy::inScope($host, $competitorFamilies)) {
			return $this->urlItem($normalized, PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_MANUAL, [$family]);
		}

		$serp = $this->pages->serpResultForUrl($context->projectId(), array_values(array_unique([$url, $normalized])));

		if ($serp !== null) {
			return ['serp' => [
				'snapshot_id' => $serp['serp_snapshot_id'],
				'snapshot' => null,
				'market_keyword_id' => $serp['market_keyword_id'],
				'keyword' => null,
				'rank_group' => $serp['rank_group'],
				'checked_at' => $serp['checked_at'],
				'freshness' => null,
			]] + $this->urlItem($normalized, PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_SERP, [$family]);
		}

		return self::item($url, $normalized, $host, PageTarget::KIND_COMPETITOR, PageTarget::SOURCE_MANUAL, [], 'url_not_allowed');
	}

	/**
	 * @param list<string> $families
	 * @return array<string, mixed>
	 */
	private function urlItem(string $url, string $kind, string $source, array $families): array
	{
		$inspected = $this->policy->inspect($url);

		if (! $inspected['allowed']) {
			return self::item($url, null, $inspected['host'], $kind, $source, $families, (string) $inspected['reason']);
		}

		if ($families === [] || ! UrlSafetyPolicy::inScope((string) $inspected['host'], $families)) {
			return self::item($url, (string) $inspected['url'], $inspected['host'], $kind, $source, $families, 'outside_scope');
		}

		return self::item($url, (string) $inspected['url'], (string) $inspected['host'], $kind, $source, $families, null);
	}

	/**
	 * @param list<string> $families
	 * @return array<string, mixed>
	 */
	private static function item(?string $input, ?string $url, ?string $host, ?string $kind, string $source, array $families, ?string $reason): array
	{
		return ['input' => $input, 'url' => $url, 'host' => $host, 'kind' => $kind, 'source' => $source, 'families' => $families, 'allowed' => $reason === null, 'reason' => $reason, 'topic_id' => null, 'serp' => null];
	}

	/**
	 * Uzupełnienie elementu o stan pamięci, limity hosta i decyzję „pobierz / z pamięci / odmowa” (bez HTTP).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function planItem(ProjectContext $context, array $item, bool $force): array
	{
		$item += ['cache' => null, 'snapshot' => null, 'last_seen_at' => null, 'host_limits' => null, 'would_fetch' => false, 'page' => null];

		if (! $item['allowed']) {
			return $item;
		}

		if (! $this->kindEnabled((string) $item['kind'])) {
			return ['allowed' => false, 'reason' => 'kind_disabled'] + $item;
		}

		$target = $this->pages->targetByUrl($context->projectId(), (string) $item['url']);
		$snapshot = $target === null ? null : $this->pages->latestSnapshot($target);
		$cache = $target === null ? 'missing' : $this->cacheState($target, $snapshot);
		$limits = $this->hostLimits((string) $item['host']);

		return [
			'cache' => $cache,
			'page' => $target?->publicId,
			'snapshot' => $snapshot?->publicId,
			'last_seen_at' => $snapshot?->lastSeenAt,
			'host_limits' => $limits,
			'would_fetch' => $force || $cache !== 'fresh',
		] + $item;
	}

	/**
	 * Pobranie jednego elementu planu.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function fetchItem(ProjectContext $context, array $item, bool $force, string $trigger, bool $wait = false): array
	{
		$userId = $context->userId() > 0 ? $context->userId() : null;

		if (! $item['allowed']) {
			return $item + ['outcome' => 'refused', 'error' => $item['reason']];
		}

		$target = $this->pages->ensureTarget($context->projectId(), (string) $item['url'], (string) $item['host'], (string) $item['kind'], (string) $item['source'], $item['topic_id'], $userId);
		$item['page'] = $target->publicId;

		if (is_array($item['serp'])) {
			$this->pages->linkSerp($target, (int) $item['serp']['snapshot_id'], (int) $item['serp']['market_keyword_id'], (int) $item['serp']['rank_group'], (string) $item['serp']['checked_at']);
		}

		$startedAt = $this->pages->now();
		$started = microtime(true);
		$latest = $this->pages->latestSnapshot($target);

		if (! $item['would_fetch']) {
			$this->pages->recordFetch($target, $trigger, $userId, $startedAt, 0, false, 'cached', null, null, null, $latest?->id, null, ['cache' => 'fresh']);

			return ['outcome' => 'cached', 'error' => null, 'snapshot' => $latest?->publicId, 'changed' => false] + $item;
		}

		if (! $this->transportAvailable) {
			return $this->refuse($target, $item, $trigger, $userId, $startedAt, 'transport_unavailable');
		}

		$pageLock = 'page_fetch_' . $target->id;
		$hostLock = 'page_host_' . substr(md5($target->host), 0, 16);

		if (! $this->db->acquireLock($pageLock, 0)) {
			return $this->refuse($target, $item, $trigger, $userId, $startedAt, 'fetch_in_progress');
		}

		try {
			if (! $this->db->acquireLock($hostLock, 0)) {
				return $this->refuse($target, $item, $trigger, $userId, $startedAt, 'host_busy');
			}

			try {
				$limits = $this->hostLimits($target->host);

				if ($wait && $limits['blocked'] === 'domain_cooldown' && $this->await((int) $limits['retry_in'])) {
					$limits = $this->hostLimits($target->host);
					$startedAt = $this->pages->now();
					$started = microtime(true);
				}

				if ($limits['blocked'] !== null) {
					return $this->refuse($target, $item, $trigger, $userId, $startedAt, $limits['blocked']) + ['retry_in' => $limits['retry_in']];
				}

				$request = new FetchRequest(
					$target->url,
					$item['families'],
					$this->config->maxBytes(),
					$this->config->timeout(),
					$this->config->connectTimeout(),
					$this->config->maxRedirects(),
					$this->config->userAgent(),
					FetchRequest::ACCEPT_HTML,
					$force ? null : $latest?->etag,
					$force ? null : $latest?->lastModified,
				);
				$robots = $this->robots->check($request);

				if (! $robots['allowed']) {
					// Odmowa polityki już przy robots.txt (np. host rozwiązany do sieci wewnętrznej) — powód polityki, nie „robots”.
					$refused = $robots['status'] === 'refused';
					$error = $refused ? (string) ($robots['reason'] ?? 'refused') : 'robots_' . $robots['status'];
					$outcome = $refused ? 'refused' : 'robots';
					$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), $robots['network'], $outcome, $error, null, null, null, null, ['robots' => $robots['status']]);
					$this->pages->recordAttempt($target, PageTarget::STATUS_BLOCKED, $error, null, null, null);

					return ['outcome' => $outcome, 'error' => $error, 'snapshot' => $latest?->publicId, 'changed' => false] + $item;
				}

				$delay = min(self::MAX_CRAWL_DELAY, (int) ceil((float) ($robots['crawl_delay'] ?? 0)));
				$remaining = $limits['last'] === null ? 0 : $delay - ($this->clock->now()->getTimestamp() - (int) strtotime($limits['last'] . ' UTC'));

				if ($delay > $this->config->domainInterval() && $remaining > 0 && ! ($wait && $this->await($remaining))) {
					return $this->refuse($target, $item, $trigger, $userId, $startedAt, 'domain_cooldown') + ['retry_in' => $remaining];
				}

				$result = $this->fetcher->fetch($request);

				return $this->store($target, $item, $result, $latest, $trigger, $userId, $startedAt, $started, $robots['status']);
			} finally {
				$this->db->releaseLock($hostLock);
			}
		} catch (Throwable $exception) {
			$this->logger->error('Page fetch of {page} failed unexpectedly: {class}.', ['page' => $target->publicId, 'class' => $exception::class]);
			$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), true, 'failed', 'internal_error', null, null, null, null, []);
			$this->pages->recordAttempt($target, PageTarget::STATUS_FAILED, 'internal_error', null, null, null);

			return ['outcome' => 'failed', 'error' => 'internal_error', 'snapshot' => $latest?->publicId, 'changed' => false] + $item;
		} finally {
			$this->db->releaseLock($pageLock);
		}
	}

	/**
	 * Zapis wyniku pobrania: nowy snapshot tylko przy zmianie treści; błąd nie dotyka ostatniego poprawnego snapshotu.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function store(PageTarget $target, array $item, FetchResult $result, ?PageSnapshotRecord $latest, string $trigger, ?int $userId, string $startedAt, float $started, string $robots): array
	{
		$diagnostics = $result->diagnostics() + ['robots' => $robots];

		if ($result->outcome === FetchResult::NOT_MODIFIED && $latest !== null) {
			$this->pages->touchSnapshot($latest, self::ascii($result->headers['etag'] ?? null, 255), self::ascii($result->headers['last-modified'] ?? null, 64));
			$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), $result->network, 'not_modified', null, $result->httpStatus, $result->bytes, $latest->id, null, $diagnostics);
			$this->pages->recordAttempt($target, PageTarget::STATUS_OK, null, $result->httpStatus, $latest->finalUrl, $latest->id);

			return ['outcome' => 'unchanged', 'error' => null, 'snapshot' => $latest->publicId, 'changed' => false, 'http_status' => 304] + $item;
		}

		if (! $result->ok()) {
			$error = $result->errorCode ?? $result->outcome;
			$retryAt = $result->retryAfter !== null ? $this->clock->now()->modify('+' . $result->retryAfter . ' seconds')->format('Y-m-d H:i:s') : null;
			$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), $result->network, $result->outcome, $error, $result->httpStatus, $result->bytes, null, $retryAt, $diagnostics);
			$this->pages->recordAttempt($target, $result->outcome === FetchResult::REFUSED ? PageTarget::STATUS_BLOCKED : PageTarget::STATUS_FAILED, $error, $result->httpStatus, null, null);
			$this->logger->info('Page {page} fetch {outcome}: {error} (HTTP {status}).', ['page' => $target->publicId, 'outcome' => $result->outcome, 'error' => $error, 'status' => $result->httpStatus ?? 0]);

			return ['outcome' => $result->outcome, 'error' => $error, 'http_status' => $result->httpStatus, 'snapshot' => $latest?->publicId, 'changed' => false] + $item;
		}

		$extraction = $this->extractor->extract((string) $result->body, (string) $result->finalUrl, $result->charset, $result->headers);
		$hash = $extraction->contentHash();
		$etag = self::ascii($result->headers['etag'] ?? null, 255);
		$lastModified = self::ascii($result->headers['last-modified'] ?? null, 64);

		if ($latest !== null && $latest->contentHash === $hash && $latest->extractorVersion === HtmlExtractor::VERSION) {
			$this->pages->touchSnapshot($latest, $etag, $lastModified);
			$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), true, 'unchanged', null, $result->httpStatus, $result->bytes, $latest->id, null, $diagnostics);
			$this->pages->recordAttempt($target, PageTarget::STATUS_OK, null, $result->httpStatus, $latest->finalUrl, $latest->id);

			return ['outcome' => 'unchanged', 'error' => null, 'http_status' => $result->httpStatus, 'snapshot' => $latest->publicId, 'changed' => false] + $item;
		}

		$now = $this->pages->now();
		$data = $extraction->toArray() + ['fetch' => ['requested_url' => $result->requestedUrl, 'final_url' => $result->finalUrl, 'http_status' => $result->httpStatus, 'redirects' => $result->redirects]];
		$snapshot = $this->pages->createSnapshot($target, [
			'extractor_version' => HtmlExtractor::VERSION,
			'fetched_at' => $now,
			'last_seen_at' => $now,
			'http_status' => (int) $result->httpStatus,
			'content_type' => (string) $result->contentType,
			'charset' => self::ascii($extraction->charset, 40),
			'final_url' => (string) $result->finalUrl,
			'bytes' => $result->bytes,
			'fetch_ms' => $result->durationMs,
			'body_hash' => hash('sha256', (string) $result->body),
			'content_hash' => $hash,
			'etag' => $etag,
			'last_modified' => $lastModified,
			'title' => $extraction->title(),
			'word_count' => (int) $extraction->content['word_count'],
			'h1_count' => (int) $extraction->headings['counts']['h1'],
			'headings_count' => min(65535, (int) $extraction->headings['total']),
			'links_internal' => (int) $extraction->links['counts']['internal'],
			'links_external' => (int) $extraction->links['counts']['external'],
			'content_quality' => (string) $extraction->quality['level'],
			'indexability' => (string) $extraction->technical['indexability'],
			'canonical_status' => (string) $extraction->technical['canonical_status'],
			'data' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
		]);
		$this->pages->recordFetch($target, $trigger, $userId, $startedAt, self::elapsed($started), true, $latest === null ? 'created' : 'changed', null, $result->httpStatus, $result->bytes, $snapshot->id, null, $diagnostics);
		$this->pages->recordAttempt($target, PageTarget::STATUS_OK, null, $result->httpStatus, $result->finalUrl, $snapshot->id);
		$this->pages->pruneSnapshots($target, $this->config->maxSnapshots());

		return ['outcome' => $latest === null ? 'created' : 'changed', 'error' => null, 'http_status' => $result->httpStatus, 'snapshot' => $snapshot->publicId, 'changed' => true] + $item;
	}

	/**
	 * Odmowa przed żądaniem (zapisana jako próba bez ruchu sieciowego).
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function refuse(PageTarget $target, array $item, string $trigger, ?int $userId, string $startedAt, string $reason): array
	{
		$this->pages->recordFetch($target, $trigger, $userId, $startedAt, 0, false, 'refused', $reason, null, null, null, null, []);

		return ['outcome' => 'refused', 'error' => $reason, 'snapshot' => $this->pages->latestSnapshot($target)?->publicId, 'changed' => false] + $item;
	}

	/** Czekanie na odstęp hosta w zleceniu z czekaniem — false, gdy przekroczyłoby łączny limit czekania. */
	private function await(int $seconds): bool
	{
		if ($seconds <= 0 || $this->waited + $seconds > self::MAX_WAIT_TOTAL) {
			return false;
		}

		$this->waited += $seconds;
		($this->sleeper ?? static function (int $seconds): void {
			sleep($seconds);
		})($seconds);

		return true;
	}

	/**
	 * Uprzejmość wobec hosta (wszystkie projekty): `Retry-After`, odstęp, limit dzienny.
	 *
	 * @return array{blocked: ?string, retry_in: ?int, last: ?string, fetches_24h: int, daily_limit: int, interval: int}
	 */
	private function hostLimits(string $host): array
	{
		$now = $this->clock->now()->getTimestamp();
		$activity = $this->pages->hostActivity($host, $this->ago(86400));
		$interval = $this->config->domainInterval();
		$sinceLast = $activity['last'] === null ? null : $now - (int) strtotime($activity['last'] . ' UTC');
		$retryAfter = $activity['retry_after'] === null ? null : (int) strtotime($activity['retry_after'] . ' UTC') - $now;
		[$blocked, $retryIn] = match (true) {
			$retryAfter !== null && $retryAfter > 0 => ['host_retry_after', $retryAfter],
			$sinceLast !== null && $sinceLast < $interval => ['domain_cooldown', $interval - $sinceLast],
			$activity['count'] >= $this->config->domainDailyLimit() => ['domain_daily_limit', null],
			default => [null, null],
		};

		return ['blocked' => $blocked, 'retry_in' => $retryIn, 'last' => $activity['last'], 'fetches_24h' => $activity['count'], 'daily_limit' => $this->config->domainDailyLimit(), 'interval' => $interval];
	}

	/** `fresh` / `stale` (snapshot), `failed` (brak snapshotu po nieudanej próbie), `missing` (nigdy nie pobrano). */
	private function cacheState(PageTarget $target, ?PageSnapshotRecord $snapshot): string
	{
		if ($snapshot === null) {
			return $target->lastError !== null ? 'failed' : 'missing';
		}

		return (int) strtotime($snapshot->lastSeenAt . ' UTC') >= $this->clock->now()->getTimestamp() - $this->config->ttlHours() * 3600 ? 'fresh' : 'stale';
	}

	/**
	 * Dowód strony dla kontekstu AI: ostatni snapshot (pełne dane) i stan.
	 *
	 * @return array<string, mixed>
	 */
	private function evidence(PageTarget $target): array
	{
		$snapshot = $this->pages->latestSnapshot($target);
		$full = $snapshot === null ? null : $this->pages->snapshotById($target->projectId, $snapshot->id);

		return [
			'url' => $target->url,
			'target' => $target->toArray(),
			'snapshot' => $full?->toArray(true),
			'cache' => $this->cacheState($target, $snapshot),
		];
	}

	/**
	 * Odmowa całego zlecenia (zanim cokolwiek zostanie pobrane).
	 *
	 * @param list<array<string, mixed>> $items
	 */
	private function refusal(array $items): ?string
	{
		if (! $this->transportAvailable) {
			return 'transport_unavailable';
		}

		if ($items === []) {
			return 'nothing_selected';
		}

		return count($items) > $this->config->maxUrls() ? 'too_many_urls' : null;
	}

	private function kindEnabled(string $kind): bool
	{
		return $kind === PageTarget::KIND_PROJECT ? $this->config->projectEnabled() : $this->config->competitorsEnabled();
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function publicItem(array $item): array
	{
		unset($item['families']);

		if (is_array($item['serp'] ?? null)) {
			unset($item['serp']['snapshot_id'], $item['serp']['market_keyword_id']);
		}

		return $item;
	}

	private static function ascii(?string $value, int $max): ?string
	{
		if ($value === null || $value === '' || preg_match('/^[\x20-\x7E]+$/', $value) !== 1) {
			return null;
		}

		return substr($value, 0, $max);
	}

	private static function elapsed(float $started): int
	{
		return (int) round((microtime(true) - $started) * 1000);
	}

	private function ago(int $seconds): string
	{
		return $this->clock->now()->modify('-' . $seconds . ' seconds')->format('Y-m-d H:i:s');
	}

	/** Transport dostępny w tym środowisku (ext-curl z przypinaniem adresu). */
	public static function transportSupported(): bool
	{
		return CurlPageFetcher::available();
	}
}
