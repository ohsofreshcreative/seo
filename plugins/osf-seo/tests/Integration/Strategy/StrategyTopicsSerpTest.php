<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Strategy\Topics\TopicEvent;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Rdzeń Strategii na pomiarach STEP 14 (atrapa dostawcy): Pozycja SERP ze świeżego pomiaru prowadzi do optymalizacji, spadek
 * w porównywalnym pomiarze — do odzyskania, zmiana adresu rankującego — do sprawdzenia; silny overlap SERP łączy frazy w temat.
 */
final class StrategyTopicsSerpTest extends StrategyTestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	public function test_serp_measurements_drive_optimize_then_recover_then_url_flip(): void
	{
		$context = $this->trackedProject(['pozycjonowanie stron']);
		$this->gscKeyword($context, 'pozycjonowanie stron', 800, 15.0, self::PAGE);
		$this->measure($context, ['pozycjonowanie stron' => self::results(14, self::PAGE)]);
		$this->strategy->refresh($context);

		$topic = $this->strategy->topic($context, 'pozycjonowanie stron')['topic'];
		self::assertSame(['optimize', 'serp_position', 'confirmed', self::PAGE, 'top20'], [$topic->action, $topic->actionReason, $topic->targetState, $topic->targetUrl, $topic->serpBand]);
		self::assertSame(['gsc', 'serp'], $topic->analysis['target']['families']);

		// Spadek #14 → #24 w porównywalnym pomiarze — odzyskanie.
		$this->measureNextWeek($context, ['pozycjonowanie stron' => self::results(24, self::PAGE)]);
		$this->strategy->refresh($context);
		$detail = $this->strategy->topic($context, 'pozycjonowanie stron');
		self::assertSame(['recover', 'serp_decline', 'top50'], [$detail['topic']->action, $detail['topic']->actionReason, $detail['topic']->serpBand]);
		self::assertSame(10, $detail['topic']->analysis['facts']['decline']['lost']);
		$events = array_column($detail['events'], 'type');
		self::assertContains(TopicEvent::ACTION_CHANGED, $events);
		self::assertContains(TopicEvent::SERP_BAND_CHANGED, $events);

		// Inna strona projektu rankuje (bez spadku) — pojedyncza zmiana adresu: do sprawdzenia, nie konsolidacja.
		$this->measureNextWeek($context, ['pozycjonowanie stron' => self::results(23, 'https://example.pl/blog/pozycjonowanie-poradnik/')]);
		$this->strategy->refresh($context);
		$flip = $this->strategy->topic($context, 'pozycjonowanie stron')['topic'];
		self::assertSame(['investigate', 'url_flip'], [$flip->action, $flip->actionReason]);
		self::assertContains('url_flip', array_column($flip->analysis['conflicts'], 'type'));
		self::assertNotSame('high', $flip->confidenceLevel);
	}

	public function test_strong_serp_overlap_groups_keywords_and_different_results_stay_apart(): void
	{
		$context = $this->trackedProject(['buty do biegania', 'obuwie do biegania', 'kurtka zimowa']);
		$shared = ['https://sklep-a.example/biegowe/', 'https://sklep-b.example/biegowe/', 'https://sklep-c.example/biegowe/', 'https://sklep-d.example/biegowe/', 'https://sklep-e.example/biegowe/'];
		$this->measure($context, [
			'buty do biegania' => self::urls($shared, 'buty'),
			'obuwie do biegania' => self::urls(array_reverse($shared), 'obuwie'),
			'kurtka zimowa' => self::urls([], 'kurtka'),
		]);
		$this->strategy->refresh($context);

		$running = $this->strategy->topic($context, 'obuwie do biegania');
		self::assertSame(['buty do biegania', 'obuwie do biegania'], array_column($running['members'], 'keyword'));
		self::assertSame('serp_overlap', $running['topic']->analysis['members'][1]['basis']);
		self::assertSame(1, $this->strategy->topic($context, 'kurtka zimowa')['topic']->keywordsCount);
		self::assertSame(2, $this->strategy->status($context)['topics']['active']);
	}

	/**
	 * TOP20: projekt (adres) na pozycji, reszta — syntetyczne domeny z podstronami.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function results(int $rank, string $url): array
	{
		$items = [];

		for ($position = 1; $position <= 30; $position++) {
			$items[] = $position === $rank
				? DataForSeoFakes::serpOrganic($position, 'example.pl', $url)
				: DataForSeoFakes::serpOrganic($position, 'wynik-' . $position . '.example', 'https://wynik-' . $position . '.example/pozycjonowanie/');
		}

		return $items;
	}

	/**
	 * TOP20: podane adresy na pierwszych pozycjach, reszta — unikalne adresy frazy.
	 *
	 * @param list<string> $urls
	 * @return list<array<string, mixed>>
	 */
	private static function urls(array $urls, string $slug): array
	{
		$items = [];

		for ($position = 1; $position <= 20; $position++) {
			$url = $urls[$position - 1] ?? 'https://inny-' . $position . '-' . $slug . '.example/' . $slug . '/';
			$items[] = DataForSeoFakes::serpOrganic($position, (string) parse_url($url, PHP_URL_HOST), $url);
		}

		return $items;
	}
}
