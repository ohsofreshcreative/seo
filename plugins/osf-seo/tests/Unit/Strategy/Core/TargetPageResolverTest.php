<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy\Core;

use OsfSeo\Strategy\Target\SlugMatcher;
use OsfSeo\Strategy\Target\TargetPageResolver;
use OsfSeo\Strategy\Target\TargetState;
use OsfSeo\Strategy\Topics\ConflictSignal;
use OsfSeo\Strategy\Topics\UrlConflictDetector;
use OsfSeo\Tests\Support\StrategyFakes;
use PHPUnit\Framework\TestCase;

/**
 * Strona docelowa (faza C, D57): kolejność źródeł, siła SERP według pozycji, udziały GSC, Labs najwyżej średnio, slug zawsze słaby,
 * stany i sygnały konfliktu URL.
 */
final class TargetPageResolverTest extends TestCase
{
	private const A = 'https://example.pl/a/';

	private const B = 'https://example.pl/b/';

	public function test_serp_strength_depends_on_the_rank(): void
	{
		$resolver = new TargetPageResolver();
		$strength = static fn (int $rank): string => $resolver->votes(StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['rank' => $rank, 'url' => self::A]]))['votes'][0]->strength->value;

		self::assertSame(['strong', 'strong', 'medium', 'medium', 'weak'], [$strength(1), $strength(20), $strength(21), $strength(50), $strength(51)]);
		self::assertSame(TargetState::Probable, $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['rank' => 14, 'url' => self::A]]))->state, 'Jedna rodzina silnie — prawdopodobna.');
		$medium = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['rank' => 35, 'url' => self::A]]));
		self::assertSame([TargetState::Unknown, true], [$medium->state, $medium->weak()], 'Pojedyncze średnie wskazanie to za mało.');
	}

	public function test_gsc_shares_and_independent_families(): void
	{
		$resolver = new TargetPageResolver();
		$dominant = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 700], [self::B, 100]]]]));
		self::assertSame([TargetState::Probable, self::A, ['gsc']], [$dominant->state, $dominant->url, $dominant->families]);

		$secondary = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 650], [self::B, 350]]]]));
		self::assertSame([TargetState::Probable, ['independent_families'] === $secondary->reasons], [$secondary->state, false]);
		self::assertContains('secondary_url', $secondary->reasons);

		$split = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 480], [self::B, 450]]]]));
		self::assertSame([TargetState::Conflict, ['competing_urls']], [$split->state, $split->reasons]);

		$serpAndGsc = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 700]]], 'serp' => ['rank' => 30, 'url' => self::A]]));
		self::assertSame([TargetState::Confirmed, ['gsc', 'serp']], [$serpAndGsc->state, $serpAndGsc->families], 'GSC silnie + SERP średnio = dwie niezależne rodziny.');

		$rivals = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 700]]], 'serp' => ['rank' => 8, 'url' => self::B]]));
		self::assertSame(TargetState::Conflict, $rivals->state, 'SERP i GSC wskazują różne strony.');
	}

	public function test_labs_is_at_most_medium_and_slug_is_always_weak(): void
	{
		$resolver = new TargetPageResolver();
		$labs = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => [], 'gap' => ['project_labs_rank' => 5, 'target' => ['url' => self::A, 'source' => 'labs']]]));
		self::assertSame([TargetState::Unknown, 'medium'], [$labs->state, $labs->votes[0]->strength->value], 'Samo Labs — za mało na stronę docelową.');

		$slug = $resolver->forKeyword(StrategyFakes::keyword('pozycjonowanie stron', ['gsc' => []]), new SlugMatcher(['https://example.pl/pozycjonowanie-stron/']));
		self::assertSame(['slug', 'weak'], [$slug->votes[0]->family->value, $slug->votes[0]->strength->value]);
		self::assertTrue($slug->weak());

		// Slug tylko bez innych wskazań.
		$withGsc = $resolver->forKeyword(StrategyFakes::keyword('pozycjonowanie stron', ['gsc' => ['pages' => [[self::A, 700]]]]), new SlugMatcher(['https://example.pl/pozycjonowanie-stron/']));
		self::assertSame(['gsc'], array_map(static fn ($vote): string => $vote->family->value, $withGsc->votes));
	}

	public function test_none_requires_no_candidates_and_extra_no_visibility_evidence(): void
	{
		$resolver = new TargetPageResolver();

		self::assertSame(TargetState::Unknown, $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => []]))->state, 'Brak wyświetleń GSC nie jest dowodem braku widoczności.');
		self::assertSame(TargetState::Unknown, $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => null]))->state);
		self::assertSame(TargetState::None, $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['found' => false]]))->state);

		// Ślad starszego pomiaru (projekt rankował 60 dni temu) — strona może istnieć.
		$history = $resolver->forKeyword(StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['checked_at' => StrategyFakes::daysAgo(60), 'rank' => 4, 'url' => self::A]]));
		self::assertSame([TargetState::Unknown, ['possible_existing_page']], [$history->state, $history->reasons]);
		self::assertSame([['url' => self::A, 'source' => 'serp_history']], $history->hints);
	}

	public function test_manual_target_and_manual_no_page(): void
	{
		$resolver = new TargetPageResolver();
		$keyword = StrategyFakes::keyword('fraza', ['gsc' => ['pages' => [[self::A, 700]]]]);
		$manual = $resolver->forTopic([$resolver->forKeyword($keyword)], self::B);

		self::assertSame([TargetState::Confirmed, self::B, ['manual']], [$manual->state, $manual->url, $manual->families]);
		self::assertSame(self::A, $manual->alternatives[0]['url']);

		$none = $resolver->forTopic([$resolver->forKeyword($keyword)], null, true);
		self::assertSame([TargetState::None, true], [$none->state, $none->manualNone]);
	}

	public function test_url_conflict_signals(): void
	{
		$detector = new UrlConflictDetector();
		$resolver = new TargetPageResolver();
		$signals = static function (array $options) use ($detector, $resolver): array {
			$keyword = StrategyFakes::keyword('fraza', $options);

			return array_map(static fn (ConflictSignal $signal): string => $signal->type . ':' . $signal->strength, $detector->detect($keyword, $resolver->forKeyword($keyword)));
		};

		self::assertSame(['cannibalization:strong'], $signals(['opportunities' => [['type' => 'cannibalization', 'confidence' => 2, 'urls' => [self::A, self::B]]]]));
		self::assertSame(['cannibalization:moderate'], $signals(['opportunities' => [['type' => 'cannibalization', 'confidence' => 1, 'urls' => [self::A, self::B]]]]));
		self::assertSame([], $signals(['opportunities' => [['type' => 'cannibalization', 'confidence' => 3, 'status' => 'dismissed', 'urls' => [self::A, self::B]]]]), 'Odrzucona szansa nie jest dowodem.');
		self::assertSame(['gsc_split:strong'], $signals(['gsc' => ['pages' => [[self::A, 500], [self::B, 400]], 'position' => 8.0]]));
		self::assertSame(['gsc_split:moderate'], $signals(['gsc' => ['pages' => [[self::A, 750], [self::B, 250]], 'position' => 8.0]]));
		self::assertSame(['gsc_split:weak'], $signals(['gsc' => ['pages' => [[self::A, 500], [self::B, 400]], 'position' => 2.0]]));
		self::assertSame(['url_flip:weak'], $signals(['gsc' => [], 'serp' => ['rank' => 9, 'url' => self::B, 'prev_rank' => 9, 'prev_url' => self::A]]));
		self::assertSame(['multiple_top10:moderate'], $signals(['gsc' => [], 'serp' => ['rank' => 4, 'url' => self::A, 'top10' => [['url' => self::A, 'rank' => 4], ['url' => self::B, 'rank' => 9]]]]));
		self::assertSame(['multiple_top10:weak'], $signals(['gsc' => [], 'serp' => ['rank' => 1, 'url' => self::A, 'top10' => [['url' => self::A, 'rank' => 1], ['url' => self::B, 'rank' => 2]]]]));

		// Ręczna strona ≠ adres rankujący: raz — umiarkowany, w dwóch porównywalnych pomiarach — silny.
		$keyword = StrategyFakes::keyword('fraza', ['gsc' => [], 'serp' => ['rank' => 5, 'url' => self::A, 'prev_rank' => 6, 'prev_url' => self::A]]);
		$target = $resolver->forTopic([$resolver->forKeyword($keyword)], self::B);
		self::assertSame(['target_mismatch:strong'], array_map(static fn (ConflictSignal $signal): string => $signal->type . ':' . $signal->strength, $detector->detect($keyword, $target)));
	}
}
