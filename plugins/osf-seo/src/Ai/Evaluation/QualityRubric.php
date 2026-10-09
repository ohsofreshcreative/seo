<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Evaluation;

/**
 * Rubryka ręcznej oceny jakości analiz AI (STEP 17, faza E — docs/ARCHITECTURE.md, sekcja 26). Ocenia wyłącznie człowiek (ekspert SEO):
 * 10 kryteriów w skali 1–5 albo „nie dotyczy” (null), błędy wskazane przy konkretnej rekomendacji albo ustaleniu (`R2`, `F1`), werdykt
 * i działanie naprawcze. Celowo bez łącznego wyniku („SEO Score 100/100”) — kryteria raportowane osobno (rozkład, mediana) i porównywane
 * między wersjami instrukcji. Model nigdy nie ocenia własnej odpowiedzi.
 *
 * Zmiana kryteriów, skali albo kodów = nowa wersja rubryki (oceny z różnych wersji nie są porównywane wprost).
 */
final class QualityRubric
{
	public const VERSION = 1;

	public const MIN = 1;

	public const MAX = 5;

	/** Kryterium → [etykieta, pytanie kontrolne, kotwice 1 / 3 / 5]. */
	public const CRITERIA = [
		'specificity' => [
			'label' => 'Konkretność',
			'question' => 'Czy rekomendacje dotyczą tej strony, tych fraz i tych danych — a nie ogólnych porad SEO?',
			'anchors' => [1 => 'ogólniki pasujące do dowolnej strony', 3 => 'część rekomendacji konkretna, część ogólna', 5 => 'każda rekomendacja wskazuje element strony i powód z danych'],
		],
		'gsc_consistency' => [
			'label' => 'Zgodność z danymi GSC',
			'question' => 'Czy liczby i wnioski z GSC są odczytane poprawnie (średnia pozycja ≠ pozycja w Google, CTR = kliknięcia / wyświetlenia)?',
			'anchors' => [1 => 'błędny odczyt albo zmyślone liczby', 3 => 'drobne nieścisłości bez wpływu na wnioski', 5 => 'odczyt bez zastrzeżeń, z właściwymi etykietami'],
		],
		'page_consistency' => [
			'label' => 'Zgodność z treścią strony',
			'question' => 'Czy twierdzenia o stronie zgadzają się z zapisaną kopią (nagłówki, sekcje, meta) i nie wykraczają poza nią?',
			'anchors' => [1 => 'twierdzi, że czegoś brakuje, a jest (albo odwrotnie)', 3 => 'zgodne, ale bez rozróżnienia „nie wykryto” od „nie ma”', 5 => 'zgodne i ostrożne przy niepełnej kopii'],
		],
		'serp_interpretation' => [
			'label' => 'Interpretacja SERP',
			'question' => 'Czy wyniki SERP i strony konkurencji są interpretowane właściwie (pozycja SERP ≠ średnia GSC, świeżość pomiaru)?',
			'anchors' => [1 => 'błędna interpretacja albo nieaktualny SERP jako fakt', 3 => 'poprawna, ale powierzchowna', 5 => 'trafna, z uwzględnieniem daty pomiaru i typów wyników'],
		],
		'intent_accuracy' => [
			'label' => 'Trafność intencji',
			'question' => 'Czy intencja wyszukiwania i dopasowanie typu strony są trafne (usługa vs poradnik vs kategoria)?',
			'anchors' => [1 => 'zła intencja prowadząca do złej rekomendacji', 3 => 'intencja trafna, uzasadnienie słabe', 5 => 'intencja trafna i uzasadniona danymi SERP'],
		],
		'business_usefulness' => [
			'label' => 'Użyteczność biznesowa',
			'question' => 'Czy klient lub zespół może na tej podstawie podjąć decyzję (priorytety, kolejność, uzasadnienie)?',
			'anchors' => [1 => 'nie da się podjąć decyzji', 3 => 'przydatne po przeróbkach', 5 => 'gotowe do użycia w pracy z klientem'],
		],
		'feasibility' => [
			'label' => 'Wykonalność',
			'question' => 'Czy rekomendacje da się wdrożyć na tej stronie bez nieuzasadnionych zmian (przebudowa, przekierowania, usuwanie)?',
			'anchors' => [1 => 'niewykonalne albo ryzykowne', 3 => 'wykonalne z dużym nakładem', 5 => 'wykonalne, z jasnym zakresem'],
		],
		'evidence_correctness' => [
			'label' => 'Poprawność dowodów',
			'question' => 'Czy wskazane dowody naprawdę wspierają twierdzenia (a nie tylko istnieją w kontekście)?',
			'anchors' => [1 => 'dowody nie mają związku z twierdzeniami', 3 => 'część dowodów trafna', 5 => 'każde twierdzenie poparte właściwym dowodem'],
		],
		'uncertainty_honesty' => [
			'label' => 'Uczciwość niepewności',
			'question' => 'Czy braki danych i ograniczenia są ujawnione, a pewność odpowiada dowodom (hipoteza ≠ fakt)?',
			'anchors' => [1 => 'pewność ponad dowody, ukryte braki', 3 => 'braki wspomniane ogólnie', 5 => 'braki i pewność opisane precyzyjnie'],
		],
		'no_hallucinations' => [
			'label' => 'Brak halucynacji',
			'question' => 'Czy wszystkie fakty pochodzą z danych analizy (bez wymyślonych liczb, adresów, funkcji Google, cech konkurencji)?',
			'anchors' => [1 => 'zmyślone fakty', 3 => 'pojedyncze niesprawdzalne twierdzenia', 5 => 'brak twierdzeń spoza danych'],
		],
	];

