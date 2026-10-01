<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Discovery;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\Discovery\CandidateFilters;
use OsfSeo\Discovery\CandidateStatus;
use OsfSeo\Discovery\DiscoveryNotFound;
use OsfSeo\Discovery\Visibility;
use OsfSeo\Gsc\Dictionary;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Support\ValidationException;
use OsfSeo\Setup\Installer;
use OsfSeo\Sync\SyncConfig;
use OsfSeo\Sync\SyncPlanner;
use OsfSeo\Sync\SyncRunner;
use OsfSeo\Sync\SyncScheduler;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Kandydaci: widoczność w GSC (średnia pozycja ważona wyświetleniami), strona docelowa, reset property, praca nad frazą,
 * izolacja projektów, filtry i sortowanie, wykluczenia, podpowiedzi seedów, niezależność od importu GSC.
 */
final class DiscoveryCandidatesTest extends DiscoveryTestCase
{
	public function test_gsc_visibility_is_classified_from_weighted_average_position_across_keyword_variants(): void
	{
		$context = $this->projectWithKeywords('example.pl', []);
		// 90 dni do 2026-01-14: „projektowanie stron” na poz. 45 (120 wyświetleń), „strony www” — dwa warianty:
		// 100 wyświetleń na poz. 4 i 10 na poz. 60 → (400 + 600) / 110 = 9,09; „agencja seo” na poz. 2 z dużym udziałem.
		$this->gscRow($context, 'projektowanie stron', 120, 0, 45.0);
		$this->gscRow($context, 'Strony WWW', 100, 30, 4.0);
		$this->gscRow($context, 'strony  www', 10, 0, 60.0, '2026-01-10');
		$this->gscRow($context, 'agencja seo', 2400, 600, 2.0);
		$this->gscRow($context, 'audyt ux', 15, 1, 6.0);
		$this->mockRelated('strony internetowe', [
			[DataForSeoFakes::labsKeyword('projektowanie stron', 1900, 41), 1],
			[DataForSeoFakes::labsKeyword('strony www', 50, 30), 1],
			[DataForSeoFakes::labsKeyword('agencja seo', 1000, 50), 1],
			[DataForSeoFakes::labsKeyword('audyt ux', 2400, 30), 2],
			[DataForSeoFakes::labsKeyword('tworzenie stron', 880, 35), 2],
		]);

		$this->discover($context, 'strony internetowe');

		$weak = $this->candidate($context, 'projektowanie stron');
		self::assertSame([Visibility::Low, 45.0, 120], [$weak->visibility, $weak->gscPosition, $weak->gscImpressions], 'Pozycja 45 przy dużym wolumenie — słaba widoczność, nadal szansa.');
		$variants = $this->candidate($context, 'strony www');
		self::assertSame([Visibility::Visible, 9.09, 110, 30], [$variants->visibility, $variants->gscPosition, $variants->gscImpressions, $variants->gscClicks], 'Warianty frazy sumowane; pozycja = Σ position_sum / Σ wyświetleń.');
		self::assertSame(Visibility::Visible, $this->candidate($context, 'agencja seo')->visibility);
		self::assertSame(Visibility::Low, $this->candidate($context, 'audyt ux')->visibility, 'TOP 10, ale 15 wyświetleń przy wolumenie 2400 — sporadycznie.');
		$none = $this->candidate($context, 'tworzenie stron');
		self::assertSame([Visibility::None, null, 15.0], [$none->visibility, $none->gscPosition, $none->score?->components['gap']]);
		self::assertSame(0.0, $this->candidate($context, 'agencja seo')->score?->components['gap']);

		self::assertSame(['projektowanie stron', 'tworzenie stron', 'audyt ux'], $this->listed($context), 'Domyślnie: luka widoczności (bez „już widocznych”), wg priorytetu.');
		self::assertSame(['agencja seo', 'strony www'], $this->listed($context, ['visibility' => 'visible', 'sort' => 'keyword']));
	}

