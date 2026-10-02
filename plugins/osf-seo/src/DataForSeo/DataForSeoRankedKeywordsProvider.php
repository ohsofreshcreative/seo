<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OsfSeo\Gap\CompetitorKeywordsProvider;
use OsfSeo\Gap\RankedKeyword;
use OsfSeo\Gap\RankedKeywordsBatch;
use OsfSeo\Gap\RankedKeywordsQuery;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;
use OsfSeo\Serp\DomainFamily;
use Throwable;

/**
 * DataForSEO Labs Ranked Keywords (`dataforseo_labs/google/ranked_keywords/live`) jako dostawca fraz domeny
 * (STEP 15, docs/ARCHITECTURE.md, sekcja 14.2):
 *
 * - tryb Live (Labs nie ma kolejki Standard), jedna domena na żądanie, `limit` ≤ 1000 i `offset` — jedyny mechanizm
 *   paginacji opisany w specyfikacji tego endpointu; import kończy się na 10 000 frazach domeny (dalej dostawca zaleca
 *   inny mechanizm, którego specyfikacja tego endpointu nie potwierdza — nie ryzykujemy pominięcia ani dublowania fraz),
 * - `item_types: ["organic"]` (domyślnie dostawca zwraca też wyniki płatne — płacilibyśmy za nie),
 *   `historical_serp_mode: live`, `ignore_synonyms: false`, bez `include_clickstream_data` (podwójna cena),
 * - zakres filtrowany po stronie dostawcy (`rank_group ≤ N`, wolumen ≥ M), stała kolejność: wolumen malejąco,
 *   pozycja rosnąco — przy przycięciu limitem zostają frazy o największym popycie,
 * - cena za żądanie + za każdy zwrócony element; odpowiedź zawiera wolumen, CPC, konkurencję Ads, KD i intencję.
 */
final class DataForSeoRankedKeywordsProvider implements CompetitorKeywordsProvider
{
	public const RANKED_KEYWORDS_LIVE = 'dataforseo_labs/google/ranked_keywords/live';

	public const MAX_ITEMS_PER_REQUEST = 1000;

	/** Granica bezpiecznej paginacji `offset` (strony do 9000 + 1000). */
	public const MAX_ROWS_PER_DOMAIN = 10000;

	/** Najgorsza pozycja w bazie Labs (TOP100). */
	public const MAX_RANK = 100;

	public function __construct(
		private readonly DataForSeoClient $client,
		private readonly DataForSeoConfig $config,
	) {
	}

	public function name(): string
	{
		return DataForSeoProvider::NAME;
	}

	public function label(): string
	{
		return 'DataForSEO Labs';
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
		return new ProviderEndpoint('labs_ranked_keywords', ProviderEndpoint::MODE_LIVE, self::RANKED_KEYWORDS_LIVE);
	}

	public function maxItemsPerRequest(): int
	{
		return self::MAX_ITEMS_PER_REQUEST;
	}

	public function maxRowsPerDomain(): int
	{
		return self::MAX_ROWS_PER_DOMAIN;
	}

	public function estimateCost(int $items): float
	{
		return round($this->config->priceGapRequest() + max(0, $items) * $this->config->priceGapItem(), 6);
	}

	public function rankedKeywords(Market $market, RankedKeywordsQuery $query): RankedKeywordsBatch
	{
		if (
			$query->limit < 1 || $query->limit > self::MAX_ITEMS_PER_REQUEST || $query->offset < 0
			|| $query->offset + $query->limit > self::MAX_ROWS_PER_DOMAIN || DomainFamily::normalize($query->domain) !== $query->domain
		) {
			throw new InvalidArgumentException('Invalid DataForSEO ranked keywords query.');
		}

		$task = DataForSeoResponse::singleTask($this->client->post($this->endpoint()->path, [self::requestBody($market, $query)]));
		$code = $task['status_code'];

		if ($code === DataForSeoStatus::NO_RESULTS) {
			return new RankedKeywordsBatch([], 0, 0, DataForSeoResponse::cost($task));
		}

		if ($code !== DataForSeoStatus::OK) {
			throw DataForSeoResponse::taskError($task);
		}

		return self::parse($task['result'] ?? null, $query->domain, $query->offset, DataForSeoResponse::cost($task));
	}

