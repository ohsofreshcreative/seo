<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Analysis;

/**
 * Gotowość danych do analizy (D106) — wyłącznie z zapisanego stanu, bez żadnego żądania:
 *
 * - `ready` — dane wymagane przez typ analizy są dostępne i aktualne,
 * - `partial` — analiza możliwa w ograniczonym zakresie; każde ograniczenie jest jawne (`limitations`) i trafia do kontekstu,
 * - `insufficient` — nie da się przygotować użytecznej analizy (np. brak snapshotu strony) — żadne wywołanie modelu,
 * - `blocked` — typ niezgodny z działaniem Strategii albo temat nieaktywny — odmowa.
 *
 * Gotowość nigdy nie uruchamia pobierania stron, pomiarów SERP ani DataForSEO — `hints` wskazują jedynie jawne komendy do rozważenia.
 */
final class Readiness
{
	public const READY = 'ready';

	public const PARTIAL = 'partial';

	public const INSUFFICIENT = 'insufficient';

	public const BLOCKED = 'blocked';

	/** Znaczenie kodów (dla modelu i człowieka). */
	public const MEANINGS = [
		// Blokady.
		'no_strategy_decision' => 'The topic has no Strategy decision yet (refresh the Strategy first).',
		'topic_inactive' => 'The topic is inactive (merged or no longer selected).',
		'action_not_supported' => 'This analysis type contradicts the Strategy action of the topic.',
		'explicit_choice_required' => 'This analysis type requires an explicit user choice for this Strategy action.',
		// Brak danych.
		'target_page_unknown' => 'No known target page of the topic — a page optimization needs a known page.',
		'page_not_fetched' => 'The page has no stored snapshot — fetch it explicitly first (pages:fetch).',
		'page_fetch_failed' => 'The last fetch of the page failed and no earlier snapshot exists — NOT proof that the page does not exist.',
		'page_content_empty' => 'No readable text was extracted from the page snapshot.',
		'no_keywords' => 'The topic has no keywords.',
		'no_competitor_snapshots' => 'No usable competitor page snapshot linked to a SERP measurement of the topic keywords.',
		// Ograniczenia obniżające gotowość.
		'page_content_incomplete' => 'The page snapshot is incomplete (e.g. JavaScript rendering) — missing text is NOT proof that the page lacks it.',
		'page_content_partial' => 'Only part of the page content was extracted.',
		'page_snapshot_stale' => 'The page snapshot is older than the freshness window (24 h by default).',
		'page_last_fetch_failed' => 'The last fetch failed; the stored snapshot is from an earlier successful fetch and is not a current confirmation.',
		'intent_unknown' => 'No search intent signal (provider intent or SERP profile) — intent must be stated as uncertain.',
		'no_market_data' => 'No search volume for the topic keywords.',
		'serp_stale' => 'The SERP measurement is 31–90 days old — only limited interpretation of result structure.',
		'serp_expired' => 'The SERP measurement is older than 90 days — no current ranking conclusions.',
		'competitor_serp_stale' => 'Some competitor pages are linked to SERP measurements 31–90 days old.',
		'competitor_serp_expired' => 'Some competitor pages are linked to SERP measurements older than 90 days — no ranking conclusions from them.',
		'serp_and_page_dates_differ' => 'Competitor pages were fetched more than 7 days apart from the SERP measurement — ranking and content were not observed together.',
		'single_competitor' => 'Only one usable competitor page — repeated topics across competitors cannot be established.',
		'competitor_content_incomplete' => 'Some competitor snapshots are incomplete or unusable and were left out of the comparison.',
		'known_page_may_cover_topic' => 'A project page is already associated with the topic — a new page may duplicate it.',
		// Informacje (bez obniżenia gotowości).
		'page_index_incomplete' => 'The project page index is incomplete (pages known only from GSC) — other pages may exist.',
		'no_gsc_data' => 'No GSC data for the topic — unknown, not proof of no visibility.',
		'no_serp_measurement' => 'No SERP measurement for the topic keywords.',
		'no_competitor_pages' => 'No competitor page snapshots linked to the topic.',
		'candidate_page' => 'Brief for a "Kandydat na nową stronę" — not a confirmation that the site lacks such a page.',
		'missing_page_confirmed_manually' => 'A user confirmed manually that the site has no page for this topic.',
	];

	/**
	 * @param list<string> $constraints
	 * @param list<string> $reasons kody blokady albo braku danych (stan `blocked` / `insufficient`)
	 * @param list<string> $limitations kody obniżające gotowość do `partial`
	 * @param list<string> $notes informacje bez obniżenia gotowości
	 * @param array<string, bool> $requirements wymaganie → spełnione
	 * @param list<string> $hints jawne komendy do rozważenia (nigdy wykonywane automatycznie)
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $state,
		public readonly ?string $action,
		public readonly string $mode,
		public readonly array $constraints,
		public readonly array $reasons,
		public readonly array $limitations,
		public readonly array $notes,
		public readonly array $requirements,
		public readonly array $hints = [],
	) {
	}

	public function runnable(): bool
	{
		return $this->state === self::READY || $this->state === self::PARTIAL;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		$describe = static fn (array $codes): array => array_map(static fn (string $code): array => ['code' => $code, 'meaning' => self::MEANINGS[$code] ?? $code], $codes);

		return [
			'type' => $this->type,
			'type_label' => AnalysisType::label($this->type),
			'state' => $this->state,
			'strategy_action' => $this->action,
			'compatibility' => $this->mode,
			'constraints' => $this->constraints,
			'reasons' => $describe($this->reasons),
			'limitations' => $describe($this->limitations),
			'notes' => $describe($this->notes),
			'requirements' => $this->requirements,
			'hints' => $this->hints,
		];
	}
}
