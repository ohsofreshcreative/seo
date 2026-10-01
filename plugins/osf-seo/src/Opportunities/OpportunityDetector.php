<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\KeywordRow;

/**
 * Wykrywanie szans SEO na zagregowanych danych GSC jednego projektu i okresu (bez bazy, bez WordPressa).
 *
 * Klasyfikacja fraz (`query_daily`, pozycja = średnia pozycja GSC ważona wyświetleniami):
 * 1. Spadek — fraza z wystarczającą bazą w poprzednim okresie, która straciła kliknięcia, wyświetlenia
 *    albo średnią pozycję (próg bezwzględny I względny). Spadek ma pierwszeństwo: taka fraza nie trafia
 *    do pozostałych kategorii (jedna fraza = jedno działanie).
 * 2. Niski CTR — pozycja ≤ low_ctr_max_position, CTR ≤ low_ctr_max_ratio × referencyjny CTR jej
 *    przedziału pozycji (CtrModel) i istotna luka kliknięć.
 * 3. Blisko TOP — pozycja (3; 10] → cel TOP 3, (10; 20] → cel TOP 10.
 * 4. Słaba pozycja — pozycja (20; 100] przy wysokim progu wyświetleń (rozłączne z „blisko TOP”).
 * Fraza może jednocześnie mieć niski CTR i być blisko TOP — to różne działania (opis vs treść).
 *
 * Grupowanie: typ × podstrona docelowa frazy (adres z największą liczbą kliknięć, potem wyświetleń
 * w bieżącym okresie; dla utraconych fraz — w poprzednim), a bez danych `query_page` — typ × fraza.
 * Kanibalizacja: typ × para dwóch głównych adresów (wiele fraz tej samej pary = jedna szansa).
 * Spadek całej podstrony (sumy jej widocznych fraz) tworzy/uzupełnia grupę spadku tej podstrony.
 */
final class OpportunityDetector
{
	public function __construct(
		private readonly OpportunityConfig $config,
		private readonly OpportunityScorer $scorer,
		private readonly ConfidenceModel $confidence,
	) {
	}

	public static function create(OpportunityConfig $config): self
	{
		return new self($config, new OpportunityScorer($config), new ConfidenceModel($config));
	}

	/**
	 * Frazy, dla których potrzebne są dane segmentów okresu (kandydaci do kanibalizacji).
	 *
	 * @return list<int>
	 */
	public function cannibalizationKeywordIds(DetectionInput $input): array
	{
		return array_keys($this->cannibalizationCandidates($input));
	}

