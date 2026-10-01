<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\BulkInsert;
use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Zapis wyniku pomiaru: pełne TOP N (wszystkie wyniki organiczne i wyróżnione fragmenty, wszystkie domeny — nie tylko
 * projekt i konkurenci), wynik projektu w pomiarze i bieżący stan monitorowanej frazy.
 *
 * Słowniki (domeny, adresy, treści) uzupełniane przed transakcją — wpisy są niezmienne i idempotentne, więc transakcja
 * zapisu wyniku jest krótka: przejęcie pomiaru (tylko jeden proces zapisze dane zadanie) → wsadowy zapis wierszy wyników
 * → metadane pomiaru → bieżący stan frazy.
 */
final class SerpStore
{
	public function __construct(
		private readonly Connection $db,
		private readonly SerpDictionary $dictionary,
		private readonly SerpSnapshotRepository $snapshots,
		private readonly TrackedKeywordRepository $tracked,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param array<string, string|null> $snapshot wiersz `serp_snapshots`
	 * @param string $projectDomain znormalizowana domena projektu (rodzina: domena + subdomeny)
	 * @return int|null liczba zapisanych wyników; null — pomiar zapisał już inny proces
	 */
	public function ingest(array $snapshot, SerpPage $page, string $projectDomain, ?float $reportedCost = null): ?int
	{
		$items = $page->items;
		$domainIds = $this->dictionary->domainIds(array_map(static fn (SerpItem $item): string => $item->host, $items));
		$urls = [];
		$snippets = [];

		foreach ($items as $item) {
			if (isset($domainIds[$item->host])) {
				$urls[$item->urlHash()] = ['url' => $item->url, 'domain_id' => $domainIds[$item->host]];
			}

			$hash = $item->snippetHash();

			if ($hash !== null) {
				$snippets[$hash] = $item;
			}
		}

		$urlIds = $this->dictionary->urlIds($urls);
		$snippetIds = $this->dictionary->snippetIds($snippets);
		$observation = self::observe($items, $projectDomain);
		$snapshotId = (int) $snapshot['id'];
		$checkedAt = $page->checkedAt ?? $this->clock->now()->format('Y-m-d H:i:s');

		return $this->db->transaction(function () use ($snapshotId, $snapshot, $page, $items, $domainIds, $urlIds, $snippetIds, $observation, $checkedAt, $reportedCost): ?int {
			if (! $this->snapshots->claim($snapshotId, $checkedAt)) {
				return null;
			}

			$insert = new BulkInsert(
				$this->db,
				$this->db->table('serp_results'),
				['snapshot_id', 'item_index', 'result_type', 'rank_group', 'rank_absolute', 'page', 'domain_id', 'url_id', 'snippet_id', 'flags'],
				['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d'],
				'',
				500,
			);
			$index = 0;

			foreach ($items as $item) {
				$urlId = $urlIds[$item->urlHash()] ?? null;

				if (! isset($domainIds[$item->host]) || $urlId === null) {
					continue;
				}

				$hash = $item->snippetHash();
				$insert->add([
					$snapshotId,
					++$index,
					$item->type,
					min($item->rankGroup, 65535),
					min($item->rankAbsolute, 65535),
					min((int) $item->page, 255),
					$domainIds[$item->host],
					$urlId,
					$hash === null ? 0 : ($snippetIds[$hash] ?? 0),
					$item->flags,
				]);
			}

			$insert->flush();
			$best = $observation['best'];
			$data = [
				'se_domain' => $page->seDomain,
				'se_results_count' => $page->seResultsCount,
				'pages_count' => $page->pagesCount,
				'items_count' => $page->itemsCount,
				'organic_count' => $page->organicCount(),
				'item_types' => $page->itemTypes,
				'spell_type' => $page->spellType,
				'spell_keyword' => $page->spellKeyword,
				'project_rank' => $best?->rankGroup,
				'project_rank_absolute' => $best?->rankAbsolute,
				'project_url_id' => $best === null ? null : ($urlIds[$best->urlHash()] ?? null),
				'project_results' => min($observation['count'], 255),
				'project_featured' => $observation['featured'] ? 1 : 0,
			];

			if ($reportedCost !== null) {
				$data['cost'] = round($reportedCost, 6);
			}

			$this->snapshots->update($snapshotId, $data);
			$this->tracked->refreshCurrent((int) $snapshot['tracked_keyword_id'], $snapshotId);

			return $index;
		});
	}

	/**
	 * Wynik projektu w pomiarze: najlepsza pozycja organiczna domeny projektu (z subdomenami), liczba jej wyników
	 * organicznych i czy projekt ma wyróżniony fragment (osobno — nie jako pozycja #1).
	 *
	 * @param list<SerpItem> $items
	 * @return array{best: ?SerpItem, count: int, featured: bool}
	 */
	public static function observe(array $items, string $domain): array
	{
		$best = null;
		$count = 0;
		$featured = false;

		foreach ($items as $item) {
			if (! DomainFamily::matches($item->host, $domain)) {
				continue;
			}

			if (! $item->isOrganic()) {
				$featured = $featured || $item->type === SerpItem::TYPE_FEATURED_SNIPPET;

				continue;
			}

			$count++;

			if ($best === null || $item->rankGroup < $best->rankGroup) {
				$best = $item;
			}
		}

		return ['best' => $best, 'count' => $count, 'featured' => $featured];
	}
}
