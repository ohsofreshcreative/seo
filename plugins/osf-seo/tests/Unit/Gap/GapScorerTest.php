<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gap;

use OsfSeo\Gap\GapScorer;
use OsfSeo\Gap\GapType;
use OsfSeo\Gap\ProjectEvidence;
use OsfSeo\Gap\ProjectVisibility;
use PHPUnit\Framework\TestCase;

final class GapScorerTest extends TestCase
{
	private static function missing(): ProjectEvidence
	{
		return new ProjectEvidence(ProjectVisibility::None, ProjectEvidence::SOURCE_LABS, null);
	}

	public function test_plan_examples(): void
	{
		$scorer = new GapScorer();

		$one = $scorer->score(GapType::Missing, self::missing(), 4, 1, 720, 31, 4.5, 'commercial');
		self::assertSame(77, $one->priority);
		self::assertSame(['demand' => 17.9, 'attainability' => 10.4, 'evidence' => 10.2, 'gap' => 30.0, 'intent' => 5.0, 'commercial' => 3.6], $one->components);

		$weak = new ProjectEvidence(ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, 38.5);
		self::assertSame(82, $scorer->score(GapType::Weak, $weak, 4, 3, 1300, 45, 6.2, 'commercial')->priority);

		self::assertSame(59, $scorer->score(GapType::Unknown, ProjectEvidence::unknown(), 9, 1, 9900, 78, 3.1, 'informational')->priority, 'Ogromny wolumen nie dominuje.');
		self::assertSame(70, $scorer->score(GapType::Missing, self::missing(), 2, 1, 20, 5, null, 'transactional')->priority, 'Długi ogon z niską KD.');

		$competitive = $scorer->score(GapType::Competitive, new ProjectEvidence(ProjectVisibility::Visible, ProjectEvidence::SOURCE_SERP, 6.0), 3, 1, 720, 31, 4.5, 'commercial');
		self::assertSame(0.6, $competitive->multiplier);
		self::assertSame(32, $competitive->priority, 'Fraza bez luki nie wypiera luk.');
	}

	public function test_missing_metrics_degrade_gracefully(): void
	{
		$score = (new GapScorer())->score(GapType::Missing, self::missing(), 15, 1, null, null, null, null);

		self::assertSame(['demand' => 0.0, 'attainability' => 7.5, 'evidence' => 7.2, 'gap' => 30.0, 'intent' => 2.5, 'commercial' => 0.0], $score->components);
		self::assertSame(47, $score->priority);
	}

	public function test_weak_gap_scales_with_distance_and_stronger_is_lowest(): void
	{
		$scorer = new GapScorer();
		$near = $scorer->score(GapType::Weak, new ProjectEvidence(ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, 16.0), 10, 1, 500, 30, 1.0, 'commercial');
		$far = $scorer->score(GapType::Weak, new ProjectEvidence(ProjectVisibility::Low, ProjectEvidence::SOURCE_GSC, 60.0), 10, 1, 500, 30, 1.0, 'commercial');

		self::assertSame(12.0, $near->components['gap'], 'Minimum 0,4 × 30.');
		self::assertSame(30.0, $far->components['gap']);
		self::assertLessThan(20, $scorer->score(GapType::Stronger, new ProjectEvidence(ProjectVisibility::Visible, ProjectEvidence::SOURCE_GSC, 2.0), 5, 1, 720, 31, 4.5, 'commercial')->priority);
	}

	public function test_cluster_priority(): void
	{
		self::assertSame(81, GapScorer::clusterPriority(82, 4800));
		self::assertSame(74, GapScorer::clusterPriority(80, 720));
		self::assertSame(0, GapScorer::clusterPriority(0, 0));
	}
}
