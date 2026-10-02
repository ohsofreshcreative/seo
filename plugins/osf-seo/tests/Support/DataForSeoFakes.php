<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Support;

use OsfSeo\DataForSeo\DataForSeoConfig;

/**
 * Syntetyczne (losowe) dane logowania DataForSEO i odpowiedzi API do testów — nigdy prawdziwe.
 * Konfiguracja przez zmienne środowiskowe (jak w produkcji: stała lub env), sprzątana po teście.
 */
final class DataForSeoFakes
{
	public static function login(): string
	{
		return 'wibble-test-' . bin2hex(random_bytes(4)) . '@example.test';
	}

	public static function password(): string
	{
		return bin2hex(random_bytes(12));
	}

	/**
	 * @return array{0: string, 1: string} login, hasło
	 */
	public static function configure(): array
	{
		$credentials = [self::login(), self::password()];
		putenv(DataForSeoConfig::LOGIN . '=' . $credentials[0]);
		putenv(DataForSeoConfig::PASSWORD . '=' . $credentials[1]);

		return $credentials;
	}

	public static function clear(): void
	{
		foreach ([DataForSeoConfig::LOGIN, DataForSeoConfig::PASSWORD] as $name) {
			putenv($name);
		}
	}

	public static function taskId(): string
	{
		$hex = bin2hex(random_bytes(16));

		return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
	}