	/**
	 * @return list<Candidate> posortowane: typ, priorytet malejąco
	 */
	public function detect(DetectionInput $input): array
	{
		$days = $input->period->days;
		$keywords = [];
		$pairsByKeyword = [];

		foreach ($input->keywords as $row) {
			$keywords[$row->keywordId] = $row;
		}

		foreach ($input->pairs as $pair) {
			$pairsByKeyword[$pair->keywordId][] = $pair;
		}

		$referenceMin = $this->config->volume('reference_min_impressions', $days);
		$referenceKeywords = $this->config->int('reference_min_keywords');
		$model = CtrModel::fromSamples(array_map(self::current(...), $input->keywords), $referenceMin, $referenceKeywords);
		$previousModel = CtrModel::fromSamples(array_map(self::previous(...), $input->keywords), $referenceMin, $referenceKeywords);

		/** @var array<string, array<string, array{page: ?string, keyword: ?string, items: list<array<string, mixed>>, page_signals?: array<string, float|int>}>> $groups */
		$groups = [];

		foreach ($keywords as $id => $row) {
			$pairs = $pairsByKeyword[$id] ?? [];
			$page = self::primaryPage($pairs, true) ?? self::primaryPage($pairs, false);
			$current = self::current($row);
			$previous = self::previous($row);
			$signals = $input->previousCovered ? $this->declineSignals($current, $previous, $days) : [];

			if ($signals !== []) {
				self::add($groups, OpportunityType::Decline, $page, $row, ['signals' => $signals]);

				continue;
			}

			$position = $current->position();

			if ($position === null) {
				continue;
			}

			$lowCtr = $this->lowCtr($current, $position, $model, $days);

			if ($lowCtr !== null) {
				$previousPosition = $previous->position();
				$lowCtr['previous_reference_ctr'] = $previousPosition === null ? null : $previousModel->reference($previousPosition);
				self::add($groups, OpportunityType::LowCtr, $page, $row, $lowCtr);
			}

			if ($position > 3.0 && $position <= 20.0 && $current->impressions >= $this->config->volume('near_top_min_impressions', $days)) {
				$target = $position <= 10.0 ? 3 : 10;
				$targetCtr = $model->bucketReference($target === 3 ? CtrModel::TARGET_TOP3 : CtrModel::TARGET_TOP10);
				self::add($groups, OpportunityType::NearTop, $page, $row, [
					'target' => $target,
					'target_ctr' => $targetCtr,
					'potential_clicks' => max(0.0, $current->impressions * $targetCtr - $current->clicks),
					'proximity' => $target === 3 ? ($position <= 10.0 ? (10.0 - $position) / 7.0 : 0.0) : (20.0 - $position) / 10.0,
				]);
			} elseif ($position > 20.0 && $position <= 100.0 && $current->impressions >= $this->config->volume('weak_position_min_impressions', $days)) {
				$targetCtr = $model->bucketReference(CtrModel::TARGET_TOP20);
				self::add($groups, OpportunityType::WeakPosition, $page, $row, [
					'target' => 20,
					'target_ctr' => $targetCtr,
					'potential_clicks' => max(0.0, $current->impressions * $targetCtr - $current->clicks),
					'proximity' => (100.0 - $position) / 80.0,
				]);
			}
		}

		if ($input->previousCovered) {
			$this->addPageDeclines($groups, $input->pageTotals, $days);
		}

		$texts = $input->keywordTexts;
		$pairsByUrl = [];

		foreach ($keywords as $id => $row) {
			$texts[$id] = $row->keyword;
		}

		foreach ($input->pairs as $pair) {
			$pairsByUrl[$pair->url][] = $pair;
		}

		$candidates = [];

		foreach ($groups as $type => $byKey) {
			foreach ($byKey as $group) {
				$candidates[] = $this->finalizeGroup(OpportunityType::from($type), $group, $input, $model, $pairsByUrl, $texts);
			}
		}

		foreach ($this->cannibalizationGroups($input, $keywords) as $group) {
			$candidates[] = $this->finalizeCannibalization($group, $input);
		}

		return $this->limitPerType($candidates);
	}

	/**
	 * @return array{reference_ctr: float, bucket: string, expected_clicks: float, click_gap: float}|null
	 */
	private function lowCtr(Stats $current, float $position, CtrModel $model, int $days): ?array
	{
		if ($position > $this->config->ratio('low_ctr_max_position') || $current->impressions < $this->config->volume('low_ctr_min_impressions', $days)) {
			return null;
		}

		$reference = $model->reference($position);
		$expected = $current->impressions * $reference;
		$gap = $expected - $current->clicks;

		if ($reference <= 0 || $current->clicks > $this->config->ratio('low_ctr_max_ratio') * $expected || $gap < $this->config->volume('low_ctr_min_click_gap', $days)) {
			return null;
		}

		return [
			'reference_ctr' => $reference,
			'bucket' => CtrModel::bucketLabel(CtrModel::bucketIndex($position)),
			'expected_clicks' => $expected,
			'click_gap' => $gap,
		];
	}

	/**
	 * Sygnały istotnego spadku frazy (puste = brak). Każdy wymaga minimalnej bazy i progu bezwzględnego
	 * oraz względnego — 1 → 0 kliknięć albo 7,1 → 7,3 przy małej liczbie wyświetleń to szum.
	 *
	 * @return array<string, float|int>
	 */
	private function declineSignals(Stats $current, Stats $previous, int $days): array
	{
		if ($previous->impressions < $this->config->volume('decline_min_previous_impressions', $days)) {
			return [];
		}

		$relative = $this->config->ratio('decline_min_relative');
		$signals = [];
		$clickLoss = $previous->clicks - $current->clicks;
		$impressionLoss = $previous->impressions - $current->impressions;

		if ($previous->clicks >= $this->config->volume('decline_min_previous_clicks', $days)
			&& $clickLoss >= $this->config->volume('decline_min_click_loss', $days)
			&& $clickLoss / $previous->clicks >= $relative) {
			$signals['clicks'] = $clickLoss;
		}

		if ($impressionLoss >= $this->config->volume('decline_min_impression_loss', $days) && $impressionLoss / $previous->impressions >= $relative) {
			$signals['impressions'] = $impressionLoss;
		}

		$currentPosition = $current->position();
		$previousPosition = $previous->position();

		if ($currentPosition !== null && $previousPosition !== null
			&& $current->impressions >= $this->config->volume('decline_min_current_impressions_for_position', $days)) {
			$drop = $currentPosition - $previousPosition;
			$required = max($this->config->ratio('decline_min_position_drop'), $previousPosition * $this->config->ratio('decline_relative_position_drop'));

			if ($drop >= $required) {
				$signals['position'] = round($drop, 2);
			}
		}

		return $signals;
	}

