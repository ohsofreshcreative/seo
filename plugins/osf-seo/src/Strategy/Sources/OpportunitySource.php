<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Sources;

use OsfSeo\Database\Connection;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityKeywordIndex;
use OsfSeo\Opportunities\UrlKey;
use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\SourceSignal;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategySource;

/**
 * Szanse SEO (STEP 11, wykrycia z 28 dni): frazy grup aktywnych, nieodrzuconych szans są kandydatami (poziom decyzji), z wagą
 * = priorytet szansy. Powiązanie fraza ↔ szansa wyłącznie z danych query × page (`OpportunityKeywordIndex`, D54); nigdy z samego
 * `opportunities.keyword`. Dowody rozdzielają:
 *
 * - `direct` — fraza szansy albo fraza z listy grupy w wykryciu (dowód, że szansa dotyczy frazy; tylko te dają sygnał i fakt `opportunities`),
 * - `context` — ta sama podstrona (strony GSC frazy z dowodów GSC); nie jest dowodem, że szansa dotyczy frazy.
 *
 * `members_complete = false` — lista fraz grupy w wykryciu jest przycięta (brak powiązania bezpośredniego niczego wtedy nie dowodzi).
 */
final class OpportunitySource implements CandidateSource
{
	private const CHUNK = 500;

	private const LINK_ORDER = [OpportunityKeywordIndex::LINK_KEYWORD => 0, OpportunityKeywordIndex::LINK_MEMBER => 1, OpportunityKeywordIndex::LINK_PAGE => 2];

	/** @var array{project: int, opportunities: list<array<string, mixed>>, members: array<string, array<int, string>>, variants: array<string, string>}|null bieżące przeliczenie */
	private ?array $cache = null;

	public function __construct(
		private readonly Connection $db,
		private readonly OpportunityKeywordIndex $index,
		private readonly MarketKeywordLookup $lookup,
	) {
	}

	public function source(): StrategySource
	{
		return StrategySource::Opportunity;
	}

	public function fingerprint(SourceScope $scope): string
	{
		$row = $this->db->fetchRow(
			"SELECT COUNT(*) AS n, MAX(updated_at) AS u, COALESCE(SUM(CRC32(CONCAT_WS(':', id, state, status))), 0) AS c
			FROM `{$this->db->table('opportunities')}` WHERE project_id = %d",
			[$scope->projectId],
		) ?? [];
		$analysis = $this->db->fetchRow(
			"SELECT data_key, analyzed_at FROM `{$this->db->table('opportunity_analyses')}` WHERE project_id = %d AND period_days = %d",
			[$scope->projectId, OpportunityConfig::CANONICAL_DAYS],
		) ?? [];

		return implode(':', [$row['n'] ?? 0, $row['u'] ?? '', $row['c'] ?? 0, $analysis['data_key'] ?? '', $analysis['analyzed_at'] ?? '']);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		$this->cache = null;
		$state = $this->state($scope);
		$weights = [];

		foreach ($state['members'] as $hex => $links) {
			foreach ($links as $index => $link) {
				$opportunity = $state['opportunities'][$index];

				if ($opportunity['status'] !== 'dismissed') {
					$weights[$hex] = max($weights[$hex] ?? 0.0, (float) $opportunity['priority']);
				}
			}
		}

		$market = $this->lookup->byKeys($scope->market, array_map('strval', array_keys($weights)));
		$signals = [];

		foreach ($weights as $hex => $weight) {
			// Fraza GSC bez wiersza rynkowego dostaje go przy zapisie (jak w źródle GSC) — w podglądzie bez zapisu.
			$row = $market[(string) $hex] ?? null;
			$signals[] = new SourceSignal(
				(string) $hex,
				$row['id'] ?? null,
				$row['keyword'] ?? MarketKeyword::normalize($state['variants'][(string) $hex]),
				$row['intent'] ?? null,
				StrategySource::Opportunity,
				SourceSignal::TIER_DECISION,
				$weight,
			);
		}

		return new SignalBatch($signals);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		$state = $this->state($scope);
		$byPage = [];

		foreach ($state['opportunities'] as $index => $opportunity) {
			foreach ($opportunity['urls'] as $url) {
				$byPage[bin2hex(UrlKey::hash((string) $url))][] = $index;
			}
		}

		$result = [];

		foreach ($keys as $id => $hex) {
			$links = $state['members'][(string) $hex] ?? [];

			foreach ((array) ($collected[$id]['gsc']['pages'] ?? []) as $page) {
				foreach ($byPage[bin2hex(UrlKey::hash((string) ($page['url'] ?? '')))] ?? [] as $index) {
					$links[$index] ??= OpportunityKeywordIndex::LINK_PAGE;
				}
			}

			if ($links === []) {
				continue;
			}

			$items = ['direct' => [], 'context' => []];

			foreach ($links as $index => $link) {
				$opportunity = $state['opportunities'][$index];
				$items[OpportunityKeywordIndex::isDirect($link) ? 'direct' : 'context'][] = [
					'id' => $opportunity['public_id'],
					'type' => $opportunity['type'],
					'status' => $opportunity['status'],
					'priority' => $opportunity['priority'],
					'confidence' => $opportunity['confidence'],
					'page' => $opportunity['page_url'],
					'link' => $link,
					'members_complete' => $opportunity['members_complete'],
				];
			}

			$items['direct_open'] = count(array_filter($items['direct'], static fn (array $item): bool => $item['status'] !== 'dismissed'));

			foreach (['direct', 'context'] as $group) {
				$list = $items[$group];
				usort($list, static fn (array $a, array $b): int => [self::LINK_ORDER[$a['link']], $b['priority'], $a['id']] <=> [self::LINK_ORDER[$b['link']], $a['priority'], $b['id']]);
				$items[$group] = array_slice($list, 0, StrategyConfig::EVIDENCE_OPPORTUNITIES);
				$items[$group . '_total'] = count($list);
			}

			$result[(int) $id] = $items;
		}

		return $result;
	}

