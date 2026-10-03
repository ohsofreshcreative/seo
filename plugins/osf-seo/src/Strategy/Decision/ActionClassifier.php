<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

use OsfSeo\Gap\GapConfig;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Strategy\Target\TargetState;
use OsfSeo\Strategy\Topics\ConflictSignal;

/**
 * Działanie tematu deterministycznymi regułami w kolejności consolidate → recover → optimize → create → monitor → investigate
 * (D58 — docs/ARCHITECTURE.md, sekcja 15.13). Brak danych nigdy nie daje fałszywej pewności — wtedy „investigate” z kodem powodu.
 *
 * - **consolidate**: silny konflikt URL (szansa kanibalizacji z pewnością ≥ średnia, podział wyświetleń GSC, silny overlap przy różnych
 *   stronach docelowych, adres rankujący spójnie ≠ potwierdzona strona); pojedyncza zmiana adresu → investigate:url_flip,
 * - **recover**: znana strona docelowa i realny spadek (Pozycja SERP w porównywalnych pomiarach albo szansa „spadek” z GSC); Labs nigdy samo,
 * - **optimize**: znana strona docelowa (nie samo dopasowanie adresu ani przypadkowy wynik #87) i sygnał: pozycja 4–50, szanse SEO
 *   (niski CTR, blisko TOP, słaba pozycja), słaba luka fraz, luka treści „do wzmocnienia”; sprzeczna intencja → investigate,
 * - **create** (Kandydat na nową stronę): stan „brak znanej strony”, realny popyt (wolumen od progu albo zaakceptowana Nowa fraza), świeży
 *   pomiar SERP i co najmniej jeden dodatkowy dowód (luka treści „nowa strona”, ≥ 2 konkurentów w TOP10, dedykowane strony w SERP),
 * - **monitor**: projekt w TOP3 bez spadku, konfliktu i otwartej szansy (nigdy automatyczne dodanie do monitorowania pozycji),
 * - **investigate**: pełny wynik z powodem (serp_required, target_unknown, target_weak, possible_existing_page, intent_mismatch, labs_only,
 *   url_flip, data_incomplete, conflicting_evidence, low_demand, low_visibility).
 */
final class ActionClassifier
{
	public const CREATE_MIN_VOLUME = GapConfig::MIN_CONTENT_GAP_VOLUME;

	public const CREATE_MIN_COMPETITORS = 2;

	public const MONITOR_MAX_RANK = 3;

	public const OPTIMIZE_MAX_POSITION = 50;

	/** Typy konfliktu, które przy sile `strong` uzasadniają konsolidację (kolejność = kolejność powodu). */
	private const CONSOLIDATE = [
		ConflictSignal::CANNIBALIZATION => ReasonCode::CANNIBALIZATION,
		ConflictSignal::GSC_SPLIT => ReasonCode::GSC_SPLIT,
		ConflictSignal::OVERLAP_TARGETS => ReasonCode::OVERLAP_TARGETS,
		ConflictSignal::TARGET_MISMATCH => ReasonCode::TARGET_MISMATCH,
	];

	/** Szanse SEO uzasadniające optymalizację (kolejność = kolejność powodu). */
	private const OPTIMIZE_OPPORTUNITIES = [
		OpportunityType::LowCtr->value => ReasonCode::LOW_CTR,
		OpportunityType::NearTop->value => ReasonCode::NEAR_TOP,
		OpportunityType::WeakPosition->value => ReasonCode::WEAK_POSITION,
	];

