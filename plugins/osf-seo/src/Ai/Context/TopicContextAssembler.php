<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Ai\Page\PageSnapshot;

/**
 * Deterministyczny kontekst AI tematu Strategii (wersja 1 — docs/ARCHITECTURE.md, sekcja 22.4) zbudowany WYŁĄCZNIE z pakietu kontekstu
 * STEP 16 (`TopicContextBuilder`), SERP Intelligence tematu i aktualności źródeł — bez nowych reguł Strategii i bez żadnego żądania.
 *
 * - Proweniencja każdej sekcji dowodów: źródło, rodzaj (`fact` / `third_party_estimate` / `heuristic`), data, świeżość, wiarygodność;
 *   pozycje rozdzielone nazwami: `average_position_gsc` (GSC), `serp_rank_group` (pomiar SERP), `rank_labs` (baza DataForSEO Labs).
 * - Odwołania (`refs`) do dowodów, na które odpowiedź modelu musi się powoływać (walidowane po stronie PHP).
 * - Braki danych jawnie (`data_gaps`) — w tym zawsze: treść strony niepobrana, indeks stron niepełny („brak znanej strony” ≠ brak strony).
 * - Budżet: limity elementów i długości tekstów, a ponad MAX_BYTES — redukcja całych elementów w kolejności od najmniej ważnych
 *   (uzupełniające → luki → SERP → frazy; decyzja i główne dowody zostają), z licznikami pominięć. JSON nigdy nie jest ucinany.
 * - Treści zewnętrzne o dowolnej treści (tytuły wyników SERP) w osobnej sekcji `external_texts` (blok niezaufany w instrukcjach);
 *   frazy, adresy, domeny i nazwy wskazane w `untrusted`.
 * - Bez notatek wewnętrznych, nazwy projektu, identyfikatorów użytkowników i danych innych projektów.
 *
 * Odcisk (`AiContext::fingerprint`) = SHA-256 kanonicznego JSON-u treści — bez czasu budowania; zmiana dowodów zmienia odcisk.
 */
final class TopicContextAssembler
{
	public const SCHEMA = 'whack-a-mole/ai-topic-context';

	public const VERSION = 1;

	public const MAX_BYTES = 32000;

	/** Limity elementów sekcji (przed redukcją). */
	public const LIMITS = [
		'keywords' => 15,
		'rule_checks' => 8,
		'serp_results' => 10,
		'serp_overlap' => 8,
		'labs_gaps' => 10,
		'content_gaps' => 5,
		'opportunities' => 10,
		'discovery' => 10,
		'votes' => 6,
		'alternatives' => 4,
		'conflicts' => 5,
	];

	/** Kroki redukcji ponad MAX_BYTES — od najmniej ważnych (kolejność: decyzja → główne dowody → frazy → SERP → luki → uzupełniające). */
	private const REDUCTIONS = [
		'discovery_3' => ['discovery' => 3],
		'opportunities_3' => ['opportunities' => 3],
		'content_gaps_2' => ['content_gaps' => 2],
		'supplementary_0' => ['discovery' => 0, 'opportunities' => 0],
		'labs_gaps_5' => ['labs_gaps' => 5],
		'serp_overlap_3' => ['serp_overlap' => 3],
		'serp_results_5' => ['serp_results' => 5],
		'keywords_8' => ['keywords' => 8],
		'target_details' => ['votes' => 3, 'alternatives' => 2, 'rule_checks' => 4],
		'gaps_minimal' => ['labs_gaps' => 2, 'content_gaps' => 0, 'serp_overlap' => 0],
		'keywords_5' => ['keywords' => 5],
	];

	private const TEXT = ['keyword' => 120, 'label' => 160, 'title' => 160, 'url' => 300, 'domain' => 100, 'name' => 80];

	/** Znaczenie braków danych (dla modelu i człowieka). */
	public const DATA_GAPS = [
		'no_gsc_connection' => 'Project has no Google Search Console property — GSC facts are unknown (not zero).',
		'no_gsc_data' => 'No GSC data for this topic in the project — unknown, not proof of no visibility.',
		'gsc_window_incomplete' => 'GSC data does not cover the whole window (e.g. import in progress) — GSC totals may be understated.',
		'gsc_no_impressions' => 'GSC data exists but the topic keywords had no impressions in the window — not proof that the page does not exist.',
		'gsc_stale' => 'Newest GSC data is older than 7 days.',
		'no_serp_measurement' => 'No SERP measurement for the topic — SERP rank and competitors are unknown.',
		'serp_stale' => 'SERP measurement is 31–90 days old — lower confidence.',
		'serp_expired' => 'SERP measurement is older than 90 days — not interpreted (no rank, no results).',
		'no_market_data' => 'No search volume for topic keywords — unknown (null), never zero.',
		'market_data_partial' => 'Search volume is missing for some topic keywords.',
		'no_labs_import' => 'No DataForSEO Labs import for the project — keyword gap data unavailable.',
		'target_unknown' => 'Target page is unknown — available signals are insufficient.',
		'target_none_not_proof' => 'No known target page in available sources — NOT proof that such a page does not exist on the site.',
		'target_conflict' => 'Several project pages compete for the topic (URL conflict signals).',
		'page_content_not_fetched' => 'Page content (title, headings, text) was not fetched — do not assume what the page contains.',
		'page_index_incomplete' => 'The project page index is incomplete (pages known only from GSC) — a page missing from it may still exist.',
		'keywords_omitted' => 'Some topic keywords were omitted from the context (limits).',
		'context_reduced' => 'Context was reduced to fit the size budget — see limits.omitted.',
	];

