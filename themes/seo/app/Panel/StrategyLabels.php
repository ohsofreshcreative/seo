<?php

namespace App\Panel;

use OsfSeo\Strategy\Decision\ReasonCode;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\Serp\ResultShape;
use OsfSeo\Strategy\Serp\SerpIntentSignal;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Strategy\Target\EvidenceFamily;
use OsfSeo\Strategy\Topics\TopicEvent;
use OsfSeo\Strategy\Topics\TopicStatus;

/**
 * Etykiety panelu Strategii (wyłącznie prezentacja): kody czynników pewności, składników priorytetu, podstaw grupowania, konfliktów,
 * dowodów strony docelowej i reguł działań zapisane w analizie tematu (plugin) → polskie opisy. Nieznany kod — sam kod (nigdy pusty).
 */
final class StrategyLabels
{
	/** Składniki priorytetu Strategii (PriorityModel) — kolejność wyświetlania. */
	public const PRIORITY_COMPONENTS = [
		'demand' => ['Popyt', 'większy z wolumenu i miesięcznych wyświetleń GSC (skala logarytmiczna)'],
		'potential' => ['Potencjał', 'zależny od działania i pozycji'],
		'urgency' => ['Pilność', 'spadek pozycji, szansa „spadek”, konsolidacja'],
		'competition' => ['Konkurencja i SERP', 'konkurenci w TOP10, luka treści'],
		'attainability' => ['Osiągalność', 'trudność SEO frazy głównej (brak KD = połowa punktów, nie KD 0)'],
		'value' => ['Wartość', 'intencja i CPC (mała waga)'],
	];

	public static function action(?string $action): string
	{
		return $action === null ? '—' : (StrategyAction::tryFrom($action)?->label() ?? $action);
	}

	public static function reason(?string $code): string
	{
		return $code === null ? '—' : ReasonCode::label($code);
	}

	public static function status(?string $status): string
	{
		return $status === null ? '—' : (TopicStatus::tryFrom($status)?->label() ?? $status);
	}

	public static function source(string $code): string
	{
		return StrategySource::tryFrom($code)?->label() ?? $code;
	}

	public static function family(string $code): string
	{
		return EvidenceFamily::tryFrom($code)?->label() ?? $code;
	}

	public static function shape(?string $shape): string
	{
		return $shape === null ? '—' : (ResultShape::tryFrom($shape)?->label() ?? $shape);
	}

	public static function serpIntent(?string $intent): string
	{
		return $intent === null ? '—' : (SerpIntentSignal::tryFrom($intent)?->label() ?? $intent);
	}

	public static function event(string $type): string
	{
		return TopicEvent::label($type);
	}

	public static function level(?string $level): string
	{
		return match ($level) {
			'high' => 'wysoka',
			'medium' => 'średnia',
			'low' => 'niska',
			default => '—',
		};
	}

	/** Pasmo Pozycji SERP tematu (pomiar odniesienia). */
	public static function serpBand(?string $band): string
	{
		return match ($band) {
			'top3' => 'TOP 3',
			'top10' => 'TOP 10',
			'top20' => 'TOP 20',
			'top50' => 'TOP 50',
			'top100' => 'TOP 100',
			'out' => 'Poza sprawdzonymi wynikami',
			'stale' => 'Pomiar nieaktualny (31–90 dni)',
			default => 'Brak świeżego pomiaru',
		};
	}

