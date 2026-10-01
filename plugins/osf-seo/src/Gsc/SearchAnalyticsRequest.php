<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use InvalidArgumentException;
use OsfSeo\Support\DateRange;

/**
 * Zapytanie searchAnalytics.query. Daty to daty GSC (czas pacyficzny) w formacie Y-m-d — bez przeliczania stref.
 */
final class SearchAnalyticsRequest
{
	/** Maksymalny rowLimit API. */
	public const MAX_ROW_LIMIT = 25000;

	/** GSC udostępnia przez API maksymalnie ok. 50 000 wierszy dziennie na typ wyszukiwania (limit Google). */
	public const MAX_ROWS_PER_DAY = 50000;

	public const DIMENSIONS = ['date', 'query', 'page', 'country', 'device', 'searchAppearance'];

	public const DATA_STATES = ['final', 'all'];

	public const TYPES = ['web', 'image', 'video', 'news', 'discover', 'googleNews'];

	/** Twardy limit stron jednego zapytania (niezależnie od zakresu dat). */
	private const MAX_PAGES_CAP = 400;

	public readonly DateRange $range;

	/** @var list<string> */
	public readonly array $dimensions;

	/**
	 * @param list<string> $dimensions
	 */
	public function __construct(
		DateRange $range,
		array $dimensions,
		public readonly int $rowLimit = self::MAX_ROW_LIMIT,
		public readonly int $startRow = 0,
		public readonly string $dataState = 'final',
		public readonly string $type = 'web',
	) {
		if (! array_is_list($dimensions) || count(array_unique($dimensions)) !== count($dimensions) || array_diff($dimensions, self::DIMENSIONS) !== []) {
			throw new InvalidArgumentException('Invalid Search Analytics dimensions.');
		}

		if ($rowLimit < 1 || $rowLimit > self::MAX_ROW_LIMIT) {
			throw new InvalidArgumentException('rowLimit must be between 1 and 25000.');
		}

		if ($startRow < 0) {
			throw new InvalidArgumentException('startRow must not be negative.');
		}

		if (! in_array($dataState, self::DATA_STATES, true) || ! in_array($type, self::TYPES, true)) {
			throw new InvalidArgumentException('Invalid dataState or type.');
		}

		$this->range = $range;
		$this->dimensions = $dimensions;
	}

	public function withStartRow(int $startRow): self
	{
		return new self($this->range, $this->dimensions, $this->rowLimit, $startRow, $this->dataState, $this->type);
	}

	/**
	 * Górna granica liczby stron dla zakresu (ochrona przed nieskończoną paginacją):
	 * dni × limit wierszy dziennie Google / rowLimit + 1 (strona pusta kończąca).
	 */
	public function maxPages(): int
	{
		return min(self::MAX_PAGES_CAP, (int) ceil($this->range->days() * self::MAX_ROWS_PER_DAY / $this->rowLimit) + 1);
	}

	public function dimensionIndex(string $dimension): ?int
	{
		$index = array_search($dimension, $this->dimensions, true);

		return $index === false ? null : $index;
	}

	/**
	 * @return array<string, mixed> treść żądania API
	 */
	public function toApi(): array
	{
		return [
			'startDate' => $this->range->start,
			'endDate' => $this->range->end,
			'dimensions' => $this->dimensions,
			'type' => $this->type,
			'dataState' => $this->dataState,
			'aggregationType' => 'auto',
			'rowLimit' => $this->rowLimit,
			'startRow' => $this->startRow,
		];
	}
}
