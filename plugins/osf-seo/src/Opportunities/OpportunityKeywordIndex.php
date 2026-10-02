<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Database\Connection;

/**
 * Powiązanie szans SEO z frazami na podstawie danych query × page (docs/ARCHITECTURE.md, sekcja 15.5, D54).
 *
 * Szanse są w większości per podstrona (odcisk typ × strona), a `opportunities.keyword` jest wypełnione tylko dla szans bez danych
 * query × page — sama ta kolumna nie wystarcza do powiązania. Rodzaje powiązania (od najsilniejszego):
 *
 * - `keyword` — szansa na poziomie frazy (dokładny tekst frazy GSC),
 * - `member` — fraza należy do grupy szansy: detektor przypisał ją do strony docelowej szansy (albo do pary adresów przy kanibalizacji);
 *   pełna lista fraz grupy pochodzi z tekstu wyszukiwania wykrycia (`opportunity_detections.search_text`, do 10 000 znaków),
 * - `page` — fraza ma wyświetlenia w GSC (query × page) na podstronie szansy (adres bez `#fragmentu`, jak w `UrlKey`).
 *
 * Wyłącznie odczyt; bez agregacji historii GSC (strony frazy przekazuje wywołujący).
 */
final class OpportunityKeywordIndex
{
	public const LINK_KEYWORD = 'keyword';

	public const LINK_MEMBER = 'member';

	public const LINK_PAGE = 'page';

	/** Tekst wyszukiwania wykrycia jest przycinany do tylu znaków (OpportunityDetector::searchText). */
	public const SEARCH_TEXT_LIMIT = 10000;

	/** Liczba adresów na początku tekstu wyszukiwania szansy „Możliwa kanibalizacja” (para adresów). */
	private const PAIR_URLS = 2;

	private const CHUNK = 500;

	public function __construct(private readonly Connection $db)
	{
	}

	/**
	 * Aktywne szanse projektu wykryte w okresie (domyślnie 28 dni) z frazami grupy (dokładne teksty GSC) i adresami.
	 * Szanse odrzucone są zwracane z `status = dismissed` — o pominięciu decyduje wywołujący.
	 *
	 * @return list<array{id: int, public_id: string, type: string, status: string, priority: int, confidence: int, page_url: ?string, page_hash: ?string, keyword: ?string, urls: list<string>, members: list<string>, truncated: bool}>
	 */
	public function opportunities(int $projectId, int $periodDays = OpportunityConfig::CANONICAL_DAYS): array
	{
		$result = [];

		foreach ($this->db->fetchAll(
			"SELECT o.id, o.public_id, o.type, o.status, o.page_url, LOWER(HEX(o.page_hash)) AS page_hash, o.keyword, d.priority, d.confidence, d.search_text
			FROM `{$this->db->table('opportunity_detections')}` d
			JOIN `{$this->db->table('opportunities')}` o ON o.id = d.opportunity_id AND o.project_id = d.project_id
			WHERE d.project_id = %d AND d.period_days = %d AND o.state = 'active'
			ORDER BY d.priority DESC, o.id",
			[$projectId, $periodDays],
		) as $row) {
			[$urls, $members] = self::split((string) $row['type'], $row['page_url'], $row['keyword'], (string) $row['search_text']);
			$result[] = [
				'id' => (int) $row['id'],
				'public_id' => (string) $row['public_id'],
				'type' => (string) $row['type'],
				'status' => (string) $row['status'],
				'priority' => (int) $row['priority'],
				'confidence' => (int) $row['confidence'],
				'page_url' => $row['page_url'],
				'page_hash' => $row['page_hash'],
				'keyword' => $row['keyword'],
				'urls' => $urls,
				'members' => $members,
				'truncated' => mb_strlen((string) $row['search_text'], 'UTF-8') >= self::SEARCH_TEXT_LIMIT,
			];
		}

		return $result;
	}

