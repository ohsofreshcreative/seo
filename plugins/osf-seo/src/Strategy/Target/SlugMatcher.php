<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\TextFold;

/**
 * Dopasowanie frazy do adresów znanych stron projektu po wyrazach ścieżki (bez stron głównych) — wyłącznie słaba wskazówka
 * strony docelowej (D57): brak dopasowania nie dowodzi braku strony, dopasowanie nie potwierdza strony.
 */
final class SlugMatcher
{
	/** @var array<string, list<int>> wyraz ścieżki → indeksy stron */
	private array $byToken = [];

	/** @var list<array{url: string, tokens: array<string, bool>}> */
	private array $pages = [];

	/**
	 * @param list<string> $urls
	 */
	public function __construct(array $urls)
	{
		foreach (array_values(array_unique($urls)) as $url) {
			if (TextFold::isRoot($url)) {
				continue;
			}

			$tokens = array_fill_keys(TextFold::pathTokens($url), true);

			if ($tokens === []) {
				continue;
			}

			$index = count($this->pages);
			$this->pages[] = ['url' => $url, 'tokens' => $tokens];

			foreach (array_keys($tokens) as $token) {
				$this->byToken[(string) $token][] = $index;
			}
		}
	}

	/**
	 * Strony z co najmniej 60% wyrazów frazy w ścieżce (fraza wielowyrazowa — co najmniej dwa wyrazy), najlepsze pierwsze.
	 *
	 * @return list<array{url: string, coverage: float}>
	 */
	public function match(string $keyword, int $limit = 2): array
	{
		$tokens = array_values(array_unique(TextFold::tokens($keyword)));

		if ($tokens === []) {
			return [];
		}

		$candidates = [];

		foreach ($tokens as $token) {
			foreach ($this->byToken[$token] ?? [] as $index) {
				$candidates[$index] = ($candidates[$index] ?? 0) + 1;
			}
		}

		$result = [];

		foreach ($candidates as $index => $hits) {
			$coverage = $hits / count($tokens);

			if ($coverage >= GapConfig::SLUG_COVERAGE && ($hits >= 2 || count($tokens) === 1)) {
				$result[] = ['url' => $this->pages[$index]['url'], 'coverage' => round($coverage, 2), 'extra' => count($this->pages[$index]['tokens']) - $hits];
			}
		}

		usort($result, static fn (array $a, array $b): int => [$b['coverage'], $a['extra'], $a['url']] <=> [$a['coverage'], $b['extra'], $b['url']]);

		return array_map(static fn (array $item): array => ['url' => $item['url'], 'coverage' => $item['coverage']], array_slice($result, 0, max(0, $limit)));
	}
}
