<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Workspace;

use OsfSeo\Ai\Analysis\AnalysisType;

/**
 * Polskie etykiety przestrzeni roboczej AI (STEP 17, faza D): stany gotowości, kody braków danych, statusy analiz, powody odmów,
 * pola raportu (podstawa, pewność, pilność, wpływ, intencja, rodzaj rekomendacji) — wspólne dla widoków panelu i eksportu tekstowego.
 * Użytkownik nigdy nie widzi kodu technicznego: nieznany kod → opis ogólny.
 */
final class ReportLabels
{
	public const READINESS = [
		'ready' => 'Gotowe do analizy',
		'partial' => 'Częściowo gotowe',
		'insufficient' => 'Brakuje danych',
		'blocked' => 'Niedostępne dla tego tematu',
	];

	public const COMPATIBILITY = [
		'allowed' => 'Zgodny z działaniem Strategii',
		'explicit' => 'Wymaga świadomego wyboru',
		'blocked' => 'Niezgodny z działaniem Strategii',
	];

	/** Kody gotowości (`Readiness::MEANINGS`) — po polsku, prostym językiem. */
	public const READINESS_CODES = [
		'no_strategy_decision' => 'Temat nie ma jeszcze decyzji Strategii — najpierw przelicz Strategię.',
		'topic_inactive' => 'Temat jest nieaktywny (scalony albo poza Strategią).',
		'action_not_supported' => 'Ten rodzaj analizy nie pasuje do działania Strategii dla tego tematu.',
		'explicit_choice_required' => 'Ten rodzaj analizy nie jest zalecany dla działania Strategii — możesz go wybrać tylko świadomie.',
		'target_page_unknown' => 'Nie znamy strony docelowej tematu, a optymalizacja wymaga znanej strony.',
		'page_not_fetched' => 'Strona docelowa nie została jeszcze pobrana.',
		'page_fetch_failed' => 'Ostatnie pobranie strony nie powiodło się, a wcześniejszej kopii nie ma. To nie dowód, że strona nie istnieje.',
		'page_content_empty' => 'W pobranym HTML nie wykryto czytelnej treści.',
		'no_keywords' => 'Temat nie ma fraz.',
		'no_competitor_snapshots' => 'Brak pobranych stron konkurencji z pomiaru SERP fraz tematu.',
		'page_content_incomplete' => 'Kopia strony jest niepełna (np. treść ładowana JavaScriptem). Brak tekstu w kopii nie oznacza braku na stronie.',
		'page_content_partial' => 'Z kopii strony udało się wyodrębnić tylko część treści.',
		'page_snapshot_stale' => 'Kopia strony jest starsza niż okno świeżości.',
		'page_last_fetch_failed' => 'Ostatnie pobranie nie powiodło się — analiza użyje wcześniejszej kopii.',
		'intent_unknown' => 'Brak sygnału intencji wyszukiwania — intencja zostanie oznaczona jako niepewna.',
		'no_market_data' => 'Brak wolumenu wyszukiwań dla fraz tematu.',
		'serp_stale' => 'Pomiar SERP ma 31–90 dni — tylko ograniczona interpretacja wyników.',
		'serp_expired' => 'Pomiar SERP ma ponad 90 dni — bez wniosków o aktualnych wynikach.',
		'competitor_serp_stale' => 'Część stron konkurencji pochodzi z pomiaru SERP sprzed 31–90 dni.',
		'competitor_serp_expired' => 'Część stron konkurencji pochodzi z pomiaru SERP sprzed ponad 90 dni.',
		'serp_and_page_dates_differ' => 'Strony konkurencji pobrano ponad 7 dni od pomiaru SERP — wyniki i treść nie pochodzą z tej samej chwili.',
		'single_competitor' => 'Tylko jedna przydatna strona konkurencji — nie da się ustalić tematów powtarzających się u konkurentów.',
		'competitor_content_incomplete' => 'Część kopii stron konkurencji jest niepełna i została pominięta w porównaniu.',
		'known_page_may_cover_topic' => 'Projekt ma już stronę powiązaną z tematem — nowa strona może ją dublować.',
		'page_index_incomplete' => 'Indeks stron projektu jest niepełny (strony znane tylko z GSC) — w witrynie mogą istnieć inne strony.',
		'no_gsc_data' => 'Brak danych GSC dla tematu (to nie dowód braku widoczności).',
		'no_serp_measurement' => 'Brak pomiaru SERP dla fraz tematu.',
		'no_competitor_pages' => 'Brak pobranych stron konkurencji powiązanych z tematem.',
		'candidate_page' => 'Brief dotyczy kandydata na nową stronę — to nie potwierdzenie, że witryna nie ma takiej strony.',
		'missing_page_confirmed_manually' => 'Brak strony w witrynie potwierdzono ręcznie.',
	];

