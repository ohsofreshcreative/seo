<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Materializacja faktów i dowodów kandydatów (docs/ARCHITECTURE.md, sekcja 15.3): każde źródło dokłada swój dowód dla wszystkich
 * kandydatów (także tych, którzy weszli innym źródłem), najpierw GSC (strony frazy są potrzebne do powiązań szans SEO).
 *
 * Dowód źródła może zawierać klucz `_facts` z wewnętrznymi identyfikatorami do kolumn faktów — nie trafia on do JSON-a dowodów
 * (w dowodach wyłącznie publiczne identyfikatory ULID i wartości).
 */
final class EvidenceBuilder
{
	/**
	 * 2 — dowody szans SEO rozdzielone na powiązania bezpośrednie i kontekstowe; 3 — SERP Intelligence (`serp.intel`); 4 — poprzedni adres
	 * projektu w SERP, adresy projektu w TOP10, korekta pisowni, adresy pary kanibalizacji (faza C); 5 — chwila zgodnego pomiaru w kolumnie
	 * faktów `serp_intel_at` (faza D).
	 */
	public const VERSION = 5;

	/** Kolejność źródeł przy zbieraniu dowodów (GSC pierwsze). */
	private const ORDER = ['gsc', 'manual', 'serp', 'opportunity', 'discovery', 'gap', 'content_gap'];

	/** Dowód GSC frazy bez wyświetleń w oknie (projekt ma dane GSC). */
	public const EMPTY_GSC = ['impressions' => 0, 'clicks' => 0, 'position' => null, 'variants' => 0, 'pages' => [], 'pages_total' => 0];

	/**
	 * @param list<CandidateSource> $sources
	 */
	public function __construct(private readonly array $sources)
	{
	}

	/**
	 * @param list<Candidate> $candidates kandydaci z identyfikatorem frazy rynkowej
	 * @return list<KeywordFacts>
	 */
	public function build(SourceScope $scope, array $candidates): array
	{
		$keys = [];
		$byId = [];

		foreach ($candidates as $candidate) {
			if ($candidate->marketKeywordId === null) {
				continue;
			}

			$keys[$candidate->marketKeywordId] = $candidate->keyHex;
			$byId[$candidate->marketKeywordId] = $candidate;
		}

		$collected = [];

		foreach (self::ordered($this->sources) as $source) {
			if ($keys === []) {
				break;
			}

			$code = $source->source()->value;

			foreach ($source->evidence($scope, $keys, $collected) as $id => $evidence) {
				if (isset($keys[$id])) {
					$collected[$id][$code] = $evidence;
				}
			}
		}

		$result = [];

		foreach ($byId as $id => $candidate) {
			$result[] = $this->facts($scope, $candidate, $collected[$id] ?? []);
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $sections kod źródła → dowód
	 */
	private function facts(SourceScope $scope, Candidate $candidate, array $sections): KeywordFacts
	{
		$gsc = $scope->hasGsc() ? (is_array($sections['gsc'] ?? null) ? $sections['gsc'] : self::EMPTY_GSC) : null;
		$serp = is_array($sections['serp'] ?? null) ? $sections['serp'] : null;
		$gap = is_array($sections['gap'] ?? null) ? $sections['gap'] : null;
		$discovery = is_array($sections['discovery'] ?? null) ? $sections['discovery'] : null;
		$contentGap = is_array($sections['content_gap'] ?? null) ? $sections['content_gap'] : null;
		// Fakt `opportunities` = nieodrzucone szanse powiązane bezpośrednio (kontekst tej samej podstrony nie jest dowodem dla frazy).
		$opportunities = (int) ($sections['opportunity']['direct_open'] ?? 0);
		$topPage = $gsc['pages'][0] ?? null;
		$evidence = ['v' => self::VERSION, 'keyword' => $candidate->keyword, 'sources' => $candidate->sourceCodes()];

		if ($scope->hasGsc()) {
			$evidence['gsc'] = self::strip($gsc);
		} else {
			$evidence['gsc'] = null;
		}

		foreach (self::ORDER as $code) {
			if ($code !== 'gsc' && isset($sections[$code]) && $sections[$code] !== [] && $sections[$code] !== null) {
				$evidence[$code] = is_array($sections[$code]) ? self::strip($sections[$code]) : $sections[$code];
			}
		}

		return new KeywordFacts(
			marketKeywordId: (int) $candidate->marketKeywordId,
			sources: $candidate->sources,
			tier: $candidate->tier,
			gscImpressions: $gsc === null ? null : (int) $gsc['impressions'],
			gscClicks: $gsc === null ? null : (int) $gsc['clicks'],
			gscPosition: $gsc === null || $gsc['position'] === null ? null : (float) $gsc['position'],
			gscPages: $gsc === null ? null : min(255, (int) $gsc['pages_total']),
			gscTopUrlId: isset($gsc['_facts']['top_url_id']) ? (int) $gsc['_facts']['top_url_id'] : null,
			gscTopShare: is_array($topPage) && isset($topPage['share']) ? (float) $topPage['share'] : null,
			trackedKeywordId: isset($serp['_facts']['tracked_keyword_id']) ? (int) $serp['_facts']['tracked_keyword_id'] : null,
			serpCheckedAt: isset($serp['checked_at']) ? (string) $serp['checked_at'] : null,
			serpFound: isset($serp['checked_at'], $serp['found']) ? (bool) $serp['found'] : null,
			serpRank: isset($serp['checked_at'], $serp['rank']) ? (int) $serp['rank'] : null,
			serpUrlId: isset($serp['checked_at'], $serp['_facts']['url_id']) ? (int) $serp['_facts']['url_id'] : null,
			gapKeywordId: isset($gap['_facts']['gap_keyword_id']) ? (int) $gap['_facts']['gap_keyword_id'] : null,
			gapClusterId: isset($gap['_facts']['cluster_id']) ? (int) $gap['_facts']['cluster_id'] : (isset($contentGap['_facts']['cluster_id']) ? (int) $contentGap['_facts']['cluster_id'] : null),
			discoveryCandidateId: isset($discovery['_facts']['candidate_id']) ? (int) $discovery['_facts']['candidate_id'] : null,
			opportunities: min(255, $opportunities),
			evidence: $evidence,
			serpIntelAt: isset($serp['intel']['checked_at']) && is_string($serp['intel']['checked_at']) ? $serp['intel']['checked_at'] : null,
		);
	}

	/**
	 * Dowód bez wewnętrznych identyfikatorów (rekurencyjnie: także elementy list).
	 *
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function strip(array $value): array
	{
		unset($value['_facts']);

		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::strip($item);
			}
		}

		return $value;
	}

	/**
	 * @param list<CandidateSource> $sources
	 * @return list<CandidateSource>
	 */
	private static function ordered(array $sources): array
	{
		$position = array_flip(self::ORDER);
		usort($sources, static fn (CandidateSource $a, CandidateSource $b): int => ($position[$a->source()->value] ?? 99) <=> ($position[$b->source()->value] ?? 99));

		return $sources;
	}
}
