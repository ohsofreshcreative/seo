<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Wyjaśnienia i rekomendacje szans — deterministyczne reguły na podstawie zapisanych dowodów (bez AI).
 *
 * Teksty opisują objaw widoczny w GSC, a nie jego przyczynę: system nie analizuje HTML, title, meta description,
 * treści, linków ani wyników konkurencji — rekomendacje to hipotezy i kolejne kroki do ręcznego sprawdzenia.
 */
final class OpportunityExplainer
{
	/** Tytuł szansy: podstrona, fraza albo para adresów. */
	public static function title(array $evidence): string
	{
		$entity = $evidence['entity'] ?? [];

		return match ($entity['kind'] ?? '') {
			'pair' => implode(' ↔ ', array_map(Text::path(...), array_slice($entity['urls'] ?? [], 0, 2))),
			'page' => Text::path((string) $entity['page']),
			default => '„' . ($entity['keyword'] ?? '') . '”',
		};
	}

	/** Dlaczego szansa się pojawiła — jedno-dwa zdania z liczbami z dowodów. */
	public static function summary(OpportunityType $type, array $evidence): string
	{
		$current = Stats::fromArray($evidence['metrics']['current'] ?? null);
		$previous = Stats::fromArray($evidence['metrics']['previous'] ?? null);
		$details = $evidence['details'] ?? [];
		$count = (int) ($evidence['keywords_total'] ?? 0);
		$isPage = ($evidence['entity']['kind'] ?? '') === 'page';
		$subject = $isPage ? Text::keywords($count) . ' tej podstrony' : 'Fraza';

		return match ($type) {
			OpportunityType::LowCtr => sprintf(
				'%s: %s wyświetleń przy średniej pozycji (GSC) %s, ale CTR %s — referencyjny CTR dla tych pozycji to ok. %s. Przy referencyjnym CTR byłoby szacunkowo ok. %s kliknięć więcej.',
				$subject,
				Text::number($current->impressions),
				Text::position($current->position()),
				Text::percent($current->ctr(), 2),
				Text::percent(isset($details['expected_ctr']) ? (float) $details['expected_ctr'] : null, 2),
				Text::number((float) ($details['click_gap'] ?? 0)),
			),
			OpportunityType::NearTop => sprintf(
				'%s blisko TOP 3 i %s blisko TOP 10 (średnia pozycja (GSC)), łącznie %s wyświetleń. Szacunkowy potencjał przy pozycji docelowej: ok. %s kliknięć w okresie — bez gwarancji osiągnięcia.',
				Text::keywords((int) ($details['top3'] ?? 0)),
				Text::keywords((int) ($details['top10'] ?? 0)),
				Text::number($current->impressions),
				Text::number((float) ($details['potential_clicks'] ?? 0)),
			),
			OpportunityType::WeakPosition => sprintf(
				'%s z %s wyświetleniami, ale średnią pozycją (GSC) %s. Google już łączy stronę z tymi zapytaniami, choć wynik jest daleko od czołówki.',
				Text::keywords($count),
				Text::number($current->impressions),
				Text::position($current->position()),
			),
			OpportunityType::Decline => sprintf(
				'%s Kliknięcia %s → %s (%s), wyświetlenia %s → %s (%s), średnia pozycja (GSC) %s → %s. Okres %s vs %s.',
				! empty($details['page_signals']) ? 'Spadek całej podstrony (suma widocznych fraz).' : sprintf('Spadek dotyczy: %s.', Text::keywords((int) ($details['keywords_declining'] ?? $count))),
				Text::number($previous->clicks),
				Text::number($current->clicks),
				Text::relative($previous->clicks, $current->clicks),
				Text::number($previous->impressions),
				Text::number($current->impressions),
				Text::relative($previous->impressions, $current->impressions),
				Text::position($previous->position()),
				Text::position($current->position()),
				Text::range($evidence['period']['current'] ?? ['', '']),
				Text::range($evidence['period']['previous'] ?? ['', '']),
			),
			OpportunityType::Cannibalization => self::cannibalizationSummary($evidence, $count),
		};
	}

