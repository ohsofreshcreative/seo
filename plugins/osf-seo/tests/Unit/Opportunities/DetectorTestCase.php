<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Opportunities;

use OsfSeo\Analytics\KeywordRow;
use OsfSeo\Analytics\Period;
use OsfSeo\Opportunities\Candidate;
use OsfSeo\Opportunities\DetectionInput;
use OsfSeo\Opportunities\OpportunityConfig;
use OsfSeo\Opportunities\OpportunityDetector;
use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Opportunities\PagePair;
use OsfSeo\Opportunities\Stats;
use OsfSeo\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * Pomocnicze konstruktory danych: metryki jako [kliknięcia, wyświetlenia, średnia pozycja].
 */
abstract class DetectorTestCase extends TestCase
{
	protected const PROPERTY = 'sc-domain:example.pl';

	protected const LATEST = '2026-03-28';

	/**
	 * @param array{0: int, 1: int, 2: float} $current
	 * @param array{0: int, 1: int, 2: float} $previous
	 */
	protected static function keyword(int $id, string $text, array $current, array $previous = [0, 0, 0.0]): KeywordRow
	{
		return new KeywordRow($id, $text, $current[0], $current[1], $current[2] * $current[1], $previous[0], $previous[1], $previous[2] * $previous[1]);
	}

	/**
	 * @param array{0: int, 1: int, 2: float} $current
	 * @param array{0: int, 1: int, 2: float} $previous
	 */
	protected static function pair(int $keywordId, string $url, array $current, array $previous = [0, 0, 0.0]): PagePair
	{
		return new PagePair($keywordId, $url, self::stats($current), self::stats($previous));
	}

	/**
	 * @param array{0: int, 1: int, 2: float} $metrics
	 */
	protected static function stats(array $metrics): Stats
	{
		return new Stats($metrics[0], $metrics[1], $metrics[2] * $metrics[1]);
	}

	/**
	 * @param list<KeywordRow> $keywords
	 * @param list<PagePair> $pairs
	 * @param array<string, array{0: Stats, 1: Stats}> $pageTotals
	 */
	protected static function input(array $keywords, array $pairs = [], array $pageTotals = [], bool $previousCovered = true, int $days = 28, string $property = self::PROPERTY): DetectionInput
	{
		return new DetectionInput($property, new Period(self::LATEST, $days), $previousCovered, $keywords, $pairs, $pageTotals);
	}

	/**
	 * @param array<string, int|float> $overrides nazwa progu => wartość
	 */
	protected static function detector(array $overrides = []): OpportunityDetector
	{
		return OpportunityDetector::create(new OpportunityConfig(new Config(), $overrides));
	}

	/**
	 * @param list<Candidate> $candidates
	 * @return list<Candidate>
	 */
	protected static function ofType(array $candidates, OpportunityType $type): array
	{
		return array_values(array_filter($candidates, static fn (Candidate $candidate): bool => $candidate->type === $type));
	}

	/**
	 * @param list<Candidate> $candidates
	 * @return list<string>
	 */
	protected static function keywordsOf(Candidate $candidate): array
	{
		return array_map(static fn (array $item): string => (string) $item['keyword'], $candidate->evidence['keywords']);
	}
}
