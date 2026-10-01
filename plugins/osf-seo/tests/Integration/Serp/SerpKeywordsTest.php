<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Serp;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Auth\Roles;
use OsfSeo\Market\MarketKeyBackfill;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Support\Ulid;
use OsfSeo\Support\ValidationException;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Monitorowane frazy: źródła (ręcznie, Frazy GSC, Nowe frazy) bez płatnych żądań i bez wzbogacania, miękki limit
 * bez obcinania, usuwanie z zachowaniem historii, średnia pozycja GSC osobno od Pozycji SERP, izolacja projektów,
 * klient tylko do odczytu, reset property GSC.
 */
final class SerpKeywordsTest extends SerpTestCase
{
	public function test_manual_keywords_are_normalized_validated_and_not_enriched(): void
	{
		$context = $this->trackedProject([]);
		$result = $this->serp->addKeywords($context, 'manual', "Buty Damskie\nbuty  damskie, site:example.pl\n\n" . str_repeat('a', 201) . "; żółte buty");

		self::assertSame(2, $result['added']);
		self::assertSame(['search_operator', 'too_long'], array_column($result['rejected'], 'reason'));
		self::assertSame(['buty damskie', 'żółte buty'], $this->positionsList($context, ['sort' => 'keyword']));
		self::assertNull($this->row($context, 'buty damskie')->searchVolume, 'Bez wzbogacania — Wolumen „—”, nie 0.');
		self::assertSame('—', $this->row($context, 'buty damskie')->rankLabel(), 'Jeszcze nie sprawdzono.');
		self::assertSame(0, self::tableCount('market_tasks'));
		self::assertSame([], $this->dataForSeoRequests());

		$again = $this->serp->addKeywords($context, 'manual', ['buty damskie']);
		self::assertSame(['added' => 0, 'restored' => 0, 'existing' => 1], array_intersect_key($again, array_flip(['added', 'restored', 'existing'])));
	}