	public const METRIC_DEFINITIONS = [
		'average_position_gsc' => 'Google Search Console average position: SUM(position×impressions)/SUM(impressions) in the window. Lower is better. An average, not an exact SERP rank.',
		'ctr' => 'clicks / impressions (0–1) from GSC.',
		'serp_rank_group' => 'Rank among organic results of the project domain family in a SERP measurement (DataForSEO Google Organic) at measured_at. Featured snippet is not #1. null = not found or no usable measurement.',
		'rank_labs' => 'Position from the DataForSEO Labs database (dated third-party snapshot) — not a live SERP and not GSC.',
		'search_volume' => 'Average monthly searches (Google Ads data via DataForSEO). null = unknown, never zero.',
		'keyword_difficulty' => 'SEO keyword difficulty 0–100 (DataForSEO Labs). Not Google Ads competition.',
		'cpc_usd' => 'Google Ads cost per click in USD.',
		'priority' => 'Whack-a-mole Strategy priority 0–100: a heuristic signal to check, not business value and not a traffic forecast.',
		'kind' => 'fact = measured data; third_party_estimate = provider estimate; heuristic = Whack-a-mole rule-based interpretation. Your own unproven claims must be marked as hypothesis.',
		'provenance' => 'Each evidence section has provenance (source, kind, as_of, freshness, reliability) that applies to its items unless an item has its own measured_at/freshness.',
		'refs' => 'Only refs listed in `refs` exist. Cite them in evidence_refs.',
	];

	/** Pola z danymi zewnętrznymi (frazy, adresy, domeny, nazwy, tytuły) — dane, nigdy instrukcje. */
	public const UNTRUSTED = [
		'topic.label',
		'topic.main_keyword.keyword',
		'keywords[].keyword',
		'keywords[].serp.url',
		'keywords[].target.url',
		'evidence.serp.reference_keyword.keyword',
		'evidence.serp.project.url',
		'evidence.serp.competitors.best.name',
		'evidence.serp.top_results[].domain',
		'evidence.serp.top_results[].url',
		'evidence.serp.top_results[].competitor',
		'evidence.serp.overlap[].keyword',
		'evidence.labs_gaps.items[].best_competitor',
		'evidence.content_gaps.items[].label',
		'evidence.opportunities.items[].page',
		'evidence.discovery.items[].target_url',
		'target_page.url',
		'target_page.votes[].url',
		'target_page.alternatives[].url',
		'target_page.conflicts[].keyword',
		'target_page.conflicts[].urls[]',
		'target_page.possible_existing_page[].url',
		'target_page.derived_signals[].url',
		'target_page.page_content',
		'external_texts[].text',
	];

	private int $truncated = 0;

	/**
	 * @param array{
	 *   context: array<string, mixed>,
	 *   serp: array<string, mixed>,
	 *   freshness: array<string, mixed>,
	 *   project: array{domain: string, market: ?string, gsc_window: mixed},
	 *   market_as_of: array<string, array{volume: ?string, difficulty: ?string}>,
	 *   page_index_complete: bool,
	 *   page: ?PageSnapshot,
	 *   now: DateTimeImmutable,
	 *   topic_id: int,
	 * } $source
	 */
	public function assemble(array $source): AiContext
	{
		$limits = self::LIMITS;
		$applied = [];
		$body = $this->compose($source, $limits, $applied);

		foreach (self::REDUCTIONS as $code => $changes) {
			if (strlen(AiContext::encode($body)) <= self::MAX_BYTES) {
				break;
			}

			$limits = array_merge($limits, $changes);
			$applied[] = $code;
			$body = $this->compose($source, $limits, $applied);
		}

		$body['limits']['within_budget'] = strlen(AiContext::encode($body)) <= self::MAX_BYTES;

		return new AiContext($body, $source['topic_id'], (string) ($source['context']['topic']['id'] ?? ''));
	}

