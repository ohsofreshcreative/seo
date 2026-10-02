<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Market\Market;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;

/**
 * Zapis metryk fraz z odpowiedzi DataForSEO Labs (Nowe frazy, Luki SEO) do wspólnych `market_keywords`: wolumen i trudność
 * tylko tam, gdzie brakuje danych albo minął TTL (świeży wolumen Google Ads ze STEP 12 nie jest nadpisywany); intencja,
 * grupa synonimów dostawcy (`core_key`) i flaga innego języka — zawsze, gdy dostawca je podał. Brak wartości u dostawcy
 * niczego nie zmienia.
 */
final class KeywordMetricsWriter
{
	public function __construct(
		private readonly MarketMetricsRepository $metrics,
		private readonly MarketDataConfig $config,
	) {
	}

	/**
	 * @param array<string, DiscoveredKeyword> $items postać znormalizowana → fraza z metrykami
	 * @return array<string, int> postać znormalizowana → id frazy rynkowej
	 */
	public function store(Market $market, array $items, int $taskId): array
	{
		$keywords = array_map('strval', array_keys($items));

		if ($keywords === []) {
			return [];
		}

		$ids = $this->metrics->ensure($market, $keywords);
		$existing = $this->metrics->findByKeys($market, array_map(static fn (string $keyword): string => MarketKeyword::key($keyword), $keywords));
		$now = $this->metrics->now();
		$volume = [];
		$difficulty = [];
		$intents = [];
		$cores = [];
		$languages = [];

		foreach ($items as $keyword => $item) {
			// Klucze tablic PHP zamieniają frazy liczbowe („2024”) na int.
			$keyword = (string) $keyword;
			$current = $existing[bin2hex(MarketKeyword::key($keyword))] ?? null;

			if ($item->searchVolume !== null && ($current === null || ! $current->volumeFetched() || $current->isVolumeStale($now))) {
				$volume[$keyword] = $item->volumeMetrics();
			}

			if ($item->keywordDifficulty !== null && ($current === null || ! $current->difficultyFetched() || $current->isDifficultyStale($now))) {
				$difficulty[$keyword] = $item->keywordDifficulty;
			}

			if ($item->intent !== null) {
				$intents[$keyword] = $item->intent;
			}

			if ($item->isAnotherLanguage !== null) {
				$languages[$keyword] = $item->isAnotherLanguage;
			}

			if ($item->coreKeyword !== null && MarketKeyword::normalize($item->coreKeyword) !== '') {
				$cores[$keyword] = $item->coreKeyword;
			}
		}

		if ($volume !== []) {
			$this->metrics->storeVolume($market, array_map('strval', array_keys($volume)), $volume, $taskId, $this->config->volumeTtlDays());
		}

		if ($difficulty !== []) {
			$this->metrics->storeDifficulty($market, array_map('strval', array_keys($difficulty)), $difficulty, $taskId, $this->config->difficultyTtlDays());
		}

		if ($intents !== []) {
			$this->metrics->storeIntent($market, $intents);
		}

		if ($cores !== []) {
			$this->metrics->storeCoreKeys($market, $cores);
		}

		if ($languages !== []) {
			$this->metrics->storeOtherLanguage($market, $languages);
		}

		return $ids;
	}
}