	public function test_target_page_comes_only_from_gsc_data_and_is_never_guessed(): void
	{
		$context = $this->projectWithKeywords('example.pl', []);
		$this->gscRow($context, 'projektowanie stron', 120, 3, 30.0);
		$db = self::db();
		$dictionary = new Dictionary($db, $this->clock);
		$keywordId = $dictionary->keywordIds($context->projectId(), ['projektowanie stron'])['projektowanie stron'];
		$pages = $dictionary->pageIds($context->projectId(), ['https://example.pl/oferta/', 'https://example.pl/blog/strony#cennik']);

		foreach ([['https://example.pl/oferta/', 1, 40], ['https://example.pl/blog/strony#cennik', 2, 80]] as [$url, $clicks, $impressions]) {
			$db->execute(
				"INSERT INTO `{$db->table('gsc_query_page_daily')}` (project_id, date, keyword_id, page_id, clicks, impressions, position_sum) VALUES (%d, '2026-01-14', %d, %d, %d, %d, %f)",
				[$context->projectId(), $keywordId, $pages[$url], $clicks, $impressions, 30.0 * $impressions],
			);
		}

		$this->mockRelated('strony', [[DataForSeoFakes::labsKeyword('projektowanie stron', 1900, 41), 1], [DataForSeoFakes::labsKeyword('strony cennik', 300, 20), 1]]);
		$this->discover($context, 'strony');

		self::assertSame('https://example.pl/blog/strony', $this->candidate($context, 'projektowanie stron')->targetUrl, 'Strona z największą liczbą kliknięć (bez #fragmentu).');
		self::assertNull($this->candidate($context, 'strony cennik')->targetUrl, 'Brak danych GSC — „Brak przypisanej strony”, bez zgadywania.');
	}