	/**
	 * @param array<string, mixed> $source
	 * @param array<string, int> $limits
	 * @param list<string> $applied
	 * @return array<string, mixed>
	 */
	private function compose(array $source, array $limits, array $applied): array
	{
		$this->truncated = 0;
		$ctx = $source['context'];
		$now = $source['now'];
		$freshness = $source['freshness'];
		$refs = ['decision' => true, 'gsc' => true, 'market' => true, 'target' => true, 'topic' => true];
		$omitted = [];

		// Frazy: lider pierwszy, pozostałe w kolejności pakietu STEP 16.
		$all = array_values(array_filter((array) ($ctx['keywords'] ?? []), 'is_array'));
		usort($all, static fn (array $a, array $b): int => (($a['role'] ?? '') === 'leader' ? 0 : 1) <=> (($b['role'] ?? '') === 'leader' ? 0 : 1));
		$included = array_slice($all, 0, $limits['keywords']);
		$omitted['keywords'] = count($all) - count($included);
		$kwRefs = [];

		foreach ($included as $keyword) {
			$kwRefs[(string) $keyword['id']] = 'kw:' . $keyword['id'];
			$refs['kw:' . $keyword['id']] = true;
		}

		$keywordRef = static fn (mixed $id): ?string => is_scalar($id) ? ($kwRefs[(string) $id] ?? null) : null;
		$leader = $included[0] ?? null;
		$leader = $leader !== null && ($leader['role'] ?? null) === 'leader' ? $leader : null;
		$keywords = array_map(fn (array $keyword): array => $this->keyword($keyword), $included);

		// Decyzja Strategii (heurystyka reguł STEP 16).
		$decision = (array) ($ctx['decision'] ?? []);
		$checks = array_values(array_filter((array) ($decision['checks'] ?? []), 'is_array'));
		$omitted['rule_checks'] = max(0, count($checks) - $limits['rule_checks']);
		$priority = is_array($ctx['priority'] ?? null) ? $ctx['priority'] : null;
		$confidence = is_array($ctx['confidence'] ?? null) ? $ctx['confidence'] : null;

		// GSC.
		$gscFacts = is_array($ctx['gsc'] ?? null) ? $ctx['gsc'] : null;
		$gscConnected = ($freshness['gsc']['connected'] ?? false) === true;
		$gscNewest = $freshness['gsc']['newest_date'] ?? null;
		$gscFreshness = self::gscFreshness(is_string($gscNewest) ? $gscNewest : null, $now);
		$window = $source['project']['gsc_window'] ?? null;

		// SERP.
		$serpView = (array) ($source['serp'] ?? []);
		$detail = is_array($serpView['detail'] ?? null) ? $serpView['detail'] : null;
		$serpSection = null;
		$external = [];

		if ($detail !== null) {
			[$serpSection, $serpRefs, $external, $serpOmitted] = $this->serp($detail, $serpView, $limits, $keywordRef);
			$refs += $serpRefs;
			$omitted += $serpOmitted;
		}

		// Luki fraz (Labs), luki treści, Szanse SEO, Nowe frazy.
		$gapItems = array_values(array_filter((array) ($ctx['gap'] ?? []), 'is_array'));
		$labsGaps = [];

		foreach (array_slice($gapItems, 0, $limits['labs_gaps']) as $gap) {
			$ref = 'gap:' . (string) ($gap['id'] ?? $gap['keyword'] ?? count($labsGaps));
			$refs[$ref] = true;
			$best = is_array($gap['best_competitor'] ?? null) ? $gap['best_competitor'] : null;
			$labsGaps[] = [
				'ref' => $ref,
				'keyword_ref' => $keywordRef($gap['keyword'] ?? null),
				'gap_type' => $gap['gap_type'] ?? null,
				'project_visibility' => $gap['visibility'] ?? null,
				'visibility_source' => $gap['visibility_source'] ?? null,
				'project_rank_labs' => $gap['project_rank_labs'] ?? null,
				'competitors_ranking' => $gap['competitors'] ?? null,
				'competitors_in_top10' => $gap['competitors_top10'] ?? null,
				'best_competitor' => $best === null ? null : [
					'name' => $this->text($best['name'] ?? null, 'name'),
					'domain' => $this->text($best['domain'] ?? null, 'domain'),
					'rank_labs' => $best['rank_labs'] ?? null,
				],
				'gap_priority' => $gap['priority'] ?? null,
			];
		}

		$omitted['labs_gaps'] = count($gapItems) - count($labsGaps);
		$contentGaps = $this->items((array) ($ctx['content_gap'] ?? []), $limits['content_gaps'], 'cg', $refs, $omitted, 'content_gaps', fn (array $item): array => [
			'label' => $this->text($item['label'] ?? null, 'label'),
			'content_gap' => $item['content_gap'] ?? null,
			'confidence' => $item['confidence'] ?? null,
			'reason' => $item['reason'] ?? null,
		]);
		$opportunities = $this->items((array) ($ctx['opportunities'] ?? []), $limits['opportunities'], 'opp', $refs, $omitted, 'opportunities', fn (array $item): array => [
			'type' => $item['type'] ?? null,
			'status' => $item['status'] ?? null,
			'signal_priority' => $item['priority'] ?? null,
			'confidence' => $item['confidence'] ?? null,
			'page' => $this->text($item['page'] ?? null, 'url'),
		]);
		$discovery = $this->items((array) ($ctx['discovery'] ?? []), $limits['discovery'], 'disc', $refs, $omitted, 'discovery', fn (array $item): array => [
			'keyword_ref' => $keywordRef($item['keyword'] ?? null),
			'status' => $item['status'] ?? null,
			'discovery_priority' => $item['priority'] ?? null,
			'visibility_gsc' => $item['visibility_gsc'] ?? null,
			'target_url' => $this->text($item['target_url'] ?? null, 'url'),
		]);

		// Rynek: daty pobrania metryk (wspólne dla rynku, tylko frazy tematu).
		$asOf = ['volume' => [], 'difficulty' => []];
		$withVolume = 0;
		$withDifficulty = 0;

		foreach ($included as $keyword) {
			$dates = $source['market_as_of'][(string) $keyword['id']] ?? [];

			foreach (['volume', 'difficulty'] as $metric) {
				if (is_string($dates[$metric] ?? null)) {
					$asOf[$metric][] = substr($dates[$metric], 0, 10);
				}
			}

			$withVolume += ($keyword['market']['volume'] ?? null) !== null ? 1 : 0;
			$withDifficulty += ($keyword['market']['difficulty'] ?? null) !== null ? 1 : 0;
		}

		// Strona docelowa (heurystyka TargetPageResolver) i konflikty adresów.
		$target = is_array($ctx['target'] ?? null) ? $ctx['target'] : [];
		$state = is_string($target['state'] ?? null) ? $target['state'] : null;
		$conflictItems = array_values(array_filter((array) ($ctx['conflicts'] ?? []), 'is_array'));
		$conflicts = [];

		foreach (array_slice($conflictItems, 0, $limits['conflicts']) as $index => $conflict) {
			$refs['conflict:' . ($index + 1)] = true;
			$conflicts[] = [
				'ref' => 'conflict:' . ($index + 1),
				'type' => $conflict['type'] ?? null,
				'strength' => $conflict['strength'] ?? null,
				'keyword' => $this->text($conflict['keyword'] ?? null, 'keyword'),
				'urls' => array_map(fn (mixed $url): ?string => $this->text(is_string($url) ? $url : null, 'url'), array_slice(array_values((array) ($conflict['urls'] ?? [])), 0, 3)),
			];
		}

		$omitted['conflicts'] = count($conflictItems) - count($conflicts);
		$votes = array_values(array_filter((array) ($target['votes'] ?? []), 'is_array'));
		$alternatives = array_values(array_filter((array) ($target['alternatives'] ?? []), 'is_array'));
		$omitted['votes'] = max(0, count($votes) - $limits['votes']);
		$omitted['alternatives'] = max(0, count($alternatives) - $limits['alternatives']);
		$page = $source['page'] ?? null;

		// Braki danych (stała kolejność).
		$gaps = [];
		$volumes = array_map(static fn (array $keyword): mixed => $keyword['market']['volume'] ?? null, $included);
		$serpFreshness = $detail['freshness'] ?? null;
		$anySerp = array_filter($included, static fn (array $keyword): bool => is_array($keyword['serp'] ?? null)) !== [];
		$conditions = [
			'no_gsc_connection' => ! $gscConnected,
			'no_gsc_data' => $gscConnected && $gscFacts === null,
			'gsc_window_incomplete' => $gscFacts !== null && ($gscFacts['complete'] ?? null) === false,
			'gsc_no_impressions' => $gscFacts !== null && ($gscFacts['impressions'] ?? null) === 0,
			'gsc_stale' => $gscConnected && in_array($gscFreshness, ['stale', 'expired'], true),
			'no_serp_measurement' => $detail === null && ! $anySerp,
			'serp_stale' => $serpFreshness === 'stale',
			'serp_expired' => $serpFreshness === 'expired',
			'no_market_data' => $included !== [] && array_filter($volumes, static fn (mixed $volume): bool => $volume !== null) === [],
			'market_data_partial' => in_array(null, $volumes, true) && array_filter($volumes, static fn (mixed $volume): bool => $volume !== null) !== [],
			'no_labs_import' => ($freshness['labs']['last_import_at'] ?? null) === null,
			'target_unknown' => $state === 'unknown' || $state === null,
			'target_none_not_proof' => $state === 'none',
			'target_conflict' => $state === 'conflict' || $conflicts !== [],
			'page_content_not_fetched' => $page === null,
			'page_index_incomplete' => ($source['page_index_complete'] ?? false) !== true,
			'keywords_omitted' => $omitted['keywords'] > 0,
			'context_reduced' => $applied !== [],
		];
		$gapRefs = [
			'no_gsc_data' => 'gsc', 'gsc_window_incomplete' => 'gsc', 'gsc_no_impressions' => 'gsc', 'gsc_stale' => 'gsc', 'no_gsc_connection' => 'gsc',
			'serp_stale' => 'serp', 'serp_expired' => 'serp', 'no_market_data' => 'market', 'market_data_partial' => 'market',
			'target_unknown' => 'target', 'target_none_not_proof' => 'target', 'target_conflict' => 'target', 'page_content_not_fetched' => 'target',
			'page_index_incomplete' => 'target',
		];

		foreach ($conditions as $code => $applies) {
			if ($applies) {
				$ref = $gapRefs[$code] ?? null;
				$gaps[] = ['code' => $code, 'ref' => $ref !== null && isset($refs[$ref]) ? $ref : null, 'meaning' => self::DATA_GAPS[$code]];
			}
		}

		ksort($refs, SORT_STRING);
		$omitted = array_filter($omitted, static fn (int $count): bool => $count > 0);
		ksort($omitted);

		return [
			'schema' => self::SCHEMA,
			'context_version' => self::VERSION,
			'strategy' => [
				'context_version' => $ctx['context_version'] ?? null,
				'rules_version' => $ctx['rules_version'] ?? null,
				'evidence_hash' => $ctx['evidence_hash'] ?? null,
			],
			'project' => [
				'domain' => $this->text($source['project']['domain'] ?? null, 'domain'),
				'market' => $this->text($source['project']['market'] ?? null, 'name'),
			],
			'topic' => [
				'ref' => 'topic',
				'id' => $ctx['topic']['id'] ?? null,
				'label' => $this->text($ctx['topic']['label'] ?? null, 'label'),
				'main_keyword' => $leader === null ? null : ['ref' => 'kw:' . $leader['id'], 'keyword' => $this->text((string) $leader['keyword'], 'keyword')],
				'keywords_total' => $ctx['topic']['keywords'] ?? count($all),
				'keywords_in_context' => count($included),
				'search_volume_sum' => $ctx['topic']['demand'] ?? null,
				'workflow_status' => $ctx['workflow']['status'] ?? null,
				'decision_changed_since_status' => $ctx['workflow']['decision_changed'] ?? null,
			],
			'decision' => [
				'ref' => 'decision',
				'provenance' => ['source' => 'whack_a_mole_strategy', 'kind' => 'heuristic', 'rules_version' => $ctx['rules_version'] ?? null, 'reliability' => $confidence['level'] ?? null],
				'action' => $decision['action'] ?? null,
				'action_label' => $decision['action_label'] ?? null,
				'reason' => $decision['reason'] ?? null,
				'reason_label' => $decision['reason_label'] ?? null,
				'basis' => array_values((array) ($decision['basis'] ?? [])),
				'rule_checks' => array_map(static fn (array $check): array => ['rule' => $check['rule'] ?? null, 'passed' => $check['passed'] ?? null, 'why' => $check['why'] ?? null], array_slice($checks, 0, $limits['rule_checks'])),
				'priority' => $priority === null ? null : [
					'value' => $priority['value'] ?? null,
					'band' => $priority['band'] ?? null,
					'components' => array_map(static fn (mixed $component): array => ['value' => is_array($component) ? ($component['value'] ?? null) : null, 'max' => is_array($component) ? ($component['max'] ?? null) : null], (array) ($priority['components'] ?? [])),
				],
				'confidence' => $confidence === null ? null : [
					'level' => $confidence['level'] ?? null,
					'points' => $confidence['points'] ?? null,
					'positive' => array_values((array) ($confidence['positive'] ?? [])),
					'negative' => array_values((array) ($confidence['negative'] ?? [])),
					'caps' => array_values((array) ($confidence['caps'] ?? [])),
				],
			],
			'keywords' => $keywords,
			'evidence' => [
				'gsc' => [
					'ref' => 'gsc',
					'provenance' => [
						'source' => 'google_search_console',
						'kind' => 'fact',
						'as_of' => $gscNewest,
						'window' => is_array($window) && count($window) === 2 ? ['from' => $window[0], 'to' => $window[1]] : null,
						'timezone' => 'America/Los_Angeles',
						'freshness' => $gscFreshness,
						'reliability' => match (true) {
							! $gscConnected || $gscFacts === null => 'none',
							$gscFreshness === 'expired' => 'low',
							($gscFacts['complete'] ?? null) === false || $gscFreshness === 'stale' => 'medium',
							default => 'high',
						},
					],
					'connected' => $gscConnected,
					'window_complete' => $gscFacts['complete'] ?? null,
					'topic_totals' => $gscFacts === null ? null : [
						'clicks' => $gscFacts['clicks'] ?? null,
						'impressions' => $gscFacts['impressions'] ?? null,
						'ctr' => self::ctr($gscFacts['clicks'] ?? null, $gscFacts['impressions'] ?? null),
						'average_position_gsc' => $gscFacts['position'] ?? null,
					],
				],
				'serp' => $serpSection,
				'market' => [
					'ref' => 'market',
					'provenance' => [
						'source' => 'dataforseo',
						'kind' => 'third_party_estimate',
						'as_of' => [
							'search_volume' => $asOf['volume'] === [] ? null : ['oldest' => min($asOf['volume']), 'newest' => max($asOf['volume'])],
							'keyword_difficulty' => $asOf['difficulty'] === [] ? null : ['oldest' => min($asOf['difficulty']), 'newest' => max($asOf['difficulty'])],
						],
						'reliability' => 'medium',
					],
					'keywords_with_volume' => $withVolume,
					'keywords_with_difficulty' => $withDifficulty,
				],
				'labs_gaps' => [
					'provenance' => [
						'source' => 'dataforseo_labs',
						'kind' => 'third_party_estimate',
						'as_of' => $freshness['labs']['last_import_at'] ?? null,
						'recalculated_at' => $freshness['labs']['recalculated_at'] ?? null,
						'reliability' => 'medium',
						'note' => 'rank_labs is a dated database snapshot — not a live SERP and not GSC.',
					],
					'items' => $labsGaps,
				],
				'content_gaps' => [
					'provenance' => ['source' => 'whack_a_mole_content_gap', 'kind' => 'heuristic', 'as_of' => $freshness['labs']['recalculated_at'] ?? null, 'reliability' => 'low'],
					'items' => $contentGaps,
				],
				'opportunities' => [
					'provenance' => ['source' => 'whack_a_mole_opportunities', 'kind' => 'heuristic', 'based_on' => 'google_search_console', 'reliability' => 'medium', 'note' => 'Signals to check, not guaranteed gains.'],
					'items' => $opportunities,
				],
				'discovery' => [
					'provenance' => ['source' => 'whack_a_mole_keyword_discovery', 'kind' => 'heuristic', 'based_on' => 'dataforseo_labs', 'reliability' => 'low', 'note' => 'visibility_gsc is not proof of absence.'],
					'items' => $discovery,
				],
			],
			'target_page' => [
				'ref' => 'target',
				'provenance' => ['source' => 'whack_a_mole_target_resolver', 'kind' => 'heuristic', 'reliability' => match ($state) {
					'confirmed' => 'high',
					'probable' => 'medium',
					default => 'low',
				}],
				'state' => $state,
				'state_label' => $target['state_label'] ?? null,
				'url' => $this->text($target['url'] ?? null, 'url'),
				'is_home' => (bool) ($target['home'] ?? false),
				'supported_by' => array_values((array) ($target['families'] ?? [])),
				'manual' => (bool) ($target['manual'] ?? false),
				'manual_no_page' => (bool) ($target['manual_none'] ?? false),
				'reasons' => array_values((array) ($target['reasons'] ?? [])),
				'votes' => array_map(fn (array $vote): array => [
					'url' => $this->text($vote['url'] ?? null, 'url'),
					'family' => $vote['family'] ?? null,
					'strength' => $vote['strength'] ?? null,
					'basis' => $vote['basis'] ?? null,
				], array_slice($votes, 0, $limits['votes'])),
				'alternatives' => array_map(fn (array $alternative): array => [
					'url' => $this->text($alternative['url'] ?? null, 'url'),
					'families' => array_values((array) ($alternative['families'] ?? [])),
					'strength' => $alternative['strength'] ?? null,
				], array_slice($alternatives, 0, $limits['alternatives'])),
				'conflicts' => $conflicts,
				'counter_evidence' => array_values((array) ($target['no_visibility'] ?? [])),
				'possible_existing_page' => array_map(fn (mixed $hint): array => [
					'url' => $this->text(is_array($hint) && is_string($hint['url'] ?? null) ? $hint['url'] : null, 'url'),
					'source' => is_array($hint) ? ($hint['source'] ?? null) : null,
				], array_slice(array_values((array) ($target['hints'] ?? [])), 0, 3)),
				'derived_signals' => array_map(fn (mixed $signal): array => [
					'url' => $this->text(is_array($signal) && is_string($signal['url'] ?? null) ? $signal['url'] : null, 'url'),
					'source' => is_array($signal) ? ($signal['source'] ?? null) : null,
				], array_slice(array_values((array) ($target['derived'] ?? [])), 0, 4)),
				'page_index' => ['complete' => ($source['page_index_complete'] ?? false) === true, 'basis' => 'gsc_known_pages'],
				'page_content' => $page instanceof PageSnapshot ? $page->toContext() : ['available' => false, 'reason' => 'not_fetched'],
			],
			'external_texts' => $external,
			'data_gaps' => $gaps,
			'refs' => array_keys($refs),
			'limits' => [
				'max_bytes' => self::MAX_BYTES,
				'item_limits' => $limits,
				'omitted' => $omitted,
				'reductions' => $applied,
				'truncated_texts' => $this->truncated,
				'within_budget' => null,
			],
			'metric_definitions' => self::METRIC_DEFINITIONS,
			'untrusted' => self::UNTRUSTED,
		];
	}

