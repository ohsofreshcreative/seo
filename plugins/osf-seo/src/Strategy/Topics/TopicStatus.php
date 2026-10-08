<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

/**
 * Status pracy nad tematem (jak w Szansach SEO, D61) — zmienia go wyłącznie użytkownik; przeliczenie nigdy go nie zmienia.
 */
enum TopicStatus: string
{
	case New = 'new';
	case Review = 'review';
	case Planned = 'planned';
	case InProgress = 'in_progress';
	case Completed = 'completed';
	case Dismissed = 'dismissed';

	/** Statusy „otwarte” (domyślna lista backlogu). */
	public const OPEN = ['new', 'review', 'planned', 'in_progress'];

	public function label(): string
	{
		return match ($this) {
			self::New => 'Nowy',
			self::Review => 'Do przeglądu',
			self::Planned => 'Zaplanowany',
			self::InProgress => 'W realizacji',
			self::Completed => 'Zrealizowany',
			self::Dismissed => 'Odrzucony',
		};
	}

	/** Moment zapisu punktu odniesienia do późniejszej oceny efektu (pierwsze przejście do realizacji albo zrealizowania). */
	public function capturesBaseline(): bool
	{
		return $this === self::InProgress || $this === self::Completed;
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $status): string => $status->value, self::cases());
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
	}
}
