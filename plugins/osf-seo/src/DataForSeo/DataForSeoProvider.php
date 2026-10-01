<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use InvalidArgumentException;
use OsfSeo\Market\DifficultyBatch;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Market\VolumeBatch;
use OsfSeo\Market\VolumeMetrics;
use OsfSeo\Market\VolumeSubmission;

/**
 * DataForSEO jako dostawca metryk fraz (zatwierdzony płatny dostawca Wibble — ARCHITECTURE.md, D3):
 *
 * - wolumen, CPC, konkurencja Ads, historia miesięczna — Keywords Data API, Google Ads Search Volume,
 *   kolejka Standard (`task_post` → wynik później przez `task_get/{id}`); do 1000 fraz w zadaniu, cena za zadanie,
 * - trudność SEO — DataForSEO Labs, Google Bulk Keyword Difficulty (`live`; Labs nie ma trybu Standard);
 *   do 1000 fraz w żądaniu, cena za żądanie + za zwrócony element.
 */
final class DataForSeoProvider implements KeywordMetricsProvider
{
	public const NAME = 'dataforseo';

	public const VOLUME_POST = 'keywords_data/google_ads/search_volume/task_post';

	public const VOLUME_GET = 'keywords_data/google_ads/search_volume/task_get';

	public const DIFFICULTY_LIVE = 'dataforseo_labs/google/bulk_keyword_difficulty/live';

	/** Bezpłatna lista lokalizacji i języków DataForSEO Labs (weryfikacja kodów rynków). */
	public const LOCATIONS = 'dataforseo_labs/locations_and_languages';

	public const MAX_KEYWORDS_PER_TASK = 1000;

	private const COMPETITION_LEVELS = ['low', 'medium', 'high'];

	public function __construct(
		private readonly DataForSeoClient $client,
		private readonly DataForSeoConfig $config,
	) {
	}

	public function name(): string
	{
		return self::NAME;
	}

