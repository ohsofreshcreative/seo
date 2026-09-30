<?php

declare(strict_types=1);

namespace OsfSeo\Database;

/**
 * Migracja schematu. Zasady:
 *
 * - migracja po wdrożeniu jest niezmienna — zmiana schematu = nowa migracja z wyższym numerem,
 * - `up()` musi być idempotentne (CREATE TABLE IF NOT EXISTS, sprawdzanie kolumn/indeksów przed ALTER),
 *   bo migracja może zostać powtórzona, np. po utracie opcji z wersją schematu,
 * - migracje nigdy nie usuwają danych bez jawnej decyzji (osobna, opisana migracja).
 */
interface Migration
{
	public function version(): int;

	public function name(): string;

	public function up(Connection $db): void;
}