	public function classify(TopicFacts $facts): Decision
	{
		$checks = [];
		$target = $facts->target;
		$hasTarget = $target->state->hasTarget();

		// 1. Konsolidacja — silny konflikt URL (istniejące strony projektu).
		$consolidate = [];

		foreach ($facts->conflicts(ConflictSignal::STRONG) as $signal) {
			if (isset(self::CONSOLIDATE[$signal->type])) {
				$consolidate[self::CONSOLIDATE[$signal->type]] = array_search($signal->type, array_keys(self::CONSOLIDATE), true);
			}
		}

		asort($consolidate);

		if ($consolidate !== [] && ! $target->manualNone) {
			return new Decision(StrategyAction::Consolidate, (string) array_key_first($consolidate), array_keys($consolidate), [self::check('consolidate', true, 'strong_url_conflict')], false);
		}

		$checks[] = self::check('consolidate', false, $consolidate === [] ? 'no_strong_url_conflict' : 'manual_no_page');

		if ($target->state === TargetState::Conflict) {
			$checks[] = self::check('target', false, 'competing_urls');

			return self::investigate($facts->hasConflict(ConflictSignal::URL_FLIP) ? ReasonCode::URL_FLIP : ReasonCode::CONFLICTING_EVIDENCE, $checks);
		}

		if ($facts->hasConflict(ConflictSignal::URL_FLIP) && $target->state !== TargetState::Confirmed) {
			$checks[] = self::check('target', false, 'url_flip_without_confirmed_target');

			return self::investigate(ReasonCode::URL_FLIP, $checks);
		}

		// 2. Odzyskanie — znana strona i realny spadek (Labs nigdy samo).
		$serpDecline = $facts->serpDecline() !== null;
		$gscDecline = $facts->hasOpportunity(OpportunityType::Decline);

		if ($hasTarget && ($serpDecline || $gscDecline)) {
			$reason = $serpDecline && $gscDecline ? ReasonCode::SERP_GSC_DECLINE : ($serpDecline ? ReasonCode::SERP_DECLINE : ReasonCode::GSC_DECLINE);
			$checks[] = self::check('recover', true, $reason);

			return new Decision(StrategyAction::Recover, $reason, array_values(array_filter([$serpDecline ? ReasonCode::SERP_DECLINE : null, $gscDecline ? ReasonCode::GSC_DECLINE : null])), $checks, true);
		}

		$checks[] = self::check('recover', false, ! $hasTarget ? 'no_known_target' : 'no_decline');

		// 3. Optymalizacja — znana strona i sygnał potencjału.
		$position = null;

		if ($hasTarget) {
			$basis = $this->optimizeBasis($facts);

			if ($basis !== []) {
				if ($facts->intentMismatch() !== null) {
					$checks[] = self::check('optimize', false, 'intent_mismatch');

					return self::investigate(ReasonCode::INTENT_MISMATCH, $checks);
				}

				$checks[] = self::check('optimize', true, $basis[0]);

				return new Decision(StrategyAction::Optimize, $basis[0], $basis, $checks, in_array($basis[0], [ReasonCode::SERP_POSITION, ReasonCode::GSC_POSITION], true));
			}

			$position = $facts->primarySerp()?->serpRank() ?? ($facts->primarySerp() === null ? $facts->gscPosition() : null);
			$checks[] = self::check('optimize', false, 'no_optimization_signal');
		} else {
			$checks[] = self::check('optimize', false, $target->weak() ? 'weak_target' : 'no_known_target');
		}

		// 4. Kandydat na nową stronę — najbardziej zachowawcza reguła.
		if ($target->state === TargetState::None) {
			$create = $this->createGate($facts);
			$checks[] = self::check('create', $create === null, $create ?? ReasonCode::NEW_PAGE_CANDIDATE);

			if ($create === null) {
				return new Decision(StrategyAction::Create, ReasonCode::NEW_PAGE_CANDIDATE, $this->createEvidence($facts), $checks, false);
			}

			return self::investigate($create, $checks);
		}

		$checks[] = self::check('create', false, 'target_not_none');

		// 5. Monitorowanie — TOP3 bez problemów.
		$monitor = $hasTarget ? $this->monitor($facts) : null;

		if ($monitor !== null) {
			$checks[] = self::check('monitor', true, $monitor);

			return new Decision(StrategyAction::Monitor, $monitor, [$monitor], $checks, true);
		}

		$checks[] = self::check('monitor', false, $hasTarget ? 'not_stable_top3' : 'no_known_target');

		// 6. Do sprawdzenia.
		return self::investigate($this->investigateReason($facts, $position), $checks);
	}

	/**
	 * Podstawy optymalizacji w kolejności siły dowodu.
	 *
	 * @return list<string>
	 */
	private function optimizeBasis(TopicFacts $facts): array
	{
		$basis = [];
		$primary = $facts->primarySerp();
		$rank = $primary?->serpRank();

		if ($rank !== null && $rank > self::MONITOR_MAX_RANK && $rank <= self::OPTIMIZE_MAX_POSITION) {
			$basis[] = ReasonCode::SERP_POSITION;
		}

		foreach (self::OPTIMIZE_OPPORTUNITIES as $type => $code) {
			if (in_array($type, $facts->opportunityTypes(), true)) {
				$basis[] = $code;
			}
		}

		$gscPosition = $facts->gscPosition();

		// Średnia pozycja (GSC) tylko bez świeżego pomiaru SERP (gdy jest pomiar, decyduje Pozycja SERP).
		if ($primary === null && $gscPosition !== null && $gscPosition > self::MONITOR_MAX_RANK && $gscPosition <= self::OPTIMIZE_MAX_POSITION) {
			$basis[] = ReasonCode::GSC_POSITION;
		}

		if ($facts->gapWeak()) {
			$basis[] = ReasonCode::GAP_WEAK;
		}

		if (($facts->contentGap()['content_gap'] ?? null) === 'improve') {
			$basis[] = ReasonCode::CONTENT_IMPROVE;
		}

		return $basis;
	}

