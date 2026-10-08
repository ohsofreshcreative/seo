<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Target\ProjectPageIndex;
use OsfSeo\Support\Clock;

/**
 * Źródła kontekstu AI tematu: wyłącznie istniejące odczyty Strategii w obrębie projektu (`StrategyService::topicView` — pakiet
 * kontekstu STEP 16, SERP Intelligence tematu, aktualność źródeł; `panelState` — rynek i okno GSC), daty pobrania metryk rynkowych
 * fraz tematu i zapisane snapshoty stron (Page Intelligence, faza B — strona docelowa i strony konkurencji z SERP-u fraz tematu).
 * Bez zapisu, bez żadnego żądania (także bez pobierania stron) i bez równoległej logiki Strategii.
 */
final class AiTopicContextBuilder
{
	public function __construct(
		private readonly StrategyService $strategy,
		private readonly Connection $db,
		private readonly ProjectPageIndex $pages,
		private readonly PageEvidenceSource $pageEvidence,
		private readonly Clock $clock,
		private readonly TopicContextAssembler $assembler = new TopicContextAssembler(),
	) {
	}

	/**
	 * @throws StrategyNotFound temat spoza projektu albo nieistniejący (bez rozróżnienia)
	 */
	public function build(ProjectContext $context, string $topic): AiContext
	{
		$view = $this->strategy->topicView($context, $topic);
		$state = $this->strategy->panelState($context);
		$members = (array) $view['members'];

		return $this->assembler->assemble([
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
		]);
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
