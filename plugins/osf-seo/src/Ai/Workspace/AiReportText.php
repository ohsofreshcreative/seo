<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Workspace;

/**
 * Eksport tekstowy raportu (przyciski „Kopiuj …” i druk) — wyłącznie z raportu (`AiReport::build`) widocznego dla użytkownika: bez kosztów,
 * dostawcy, modelu, odcisków, kodów i surowej odpowiedzi modelu. Zwykły tekst (bez HTML) do wklejenia w dokumencie dla copywritera.
 */
final class AiReportText
{
	/**
	 * @param array<string, mixed> $report
	 * @param array{topic?: ?string, date?: ?string} $meta
	 */
	public static function summary(array $report, array $meta = []): string
	{
		$lines = [self::heading($report, $meta), ''];
		$lines[] = 'Podsumowanie';
		$lines[] = (string) $report['summary'];

		if (is_array($report['intent'] ?? null)) {
			$lines[] = '';
			$lines[] = 'Intencja wyszukiwania: ' . $report['intent']['label'] . self::claim($report['intent']);

			if ($report['intent']['explanation'] !== '') {
				$lines[] = $report['intent']['explanation'];
			}
		}

		if (is_array($report['top'] ?? null)) {
			$lines[] = '';
			$lines[] = 'Najważniejsza rekomendacja';
			$lines[] = $report['top']['title'];

			if ($report['top']['description'] !== '') {
				$lines[] = $report['top']['description'];
			}
		}

		return self::finish($lines, $report);
	}

	/**
	 * @param array<string, mixed> $report
	 * @param array{topic?: ?string, date?: ?string} $meta
	 */
	public static function recommendations(array $report, array $meta = []): string
	{
		$lines = [self::heading($report, $meta), '', 'Rekomendacje (od najważniejszej)'];

		foreach ((array) $report['recommendations'] as $index => $item) {
			$lines[] = '';
			$details = array_filter([$item['priority_label'], $item['urgency_label'] === null ? null : 'pilność: ' . mb_strtolower((string) $item['urgency_label'], 'UTF-8'), $item['basis_label'], $item['confidence_label']]);
			$lines[] = ($index + 1) . '. ' . $item['title'] . ($details === [] ? '' : ' (' . implode(', ', $details) . ')');

			foreach (['target' => 'Gdzie', 'description' => 'Co zrobić', 'rationale' => 'Dlaczego', 'verification' => 'Jak sprawdzić'] as $key => $label) {
				if (($item[$key] ?? '') !== '') {
					$lines[] = '   ' . $label . ': ' . $item[$key];
				}
			}

			if ($item['manual_check']) {
				$lines[] = '   Wymaga ręcznej weryfikacji przed wdrożeniem.';
			}

			if ($item['evidence'] !== []) {
				$lines[] = '   Dowody: ' . self::evidence($item['evidence']);
			}
		}

		if ($report['checks'] !== []) {
			$lines[] = '';
			$lines[] = 'Do ręcznego sprawdzenia';

			foreach ($report['checks'] as $check) {
				$lines[] = '- ' . $check['check'] . ($check['reason'] === '' ? '' : ' — ' . $check['reason']);
			}
		}

		return self::finish($lines, $report);
	}