	/** Bramki kandydata na nową stronę: null — spełnione, inaczej kod powodu „investigate”. */
	private function createGate(TopicFacts $facts): ?string
	{
		if (! $facts->target->manualNone && $facts->primarySerp() === null) {
			return ReasonCode::SERP_REQUIRED;
		}

		if (((int) $facts->demand()) < self::CREATE_MIN_VOLUME && ! $facts->acceptedDiscovery()) {
			return ReasonCode::LOW_DEMAND;
		}

		if ($facts->opportunities() !== [] || (int) $facts->gscImpressions() > 0) {
			// Szansa SEO albo wyświetlenia GSC oznaczają widoczną stronę projektu.
			return ReasonCode::POSSIBLE_EXISTING_PAGE;
		}

		if ($this->createEvidence($facts) === []) {
			return ReasonCode::POSSIBLE_EXISTING_PAGE;
		}

		return null;
	}

	/**
	 * Dodatkowe dowody kandydata na nową stronę.
	 *
	 * @return list<string>
	 */
	private function createEvidence(TopicFacts $facts): array
	{
		$evidence = [];

		if (($facts->contentGap()['content_gap'] ?? null) === 'new_page') {
			$evidence[] = 'content_gap_new_page';
		}

		if ($facts->competitorsTop10() >= self::CREATE_MIN_COMPETITORS) {
			$evidence[] = 'competitors_top10';
		}

		if ($facts->serpDedicatedPages()) {
			$evidence[] = 'serp_dedicated_pages';
		}

		return $evidence;
	}

	private function monitor(TopicFacts $facts): ?string
	{
		if ($facts->serpDecline() !== null || $facts->conflicts(ConflictSignal::MODERATE) !== [] || $facts->opportunities() !== []) {
			return null;
		}

		$primary = $facts->primarySerp();

		if ($primary !== null) {
			$rank = $primary->serpRank();

			return $rank !== null && $rank <= self::MONITOR_MAX_RANK ? ReasonCode::TOP3_SERP : null;
		}

		$position = $facts->gscPosition();

		return $facts->profiledSerp() === null && $position !== null && $position <= self::MONITOR_MAX_RANK ? ReasonCode::TOP3_GSC : null;
	}

	private function investigateReason(TopicFacts $facts, float|int|null $position): string
	{
		$target = $facts->target;

		if ($facts->conflicts(ConflictSignal::MODERATE) !== []) {
			return ReasonCode::CONFLICTING_EVIDENCE;
		}

		if ($target->state->hasTarget()) {
			if ($position !== null && $position > self::OPTIMIZE_MAX_POSITION) {
				return ReasonCode::LOW_VISIBILITY;
			}

			return $facts->primarySerp() === null ? ReasonCode::SERP_REQUIRED : ReasonCode::LOW_VISIBILITY;
		}

		if ($target->weak()) {
			return ReasonCode::TARGET_WEAK;
		}

		if ($facts->labsOnly()) {
			return ReasonCode::LABS_ONLY;
		}

		if ($target->hints !== []) {
			return ReasonCode::POSSIBLE_EXISTING_PAGE;
		}

		if (! $facts->gscKnown() || ! $facts->gscComplete) {
			return ReasonCode::DATA_INCOMPLETE;
		}

		if ($facts->primarySerp() === null) {
			return ReasonCode::SERP_REQUIRED;
		}

		return ReasonCode::TARGET_UNKNOWN;
	}

	/**
	 * @param list<array{rule: string, passed: bool, why: string}> $checks
	 */
	private static function investigate(string $reason, array $checks): Decision
	{
		return new Decision(StrategyAction::Investigate, $reason, [$reason], $checks, false);
	}

	/**
	 * @return array{rule: string, passed: bool, why: string}
	 */
	private static function check(string $rule, bool $passed, string $why): array
	{
		return ['rule' => $rule, 'passed' => $passed, 'why' => $why];
	}
}