	/** Braki danych kontekstu (`TopicContextAssembler::DATA_GAPS`). */
	public const DATA_GAPS = [
		'no_gsc_connection' => 'Projekt nie ma połączenia z Google Search Console — dane GSC są nieznane (nie zerowe).',
		'no_gsc_data' => 'Brak danych GSC dla tematu — to nie dowód braku widoczności.',
		'gsc_window_incomplete' => 'Dane GSC nie obejmują całego okna (np. trwa import).',
		'gsc_no_impressions' => 'Frazy tematu nie miały wyświetleń w GSC w oknie danych.',
		'gsc_stale' => 'Najnowsze dane GSC są starsze niż 7 dni.',
		'no_serp_measurement' => 'Brak pomiaru SERP — pozycja i konkurenci w wynikach są nieznani.',
		'serp_stale' => 'Pomiar SERP ma 31–90 dni — niższa pewność.',
		'serp_expired' => 'Pomiar SERP ma ponad 90 dni — wyniki nie są interpretowane.',
		'no_market_data' => 'Brak wolumenu wyszukiwań dla fraz tematu.',
		'market_data_partial' => 'Część fraz tematu nie ma wolumenu wyszukiwań.',
		'no_labs_import' => 'Brak importu Luk SEO (Labs) dla projektu.',
		'target_unknown' => 'Strona docelowa tematu jest nieznana.',
		'target_none_not_proof' => 'Brak znanej strony w dostępnych źródłach — to nie dowód, że witryna nie ma takiej strony.',
		'target_conflict' => 'Kilka stron projektu konkuruje o temat (sygnały konfliktu adresów).',
		'page_content_not_fetched' => 'Strona docelowa nie została pobrana — treść strony jest nieznana.',
		'page_fetch_failed' => 'Ostatnie pobranie strony docelowej nie powiodło się — to nie dowód, że strona nie istnieje.',
		'page_content_incomplete' => 'Pobranego HTML nie udało się wiarygodnie odczytać (np. treść z JavaScriptu).',
		'page_content_partial' => 'Z kopii strony wyodrębniono tylko część treści.',
		'page_snapshot_stale' => 'Kopia strony jest starsza niż okno świeżości.',
		'competitor_pages_not_fetched' => 'Nie pobrano stron konkurencji z SERP — ich treść jest nieznana.',
		'serp_and_page_dates_differ' => 'Strony konkurencji pobrano ponad 7 dni od pomiaru SERP.',
		'page_index_incomplete' => 'Indeks stron projektu jest niepełny — strona spoza indeksu może istnieć.',
		'keywords_omitted' => 'Część fraz tematu pominięto w kontekście (limity).',
		'context_reduced' => 'Kontekst skrócono, aby zmieścić się w limicie rozmiaru.',
	];

	public const STATUSES = [
		'queued' => 'W kolejce',
		'reserved' => 'W przygotowaniu',
		'running' => 'W trakcie',
		'succeeded' => 'Gotowa',
		'invalid' => 'Odrzucona przez kontrolę jakości',
		'failed' => 'Nieudana',
		'uncertain' => 'Wynik niepewny',
	];