	public function test_property_reset_keeps_candidates_and_workflow_and_reclassifies_after_reimport(): void
	{
		$context = $this->projectWithKeywords('example.pl', []);
		$this->gscRow($context, 'agencja seo', 2400, 600, 2.0);
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('agencja seo', 1000, 50), 1], [DataForSeoFakes::labsKeyword('pozycjonowanie', 900, 40), 1]]);
		$this->discover($context, 'seo');
		$candidate = $this->candidate($context, 'pozycjonowanie');
		$this->discovery->update($context, $candidate->publicId, ['status' => 'accepted', 'note' => 'Do strategii Q1']);
		$this->discovery->update($context, $this->candidate($context, 'agencja seo')->publicId, ['status' => 'dismissed', 'note' => '']);

		$this->mockSites([['sc-domain:example.pl', 'siteOwner'], ['https://www.example.pl/', 'siteOwner']]);
		$context = $this->properties->select($context, 'https://www.example.pl/', true);
		$this->discovery->onPropertyReset($context->projectId());

		$after = $this->candidate($context, 'pozycjonowanie');
		self::assertSame([CandidateStatus::Accepted, 'Do strategii Q1', Visibility::Unknown], [$after->status, $after->note, $after->visibility]);
		self::assertSame(2, self::tableCount('discovery_candidates'), 'Kandydaci nie należą do danych GSC.');
		self::assertSame(Visibility::Unknown, $this->candidate($context, 'agencja seo')->visibility);

		// Nowy import: frazy GSC wracają z nowymi ID; widoczność oceniana od nowa bez żadnego płatnego żądania.
		$this->gscRow($context, 'agencja seo', 2400, 600, 2.0);
		$this->discovery->refresh($context);
		self::assertSame([CandidateStatus::Dismissed, Visibility::Visible], [$this->candidate($context, 'agencja seo')->status, $this->candidate($context, 'agencja seo')->visibility]);
		self::assertSame(Visibility::None, $this->candidate($context, 'pozycjonowanie')->visibility);
		self::assertCount(1, $this->dataForSeoRequests());
	}

	public function test_workflow_status_note_actor_validation_and_bulk_update(): void
	{
		$context = $this->projectWithKeywords('example.pl', []);
		$this->mockRelated('seo', array_map(static fn (int $i): array => [DataForSeoFakes::labsKeyword("fraza {$i}", 100 * $i), 1], range(1, 3)));
		$this->discover($context, 'seo');
		$candidate = $this->candidate($context, 'fraza 1');

		$updated = $this->discovery->update($context, $candidate->publicId, ['status' => 'review', 'note' => "  Sprawdzić intencję\r\nz klientem  "]);
		self::assertSame([CandidateStatus::Review, "Sprawdzić intencję\nz klientem", $context->userId()], [$updated->status, $updated->note, $updated->statusChangedBy]);
		self::assertNotNull($updated->statusChangedAt);

		foreach ([['status' => 'done'], ['status' => 'new', 'note' => str_repeat('x', 2001)]] as $input) {
			try {
				$this->discovery->update($context, $candidate->publicId, $input);
				self::fail('Nieprawidłowe dane muszą zostać odrzucone.');
			} catch (ValidationException) {
			}
		}

		$ids = array_map(fn (string $keyword): string => $this->candidate($context, $keyword)->publicId, ['fraza 2', 'fraza 3']);
		self::assertSame(2, $this->discovery->bulkUpdate($context, [...$ids, '01J0000000000000000000NONE'], 'dismissed'));
		self::assertSame(['fraza 1'], array_values(array_diff($this->listed($context, ['visibility' => 'all']), ['seo'])), 'Odrzucone frazy znikają z domyślnej listy (otwarte).');
		self::assertSame(['fraza 3', 'fraza 2'], $this->listed($context, ['visibility' => 'all', 'status' => 'dismissed', 'sort' => 'volume']));

		$client = $this->createUser(Roles::CLIENT);
		$this->projects->assign($context->projectId(), $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		self::assertSame('fraza 1', $this->discovery->candidate($clientContext, $candidate->publicId)->keyword, 'Klient czyta szczegóły.');

		foreach ([
			fn () => $this->discovery->update($clientContext, $candidate->publicId, ['status' => 'accepted']),
			fn () => $this->discovery->bulkUpdate($clientContext, $ids, 'accepted'),
		] as $call) {
			try {
				$call();
				self::fail('Klient nie zmienia statusu.');
			} catch (AccessDenied) {
			}
		}
	}

	public function test_candidates_and_runs_are_isolated_between_projects(): void
	{
		$a = $this->projectWithKeywords('example.pl', []);
		$b = $this->projectWithKeywords('sklep.pl', []);
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]]);
		$run = $this->discover($a, 'seo');
		$candidate = $this->candidate($a, 'pozycjonowanie');

		self::assertSame([], $this->listed($b, ['visibility' => 'all', 'status' => 'all']));

		foreach ([
			fn () => $this->discovery->candidate($b, $candidate->publicId),
			fn () => $this->discovery->update($b, $candidate->publicId, ['status' => 'accepted']),
			fn () => $this->discovery->run($b, $run->publicId),
			fn () => $this->discovery->cancel($b, $run->publicId),
			fn () => $this->discovery->candidate($a, (string) $candidate->id),
			fn () => $this->discovery->candidate($a, "01J0000000000000000000TEST' OR 1=1 --"),
		] as $call) {
			try {
				$call();
				self::fail('Identyfikator z innego projektu (lub wewnętrzne ID) musi dawać „nie znaleziono”.');
			} catch (DiscoveryNotFound) {
			}
		}

		self::assertSame(0, $this->discovery->bulkUpdate($b, [$candidate->publicId], 'dismissed'), 'Akcja zbiorcza pomija frazy innych projektów.');
		self::assertSame(CandidateStatus::New, $this->candidate($a, 'pozycjonowanie')->status);

		// Ta sama fraza w projekcie B — osobny kandydat (osobny stan pracy), wspólna fraza rynkowa (jedne metryki).
		$this->mockRelated('seo', [[DataForSeoFakes::labsKeyword('pozycjonowanie', 900), 1]]);
		$this->discover($b, 'seo');
		self::assertSame($candidate->market->id, $this->candidate($b, 'pozycjonowanie')->market->id);
		self::assertNotSame($candidate->publicId, $this->candidate($b, 'pozycjonowanie')->publicId);
	}

	public function test_list_filters_sorting_pagination_and_retroactive_exclusions(): void
	{
		$context = $this->projectWithKeywords('example.pl', []);
		$this->mockRelated('seo', [
			[DataForSeoFakes::labsKeyword('pozycjonowanie stron', 2400, 60, 4.0, 0.5, 'commercial'), 1],
			[DataForSeoFakes::labsKeyword('jak działa seo', 880, 20, 0.5, 0.1, 'informational'), 2],
			[DataForSeoFakes::labsKeyword('praca seo specjalista', 590, 15, 1.0, 0.2, 'navigational'), 2],
			[DataForSeoFakes::labsKeyword('żółte strony seo', 40, null, null, null, null), 3],
		]);
		$this->discover($context, 'seo', ['depth' => '3']);

		self::assertSame(['praca seo specjalista', 'jak działa seo', 'pozycjonowanie stron', 'żółte strony seo'], $this->listed($context, ['sort' => 'difficulty']), 'Trudność rosnąco (15, 20, 60), brak wartości na końcu.');
		self::assertSame(['pozycjonowanie stron', 'jak działa seo'], $this->listed($context, ['min_volume' => '800', 'sort' => 'volume']));
		self::assertSame(['jak działa seo', 'praca seo specjalista'], $this->listed($context, ['max_kd' => '30', 'sort' => 'volume']));
		self::assertSame(['jak działa seo'], $this->listed($context, ['intent' => 'informational']));
		self::assertSame(['żółte strony seo'], $this->listed($context, ['q' => 'ŻÓŁTE']), 'Wyszukiwanie bez rozróżniania wielkości liter, z polskimi znakami.');
		self::assertSame([], $this->listed($context, ['q' => '%']), 'Znaki LIKE są escapowane.');
		$top = $this->listed($context);
		self::assertCount(4, $top);
		self::assertSame($top, array_slice($this->listed($context, ['min_priority' => '0']), 0, 4));

		$page = $this->discovery->list($context, new CandidateFilters(perPage: 2, page: 2));
		self::assertSame([4, 2, 2], [$page->total, count($page->rows), $page->pages()]);

		$this->discovery->saveExclusions($context, "praca\nżółt*");
		self::assertSame(['pozycjonowanie stron', 'jak działa seo'], $this->listed($context, ['sort' => 'volume']), 'Wykluczenia działają wstecz (bez API).');
		self::assertSame(['praca seo specjalista', 'żółte strony seo'], $this->listed($context, ['excluded' => '1', 'sort' => 'volume']));
		self::assertSame(2, $this->discovery->status($context)['summary']['excluded']);
		self::assertSame("praca\nżółt*", $this->discovery->exclusions($context)->toText());
		self::assertCount(1, $this->dataForSeoRequests());
	}

	public function test_seed_suggestions_come_from_gsc_and_opportunities_without_brand_queries(): void
	{
		$context = $this->projectWithKeywords('ohsofresh.pl', []);
		$this->gscRow($context, 'strony internetowe warszawa', 900, 40, 8.0);
		$this->gscRow($context, 'OhSoFresh agencja', 2000, 900, 1.0);
		$this->gscRow($context, 'oh so fresh', 400, 200, 1.0);
		$this->gscRow($context, 'sklep woocommerce', 300, 10, 15.0);
		$this->gscRow($context, 'hosting', 3000, 2, 48.0);
		$this->gscRow($context, 'jak zrobić stronę internetową krok po kroku', 500, 20, 9.0);

		$suggestions = $this->discovery->suggestions($context);

		self::assertSame(['strony internetowe warszawa', 'sklep woocommerce'], array_column($suggestions['gsc'], 'seed'), 'Bez marki, bez słabych pozycji (> 20) i długich zapytań; wg kliknięć.');
		self::assertSame([], $suggestions['opportunity']);
		self::assertSame([], $this->dataForSeoRequests());
	}

	public function test_discovery_background_failure_does_not_affect_gsc_queue_or_other_modules(): void
	{
		$context = $this->projectWithKeywords();
		$this->discovery->start($context, $this->request('seo'), 'cli');
		$this->google->json(self::RELATED, 200, ['unexpected' => 'html']);
		$plugin = osf_seo();
		$scheduler = new SyncScheduler($plugin->get(SyncPlanner::class), $plugin->get(SyncRunner::class), $this->guard, $plugin->get(Installer::class), $plugin->get(SyncConfig::class), $this->captureLogger());
		$ran = [];
		$scheduler->onAfterRun(function () use (&$ran): array {
			add_filter('wp_doing_cron', '__return_true');

			return $ran[] = $this->discovery->runBackground(20.0, true);
		});
		$scheduler->onAfterRun(static function (): never {
			throw new \RuntimeException('discovery exploded');
		});
		$scheduler->onAfterRun(function () use (&$ran): string {
			return $ran[] = 'next step';
		});

		$scheduler->runFollowUps();

		self::assertCount(2, $ran, 'Błąd kroku wyszukiwania nie zatrzymuje kolejnych kroków.');
		self::assertSame('next step', $ran[1]);
		self::assertSame('failed', $this->runs->recent($context->projectId())[0]->status, 'Uszkodzona odpowiedź — tylko przebieg wyszukiwania się nie udaje.');
		self::assertSame(7, self::rowCount('keywords', $context->projectId()), 'Dane GSC nietknięte.');
	}

	private function gscRow(ProjectContext $context, string $keyword, int $impressions, int $clicks, float $position, string $date = '2026-01-14'): void
	{
		$db = self::db();
		$id = (new Dictionary($db, $this->clock))->keywordIds($context->projectId(), [$keyword])[$keyword];
		$db->execute(
			"INSERT INTO `{$db->table('gsc_query_daily')}` (project_id, date, keyword_id, clicks, impressions, position_sum) VALUES (%d, %s, %d, %d, %d, %f)",
			[$context->projectId(), $date, $id, $clicks, $impressions, $position * $impressions],
		);
	}
}