	/**
	 * Spadek całej podstrony (suma jej widocznych fraz z `query_page`) — także gdy żadna fraza z osobna
	 * nie przekracza progów (wiele małych spadków).
	 *
	 * @param array<string, array<string, mixed>> $groups
	 * @param array<string, array{0: Stats, 1: Stats}> $pageTotals
	 */
	private function addPageDeclines(array &$groups, array $pageTotals, int $days): void
	{
		$relative = $this->config->ratio('decline_min_relative');

		foreach ($pageTotals as $url => [$current, $previous]) {
			$signals = [];
			$clickLoss = $previous->clicks - $current->clicks;
			$impressionLoss = $previous->impressions - $current->impressions;

			if ($previous->clicks >= $this->config->volume('decline_page_min_previous_clicks', $days)
				&& $clickLoss >= $this->config->volume('decline_page_min_click_loss', $days)
				&& $clickLoss / $previous->clicks >= $relative) {
				$signals['clicks'] = $clickLoss;
			}

			if ($previous->impressions > 0
				&& $impressionLoss >= $this->config->volume('decline_page_min_impression_loss', $days)
				&& $impressionLoss / $previous->impressions >= $relative) {
				$signals['impressions'] = $impressionLoss;
			}

			if ($signals === []) {
				continue;
			}

			$key = 'page:' . $url;
			$groups[OpportunityType::Decline->value][$key] ??= ['page' => $url, 'keyword' => null, 'items' => []];
			$groups[OpportunityType::Decline->value][$key]['page_signals'] = $signals;
		}
	}

