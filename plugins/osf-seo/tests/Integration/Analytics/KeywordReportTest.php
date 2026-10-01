<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Analytics;

use OsfSeo\Analytics\KeywordFilters;
use OsfSeo\Analytics\KeywordRow;
use OsfSeo\Analytics\Period;

final class KeywordReportTest extends AnalyticsTestCase
{
	/**
	 * @param array<string, mixed> $input
	 * @return list<KeywordRow>
	 */
	private function rows(array $input = []): array
	{
		return $this->page($input)->rows;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function page(array $input = []): \OsfSeo\Analytics\KeywordPage
	{
		$context = $this->context ??= $this->seeded();

		return $this->keywords->keywords($context, KeywordFilters::fromInput(['days' => 7] + $input));
	}

	private ?\OsfSeo\Auth\ProjectContext $context = null;

	private function seeded(): \OsfSeo\Auth\ProjectContext
	{
		$context = $this->readyProject();
		$this->seedFixture($context);

		return $context;
	}

	/**
	 * @param list<KeywordRow> $rows
	 * @return list<string>
	 */
	private static function names(array $rows): array
	{
		return array_map(static fn (KeywordRow $row): string => $row->keyword, $rows);
	}

	private function row(string $keyword): KeywordRow
	{
		foreach ($this->rows(['per_page' => 100]) as $row) {
			if ($row->keyword === $keyword) {
				return $row;
			}
		}

		self::fail("Brak frazy {$keyword}.");
	}

	public function test_period_ends_at_latest_imported_date_and_compares_previous_equal_period(): void
	{
		$page = $this->page();

		self::assertSame('2026-01-14', $page->period->latestDate);
		self::assertSame('2026-01-08..2026-01-14', (string) $page->period->current);
		self::assertSame('2026-01-01..2026-01-07', (string) $page->period->previous);
		self::assertSame('2025-12-18..2026-01-14', (string) (new Period('2026-01-14', 28))->current);
	}

	public function test_default_list_contains_current_keywords_sorted_by_clicks(): void
	{
		$page = $this->page();

		self::assertSame(10, $page->total, 'Fraza utracona (tylko poprzedni okres) nie jest na liście.');
		self::assertSame(['alfa', 'gamma', 'zeta', 'beta'], array_slice(self::names($page->rows), 0, 4), 'Kliknięcia malejąco, remis: więcej wyświetleń.');
	}

	public function test_metrics_use_aggregate_ctr_and_impression_weighted_position(): void
	{
		$zeta = $this->row('zeta');

		self::assertSame(1, $zeta->clicks);
		self::assertSame(100, $zeta->impressions);
		self::assertSame(0.01, $zeta->ctr(), 'CTR = 1 / 100, nie średnia CTR dni (50%).');
		self::assertEqualsWithDelta(49.51, $zeta->position(), 1e-9, 'Pozycja ważona wyświetleniami, nie AVG (25,5).');
		self::assertEqualsWithDelta(10.49, $zeta->positionChange(), 1e-9);
		self::assertEqualsWithDelta(29.3, $this->row('lambda')->position(), 1e-9, '(1×2 + 39×30) / 40, nie (2 + 30) / 2.');
	}

	public function test_comparison_semantics(): void
	{
		$alfa = $this->row('alfa');
		self::assertSame(8.0, $alfa->positionChange(), '15 → 7 = +8 (wzrost).');
		self::assertSame(10, $alfa->clicksChange());
		self::assertSame(0, $alfa->impressionsChange());
		self::assertEqualsWithDelta(0.1, $alfa->ctrChange(), 1e-12);

		$beta = $this->row('beta');
		self::assertSame(-5.0, $beta->positionChange(), '4 → 9 = −5 (spadek).');
		self::assertSame(-4, $beta->clicksChange());

		$gamma = $this->row('gamma');
		self::assertTrue($gamma->isNew());
		self::assertNull($gamma->positionChange());
		self::assertNull($gamma->ctrChange());
	}

	public function test_primary_page_is_derived_from_current_period(): void
	{
		self::assertSame('https://example.pl/buty/', $this->row('alfa')->primaryPage, 'Najwięcej kliknięć w bieżącym okresie (poprzedni ignorowany).');
		self::assertSame('https://example.pl/obuwie/', $this->row('beta')->primaryPage);
		self::assertNull($this->row('gamma')->primaryPage);
	}

	public function test_search_filter_is_a_safe_substring_match(): void
	{
		self::assertEqualsCanonicalizing(['beta', 'zeta', 'eta', 'theta'], self::names($this->rows(['q' => 'eta'])));
		self::assertSame([], $this->rows(['q' => '%']), 'Znak % jest dosłowny (escape LIKE).');
		self::assertSame([], $this->rows(['q' => "' OR 1=1 -- "]));
	}

	public function test_position_range_and_minimum_impressions(): void
	{
		self::assertEqualsCanonicalizing(['eta', 'alfa', 'beta', 'theta'], self::names($this->rows(['pos_min' => '3', 'pos_max' => '10'])));
		self::assertEqualsCanonicalizing(['alfa', 'beta', 'zeta'], self::names($this->rows(['min_impr' => '50'])));
	}

	public function test_gains_and_losses_require_minimum_impressions_in_both_periods(): void
	{
		self::assertSame(['zeta', 'alfa'], self::names($this->rows(['movement' => 'gains', 'sort' => 'position_change'])), 'Epsilon (+30, 2–3 wyświetlenia) to szum — pominięty.');
		self::assertSame(['beta'], self::names($this->rows(['movement' => 'losses', 'sort' => 'position_change', 'dir' => 'asc'])));
	}

	public function test_sorting_is_whitelisted_and_nulls_go_last(): void
	{
		self::assertSame(['gamma', 'eta'], array_slice(self::names($this->rows(['sort' => 'position', 'dir' => 'asc'])), 0, 2));
		self::assertSame(['epsilon', 'zeta', 'alfa', 'beta'], array_slice(self::names($this->rows(['sort' => 'position_change'])), 0, 4));
		self::assertSame('kappa', self::names($this->rows(['sort' => 'position', 'dir' => 'desc']))[0]);
		self::assertSame(['alfa', 'gamma', 'zeta'], array_slice(self::names($this->rows(['sort' => 'position; DROP TABLE x', 'dir' => 'sideways'])), 0, 3), 'Nieznane sortowanie → domyślne.');
		self::assertSame(['zeta', 'beta', 'gamma', 'alfa'], array_slice(self::names($this->rows(['sort' => 'ctr', 'dir' => 'asc'])), -4), 'CTR z sum: 1%, 2%, 10%, 20%.');
		self::assertSame(['alfa', 'beta', 'epsilon'], array_slice(self::names($this->rows(['sort' => 'keyword', 'dir' => 'asc'])), 0, 3));
	}

	public function test_server_side_pagination(): void
	{
		$first = $this->page(['per_page' => 25]);
		self::assertCount(10, $first->rows);
		self::assertSame(1, $first->pages());

		$beyond = $this->page(['per_page' => 25, 'page' => 3]);
		self::assertSame([], $beyond->rows);
		self::assertSame(10, $beyond->total);

		self::assertSame(50, KeywordFilters::fromInput(['per_page' => 100000])->perPage, 'Rozmiar strony z białej listy.');
	}

	public function test_project_without_data_and_project_isolation(): void
	{
		$this->page();
		$other = $this->readyProject('other.pl');

		$empty = $this->keywords->keywords($other, KeywordFilters::fromInput([]));
		self::assertFalse($empty->hasData());
		self::assertSame([], $empty->rows);

		$this->seedFixture($other);
		self::assertSame(10, $this->keywords->keywords($other, KeywordFilters::fromInput(['days' => 7]))->total, 'Tylko frazy projektu.');
		self::assertSame(10, $this->page()->total);
	}
}