	/**
	 * @param array<string, mixed> $keyword
	 * @return array<string, mixed>
	 */
	private function keyword(array $keyword): array
	{
		$gsc = is_array($keyword['gsc'] ?? null) ? $keyword['gsc'] : null;
		$serp = is_array($keyword['serp'] ?? null) ? $keyword['serp'] : null;
		$usable = in_array($serp['freshness'] ?? null, ['fresh', 'stale'], true);
		$market = (array) ($keyword['market'] ?? []);
		$target = is_array($keyword['target'] ?? null) ? $keyword['target'] : [];

		return [
			'ref' => 'kw:' . $keyword['id'],
			'keyword' => $this->text((string) ($keyword['keyword'] ?? ''), 'keyword'),
			'role' => $keyword['role'] ?? null,
			'grouping_basis' => $keyword['basis'] ?? null,
			'pinned' => (bool) ($keyword['pinned'] ?? false),
			'sources' => array_values((array) ($keyword['sources'] ?? [])),
			'gsc' => $gsc === null ? null : [
				'clicks' => $gsc['clicks'] ?? null,
				'impressions' => $gsc['impressions'] ?? null,
				'ctr' => self::ctr($gsc['clicks'] ?? null, $gsc['impressions'] ?? null),
				'average_position_gsc' => $gsc['average_position_gsc'] ?? null,
				'pages' => $gsc['pages'] ?? null,
			],
			'market' => [
				'search_volume' => $market['volume'] ?? null,
				'keyword_difficulty' => $market['difficulty'] ?? null,
				'cpc_usd' => $market['cpc'] ?? null,
				'intent' => $market['intent'] ?? null,
			],
			'serp' => $serp === null ? null : [
				'measured_at' => $serp['checked_at'] ?? null,
				'freshness' => $serp['freshness'] ?? null,
				'found' => $usable ? ($serp['rank'] ?? null) !== null : null,
				'serp_rank_group' => $usable ? ($serp['rank'] ?? null) : null,
				'url' => $usable ? $this->text(is_string($serp['url'] ?? null) ? $serp['url'] : null, 'url') : null,
			],
			'target' => [
				'state' => $target['state'] ?? null,
				'url' => $this->text(is_string($target['url'] ?? null) ? $target['url'] : null, 'url'),
			],
		];
	}