	/**
	 * Rekomendowane kolejne kroki (hipotezy do sprawdzenia, nie diagnoza).
	 *
	 * @return list<string>
	 */
	public static function recommendations(OpportunityType $type, array $evidence): array
	{
		$details = $evidence['details'] ?? [];
		$keywords = $evidence['keywords'] ?? [];
		$isPage = in_array($evidence['entity']['kind'] ?? '', ['page', 'pair'], true);

		switch ($type) {
			case OpportunityType::LowCtr:
				$steps = [
					'Sprawdź title i meta description' . ($isPage ? ' tej podstrony' : '') . ' dla głównych fraz — czy odpowiadają na zapytanie i zachęcają do kliknięcia.',
					'Zweryfikuj dopasowanie wyniku do intencji wyszukiwania tych fraz.',
					'Porównaj ręcznie swój wynik z wynikami konkurencji dla tych fraz.',
				];

				foreach ($keywords as $keyword) {
					$position = Stats::fromArray($keyword['current'] ?? null)->position();

					if ($position !== null && $position <= 3.5) {
						$steps[] = 'Przy wysokiej pozycji niski CTR bywa skutkiem elementów rozszerzonych w wynikach (np. odpowiedzi, mapy, reklamy) — sprawdź ręcznie wygląd wyników.';
						break;
					}
				}

				if ((float) ($evidence['score']['inputs']['ctr_drop'] ?? 0) > 0.2) {
					$steps[] = 'CTR spadł względem poprzedniego okresu — sprawdź, czy zmieniał się opis wyniku albo wygląd wyników wyszukiwania.';
				}

				return $steps;

			case OpportunityType::NearTop:
				$steps = [
					'Sprawdź możliwość rozszerzenia istniejącej treści o zagadnienia związane z tymi frazami.',
					'Zweryfikuj linkowanie wewnętrzne do tej podstrony (liczba linków, anchory zgodne z frazami).',
					'Sprawdź, czy fraza i jej intencja są odpowiednio pokryte (nagłówki, sekcje, odpowiedzi na pytania).',
				];

				if ((int) ($details['top3'] ?? 0) > 0 && (int) ($details['top10'] ?? 0) > 0) {
					$steps[] = 'Zacznij od fraz blisko TOP 3 z największą liczbą wyświetleń — tam przesunięcie o kilka pozycji ma największe znaczenie.';
				}

				if (! $isPage) {
					$steps[] = 'GSC nie wskazuje strony docelowej tej frazy — ustal, która podstrona powinna na nią odpowiadać.';
				}

				return $steps;

			case OpportunityType::WeakPosition:
				return array_values(array_filter([
					'Sprawdź, czy istnieje podstrona dedykowana tym zapytaniom — jeśli nie, rozważ jej przygotowanie.',
					'Zweryfikuj, czy treść odpowiada na intencję tych zapytań i jest wystarczająco rozbudowana.',
					'Wzmocnij linkowanie wewnętrzne do podstrony, która ma odpowiadać na te zapytania.',
					! $isPage ? 'GSC nie wskazuje strony docelowej tej frazy — ustal, która podstrona powinna na nią odpowiadać.' : null,
				]));

			case OpportunityType::Decline:
				$steps = [
					'Sprawdź, co zmieniło się na podstronie i w wynikach wyszukiwania w tym okresie (treść, title, przekierowania, indeksacja).',
					'Porównaj obecny okres z poprzednim dla najważniejszych fraz (lista poniżej).',
				];
				$steps[] = ! empty($details['page_signals']) || (int) ($details['keywords_declining'] ?? 0) >= 3
					? 'Spadek dotyczy wielu fraz — zweryfikuj zmiany na całej podstronie, a nie tylko pojedynczej frazy.'
					: 'Spadek dotyczy głównie jednej frazy — sprawdź ją w pierwszej kolejności, zanim zmienisz całą podstronę.';

				foreach ($keywords as $keyword) {
					if ((int) ($keyword['current']['impressions'] ?? 0) === 0) {
						$steps[] = 'Część fraz straciła całą widoczność — sprawdź indeksację adresu (np. narzędziem „Sprawdzanie adresu URL” w Search Console).';
						break;
					}
				}

				if (in_array('position', $details['signals'] ?? [], true)) {
					$steps[] = 'Średnia pozycja (GSC) pogorszyła się — sprawdź ręcznie, czy w wynikach pojawiły się nowe strony odpowiadające na te zapytania.';
				}

				return $steps;

			case OpportunityType::Cannibalization:
				$urls = array_map(Text::path(...), array_slice($evidence['entity']['urls'] ?? [], 0, 2));
				$steps = [
					sprintf('Porównaj intencję podstron %s dla tych fraz.', implode(' i ', $urls)),
					'Sprawdź, czy obie podstrony powinny pozostać osobne (różne intencje) — wtedy wiele adresów może być w porządku.',
					'Rozważ uporządkowanie linkowania wewnętrznego (jedna podstrona jako główna dla frazy) albo konsolidację treści.',
				];

				if ((int) ($details['dominant_changes'] ?? 0) > 0 || (float) ($details['switch_share'] ?? 0) > 0) {
					$steps[] = 'Dominujący adres zmieniał się w czasie — to silniejszy sygnał, że podstrony konkurują o tę samą intencję.';
				}

				return $steps;
		}

		return [];
	}

