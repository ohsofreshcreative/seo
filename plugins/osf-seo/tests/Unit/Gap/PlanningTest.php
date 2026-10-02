<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Gap;

use InvalidArgumentException;
use OsfSeo\Gap\Coverage;
use OsfSeo\Gap\GapDomain;
use OsfSeo\Gap\GapRequest;
use OsfSeo\Gap\GapSettings;
use OsfSeo\Gap\ImportPages;
use PHPUnit\Framework\TestCase;

final class PlanningTest extends TestCase
{
	private static function dataset(array $overrides = []): GapDomain
	{
		$row = $overrides + [
			'id' => '1', 'provider' => 'dataforseo', 'location_code' => '2616', 'language_code' => 'pl', 'domain' => 'konkurent.pl',
			'status' => 'ready', 'coverage_max_rank' => '30', 'coverage_min_volume' => '10', 'coverage_max_rows' => '10000', 'complete' => '1',
			'covered_min_volume' => '10', 'total_count' => '4321', 'rows_present' => '4321', 'labs_updated_at' => '2026-09-20 00:00:00',
			'import_run_id' => '3', 'imported_at' => '2026-10-01 10:00:00', 'stale_after' => '2026-10-31 10:00:00',
		];

		return GapDomain::fromRow($row);
	}

	public function test_coverage_inclusion(): void
	{
		$standard = new Coverage(30, 10, 10000);

		self::assertTrue($standard->includes(new Coverage(20, 50, 2000)));
		self::assertFalse($standard->includes(new Coverage(50, 10, 10000)));
		self::assertFalse($standard->includes(new Coverage(30, 0, 10000)));
		self::assertTrue($standard->sameFilters(new Coverage(30, 10, 500)));

		$this->expectException(InvalidArgumentException::class);
		new Coverage(101, 10, 10000);
	}

	public function test_fresh_dataset_satisfies_narrower_or_equal_coverage(): void
	{
		$dataset = self::dataset();
		$now = '2026-10-02 10:00:00';

		self::assertTrue($dataset->satisfies(new Coverage(30, 10, 10000), $now));
		self::assertTrue($dataset->satisfies(new Coverage(10, 50, 2000), $now));
		self::assertFalse($dataset->satisfies(new Coverage(50, 10, 10000), $now), 'Szerszy zakres pozycji.');
		self::assertFalse($dataset->satisfies(new Coverage(30, 10, 10000), '2026-11-01 00:00:00'), 'Nieaktualny.');
		self::assertFalse(self::dataset(['status' => 'importing'])->satisfies(new Coverage(10, 50, 2000), $now));
		self::assertFalse(self::dataset(['complete' => '0', 'coverage_max_rows' => '2000'])->satisfies(new Coverage(30, 10, 10000), $now), 'Przycięty limitem 2000.');
	}

	public function test_absence_is_reliable_only_within_covered_volume_with_margin(): void
	{
		self::assertSame(15, GapDomain::reliableVolume(10));
		self::assertSame(0, GapDomain::reliableVolume(0), 'Bez filtra wolumenu nie ma granicy.');
		self::assertTrue(self::dataset()->absenceReliable(15));
		self::assertFalse(self::dataset()->absenceReliable(14), 'Tuż nad filtrem wolumen mógł spaść poniżej filtra — to nie utrata pozycji.');
		self::assertFalse(self::dataset()->absenceReliable(10), 'Fraza na samej granicy filtra.');
		self::assertFalse(self::dataset()->absenceReliable(9), 'Poniżej filtra wolumenu import nie mógł jej zwrócić.');
		self::assertFalse(self::dataset()->absenceReliable(null));
		$truncated = self::dataset(['complete' => '0', 'covered_min_volume' => '91']);
		self::assertTrue($truncated->absenceReliable(137));
		self::assertFalse($truncated->absenceReliable(136));
		self::assertFalse($truncated->absenceReliable(91), 'Przycięty limitem fraz: granica + zapas.');
		self::assertTrue(self::dataset(['coverage_min_volume' => '0', 'covered_min_volume' => '0'])->absenceReliable(0));
		self::assertFalse(self::dataset(['covered_min_volume' => null])->absenceReliable(5000), 'Wykryte dublowanie stron — brak wiarygodności.');
		self::assertFalse(self::dataset(['imported_at' => null])->absenceReliable(5000));
	}

	public function test_no_visibility_requires_reliable_absence_in_full_top100(): void
	{
		$top100 = self::dataset(['coverage_max_rank' => '100']);
		self::assertTrue($top100->provesNoVisibility(5000));
		self::assertNull($top100->absenceDoubt(5000));
		self::assertFalse(self::dataset()->provesNoVisibility(5000), 'Zbiór TOP30 (np. pobrany jako konkurent innego projektu) nie wyklucza pozycji 31–100.');
		self::assertStringContainsString('TOP30', (string) self::dataset()->absenceDoubt(5000));
		self::assertFalse(self::dataset(['coverage_max_rank' => '100', 'covered_min_volume' => null])->provesNoVisibility(5000), 'Import niespójny.');
		self::assertFalse($top100->provesNoVisibility(null), 'Nieznany wolumen.');
		self::assertFalse($top100->provesNoVisibility(12), 'Wolumen poniżej granicy z zapasem.');
		$truncated = self::dataset(['coverage_max_rank' => '100', 'complete' => '0', 'covered_min_volume' => '881']);
		self::assertFalse($truncated->provesNoVisibility(1000), 'Przycięty limitem 10 000 fraz: fraza poniżej granicy + zapasu mogła się nie zmieścić.');
		self::assertTrue($truncated->provesNoVisibility(1322));
		self::assertFalse(self::dataset(['coverage_max_rank' => '100', 'imported_at' => null, 'coverage_min_volume' => null])->provesNoVisibility(5000), 'Brak importu.');
	}

	public function test_request_parsing_presets_and_safe_row_limit(): void
	{
		$defaults = new GapSettings(1);
		$standard = GapRequest::fromInput([], $defaults, 10000);
		self::assertSame([30, 10, 10000], [$standard->coverage->maxRank, $standard->coverage->minVolume, $standard->coverage->maxRows]);
		self::assertTrue($standard->baseline);
		self::assertFalse($standard->force);

		$quick = GapRequest::fromInput(['preset' => 'quick', 'baseline' => '0'], $defaults, 10000);
		self::assertSame([10, 50, 2000], [$quick->coverage->maxRank, $quick->coverage->minVolume, $quick->coverage->maxRows]);
		self::assertFalse($quick->baseline);

		$custom = GapRequest::fromInput(['max_rank' => '100', 'min_volume' => '0', 'max_rows' => '50000', 'competitors' => '01J0000000000000000000CMP1, nope', 'force' => '1'], $defaults, 10000);
		self::assertSame(10000, $custom->coverage->maxRows, 'Nigdy powyżej bezpiecznej paginacji dostawcy.');
		self::assertSame(['01J0000000000000000000CMP1'], $custom->competitors);
		self::assertTrue($custom->force);
		self::assertSame(30, GapRequest::fromInput(['max_rank' => '37'], $defaults, 10000)->coverage->maxRank, 'Tylko wartości z listy.');
	}

	public function test_page_arithmetic(): void
	{
		self::assertSame(1, ImportPages::requests(0));
		self::assertSame(1, ImportPages::requests(1000));
		self::assertSame(2, ImportPages::requests(1001));
		self::assertSame(10, ImportPages::requests(10000));
	}
}