	/**
	 * @param array<string, mixed> $group
	 * @param array<string, list<PagePair>> $pairsByUrl
	 * @param array<int, string> $texts
	 */
	private function finalizeGroup(OpportunityType $type, array $group, DetectionInput $input, CtrModel $model, array $pairsByUrl, array $texts): Candidate
	{
		$days = $input->period->days;
		$items = $group['items'];
		$current = new Stats();
		$previous = new Stats();

		foreach ($items as $item) {
			$current = $current->add($item['current']);
			$previous = $previous->add($item['previous']);
		}

		$page = $group['page'];
		$pageTotals = $page !== null ? ($input->pageTotals[$page] ?? null) : null;
		$comparison = ! $input->previousCovered ? 'not_covered' : ($previous->impressions > 0 ? 'ok' : 'no_previous_data');
		$details = [];

		switch ($type) {
			case OpportunityType::LowCtr:
				usort($items, static fn (array $a, array $b): int => $b['click_gap'] <=> $a['click_gap'] ?: strcmp($a['keyword'], $b['keyword']));
				$expected = array_sum(array_column($items, 'expected_clicks'));
				$gap = array_sum(array_column($items, 'click_gap'));
				$ratio = $expected > 0 ? $current->clicks / $expected : 1.0;
				$ctrDrop = 0.0;
				$previousCtr = $previous->ctr();

				if ($input->previousCovered && $previousCtr !== null && $previousCtr > 0) {
					$ctrDrop = max(0.0, ($previousCtr - (float) $current->ctr()) / $previousCtr);
				}

				// Spójność: CTR był niski także w poprzednim okresie (te same frazy, referencja poprzedniego okresu).
				$previousExpected = 0.0;
				$previousClicks = 0;

				foreach ($items as $item) {
					if ($item['previous_reference_ctr'] !== null) {
						$previousExpected += $item['previous']->impressions * $item['previous_reference_ctr'];
						$previousClicks += $item['previous']->clicks;
					}
				}

				$previousRatio = $previousExpected > 0 ? $previousClicks / $previousExpected : null;
				$consistent = $comparison === 'ok' && $previousRatio !== null && $previousRatio <= $this->config->ratio('low_ctr_max_ratio');
				$inputs = ['impressions' => $current->impressions, 'click_gap' => $gap, 'ctr_ratio' => $ratio, 'ctr_drop' => $ctrDrop];
				$details = [
					'expected_clicks' => round($expected, 2),
					'expected_ctr' => $current->impressions > 0 ? round($expected / $current->impressions, 6) : null,
					'click_gap' => round($gap, 2),
					'ctr_ratio' => round($ratio, 4),
					'previous_ctr_ratio' => $previousRatio === null ? null : round($previousRatio, 4),
					'reference' => $model->toArray(),
				];
				$rule = 'low_ctr_previous_period';
				$sample = $current->impressions;
				break;

			case OpportunityType::NearTop:
			case OpportunityType::WeakPosition:
				usort($items, static fn (array $a, array $b): int => $b['potential_clicks'] <=> $a['potential_clicks'] ?: $b['current']->impressions <=> $a['current']->impressions ?: strcmp($a['keyword'], $b['keyword']));
				$proximity = 0.0;
				$inBand = 0;
				$improvementCurrent = new Stats();
				$improvementPrevious = new Stats();

				foreach ($items as $item) {
					$proximity += $item['current']->impressions * $item['proximity'];
					$previousPosition = $item['previous']->position();

					if ($previousPosition !== null) {
						$improvementCurrent = $improvementCurrent->add($item['current']);
						$improvementPrevious = $improvementPrevious->add($item['previous']);
						$inBand += ($type === OpportunityType::NearTop ? $previousPosition <= 20.0 : $previousPosition > 20.0 && $previousPosition <= 100.0)
							? $item['current']->impressions
							: 0;
					}
				}

				$proximity = $current->impressions > 0 ? $proximity / $current->impressions : 0.0;
				$growth = $comparison === 'ok' ? ($current->impressions - $previous->impressions) / $previous->impressions : null;
				$improvement = $comparison === 'ok' && $improvementPrevious->hasData()
					? (float) $improvementPrevious->position() - (float) $improvementCurrent->position()
					: null;
				$consistent = $comparison === 'ok' && $current->impressions > 0 && $inBand / $current->impressions >= 0.5;
				$inputs = [
					'impressions' => $current->impressions,
					'potential_clicks' => array_sum(array_column($items, 'potential_clicks')),
					'proximity' => $proximity,
					'impressions_growth' => $growth,
					'position_improvement' => $improvement,
				];
				$details = [
					'potential_clicks' => round((float) $inputs['potential_clicks'], 2),
					'proximity' => round($proximity, 4),
					'impressions_growth' => $growth === null ? null : round($growth, 4),
					'position_improvement' => $improvement === null ? null : round($improvement, 2),
				];

				if ($type === OpportunityType::NearTop) {
					$details['top3'] = count(array_filter($items, static fn (array $item): bool => $item['target'] === 3));
					$details['top10'] = count(array_filter($items, static fn (array $item): bool => $item['target'] === 10));
				}

				$rule = $type === OpportunityType::NearTop ? 'near_top_stable_band' : 'weak_position_stable_band';
				$sample = $current->impressions;
				break;

			case OpportunityType::Decline:
				$pageSignals = $group['page_signals'] ?? null;

				if ($items === [] && $page !== null) {
					// Spadek całej podstrony bez pojedynczej frazy powyżej progów — dowody: frazy podstrony z największą stratą.
					$items = self::pageContextItems($pairsByUrl[$page] ?? [], $texts);
				}

				usort($items, static fn (array $a, array $b): int => ($b['previous']->clicks - $b['current']->clicks) <=> ($a['previous']->clicks - $a['current']->clicks)
					?: ($b['previous']->impressions - $b['current']->impressions) <=> ($a['previous']->impressions - $a['current']->impressions)
					?: strcmp($a['keyword'], $b['keyword']));

				if ($group['items'] === [] && $pageTotals !== null) {
					[$current, $previous] = $pageTotals;
				}

				$clicksLost = 0;
				$impressionsLost = 0;
				$kinds = [];
				$worseCurrent = new Stats();
				$worsePrevious = new Stats();

				foreach ($group['items'] as $item) {
					$clicksLost += max(0, $item['previous']->clicks - $item['current']->clicks);
					$impressionsLost += max(0, $item['previous']->impressions - $item['current']->impressions);
					$kinds += array_fill_keys(array_keys($item['signals']), true);

					if ($item['current']->hasData() && $item['previous']->hasData()) {
						$worseCurrent = $worseCurrent->add($item['current']);
						$worsePrevious = $worsePrevious->add($item['previous']);
					}
				}

				$baseClicks = $previous->clicks;
				$baseImpressions = $previous->impressions;

				if ($pageSignals !== null && $pageTotals !== null) {
					[$pageCurrent, $pagePrevious] = $pageTotals;
					$clicksLost = max($clicksLost, $pagePrevious->clicks - $pageCurrent->clicks);
					$impressionsLost = max($impressionsLost, $pagePrevious->impressions - $pageCurrent->impressions);
					$baseClicks = max($baseClicks, $pagePrevious->clicks);
					$baseImpressions = max($baseImpressions, $pagePrevious->impressions);
					$kinds += array_fill_keys(array_map(static fn (string $kind): string => 'page_' . $kind, array_keys($pageSignals)), true);
				}

				$previousCtr = $baseImpressions > 0 ? $baseClicks / $baseImpressions : 0.0;
				$lost = max((float) $clicksLost, $impressionsLost * $previousCtr);
				$relativeLoss = $baseClicks > 0 ? $lost / $baseClicks : ($baseImpressions > 0 ? $impressionsLost / $baseImpressions : 0.0);
				$worsening = $worsePrevious->hasData() ? (float) $worseCurrent->position() - (float) $worsePrevious->position() : null;
				$consistent = count($kinds) >= 2 || count($group['items']) >= 2;
				$comparison = 'ok';
				$inputs = [
					'impressions' => $baseImpressions,
					'clicks_lost' => $lost,
					'relative_loss' => min(1.0, $relativeLoss),
					'position_worsening' => $worsening,
				];
				$details = [
					'clicks_lost' => $clicksLost,
					'impressions_lost' => $impressionsLost,
					'estimated_clicks_lost' => round($lost, 2),
					'relative_loss' => round(min(1.0, $relativeLoss), 4),
					'position_worsening' => $worsening === null ? null : round($worsening, 2),
					'signals' => array_keys($kinds),
					'page_signals' => $pageSignals,
					'keywords_declining' => count($group['items']),
				];
				$rule = 'decline_multiple_signals';
				$sample = $baseImpressions;
				break;

			default:
				throw new \LogicException('Unsupported opportunity type.');
		}

		$score = $this->scorer->score($type, $inputs, $days);
		$confidence = $this->confidence->evaluate($sample, $comparison, $consistent, $rule, $days);
		$keyword = $group['keyword'];
		$fingerprint = $page !== null
			? Fingerprint::page($input->property, $type, $page)
			: Fingerprint::keyword($input->property, $type, (string) $keyword);
		$limit = $this->config->int('evidence_keywords');

		$evidence = [
			'v' => OpportunityConfig::ANALYSIS_VERSION,
			'type' => $type->value,
			'period' => self::periodEvidence($input),
			'entity' => ['kind' => $page !== null ? 'page' : 'keyword', 'page' => $page, 'keyword' => $page === null ? $keyword : null],
			'metrics' => ['current' => $current->toArray(), 'previous' => $previous->toArray()],
			'page_totals' => $pageTotals === null ? null : ['current' => $pageTotals[0]->toArray(), 'previous' => $pageTotals[1]->toArray()],
			'keywords' => array_map(self::serializeItem(...), array_slice($items, 0, $limit)),
			'keywords_total' => count($items),
			'details' => $details,
			'score' => $score,
			'confidence' => $confidence,
		];

		return new Candidate(
			type: $type,
			fingerprint: $fingerprint,
			pageUrl: $page,
			keyword: $page === null ? $keyword : null,
			priority: $score['total'],
			confidence: Confidence::from($confidence['level']),
			impressions: $type === OpportunityType::Decline ? $previous->impressions : $current->impressions,
			clicks: $type === OpportunityType::Decline ? $previous->clicks : $current->clicks,
			searchText: self::searchText([$page, $keyword, ...array_column($items, 'keyword')]),
			evidence: $evidence,
		);
	}

