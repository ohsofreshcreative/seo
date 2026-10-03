<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

/**
 * Kody powodów działań (stabilne identyfikatory w danych i CLI; etykiety po polsku dla panelu).
 */
final class ReasonCode
{
	// Konsolidacja — silny konflikt URL.
	public const CANNIBALIZATION = 'cannibalization';

	public const GSC_SPLIT = 'gsc_split';

	public const OVERLAP_TARGETS = 'overlap_targets';

	public const TARGET_MISMATCH = 'target_mismatch';

	// Odzyskanie.
	public const SERP_DECLINE = 'serp_decline';

	public const GSC_DECLINE = 'gsc_decline';

	public const SERP_GSC_DECLINE = 'serp_gsc_decline';

	// Optymalizacja.
	public const SERP_POSITION = 'serp_position';

	public const LOW_CTR = 'low_ctr';

	public const NEAR_TOP = 'near_top';

	public const WEAK_POSITION = 'weak_position';

	public const GSC_POSITION = 'gsc_position';

	public const GAP_WEAK = 'gap_weak';

	public const CONTENT_IMPROVE = 'content_improve';

	// Kandydat na nową stronę.
	public const NEW_PAGE_CANDIDATE = 'new_page_candidate';

	// Monitorowanie.
	public const TOP3_SERP = 'top3_serp';

	public const TOP3_GSC = 'top3_gsc';

	// Do sprawdzenia.
	public const SERP_REQUIRED = 'serp_required';

	public const TARGET_UNKNOWN = 'target_unknown';

	public const TARGET_WEAK = 'target_weak';

	public const POSSIBLE_EXISTING_PAGE = 'possible_existing_page';

	public const INTENT_MISMATCH = 'intent_mismatch';

	public const LABS_ONLY = 'labs_only';

	public const URL_FLIP = 'url_flip';

	public const DATA_INCOMPLETE = 'data_incomplete';

	public const CONFLICTING_EVIDENCE = 'conflicting_evidence';

	public const LOW_DEMAND = 'low_demand';

	public const LOW_VISIBILITY = 'low_visibility';

	public static function label(string $code): string
	{
		return match ($code) {
			self::CANNIBALIZATION => 'szansa SEO „Możliwa kanibalizacja” z wystarczającą pewnością',
			self::GSC_SPLIT => 'wyświetlenia frazy rozłożone na kilka stron projektu (GSC)',
			self::OVERLAP_TARGETS => 'silnie podobne wyniki wyszukiwania, a różne strony docelowe projektu',
			self::TARGET_MISMATCH => 'w wynikach rankuje inna strona niż potwierdzona strona docelowa',
			self::SERP_DECLINE => 'spadek Pozycji SERP względem poprzedniego porównywalnego pomiaru',
			self::GSC_DECLINE => 'spadek wykryty w Szansach SEO (GSC)',
			self::SERP_GSC_DECLINE => 'spadek w pomiarze SERP i w GSC',
			self::SERP_POSITION => 'strona projektu na pozycji 4–50 w świeżym pomiarze SERP',
			self::LOW_CTR => 'duża widoczność, niski CTR (Szanse SEO)',
			self::NEAR_TOP => 'blisko TOP 3 / TOP 10 (Szanse SEO)',
			self::WEAK_POSITION => 'duża widoczność przy słabej średniej pozycji (Szanse SEO)',
			self::GSC_POSITION => 'średnia pozycja (GSC) 4–50 bez świeżego pomiaru SERP',
			self::GAP_WEAK => 'Luka fraz: słaba widoczność względem konkurentów',
			self::CONTENT_IMPROVE => 'Luka treści: istniejąca strona do wzmocnienia',
			self::NEW_PAGE_CANDIDATE => 'brak znanej strony, realny popyt i dodatkowe dowody z SERP, luki treści albo konkurentów',
			self::TOP3_SERP => 'projekt w TOP 3 świeżego pomiaru, bez spadku i konfliktu',
			self::TOP3_GSC => 'średnia pozycja (GSC) w TOP 3, bez spadku i konfliktu (bez świeżego pomiaru SERP)',
			self::SERP_REQUIRED => 'potrzebny świeży pomiar SERP',
			self::TARGET_UNKNOWN => 'nie wiadomo, która strona projektu odpowiada frazom',
			self::TARGET_WEAK => 'strona docelowa wskazana tylko słabym sygnałem',
			self::POSSIBLE_EXISTING_PAGE => 'strona projektu może już istnieć — potrzebna wiedza o stronach witryny',
			self::INTENT_MISMATCH => 'intencja według dostawcy nie zgadza się z sygnałem z wyników wyszukiwania',
			self::LABS_ONLY => 'tylko dane DataForSEO Labs (bez GSC i pomiaru SERP)',
			self::URL_FLIP => 'zmiana strony rankującej między pomiarami',
			self::DATA_INCOMPLETE => 'niepełne dane (GSC poza oknem albo brak danych projektu)',
			self::CONFLICTING_EVIDENCE => 'sprzeczne dowody (konkurujące strony projektu)',
			self::LOW_DEMAND => 'za mały popyt na nową stronę',
			self::LOW_VISIBILITY => 'strona istnieje, ale widoczność jest odległa (poza TOP 50)',
			default => $code,
		};
	}
}