	public function test_gsc_keywords_are_tracked_only_when_they_exist_in_the_project(): void
	{
		$context = $this->projectWithKeywords();
		$result = $this->serp->addKeywords($context, 'gsc', ['Buty Damskie', 'żółte buty', 'fraza spoza gsc']);

		self::assertSame(2, $result['added']);
		self::assertSame([['keyword' => 'fraza spoza gsc', 'reason' => 'not_found']], $result['rejected']);
		self::assertSame('gsc', $this->row($context, 'buty damskie')->source);
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_discovery_candidates_are_tracked_by_public_id_of_the_same_project_only(): void
	{
		$context = $this->trackedProject([]);
		$other = $this->trackedProject([], 'inny.pl');
		$market = $this->serp->market($context);
		$ids = $this->marketMetrics->ensure($market, ['nowa fraza', 'obca fraza']);
		$mine = $this->candidateRow($context->projectId(), $ids['nowa fraza']);
		$foreign = $this->candidateRow($other->projectId(), $ids['obca fraza']);

		$result = $this->serp->addKeywords($context, 'discovery', [$mine, $foreign, 'nie-ulid']);

		self::assertSame(1, $result['added']);
		self::assertSame(['nowa fraza'], $this->positionsList($context));
		self::assertSame('discovery', $this->row($context, 'nowa fraza')->source);
		self::assertSame([], $this->positionsList($other));
	}

	public function test_soft_limit_rejects_the_whole_selection_with_a_clear_message(): void
	{
		putenv(SerpConfig::MAX_KEYWORDS . '=3');
		$this->buildServices();
		$context = $this->trackedProject(['jeden', 'dwa']);

		try {
			$this->serp->addKeywords($context, 'manual', ['dwa', 'trzy', 'cztery']);
			self::fail('Limit przekroczony.');
		} catch (ValidationException $exception) {
			self::assertStringContainsString('Limit monitorowanych fraz w projekcie: 3 (obecnie 2). Możesz dodać jeszcze 1, a wybrano 2 nowych.', $exception->errors()['keywords']);
		}

		self::assertSame(['dwa', 'jeden'], $this->positionsList($context, ['sort' => 'keyword']), 'Bez cichego obcinania wyboru.');
		self::assertSame(1, $this->serp->addKeywords($context, 'manual', ['dwa', 'trzy'])['added'], 'Fraza już monitorowana nie liczy się do nowych.');
	}

	public function test_limit_supports_thousands_of_keywords_per_project(): void
	{
		putenv(SerpConfig::MAX_KEYWORDS . '=5000');
		$this->buildServices();
		$context = $this->trackedProject([]);
		$keywords = array_map(static fn (int $i): string => 'fraza ' . $i, range(1, 2500));

		self::assertSame(2500, $this->serp->addKeywords($context, 'manual', $keywords)['added']);
		$plan = $this->serp->plan($context);
		self::assertSame(2500, $plan->tasks());
		self::assertSame(25, $plan->posts());
		self::assertEqualsWithDelta(2500 * self::TOP100_COST, $plan->estimatedCost(), 1e-6);
		self::assertSame(2500, $this->serp->positions($context, PositionsFilters::fromInput([]))['total']);
		self::assertCount(PositionsFilters::PER_PAGE, $this->serp->positions($context, PositionsFilters::fromInput(['page' => '50']))['rows']);
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_removed_keyword_keeps_history_and_can_be_restored(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([6 => 'example.pl'])]);
		$publicId = $this->row($context, 'buty damskie')->publicId;

		self::assertSame(1, $this->serp->removeKeywords($context, [$publicId]));
		self::assertSame([], $this->positionsList($context));
		self::assertSame(100, self::tableCount('serp_results'), 'Usunięcie z monitorowania nie usuwa historii.');
		self::assertSame('no_keywords', $this->serp->plan($context)->skipReason);

		self::assertSame(1, $this->serp->addKeywords($context, 'manual', ['buty damskie'])['restored']);
		$row = $this->row($context, 'buty damskie');
		self::assertSame([$publicId, 6], [$row->publicId, $row->rank]);
	}

	public function test_gsc_average_position_is_shown_separately_from_serp_rank(): void
	{
		$context = $this->projectWithKeywords();
		self::db()->insert(self::db()->table('gsc_site_daily'), ['project_id' => $context->projectId(), 'date' => '2026-01-14', 'device' => 0, 'clicks' => 10, 'impressions' => 1000, 'position_sum' => 9000.0]);
		(new MarketKeyBackfill(self::db()))->fillProject($context->projectId());
		$this->serp->addKeywords($context, 'gsc', ['Buty Damskie']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([3 => 'example.pl'])]);

		$row = $this->row($context, 'buty damskie');
		self::assertSame(3, $row->rank, 'Pozycja SERP — ostatni pomiar.');
		self::assertSame(8.0, $row->gscPosition, 'Średnia pozycja (GSC) obu wariantów frazy: Σ position_sum / Σ wyświetleń.');
		self::assertSame(560, $row->gscImpressions);