	/**
	 * Kandydaci do kanibalizacji: fraza z min. wyświetleniami (suma po adresach), co najmniej dwa adresy z istotnym
	 * udziałem i liczbą wyświetleń, i nie wszystkie w ścisłej czołówce (≤ cannibalization_top_position) — dwa wyniki
	 * na pozycjach 1–3 albo sitelinki to zwykle nie problem.
	 *
	 * @return array<int, array{current: Stats, previous: Stats, pairs: list<PagePair>, meaningful: list<PagePair>}>
	 */
	private function cannibalizationCandidates(DetectionInput $input): array
	{
		$days = $input->period->days;
		$minimum = $this->config->volume('cannibalization_min_impressions', $days);
		$urlMinimum = $this->config->volume('cannibalization_min_url_impressions', $days);
		$share = $this->config->ratio('cannibalization_min_share');
		$top = $this->config->ratio('cannibalization_top_position');
		$byKeyword = [];

		foreach ($input->pairs as $pair) {
			$byKeyword[$pair->keywordId][] = $pair;
		}

		$candidates = [];

		foreach ($byKeyword as $keywordId => $pairs) {
			$current = new Stats();
			$previous = new Stats();

			foreach ($pairs as $pair) {
				$current = $current->add($pair->current);
				$previous = $previous->add($pair->previous);
			}

			if (count($pairs) < 2 || $current->impressions < $minimum) {
				continue;
			}

			$meaningful = array_values(array_filter(
				$pairs,
				static fn (PagePair $pair): bool => $pair->current->impressions >= $urlMinimum && $pair->current->impressions / $current->impressions >= $share,
			));

			if (count($meaningful) < 2) {
				continue;
			}

			$allAtTop = array_reduce($meaningful, static fn (bool $carry, PagePair $pair): bool => $carry && (float) $pair->current->position() <= $top, true);

			if ($allAtTop) {
				continue;
			}

			usort($meaningful, static fn (PagePair $a, PagePair $b): int => $b->current->impressions <=> $a->current->impressions ?: strcmp($a->url, $b->url));
			$candidates[$keywordId] = ['current' => $current, 'previous' => $previous, 'pairs' => $pairs, 'meaningful' => $meaningful];
		}

		return $candidates;
	}