	/**
	 * Rozbicie priorytetu na składniki (z opisem słownym).
	 *
	 * @return list<array{label: string, points: float, max: int, text: string}>
	 */
	public static function scoreBreakdown(OpportunityType $type, array $evidence): array
	{
		$score = $evidence['score'] ?? [];
		$inputs = $score['inputs'] ?? [];
		$impressions = Text::number((int) ($inputs['impressions'] ?? 0));

		$size = match ($type) {
			OpportunityType::LowCtr => sprintf('luka ok. %s kliknięć, CTR to %s referencyjnego', Text::number((float) ($inputs['click_gap'] ?? 0)), Text::percent(isset($inputs['ctr_ratio']) ? (float) $inputs['ctr_ratio'] : null, 0)),
			OpportunityType::NearTop, OpportunityType::WeakPosition => sprintf('potencjał ok. %s kliknięć, bliskość celu %s', Text::number((float) ($inputs['potential_clicks'] ?? 0)), Text::percent((float) ($inputs['proximity'] ?? 0), 0)),
			OpportunityType::Decline => sprintf('utracone ok. %s kliknięć (%s bazy)', Text::number((float) ($inputs['clicks_lost'] ?? 0)), Text::percent((float) ($inputs['relative_loss'] ?? 0), 0)),
			OpportunityType::Cannibalization => sprintf('wyrównanie podziału wyświetleń %s, %s', Text::percent((float) ($inputs['balance'] ?? 0), 0), Text::keywords((int) ($inputs['queries'] ?? 0))),
		};

		$trend = match ($type) {
			OpportunityType::LowCtr => (float) ($inputs['ctr_drop'] ?? 0) > 0 ? sprintf('CTR niższy o %s niż w poprzednim okresie', Text::percent((float) $inputs['ctr_drop'], 0)) : 'CTR nie spadł względem poprzedniego okresu (lub brak porównania)',
			OpportunityType::NearTop, OpportunityType::WeakPosition => sprintf(
				'wyświetlenia %s, średnia pozycja (GSC) %s',
				isset($inputs['impressions_growth']) ? ((float) $inputs['impressions_growth'] >= 0 ? '+' : '−') . Text::percent(abs((float) $inputs['impressions_growth']), 0) : 'bez porównania',
				isset($inputs['position_improvement']) ? ((float) $inputs['position_improvement'] >= 0 ? 'lepsza o ' : 'gorsza o ') . Text::position(abs((float) $inputs['position_improvement'])) : 'bez porównania',
			),
			OpportunityType::Decline => isset($inputs['position_worsening']) ? sprintf('średnia pozycja (GSC) zmieniła się o %s (dodatnia = gorsza)', Text::position((float) $inputs['position_worsening'])) : 'bez porównania pozycji',
			OpportunityType::Cannibalization => sprintf('zmiana dominującego adresu w %s wyświetleń', Text::percent((float) ($inputs['switch_share'] ?? 0), 0)),
		};

		return [
			['label' => 'Popyt', 'points' => (float) ($score['demand'] ?? 0), 'max' => OpportunityScorer::DEMAND_MAX, 'text' => $impressions . ' wyświetleń w okresie (skala logarytmiczna z limitem)'],
			['label' => 'Skala', 'points' => (float) ($score['size'] ?? 0), 'max' => OpportunityScorer::SIZE_MAX, 'text' => $size],
			['label' => 'Trend', 'points' => (float) ($score['trend'] ?? 0), 'max' => OpportunityScorer::TREND_MAX, 'text' => $trend],
		];
	}

