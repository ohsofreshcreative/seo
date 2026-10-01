<?php

declare(strict_types=1);

namespace OsfSeo\DataForSeo;

use InvalidArgumentException;
use OsfSeo\Discovery\DiscoveredKeyword;
use OsfSeo\Discovery\DiscoveryBatch;
use OsfSeo\Discovery\DiscoveryMethod;
use OsfSeo\Discovery\DiscoveryQuery;
use OsfSeo\Discovery\KeywordDiscoveryProvider;
use OsfSeo\Market\Market;
use OsfSeo\Market\ProviderEndpoint;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Market\ProviderException;

/**
 * DataForSEO Labs jako dostawca wyszukiwania nowych fraz (STEP 13, docs/ARCHITECTURE.md, sekcja 12.2):
 *
 * - Related Keywords (`dataforseo_labs/google/related_keywords/live`) — frazy z „Podobne wyszukiwania” w głąb od seeda
 *   (`depth` 1–4: maks. ok. 8 / 72 / 584 / 4680 fraz),
 * - Keyword Suggestions (`dataforseo_labs/google/keyword_suggestions/live`) — frazy zawierające seed.
 *
 * Oba: tryb Live (Labs nie ma Standard), jeden seed na żądanie, `limit` ≤ 1000 i `offset` (paginacja), cena za żądanie
 * + za każdy zwrócony element. Odpowiedź zawiera wolumen, CPC, konkurencję Ads, historię, trudność SEO i intencję —
 * kandydaci nie wymagają osobnych płatnych żądań wzbogacających. `include_clickstream_data` (podwójna cena) nie jest
 * wysyłane nigdy, `include_serp_info` — wyłączone (SERP to kolejny etap).
 */
final class DataForSeoDiscoveryProvider implements KeywordDiscoveryProvider
{
	public const RELATED_LIVE = 'dataforseo_labs/google/related_keywords/live';

	public const SUGGESTIONS_LIVE = 'dataforseo_labs/google/keyword_suggestions/live';

	public const MAX_ITEMS_PER_REQUEST = 1000;

	/** Szacowana maksymalna liczba fraz Related Keywords dla głębokości (dokumentacja DataForSEO). */
	public const RELATED_DEPTH_RESULTS = [0 => 1, 1 => 8, 2 => 72, 3 => 584, 4 => 4680];

	private const COMPETITION_LEVELS = ['low', 'medium', 'high'];

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

	public function acceptsKeyword(string $normalizedKeyword): bool
	{
		return KeywordRules::accepts($normalizedKeyword);
	}

	public function methods(): array
	{
		return [DiscoveryMethod::Related, DiscoveryMethod::Suggestions];
	}

	public function endpoint(DiscoveryMethod $method): ProviderEndpoint
	{
		return match ($method) {
			DiscoveryMethod::Related => new ProviderEndpoint('labs_related_keywords', ProviderEndpoint::MODE_LIVE, self::RELATED_LIVE),
			DiscoveryMethod::Suggestions => new ProviderEndpoint('labs_keyword_suggestions', ProviderEndpoint::MODE_LIVE, self::SUGGESTIONS_LIVE),
		};
	}

	public function maxItemsPerRequest(): int
	{
		return self::MAX_ITEMS_PER_REQUEST;
	}

	public function maxResults(DiscoveryMethod $method, ?int $depth): ?int
	{
		return $method === DiscoveryMethod::Related ? self::RELATED_DEPTH_RESULTS[max(0, min(4, $depth ?? 1))] : null;
	}

	/** Żądanie + elementy; dane seeda liczone ostrożnie jako dodatkowy element. */
	public function estimateCost(int $items): float
	{
		return round($this->config->priceDiscoveryRequest() + (max(0, $items) + 1) * $this->config->priceDiscoveryItem(), 6);
	}

	public function discover(Market $market, DiscoveryQuery $query): DiscoveryBatch
	{
		if ($query->limit < 1 || $query->limit > self::MAX_ITEMS_PER_REQUEST || $query->offset < 0 || trim($query->seed) === '') {
			throw new InvalidArgumentException('Invalid DataForSEO discovery query.');
		}

		$related = $query->method === DiscoveryMethod::Related;
		$task = DataForSeoResponse::singleTask($this->client->post($this->endpoint($query->method)->path, [self::requestBody($market, $query)]));
		$code = $task['status_code'];

		if ($code === DataForSeoStatus::NO_RESULTS) {
			return new DiscoveryBatch([], null, 0, DataForSeoResponse::cost($task));
		}

		if ($code !== DataForSeoStatus::OK) {
			throw DataForSeoResponse::taskError($task);
		}

		return self::parse($task['result'] ?? null, $related, $query->offset, DataForSeoResponse::cost($task));
	}

