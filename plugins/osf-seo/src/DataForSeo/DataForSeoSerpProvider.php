<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Serp\ItemTypes;
use OsfSeo\Serp\SerpContext;
use OsfSeo\Serp\SerpItem;
use OsfSeo\Serp\SerpKeywordRules;
use OsfSeo\Serp\SerpPage;
use OsfSeo\Serp\SerpProvider;
use OsfSeo\Serp\SerpReadyTask;
use OsfSeo\Serp\SerpSubmission;
use OsfSeo\Serp\SerpTaskRequest;
use Throwable;

/**
 * DataForSEO Google Organic SERP API jako dostawca pomiarów pozycji (STEP 14, docs/ARCHITECTURE.md, sekcja 13.2):
 *
 * - zlecenie: `serp/google/organic/task_post` (kolejka Standard, priorytet zwykły) — do 100 zadań w jednym POST,
 *   każde z `tag` = ULID pomiaru; `depth` + `max_crawl_pages` ograniczają pobranie do N stron, więc koszt maksymalny jest znany,
 * - gotowe zadania: `serp/google/organic/tasks_ready` (bezpłatnie, do 1000 na wywołanie),
 * - wynik: `serp/google/organic/task_get/advanced/{id}` (bezpłatnie, 30 dni) — wyniki organiczne i wyróżnione fragmenty.
 *
 * Cena: pierwsza strona (do 10 wyników) + 0,75 × cena za każdą kolejną (stałe `OSF_SEO_DATAFORSEO_PRICE_SERP_*`).
 * Płatnych parametrów (calculate_rectangles, AI Overview, kliknięcia „Ludzie pytają też”) nie wysyłamy.
 */
final class DataForSeoSerpProvider implements SerpProvider
{
	public const TASK_POST = 'serp/google/organic/task_post';

	public const TASKS_READY = 'serp/google/organic/tasks_ready';

	public const TASK_GET = 'serp/google/organic/task_get/advanced';

	public const ENDPOINT = 'google_organic_serp';

	public const MAX_TASKS_PER_POST = 100;

	private const STORED_TYPES = ['organic' => SerpItem::TYPE_ORGANIC, 'featured_snippet' => SerpItem::TYPE_FEATURED_SNIPPET];

	private const CHECK_FLAGS = [
		'is_featured_snippet' => SerpItem::FLAG_FEATURED,
		'amp_version' => SerpItem::FLAG_AMP,
		'is_image' => SerpItem::FLAG_IMAGE,
		'is_video' => SerpItem::FLAG_VIDEO,
		'is_web_story' => SerpItem::FLAG_WEB_STORY,
		'is_malicious' => SerpItem::FLAG_MALICIOUS,
		'is_highly_cited' => SerpItem::FLAG_HIGHLY_CITED,
	];

	public function __construct(
		private readonly DataForSeoClient $client,
		private readonly DataForSeoConfig $config,
	) {
	}

	public function name(): string
	{
		return DataForSeoProvider::NAME;
	}

	public function isConfigured(): bool
	{
		return $this->config->isConfigured();
	}

	public function configurationProblems(): array
	{
		return $this->config->missing();
	}

	public function resolveMarket(string $country, string $language): ?Market
	{
		return DataForSeoMarkets::resolve($country, $language);
	}

	public function endpoint(): ProviderEndpoint
	{
		return new ProviderEndpoint(self::ENDPOINT, ProviderEndpoint::MODE_STANDARD, self::TASK_POST);
	}

	public function maxTasksPerPost(): int
	{
		return self::MAX_TASKS_PER_POST;
	}

	public function estimateCost(SerpContext $context): float
	{
		return round($this->config->priceSerpPage() + ($context->pages() - 1) * $this->config->priceSerpNextPage(), 6);
	}

	public function submit(array $tasks): array
	{
		if ($tasks === [] || count($tasks) > self::MAX_TASKS_PER_POST) {
			throw new InvalidArgumentException('Invalid number of SERP tasks.');
		}

		$bodies = [];

		foreach ($tasks as $task) {
			if (! SerpKeywordRules::accepts($task->keyword)) {
				throw new InvalidArgumentException('SERP keyword is not allowed.');
			}

			$bodies[] = self::requestBody($task);
		}

		$envelope = $this->client->post(self::TASK_POST, $bodies);

		return self::parseSubmissions($envelope['tasks'] ?? null, $tasks);
	}

