<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use DateTimeImmutable;
use OsfSeo\Strategy\Serp\SerpFreshness;

/**
 * Sygnały aktywnego kandydata Strategii dla rdzenia (faza C): metadane frazy, metryki rynkowe i zmaterializowane dowody źródeł
 * (`strategy_keywords.evidence`, wersja 4). Czysta logika — świeżość pomiaru SERP liczona od nowa z `checked_at` (Pozycja SERP
 * projektu wyłącznie ze świeżego pomiaru ≤ 30 dni; 31–90 dni — tylko kształt i kompozycja; > 90 dni — bez wpływu).
 */
final class KeywordSignals
{
	/**
	 * @param array<string, mixed> $evidence dowody kandydata (bez wewnętrznych identyfikatorów)
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $marketKeywordId,
		public readonly string $keyword,
		public readonly ?string $coreKey,
		public readonly bool $manual,
		public readonly int $sources,
		public readonly ?int $tier,
		public readonly ?int $volume,
		public readonly ?int $difficulty,
		public readonly ?float $cpc,
		public readonly ?string $intent,
		public readonly ?int $topicId,
		public readonly ?int $pinnedTopicId,
		public readonly array $evidence,
		public readonly ?string $serpFreshness,
		public readonly ?string $factsHash = null,
	) {
	}

	/**
	 * @param array<string, mixed> $row wiersz `strategy_keywords` z metrykami `market_keywords`
	 */
	public static function fromRow(array $row, DateTimeImmutable $now): self
	{
		$evidence = json_decode((string) ($row['evidence'] ?? ''), true);
		$evidence = is_array($evidence) ? $evidence : [];
		$checked = $evidence['serp']['intel']['checked_at'] ?? null;

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['market_keyword_id'],
			(string) $row['keyword'],
			isset($row['core_key']) && $row['core_key'] !== '' ? (string) $row['core_key'] : null,
			(int) ($row['manual'] ?? 0) === 1,
			(int) ($row['sources'] ?? 0),
			isset($row['tier']) ? (int) $row['tier'] : null,
			isset($row['search_volume']) ? (int) $row['search_volume'] : null,
			isset($row['keyword_difficulty']) ? (int) $row['keyword_difficulty'] : null,
			isset($row['cpc']) ? (float) $row['cpc'] : null,
			isset($row['search_intent']) && $row['search_intent'] !== '' ? (string) $row['search_intent'] : null,
			isset($row['topic_id']) ? (int) $row['topic_id'] : null,
			isset($row['pinned_topic_id']) ? (int) $row['pinned_topic_id'] : null,
			$evidence,
			is_string($checked) ? SerpFreshness::of($checked, $now) : null,
			isset($row['facts_hash']) && $row['facts_hash'] !== '' ? (string) $row['facts_hash'] : null,
		);
	}

	// GSC

	/** Projekt ma dane GSC w oknie (dowód GSC null = brak danych projektu, zera = fraza bez wyświetleń). */
	public function gscKnown(): bool
	{
		return is_array($this->evidence['gsc'] ?? null);
	}

	public function gscImpressions(): ?int
	{
		return $this->gscKnown() ? (int) ($this->evidence['gsc']['impressions'] ?? 0) : null;
	}

	public function gscClicks(): ?int
	{
		return $this->gscKnown() ? (int) ($this->evidence['gsc']['clicks'] ?? 0) : null;
	}

	/** Średnia pozycja (GSC) — nigdy Pozycja SERP. */
	public function gscPosition(): ?float
	{
		$position = $this->evidence['gsc']['position'] ?? null;

		return $this->gscKnown() && is_numeric($position) ? (float) $position : null;
	}

	/**
	 * Strony GSC frazy (największy udział wyświetleń; udział liczony względem wszystkich stron frazy).
	 *
	 * @return list<array{url: string, impressions: int, clicks: int, share: ?float}>
	 */
	public function gscPages(): array
	{
		$pages = [];

		foreach ((array) ($this->evidence['gsc']['pages'] ?? []) as $page) {
			if (is_array($page) && is_string($page['url'] ?? null)) {
				$pages[] = [
					'url' => $page['url'],
					'impressions' => (int) ($page['impressions'] ?? 0),
					'clicks' => (int) ($page['clicks'] ?? 0),
					'share' => isset($page['share']) ? (float) $page['share'] : null,
				];
			}
		}

		return $pages;
	}

	// SERP (STEP 14 + SERP Intelligence)

	/** Zgodny pomiar nadaje się do klasyfikacji (≤ 90 dni). */
	public function serpUsable(): bool
	{
		return SerpFreshness::usableForClassification($this->serpFreshness);
	}

	public function serpFresh(): bool
	{
		return $this->serpFreshness === SerpFreshness::FRESH;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function intel(): ?array
	{
		$intel = $this->evidence['serp']['intel'] ?? null;

		return is_array($intel) ? $intel : null;
	}

	/** Projekt w świeżym zgodnym pomiarze (null — brak świeżego pomiaru). */
	public function serpFound(): ?bool
	{
		$project = $this->serpFresh() ? ($this->intel()['project'] ?? null) : null;

		return is_array($project) ? (bool) ($project['found'] ?? false) : null;
	}

	/** Pozycja SERP projektu — wyłącznie ze świeżego pomiaru. */
	public function serpRank(): ?int
	{
		$project = $this->serpFresh() ? ($this->intel()['project'] ?? null) : null;

		return is_array($project) && isset($project['rank']) ? (int) $project['rank'] : null;
	}

	public function serpUrl(): ?string
	{
		$project = $this->serpFresh() ? ($this->intel()['project'] ?? null) : null;

		return is_array($project) && is_string($project['url'] ?? null) ? $project['url'] : null;
	}

	/**
	 * Adresy projektu w TOP10 świeżego pomiaru (najlepsza pozycja każdego adresu).
	 *
	 * @return list<array{url: string, rank: int}>
	 */
	public function serpTop10(): array
	{
		$project = $this->serpFresh() ? ($this->intel()['project'] ?? null) : null;
		$result = [];

		foreach ((array) (is_array($project) ? ($project['top10'] ?? []) : []) as $item) {
			if (is_array($item) && is_string($item['url'] ?? null)) {
				$result[] = ['url' => $item['url'], 'rank' => (int) ($item['rank'] ?? 0)];
			}
		}

		return $result;
	}

	/**
	 * Zmiana względem poprzedniego porównywalnego pomiaru — tylko gdy ostatni pomiar monitorowania jest tym samym, świeżym zgodnym
	 * pomiarem (inny kontekst albo pomiar nieaktualny → brak porównania w Strategii).
	 *
	 * @return array{change: ?string, value: ?int, prev_rank: ?int, prev_url: ?string, decline: bool, bands: array<string, ?string>}|null
	 */
	public function serpChange(): ?array
	{
		$serp = $this->evidence['serp'] ?? null;
		$intel = $this->intel();

		if (! $this->serpFresh() || ! is_array($serp) || $intel === null || ($serp['checked_at'] ?? null) === null || $serp['checked_at'] !== ($intel['checked_at'] ?? null)) {
			return null;
		}

		return [
			'change' => is_string($serp['change'] ?? null) ? $serp['change'] : null,
			'value' => isset($serp['change_value']) ? (int) $serp['change_value'] : null,
			'prev_rank' => isset($serp['prev_rank']) ? (int) $serp['prev_rank'] : null,
			'prev_url' => is_string($serp['prev_url'] ?? null) ? $serp['prev_url'] : null,
			'decline' => (bool) ($serp['decline'] ?? false),
			'bands' => array_map(static fn (mixed $band): ?string => is_string($band) ? $band : null, (array) ($serp['bands'] ?? [])),
		];
	}

	/**
	 * Ślady istniejącej strony projektu w pomiarach starszych niż świeże albo w poprzednim pomiarze (nie Pozycja SERP — tylko wskazówka,
	 * że strona może istnieć).
	 *
	 * @return list<array{url: string, source: string}>
	 */
	public function serpHistoricUrls(): array
	{
		$serp = $this->evidence['serp'] ?? null;
		$result = [];

		if (! is_array($serp)) {
			return [];
		}

		if (is_string($serp['url'] ?? null) && ! ($this->serpFresh() && $this->serpChange() !== null)) {
			$result[] = ['url' => $serp['url'], 'source' => 'serp_history'];
		}

		if (is_string($serp['prev_url'] ?? null)) {
			$result[] = ['url' => $serp['prev_url'], 'source' => 'serp_previous'];
		}

		return $result;
	}

	/**
	 * Profil zgodnego pomiaru (kształt, kompozycja, sygnał intencji — pewności już obniżone dla nieaktualnego pomiaru).
	 *
	 * @return array<string, mixed>|null
	 */
	public function serpProfile(): ?array
	{
		$profile = $this->serpUsable() ? ($this->intel()['profile'] ?? null) : null;

		return is_array($profile) ? $profile : null;
	}

	/** Konkurenci (aktywni) w TOP10 zgodnego pomiaru nadającego się do klasyfikacji. */
	public function serpCompetitorsTop10(): ?int
	{
		$competitors = $this->serpUsable() ? ($this->intel()['competitors'] ?? null) : null;

		return is_array($competitors) ? (int) ($competitors['top10'] ?? 0) : null;
	}

	/** Korekta pisowni wyszukiwarki w zgodnym pomiarze (np. `showing_results_for`). */
	public function serpSpell(): ?string
	{
		$spell = $this->serpUsable() ? ($this->intel()['spell'] ?? null) : null;

		return is_string($spell) ? $spell : null;
	}

	public function serpCheckedAt(): ?string
	{
		$checked = $this->intel()['checked_at'] ?? null;

		return is_string($checked) ? $checked : null;
	}

	public function serpSnapshot(): ?string
	{
		$snapshot = $this->intel()['snapshot'] ?? null;

		return is_string($snapshot) ? $snapshot : null;
	}

	// Szanse SEO, Nowe frazy, Luki

	/**
	 * Nieodrzucone szanse SEO powiązane bezpośrednio z frazą (kontekst wspólnej podstrony pomijany — D54).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function opportunities(): array
	{
		return array_values(array_filter(
			(array) ($this->evidence['opportunity']['direct'] ?? []),
			static fn (mixed $item): bool => is_array($item) && ($item['status'] ?? '') !== 'dismissed',
		));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function discovery(): ?array
	{
		$discovery = $this->evidence['discovery'] ?? null;

		return is_array($discovery) ? $discovery : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function gap(): ?array
	{
		$gap = $this->evidence['gap'] ?? null;

		return is_array($gap) ? $gap : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function contentGap(): ?array
	{
		$contentGap = $this->evidence['content_gap'] ?? null;

		return is_array($contentGap) ? $contentGap : null;
	}

	/** Identyfikator grupy luk (publiczny) — wyłącznie sygnał pomocniczy grupowania. */
	public function gapClusterId(): ?string
	{
		$id = $this->contentGap()['id'] ?? null;

		return is_string($id) ? $id : null;
	}

	/** Źródła, z których kandydat pochodzi (kody). */
	public function sourceCodes(): array
	{
		return array_values(array_map('strval', (array) ($this->evidence['sources'] ?? [])));
	}
}
