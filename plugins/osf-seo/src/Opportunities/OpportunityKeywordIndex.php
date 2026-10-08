<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Database\Connection;

/**
 * Powiązanie szans SEO z frazami na podstawie danych query × page (docs/ARCHITECTURE.md, sekcja 15.5, D54).
 *
 * Szanse są w większości per podstrona (odcisk typ × strona), a `opportunities.keyword` jest wypełnione tylko dla szans bez danych
 * query × page — sama ta kolumna nie wystarcza do powiązania. Rodzaje powiązania:
 *
 * - **bezpośrednie** (dowód wykrycia — szansa dotyczy tej frazy):
 *   - `keyword` — szansa na poziomie frazy (dokładny tekst frazy GSC),
 *   - `member` — fraza należy do grupy szansy: detektor przypisał ją do strony docelowej szansy (albo do pary adresów przy kanibalizacji);
 *     lista fraz grupy pochodzi z tekstu wyszukiwania wykrycia (`opportunity_detections.search_text`),
 * - **kontekstowe** (nie jest dowodem, że szansa dotyczy tej frazy):
 *   - `page` — fraza ma wyświetlenia w GSC (query × page) na podstronie szansy (adres bez `#fragmentu`, jak w `UrlKey`).
 *
 * Ograniczenie: tekst wyszukiwania jest przycinany do 10 000 znaków (`OpportunityDetector::searchText`), a dowody wykrycia (`evidence.keywords`)
 * zawierają tylko kilkadziesiąt fraz. Przy przyciętym tekście lista fraz grupy jest niepełna (`members_complete = false`): brak powiązania
 * bezpośredniego nie oznacza wtedy, że fraza nie należy do grupy, a ostatnia (możliwie ucięta) linia nie jest dopasowywana.
 *
 * Wyłącznie odczyt; bez agregacji historii GSC (strony frazy przekazuje wywołujący).
 */
final class OpportunityKeywordIndex
{
	public const LINK_KEYWORD = 'keyword';

	public const LINK_MEMBER = 'member';

	public const LINK_PAGE = 'page';

	/** Powiązania bezpośrednie — dowód wykrycia, że szansa dotyczy frazy. */
	public const DIRECT_LINKS = [self::LINK_KEYWORD, self::LINK_MEMBER];

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
	 * @return list<array{id: int, public_id: string, type: string, status: string, priority: int, confidence: int, page_url: ?string, page_hash: ?string, keyword: ?string, urls: list<string>, members: list<string>, members_complete: bool}>
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
			$searchText = (string) $row['search_text'];
			[$urls, $members] = self::split((string) $row['type'], $row['page_url'], $row['keyword'], $searchText);
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
				'members_complete' => ! self::isTruncated($searchText),
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
		$lines = explode("\n", $searchText);

		if (self::isTruncated($searchText)) {
			// Ostatnia linia przyciętego tekstu może być uciętą frazą („sklep” z „sklep internetowy”) — nie jest członkiem grupy.
			array_pop($lines);
		}

		$lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
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

		return [$urls, array_values(array_unique($lines))];
	}

	/** Tekst wyszukiwania osiągnął limit — lista fraz grupy może być niepełna, a ostatnia linia ucięta. */
	public static function isTruncated(string $searchText): bool
	{
		return mb_strlen($searchText, 'UTF-8') >= self::SEARCH_TEXT_LIMIT;
	}

	public static function isDirect(string $link): bool
	{
		return in_array($link, self::DIRECT_LINKS, true);
	}

	/**
	 * Szanse powiązane z jedną frazą (np. szczegóły luki): warianty zapisu frazy (dokładne teksty GSC) i adresy stron,
	 * na których fraza ma wyświetlenia w GSC. Tylko szanse aktywne; najpierw powiązania bezpośrednie, potem od najwyższego priorytetu.
	 * `members_complete = false` — lista fraz grupy w wykryciu jest przycięta (powiązanie kontekstowe może być w rzeczywistości bezpośrednie).
	 *
	 * @param list<string> $variants
	 * @param list<string> $pageUrls
	 * @return list<array{public_id: string, type: string, status: string, last_priority: ?string, page_url: ?string, link: string, direct: bool, members_complete: bool}>
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

			// Przycięty tekst: bez końcowego znaku nowej linii ostatnia (możliwie ucięta) linia nie pasuje do żadnej frazy.
			$haystack = "CAST(CONCAT('\\n', d.search_text, IF(CHAR_LENGTH(d.search_text) >= " . self::SEARCH_TEXT_LIMIT . ", '', '\\n')) AS BINARY)";

			foreach ($variants as $variant) {
				$member[] = "LOCATE(CAST(CONCAT('\\n', %s, '\\n') AS BINARY), {$haystack}) > 0";
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

		$truncated = "EXISTS (SELECT 1 FROM `{$this->db->table('opportunity_detections')}` t WHERE t.opportunity_id = o.id AND t.project_id = o.project_id
			AND CHAR_LENGTH(t.search_text) >= " . self::SEARCH_TEXT_LIMIT . ')';
		$rows = $this->db->fetchAll(
			'SELECT x.* FROM (SELECT o.id, o.public_id, o.type, o.status, o.last_priority, o.page_url, ' . implode(', ', $select) . ", {$truncated} AS truncated
				FROM `{$this->db->table('opportunities')}` o
				WHERE o.project_id = %d AND o.state = 'active' AND (" . implode(' OR ', $conditions) . ')) x
			ORDER BY (x.by_keyword = 1 OR x.by_member = 1) DESC, x.last_priority DESC, x.id LIMIT %d',
			[...$params, $projectId, ...$whereParams, max(1, $limit)],
		);

		return array_map(static function (array $row): array {
			$link = match (true) {
				(int) $row['by_keyword'] === 1 => self::LINK_KEYWORD,
				(int) $row['by_member'] === 1 => self::LINK_MEMBER,
				default => self::LINK_PAGE,
			};

			return [
				'public_id' => (string) $row['public_id'],
				'type' => (string) $row['type'],
				'status' => (string) $row['status'],
				'last_priority' => $row['last_priority'],
				'page_url' => $row['page_url'],
				'link' => $link,
				'direct' => self::isDirect($link),
				'members_complete' => (int) $row['truncated'] === 0,
			];
		}, $rows);
	}

	public static function linkLabel(string $link): string
	{
		return match ($link) {
			self::LINK_KEYWORD => 'ta sama fraza',
			self::LINK_MEMBER => 'fraza w grupie szansy',
			self::LINK_PAGE => 'ta sama podstrona — kontekst',
			default => $link,
		};
	}
}