	public static function confidenceFactor(string $code): string
	{
		return match ($code) {
			'manual_target' => 'ręcznie wskazana strona docelowa',
			'manual_no_page' => 'ręcznie potwierdzony brak strony',
			'target_confirmed' => 'potwierdzona strona docelowa',
			'target_probable' => 'prawdopodobna strona docelowa',
			'target_conflict' => 'konflikt stron docelowych',
			'target_none' => 'brak znanej strony docelowej (bez kary — to nie dowód braku strony)',
			'target_weak' => 'strona docelowa wskazana tylko słabym sygnałem',
			'target_unknown' => 'nieznana strona docelowa',
			'family_gsc' => 'dane Google Search Console',
			'family_serp_fresh' => 'świeży pomiar SERP (≤ 30 dni)',
			'family_serp_stale' => 'nieaktualny pomiar SERP (31–90 dni)',
			'family_labs' => 'dane DataForSEO Labs',
			'gsc_sample_large' => 'duża próba wyświetleń GSC',
			'gsc_sample_medium' => 'średnia próba wyświetleń GSC',
			'volume_known' => 'znany wolumen',
			'independent_basis' => 'niezależne podstawy decyzji z różnych źródeł',
			'opportunity_high_confidence' => 'szansa SEO z wysoką pewnością',
			'create_evidence' => 'dodatkowe dowody dla nowej strony',
			'no_gsc_data' => 'brak danych GSC projektu',
			'gsc_incomplete' => 'niepełne dane GSC w oknie',
			'serp_stale' => 'tylko nieaktualny pomiar SERP',
			'serp_missing' => 'brak pomiaru SERP',
			'conflict_strong' => 'silny sygnał konfliktu adresów',
			'conflict_moderate' => 'umiarkowany sygnał konfliktu adresów',
			'url_flip' => 'zmiana strony rankującej między pomiarami',
			'spell_correction' => 'wyszukiwarka poprawia pisownię frazy',
			'intent_mismatch' => 'intencja dostawcy niezgodna z sygnałem z SERP',
			'labs_only' => 'tylko dane DataForSEO Labs',
			'serp_not_found_with_target' => 'strona wskazana, ale projekt poza sprawdzonymi wynikami SERP',
			default => $code,
		};
	}

	public static function confidenceCap(string $code): string
	{
		return match ($code) {
			'investigate' => 'działanie „Do sprawdzenia” — pewność najwyżej średnia',
			'create_without_page_index' => 'kandydat na nową stronę bez pełnego indeksu stron projektu — pewność najwyżej średnia',
			'target_weak' => 'słabe wskazanie strony — pewność najwyżej średnia',
			'stale_serp' => 'decyzja zależna od pozycji przy nieaktualnym pomiarze — pewność najwyżej średnia',
			default => $code,
		};
	}

	public static function grouping(?string $basis): string
	{
		return match ($basis) {
			'leader' => 'fraza główna',
			'pin' => 'przypięta ręcznie',
			'same_target' => 'ta sama strona docelowa',
			'serp_overlap' => 'silny overlap SERP',
			null => '—',
			default => $basis,
		};
	}

	public static function conflict(string $type): string
	{
		return match ($type) {
			'cannibalization' => 'Możliwa kanibalizacja (Szanse SEO)',
			'gsc_split' => 'Wyświetlenia rozłożone na kilka stron (GSC)',
			'target_mismatch' => 'W wynikach rankuje inna strona niż wskazana',
			'url_flip' => 'Zmiana strony rankującej między pomiarami',
			'multiple_top10' => 'Kilka stron projektu w TOP10',
			'overlap_targets' => 'Podobne wyniki wyszukiwania, różne strony docelowe',
			default => $type,
		};
	}

	/** Podstawa wskazania strony docelowej (głos PageVote). */
	public static function vote(string $basis): string
	{
		return match ($basis) {
			'serp_top20' => 'pomiar SERP: strona w TOP20',
			'serp_top50' => 'pomiar SERP: strona na pozycji 21–50',
			'serp_beyond50' => 'pomiar SERP: strona poza TOP50',
			'gsc_dominant' => 'GSC: dominująca strona frazy',
			'gsc_share' => 'GSC: istotny udział wyświetleń',
			'gsc_minor' => 'GSC: niewielki udział wyświetleń',
			'labs_target' => 'DataForSEO Labs: adres projektu',
			'slug_match' => 'dopasowanie adresu do frazy (heurystyka)',
			default => $basis,
		};
	}

	public static function strength(?string $strength): string
	{
		return match ($strength) {
			'strong' => 'silne',
			'medium' => 'średnie',
			'weak' => 'słabe',
			default => '—',
		};
	}

	/** Uzasadnienie stanu strony docelowej. */
	public static function targetReason(string $code): string
	{
		return match ($code) {
			'manual' => 'wskazanie ręczne',
			'manual_none' => 'ręcznie potwierdzony brak strony',
			'independent_families' => 'co najmniej dwie niezależne rodziny dowodów',
			'single_strong_family' => 'jedno silne wskazanie',
			'two_medium_families' => 'dwa średnie wskazania z różnych rodzin',
			'target_weak' => 'tylko słabe wskazanie',
			'competing_urls' => 'kilka stron projektu z mocnymi wskazaniami',
			'secondary_url' => 'inne strony mają słabsze wskazania',
			'possible_existing_page' => 'możliwa istniejąca strona (ślady w starszych pomiarach albo Labs)',
			'no_known_page' => 'brak wskazań i dodatkowe dowody braku widoczności — to nie dowód, że strona nie istnieje',
			'no_target_evidence' => 'brak wskazań w dostępnych danych',
			'serp_not_found' => 'projekt poza sprawdzonymi wynikami SERP',
			'labs_missing' => 'fraza nieobecna w punkcie odniesienia DataForSEO Labs',
			default => $code,
		};
	}

