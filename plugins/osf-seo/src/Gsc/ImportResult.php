<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

final class ImportResult
{
	public function __construct(
		public readonly Dataset $dataset,
		/** Wiersze zwrócone przez Google (po walidacji). */
		public readonly int $rowsFetched,
		/** Wiersze zapisane w tabeli faktów (po podmianie zakresu). */
		public readonly int $rowsWritten,
		/** Usunięte poprzednie wiersze zakresu. */
		public readonly int $rowsReplaced,
		public readonly int $apiRequests,
		public readonly int $pages,
		/** Najstarsza i najnowsza data obecna w danych (null przy braku wierszy). */
		public readonly ?string $minDate,
		public readonly ?string $maxDate,
		public readonly int $keywordsCreated,
		public readonly int $pagesCreated,
	) {
	}

	/**
	 * @return array<string, int|string|null>
	 */
	public function toArray(): array
	{
		return [
			'dataset' => $this->dataset->value,
			'rows_fetched' => $this->rowsFetched,
			'rows_written' => $this->rowsWritten,
			'rows_replaced' => $this->rowsReplaced,
			'api_requests' => $this->apiRequests,
			'pages' => $this->pages,
			'min_date' => $this->minDate,
			'max_date' => $this->maxDate,
			'keywords_created' => $this->keywordsCreated,
			'pages_created' => $this->pagesCreated,
		];
	}
}