		$market = $this->serp->market($context);
		$marketId = $this->marketMetrics->ensure($market, ['buty damskie'])['buty damskie'];
		$ranks = $this->serp->ranksForMarketKeywords($context, [$marketId, 999999]);
		self::assertSame([$marketId], array_keys($ranks), 'Kolumna „Pozycja SERP” w Frazach tylko dla monitorowanych.');
		self::assertSame(3, $ranks[$marketId]['rank']);
	}

	public function test_gsc_property_reset_keeps_serp_tracking_and_history(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->competitors->create($context, ['domain' => 'konkurent.pl', 'name' => '']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl'])]);
		$this->seedGscData($context, '2026-01-10');

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$context = $this->properties->select($context, 'https://www.example.pl/', true);

		self::assertSame(0, self::rowCount('gsc_query_daily', $context->projectId()));
		self::assertSame(1, self::tableCount('serp_tracked_keywords'));
		self::assertSame(100, self::tableCount('serp_results'));
		self::assertSame(1, self::tableCount('serp_competitors'));
		self::assertSame(2, $this->row($context, 'buty damskie')->rank);
	}

	public function test_client_can_read_but_never_change_or_run_checks(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl'])]);
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		$requests = count($this->dataForSeoRequests());

		self::assertSame(['buty damskie'], $this->positionsList($clientContext));
		self::assertSame(2, $this->serp->keyword($clientContext, $this->row($clientContext, 'buty damskie')->publicId)['row']->rank);
		self::assertSame([], $this->competitors->list($clientContext));

		$denied = [
			'addKeywords' => fn () => $this->serp->addKeywords($clientContext, 'manual', ['nowa']),
			'removeKeywords' => fn () => $this->serp->removeKeywords($clientContext, [$this->row($clientContext, 'buty damskie')->publicId]),
			'start' => fn () => $this->serp->start($clientContext),
			'saveSettings' => fn () => $this->serp->saveSettings($clientContext, ['enabled' => '1', 'confirm' => '1']),
			'execute' => fn () => $this->serp->execute($clientContext, $this->serpRuns->recent($context->projectId())[0]),
			'cancel' => fn () => $this->serp->cancel($clientContext, $this->serpRuns->recent($context->projectId())[0]->publicId),
			'createCompetitor' => fn () => $this->competitors->create($clientContext, ['domain' => 'konkurent.pl', 'name' => '']),
		];

		foreach ($denied as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		self::assertSame($requests, count($this->dataForSeoRequests()));
		self::assertSame(1, self::tableCount('serp_tracked_keywords'));
		self::assertFalse($this->serp->settings($context)->enabled);
	}

	public function test_records_of_another_project_are_not_found(): void
	{
		$first = $this->trackedProject(['buty damskie']);
		$second = $this->trackedProject(['żółte buty'], 'drugi-projekt.pl');
		$run = $this->measure($first, ['buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl'])]);
		$keyword = $this->row($first, 'buty damskie')->publicId;
		$snapshot = (string) self::db()->fetchValue('SELECT public_id FROM `' . self::db()->table('serp_snapshots') . '`');

		foreach ([
			'keyword' => fn () => $this->serp->keyword($second, $keyword),
			'run' => fn () => $this->serp->run($second, $run->publicId),
			'snapshot' => fn () => $this->serp->keyword($second, $this->row($second, 'żółte buty')->publicId, $snapshot),
		] as $label => $call) {
			try {
				$call();
				self::fail('Rekord innego projektu: ' . $label);
			} catch (SerpNotFound) {
			}
		}

		self::assertSame(0, $this->serp->removeKeywords($second, [$keyword]));
		self::assertFalse($this->serp->cancel($second, $run->publicId));
		self::assertSame(['buty damskie'], $this->positionsList($first));
		self::assertNull($this->serp->trackedId($second, $keyword));
		self::assertNull($this->serp->trackedId($second, 'buty damskie'));

		$outsider = $this->createUser(Roles::CLIENT);
		$this->expectException(ProjectNotFound::class);
		$this->guard->authorize($first->publicId(), $outsider);
	}

	private function candidateRow(int $projectId, int $marketKeywordId): string
	{
		$publicId = Ulid::generate();
		self::db()->insert(self::db()->table('discovery_candidates'), [
			'public_id' => $publicId,
			'project_id' => $projectId,
			'market_keyword_id' => $marketKeywordId,
			'status' => 'new',
			'seeds_count' => 1,
			'visibility' => 'unknown',
			'excluded' => 0,
			'discovered_at' => '2026-01-15 12:00:00',
			'last_seen_at' => '2026-01-15 12:00:00',
			'created_at' => '2026-01-15 12:00:00',
			'updated_at' => '2026-01-15 12:00:00',
		]);

		return $publicId;
	}
}
