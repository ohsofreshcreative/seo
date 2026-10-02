<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gap;

use OsfSeo\Gap\GapClassifier;
use OsfSeo\Gap\GapType;
use OsfSeo\Gap\ProjectEvidence;
use OsfSeo\Gap\ProjectVisibility;
use OsfSeo\Gap\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityAndTypeTest extends TestCase
{
	private VisibilityResolver $resolver;

	private GapClassifier $classifier;

	protected function setUp(): void
	{
		$this->resolver = new VisibilityResolver(10, 10.0, 0.1, 90);
		$this->classifier = new GapClassifier(10.0, 5);
	}

	/**
	 * @return array{has_data: bool, impressions: int, position_sum: float}
	 */
	private static function gsc(bool $hasData, int $impressions = 0, float $position = 0.0): array
	{
		return ['has_data' => $hasData, 'impressions' => $impressions, 'position_sum' => $impressions * $position];
	}

	public function test_fresh_serp_measurement_wins_over_gsc_and_labs(): void
	{
		$evidence = $this->resolver->resolve(['found' => true, 'rank' => 34, 'depth' => 100], self::gsc(true, 500, 6.0), ['rank' => 3, 'absence_reliable' => true], 720);

		self::assertSame(ProjectVisibility::Low, $evidence->visibility);
		self::assertSame(ProjectEvidence::SOURCE_SERP, $evidence->source);
		self::assertSame(34.0, $evidence->position);
	}

	public function test_serp_out_of_top100_is_confirmed_absence(): void
	{
		$evidence = $this->resolver->resolve(['found' => false, 'rank' => null, 'depth' => 100], self::gsc(true, 0), null, 720);

		self::assertSame(ProjectVisibility::None, $evidence->visibility);
		self::assertSame(ProjectEvidence::SOURCE_SERP, $evidence->source);
		self::assertSame(GapType::Missing, $this->classifier->classify($evidence, 4));
	}

	public function test_serp_not_found_within_shallow_depth_is_not_absence(): void
	{
		$evidence = $this->resolver->resolve(['found' => false, 'rank' => null, 'depth' => 10], self::gsc(false), null, 720);

		self::assertSame(ProjectVisibility::Unknown, $evidence->visibility, 'Poza TOP10 to nie brak w TOP100.');
	}

	public function test_gsc_weighted_position_and_sporadic_visibility(): void
	{
		$weak = $this->resolver->resolve(null, self::gsc(true, 400, 38.5), null, 1300);
		self::assertSame(ProjectVisibility::Low, $weak->visibility);
		self::assertSame(38.5, $weak->position);
		self::assertSame(GapType::Weak, $this->classifier->classify($weak, 4));

		// Pozycja 4, ale 50 wyświetleń w 90 dniach przy wolumenie 1000 (oczekiwane ~3000) → sporadyczna.
		$sporadic = $this->resolver->resolve(null, self::gsc(true, 50, 4.0), null, 1000);
		self::assertSame(ProjectVisibility::Low, $sporadic->visibility);
		self::assertTrue($sporadic->sporadic);
		self::assertSame(GapType::Weak, $this->classifier->classify($sporadic, 2));

		$visible = $this->resolver->resolve(null, self::gsc(true, 3000, 2.4), null, 1000);
		self::assertSame(ProjectVisibility::Visible, $visible->visibility);
		self::assertSame(GapType::Stronger, $this->classifier->classify($visible, 4));
	}

	public function test_missing_gsc_alone_never_means_no_visibility(): void
	{
		// GSC ma dane projektu, fraza bez wyświetleń, brak punktu odniesienia Labs → Nieznana.
		self::assertSame(ProjectVisibility::Unknown, $this->resolver->resolve(null, self::gsc(true, 0), null, 720)->visibility);
		// Punkt odniesienia niewiarygodny dla tego wolumenu → Nieznana.
		self::assertSame(ProjectVisibility::Unknown, $this->resolver->resolve(null, self::gsc(true, 3), ['rank' => null, 'absence_reliable' => false], 720)->visibility);
		// Labs wiarygodnie bez frazy, ale projekt bez danych GSC → Nieznana (brak drugiego dowodu).
		self::assertSame(ProjectVisibility::Unknown, $this->resolver->resolve(null, self::gsc(false), ['rank' => null, 'absence_reliable' => true], 720)->visibility);
		// Labs wiarygodnie bez frazy i GSC < 10 wyświetleń → Brak.
		$none = $this->resolver->resolve(null, self::gsc(true, 4, 50.0), ['rank' => null, 'absence_reliable' => true], 720);
		self::assertSame(ProjectVisibility::None, $none->visibility);
		self::assertSame(ProjectEvidence::SOURCE_LABS, $none->source);
		self::assertSame(GapType::Unknown, $this->classifier->classify(ProjectEvidence::unknown(), 4));
	}

	public function test_labs_baseline_position_when_gsc_has_too_few_impressions(): void
	{
		$evidence = $this->resolver->resolve(null, self::gsc(true, 5, 30.0), ['rank' => 27, 'absence_reliable' => true], 720);

		self::assertSame(ProjectEvidence::SOURCE_LABS, $evidence->source);
		self::assertSame(27.0, $evidence->position);
		self::assertSame(GapType::Weak, $this->classifier->classify($evidence, 4));
	}

	public function test_type_boundaries(): void
	{
		$at = static fn (float $position): ProjectEvidence => new ProjectEvidence($position <= 10 ? ProjectVisibility::Visible : ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, $position);

		self::assertSame(GapType::Stronger, $this->classifier->classify($at(3.4), 4), 'P < C.');
		self::assertSame(GapType::Competitive, $this->classifier->classify($at(4.0), 4), 'Remis = porównywalna.');
		self::assertSame(GapType::Competitive, $this->classifier->classify($at(8.0), 1), 'TOP 10 = porównywalna.');
		self::assertSame(GapType::Competitive, $this->classifier->classify($at(15.0), 11), 'Różnica < 5.');
		self::assertSame(GapType::Weak, $this->classifier->classify($at(16.0), 11), 'Poza TOP 10 i różnica ≥ 5.');
	}
}
