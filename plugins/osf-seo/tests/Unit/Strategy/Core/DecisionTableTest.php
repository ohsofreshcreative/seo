<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy\Core;

use OsfSeo\Strategy\Decision\PriorityModel;
use OsfSeo\Strategy\Decision\ReasonCode;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\Target\SlugMatcher;
use OsfSeo\Strategy\Target\TargetState;
use OsfSeo\Strategy\Topics\TopicRefresher;
use OsfSeo\Tests\Support\StrategyFakes;
use PHPUnit\Framework\TestCase;

/**
 * Tabela decyzji rdzenia Strategii (faza C, przypadki A–T): brak widoczności nigdy nie dowodzi braku strony, „create” tylko z twardymi
 * bramkami, pozycja zależna od świeżości pomiaru, rodziny dowodów liczone raz.
 */
final class DecisionTableTest extends TestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	public function test_a_no_gsc_impressions_and_competitor_ranking_is_never_a_new_page(): void
	{
		// Labs: wiarygodny brak projektu w punkcie odniesienia, konkurenci rankują — bez świeżego pomiaru SERP.
		$missing = StrategyFakes::evaluate([StrategyFakes::keyword('agencja seo łódź', [
			'gsc' => [],
			'gap' => ['gap_type' => 'missing', 'visibility' => 'none', 'visibility_source' => 'labs', 'competitors' => 3, 'competitors_top10' => 3, 'target' => null],
		])]);
		self::assertSame(TargetState::None, $missing['facts']->target->state, 'Wiarygodny brak w Labs to dowód braku widoczności, nie braku strony.');
		self::assertSame([StrategyAction::Investigate, ReasonCode::SERP_REQUIRED], [$missing['decision']->action, $missing['decision']->reason]);

		// Luka bez wiarygodnego dowodu widoczności (nieznana) — tylko Labs.
		$unknown = StrategyFakes::evaluate([StrategyFakes::keyword('agencja seo łódź', [
			'gsc' => [],
			'gap' => ['gap_type' => 'unknown', 'visibility' => 'unknown', 'visibility_source' => null, 'competitors' => 3, 'competitors_top10' => 3, 'target' => null],
		])]);
		self::assertSame([StrategyAction::Investigate, ReasonCode::LABS_ONLY], [$unknown['decision']->action, $unknown['decision']->reason]);
	}

	public function test_b_no_gsc_and_no_project_in_fresh_serp_without_page_evidence_is_not_a_new_page(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('strony www dla firm', [
			'gsc' => [],
			'serp' => ['found' => false, 'competitors_top10' => 1, 'profile' => StrategyFakes::profile('mixed', 'low')],
		])]);

		self::assertSame(TargetState::None, $result['facts']->target->state);
		self::assertSame([StrategyAction::Investigate, ReasonCode::POSSIBLE_EXISTING_PAGE], [$result['decision']->action, $result['decision']->reason]);
		self::assertNotSame('high', $result['confidence']->level);
	}

	public function test_c_fresh_serp_without_project_real_demand_and_content_gap_is_a_new_page_candidate_with_at_most_medium_confidence(): void
	{
		$keyword = StrategyFakes::keyword('sklep internetowy dla piekarni', [
			'volume' => 880,
			'gsc' => [],
			'serp' => ['found' => false, 'competitors_top10' => 3, 'profile' => StrategyFakes::profile('subpage', 'high')],
			'content_gap' => ['id' => 'CLU1', 'label' => 'sklep internetowy', 'content_gap' => 'new_page', 'confidence' => 'high', 'reason' => 'no_target', 'status' => 'new'],
			'gap' => ['gap_type' => 'missing', 'visibility' => 'none', 'visibility_source' => 'serp', 'competitors' => 3, 'competitors_top10' => 3, 'target' => null],
		]);
		$result = StrategyFakes::evaluate([$keyword]);

		self::assertSame([StrategyAction::Create, ReasonCode::NEW_PAGE_CANDIDATE], [$result['decision']->action, $result['decision']->reason]);
		self::assertSame(['content_gap_new_page', 'competitors_top10', 'serp_dedicated_pages'], $result['decision']->basis);
		self::assertSame('medium', $result['confidence']->level, 'Bez pełnego indeksu stron projektu najwyżej średnia pewność.');
		self::assertContains('create_without_page_index', $result['confidence']->caps);

		// Ręczne potwierdzenie braku strony albo pełny indeks stron pozwalają na wyższą pewność.
		self::assertSame('high', StrategyFakes::evaluate([$keyword], null, true)['confidence']->level);
		self::assertSame('high', StrategyFakes::evaluate([$keyword], pageIndexComplete: true)['confidence']->level);

		// Bez realnego popytu — nie.
		$small = StrategyFakes::evaluate([StrategyFakes::keyword('sklep internetowy dla piekarni', ['volume' => 20, 'gsc' => [], 'serp' => ['found' => false, 'competitors_top10' => 3]])]);
		self::assertSame([StrategyAction::Investigate, ReasonCode::LOW_DEMAND], [$small['decision']->action, $small['decision']->reason]);
	}

	public function test_d_slug_only_target_is_investigated(): void
	{
		$slugs = new SlugMatcher(['https://example.pl/', 'https://example.pl/oferta/pozycjonowanie-stron/', 'https://example.pl/blog/']);
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', ['gsc' => []])], slugs: $slugs);

		self::assertSame(TargetState::Unknown, $result['facts']->target->state);
		self::assertTrue($result['facts']->target->weak());
		self::assertSame('https://example.pl/oferta/pozycjonowanie-stron/', $result['facts']->target->url);
		self::assertSame([StrategyAction::Investigate, ReasonCode::TARGET_WEAK], [$result['decision']->action, $result['decision']->reason]);
		self::assertNotSame('high', $result['confidence']->level);
	}

	public function test_e_random_project_url_at_87_is_not_a_target_to_optimize(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => [],
			'serp' => ['rank' => 87, 'url' => 'https://example.pl/blog/stary-wpis/'],
		])]);

		self::assertSame(TargetState::Unknown, $result['facts']->target->state);
		self::assertNotSame(StrategyAction::Optimize, $result['decision']->action);
		self::assertSame([StrategyAction::Investigate, ReasonCode::TARGET_WEAK], [$result['decision']->action, $result['decision']->reason]);
	}

	public function test_f_confirmed_page_at_14_is_optimized(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 15.0],
			'serp' => ['rank' => 14, 'url' => self::PAGE],
		])]);

		self::assertSame(TargetState::Confirmed, $result['facts']->target->state);
		self::assertSame(['gsc', 'serp'], $result['facts']->target->families);
		self::assertSame([StrategyAction::Optimize, ReasonCode::SERP_POSITION], [$result['decision']->action, $result['decision']->reason]);
		self::assertTrue($result['decision']->positionDependent);
	}

	public function test_g_confirmed_page_falling_from_8_to_24_is_recovered(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 9.0],
			'serp' => ['rank' => 24, 'url' => self::PAGE, 'prev_rank' => 8, 'prev_url' => self::PAGE],
		])]);

		self::assertSame(TargetState::Confirmed, $result['facts']->target->state);
		self::assertSame([StrategyAction::Recover, ReasonCode::SERP_DECLINE], [$result['decision']->action, $result['decision']->reason]);
		self::assertSame(16, $result['facts']->serpDecline()['lost']);
		self::assertGreaterThan(0.0, $result['priority']->components['urgency']['value']);
	}

	public function test_h_impressions_split_between_two_urls_is_consolidated(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [['https://example.pl/a/', 450], ['https://example.pl/b/', 430], ['https://example.pl/c/', 120]], 'position' => 9.0],
		])]);

		self::assertSame(TargetState::Conflict, $result['facts']->target->state);
		self::assertSame([StrategyAction::Consolidate, ReasonCode::GSC_SPLIT], [$result['decision']->action, $result['decision']->reason]);
		self::assertTrue($result['facts']->conflicts[0]->isStrong());

		// Ten sam podział w TOP3 (dwa wyniki na górze) — słaby sygnał, bez konsolidacji.
		$top = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [['https://example.pl/a/', 450], ['https://example.pl/b/', 430]], 'position' => 2.0],
		])]);
		self::assertNotSame(StrategyAction::Consolidate, $top['decision']->action);
	}

	public function test_i_single_url_flip_is_investigated_not_consolidated(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => [],
			'serp' => ['rank' => 6, 'url' => 'https://example.pl/b/', 'prev_rank' => 7, 'prev_url' => 'https://example.pl/a/'],
		])]);

		self::assertSame(TargetState::Probable, $result['facts']->target->state);
		self::assertSame([StrategyAction::Investigate, ReasonCode::URL_FLIP], [$result['decision']->action, $result['decision']->reason]);

		// Potwierdzona strona (GSC + SERP) — pojedyncza zmiana adresu nie blokuje optymalizacji, tylko obniża pewność.
		$confirmed = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [['https://example.pl/b/', 300]]],
			'serp' => ['rank' => 6, 'url' => 'https://example.pl/b/', 'prev_rank' => 7, 'prev_url' => 'https://example.pl/a/'],
		])]);
		self::assertSame(StrategyAction::Optimize, $confirmed['decision']->action);
		self::assertContains('url_flip', array_column($confirmed['confidence']->factors, 'code'));
	}

	public function test_j_stable_top2_is_monitored(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 900]], 'position' => 2.1],
			'serp' => ['rank' => 2, 'url' => self::PAGE, 'prev_rank' => 2, 'prev_url' => self::PAGE],
		])]);

		self::assertSame([StrategyAction::Monitor, ReasonCode::TOP3_SERP], [$result['decision']->action, $result['decision']->reason]);
		self::assertSame(PriorityModel::MONITOR_FACTOR, $result['priority']->actionFactor, 'Monitorowanie ma obniżony priorytet.');
	}

	public function test_q_missing_difficulty_is_neutral_not_zero(): void
	{
		$base = ['gsc' => ['pages' => [[self::PAGE, 400]]], 'serp' => ['rank' => 14, 'url' => self::PAGE]];
		$unknown = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', ['kd' => null] + $base)])['priority'];
		$zero = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', ['kd' => 0] + $base)])['priority'];
		$hard = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', ['kd' => 100] + $base)])['priority'];

		self::assertSame([5.0, false], [$unknown->components['attainability']['value'], $unknown->components['attainability']['difficulty_known']]);
		self::assertSame(10.0, $zero->components['attainability']['value']);
		self::assertSame(0.0, $hard->components['attainability']['value']);
		self::assertLessThan($zero->value, $unknown->value);
		self::assertGreaterThan($hard->value, $unknown->value);
	}

	public function test_r_serp_31_to_90_days_old_gives_no_current_project_position(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 12.0],
			'serp' => ['checked_at' => StrategyFakes::daysAgo(45), 'rank' => 5, 'url' => self::PAGE],
		])]);

		self::assertNull($result['facts']->leader->serpRank());
		self::assertNull($result['facts']->primarySerp());
		self::assertSame('stale', TopicRefresher::serpBand($result['facts']));
		self::assertSame([StrategyAction::Optimize, ReasonCode::GSC_POSITION], [$result['decision']->action, $result['decision']->reason], 'Pozycja tylko ze średniej pozycji (GSC).');
		self::assertSame(['gsc'], $result['facts']->target->families, 'Nieaktualny pomiar nie wskazuje strony.');
		self::assertContains('stale_serp', $result['confidence']->caps);
		self::assertNotSame('high', $result['confidence']->level);
	}

	public function test_s_serp_older_than_90_days_has_no_influence_on_the_action(): void
	{
		$base = ['gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 12.0]];
		$without = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', $base)]);
		$expired = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', $base + ['serp' => ['checked_at' => StrategyFakes::daysAgo(120), 'rank' => 2, 'url' => 'https://example.pl/inna/']])]);

		self::assertSame([$without['decision']->action, $without['decision']->reason], [$expired['decision']->action, $expired['decision']->reason]);
		self::assertSame($without['facts']->target->state, $expired['facts']->target->state);
		self::assertSame($without['facts']->target->url, $expired['facts']->target->url);
		self::assertNull($expired['facts']->profiledSerp());
	}

	public function test_t_two_signals_from_the_same_gsc_data_are_not_independent_confirmations(): void
	{
		$result = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 12.0],
			'opportunities' => [['type' => 'near_top', 'page' => self::PAGE]],
			'discovery' => ['id' => 'DSC1', 'status' => 'accepted', 'priority' => 70, 'visibility_gsc' => 'weak', 'target_url' => self::PAGE],
			'gap' => ['gap_type' => 'weak', 'visibility' => 'low', 'visibility_source' => 'gsc', 'competitors' => 2, 'competitors_top10' => 2, 'target' => ['url' => self::PAGE, 'source' => 'gsc']],
		])]);
		$target = $result['facts']->target;

		self::assertSame(TargetState::Probable, $target->state, 'GSC, szansa SEO, Nowe frazy i luka ze źródłem GSC to jedna rodzina dowodów.');
		self::assertSame(['gsc'], $target->families);
		self::assertSame(['gap_gsc', 'opportunity', 'discovery'], array_column($target->derived, 'source'));
		$codes = array_column($result['confidence']->factors, 'code');
		self::assertSame(['target_probable', 'family_gsc'], array_values(array_filter($codes, static fn (string $code): bool => str_starts_with($code, 'target_') || $code === 'family_gsc')), 'Strona tylko prawdopodobna, GSC liczone raz.');

		// Niezależna rodzina (Labs) potwierdza stronę.
		$labs = StrategyFakes::evaluate([StrategyFakes::keyword('pozycjonowanie stron', [
			'gsc' => ['pages' => [[self::PAGE, 400]], 'position' => 12.0],
			'gap' => ['gap_type' => 'weak', 'visibility' => 'low', 'visibility_source' => 'labs', 'project_labs_rank' => 14, 'competitors' => 2, 'competitors_top10' => 2, 'target' => ['url' => self::PAGE, 'source' => 'labs']],
		])]);
		self::assertSame([TargetState::Confirmed, ['gsc', 'labs']], [$labs['facts']->target->state, $labs['facts']->target->families]);
	}
}