	/**
	 * Brief dla copywritera: intencja, H1 i konspekt H2–H3, tytuł i opis meta, tematy, pytania, CTA, dane od klienta, linkowanie.
	 *
	 * @param array<string, mixed> $report
	 * @param array{topic?: ?string, date?: ?string} $meta
	 */
	public static function brief(array $report, array $meta = []): string
	{
		$lines = [self::heading($report, $meta)];

		if ($report['candidate']) {
			$lines[] = 'Kandydat na nową stronę — przed napisaniem sprawdź, czy witryna nie ma już strony na ten temat.';
		}

		if (is_array($report['intent'] ?? null)) {
			$lines[] = '';
			$lines[] = 'Intencja wyszukiwania: ' . $report['intent']['label'];
		}

		if (is_array($report['outline'] ?? null)) {
			$lines[] = '';
			$lines[] = 'Konspekt';

			if ($report['outline']['h1'] !== '') {
				$lines[] = 'H1: ' . $report['outline']['h1'];
			}

			foreach ($report['outline']['sections'] as $section) {
				$lines[] = ($section['level'] === 3 ? '    H3: ' : '  H2: ') . $section['heading'] . ($section['scope'] === '' ? '' : ' — ' . $section['scope']);
			}
		}

		$lists = [
			'Propozycje tytułu strony' => array_map(static fn (array $item): string => $item['text'] . ' (' . $item['length'] . ' znaków)', $report['titles']),
			'Propozycje opisu meta' => array_map(static fn (array $item): string => $item['text'] . ' (' . $item['length'] . ' znaków)', $report['metas']),
			'Tematy do omówienia' => array_map(static fn (array $topic): string => $topic['label'] . ' — ' . $topic['status_label'] . ($topic['note'] === '' ? '' : ' (' . $topic['note'] . ')'), $report['topics']),
			'Pytania użytkowników' => $report['questions'],
			'Wezwania do działania (CTA)' => $report['ctas'],
			'Dane potrzebne od klienta' => $report['client_data'],
			'Linkowanie wewnętrzne' => array_map(static fn (array $link): string => ($link['from_url'] === '' ? 'ta strona' : $link['from_url']) . ' → ' . ($link['to_url'] === '' ? 'ta strona' : $link['to_url']) . ($link['anchor'] === '' ? '' : ' (anchor: ' . $link['anchor'] . ')'), $report['links']),
		];

		foreach ($lists as $title => $items) {
			if ($items === []) {
				continue;
			}

			$lines[] = '';
			$lines[] = $title;

			foreach ($items as $item) {
				$lines[] = '- ' . $item;
			}
		}

		return self::finish($lines, $report);
	}

	/**
	 * @param array<string, mixed> $report
	 * @param array{topic?: ?string, date?: ?string} $meta
	 */
	private static function heading(array $report, array $meta): string
	{
		$parts = [(string) $report['type_label']];

		if (is_string($meta['topic'] ?? null) && $meta['topic'] !== '') {
			$parts[] = 'temat: ' . $meta['topic'];
		}

		if (is_string($meta['date'] ?? null) && $meta['date'] !== '') {
			$parts[] = $meta['date'];
		}

		return implode(' — ', $parts);
	}

	/**
	 * Wspólne zakończenie: ograniczenia danych i ostrzeżenia (raport nigdy nie gubi zastrzeżeń przy kopiowaniu).
	 *
	 * @param list<string> $lines
	 * @param array<string, mixed> $report
	 */
	private static function finish(array $lines, array $report): string
	{
		$notes = [...(array) $report['limitations'], ...(array) $report['warnings']];

		if ($notes !== []) {
			$lines[] = '';
			$lines[] = 'Ograniczenia i zastrzeżenia';

			foreach ($notes as $note) {
				$lines[] = '- ' . $note;
			}
		}

		$lines[] = '';
		$lines[] = 'Rekomendacje AI to hipotezy do sprawdzenia, a nie gwarancja wzrostu widoczności.';

		return implode("\n", $lines) . "\n";
	}

	/**
	 * @param array<string, mixed> $claim
	 */
	private static function claim(array $claim): string
	{
		$details = array_filter([$claim['basis_label'] ?? null, $claim['confidence_label'] ?? null]);

		return $details === [] ? '' : ' (' . implode(', ', $details) . ')';
	}

	/**
	 * @param list<array{label: string, url: ?string}> $evidence
	 */
	private static function evidence(array $evidence): string
	{
		return implode('; ', array_map(static fn (array $item): string => $item['label'] . ($item['url'] === null ? '' : ' (' . $item['url'] . ')'), $evidence));
	}
}