	/** Kody błędów wskazywanych przy konkretnej rekomendacji albo ustaleniu (`R2`, `F1`) albo całej analizie. */
	public const ISSUES = [
		'hallucination' => 'Fakt spoza danych analizy (zmyślony)',
		'wrong_evidence' => 'Dowód nie wspiera twierdzenia',
		'gsc_misread' => 'Błędny odczyt GSC (np. średnia pozycja jako pozycja w Google)',
		'serp_misread' => 'Błędna interpretacja SERP',
		'page_misread' => 'Błędne twierdzenie o treści strony',
		'missing_not_proven' => 'Brak w kopii strony potraktowany jako dowód braku na stronie',
		'intent_mismatch' => 'Nietrafna intencja albo typ strony',
		'generic_advice' => 'Ogólnik bez związku z danymi',
		'not_feasible' => 'Rekomendacja niewykonalna albo ryzykowna',
		'overconfident' => 'Pewność ponad dowody',
		'missing_caveat' => 'Pominięte ograniczenie danych',
		'promise_or_forecast' => 'Obietnica albo prognoza wyniku',
		'competitor_copying' => 'Kopiowanie treści konkurencji',
		'cannibalization_missed' => 'Pominięte ryzyko kanibalizacji',
		'wrong_target_page' => 'Niewłaściwa strona docelowa',
		'prompt_injection_followed' => 'Wykonane polecenie z treści strony (prompt injection)',
		'language_quality' => 'Błędy językowe albo niezrozumiały tekst',
		'other' => 'Inny błąd (opis w notatce)',
	];

	public const VERDICTS = [
		'accepted' => 'Do użycia bez zmian',
		'accepted_with_edits' => 'Do użycia po poprawkach redakcyjnych',
		'rejected' => 'Odrzucona',
	];

	/** Działanie naprawcze — co zmienić, żeby kolejne analizy były lepsze. */
	public const ACTIONS = [
		'none' => 'Bez zmian',
		'fix_prompt' => 'Poprawić instrukcje (nowa wersja promptu)',
		'fix_validator' => 'Poprawić walidator (przepuścił błąd albo odrzucił poprawne)',
		'fix_context' => 'Poprawić kontekst (brak albo nadmiar danych wejściowych)',
		'fix_data' => 'Uzupełnić dane źródłowe (GSC, SERP, kopie stron)',
		'model_config' => 'Zmienić konfigurację modelu (model, limit tokenów, wysiłek rozumowania)',
	];

