<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Indeks stron projektu (punkt rozszerzenia, D57): dziś strony znane z GSC (`GscPageIndex` — niepełny inwentarz), w przyszłości
 * crawler, mapa witryny albo REST WordPressa. Tylko pełny indeks (`complete()`) albo ręczne potwierdzenie pozwala na wysoką
 * pewność „Kandydata na nową stronę”; indeks niepełny służy wyłącznie słabemu dopasowaniu adresu.
 */
interface ProjectPageIndex
{
	/** Kod źródła indeksu (np. `gsc`). */
	public function source(): string;

	/** Czy indeks obejmuje cały inwentarz stron projektu (GSC — nie: strony bez wyświetleń są niewidoczne). */
	public function complete(): bool;

	/**
	 * Znane strony projektu (adresy znormalizowane jak w słowniku `serp_urls`).
	 *
	 * @return list<string>
	 */
	public function pages(int $projectId): array;
}
