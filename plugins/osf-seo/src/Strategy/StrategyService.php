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
use OsfSeo\Strategy\Serp\SerpIntelligence;
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
 * Strategia dla CLI i (od fazy D) panelu — wyłącznie przez ProjectContext (docs/ARCHITECTURE.md, sekcja 15). Żadna metoda nie
 * wysyła żądań do API. Przeliczenie, wpisy ręczne, status pracy, ręczna strona docelowa i przypięcia wymagają `osf_seo_manage_strategy`;
 * odczyt — dostępu do projektu (bez tego uprawnienia: bez odrzuconych tematów i notatek wewnętrznych, D62).
 */
final class StrategyService
{
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
		$report = $this->refresher->refresh($context->projectId(), $force);

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
			'events' => $this->events->forTopic($context->projectId(), $topic->id),
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

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