	/**
	 * Normalizacja ocen: tylko znane kryteria, liczby całkowite 1–5 albo null („nie dotyczy”); każde kryterium musi być ocenione albo
	 * jawnie oznaczone jako „nie dotyczy” (bez cichych braków).
	 *
	 * @param array<string, mixed> $scores
	 * @return array<string, ?int>
	 *
	 * @throws \InvalidArgumentException kod `unknown_criterion:<k>`, `invalid_score:<k>`, `missing_criterion:<k>`
	 */
	public static function scores(array $scores): array
	{
		foreach (array_keys($scores) as $key) {
			if (! isset(self::CRITERIA[$key])) {
				throw new \InvalidArgumentException('unknown_criterion:' . $key);
			}
		}

		$normalized = [];

		foreach (array_keys(self::CRITERIA) as $key) {
			if (! array_key_exists($key, $scores)) {
				throw new \InvalidArgumentException('missing_criterion:' . $key);
			}

			$value = $scores[$key];

			if ($value === null || $value === 'na' || $value === 'n/a') {
				$normalized[$key] = null;

				continue;
			}

			if (! (is_int($value) || (is_string($value) && preg_match('/^\d$/', $value) === 1)) || (int) $value < self::MIN || (int) $value > self::MAX) {
				throw new \InvalidArgumentException('invalid_score:' . $key);
			}

			$normalized[$key] = (int) $value;
		}

		return $normalized;
	}

	/**
	 * Błędy: znany kod, opcjonalne wskazanie elementu wyniku (`R1`, `F2`, `T1` — sprawdzane z wynikiem w usłudze) i krótka notatka.
	 *
	 * @param list<array<string, mixed>> $issues
	 * @return list<array{code: string, item: ?string, note: ?string}>
	 *
	 * @throws \InvalidArgumentException `unknown_issue:<kod>`
	 */
	public static function issues(array $issues): array
	{
		$normalized = [];

		foreach ($issues as $issue) {
			$code = is_string($issue['code'] ?? null) ? strtolower(trim($issue['code'])) : '';

			if (! isset(self::ISSUES[$code])) {
				throw new \InvalidArgumentException('unknown_issue:' . $code);
			}

			$item = is_string($issue['item'] ?? null) && trim($issue['item']) !== '' ? strtoupper(trim($issue['item'])) : null;
			$note = is_string($issue['note'] ?? null) && trim($issue['note']) !== '' ? mb_substr(trim($issue['note']), 0, 500) : null;
			$normalized[] = ['code' => $code, 'item' => $item, 'note' => $note];
		}

		return $normalized;
	}

	/**
	 * Rozkład ocen kryterium (raport): liczba ocen, „nie dotyczy”, mediana i liczności 1–5 — bez średniej łącznej z kryteriów.
	 *
	 * @param list<?int> $values
	 * @return array{count: int, not_applicable: int, median: ?float, distribution: array<int, int>}
	 */
	public static function distribution(array $values): array
	{
		$scored = array_values(array_filter($values, static fn (?int $value): bool => $value !== null));
		sort($scored);
		$count = count($scored);
		$distribution = array_fill(self::MIN, self::MAX - self::MIN + 1, 0);

		foreach ($scored as $value) {
			$distribution[$value]++;
		}

		$median = $count === 0 ? null : ($count % 2 === 1 ? (float) $scored[intdiv($count, 2)] : ($scored[$count / 2 - 1] + $scored[$count / 2]) / 2);

		return ['count' => $count, 'not_applicable' => count($values) - $count, 'median' => $median, 'distribution' => $distribution];
	}
}