	/**
	 * Treść zadania. Bez `priority` (domyślnie zwykły), bez płatnych parametrów; `remove_from_url` usuwa parametr
	 * śledzący Google (`srsltid`), żeby ten sam adres był rozpoznawany między pomiarami.
	 *
	 * @return array<string, mixed>
	 */
	public static function requestBody(SerpTaskRequest $task): array
	{
		return [
			'keyword' => $task->keyword,
			'location_code' => $task->context->locationCode,
			'language_code' => $task->context->languageCode,
			'device' => $task->context->device->value,
			'os' => $task->context->os(),
			'depth' => $task->context->depth,
			'max_crawl_pages' => $task->context->pages(),
			'tag' => $task->tag,
			'remove_from_url' => ['srsltid'],
		];
	}

	/**
	 * Odpowiedź na zlecenie: zadanie dopasowane po `tag` (a gdy go brak — po kolejności). Zadanie bez odpowiedzi jest
	 * niepewne (`MalformedResponse`): mogło zostać utworzone — odzyskuje je lista gotowych zadań.
	 *
	 * @param list<SerpTaskRequest> $requests
	 * @return array<string, SerpSubmission>
	 */
	public static function parseSubmissions(mixed $tasks, array $requests): array
	{
		if (! is_array($tasks) || ! array_is_list($tasks)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO SERP response has no task list.');
		}

		$tags = array_map(static fn (SerpTaskRequest $request): string => $request->tag, $requests);
		$result = [];

		foreach ($tasks as $index => $task) {
			if (! is_array($task)) {
				continue;
			}

			$data = is_array($task['data'] ?? null) ? $task['data'] : [];
			$tag = is_string($data['tag'] ?? null) && in_array($data['tag'], $tags, true) ? $data['tag'] : ($tags[$index] ?? null);

			if ($tag === null || isset($result[$tag])) {
				continue;
			}

			$code = is_int($task['status_code'] ?? null) ? $task['status_code'] : 0;
			$cost = DataForSeoResponse::cost($task);
			$id = is_string($task['id'] ?? null) && self::validTaskId($task['id']) ? $task['id'] : null;

			$result[$tag] = $code === DataForSeoStatus::TASK_CREATED && $id !== null
				? new SerpSubmission($tag, $id, $cost)
				: new SerpSubmission($tag, null, $cost, DataForSeoStatus::category($code, true), $code, DataForSeoClient::message($code, $task['status_message'] ?? null));
		}

		foreach ($tags as $tag) {
			$result[$tag] ??= new SerpSubmission($tag, null, null, ProviderErrorCategory::MalformedResponse, null, 'DataForSEO SERP response has no task for the request.');
		}

		return $result;
	}

	public function readyTasks(): array
	{
		$task = DataForSeoResponse::singleTask($this->client->get(self::TASKS_READY));

		if ($task['status_code'] !== DataForSeoStatus::OK) {
			throw DataForSeoResponse::taskError($task);
		}

		$ready = [];

		foreach (is_array($task['result'] ?? null) ? $task['result'] : [] as $row) {
			if (is_array($row) && is_string($row['id'] ?? null) && self::validTaskId($row['id'])) {
				$ready[] = new SerpReadyTask($row['id'], is_string($row['tag'] ?? null) ? $row['tag'] : null);
			}
		}

		return $ready;
	}

	public function fetch(string $taskId): ?SerpPage
	{
		if (! self::validTaskId($taskId)) {
			throw new InvalidArgumentException('Invalid DataForSEO task id.');
		}

		$task = DataForSeoResponse::singleTask($this->client->get(self::TASK_GET . '/' . $taskId));
		$code = $task['status_code'];

		if (DataForSeoStatus::isPending($code)) {
			return null;
		}

		if ($code === DataForSeoStatus::NO_RESULTS) {
			return new SerpPage([]);
		}

		if ($code !== DataForSeoStatus::OK) {
			throw DataForSeoResponse::taskError($task);
		}

		return self::parse($task['result'] ?? null);
	}

	public static function validTaskId(string $id): bool
	{
		return preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{7,63}$/', $id) === 1;
	}

