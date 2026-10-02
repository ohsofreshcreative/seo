<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Serp;

use OsfSeo\Analytics\ReportCache;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\RankChange;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Support\ValidationException;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * Konkurenci: walidacja i edycja (bez wywołań API), pozycje odtwarzane z zapisanych pełnych SERP-ów (także dla
 * konkurenta dodanego później), rodzina domen (subdomeny), dezaktywacja i archiwum, konkurenci organiczni, pełne TOP100
 * z wyróżnieniem projektu i konkurentów.
 */
final class SerpCompetitorsTest extends SerpTestCase
{
	public function test_competitor_validation_normalizes_and_rejects_own_and_duplicate_domains(): void
	{
		$context = $this->trackedProject([], 'example.pl');

		$competitor = $this->competitors->create($context, ['domain' => 'https://WWW.Konkurent.PL/oferta?x=1', 'name' => '']);
		self::assertSame('konkurent.pl', $competitor->domain);
		self::assertSame('konkurent.pl', $competitor->name, 'Nazwa domyślnie = domena.');
		self::assertSame(Competitor::ACTIVE, $competitor->status);

		foreach ([
			'' => 'domain',
			'nie domena' => 'domain',
			'example.pl' => 'domain',
			'blog.example.pl' => 'domain',
			'www.example.pl' => 'domain',
			'konkurent.pl' => 'domain',
		] as $domain => $field) {
			try {
				$this->competitors->create($context, ['domain' => $domain, 'name' => 'X']);
				self::fail('Domena powinna zostać odrzucona: ' . $domain);
			} catch (ValidationException $exception) {
				self::assertArrayHasKey($field, $exception->errors(), $domain);
			}
		}

		try {
			$this->competitors->create($context, ['domain' => 'inny.pl', 'name' => str_repeat('ą', 191)]);
			self::fail('Za długa nazwa.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('name', $exception->errors());
		}

		$sub = $this->competitors->create($context, ['domain' => 'sklep.konkurent.pl', 'name' => 'Sklep konkurenta']);
		self::assertSame('sklep.konkurent.pl', $sub->domain, 'Subdomena konkurenta może być osobnym konkurentem.');
		self::assertSame([], $this->dataForSeoRequests(), 'Zarządzanie konkurentami bez żadnego żądania.');
	}

	public function test_edit_deactivate_archive_and_restore(): void
	{
		$context = $this->trackedProject([], 'example.pl');
		$competitor = $this->competitors->create($context, ['domain' => 'konkurent.pl', 'name' => 'Konkurent']);

		$edited = $this->competitors->update($context, $competitor->publicId, ['name' => 'Konkurent S.A.', 'domain' => 'konkurent.com.pl']);
		self::assertSame(['Konkurent S.A.', 'konkurent.com.pl'], [$edited->name, $edited->domain]);

		$inactive = $this->competitors->update($context, $competitor->publicId, ['status' => 'inactive']);
		self::assertSame(Competitor::INACTIVE, $inactive->status);
		self::assertSame([], $this->competitorRepository->active($context->projectId()));
		self::assertCount(1, $this->competitors->list($context));

		$this->competitors->update($context, $competitor->publicId, ['status' => 'archived']);
		self::assertCount(0, $this->competitors->list($context));
		self::assertCount(1, $this->competitors->list($context, true));

		try {
			$this->competitors->create($context, ['domain' => 'konkurent.com.pl', 'name' => '']);
			self::fail('Domena w archiwum.');
		} catch (ValidationException $exception) {
			self::assertStringContainsString('archiwum', $exception->errors()['domain']);
		}

		self::assertSame(Competitor::ACTIVE, $this->competitors->update($context, $competitor->publicId, ['status' => 'active'])->status);

		try {
			$this->competitors->update($context, $competitor->publicId, ['domain' => 'sklep.example.pl']);
			self::fail('Domena projektu.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('domain', $exception->errors());
		}
	}

	public function test_competitor_added_later_gets_history_from_stored_serps(): void
	{
		$context = $this->trackedProject(['buty damskie', 'żółte buty']);
		$this->measure($context, [
			'buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl', 3 => 'konkurent.pl', 15 => 'sklep.konkurent.pl']),
			'żółte buty' => DataForSeoFakes::serpTop([1 => 'example.pl']),
		]);
		$this->measureNextWeek($context, [
			'buty damskie' => DataForSeoFakes::serpTop([2 => 'example.pl', 5 => 'sklep.konkurent.pl', 30 => 'konkurent.pl']),
			'żółte buty' => DataForSeoFakes::serpTop([1 => 'example.pl', 9 => 'konkurent.pl']),
		]);
		$requests = count($this->dataForSeoRequests());

		$competitor = $this->competitors->create($context, ['domain' => 'konkurent.pl', 'name' => 'Konkurent']);
		$detail = $this->competitors->detail($context, $competitor->publicId);

		self::assertSame($requests, count($this->dataForSeoRequests()), 'Konkurent dodany później — bez nowego pomiaru.');
		$rows = array_column($detail['rows'], null, 'keyword');
		self::assertSame(5, $rows['buty damskie']['rank'], 'Najlepszy wynik rodziny domen (subdomena sklep.).');
		self::assertSame('https://sklep.konkurent.pl/strona-5/', $rows['buty damskie']['url']);
		self::assertSame([['rank' => 30, 'url' => 'https://konkurent.pl/strona-30/']], $rows['buty damskie']['other_urls']);
		self::assertSame([RankChange::DOWN, -2], [$rows['buty damskie']['change']->type, $rows['buty damskie']['change']->value], '#3 → #5.');
		self::assertSame([RankChange::ENTERED, 'entered'], [$rows['żółte buty']['change']->type, $rows['żółte buty']['change']->top10]);
		self::assertSame(['checked' => 2, 'found' => 2, 'top3' => 0, 'top10' => 2, 'top20' => 2, 'avg_rank' => 7.0], $detail['stats']);

		$row = $this->row($context, 'buty damskie');
		self::assertSame(5, $row->competitors[$competitor->publicId]['best'], 'Kolumna Konkurenci w liście pozycji.');

		$keyword = $this->serp->keyword($context, $row->publicId);
		$byRank = array_column(array_filter($keyword['results'], static fn (array $item): bool => (int) $item['result_type'] === 1), null, 'rank_group');
		self::assertCount(100, $byRank, 'Pełne TOP100 w szczegółach frazy.');
		self::assertTrue($byRank[2]['is_project']);
		self::assertSame('Konkurent', $byRank[5]['competitor']);
		self::assertSame('Konkurent', $byRank[30]['competitor']);
		self::assertNull($byRank[4]['competitor']);
		self::assertFalse($byRank[4]['is_project']);
		$history = $keyword['history_competitors'];
		self::assertSame([5, 3], array_map(static fn (array $item): ?int => $history[(int) $item['id']][$competitor->publicId][0]['rank'] ?? null, $keyword['history']), 'Historia konkurenta odtworzona z obu pomiarów.');
	}

