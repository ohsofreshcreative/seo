<?php

namespace App\Panel;

/**
 * Etykiety Page Intelligence w panelu (wyłącznie prezentacja): rodzaj i źródło strony, stan pamięci, jakość ekstrakcji, dyrektywy
 * indeksowania, wyniki i powody odmowy pobrania, statusy zleceń. Nieznany kod → opis ogólny (użytkownik nie widzi kodów technicznych).
 */
final class PageLabels
{
	public const KINDS = ['project' => 'Strona projektu', 'competitor' => 'Strona konkurenta'];

	public const SOURCES = ['manual' => 'Dodana ręcznie', 'topic' => 'Strona docelowa tematu', 'serp' => 'Wynik SERP'];

	public const STATUSES = ['new' => 'Jeszcze nie pobrana', 'ok' => 'Pobrana', 'failed' => 'Ostatnie pobranie nieudane', 'blocked' => 'Pobranie zablokowane'];

	public const CACHE = [
		'fresh' => 'Aktualna kopia',
		'stale' => 'Starsza kopia',
		'failed' => 'Pobranie nieudane',
		'missing' => 'Nie pobrano',
	];

	public const QUALITY = [
		'good' => 'Dobra',
		'partial' => 'Częściowa',
		'incomplete' => 'Niepełna',
		'empty' => 'Brak treści',
	];

	public const QUALITY_REASONS = [
		'js_framework_markers' => 'Ślady aplikacji JavaScript w HTML',
		'js_rendered_suspected' => 'Treść prawdopodobnie ładowana JavaScriptem — pobrany HTML może jej nie zawierać',
		'thin_extracted_text' => 'Mało tekstu w pobranym HTML',
		'no_main_landmark' => 'Brak znacznika treści głównej (<main>) — treść wyodrębniona z całej strony',
		'content_truncated' => 'Treść przycięta do limitu rozmiaru',
		'malformed_html' => 'HTML z wieloma błędami składni',
		'unparsable_html' => 'Nie udało się odczytać HTML',
	];

	public const INDEXABILITY = [
		'indexable_by_directives' => 'Brak dyrektywy noindex w HTML i nagłówkach',
		'blocked_by_directives' => 'Dyrektywa noindex w HTML albo nagłówkach',
		'unknown' => 'Nieznane',
	];

	public const CANONICAL = [
		'self' => 'Wskazuje na tę stronę',
		'other' => 'Wskazuje inny adres',
		'missing' => 'Brak',
		'multiple' => 'Kilka różnych wskazań',
		'invalid' => 'Nieprawidłowy adres',
	];

	public const TRUNCATED = [
		'html' => 'HTML przycięty do limitu rozmiaru',
		'main_text' => 'Tekst główny przycięty',
		'sections' => 'Część sekcji pominięta (limit)',
		'section_text' => 'Tekst części sekcji przycięty',
		'headings' => 'Część nagłówków pominięta (limit)',
		'links' => 'Część linków pominięta (limit)',
	];

	/** Wyniki pobrania (pozycje zlecenia i historia prób). */
	public const OUTCOMES = [
		'created' => 'Pobrano — pierwsza kopia',
		'changed' => 'Pobrano — treść się zmieniła',
		'unchanged' => 'Pobrano — treść bez zmian',
		'not_modified' => 'Pobrano — treść bez zmian',
		'cached' => 'Bez pobierania — aktualna kopia w pamięci',
		'ok' => 'Pobrano',
		'refused' => 'Odmowa przed pobraniem',
		'robots' => 'Zablokowane przez robots.txt',
		'http_error' => 'Serwer zwrócił błąd',
		'failed' => 'Pobranie nieudane',
	];