	/**
	 * @param array<int, KeywordRow> $keywords
	 * @return list<array{urls: array{0: string, 1: string}, queries: list<array<string, mixed>>}>
	 */
	private function cannibalizationGroups(DetectionInput $input, array $keywords): array
	{
		$days = $input->period->days;
		$urlMinimum = $this->config->volume('cannibalization_min_url_impressions', $days);
		$share = $this->config->ratio('cannibalization_min_share');
		$groups = [];

		foreach ($this->cannibalizationCandidates($input) as $keywordId => $candidate) {
			$text = isset($keywords[$keywordId]) ? $keywords[$keywordId]->keyword : ($input->keywordTexts[$keywordId] ?? null);

			if ($text === null) {
				continue;
			}

			$meaningful = $candidate['meaningful'];
			$meaningfulUrls = array_map(static fn (PagePair $pair): string => $pair->url, $meaningful);
			$dominantCurrent = self::dominant($candidate['pairs'], true);
			$dominantPrevious = self::dominant($candidate['pairs'], false);
			$changed = $input->previousCovered && $dominantPrevious !== null && $dominantPrevious !== $dominantCurrent;
			$previousMeaningful = $candidate['previous']->impressions > 0
				? count(array_filter($candidate['pairs'], static fn (PagePair $pair): bool => $pair->previous->impressions >= $urlMinimum && $pair->previous->impressions / $candidate['previous']->impressions >= $share))
				: 0;

			// Dominujący adres w kolejnych segmentach okresu (tylko adresy z istotnym udziałem).
			$sequence = [];

			foreach ($input->segments[$keywordId] ?? [] as $url => $segments) {
				if (! in_array($url, $meaningfulUrls, true)) {
					continue;
				}

				foreach ($segments as $segment => $impressions) {
					if ($impressions > 0 && (! isset($sequence[$segment]) || $impressions > $sequence[$segment][1] || ($impressions === $sequence[$segment][1] && strcmp($url, $sequence[$segment][0]) < 0))) {
						$sequence[$segment] = [$url, $impressions];
					}
				}
			}

			ksort($sequence);
			$dominants = array_values(array_map(static fn (array $entry): int => (int) array_search($entry[0], $meaningfulUrls, true), $sequence));
			$switches = 0;

			for ($i = 1, $n = count($dominants); $i < $n; $i++) {
				$switches += $dominants[$i] !== $dominants[$i - 1] ? 1 : 0;
			}

			$topShare = max(array_map(static fn (PagePair $pair): int => $pair->current->impressions, $candidate['pairs'])) / $candidate['current']->impressions;
			$urls = [$meaningful[0]->url, $meaningful[1]->url];
			sort($urls, SORT_STRING);
			$key = $urls[0] . "\n" . $urls[1];

			$groups[$key] ??= ['urls' => $urls, 'queries' => []];
			$groups[$key]['queries'][] = [
				'keyword' => $text,
				'current' => $candidate['current'],
				'previous' => $candidate['previous'],
				'urls' => array_map(static fn (PagePair $pair): array => [
					'url' => $pair->url,
					'current' => $pair->current->toArray(),
					'previous' => $pair->previous->toArray(),
					'share' => round($pair->current->impressions / $candidate['current']->impressions, 4),
					'previous_share' => $candidate['previous']->impressions > 0 ? round($pair->previous->impressions / $candidate['previous']->impressions, 4) : null,
					'meaningful' => in_array($pair->url, $meaningfulUrls, true),
				], self::sortPairs($candidate['pairs'])),
				'top_share' => $topShare,
				'dominant_current' => $dominantCurrent,
				'dominant_previous' => $dominantPrevious,
				'dominant_changed' => $changed,
				'previous_meaningful_urls' => $previousMeaningful,
				'segments' => count($dominants),
				'segment_days' => $input->segmentDays(),
				'dominant_sequence' => $dominants,
				'switches' => $switches,
			];
		}

		return array_values($groups);
	}

