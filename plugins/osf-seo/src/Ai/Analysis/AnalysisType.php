<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Analysis;

/**
 * Typy analiz AI (STEP 17, faza C — docs/ARCHITECTURE.md, sekcja 24). Typ zapisujemy w `ai_runs.task`; analiza tematu z fazy A
 * (`topic_analysis`, kontrakt v1) pozostaje bez zmian obok trzech analiz rekomendacji (kontrakt v2).
 */
final class AnalysisType
{
	public const TOPIC_ANALYSIS = 'topic_analysis';

	public const PAGE_OPTIMIZATION = 'page_optimization';

	public const NEW_PAGE_BRIEF = 'new_page_brief';

	public const CONTENT_GAP = 'content_gap';

	/** Analizy rekomendacji (faza C). */
	public const RECOMMENDATIONS = [self::PAGE_OPTIMIZATION, self::NEW_PAGE_BRIEF, self::CONTENT_GAP];

	public static function label(string $type): string
	{
		return match ($type) {
			self::PAGE_OPTIMIZATION => 'Optymalizacja istniejącej strony',
			self::NEW_PAGE_BRIEF => 'Brief nowej strony (kandydat)',
			self::CONTENT_GAP => 'Analiza luk treści względem konkurencji',
			self::TOPIC_ANALYSIS => 'Analiza tematu',
			default => $type,
		};
	}

	public static function fromInput(mixed $value): ?string
	{
		$value = is_string($value) ? strtolower(trim(str_replace('-', '_', $value))) : null;

		return in_array($value, self::RECOMMENDATIONS, true) ? $value : null;
	}
}
