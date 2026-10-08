<?php

namespace App\Panel;

use OsfSeo\Opportunities\Text;

/**
 * Prezentacja stanu przeliczenia Strategii (faza E): faza zadania z `StrategyService::panelState()['job']` → etykieta, opis i kolory
 * paska stanu — wspólne dla widoku i endpointu statusu (odpytywanie). Bez treści błędów: administrator widzi kod błędu, klient nie.
 */
final class StrategyRefreshView
{
	/** Fazy, w których przeliczenie czeka albo trwa (pasek odpytuje status i przeładowuje stronę po zakończeniu). */
	public const ACTIVE = ['queued', 'retrying', 'running'];

	public static function label(string $phase): string
	{
		return match ($phase) {
			'current' => 'Aktualne dane',
			'pending', 'queued', 'retrying' => 'Oczekuje na przeliczenie',
			'running' => 'Przeliczanie',
			'done' => 'Zakończono',
			'failed' => 'Błąd przeliczenia',
			'unsupported' => 'Rynek nieobsługiwany',
			default => $phase,
		};
	}

	/**
	 * @param array<string, mixed> $state `StrategyService::panelState()`
	 */
	public static function detail(array $state, bool $manage): string
	{
		$job = $state['job'];

		return match ($job['phase']) {
			'current' => 'Tematy odpowiadają aktualnym danym modułów (przeliczenie lokalne, bez kosztów).',
			'done' => 'Przeliczenie zakończone ' . Format::datetime($job['finished_at']) . ' — tematy odpowiadają aktualnym danym modułów.',
			'pending' => $state['refreshed_at'] === null
				? 'Strategia nie była jeszcze przeliczona — przeliczenie uruchomi się automatycznie w tle (bez kosztów i bez żądań do API).'
				: 'Dane modułów zmieniły się' . ($job['dirty_since'] !== null ? ' (' . Format::datetime($job['dirty_since']) . ')' : '')
					. ' — przeliczenie uruchomi się automatycznie w tle po zakończeniu importu danych, zwykle w ciągu kilku minut.',
			'queued' => ($state['requested_at'] !== null ? 'Zlecone ' . Format::datetime($state['requested_at']) : 'Wykryto nowe dane modułów')
				. ' — przeliczenie wykona zadanie w tle, zwykle w ciągu minuty. Strona odświeży się po zakończeniu.',
			'retrying' => 'Poprzednia próba się nie powiodła — ponowienie około ' . Format::datetime($job['due_at'])
				. ' (próba ' . ($job['attempts'] + 1) . ' z ' . $job['max_attempts'] . ').'
				. ($manage && $job['error'] !== null ? ' Przyczyna: ' . self::error($job['error']) . '.' : ''),
			'running' => 'Przeliczenie trwa' . ($job['started_at'] !== null ? ' od ' . Format::datetime($job['started_at']) : '')
				. ' — widok pokazuje wynik poprzedniego przeliczenia; strona odświeży się po zakończeniu.',
			'failed' => 'Nie udało się przeliczyć Strategii' . ($job['error_at'] !== null ? ' (' . Format::datetime($job['error_at']) . ')' : '')
				. ' — widok pokazuje wynik ostatniego udanego przeliczenia.'
				. ($manage
					? ' Przyczyna: ' . self::error((string) $job['error']) . '. Szczegóły w logu pluginu; kolejna próba automatycznie po zmianie danych albo po ponownym zleceniu.'
					: ' Dane z poprzedniego przeliczenia pozostają dostępne.'),
			'unsupported' => 'Rynek projektu (kraj i język) nie jest obsługiwany przez dostawcę danych rynkowych — Strategia nie może zostać przeliczona. Zmień kraj albo język w ustawieniach projektu.',
			default => '',
		};
	}

	/** Kod błędu zadania (bez treści wyjątku) → opis dla administratora. */
	public static function error(?string $code): string
	{
		return match (true) {
			$code === null || $code === '' => 'nieznana',
			$code === 'interrupted' => 'proces w tle został przerwany (limit czasu lub pamięci serwera)',
			$code === 'unsupported_market' => 'rynek projektu nieobsługiwany',
			$code === 'no_project' => 'projekt niedostępny (usunięty lub zarchiwizowany)',
			$code === 'error:DatabaseException' => 'błąd bazy danych',
			str_starts_with($code, 'error:') => 'błąd aplikacji (' . substr($code, 6) . ')',
			default => $code,
		};
	}

	/**
	 * Wynik ostatniego udanego przeliczenia: data, czas, frazy i tematy (nowe, zmienione) — null przed pierwszym przeliczeniem.
	 *
	 * @param array<string, mixed> $state
	 */
	public static function summary(array $state): ?string
	{
		if ($state['refreshed_at'] === null) {
			return null;
		}

		$parts = ['Ostatnie przeliczenie: ' . Format::datetime($state['refreshed_at'])];

		if ($state['refresh_ms'] !== null) {
			$parts[] = Format::number($state['refresh_ms']) . ' ms';
		}

		if ($state['last_refresh']['selected'] !== null) {
			$selected = (int) $state['last_refresh']['selected'];
			$parts[] = Format::number($selected) . ' ' . Text::plural($selected, 'fraza', 'frazy', 'fraz');
		}

		$topics = $state['last_refresh']['topics'] ?? null;

		if (is_array($topics) && isset($topics['topics'])) {
			$parts[] = 'tematy: ' . Format::number((int) $topics['topics'])
				. ' (nowe ' . Format::number((int) ($topics['inserted'] ?? 0)) . ', zmienione ' . Format::number((int) ($topics['updated'] ?? 0)) . ')';
		}

		return implode(' · ', $parts);
	}

	/** Klasy paska stanu według fazy (tokeny Tailwind, bez hexów). */
	public static function tone(string $phase): string
	{
		return match ($phase) {
			'failed' => 'border-red-200 bg-red-50 text-red-800',
			'unsupported' => 'border-amber-200 bg-amber-50 text-amber-800',
			'pending', 'queued', 'retrying', 'running' => 'border-sky-200 bg-sky-50 text-sky-800',
			'done' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
			default => 'border-slate-200 bg-white text-slate-700',
		};
	}

	/**
	 * Dane odpytywania (endpoint statusu i stan początkowy komponentu Alpine `runProgress`).
	 *
	 * @param array<string, mixed> $state
	 * @return array{active: bool, phase: string, label: string, detail: string}
	 */
	public static function payload(array $state, bool $manage): array
	{
		$phase = (string) $state['job']['phase'];

		return [
			'active' => in_array($phase, self::ACTIVE, true),
			'phase' => $phase,
			'label' => self::label($phase),
			'detail' => self::detail($state, $manage),
		];
	}
}
