<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\PageIntelligence;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\PageIntelligence\PageTarget;
use OsfSeo\Tests\Integration\Ai\AiTestCase;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Baza testów Page Intelligence (STEP 17, faza B): prawdziwy WordPress, baza i Strategia; transport stron — atrapa `FakePageFetcher`
 * (logika usługi: cache, snapshoty, limity, uprawnienia, izolacja). Bezpieczeństwo połączenia (przypięcie IP, przekierowania, TLS)
 * sprawdzają testy na prawdziwym ext-curl i lokalnych serwerach (`CurlPageFetcherTest`, `PageRealTransportTest`).
 */
abstract class PageTestCase extends AiTestCase
{
	protected const COMPETITOR_PAGE = 'https://konkurent.pl/pozycjonowanie/';

	/** Pobranie wskazanych adresów (CLI). */
	protected function fetchUrls(ProjectContext $context, array $urls, bool $force = false): array
	{
		return $this->pageService->fetch($context, PageSelection::urls($urls), $force);
	}

	protected function fetchOne(ProjectContext $context, string $url, bool $force = false): array
	{
		return $this->fetchUrls($context, [$url], $force)[0];
	}

	protected function target(ProjectContext $context, string $url): PageTarget
	{
		return $this->pageRepository->targetByUrl($context->projectId(), $url) ?? throw new \RuntimeException('No page target ' . $url);
	}

	protected static function pageRows(string $table, ?int $projectId = null): int
	{
		$db = self::db();

		return $projectId === null
			? (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`")
			: (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}` WHERE project_id = %d", [$projectId]);
	}

	/** Odstęp pobrań z hosta (domyślnie 10 s) — kolejne pobranie tego samego hosta w testach. */
	protected function afterHostInterval(): void
	{
		$this->clock->advance(11);
	}

	/**
	 * Projekt z pomiarem SERP frazy „pozycjonowanie stron”: projekt na #6, wyniki wynik-N.example (organiczne) i wyróżniony fragment.
	 */
	protected function serpProject(): ProjectContext
	{
		$context = $this->trackedProject(['pozycjonowanie stron']);
		$this->gscKeyword($context, 'pozycjonowanie stron', 800, 15.0, self::PAGE, 10);
		$items = [DataForSeoFakes::serpOrganic(1, 'snippet.example', 'https://snippet.example/odpowiedz/', 1, ['type' => 'featured_snippet'])];

		for ($rank = 1; $rank <= 10; $rank++) {
			$items[] = $rank === 6
				? DataForSeoFakes::serpOrganic($rank, 'example.pl', self::PAGE, $rank + 1)
				: DataForSeoFakes::serpOrganic($rank, 'wynik-' . $rank . '.example', 'https://wynik-' . $rank . '.example/seo/', $rank + 1);
		}

		$this->measure($context, ['pozycjonowanie stron' => $items]);
		$this->strategy->refresh($context);

		return $context;
	}

	protected function competitorHtml(int $rank): string
	{
		return AiFakes::pageHtml('Wynik ' . $rank . ' — oferta SEO', 'Oferta pozycjonowania ' . $rank, 2);
	}
}