	public function label(): string
	{
		return 'DataForSEO';
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

	public function markets(): array
	{
		return DataForSeoMarkets::all();
	}

	public function acceptsKeyword(string $normalizedKeyword): bool
	{
		return KeywordRules::accepts($normalizedKeyword);
	}

	public function maxKeywordsPerTask(): int
	{
		return self::MAX_KEYWORDS_PER_TASK;
	}

	public function volumeEndpoint(): ProviderEndpoint
	{
		return new ProviderEndpoint('google_ads_search_volume', ProviderEndpoint::MODE_STANDARD, self::VOLUME_POST);
	}

	public function difficultyEndpoint(): ProviderEndpoint
	{
		return new ProviderEndpoint('labs_bulk_keyword_difficulty', ProviderEndpoint::MODE_LIVE, self::DIFFICULTY_LIVE);
	}

	public function estimateVolumeCost(int $keywords): float
	{
		return $keywords > 0 ? $this->config->priceVolumeTask() : 0.0;
	}

	public function estimateDifficultyCost(int $keywords): float
	{
		return $keywords > 0 ? $this->config->priceDifficultyRequest() + $keywords * $this->config->priceDifficultyItem() : 0.0;
	}

	public function submitVolume(Market $market, array $keywords): VolumeSubmission
	{
		$this->assertBatch($keywords);
		$task = self::singleTask($this->client->post(self::VOLUME_POST, [[
			'keywords' => array_values($keywords),
			'location_code' => $market->locationCode,
			'language_code' => $market->languageCode,
		]]));

		if ($task['status_code'] !== DataForSeoStatus::TASK_CREATED && $task['status_code'] !== DataForSeoStatus::OK) {
			throw self::taskError($task);
		}

		$id = $task['id'] ?? null;

		if (! is_string($id) || ! self::validTaskId($id)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO did not return a task id.', $task['status_code']);
		}

		return new VolumeSubmission($id, self::cost($task));
	}

	public function fetchVolume(string $taskId): ?VolumeBatch
	{
		if (! self::validTaskId($taskId)) {
			throw new InvalidArgumentException('Invalid DataForSEO task id.');
		}

		$task = self::singleTask($this->client->get(self::VOLUME_GET . '/' . $taskId));
		$code = $task['status_code'];

		if (DataForSeoStatus::isPending($code)) {
			return null;
		}

		if ($code === DataForSeoStatus::NO_RESULTS) {
			return new VolumeBatch([], self::cost($task));
		}

		if ($code !== DataForSeoStatus::OK) {
			throw self::taskError($task);
		}

		return new VolumeBatch(self::parseVolume($task['result'] ?? null), self::cost($task));
	}

	public function difficulty(Market $market, array $keywords): DifficultyBatch
	{
		$this->assertBatch($keywords);
		$task = self::singleTask($this->client->post(self::DIFFICULTY_LIVE, [[
			'keywords' => array_values($keywords),
			'location_code' => $market->locationCode,
			'language_code' => $market->languageCode,
		]]));
		$code = $task['status_code'];

		if ($code === DataForSeoStatus::NO_RESULTS) {
			return new DifficultyBatch([], self::cost($task));
		}

		if ($code !== DataForSeoStatus::OK) {
			throw self::taskError($task);
		}

		return new DifficultyBatch(self::parseDifficulty($task['result'] ?? null), self::cost($task));
	}

	/**
	 * Lista lokalizacji Labs (bezpłatna) — do weryfikacji kodów rynków.
	 *
	 * @return list<array{location_code: int, location_name: string, country_iso_code: ?string, languages: list<array{language_name: string, language_code: string}>}>
	 */
	public function locations(): array
	{
		$task = self::singleTask($this->client->get(self::LOCATIONS));

		if ($task['status_code'] !== DataForSeoStatus::OK) {
			throw self::taskError($task);
		}

		$locations = [];

		foreach (is_array($task['result'] ?? null) ? $task['result'] : [] as $row) {
			if (! is_array($row) || ! is_int($row['location_code'] ?? null) || ! is_string($row['location_name'] ?? null)) {
				continue;
			}

			$languages = [];

			foreach (is_array($row['available_languages'] ?? null) ? $row['available_languages'] : [] as $language) {
				if (is_array($language) && is_string($language['language_name'] ?? null) && is_string($language['language_code'] ?? null)) {
					$languages[] = ['language_name' => $language['language_name'], 'language_code' => $language['language_code']];
				}
			}

			$locations[] = [
				'location_code' => $row['location_code'],
				'location_name' => $row['location_name'],
				'country_iso_code' => is_string($row['country_iso_code'] ?? null) ? $row['country_iso_code'] : null,
				'languages' => $languages,
			];
		}

		return $locations;
	}

	/**
	 * Wyniki Google Ads Search Volume: wartości spoza typu → null (brak danych), element bez frazy jest pomijany.
	 *
	 * @return list<VolumeMetrics>
	 */
	public static function parseVolume(mixed $result): array
	{
		if ($result === null) {
			return [];
		}

		if (! is_array($result) || ! array_is_list($result)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO search volume result is not a list.');
		}

		$items = [];

		foreach ($result as $row) {
			if (! is_array($row) || ! is_string($row['keyword'] ?? null) || $row['keyword'] === '') {
				continue;
			}

			$level = is_string($row['competition'] ?? null) ? strtolower($row['competition']) : null;

			$items[] = new VolumeMetrics(
				keyword: $row['keyword'],
				searchVolume: self::nonNegativeInt($row['search_volume'] ?? null),
				cpc: self::nonNegativeFloat($row['cpc'] ?? null),
				competitionLevel: in_array($level, self::COMPETITION_LEVELS, true) ? $level : null,
				competitionIndex: self::bounded($row['competition_index'] ?? null, 0, 100),
				lowTopOfPageBid: self::nonNegativeFloat($row['low_top_of_page_bid'] ?? null),
				highTopOfPageBid: self::nonNegativeFloat($row['high_top_of_page_bid'] ?? null),
				monthly: self::parseMonthly($row['monthly_searches'] ?? null),
			);
		}

		return $items;
	}

	/**
	 * @return list<array{keyword: string, difficulty: ?int}>
	 */
	public static function parseDifficulty(mixed $result): array
	{
		if ($result === null) {
			return [];
		}

		if (! is_array($result) || ! array_is_list($result)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO keyword difficulty result is not a list.');
		}

		$items = [];

		foreach ($result as $block) {
			if (! is_array($block)) {
				continue;
			}

			foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $row) {
				if (is_array($row) && is_string($row['keyword'] ?? null) && $row['keyword'] !== '') {
					$items[] = ['keyword' => $row['keyword'], 'difficulty' => self::bounded($row['keyword_difficulty'] ?? null, 0, 100)];
				}
			}
		}

		return $items;
	}