	/** Powody nieudanych analiz i odmów (wyłącznie dla administratora; bez treści wyjątków). */
	public const ERRORS = [
		'plan_changed' => 'Dane albo konfiguracja zmieniły się od zatwierdzenia planu — przygotuj analizę ponownie.',
		'plan_approval_required' => 'Płatna analiza wymaga zatwierdzenia planu.',
		'confirmation_required' => 'Płatna analiza wymaga jawnego potwierdzenia kosztu.',
		'explicit_confirmation_required' => 'Wybór typu niezalecanego przez Strategię wymaga osobnego potwierdzenia.',
		'already_generated' => 'Ta sama analiza (ten sam plan) została już wygenerowana.',
		'run_in_progress' => 'Analiza tego typu dla tematu jest już w toku.',
		'run_not_queued' => 'Analiza nie czeka już w kolejce.',
		'run_conflict' => 'Analizę przejął inny proces.',
		'cancelled' => 'Anulowano przed wysłaniem — bez kosztu.',
		'queue_expired' => 'Zlecenie czekało w kolejce zbyt długo i wygasło — bez kosztu.',
		'project_unavailable' => 'Projekt był niedostępny w chwili wykonania.',
		'topic_not_found' => 'Temat nie istnieje już w Strategii.',
		'readiness_insufficient' => 'Brakowało danych w chwili wykonania — bez wywołania modelu.',
		'readiness_blocked' => 'Analiza była niedostępna dla tematu w chwili wykonania.',
		'readiness_unknown' => 'Nie udało się ocenić gotowości danych.',
		'analysis_type_unknown' => 'Nieznany rodzaj analizy.',
		'ai_disabled' => 'Płatne analizy AI są wyłączone w konfiguracji serwera.',
		'provider_not_configured' => 'Ten dostawca nie jest wybrany w konfiguracji serwera.',
		'provider_unknown' => 'Nieznany dostawca AI.',
		'missing_api_key' => 'Brak klucza API dostawcy w konfiguracji serwera.',
		'model_not_configured' => 'Brak modelu w konfiguracji serwera.',
		'missing_prices' => 'Brak cen modelu w konfiguracji serwera — koszt nie może zostać oszacowany.',
		'context_too_large' => 'Kontekst analizy przekracza dopuszczalny rozmiar.',
		'run_cost_limit' => 'Szacowany koszt przekracza limit jednej analizy.',
		'budget_daily' => 'Przekroczony dzienny budżet AI.',
		'budget_monthly' => 'Przekroczony miesięczny budżet AI.',
		'budget_project' => 'Przekroczony miesięczny budżet AI projektu.',
		'budget_busy' => 'Budżet AI jest chwilowo zajęty innym zleceniem — spróbuj za chwilę.',
		'decision_not_allowed' => 'Decyzję można zapisać tylko dla gotowej analizy.',
		'decision_invalid' => 'Nieznana decyzja.',
		'internal_error' => 'Nieoczekiwany błąd wykonania — szczegóły w logu serwera.',
		'interrupted' => 'Proces wykonania został przerwany po wysłaniu żądania.',
		'not_sent' => 'Proces został przerwany przed wysłaniem żądania — bez kosztu.',
		'config' => 'Błąd konfiguracji dostawcy.',
		'auth' => 'Dostawca odrzucił dane uwierzytelniające.',
		'rejected' => 'Dostawca odrzucił żądanie.',
		'rate_limited' => 'Dostawca ograniczył liczbę żądań — spróbuj później.',
		'server' => 'Błąd po stronie dostawcy.',
		'transport' => 'Błąd połączenia z dostawcą — wynik niepewny, bez automatycznego ponowienia.',
		'invalid_response' => 'Niepoprawna odpowiedź dostawcy.',
		'refused' => 'Model odmówił odpowiedzi.',
		'incomplete' => 'Odpowiedź modelu była niepełna.',
		'incomplete_max_output_tokens' => 'Odpowiedź przerwana na limicie tokenów odpowiedzi (koszt naliczony) — zwiększ OSF_SEO_AI_MAX_OUTPUT_TOKENS albo obniż OSF_SEO_AI_REASONING_EFFORT.',
		'incomplete_content_filter' => 'Odpowiedź przerwana przez filtr treści dostawcy (koszt naliczony).',
		'failed' => 'Dostawca zgłosił błąd wykonania.',
		'plan_already_used' => 'Ten plan został już wysłany do dostawcy (koszt naliczony, bez gotowego wyniku) — ponowne wysłanie tylko jawnie, jako nowy koszt.',
		'run_counts_toward_budget' => 'Płatnej analizy z bieżącego miesiąca nie można usunąć — jej koszt liczy się do budżetu.',
		'run_active' => 'Analiza jest w toku — nie można jej usunąć.',
		'project_not_allowed' => 'Płatne analizy są dozwolone tylko dla projektów wskazanych w konfiguracji testu (OSF_SEO_AI_ALLOWED_PROJECTS).',
		'type_not_allowed' => 'Ten rodzaj analizy nie jest dozwolony w konfiguracji testu (OSF_SEO_AI_ALLOWED_TYPES).',
		'invalid_reasoning_effort' => 'Niepoprawna wartość OSF_SEO_AI_REASONING_EFFORT w konfiguracji serwera.',
		'request_encoding' => 'Nie udało się przygotować żądania do dostawcy — bez wysyłania.',
	];

