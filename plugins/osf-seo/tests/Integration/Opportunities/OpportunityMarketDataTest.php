<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Opportunities;

use OsfSeo\DataForSeo\DataForSeoClient;
use OsfSeo\DataForSeo\DataForSeoConfig;
use OsfSeo\DataForSeo\DataForSeoMarkets;
use OsfSeo\DataForSeo\DataForSeoProvider;
use OsfSeo\Http\WpHttpTransport;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Opportunities\Opportunity;
use OsfSeo\Opportunities\OpportunityService;
use OsfSeo\Opportunities\OpportunityType;

/**
 * Szanse SEO a dane rynkowe (STEP 12): dane rynkowe są wyłącznie dodatkowym kontekstem wyświetlania —
 * wykrywanie, priorytet i pewność działają tak samo bez nich, z nimi i przy awarii ich odczytu.
 */
final class OpportunityMarketDataTest extends OpportunitiesTestCase
{
	private MarketMetricsRepository $marketMetrics;

	private OpportunityService $withMarket;

	protected function setUp(): void
	{
		parent::setUp();
		self::freshTables('market_keywords', 'market_keyword_monthly');
		$this->marketMetrics = new MarketMetricsRepository(self::db(), $this->clock);
		$config = new DataForSeoConfig();
		// Bez danych logowania: odczyt zapisanych metryk nie wymaga dostawcy ani sieci.
		$provider = new DataForSeoProvider(new DataForSeoClient($config, new WpHttpTransport(), $this->sleeper, $this->captureLogger()), $config);
		$this->withMarket = new OpportunityService($this->repository, $this->analyzer, $this->data, $this->captureLogger(), null, $provider, $this->marketMetrics);
	}

	public function test_opportunities_work_without_market_data_and_market_data_does_not_change_scoring(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$before = $this->snapshot($context);
		$nearTop = $this->findByType($context, OpportunityType::NearTop);

		self::assertNotEmpty($before);
		self::assertSame([], $this->withMarket->marketMetrics($context, $nearTop), 'Bez danych rynkowych — brak dodatkowego kontekstu, szansa bez zmian.');

		$market = DataForSeoMarkets::resolve('pl', 'pl');
		$items = DataForSeoProvider::parseVolume([\OsfSeo\Tests\Support\DataForSeoFakes::volumeItem('buty zimowe', 9900)]);
		$this->marketMetrics->storeVolume($market, ['buty zimowe'], ['buty zimowe' => $items[0]], 1, 30);
		$this->marketMetrics->storeDifficulty($market, ['buty zimowe'], ['buty zimowe' => 42], 2, 30);
		$this->analyze($context, true);

		self::assertSame($before, $this->snapshot($context), 'Priorytet, pewność i typy szans nie zależą od danych rynkowych.');
		$metrics = $this->withMarket->marketMetrics($context, $nearTop);
		self::assertSame(['buty zimowe'], array_keys($metrics));
		self::assertSame([9900, 42], [$metrics['buty zimowe']->searchVolume, $metrics['buty zimowe']->keywordDifficulty]);
	}

	public function test_market_lookup_failure_does_not_break_the_opportunity_view(): void
	{
		$context = $this->readyProject();
		$this->seedScenario($context);
		$this->analyze($context);
		$nearTop = $this->findByType($context, OpportunityType::NearTop);
		$db = self::db();
		$table = $db->table('market_keywords');
		$db->execute("RENAME TABLE `{$table}` TO `{$table}_off`");

		try {
			self::assertSame([], $this->withMarket->marketMetrics($context, $nearTop));
			self::assertInstanceOf(Opportunity::class, $this->withMarket->find($context, $nearTop->publicId));
		} finally {
			$db->execute("RENAME TABLE `{$table}_off` TO `{$table}`");
		}
	}

	/**
	 * @return list<array{0: string, 1: int, 2: string}>
	 */
	private function snapshot(\OsfSeo\Auth\ProjectContext $context): array
	{
		return array_map(
			static fn (Opportunity $o): array => [$o->type->value, $o->priority, $o->confidence->name],
			$this->listed($context),
		);
	}
}
