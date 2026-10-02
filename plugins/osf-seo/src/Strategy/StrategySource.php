<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Źródło kandydata Strategii (bit w `strategy_keywords.sources`). Lista bitów jest tylko dopisywana — wartości są zapisane w bazie.
 */
enum StrategySource: string
{
	case Manual = 'manual';
	case Serp = 'serp';
	case Opportunity = 'opportunity';
	case Discovery = 'discovery';
	case Gap = 'gap';
	case ContentGap = 'content_gap';
	case Gsc = 'gsc';

	public function bit(): int
	{
		return match ($this) {
			self::Manual => 1,
			self::Serp => 2,
			self::Opportunity => 4,
			self::Discovery => 8,
			self::Gap => 16,
			self::ContentGap => 32,
			self::Gsc => 64,
		};
	}

	public function label(): string
	{
		return match ($this) {
			self::Manual => 'Ręcznie',
			self::Serp => 'Pozycje',
			self::Opportunity => 'Szanse SEO',
			self::Discovery => 'Nowe frazy',
			self::Gap => 'Luki fraz',
			self::ContentGap => 'Luki treści',
			self::Gsc => 'GSC',
		};
	}

	/**
	 * @return list<self>
	 */
	public static function fromBits(int $bits): array
	{
		return array_values(array_filter(self::cases(), static fn (self $source): bool => ($bits & $source->bit()) !== 0));
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $source): string => $source->value, self::cases());
	}
}