	/**
	 * Wynik zadania (advanced): metadane strony i zapisywane elementy (wyniki organiczne i wyróżnione fragmenty)
	 * w kolejności dostawcy. Element bez poprawnego adresu, domeny lub pozycji jest pomijany (liczony w `skipped`).
	 */
	public static function parse(mixed $result): SerpPage
	{
		if ($result === null) {
			return new SerpPage([]);
		}

		if (! is_array($result) || ! array_is_list($result)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO SERP result is not a list.');
		}

		$block = $result[0] ?? null;

		if ($block === null) {
			return new SerpPage([]);
		}

		if (! is_array($block) || (isset($block['items']) && ! is_array($block['items']))) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO SERP result block is invalid.');
		}

		$items = [];
		$skipped = 0;

		foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $row) {
			$type = is_array($row) && is_string($row['type'] ?? null) ? (self::STORED_TYPES[$row['type']] ?? null) : null;

			if ($type === null) {
				continue;
			}

			$item = self::item($row, $type);

			if ($item === null) {
				$skipped++;

				continue;
			}

			$items[] = $item;
		}

		usort($items, static fn (SerpItem $a, SerpItem $b): int => [$a->rankAbsolute, $a->type] <=> [$b->rankAbsolute, $b->type]);
		$spell = is_array($block['spell'] ?? null) ? $block['spell'] : [];

		return new SerpPage(
			$items,
			self::datetime($block['datetime'] ?? null),
			self::ascii($block['se_domain'] ?? null, 64),
			DataForSeoResponse::nonNegativeInt($block['se_results_count'] ?? null),
			DataForSeoResponse::bounded($block['pages_count'] ?? null, 0, 255),
			DataForSeoResponse::bounded($block['items_count'] ?? null, 0, 65535),
			ItemTypes::mask(is_array($block['item_types'] ?? null) ? array_values($block['item_types']) : []),
			self::ascii($spell['type'] ?? null, 32),
			DataForSeoResponse::nonEmptyString($spell['keyword'] ?? null, 255),
			$skipped,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private static function item(array $row, int $type): ?SerpItem
	{
		$rankGroup = DataForSeoResponse::bounded($row['rank_group'] ?? null, 1, 65535);
		$rankAbsolute = DataForSeoResponse::bounded($row['rank_absolute'] ?? null, 1, 65535);
		$url = self::url($row['url'] ?? null);

		if ($rankGroup === null || $rankAbsolute === null || $url === null) {
			return null;
		}

		$host = (is_string($row['domain'] ?? null) ? DomainFamily::normalize($row['domain']) : null) ?? DomainFamily::fromUrl($url);

		if ($host === null) {
			return null;
		}

		$flags = 0;

		foreach (is_array($row['checks'] ?? null) ? $row['checks'] : [] as $check) {
			$flags |= is_string($check) ? (self::CHECK_FLAGS[$check] ?? 0) : 0;
		}

		foreach (self::CHECK_FLAGS as $field => $flag) {
			$flags |= ($row[$field] ?? null) === true ? $flag : 0;
		}

		$extra = array_filter([
			'pre_snippet' => DataForSeoResponse::nonEmptyString($row['pre_snippet'] ?? null, 500),
			'extended_snippet' => DataForSeoResponse::nonEmptyString($row['extended_snippet'] ?? null, 1000),
			'featured_title' => DataForSeoResponse::nonEmptyString($row['featured_title'] ?? null, 512),
			'highlighted' => self::strings($row['highlighted'] ?? null, 20, 100),
			'rating' => self::rating($row['rating'] ?? null),
			'price' => self::price($row['price'] ?? null),
			'links' => self::links($row['links'] ?? null),
			'related_results' => is_array($row['related_result'] ?? null) && $row['related_result'] !== [] ? count($row['related_result']) : null,
			'published_at' => self::datetime($row['timestamp'] ?? null),
		], static fn (mixed $value): bool => $value !== null && $value !== []);

		$flags |= isset($extra['rating']) ? SerpItem::FLAG_RATING : 0;
		$flags |= isset($extra['price']) ? SerpItem::FLAG_PRICE : 0;
		$flags |= isset($extra['links']) ? SerpItem::FLAG_SITELINKS : 0;
		$flags |= isset($extra['related_results']) ? SerpItem::FLAG_RELATED : 0;

		return new SerpItem(
			$type,
			$rankGroup,
			$rankAbsolute,
			DataForSeoResponse::bounded($row['page'] ?? null, 1, 255),
			$host,
			$url,
			DataForSeoResponse::nonEmptyString($row['title'] ?? null, 512),
			DataForSeoResponse::nonEmptyString($row['description'] ?? null, 4000),
			DataForSeoResponse::nonEmptyString($row['breadcrumb'] ?? null, 512),
			DataForSeoResponse::nonEmptyString($row['website_name'] ?? null, 255),
			$flags,
			$extra,
		);
	}

	/** Adres wyniku: tylko http(s), bez fragmentu, najwyżej 2048 znaków (dłuższy jest pomijany — nie skracamy tożsamości). */
	public static function url(mixed $value): ?string
	{
		if (! is_string($value)) {
			return null;
		}

		// Bez fragmentu i bez parametru śledzącego Google `srsltid` (na wypadek, gdyby `remove_from_url` go nie usunął) —
		// ten sam adres ma być rozpoznawany między pomiarami.
		$url = trim((string) preg_replace('/#.*$/s', '', $value));
		$url = (string) preg_replace(['/([?&])srsltid=[^&]*(&|$)/', '/[?&]$/'], ['$1', ''], $url);

		if ($url === '' || strlen($url) > 2048 || preg_match('#^https?://#i', $url) !== 1 || preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
			return null;
		}

		return $url;
	}

	/** Data dostawcy „RRRR-MM-DD GG:MM:SS +00:00” → UTC „RRRR-MM-DD GG:MM:SS”. */
	private static function datetime(mixed $value): ?string
	{
		if (! is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable(trim($value)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		} catch (Throwable) {
			return null;
		}
	}

	private static function ascii(mixed $value, int $maxLength): ?string
	{
		return is_string($value) && preg_match('/^[\x21-\x7E]{1,' . $maxLength . '}$/', $value) === 1 ? $value : null;
	}

	/**
	 * @return list<string>|null
	 */
	private static function strings(mixed $value, int $max, int $maxLength): ?array
	{
		if (! is_array($value)) {
			return null;
		}

		$strings = array_values(array_filter(array_map(static fn (mixed $item): ?string => DataForSeoResponse::nonEmptyString($item, $maxLength), $value)));

		return $strings === [] ? null : array_slice($strings, 0, $max);
	}

	/**
	 * @return array<string, float|int>|null
	 */
	private static function rating(mixed $value): ?array
	{
		if (! is_array($value)) {
			return null;
		}

		$rating = array_filter([
			'value' => DataForSeoResponse::nonNegativeFloat($value['value'] ?? null),
			'votes' => DataForSeoResponse::nonNegativeInt($value['votes_count'] ?? null),
			'max' => DataForSeoResponse::nonNegativeFloat($value['rating_max'] ?? null),
		], static fn (mixed $item): bool => $item !== null);

		return isset($rating['value']) ? $rating : null;
	}

	/**
	 * @return array<string, float|string>|null
	 */
	private static function price(mixed $value): ?array
	{
		if (! is_array($value)) {
			return null;
		}

		$price = array_filter([
			'current' => DataForSeoResponse::nonNegativeFloat($value['current'] ?? null),
			'currency' => self::ascii($value['currency'] ?? null, 8),
		], static fn (mixed $item): bool => $item !== null);

		return isset($price['current']) ? $price : null;
	}

	/**
	 * Linki wyniku (sitelinki): tytuł i adres, najwyżej 10.
	 *
	 * @return list<array{title: ?string, url: string}>|null
	 */
	private static function links(mixed $value): ?array
	{
		if (! is_array($value)) {
			return null;
		}

		$links = [];

		foreach ($value as $link) {
			$url = is_array($link) ? self::url($link['url'] ?? null) : null;

			if ($url !== null) {
				$links[] = ['title' => DataForSeoResponse::nonEmptyString($link['title'] ?? null, 200), 'url' => $url];
			}

			if (count($links) >= 10) {
				break;
			}
		}

		return $links === [] ? null : $links;
	}
}
