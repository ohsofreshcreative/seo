<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Status pracy nad szansą — ustawiany wyłącznie ręcznie (analiza go nie zmienia).
 */
enum OpportunityStatus: string
{
	case New = 'new';
	case Review = 'review';
	case Planned = 'planned';
	case InProgress = 'in_progress';
	case Completed = 'completed';
	case Dismissed = 'dismissed';

	public function label(): string
	{
		return match ($this) {
			self::New => 'Nowa',
			self::Review => 'Do analizy',
			self::Planned => 'Zaplanowana',
			self::InProgress => 'W trakcie',
			self::Completed => 'Zrealizowana',
			self::Dismissed => 'Odrzucona',
		};
	}

	/** Szansa wymaga jeszcze decyzji albo pracy. */
	public function isOpen(): bool
	{
		return ! in_array($this, [self::Completed, self::Dismissed], true);
	}

	/**
	 * @return list<string>
	 */
	public static function openValues(): array
	{
		return array_values(array_map(
			static fn (self $status): string => $status->value,
			array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
		));
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $status): string => $status->value, self::cases());
	}
}
