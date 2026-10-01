<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Stan pracy nad nową frazą — ustawiany wyłącznie ręcznie (kolejne wyszukiwania go nie zmieniają).
 * „Zaakceptowana” = warto uwzględnić frazę w strategii SEO (nie oznacza wdrożenia).
 */
enum CandidateStatus: string
{
	case New = 'new';
	case Review = 'review';
	case Accepted = 'accepted';
	case Dismissed = 'dismissed';

	public function label(): string
	{
		return match ($this) {
			self::New => 'Nowa',
			self::Review => 'Do analizy',
			self::Accepted => 'Zaakceptowana',
			self::Dismissed => 'Odrzucona',
		};
	}

	/** Fraza czeka jeszcze na decyzję. */
	public function isOpen(): bool
	{
		return $this === self::New || $this === self::Review;
	}

	/**
	 * @return list<string>
	 */
	public static function openValues(): array
	{
		return [self::New->value, self::Review->value];
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $status): string => $status->value, self::cases());
	}
}
