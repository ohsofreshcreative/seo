<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Elementy obecne na stronie wyników (`item_types` dostawcy) zapisane jako maska bitowa pomiaru — informacja, że np.
 * pojawiło się AI Overview albo „Ludzie pytają też”, bez przechowywania treści tych elementów (STEP 14 nie pobiera
 * ich płatnymi parametrami). Lista jest tylko dopisywana (pozycja = bit); nieznany typ trafia do `other`.
 */
final class ItemTypes
{
	public const TYPES = [
		'organic', 'paid', 'featured_snippet', 'answer_box', 'knowledge_graph', 'local_pack', 'map', 'people_also_ask',
		'related_searches', 'people_also_search', 'images', 'video', 'short_videos', 'top_stories', 'twitter', 'shopping',
		'popular_products', 'ai_overview', 'discussions_and_forums', 'perspectives', 'scholarly_articles', 'recipes', 'jobs',
		'events', 'hotels_pack', 'google_flights', 'google_reviews', 'third_party_reviews', 'carousel', 'multi_carousel', 'app',
		'find_results_on', 'questions_and_answers', 'stocks_box', 'commercial_units', 'local_services', 'google_hotels',
		'math_solver', 'currency_box', 'product_considerations', 'refine_products', 'compare_sites', 'top_sights', 'other',
	];

	private const LABELS = [
		'paid' => 'Reklamy',
		'featured_snippet' => 'Wyróżniony fragment',
		'answer_box' => 'Pole odpowiedzi',
		'knowledge_graph' => 'Panel wiedzy',
		'local_pack' => 'Wyniki lokalne',
		'map' => 'Mapa',
		'people_also_ask' => 'Ludzie pytają też',
		'images' => 'Grafika',
		'video' => 'Wideo',
		'short_videos' => 'Krótkie filmy',
		'top_stories' => 'Najważniejsze artykuły',
		'shopping' => 'Zakupy',
		'popular_products' => 'Popularne produkty',
		'ai_overview' => 'AI Overview',
		'discussions_and_forums' => 'Dyskusje i fora',
	];

	/**
	 * @param list<mixed> $types
	 */
	public static function mask(array $types): int
	{
		$mask = 0;

		foreach ($types as $type) {
			if (! is_string($type)) {
				continue;
			}

			$index = array_search($type, self::TYPES, true);
			$mask |= 1 << ($index === false ? count(self::TYPES) - 1 : (int) $index);
		}

		return $mask;
	}

	/**
	 * @return list<string>
	 */
	public static function fromMask(int $mask): array
	{
		$types = [];

		foreach (self::TYPES as $index => $type) {
			if (($mask & (1 << $index)) !== 0) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * Etykiety elementów wartych pokazania (bez wyników organicznych i rzadkich typów).
	 *
	 * @return list<string>
	 */
	public static function labels(int $mask): array
	{
		return array_values(array_filter(array_map(static fn (string $type): ?string => self::LABELS[$type] ?? null, self::fromMask($mask))));
	}
}