	public const BASIS = [
		'fact' => 'Fakt',
		'inference' => 'Wniosek',
		'hypothesis' => 'Hipoteza',
		'evidence' => 'Z dowodów',
	];

	public const BASIS_HINT = [
		'fact' => 'wprost z danych pomiarowych',
		'inference' => 'wniosek z kilku dowodów',
		'hypothesis' => 'do sprawdzenia — bez bezpośredniego dowodu',
		'evidence' => 'wniosek oparty na dowodach (analiza tematu)',
	];

	public const CONFIDENCE = [
		'high' => 'Wysoka pewność',
		'medium' => 'Średnia pewność',
		'low' => 'Niska pewność',
	];

	public const URGENCY = [
		'now' => 'Teraz',
		'next' => 'W kolejnym kroku',
		'optional' => 'Opcjonalnie',
	];

	public const IMPACT = [
		'high' => 'Duży',
		'medium' => 'Średni',
		'low' => 'Mały',
		'unknown' => 'Nieznany',
	];

	public const INTENTS = [
		'informational' => 'Informacyjna',
		'commercial' => 'Komercyjna (porównanie, wybór)',
		'transactional' => 'Transakcyjna',
		'navigational' => 'Nawigacyjna',
		'local' => 'Lokalna',
		'mixed' => 'Mieszana',
		'unknown' => 'Niepewna',
	];

	public const RECOMMENDATION_TYPES = [
		'title' => 'Tytuł strony',
		'meta_description' => 'Opis meta',
		'h1' => 'Nagłówek H1',
		'heading_structure' => 'Struktura nagłówków',
		'new_section' => 'Nowa sekcja',
		'expand_section' => 'Rozbudowa sekcji',
		'internal_linking' => 'Linkowanie wewnętrzne',
		'faq' => 'Pytania i odpowiedzi',
		'intent_alignment' => 'Dopasowanie do intencji',
		'content_scope' => 'Zakres treści',
		'page_role' => 'Rola strony',
		'consolidation_check' => 'Sprawdzenie konsolidacji',
		'technical_check' => 'Kontrola techniczna',
		'other' => 'Inne',
	];

	public const FINDING_KINDS = [
		'problem' => 'Problem',
		'opportunity' => 'Szansa',
		'strength' => 'Mocna strona',
		'risk' => 'Ryzyko',
		'observation' => 'Obserwacja',
	];

	public const TOPIC_STATUSES = [
		'present_on_project_page' => 'Jest na stronie projektu',
		'common_among_competitors' => 'Powtarza się u konkurencji',
		'potentially_missing' => 'Potencjalnie brakuje',
		'shallow_on_project_page' => 'Opisane zbyt pobieżnie',
		'not_recommended' => 'Niezalecane',
	];