	/**
	 * @param array{urls: array{0: string, 1: string}, queries: list<array<string, mixed>>} $group
	 */
	private function finalizeCannibalization(array $group, DetectionInput $input): Candidate
	{
		$type = OpportunityType::Cannibalization;
		$days = $input->period->days;
		$queries = $group['queries'];
		usort($queries, static fn (array $a, array $b): int => $b['current']->impressions <=> $a['current']->impressions ?: strcmp($a['keyword'], $b['keyword']));
		$current = new Stats();
		$previous = new Stats();
		$balance = 0.0;
		$switching = 0.0;
		$consistent = false;
		$byUrl = array_fill_keys($group['urls'], 0);

		foreach ($queries as $query) {
			$impressions = $query['current']->impressions;
			$current = $current->add($query['current']);
			$previous = $previous->add($query['previous']);
			$balance += $impressions * min(1.0, (1 - $query['top_share']) / 0.5);
			$switching += ($query['dominant_changed'] || $query['switches'] >= 2) ? $impressions : 0;
			$consistent = $consistent || $query['previous_meaningful_urls'] >= 2 || $query['switches'] >= 1;

			foreach ($query['urls'] as $url) {
				if (isset($byUrl[$url['url']])) {
					$byUrl[$url['url']] += $url['current']['impressions'];
				}
			}
		}

		arsort($byUrl);
		$ordered = array_keys($byUrl);
		$comparison = ! $input->previousCovered ? 'not_covered' : ($previous->impressions > 0 ? 'ok' : 'no_previous_data');
		$inputs = [
			'impressions' => $current->impressions,
			'balance' => $current->impressions > 0 ? $balance / $current->impressions : 0.0,
			'queries' => count($queries),
			'switch_share' => $current->impressions > 0 ? $switching / $current->impressions : 0.0,
		];
		$score = $this->scorer->score($type, $inputs, $days);
		$confidence = $this->confidence->evaluate($current->impressions, $comparison, $consistent, 'cannibalization_persistent', $days);
		$limit = $this->config->int('evidence_keywords');

		$evidence = [
			'v' => OpportunityConfig::ANALYSIS_VERSION,
			'type' => $type->value,
			'period' => self::periodEvidence($input),
			'entity' => ['kind' => 'pair', 'page' => $ordered[0], 'keyword' => null, 'urls' => $ordered],
			'metrics' => ['current' => $current->toArray(), 'previous' => $previous->toArray()],
			'page_totals' => null,
			'keywords' => array_map(static function (array $query): array {
				$query['current'] = $query['current']->toArray();
				$query['previous'] = $query['previous']->toArray();
				$query['top_share'] = round($query['top_share'], 4);

				return $query;
			}, array_slice($queries, 0, $limit)),
			'keywords_total' => count($queries),
			'details' => [
				'balance' => round((float) $inputs['balance'], 4),
				'switch_share' => round((float) $inputs['switch_share'], 4),
				'dominant_changes' => count(array_filter($queries, static fn (array $query): bool => $query['dominant_changed'])),
			],
			'score' => $score,
			'confidence' => $confidence,
		];

		return new Candidate(
			type: $type,
			fingerprint: Fingerprint::pair($input->property, $type, $group['urls'][0], $group['urls'][1]),
			pageUrl: $ordered[0],
			keyword: null,
			priority: $score['total'],
			confidence: Confidence::from($confidence['level']),
			impressions: $current->impressions,
			clicks: $current->clicks,
			searchText: self::searchText([...$ordered, ...array_column($queries, 'keyword')]),
			evidence: $evidence,
		);
	}