	public function test_inactive_competitor_is_hidden_from_positions_but_keeps_history(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$competitor = $this->competitors->create($context, ['domain' => 'konkurent.pl', 'name' => 'Konkurent']);
		$this->measure($context, ['buty damskie' => DataForSeoFakes::serpTop([4 => 'konkurent.pl'])]);

		self::assertSame(4, $this->row($context, 'buty damskie')->competitors[$competitor->publicId]['best']);
		$this->competitors->update($context, $competitor->publicId, ['status' => 'inactive']);
		self::assertSame([], $this->row($context, 'buty damskie')->competitors);
		self::assertSame(4, $this->competitors->detail($context, $competitor->publicId)['rows'][0]['rank'], 'Dane historyczne nie są usuwane.');
	}

	public function test_organic_competitors_aggregate_latest_serps_without_project_domains(): void
	{
		$context = $this->trackedProject(['pierwsza', 'druga', 'trzecia']);
		$configured = $this->competitors->create($context, ['domain' => 'lider.pl', 'name' => 'Lider']);
		$this->measure($context, [
			'pierwsza' => DataForSeoFakes::serpTop([1 => 'lider.pl', 2 => 'example.pl', 4 => 'drugi.pl', 11 => 'lider.pl'], 20),
			'druga' => DataForSeoFakes::serpTop([3 => 'lider.pl', 15 => 'drugi.pl', 16 => 'blog.example.pl'], 20),
			'trzecia' => DataForSeoFakes::serpTop([8 => 'lider.pl'], 20),
		]);

		$data = $this->competitors->organic($context);
		$rows = array_column($data['rows'], null, 'host');

		self::assertSame(3, $data['keywords']);
		self::assertArrayNotHasKey('example.pl', $rows, 'Domeny projektu (z subdomenami) nie są konkurentami.');
		self::assertArrayNotHasKey('blog.example.pl', $rows);
		self::assertSame('lider.pl', $data['rows'][0]['host']);
		self::assertSame(['keywords' => 3, 'top3' => 2, 'top10' => 3, 'top20' => 3, 'avg_rank' => 4.0, 'overlap' => 2, 'urls' => 4], array_intersect_key($rows['lider.pl'], array_flip(['keywords', 'top3', 'top10', 'top20', 'avg_rank', 'overlap', 'urls'])));
		self::assertSame($configured->publicId, $rows['lider.pl']['competitor']->publicId, 'Oznaczenie już monitorowanego konkurenta.');
		self::assertSame(['keywords' => 2, 'top10' => 1, 'avg_rank' => 9.5], array_intersect_key($rows['drugi.pl'], array_flip(['keywords', 'top10', 'avg_rank'])));
		self::assertNull($rows['drugi.pl']['competitor']);
		self::assertSame(count($rows), $data['total']);
		$byAverage = array_column($this->competitors->organic($context, 1, 'avg_rank')['rows'], 'avg_rank');
		$sorted = $byAverage;
		sort($sorted);
		self::assertSame($sorted, $byAverage, 'Sortowanie po średniej pozycji SERP rosnąco.');
	}

