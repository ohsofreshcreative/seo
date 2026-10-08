<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

/**
 * Działanie tematu (D58) — reguły w tej kolejności; „create” to wyłącznie „Kandydat na nową stronę” (D57), „investigate” jest
 * pełnoprawnym wynikiem z kodem powodu.
 */
enum StrategyAction: string
{
	case Consolidate = 'consolidate';
	case Recover = 'recover';
	case Optimize = 'optimize';
	case Create = 'create';
	case Monitor = 'monitor';
	case Investigate = 'investigate';

	public function label(): string
	{
		return match ($this) {
			self::Consolidate => 'Konsolidacja stron',
			self::Recover => 'Odzyskanie widoczności',
			self::Optimize => 'Optymalizacja strony',
			self::Create => 'Kandydat na nową stronę',
			self::Monitor => 'Monitorowanie',
			self::Investigate => 'Do sprawdzenia',
		};
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $action): string => $action->value, self::cases());
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
	}
}