	/**
	 * Treść żądania (jedno zadanie).
	 *
	 * @return array<string, mixed>
	 */
	public static function requestBody(Market $market, RankedKeywordsQuery $query): array
	{
		$conditions = [['ranked_serp_element.serp_item.rank_group', '<=', max(1, min(self::MAX_RANK, $query->maxRank))]];

		if ($query->minVolume > 0) {
			$conditions[] = ['keyword_data.keyword_info.search_volume', '>=', $query->minVolume];
		}

		return [
			'target' => $query->domain,
			'location_code' => $market->locationCode,
			'language_code' => $market->languageCode,
			'item_types' => ['organic'],
			'historical_serp_mode' => 'live',
			'ignore_synonyms' => false,
			'load_rank_absolute' => false,
			'limit' => $query->limit,
			'offset' => $query->offset,
			'order_by' => ['keyword_data.keyword_info.search_volume,desc', 'ranked_serp_element.serp_item.rank_group,asc'],
			'filters' => count($conditions) === 1 ? $conditions[0] : [$conditions[0], 'and', $conditions[1]],
		];
	}

	/**
	 * Wynik: `total_count`, `items[]` = {`keyword_data`, `ranked_serp_element`}. Elementy nieorganiczne, z obcego hosta
	 * (spoza rodziny domeny) albo bez frazy są odrzucane; ta sama fraza na stronie — zostaje najlepszy wynik domeny.
	 */
	public static function parse(mixed $result, string $domain, int $offset = 0, ?float $cost = null): RankedKeywordsBatch
	{
		if ($result === null) {
			return new RankedKeywordsBatch([], 0, 0, $cost);
		}

		if (! is_array($result) || ! array_is_list($result)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO ranked keywords result is not a list.');
		}

		$block = $result[0] ?? null;

		if ($block === null) {
			return new RankedKeywordsBatch([], 0, 0, $cost);
		}

		if (! is_array($block)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO ranked keywords result block is invalid.');
		}

		$rows = $block['items'] ?? [];

		if ($rows !== null && (! is_array($rows) || ! array_is_list($rows))) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO ranked keywords items are not a list.');
		}

		$best = [];
		$skipped = [];
		$position = $offset;

		foreach ($rows ?? [] as $row) {
			$position++;
			[$item, $reason] = is_array($row) ? self::item($row, $domain, $position) : [null, 'invalid'];

			if ($item === null) {
				$skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

				continue;
			}

			$key = MarketKeyword::normalize($item->keyword->keyword);
			$current = $best[$key] ?? null;

			if ($current !== null) {
				$skipped['duplicate'] = ($skipped['duplicate'] ?? 0) + 1;

				if ($current->rankGroup <= $item->rankGroup) {
					continue;
				}
			}

			$best[$key] = $item;
		}

		$received = is_array($rows) ? count($rows) : 0;
		$total = DataForSeoResponse::nonNegativeInt($block['total_count'] ?? null) ?? ($offset + $received);

		return new RankedKeywordsBatch(array_values($best), $received, $total, $cost, $skipped);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{0: ?RankedKeyword, 1: string}
	 */
	private static function item(array $row, string $domain, int $position): array
	{
		$data = $row['keyword_data'] ?? null;
		$element = $row['ranked_serp_element'] ?? null;
		$serp = is_array($element) ? ($element['serp_item'] ?? null) : null;

		if (! is_array($data) || ! is_array($serp)) {
			return [null, 'invalid'];
		}

		if (($serp['type'] ?? null) !== 'organic') {
			return [null, 'not_organic'];
		}

		$rank = DataForSeoResponse::bounded($serp['rank_group'] ?? null, 1, self::MAX_RANK);

		if ($rank === null) {
			return [null, 'invalid_rank'];
		}

		$url = DataForSeoSerpProvider::url($serp['url'] ?? null);
		$host = is_string($serp['domain'] ?? null) ? DomainFamily::normalize($serp['domain']) : null;
		$host ??= $url === null ? null : DomainFamily::fromUrl($url);

		if ($host === null || ! DomainFamily::matches($host, $domain)) {
			return [null, 'foreign_host'];
		}

		$keyword = DataForSeoDiscoveryProvider::keyword($data, null, $position);

		if ($keyword === null || MarketKeyword::normalize($keyword->keyword) === '') {
			return [null, 'invalid_keyword'];
		}

		$etv = $serp['etv'] ?? null;

		return [new RankedKeyword(
			keyword: $keyword,
			rankGroup: $rank,
			rankAbsolute: DataForSeoResponse::bounded($serp['rank_absolute'] ?? null, 1, 65535),
			url: $url,
			host: $host,
			title: DataForSeoResponse::nonEmptyString($serp['title'] ?? null, 512),
			etv: (is_int($etv) || is_float($etv)) && $etv >= 0 ? round((float) $etv, 2) : null,
			serpUpdatedAt: self::datetime(is_array($element) ? ($element['last_updated_time'] ?? null) : null),
		), ''];
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
}