	/** Powody odmowy i błędy pobrania (po polsku; szczegóły techniczne zostają w logu serwera). */
	public const ERRORS = [
		'transport_unavailable' => 'Bezpieczne pobieranie stron jest niedostępne na tym serwerze.',
		'nothing_selected' => 'Nie wybrano żadnej strony.',
		'too_many_urls' => 'Za dużo adresów w jednym zleceniu.',
		'url_not_allowed' => 'Adres spoza projektu: dozwolone są strony domeny projektu, domen konkurentów projektu i wyników zapisanych pomiarów SERP.',
		'outside_scope' => 'Adres spoza dozwolonego zakresu projektu.',
		'kind_disabled' => 'Pobieranie tego rodzaju stron jest wyłączone w konfiguracji.',
		'topic_without_target' => 'Temat nie ma znanej strony docelowej.',
		'topic_not_found' => 'Temat nie istnieje już w Strategii.',
		'no_serp_measurement' => 'Brak zapisanego pomiaru SERP dla frazy.',
		'rank_not_found' => 'W zapisanym pomiarze SERP nie ma wyniku na tej pozycji.',
		'no_ranks_selected' => 'Nie wybrano pozycji z SERP.',
		'domain_cooldown' => 'Odstęp między pobraniami z tej witryny — kolejna próba za chwilę.',
		'host_busy' => 'Witryna jest właśnie pobierana — kolejna próba za chwilę.',
		'fetch_in_progress' => 'Ta strona jest właśnie pobierana — kolejna próba za chwilę.',
		'host_retry_after' => 'Witryna poprosiła o przerwę (Retry-After) — bez automatycznego ponowienia.',
		'domain_daily_limit' => 'Dzienny limit pobrań z tej witryny został wyczerpany.',
		'queue_busy' => 'Kolejka zleceń jest chwilowo zajęta — spróbuj ponownie.',
		'too_many_jobs' => 'Projekt ma już kilka zleceń w toku — poczekaj na ich zakończenie.',
		'project_unavailable' => 'Projekt był niedostępny w chwili wykonania.',
		'interrupted' => 'Przetwarzanie zostało przerwane.',
		'internal_error' => 'Nieoczekiwany błąd — szczegóły w logu serwera.',
		'robots_disallowed' => 'robots.txt witryny nie pozwala na pobranie tej strony.',
		'robots_unreachable' => 'Nie udało się odczytać robots.txt witryny — strona nie została pobrana.',
		'robots_invalid_url' => 'Nieprawidłowy adres robots.txt.',
		'dns_failed' => 'Nie udało się rozwiązać nazwy domeny.',
		'connect_failed' => 'Nie udało się połączyć z serwerem.',
		'connection_error' => 'Błąd połączenia.',
		'network_error' => 'Błąd sieci.',
		'tls_error' => 'Błąd połączenia szyfrowanego (TLS).',
		'timeout' => 'Przekroczono czas oczekiwania.',
		'too_large' => 'Strona przekracza dopuszczalny rozmiar.',
		'headers_too_large' => 'Nagłówki odpowiedzi są zbyt duże.',
		'too_many_redirects' => 'Zbyt wiele przekierowań.',
		'redirect_outside_scope' => 'Przekierowanie poza zakres projektu — zatrzymane.',
		'redirect_downgrade' => 'Przekierowanie z HTTPS na HTTP — zatrzymane.',
		'redirect_without_location' => 'Przekierowanie bez adresu docelowego.',
		'unsupported_content_type' => 'Odpowiedź nie jest stroną HTML.',
		'invalid_response' => 'Niepoprawna odpowiedź serwera.',
		'http_error' => 'Serwer zwrócił kod błędu HTTP.',
		'invalid_url' => 'Nieprawidłowy adres.',
		'scheme_not_allowed' => 'Dozwolone są tylko adresy http i https.',
		'port_not_allowed' => 'Niedozwolony port.',
		'credentials_not_allowed' => 'Adres nie może zawierać danych logowania.',
		'ip_literal_not_allowed' => 'Adres IP zamiast nazwy domeny jest niedozwolony.',
		'host_not_allowed' => 'Niedozwolona nazwa hosta.',
		'invalid_host' => 'Nieprawidłowa nazwa hosta.',
		'ip_private' => 'Domena wskazuje na adres sieci prywatnej — zablokowano.',
		'ip_loopback' => 'Domena wskazuje na adres lokalny — zablokowano.',
		'ip_link_local' => 'Domena wskazuje na adres lokalny łącza — zablokowano.',
		'ip_reserved' => 'Domena wskazuje na adres zarezerwowany — zablokowano.',
		'ip_multicast' => 'Domena wskazuje na adres multicast — zablokowano.',
		'ip_unspecified' => 'Domena wskazuje na nieprawidłowy adres — zablokowano.',
		'ip_embedded' => 'Domena wskazuje na niedozwolony adres — zablokowano.',
		'pinning_violation' => 'Połączenie z innym adresem niż sprawdzony — zablokowano.',
	];

	public const JOB_STATUSES = [
		'queued' => 'W kolejce',
		'running' => 'W trakcie',
		'completed' => 'Zakończone',
		'failed' => 'Nieudane',
		'cancelled' => 'Anulowane',
	];

	public static function kind(?string $kind): string
	{
		return self::KINDS[(string) $kind] ?? 'Strona';
	}

	public static function source(?string $source): string
	{
		return self::SOURCES[(string) $source] ?? '—';
	}

	public static function status(?string $status): string
	{
		return self::STATUSES[(string) $status] ?? '—';
	}

	public static function cache(?string $cache): string
	{
		return self::CACHE[(string) $cache] ?? 'Nie pobrano';
	}

	public static function quality(?string $quality): string
	{
		return self::QUALITY[(string) $quality] ?? '—';
	}

	public static function qualityReason(string $code): string
	{
		return self::QUALITY_REASONS[$code] ?? 'Ograniczenie ekstrakcji';
	}

	public static function indexability(?string $value): string
	{
		return self::INDEXABILITY[(string) $value] ?? 'Nieznane';
	}

	public static function canonical(?string $value): string
	{
		return self::CANONICAL[(string) $value] ?? '—';
	}

	public static function truncated(string $code): string
	{
		return self::TRUNCATED[$code] ?? 'Część danych przycięta';
	}

	public static function outcome(?string $outcome): string
	{
		return self::OUTCOMES[(string) $outcome] ?? 'Oczekuje';
	}

	public static function error(?string $code): ?string
	{
		if ($code === null || $code === '') {
			return null;
		}

		if (str_starts_with($code, 'http_') && ctype_digit(substr($code, 5))) {
			return 'Serwer zwrócił kod HTTP ' . substr($code, 5) . '.';
		}

		return self::ERRORS[$code] ?? 'Pobranie nie powiodło się.';
	}

	public static function jobStatus(?string $status): string
	{
		return self::JOB_STATUSES[(string) $status] ?? '—';
	}

	/** Stan pamięci jako kolor plakietki. */
	public static function cacheTone(?string $cache): string
	{
		return match ($cache) {
			'fresh' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
			'stale' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
			'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
			default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
		};
	}

	public static function qualityTone(?string $quality): string
	{
		return match ($quality) {
			'good' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
			'partial' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
			'incomplete', 'empty' => 'bg-red-50 text-red-700 ring-red-600/20',
			default => 'bg-slate-100 text-slate-600 ring-slate-500/20',
		};
	}

	/** Bezpieczny odnośnik zewnętrzny: wyłącznie http(s). */
	public static function safeUrl(?string $url): bool
	{
		return $url !== null && preg_match('#^https?://#i', $url) === 1;
	}
}
