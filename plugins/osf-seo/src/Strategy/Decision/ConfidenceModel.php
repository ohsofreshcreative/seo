<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

use OsfSeo\Opportunities\Confidence;
use OsfSeo\Strategy\Target\TargetState;
use OsfSeo\Strategy\Topics\ConflictSignal;

/**
 * Pewność rekomendacji tematu (D59 — docs/ARCHITECTURE.md, sekcja 15.13): punkty 0–100 z jawnymi czynnikami dodatnimi i ujemnymi,
 * poziom niski / średni / wysoki i limity. Rodziny dowodów liczone raz (sygnały pochodne z tych samych danych GSC nie dodają punktów).
 *
 * Limity: investigate ≤ średnia; create ≤ średnia (bez pełnego indeksu stron projektu albo ręcznego potwierdzenia braku strony);
 * słaba strona docelowa nigdy wysoka; nieaktualny pomiar SERP nigdy wysoka przy decyzji zależnej od pozycji.
 */
final class ConfidenceModel
{
	public const BASE = 40;

	public const HIGH = 70;

	public const MEDIUM = 45;

	/** Najwyższa liczba punktów poziomu średniego (limity). */
	public const MEDIUM_CAP = self::HIGH - 1;

	public function evaluate(TopicFacts $facts, Decision $decision): ConfidenceResult
	{
		$factors = [];
		$target = $facts->target;

		$factors[] = match (true) {
			$target->manual && ! $target->manualNone => ['manual_target', 18],
			$target->manualNone => ['manual_no_page', 10],
			$target->state === TargetState::Confirmed => ['target_confirmed', 15],
			$target->state === TargetState::Probable => ['target_probable', 6],
			$target->state === TargetState::Conflict => ['target_conflict', -10],
			$target->state === TargetState::None => ['target_none', 0],
			$target->weak() => ['target_weak', -15],
			default => ['target_unknown', -10],
		};

		// Rodziny dowodów (każda raz).
		if ((int) $facts->gscImpressions() > 0) {
			$factors[] = ['family_gsc', 6];
		}

		if ($facts->primarySerp() !== null) {
			$factors[] = ['family_serp_fresh', 10];
		} elseif ($facts->profiledSerp() !== null) {
			$factors[] = ['family_serp_stale', 3];
		}

		if (self::hasLabs($facts)) {
			$factors[] = ['family_labs', 4];
		}

		// Próba i popyt.
		$impressions = (int) $facts->gscImpressions();

		if ($impressions >= 1000) {
			$factors[] = ['gsc_sample_large', 6];
		} elseif ($impressions >= 300) {
			$factors[] = ['gsc_sample_medium', 3];
		}

		if ($facts->demand() !== null) {
			$factors[] = ['volume_known', 3];
		}

		// Niezależne podstawy decyzji (np. spadek w SERP i w GSC).
		if (count(array_unique(array_map(self::basisFamily(...), $decision->basis))) >= 2) {
			$factors[] = ['independent_basis', 6];
		}

		foreach ($facts->opportunities() as $opportunity) {
			if ((int) ($opportunity['confidence'] ?? 0) >= Confidence::High->value) {
				$factors[] = ['opportunity_high_confidence', 4];

				break;
			}
		}

		if ($decision->action === StrategyAction::Create) {
			$factors[] = ['create_evidence', 4 * count($decision->basis)];
		}

		// Czynniki ujemne.
		if (! $facts->gscKnown()) {
			$factors[] = ['no_gsc_data', -8];
		} elseif (! $facts->gscComplete) {
			$factors[] = ['gsc_incomplete', -6];
		}

		if ($decision->positionDependent && $facts->primarySerp() === null) {
			$factors[] = $facts->staleSerpOnly() ? ['serp_stale', -8] : ['serp_missing', -6];
		}

		if ($decision->action !== StrategyAction::Consolidate && $facts->conflicts(ConflictSignal::STRONG) !== []) {
			$factors[] = ['conflict_strong', -12];
		}

		if ($facts->conflicts(ConflictSignal::MODERATE) !== []) {
			$factors[] = ['conflict_moderate', -6];
		}

		if ($facts->hasConflict(ConflictSignal::URL_FLIP)) {
			$factors[] = ['url_flip', -4];
		}

		if ($facts->spellCorrection() !== null) {
			$factors[] = ['spell_correction', -8];
		}

		if ($facts->intentMismatch() !== null) {
			$factors[] = ['intent_mismatch', -10];
		}

		if ($facts->labsOnly()) {
			$factors[] = ['labs_only', -12];
		}

		if ($target->state->hasTarget() && in_array('serp_not_found', $target->noVisibility, true)) {
			$factors[] = ['serp_not_found_with_target', -6];
		}

		$points = self::BASE + (int) array_sum(array_column($factors, 1));
		$points = max(0, min(100, $points));
		$caps = [];

		if ($decision->action === StrategyAction::Investigate) {
			$caps[] = 'investigate';
		}

		if ($decision->action === StrategyAction::Create && ! $target->manualNone && ! $facts->pageIndexComplete) {
			$caps[] = 'create_without_page_index';
		}

		if ($target->weak()) {
			$caps[] = 'target_weak';
		}

		if ($decision->positionDependent && $facts->staleSerpOnly()) {
			$caps[] = 'stale_serp';
		}

		if ($caps !== [] && $points > self::MEDIUM_CAP) {
			$points = self::MEDIUM_CAP;
		}

		return new ConfidenceResult(
			$points,
			self::level($points),
			array_map(static fn (array $factor): array => ['code' => $factor[0], 'points' => $factor[1]], array_values(array_filter($factors, static fn (array $factor): bool => $factor[1] !== 0 || $factor[0] === 'target_none'))),
			$caps,
		);
	}

	public static function level(int $points): string
	{
		return match (true) {
			$points >= self::HIGH => 'high',
			$points >= self::MEDIUM => 'medium',
			default => 'low',
		};
	}

	private static function hasLabs(TopicFacts $facts): bool
	{
		foreach ($facts->members as $member) {
			$gap = $member->gap();

			if ($gap !== null && ((int) ($gap['competitors'] ?? 0) > 0 || isset($gap['project_labs_rank']) || ($gap['visibility_source'] ?? null) === 'labs')) {
				return true;
			}
		}

		return false;
	}

	/** Rodzina danych podstawy decyzji (do liczenia niezależnych podstaw). */
	private static function basisFamily(string $basis): string
	{
		return match ($basis) {
			ReasonCode::SERP_DECLINE, ReasonCode::SERP_POSITION => 'serp',
			ReasonCode::GSC_DECLINE, ReasonCode::LOW_CTR, ReasonCode::NEAR_TOP, ReasonCode::WEAK_POSITION, ReasonCode::GSC_POSITION, ReasonCode::GSC_SPLIT, ReasonCode::CANNIBALIZATION => 'gsc',
			ReasonCode::GAP_WEAK, ReasonCode::CONTENT_IMPROVE => 'labs',
			'content_gap_new_page' => 'labs',
			'competitors_top10', 'serp_dedicated_pages' => 'serp',
			default => $basis,
		};
	}
}