	/**
	 * Koperta odpowiedzi z jednym zadaniem.
	 *
	 * @param array<string, mixed> $task
	 * @return array<string, mixed>
	 */
	public static function envelope(array $task, int $code = 20000, float $cost = 0.0): array
	{
		$task += ['id' => self::taskId(), 'status_code' => 20000, 'status_message' => 'Ok.', 'time' => '0.1 sec.', 'cost' => $cost, 'result_count' => 1, 'path' => [], 'data' => [], 'result' => null];

		return [
			'version' => '0.1.20260901',
			'status_code' => $code,
			'status_message' => $code === 20000 ? 'Ok.' : 'Error.',
			'time' => '0.2 sec.',
			'cost' => $cost,
			'tasks_count' => 1,
			'tasks_error' => $task['status_code'] >= 40000 ? 1 : 0,
			'tasks' => [$task],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function taskCreated(string $taskId, float $cost = 0.06): array
	{
		return self::envelope(['id' => $taskId, 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => $cost, 'result_count' => 0], 20000, $cost);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function taskInQueue(string $taskId): array
	{
		return self::envelope(['id' => $taskId, 'status_code' => 40602, 'status_message' => 'Task In Queue.', 'result_count' => 0]);
	}

	/**
	 * Wynik Google Ads Search Volume.
	 *
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public static function volumeResult(string $taskId, array $items): array
	{
		return self::envelope(['id' => $taskId, 'result_count' => count($items), 'result' => $items]);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function volumeItem(string $keyword, ?int $volume, ?float $cpc = 1.25, ?string $competition = 'MEDIUM', ?int $index = 40, ?array $monthly = null): array
	{
		return [
			'keyword' => $keyword,
			'spell' => null,
			'location_code' => 2616,
			'language_code' => 'pl',
			'search_partners' => false,
			'competition' => $competition,
			'competition_index' => $index,
			'search_volume' => $volume,
			'low_top_of_page_bid' => $cpc === null ? null : round($cpc * 0.5, 2),
			'high_top_of_page_bid' => $cpc === null ? null : round($cpc * 2, 2),
			'cpc' => $cpc,
			'monthly_searches' => $monthly ?? ($volume === null ? null : [
				['year' => 2026, 'month' => 8, 'search_volume' => $volume],
				['year' => 2026, 'month' => 7, 'search_volume' => (int) round($volume * 0.8)],
			]),
		];
	}

	/**
	 * Wynik DataForSEO Labs Bulk Keyword Difficulty.
	 *
	 * @param array<string, ?int> $difficulties fraza → trudność
	 * @return array<string, mixed>
	 */
	public static function difficultyResult(array $difficulties, float $cost = 0.0): array
	{
		$items = [];

		foreach ($difficulties as $keyword => $difficulty) {
			$items[] = ['se_type' => 'google', 'keyword' => (string) $keyword, 'keyword_difficulty' => $difficulty];
		}

		return self::envelope([
			'result_count' => 1,
			'result' => [['se_type' => 'google', 'location_code' => 2616, 'language_code' => 'pl', 'total_count' => count($items), 'items_count' => count($items), 'items' => $items]],
		], 20000, $cost);
	}

	/**
	 * Dane frazy DataForSEO Labs (KeywordDataInfo): wolumen, CPC, konkurencja 0–1, historia, trudność SEO, intencja.
	 *
	 * @return array<string, mixed>
	 */
	public static function labsKeyword(string $keyword, ?int $volume, ?int $difficulty = 30, ?float $cpc = 2.5, ?float $competition = 0.42, ?string $intent = 'commercial', bool $anotherLanguage = false): array
	{
		return [
			'se_type' => 'google',
			'keyword' => $keyword,
			'location_code' => 2616,
			'language_code' => 'pl',
			'keyword_info' => [
				'se_type' => 'google',
				'last_updated_time' => '2026-09-14 03:12:44 +00:00',
				'competition' => $competition,
				'competition_level' => $competition === null ? null : ($competition >= 0.66 ? 'HIGH' : ($competition >= 0.33 ? 'MEDIUM' : 'LOW')),
				'cpc' => $cpc,
				'search_volume' => $volume,
				'low_top_of_page_bid' => $cpc === null ? null : round($cpc * 0.4, 2),
				'high_top_of_page_bid' => $cpc === null ? null : round($cpc * 1.8, 2),
				'categories' => [10021, 13418],
				'monthly_searches' => $volume === null ? null : [
					['year' => 2026, 'month' => 8, 'search_volume' => $volume],
					['year' => 2026, 'month' => 7, 'search_volume' => (int) round($volume * 0.9)],
				],
			],
			'keyword_properties' => [
				'se_type' => 'google',
				'core_keyword' => null,
				'synonym_clustering_algorithm' => 'text_processing',
				'keyword_difficulty' => $difficulty,
				'detected_language' => $anotherLanguage ? 'en' : 'pl',
				'is_another_language' => $anotherLanguage,
			],
			'serp_info' => null,
			'avg_backlinks_info' => null,
			'search_intent_info' => $intent === null ? null : ['se_type' => 'google', 'main_intent' => $intent, 'foreign_intent' => null, 'last_updated_time' => '2026-09-01 00:00:00 +00:00'],
		];
	}

	/**
	 * Wynik DataForSEO Labs Related Keywords: `items[].keyword_data` + głębokość.
	 *
	 * @param list<array{0: array<string, mixed>, 1: int}> $items [dane frazy, głębokość]
	 * @return array<string, mixed>
	 */
	public static function relatedResult(string $seed, array $items, ?array $seedData = null, ?int $total = null, float $cost = 0.0): array
	{
		return self::envelope([
			'result_count' => 1,
			'cost' => $cost,
			'result' => [[
				'se_type' => 'google',
				'seed_keyword' => $seed,
				'seed_keyword_data' => $seedData,
				'location_code' => 2616,
				'language_code' => 'pl',
				'total_count' => $total ?? count($items),
				'items_count' => count($items),
				'items' => array_map(static fn (array $item): array => [
					'se_type' => 'google',
					'keyword_data' => $item[0],
					'depth' => $item[1],
					'related_keywords' => [],
				], $items),
			]],
		], 20000, $cost);
	}

	/**
	 * Wynik DataForSEO Labs Keyword Suggestions: `items[]` = dane frazy.
	 *
	 * @param list<array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	public static function suggestionsResult(string $seed, array $items, ?array $seedData = null, ?int $total = null, float $cost = 0.0, int $offset = 0): array
	{
		return self::envelope([
			'result_count' => 1,
			'cost' => $cost,
			'result' => [[
				'se_type' => 'google',
				'seed_keyword' => $seed,
				'seed_keyword_data' => $seedData,
				'location_code' => 2616,
				'language_code' => 'pl',
				'total_count' => $total ?? count($items),
				'items_count' => count($items),
				'offset' => $offset,
				'offset_token' => bin2hex(random_bytes(8)),
				'items' => $items,
			]],
		], 20000, $cost);
	}

	/**
	 * Wynik organiczny Google Organic SERP (advanced).
	 *
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	public static function serpOrganic(int $rankGroup, string $domain, ?string $url = null, ?int $rankAbsolute = null, array $extra = []): array
	{
		return $extra + [
			'type' => 'organic',
			'rank_group' => $rankGroup,
			'rank_absolute' => $rankAbsolute ?? $rankGroup,
			'page' => (int) ceil($rankGroup / 10),
			'position' => 'left',
			'xpath' => '/html[1]/body[1]/div[' . $rankGroup . ']',
			'domain' => $domain,
			'title' => 'Tytuł ' . $domain . ' #' . $rankGroup,
			'description' => 'Opis wyniku ' . $rankGroup . ' z domeny ' . $domain,
			'url' => $url ?? 'https://' . $domain . '/strona-' . $rankGroup . '/',
			'breadcrumb' => 'https://' . $domain . ' › strona',
			'website_name' => ucfirst(explode('.', $domain)[0]),
			'checks' => null,
			'is_image' => false,
			'is_video' => false,
			'amp_version' => false,
			'rating' => null,
			'price' => null,
			'links' => null,
			'related_result' => null,
			'timestamp' => null,
		];
	}

	/**
	 * Pełne TOP N: domeny z listy na kolejnych pozycjach, reszta — syntetyczne domeny `wynik-{n}.example`.
	 *
	 * @param array<int, string> $domains pozycja (rank_group) → domena
	 * @return list<array<string, mixed>>
	 */
	public static function serpTop(array $domains, int $count = 100, int $absoluteOffset = 0): array
	{
		$items = [];

		for ($rank = 1; $rank <= $count; $rank++) {
			$domain = $domains[$rank] ?? 'wynik-' . $rank . '.example';
			$items[] = self::serpOrganic($rank, $domain, null, $rank + $absoluteOffset);
		}

		return $items;
	}

	/**
	 * Odpowiedź na zlecenie wielu zadań SERP (`task_post`): zadania utworzone z `tag` w `data`.
	 *
	 * @param list<string> $tags
	 * @param array<string, int> $errors tag → kod błędu zadania
	 * @return array<string, mixed>
	 */
	public static function serpTasksCreated(array $tags, float $costPerTask = 0.00465, array $errors = []): array
	{
		$tasks = [];

		foreach ($tags as $tag) {
			$code = $errors[$tag] ?? 20100;
			$tasks[] = [
				'id' => self::taskId(),
				'status_code' => $code,
				'status_message' => $code === 20100 ? 'Task Created.' : 'Invalid Field.',
				'time' => '0.01 sec.',
				'cost' => $code === 20100 ? $costPerTask : 0,
				'result_count' => 0,
				'path' => ['v3', 'serp', 'google', 'organic', 'task_post'],
				'data' => ['api' => 'serp', 'function' => 'task_post', 'se' => 'google', 'se_type' => 'organic', 'tag' => $tag],
				'result' => null,
			];
		}

		$total = array_sum(array_column($tasks, 'cost'));

		return [
			'version' => '0.1.20260901',
			'status_code' => 20000,
			'status_message' => 'Ok.',
			'time' => '0.2 sec.',
			'cost' => $total,
			'tasks_count' => count($tasks),
			'tasks_error' => count(array_filter($tasks, static fn (array $task): bool => $task['status_code'] >= 40000)),
			'tasks' => $tasks,
		];
	}

	/**
	 * Lista gotowych zadań (`tasks_ready`).
	 *
	 * @param list<array{0: string, 1: ?string}> $ready [id, tag]
	 * @return array<string, mixed>
	 */
	public static function serpTasksReady(array $ready): array
	{
		return self::envelope([
			'result_count' => count($ready),
			'result' => array_map(static fn (array $row): array => [
				'id' => $row[0],
				'se' => 'google',
				'se_type' => 'organic',
				'date_posted' => '2026-01-15 10:00:00 +00:00',
				'tag' => $row[1],
				'endpoint_regular' => '/v3/serp/google/organic/task_get/regular/' . $row[0],
				'endpoint_advanced' => '/v3/serp/google/organic/task_get/advanced/' . $row[0],
				'endpoint_html' => '/v3/serp/google/organic/task_get/html/' . $row[0],
			], $ready),
		]);
	}

	/**
	 * Wynik zadania Google Organic SERP (`task_get/advanced`).
	 *
	 * @param list<array<string, mixed>> $items
	 * @param list<string> $itemTypes
	 * @return array<string, mixed>
	 */
	public static function serpResult(string $taskId, string $keyword, array $items, string $datetime = '2026-01-15 10:05:00 +00:00', array $itemTypes = ['organic'], ?array $spell = null, float $cost = 0.00465): array
	{
		return self::envelope([
			'id' => $taskId,
			'cost' => $cost,
			'result_count' => 1,
			'data' => ['api' => 'serp', 'function' => 'task_get', 'se' => 'google', 'se_type' => 'organic', 'keyword' => $keyword, 'device' => 'desktop', 'os' => 'windows'],
			'result' => [[
				'keyword' => $keyword,
				'type' => 'organic',
				'se_domain' => 'google.pl',
				'location_code' => 2616,
				'language_code' => 'pl',
				'check_url' => 'https://www.google.pl/search?q=' . rawurlencode($keyword) . '&hl=pl&gl=PL',
				'datetime' => $datetime,
				'spell' => $spell,
				'refinement_chips' => null,
				'item_types' => $itemTypes,
				'se_results_count' => 1250000,
				'pages_count' => (int) max(1, ceil(count($items) / 10)),
				'items_count' => count($items),
				'items' => $items,
			]],
		]);
	}
}
