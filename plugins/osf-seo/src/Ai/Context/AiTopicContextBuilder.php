<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use OsfSeo\Ai\Analysis\Readiness;
use OsfSeo\Ai\Analysis\ReadinessEvaluator;
use OsfSeo\Ai\Prompt\AnalysisPrompts;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Target\ProjectPageIndex;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Support\Clock;

/**
 * Źródła kontekstu AI tematu: wyłącznie istniejące odczyty Strategii w obrębie projektu (`StrategyService::topicView` — pakiet
 * kontekstu STEP 16, SERP Intelligence tematu, aktualność źródeł; `panelState` — rynek i okno GSC), daty pobrania metryk rynkowych
 * fraz tematu i zapisane snapshoty stron (Page Intelligence, faza B — strona docelowa i strony konkurencji z SERP-u fraz tematu).
 * Bez zapisu, bez żadnego żądania (także bez pobierania stron) i bez równoległej logiki Strategii.
 *
 * Analizy rekomendacji (faza C): to samo źródło + gotowość (`ReadinessEvaluator` — czysta funkcja źródła), język odpowiedzi (język
 * projektu) i inne tematy Strategii projektu ze stronami docelowymi (linkowanie wewnętrzne, ryzyko kanibalizacji).
 */
final class AiTopicContextBuilder
{
	/** Najwięcej innych tematów projektu odczytywanych do sekcji `site` (assembler i tak tnie do limitu typu). */
	public const SITE_TOPICS = 40;

	public function __construct(
		private readonly StrategyService $strategy,
		private readonly Connection $db,
		private readonly ProjectPageIndex $pages,
		private readonly PageEvidenceSource $pageEvidence,
		private readonly Clock $clock,
		private readonly TopicContextAssembler $assembler = new TopicContextAssembler(),
		private readonly ReadinessEvaluator $readiness = new ReadinessEvaluator(),
	) {
	}

	/**
	 * Kontekst analizy tematu (faza A/B, wersja 2).
	 *
	 * @throws StrategyNotFound temat spoza projektu albo nieistniejący (bez rozróżnienia)
	 */
	public function build(ProjectContext $context, string $topic): AiContext
	{
		return $this->assembler->assemble($this->source($context, $topic));
	}

	/**
	 * Kontekst analizy rekomendacji (wersja 3) z oceną gotowości — bez żadnego żądania.
	 *
	 * @return array{context: AiContext, readiness: Readiness}
	 *
	 * @throws StrategyNotFound
	 */
	public function analysis(ProjectContext $context, string $topic, string $type, bool $explicit = false): array
	{
		$source = $this->source($context, $topic);
		$readiness = $this->readiness->evaluate($source, $type, $explicit);
		$source['analysis'] = [
			'type' => $type,
			'language' => AnalysisPrompts::language($context->project()->language),
			'readiness' => $readiness->toArray(),
		];
		$source['site'] = ['topics' => $this->siteTopics($context, (int) $source['topic_id'])];

		return ['context' => $this->assembler->assemble($source), 'readiness' => $readiness];
	}

	/**
	 * Gotowość analizy (bez budowania kontekstu modelu).
	 *
	 * @throws StrategyNotFound
	 */
	public function readiness(ProjectContext $context, string $topic, string $type, bool $explicit = false): Readiness
	{
		return $this->readiness->evaluate($this->source($context, $topic), $type, $explicit);
	}

	/**
	 * Źródło kontekstu: zapisane odczyty Strategii, rynku i Page Intelligence (bez zapisu i bez żądań).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws StrategyNotFound
	 */
	public function source(ProjectContext $context, string $topic): array
	{
		$view = $this->strategy->topicView($context, $topic);
		$state = $this->strategy->panelState($context);
		$members = (array) $view['members'];

		return [
			'context' => $view['context'],
			'serp' => $view['serp'],
			'freshness' => $view['freshness'],
			'project' => [
				'domain' => $context->project()->domain,
				'market' => $state['market'] ?? null,
				'gsc_window' => $state['gsc_window'] ?? null,
			],
			'market_as_of' => $this->marketDates($members),
			'page_index_complete' => $this->pages->complete(),
			'pages' => $this->pageEvidence->forTopic($context, $view['topic'], $members),
			'now' => $this->clock->now(),
			'topic_id' => $view['topic']->id,
		];
	}

	/**
	 * Inne aktywne tematy projektu (bez odrzuconych): najpierw ze stroną docelową, w kolejności priorytetu Strategii — deterministycznie.
	 *
	 * @return list<array{label: ?string, action: ?string, target_url: ?string}>
	 */
	private function siteTopics(ProjectContext $context, int $topicId): array
	{
		$rows = $this->strategy->topics($context, new TopicFilters(status: 'all', includeMonitor: true, perPage: self::SITE_TOPICS))['rows'];
		$withUrl = [];
		$withoutUrl = [];

		foreach ($rows as $row) {
			if ($row->id === $topicId || $row->status === 'dismissed') {
				continue;
			}

			$url = $row->manualTargetUrl ?? $row->targetUrl;
			$item = ['label' => $row->label, 'action' => $row->action, 'target_url' => $url];

			if ($url !== null && $url !== '') {
				$withUrl[] = $item;
			} else {
				$withoutUrl[] = $item;
			}
		}

		return [...$withUrl, ...$withoutUrl];
	}

	/**
	 * Wewnętrzny identyfikator tematu projektu (filtr historii) — rozwiązanie jak w Strategii, w obrębie projektu.
	 *
	 * @throws StrategyNotFound
	 */
	public function topicId(ProjectContext $context, string $topic): int
	{
		return $this->strategy->topic($context, $topic)['topic']->id;
	}

	/**
	 * Daty pobrania wolumenu i trudności fraz tematu (dane rynkowe wspólne dla rynku — tylko frazy tego tematu).
	 *
	 * @param list<array<string, mixed>> $members
	 * @return array<string, array{volume: ?string, difficulty: ?string}>
	 */
	private function marketDates(array $members): array
	{
		$byMarket = [];

		foreach ($members as $member) {
			$byMarket[(int) $member['market_keyword_id']] = (string) $member['id'];
		}

		if ($byMarket === []) {
			return [];
		}

		$ids = array_keys($byMarket);
		$dates = [];

		foreach ($this->db->fetchAll(
			"SELECT id, volume_fetched_at, difficulty_fetched_at FROM `{$this->db->table('market_keywords')}` WHERE id IN (" . Connection::placeholders($ids, '%d') . ')',
			$ids,
		) as $row) {
			$dates[$byMarket[(int) $row['id']]] = ['volume' => $row['volume_fetched_at'], 'difficulty' => $row['difficulty_fetched_at']];
		}

		ksort($dates);

		return $dates;
	}
}