	/**
	 * Sekcja SERP: frazy odniesienia tematu (najnowszy zgodny pomiar). Wyniki i profil tylko z pomiaru używalnego (≤ 90 dni).
	 *
	 * @param array<string, mixed> $detail
	 * @param array<string, mixed> $view
	 * @param array<string, int> $limits
	 * @param \Closure(mixed): ?string $keywordRef
	 * @return array{0: array<string, mixed>, 1: array<string, true>, 2: list<array<string, mixed>>, 3: array<string, int>}
	 */
	private function serp(array $detail, array $view, array $limits, \Closure $keywordRef): array
	{
		$freshness = $detail['freshness'] ?? null;
		$usable = in_array($freshness, ['fresh', 'stale'], true);
		$refs = ['serp' => true];
		$external = [];
		$results = $usable ? array_values(array_filter((array) ($detail['results'] ?? []), 'is_array')) : [];
		$top = [];

		foreach (array_slice($results, 0, $limits['serp_results']) as $result) {
			$ref = 'serp:' . (int) ($result['rank'] ?? 0);

			if (isset($refs[$ref])) {
				$ref .= '-' . count($top);
			}

			$refs[$ref] = true;
			$titleRef = null;
			$title = $this->text(is_string($result['title'] ?? null) ? $result['title'] : null, 'title');

			if ($title !== null && $title !== '') {
				$titleRef = 'txt:' . (count($external) + 1);
				$external[] = ['id' => $titleRef, 'source' => 'serp_title', 'about' => $ref, 'text' => $title];
			}

			$top[] = [
				'ref' => $ref,
				'serp_rank_group' => $result['rank'] ?? null,
				'domain' => $this->text(is_string($result['host'] ?? null) ? $result['host'] : null, 'domain'),
				'url' => $this->text(is_string($result['url'] ?? null) ? $result['url'] : null, 'url'),
				'title_ref' => $titleRef,
				'is_project' => (bool) ($result['project'] ?? false),
				'competitor' => $this->text(is_string($result['competitor'] ?? null) ? $result['competitor'] : null, 'name'),
				'page_shape' => $result['shape'] ?? null,
				'page_shape_confidence' => $result['confidence'] ?? null,
			];
		}

		$overlapItems = array_values(array_filter((array) ($view['overlap'] ?? []), static fn (mixed $item): bool => is_array($item) && ($item['level'] ?? null) !== null));
		$overlap = [];

		foreach (array_slice($overlapItems, 0, $limits['serp_overlap']) as $item) {
			$overlap[] = [
				'keyword_ref' => $keywordRef($item['id'] ?? null),
				'keyword' => $this->text(is_string($item['keyword'] ?? null) ? $item['keyword'] : null, 'keyword'),
				'level' => $item['level'],
				'shared_urls' => $item['shared_urls'] ?? null,
				'shared_domains' => $item['shared_domains'] ?? null,
			];
		}

		$project = $usable && is_array($detail['project'] ?? null) ? $detail['project'] : null;
		$competitors = $usable && is_array($detail['competitors'] ?? null) ? $detail['competitors'] : null;
		$best = is_array($competitors['best'] ?? null) ? $competitors['best'] : null;
		$profile = $usable && is_array($detail['profile'] ?? null) ? $detail['profile'] : null;
		$reference = is_array($view['keyword'] ?? null) ? $view['keyword'] : null;

		$section = [
			'ref' => 'serp',
			'provenance' => [
				'source' => 'serp_measurement',
				'provider' => 'dataforseo_google_organic',
				'kind' => 'fact',
				'as_of' => $detail['checked_at'] ?? null,
				'freshness' => $freshness,
				'reliability' => match ($freshness) {
					'fresh' => 'high',
					'stale' => 'medium',
					default => 'low',
				},
				'device' => $detail['context']['device'] ?? null,
				'depth' => $detail['context']['depth'] ?? null,
				'note' => 'page_shape and profile are Whack-a-mole heuristics (kind heuristic) derived from the measured SERP.',
			],
			'usable' => $usable,
			'reference_keyword' => $reference === null ? null : ['ref' => $keywordRef($reference['id'] ?? null), 'keyword' => $this->text(is_string($reference['keyword'] ?? null) ? $reference['keyword'] : null, 'keyword')],
			'project' => $project === null ? null : [
				'found' => (bool) ($project['found'] ?? false),
				'serp_rank_group' => $project['rank'] ?? null,
				'url' => $this->text(is_string($project['url'] ?? null) ? $project['url'] : null, 'url'),
				'featured_snippet' => (bool) ($project['featured'] ?? false),
			],
			'competitors' => $competitors === null ? null : [
				'in_top10' => $competitors['top10'] ?? null,
				'in_top20' => $competitors['top20'] ?? null,
				'best' => $best === null ? null : ['name' => $this->text(is_string($best['name'] ?? null) ? $best['name'] : null, 'name'), 'serp_rank_group' => $best['rank'] ?? null],
			],
			'profile' => $profile === null ? null : [
				'shape' => $profile['shape'] ?? null,
				'shape_share' => $profile['shape_share'] ?? null,
				'shape_confidence' => $profile['shape_confidence'] ?? null,
				'intent_signal' => $profile['intent_signal'] ?? null,
				'intent_confidence' => $profile['intent_confidence'] ?? null,
				'top10' => $profile['top10'] ?? null,
				'top20' => $profile['top20'] ?? null,
				'features' => $profile['features'] ?? null,
			],
			'spelling_correction' => $usable ? ($detail['spell'] ?? null) : null,
			'top_results' => $top,
			'overlap' => $overlap,
			'overlap_compared' => $view['compared'] ?? 0,
			'overlap_measured' => $view['measured'] ?? 0,
		];

		return [$section, $refs, $external, ['serp_results' => count($results) - count($top), 'serp_overlap' => count($overlapItems) - count($overlap)]];
	}