	/**
	 * Limit szans na typ (najwyższy priorytet) — lista zadań, nie zrzut wszystkich fraz.
	 *
	 * @param list<Candidate> $candidates
	 * @return list<Candidate>
	 */
	private function limitPerType(array $candidates): array
	{
		usort($candidates, static fn (Candidate $a, Candidate $b): int => strcmp($a->type->value, $b->type->value)
			?: $b->priority <=> $a->priority
			?: $b->impressions <=> $a->impressions
			?: strcmp($a->fingerprint, $b->fingerprint));
		$limit = $this->config->int('max_groups_per_type');
		$counts = [];
		$result = [];

		foreach ($candidates as $candidate) {
			$counts[$candidate->type->value] = ($counts[$candidate->type->value] ?? 0) + 1;

			if ($counts[$candidate->type->value] <= $limit) {
				$result[] = $candidate;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, array<string, mixed>> $groups
	 * @param array<string, mixed> $extra
	 */
	private static function add(array &$groups, OpportunityType $type, ?string $page, KeywordRow $row, array $extra): void
	{
		$key = $page !== null ? 'page:' . $page : 'kw:' . $row->keyword;
		$groups[$type->value][$key] ??= ['page' => $page, 'keyword' => $page === null ? $row->keyword : null, 'items' => []];
		$groups[$type->value][$key]['items'][] = ['keyword' => $row->keyword, 'current' => self::current($row), 'previous' => self::previous($row)] + $extra;
	}

	/**
	 * Strona docelowa: najwięcej kliknięć, potem wyświetleń, potem adres (stabilnie) — w bieżącym albo poprzednim okresie.
	 *
	 * @param list<PagePair> $pairs
	 */
	private static function primaryPage(array $pairs, bool $current): ?string
	{
		$best = null;

		foreach ($pairs as $pair) {
			$stats = $current ? $pair->current : $pair->previous;

			if (! $stats->hasData()) {
				continue;
			}

			if ($best === null || [$stats->clicks, $stats->impressions] > [$best[1]->clicks, $best[1]->impressions]
				|| ([$stats->clicks, $stats->impressions] === [$best[1]->clicks, $best[1]->impressions] && strcmp($pair->url, $best[0]) < 0)) {
				$best = [$pair->url, $stats];
			}
		}

		return $best[0] ?? null;
	}

	/**
	 * @param list<PagePair> $pairs
	 */
	private static function dominant(array $pairs, bool $current): ?string
	{
		return self::primaryPage($pairs, $current);
	}

	/**
	 * @param list<PagePair> $pairs
	 * @return list<PagePair>
	 */
	private static function sortPairs(array $pairs): array
	{
		usort($pairs, static fn (PagePair $a, PagePair $b): int => $b->current->impressions <=> $a->current->impressions ?: strcmp($a->url, $b->url));

		return $pairs;
	}

	/**
	 * Frazy podstrony z utratą kliknięć/wyświetleń (kontekst spadku całej podstrony).
	 *
	 * @param list<PagePair> $pairs pary tej podstrony
	 * @param array<int, string> $texts
	 * @return list<array<string, mixed>>
	 */
	private static function pageContextItems(array $pairs, array $texts): array
	{
		$items = [];

		foreach ($pairs as $pair) {
			if (isset($texts[$pair->keywordId]) && ($pair->previous->clicks > $pair->current->clicks || $pair->previous->impressions > $pair->current->impressions)) {
				$items[] = ['keyword' => $texts[$pair->keywordId], 'current' => $pair->current, 'previous' => $pair->previous, 'context' => true];
			}
		}

		return $items;
	}

	private static function current(KeywordRow $row): Stats
	{
		return new Stats($row->clicks, $row->impressions, $row->positionSum);
	}

	private static function previous(KeywordRow $row): Stats
	{
		return new Stats($row->previousClicks, $row->previousImpressions, $row->previousPositionSum);
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function serializeItem(array $item): array
	{
		foreach ($item as $key => $value) {
			if ($value instanceof Stats) {
				$item[$key] = $value->toArray();
			} elseif (is_float($value)) {
				$item[$key] = round($value, 6);
			}
		}

		return $item;
	}

	/**
	 * @return array{days: int, current: array{0: string, 1: string}, previous: array{0: string, 1: string}, previous_covered: bool}
	 */
	private static function periodEvidence(DetectionInput $input): array
	{
		return [
			'days' => $input->period->days,
			'current' => [$input->period->current->start, $input->period->current->end],
			'previous' => [$input->period->previous->start, $input->period->previous->end],
			'previous_covered' => $input->previousCovered,
		];
	}

	/**
	 * @param list<string|null> $parts
	 */
	private static function searchText(array $parts): string
	{
		return mb_substr(implode("\n", array_unique(array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''))), 0, 10000);
	}
}