	/**
	 * Adresy szansy i frazy jej grupy z tekstu wyszukiwania wykrycia (`[strona?, fraza?, …frazy]` albo `[adres 1, adres 2, …frazy]`
	 * dla kanibalizacji — OpportunityDetector::searchText).
	 *
	 * @return array{0: list<string>, 1: list<string>}
	 */
	public static function split(string $type, ?string $pageUrl, ?string $keyword, string $searchText): array
	{
		$lines = array_values(array_filter(explode("\n", $searchText), static fn (string $line): bool => $line !== ''));
		$urls = [];

		if ($type === OpportunityType::Cannibalization->value) {
			while (count($urls) < self::PAIR_URLS && isset($lines[0]) && preg_match('#^https?://#i', $lines[0]) === 1) {
				$urls[] = (string) array_shift($lines);
			}
		} elseif ($pageUrl !== null && $pageUrl !== '') {
			$urls[] = $pageUrl;
			$lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== $pageUrl));
		}

		if ($keyword !== null && $keyword !== '' && ! in_array($keyword, $lines, true)) {
			$lines[] = $keyword;
		}

		// Ostatnia linia przyciętego tekstu może być niepełną frazą — nie dopasuje się do słownika GSC (bezpieczne).
		return [$urls, array_values(array_unique($lines))];
	}

	/**
	 * Szanse powiązane z jedną frazą (np. szczegóły luki): warianty zapisu frazy (dokładne teksty GSC) i adresy stron,
	 * na których fraza ma wyświetlenia w GSC. Tylko szanse aktywne; od najwyższego priorytetu.
	 *
	 * @param list<string> $variants
	 * @param list<string> $pageUrls
	 * @return list<array{public_id: string, type: string, status: string, last_priority: ?string, page_url: ?string, link: string}>
	 */
	public function forKeyword(int $projectId, array $variants, array $pageUrls, int $limit = 10): array
	{
		$variants = array_values(array_unique(array_filter(array_map('strval', $variants), static fn (string $text): bool => $text !== '')));
		$hashes = array_values(array_unique(array_map(static fn (string $url): string => bin2hex(UrlKey::hash($url)), array_map('strval', $pageUrls))));

		if ($variants === [] && $hashes === []) {
			return [];
		}

		$conditions = [];
		$select = [];
		$params = [];
		$whereParams = [];

		if ($variants !== []) {
			$keywordIn = 'o.keyword IN (' . Connection::placeholders($variants) . ')';
			$member = [];

			foreach ($variants as $variant) {
				$member[] = "LOCATE(CAST(CONCAT('\\n', %s, '\\n') AS BINARY), CAST(CONCAT('\\n', d.search_text, '\\n') AS BINARY)) > 0";
			}

			$memberExists = "EXISTS (SELECT 1 FROM `{$this->db->table('opportunity_detections')}` d WHERE d.opportunity_id = o.id AND d.project_id = o.project_id AND ("
				. implode(' OR ', $member) . '))';
			$select[] = "{$keywordIn} AS by_keyword";
			$select[] = "{$memberExists} AS by_member";
			$params = [...$params, ...$variants, ...$variants];
			$conditions[] = $keywordIn;
			$conditions[] = $memberExists;
			$whereParams = [...$whereParams, ...$variants, ...$variants];
		} else {
			$select[] = '0 AS by_keyword';
			$select[] = '0 AS by_member';
		}

		if ($hashes !== []) {
			$pageIn = 'o.page_hash IN (' . Connection::placeholders($hashes, 'UNHEX(%s)') . ')';
			$select[] = "{$pageIn} AS by_page";
			$params = [...$params, ...$hashes];
			$conditions[] = $pageIn;
			$whereParams = [...$whereParams, ...$hashes];
		} else {
			$select[] = '0 AS by_page';
		}

		$rows = $this->db->fetchAll(
			'SELECT o.public_id, o.type, o.status, o.last_priority, o.page_url, ' . implode(', ', $select) . "
			FROM `{$this->db->table('opportunities')}` o
			WHERE o.project_id = %d AND o.state = 'active' AND (" . implode(' OR ', $conditions) . ')
			ORDER BY o.last_priority DESC, o.id LIMIT %d',
			[...$params, $projectId, ...$whereParams, max(1, $limit)],
		);

		return array_map(static fn (array $row): array => [
			'public_id' => (string) $row['public_id'],
			'type' => (string) $row['type'],
			'status' => (string) $row['status'],
			'last_priority' => $row['last_priority'],
			'page_url' => $row['page_url'],
			'link' => match (true) {
				(int) $row['by_keyword'] === 1 => self::LINK_KEYWORD,
				(int) $row['by_member'] === 1 => self::LINK_MEMBER,
				default => self::LINK_PAGE,
			},
		], $rows);
	}

	public static function linkLabel(string $link): string
	{
		return match ($link) {
			self::LINK_KEYWORD => 'ta sama fraza',
			self::LINK_MEMBER => 'fraza w grupie szansy',
			self::LINK_PAGE => 'ta sama podstrona',
			default => $link,
		};
	}
}