	/**
	 * Szanse projektu (aktywne, wykrycia 28 dni) i członkowie grup: klucz rynkowy frazy → indeks szansy → rodzaj powiązania
	 * (oraz wariant zapisu frazy GSC dla klucza).
	 *
	 * @return array{project: int, opportunities: list<array<string, mixed>>, members: array<string, array<int, string>>, variants: array<string, string>}
	 */
	private function state(SourceScope $scope): array
	{
		if ($this->cache !== null && $this->cache['project'] === $scope->projectId) {
			return $this->cache;
		}

		$opportunities = $this->index->opportunities($scope->projectId);
		$byText = [];

		foreach ($opportunities as $index => $opportunity) {
			foreach ($opportunity['members'] as $text) {
				$link = $opportunity['keyword'] !== null && $opportunity['keyword'] === $text && $opportunity['page_url'] === null
					? OpportunityKeywordIndex::LINK_KEYWORD
					: OpportunityKeywordIndex::LINK_MEMBER;
				$byText[md5((string) $text)][$index] = $link;
			}
		}

		$members = [];
		$variants = [];

		foreach (array_chunk(array_keys($byText), self::CHUNK) as $chunk) {
			foreach ($this->db->fetchAll(
				"SELECT LOWER(HEX(keyword_hash)) AS kh, LOWER(HEX(market_key)) AS h, keyword FROM `{$this->db->table('keywords')}`
				WHERE project_id = %d AND market_key IS NOT NULL AND keyword_hash IN (" . Connection::placeholders($chunk, 'UNHEX(%s)') . ')',
				[$scope->projectId, ...array_map('strval', $chunk)],
			) as $row) {
				$variants[(string) $row['h']] ??= (string) $row['keyword'];

				foreach ($byText[(string) $row['kh']] ?? [] as $index => $link) {
					$current = $members[(string) $row['h']][$index] ?? null;

					if ($current === null || self::LINK_ORDER[$link] < self::LINK_ORDER[$current]) {
						$members[(string) $row['h']][$index] = $link;
					}
				}
			}
		}

		return $this->cache = ['project' => $scope->projectId, 'opportunities' => $opportunities, 'members' => $members, 'variants' => $variants];
	}
}
