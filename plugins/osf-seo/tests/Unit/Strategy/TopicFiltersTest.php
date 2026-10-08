<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Strategy;

use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\TargetState;
use OsfSeo\Strategy\Topics\TopicFilters;
use PHPUnit\Framework\TestCase;

/**
 * Filtry backlogu Strategii (panel, faza D): walidacja wejścia z adresu (nieznane wartości → domyślne), parametry adresu bez wartości
 * domyślnych, strony, domyślny widok (aktywne, otwarte, bez monitorowania).
 */
final class TopicFiltersTest extends TestCase
{
	public function test_defaults_are_active_open_topics_that_need_action(): void
	{
		$filters = TopicFilters::fromInput([]);

		self::assertSame(['open', 'active', false, 'priority', 'desc', 1], [$filters->status, $filters->state, $filters->includeMonitor, $filters->sort, $filters->direction, $filters->page]);
		self::assertTrue($filters->isDefault());
		self::assertSame([], $filters->toQuery());
	}

	public function test_valid_input_is_parsed_and_round_trips_through_the_query(): void
	{
		$input = [
			'q' => '  buty  ',
			'action' => 'CREATE',
			'status' => 'dismissed',
			'confidence' => 'high',
			'min_priority' => '60',
			'source' => 'gap',
			'intent' => 'commercial',
			'target' => 'none',
			'serp' => 'top10',
			'min_volume' => '100',
			'max_kd' => '40',
			'changed' => '1',
			'sort' => 'serp_rank',
			'page' => '3',
		];
		$filters = TopicFilters::fromInput($input);

		self::assertSame(StrategyAction::Create, $filters->action);
		self::assertSame(StrategySource::Gap, $filters->source);
		self::assertSame(TargetState::None, $filters->target);
		self::assertSame(['buty', 'dismissed', 'high', 60, 'commercial', 'top10', 100, 40, true], [$filters->q, $filters->status, $filters->confidence, $filters->minPriority, $filters->intent, $filters->serp, $filters->minVolume, $filters->maxDifficulty, $filters->changed]);
		self::assertSame(['serp_rank', 'asc', 3], [$filters->sort, $filters->direction, $filters->page], 'Pozycja SERP domyślnie rosnąco.');
		self::assertFalse($filters->isDefault());
		self::assertEquals($filters, TopicFilters::fromInput($filters->toQuery()));
		self::assertSame(1, $filters->withPage(1)->page);
		self::assertSame('buty', $filters->withPage(2)->q);
	}

	public function test_invalid_values_fall_back_to_defaults(): void
	{
		$filters = TopicFilters::fromInput([
			'action' => 'delete',
			'status' => '<script>',
			'confidence' => 'certain',
			'min_priority' => '101',
			'source' => 'semrush',
			'intent' => 'other',
			'target' => 'missing_page',
			'serp' => 'top1',
			'min_volume' => '-5',
			'max_kd' => 'abc',
			'sort' => 'id; DROP',
			'dir' => 'sideways',
			'page' => '0',
			'q' => ['array'],
		]);

		self::assertNull($filters->action);
		self::assertSame(['open', null, null, null, null, null, null, null, null, 'priority', 'desc', 1, ''], [
			$filters->status, $filters->confidence, $filters->minPriority, $filters->source, $filters->intent, $filters->target, $filters->serp,
			$filters->minVolume, $filters->maxDifficulty, $filters->sort, $filters->direction, $filters->page, $filters->q,
		]);
		self::assertTrue($filters->isDefault());
	}
}