	/**
	 * Uzasadnienie pewności — co ją podnosi, a czego brakuje.
	 *
	 * @return list<array{ok: bool, text: string}>
	 */
	public static function confidenceReasons(array $evidence): array
	{
		$lines = [];

		foreach ($evidence['confidence']['factors'] ?? [] as $factor) {
			$ok = (int) ($factor['points'] ?? 0) > 0;

			$lines[] = match ($factor['code'] ?? '') {
				'sample' => ['ok' => $ok, 'text' => sprintf(
					'Próba: %s wyświetleń (%d/2 pkt; średnia od %s, wysoka od %s)',
					Text::number((int) $factor['value']),
					(int) $factor['points'],
					Text::number((int) $factor['medium']),
					Text::number((int) $factor['high']),
				)],
				'comparison' => ['ok' => $ok, 'text' => match ($factor['reason'] ?? '') {
					'ok' => 'Poprzedni okres jest dostępny do porównania',
					'not_covered' => 'Poprzedni okres nie jest jeszcze w pełni zaimportowany — brak porównania',
					default => 'Brak danych z poprzedniego okresu dla tych fraz',
				}],
				'consistency' => ['ok' => $ok, 'text' => self::consistencyText((string) ($factor['rule'] ?? ''), $ok)],
				default => ['ok' => $ok, 'text' => (string) ($factor['code'] ?? '')],
			};
		}

		return $lines;
	}

	private static function consistencyText(string $rule, bool $ok): string
	{
		return match ($rule) {
			'low_ctr_previous_period' => $ok ? 'Niski CTR widoczny także w poprzednim okresie' : 'Niski CTR nie potwierdza się w poprzednim okresie (albo brak danych)',
			'near_top_stable_band' => $ok ? 'Średnia pozycja stabilna — w poprzednim okresie także do 20' : 'Pozycja niestabilna albo brak danych z poprzedniego okresu',
			'weak_position_stable_band' => $ok ? 'Średnia pozycja stabilna — w poprzednim okresie także w zakresie 20–100' : 'Pozycja niestabilna albo brak danych z poprzedniego okresu',
			'decline_multiple_signals' => $ok ? 'Spadek widoczny w kilku metrykach albo kilku frazach' : 'Spadek tylko w jednej metryce jednej frazy',
			'cannibalization_persistent' => $ok ? 'Podział wyświetleń utrzymuje się (w poprzednim okresie albo w kolejnych tygodniach)' : 'Podział wyświetleń widoczny tylko w bieżącym okresie jako całości',
			default => $rule,
		};
	}

	private static function cannibalizationSummary(array $evidence, int $count): string
	{
		$urls = array_map(Text::path(...), array_slice($evidence['entity']['urls'] ?? [], 0, 2));
		$first = $evidence['keywords'][0] ?? null;
		$example = '';

		if (is_array($first)) {
			$shares = array_map(
				static fn (array $url): string => Text::percent((float) $url['share'], 0),
				array_slice(array_values(array_filter($first['urls'] ?? [], static fn (array $url): bool => (bool) ($url['meaningful'] ?? false))), 0, 3),
			);
			$example = sprintf(' Np. „%s”: udział wyświetleń %s.', (string) $first['keyword'], implode(' / ', $shares));
		}

		return sprintf(
			'%s, dla których adresy %s dzielą istotną część wyświetleń.%s Wiele adresów dla jednej frazy nie zawsze oznacza problem — to sygnał do sprawdzenia intencji.',
			Text::keywords($count),
			implode(' i ', $urls),
			$example,
		);
	}
}
