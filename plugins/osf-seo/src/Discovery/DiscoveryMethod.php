<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Metoda wyszukiwania nowych fraz (wybrane endpointy dostawcy — docs/ARCHITECTURE.md, sekcja 12.2).
 *
 * - `related` — frazy z „Podobne wyszukiwania” (graf powiązań SERP) w głąb od seeda; miara powiązania = głębokość,
 * - `suggestions` — frazy zawierające frazę seeda (wyszukiwanie pełnotekstowe w bazie dostawcy, długi ogon).
 *
 * Każde żądanie dotyczy jednego seeda, więc każdą znalezioną frazę da się przypisać do seeda (wyjaśnienie w UI).
 */
enum DiscoveryMethod: string
{
	case Related = 'related';
	case Suggestions = 'suggestions';

	public function label(): string
	{
		return match ($this) {
			self::Related => 'Powiązane frazy',
			self::Suggestions => 'Frazy zawierające seed',
		};
	}

	public function description(): string
	{
		return match ($this) {
			self::Related => 'Wyszukiwania powiązane z seedem w Google („Podobne wyszukiwania”), w głąb do wybranego poziomu.',
			self::Suggestions => 'Frazy z bazy dostawcy, które zawierają słowa seeda (długi ogon).',
		};
	}

	public function usesDepth(): bool
	{
		return $this === self::Related;
	}

	public static function fromInput(mixed $value): ?self
	{
		return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
	}
}