	/**
	 * Elementy sekcji z odwołaniem `<prefix>:<id>` (limit + licznik pominięć).
	 *
	 * @param array<int, mixed> $items
	 * @param array<string, true> $refs
	 * @param array<string, int> $omitted
	 * @param \Closure(array<string, mixed>): array<string, mixed> $map
	 * @return list<array<string, mixed>>
	 */
	private function items(array $items, int $limit, string $prefix, array &$refs, array &$omitted, string $section, \Closure $map): array
	{
		$items = array_values(array_filter($items, static fn (mixed $item): bool => is_array($item) && is_scalar($item['id'] ?? null)));
		$result = [];

		foreach (array_slice($items, 0, $limit) as $item) {
			$ref = $prefix . ':' . $item['id'];
			$refs[$ref] = true;
			$result[] = ['ref' => $ref] + $map($item);
		}

		$omitted[$section] = count($items) - count($result);

		return $result;
	}

	private function text(?string $value, string $kind): ?string
	{
		$clean = TextSanitizer::line($value, self::TEXT[$kind], $truncated);

		if ($truncated) {
			$this->truncated++;
		}

		return $clean === '' ? null : $clean;
	}

	private static function ctr(mixed $clicks, mixed $impressions): ?float
	{
		return is_numeric($clicks) && is_numeric($impressions) && (float) $impressions > 0 ? round((float) $clicks / (float) $impressions, 4) : null;
	}

	/** Świeżość danych GSC względem najnowszej daty (≤ 7 dni — świeże, ≤ 30 — starsze, później — nieaktualne). */
	public static function gscFreshness(?string $newest, DateTimeImmutable $now): string
	{
		if ($newest === null || preg_match('/^\d{4}-\d{2}-\d{2}/', $newest) !== 1) {
			return 'unknown';
		}

		$days = (int) floor(($now->getTimestamp() - (new DateTimeImmutable(substr($newest, 0, 10), new DateTimeZone('UTC')))->getTimestamp()) / 86400);

		return match (true) {
			$days <= 7 => 'fresh',
			$days <= 30 => 'stale',
			default => 'expired',
		};
	}
}