	/**
	 * @return list<array{month: string, search_volume: ?int}>
	 */
	private static function parseMonthly(mixed $monthly): array
	{
		if (! is_array($monthly)) {
			return [];
		}

		$months = [];

		foreach ($monthly as $row) {
			$year = is_array($row) ? self::bounded($row['year'] ?? null, 2000, 2100) : null;
			$month = is_array($row) ? self::bounded($row['month'] ?? null, 1, 12) : null;

			if ($year !== null && $month !== null) {
				$months[sprintf('%04d-%02d-01', $year, $month)] = self::nonNegativeInt($row['search_volume'] ?? null);
			}
		}

		ksort($months);

		return array_map(
			static fn (string $date, ?int $volume): array => ['month' => $date, 'search_volume' => $volume],
			array_keys($months),
			array_values($months),
		);
	}

	/**
	 * Wysyłamy jedno zadanie na żądanie — odpowiedź musi zawierać dokładnie jedno zadanie ze statusem.
	 *
	 * @param array<string, mixed> $envelope
	 * @return array<string, mixed> zadanie z całkowitym `status_code`
	 */
	private static function singleTask(array $envelope): array
	{
		$tasks = $envelope['tasks'];
		$task = is_array($tasks) && count($tasks) === 1 ? reset($tasks) : null;

		if (! is_array($task) || ! is_int($task['status_code'] ?? null)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO response does not contain exactly one task.');
		}

		return $task;
	}

	/**
	 * @param array<string, mixed> $task
	 */
	private static function taskError(array $task): ProviderException
	{
		$code = (int) $task['status_code'];

		return new ProviderException(DataForSeoStatus::category($code, true), DataForSeoClient::message($code, $task['status_message'] ?? null), $code);
	}

	/**
	 * @param array<string, mixed> $task
	 */
	private static function cost(array $task): ?float
	{
		return self::nonNegativeFloat($task['cost'] ?? null);
	}

	/**
	 * @param list<string> $keywords
	 */
	private function assertBatch(array $keywords): void
	{
		if ($keywords === [] || count($keywords) > self::MAX_KEYWORDS_PER_TASK) {
			throw new InvalidArgumentException(sprintf('A DataForSEO batch must contain 1–%d keywords.', self::MAX_KEYWORDS_PER_TASK));
		}
	}

	private static function validTaskId(string $id): bool
	{
		return preg_match('/^[A-Za-z0-9\-]{8,64}$/', $id) === 1;
	}

	private static function nonNegativeInt(mixed $value): ?int
	{
		if (is_float($value) && floor($value) === $value) {
			$value = (int) $value;
		}

		return is_int($value) && $value >= 0 && $value <= 4294967295 ? $value : null;
	}

	private static function nonNegativeFloat(mixed $value): ?float
	{
		return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value < 100000000 ? (float) $value : null;
	}

	private static function bounded(mixed $value, int $min, int $max): ?int
	{
		if (is_float($value) && is_finite($value)) {
			$value = (int) round($value);
		}

		return is_int($value) && $value >= $min && $value <= $max ? $value : null;
	}
}