	/**
	 * Treść żądania (jedno zadanie). Filtry po stronie dostawcy — płacimy tylko za zwrócone elementy.
	 *
	 * @return array<string, mixed>
	 */
	public static function requestBody(Market $market, DiscoveryQuery $query): array
	{
		$related = $query->method === DiscoveryMethod::Related;
		$prefix = $related ? 'keyword_data.' : '';
		$conditions = [];

		if ($query->minVolume > 0) {
			$conditions[] = [$prefix . 'keyword_info.search_volume', '>=', $query->minVolume];
		}

		if ($query->maxDifficulty !== null && $query->maxDifficulty < 100) {
			$conditions[] = [$prefix . 'keyword_properties.keyword_difficulty', '<=', max(0, $query->maxDifficulty)];
		}

		$body = [
			'keyword' => $query->seed,
			'location_code' => $market->locationCode,
			'language_code' => $market->languageCode,
			'include_seed_keyword' => $query->includeSeed && $query->offset === 0,
			'include_serp_info' => false,
			'ignore_synonyms' => false,
			'limit' => $query->limit,
			'offset' => $query->offset,
			'order_by' => [$prefix . 'keyword_info.search_volume,desc'],
		];

		if ($related) {
			$body['depth'] = max(1, min(4, $query->depth ?? 2));
		} else {
			$body['exact_match'] = false;
		}

		if ($conditions !== []) {
			$body['filters'] = count($conditions) === 1 ? $conditions[0] : [$conditions[0], 'and', $conditions[1]];
		}

		return $body;
	}

	/**
	 * Wynik Related Keywords (`items[].keyword_data` + `depth`) albo Keyword Suggestions (`items[]` = dane frazy).
	 */
	public static function parse(mixed $result, bool $related, int $offset = 0, ?float $cost = null): DiscoveryBatch
	{
		if ($result === null) {
			return new DiscoveryBatch([], null, 0, $cost);
		}

		if (! is_array($result) || ! array_is_list($result)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO discovery result is not a list.');
		}

		$block = $result[0] ?? null;

		if ($block === null) {
			return new DiscoveryBatch([], null, 0, $cost);
		}

		if (! is_array($block)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO discovery result block is invalid.');
		}

		$rows = $block['items'] ?? [];

		if ($rows !== null && ! is_array($rows)) {
			throw new ProviderException(ProviderErrorCategory::MalformedResponse, 'DataForSEO discovery items are not a list.');
		}

		$items = [];
		$position = $offset;

		foreach ($rows ?? [] as $row) {
			$position++;

			if (! is_array($row)) {
				continue;
			}

			$data = $related ? ($row['keyword_data'] ?? null) : $row;
			$depth = $related ? DataForSeoResponse::bounded($row['depth'] ?? null, 0, 4) : null;
			$keyword = is_array($data) ? self::keyword($data, $depth, $position) : null;

			if ($keyword !== null) {
				$items[] = $keyword;
			}
		}

		$seedData = $block['seed_keyword_data'] ?? null;
		$seed = is_array($seedData) ? self::keyword($seedData, 0, 0) : null;
		$total = DataForSeoResponse::nonNegativeInt($block['total_count'] ?? null) ?? count($items);

		return new DiscoveryBatch($items, $seed, $total, $cost);
	}

	/**
	 * @param array<string, mixed> $data obiekt danych frazy (KeywordDataInfo)
	 */
	private static function keyword(array $data, ?int $depth, int $position): ?DiscoveredKeyword
	{
		$keyword = DataForSeoResponse::nonEmptyString($data['keyword'] ?? null);

		if ($keyword === null) {
			return null;
		}

		$info = is_array($data['keyword_info'] ?? null) ? $data['keyword_info'] : [];
		$properties = is_array($data['keyword_properties'] ?? null) ? $data['keyword_properties'] : [];
		$intent = is_array($data['search_intent_info'] ?? null) ? ($data['search_intent_info']['main_intent'] ?? null) : null;
		$level = is_string($info['competition_level'] ?? null) ? strtolower($info['competition_level']) : null;
		$competition = $info['competition'] ?? null;
		$index = (is_int($competition) || is_float($competition)) && $competition >= 0 && $competition <= 1 ? (int) round($competition * 100) : null;
		$another = $properties['is_another_language'] ?? null;

		return new DiscoveredKeyword(
			keyword: $keyword,
			searchVolume: DataForSeoResponse::nonNegativeInt($info['search_volume'] ?? null),
			cpc: DataForSeoResponse::nonNegativeFloat($info['cpc'] ?? null),
			competitionLevel: in_array($level, self::COMPETITION_LEVELS, true) ? $level : null,
			competitionIndex: $index,
			lowTopOfPageBid: DataForSeoResponse::nonNegativeFloat($info['low_top_of_page_bid'] ?? null),
			highTopOfPageBid: DataForSeoResponse::nonNegativeFloat($info['high_top_of_page_bid'] ?? null),
			monthly: DataForSeoResponse::monthly($info['monthly_searches'] ?? null),
			keywordDifficulty: DataForSeoResponse::bounded($properties['keyword_difficulty'] ?? null, 0, 100),
			intent: is_string($intent) && in_array(strtolower($intent), DiscoveredKeyword::INTENTS, true) ? strtolower($intent) : null,
			isAnotherLanguage: is_bool($another) ? $another : null,
			coreKeyword: DataForSeoResponse::nonEmptyString($properties['core_keyword'] ?? null),
			depth: $depth,
			position: $position,
		);
	}
}