	public function test_cached_organic_aggregation_follows_new_measurements_and_keyword_changes(): void
	{
		$cached = new CompetitorService($this->competitorRepository, $this->serpReports, $this->serp, $this->captureLogger(), new ReportCache());
		$context = $this->trackedProject(['pierwsza', 'druga']);
		$this->measure($context, [
			'pierwsza' => DataForSeoFakes::serpTop([1 => 'stary-lider.pl'], 10),
			'druga' => DataForSeoFakes::serpTop([1 => 'stary-lider.pl'], 10),
		]);

		self::assertSame('stary-lider.pl', $cached->organic($context)['rows'][0]['host']);
		self::assertSame('stary-lider.pl', $cached->organic($context)['rows'][0]['host'], 'Kolejne wejście z pamięci podręcznej.');

		$this->measureNextWeek($context, [
			'pierwsza' => DataForSeoFakes::serpTop([1 => 'nowy-lider.pl', 2 => 'nowy-lider.pl'], 10),
			'druga' => DataForSeoFakes::serpTop([1 => 'nowy-lider.pl'], 10),
		]);
		$data = $cached->organic($context);
		self::assertSame('nowy-lider.pl', $data['rows'][0]['host'], 'Nowy pomiar zmienia klucz — bez jawnego unieważniania.');
		self::assertSame(2, $data['keywords']);

		$competitor = $this->competitors->create($context, ['domain' => 'nowy-lider.pl', 'name' => 'Lider']);
		self::assertSame($competitor->publicId, $cached->organic($context)['rows'][0]['competitor']->publicId, 'Oznaczenie konkurentów liczone poza pamięcią podręczną.');

		$this->serp->removeKeywords($context, [$this->row($context, 'druga')->publicId]);
		self::assertSame(1, $cached->organic($context)['rows'][0]['keywords']);
		self::assertSame(1, $cached->organic($context)['keywords']);
	}

	public function test_competitor_of_another_project_is_not_found(): void
	{
		$first = $this->trackedProject([], 'example.pl');
		$second = $this->trackedProject([], 'drugi-projekt.pl');
		$competitor = $this->competitors->create($first, ['domain' => 'konkurent.pl', 'name' => '']);

		$this->expectException(SerpNotFound::class);
		$this->competitors->detail($second, $competitor->publicId);
	}

	public function test_competitor_of_another_project_cannot_be_updated(): void
	{
		$first = $this->trackedProject([], 'example.pl');
		$second = $this->trackedProject([], 'drugi-projekt.pl');
		$competitor = $this->competitors->create($first, ['domain' => 'konkurent.pl', 'name' => '']);

		try {
			$this->competitors->update($second, $competitor->publicId, ['status' => 'archived']);
			self::fail('Konkurent innego projektu.');
		} catch (SerpNotFound) {
		}

		self::assertSame(Competitor::ACTIVE, $this->competitors->detail($first, $competitor->publicId)['competitor']->status);
		self::assertSame([], $this->competitors->list($second));
	}
}