	/** Opisy typów analiz dla panelu (co dostaje użytkownik). */
	public const TYPE_DESCRIPTIONS = [
		AnalysisType::PAGE_OPTIMIZATION => 'Rekomendacje dla istniejącej strony docelowej: tytuł i opis meta, nagłówki, zakres treści, linkowanie.',
		AnalysisType::NEW_PAGE_BRIEF => 'Brief dla kandydata na nową stronę: konspekt H1–H3, tytuł i opis meta, pytania użytkowników, dane od klienta.',
		AnalysisType::CONTENT_GAP => 'Porównanie strony projektu ze stronami konkurencji z SERP: tematy wspólne, brakujące i opisane pobieżnie.',
	];

	public static function type(string $type): string
	{
		return AnalysisType::label($type) === $type ? 'Analiza AI' : AnalysisType::label($type);
	}

	public static function readiness(?string $state): string
	{
		return self::READINESS[(string) $state] ?? 'Nieznany stan danych';
	}

	public static function readinessCode(string $code): string
	{
		return self::READINESS_CODES[$code] ?? 'Ograniczenie danych analizy.';
	}

	public static function dataGap(string $code): string
	{
		return self::DATA_GAPS[$code] ?? 'Brak części danych.';
	}

	public static function compatibility(?string $mode): string
	{
		return self::COMPATIBILITY[(string) $mode] ?? '—';
	}

	/** Dostawca bez nazwy technicznej: testowy (bez kosztów) albo nazwa dostawcy płatnego. */
	public static function provider(string $provider): string
	{
		return match ($provider) {
			'fake' => 'Dostawca testowy (bez kosztów)',
			'openai' => 'OpenAI',
			default => 'Dostawca AI',
		};
	}

	public static function costBasis(?string $basis): string
	{
		return match ($basis) {
			'usage' => 'rozliczono według zużycia',
			'reservation' => 'liczona pełna rezerwacja (wynik niepewny)',
			'not_charged' => 'bez kosztu',
			'free' => 'bez kosztu (dostawca testowy)',
			default => 'w trakcie rozliczenia',
		};
	}

	public static function status(string $status, ?string $decision = null): string
	{
		if ($status === 'succeeded' && $decision === 'rejected') {
			return 'Odrzucona przez użytkownika';
		}

		return self::STATUSES[$status] ?? 'Nieznany status';
	}

	public static function error(?string $code): ?string
	{
		if ($code === null || $code === '') {
			return null;
		}

		return self::ERRORS[$code] ?? 'Analiza nie została wykonana.';
	}

	public static function basis(?string $basis): string
	{
		return self::BASIS[(string) $basis] ?? 'Nieokreślona podstawa';
	}

	public static function confidence(?string $confidence): string
	{
		return self::CONFIDENCE[(string) $confidence] ?? 'Pewność nieokreślona';
	}

	public static function urgency(?string $urgency): string
	{
		return self::URGENCY[(string) $urgency] ?? '—';
	}

	public static function impact(?string $impact): string
	{
		return self::IMPACT[(string) $impact] ?? 'Nieznany';
	}

	public static function intent(?string $intent): string
	{
		return self::INTENTS[(string) $intent] ?? 'Niepewna';
	}

	public static function recommendationType(?string $type): string
	{
		return self::RECOMMENDATION_TYPES[(string) $type] ?? 'Inne';
	}

	public static function findingKind(?string $kind): string
	{
		return self::FINDING_KINDS[(string) $kind] ?? 'Obserwacja';
	}

	public static function topicStatus(?string $status): string
	{
		return self::TOPIC_STATUSES[(string) $status] ?? '—';
	}

	/** Priorytet rekomendacji 1–5 (1 = najważniejsza). */
	public static function priority(mixed $priority): string
	{
		return is_int($priority) && $priority >= 1 && $priority <= 5 ? 'Priorytet ' . $priority . ' z 5' : 'Priorytet nieokreślony';
	}
}
