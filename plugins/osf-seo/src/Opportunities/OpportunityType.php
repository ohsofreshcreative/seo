<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Kategorie szans SEO (STEP 11). Wszystkie oparte wyłącznie na danych Google Search Console —
 * to sygnały do sprawdzenia, nie gwarancja wzrostu.
 */
enum OpportunityType: string
{
	case LowCtr = 'low_ctr';
	case NearTop = 'near_top';
	case WeakPosition = 'weak_position';
	case Decline = 'decline';
	case Cannibalization = 'cannibalization';

	public function label(): string
	{
		return match ($this) {
			self::LowCtr => 'Niski CTR przy dużej widoczności',
			self::NearTop => 'Blisko TOP 3 / TOP 10',
			self::WeakPosition => 'Duża widoczność, słaba pozycja',
			self::Decline => 'Istotny spadek',
			self::Cannibalization => 'Możliwa kanibalizacja',
		};
	}

	public function shortLabel(): string
	{
		return match ($this) {
			self::LowCtr => 'Niski CTR',
			self::NearTop => 'Blisko TOP',
			self::WeakPosition => 'Słaba pozycja',
			self::Decline => 'Spadek',
			self::Cannibalization => 'Możliwa kanibalizacja',
		};
	}

	/** Krótko: co wykrywa ta kategoria (opis w UI). */
	public function description(): string
	{
		return match ($this) {
			self::LowCtr => 'Frazy z wieloma wyświetleniami i CTR wyraźnie niższym niż typowy dla ich średniej pozycji (GSC).',
			self::NearTop => 'Frazy ze średnią pozycją (GSC) tuż za TOP 3 (pozycja 3–10) lub TOP 10 (pozycja 10–20) i wystarczającą liczbą wyświetleń.',
			self::WeakPosition => 'Frazy z dużą liczbą wyświetleń, ale odległą średnią pozycją (GSC) 20–100 — Google już łączy z nimi stronę.',
			self::Decline => 'Frazy lub podstrony, które straciły kliknięcia, wyświetlenia albo średnią pozycję względem poprzedniego okresu.',
			self::Cannibalization => 'Frazy, dla których kilka adresów URL dzieli istotną część wyświetleń — wymaga sprawdzenia, nie zawsze jest problemem.',
		};
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array
	{
		return array_map(static fn (self $type): string => $type->value, self::cases());
	}
}