	/** Dowód braku widoczności (nigdy dowód braku strony). */
	public static function noVisibility(string $code): string
	{
		return match ($code) {
			'serp_not_found' => 'projekt poza sprawdzonymi wynikami SERP',
			'labs_missing' => 'fraza nieobecna w punkcie odniesienia DataForSEO Labs',
			default => $code,
		};
	}

	/** Ślad możliwej istniejącej strony albo sygnał pochodny (nieliczony jako niezależny). */
	public static function hint(string $source): string
	{
		return match ($source) {
			'serp_history' => 'adres projektu ze starszego pomiaru SERP',
			'serp_previous' => 'adres projektu z poprzedniego pomiaru SERP',
			'labs_rank' => 'pozycja projektu w DataForSEO Labs',
			'opportunity' => 'strona szansy SEO (pochodna GSC)',
			'discovery' => 'strona z Nowych fraz (pochodna GSC)',
			'gap_gsc' => 'strona luki (źródło GSC)',
			'gap_serp' => 'strona luki (źródło SERP)',
			default => $source,
		};
	}

	/** Ślad reguły klasyfikatora działań. */
	public static function check(string $why): string
	{
		return match ($why) {
			'no_strong_url_conflict' => 'brak silnego konfliktu adresów',
			'competing_urls' => 'konkurujące strony projektu',
			'url_flip_without_confirmed_target' => 'zmiana adresu bez potwierdzonej strony',
			'no_known_target' => 'brak znanej strony docelowej',
			'no_decline' => 'brak spadku',
			'intent_mismatch' => 'niezgodna intencja',
			'no_optimization_signal' => 'brak sygnału do optymalizacji',
			'weak_target' => 'słabe wskazanie strony',
			'target_not_none' => 'strona docelowa nie jest „brak znanej strony”',
			'not_stable_top3' => 'brak stabilnej pozycji w TOP 3',
			default => ReasonCode::label($why),
		};
	}

	public static function rule(string $rule): string
	{
		return match ($rule) {
			'consolidate' => 'Konsolidacja',
			'target' => 'Strona docelowa',
			'recover' => 'Odzyskanie',
			'optimize' => 'Optymalizacja',
			'create' => 'Kandydat na nową stronę',
			'monitor' => 'Monitorowanie',
			default => $rule,
		};
	}

	public static function overlapReason(string $code): string
	{
		return match ($code) {
			'no_measurement' => 'brak zgodnego pomiaru frazy',
			'reference_unmeasured' => 'brak pomiaru frazy odniesienia',
			'context_mismatch' => 'pomiary w różnych kontekstach',
			'expired_measurement' => 'pomiar wygasły (> 90 dni)',
			'stale_measurement' => 'pomiar nieaktualny — overlap najwyżej umiarkowany',
			'serp_intent_mismatch' => 'różny sygnał intencji z SERP — overlap najwyżej umiarkowany',
			'provider_intent_mismatch' => 'różna intencja według dostawcy — overlap najwyżej umiarkowany',
			'discounted_ubiquitous_or_home' => 'pominięto domeny wszechobecne i strony główne',
			'ubiquity_unknown' => 'za mało pomiarów, by odróżnić domeny wszechobecne',
			default => $code,
		};
	}

	/** Powód pozycji podglądu analizy SERP. */
	public static function analysisReason(string $code): string
	{
		return match ($code) {
			'fresh_measurement' => 'świeży zgodny pomiar (≤ 30 dni) — użyty ponownie bez kosztu',
			'requested_recently' => 'pomiar w toku (zlecony niedawno)',
			'no_measurement' => 'brak zgodnego pomiaru',
			'stale_measurement' => 'pomiar nieaktualny (31–90 dni)',
			'expired_measurement' => 'pomiar wygasły (> 90 dni)',
			'not_candidate' => 'fraza spoza kandydatów Strategii',
			'inactive_candidate' => 'nieaktywny kandydat',
			'keyword_too_short' => 'fraza za krótka',
			'keyword_too_long' => 'fraza za długa',
			'keyword_invalid_characters' => 'niedozwolone znaki',
			'keyword_search_operator' => 'operator wyszukiwania',
			default => $code,
		};
	}
}
