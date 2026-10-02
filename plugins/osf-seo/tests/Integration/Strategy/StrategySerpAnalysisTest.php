<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\Roles;
use OsfSeo\Market\MarketDataConfig;
use OsfSeo\Market\ProviderErrorCategory;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpSubmitter;
use OsfSeo\Strategy\Serp\SerpAnalysisPlan;
use OsfSeo\Strategy\Serp\SerpAnalysisService;
use OsfSeo\Strategy\Serp\SerpFreshness;
use OsfSeo\Strategy\StrategyConfig;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Support\ValidationException;
use OsfSeo\Tests\Support\DataForSeoFakes;

/**
 * SERP Intelligence i jednorazowa analiza SERP Strategii (STEP 16, faza B): kwalifikacja i podgląd (ponowne użycie pomiarów,
 * szacowany maksymalny koszt), pomiary `analysis` przez infrastrukturę STEP 14 (atrapa dostawcy), rozdzielenie od monitorowania,
 * przejście do monitorowania bez utraty historii, wspólne limity, odstęp, blokady i stan `uncertain`, overlap i izolacja projektów.
 */
final class StrategySerpAnalysisTest extends StrategyTestCase
{
	public function test_plan_reuses_fresh_compatible_measurements_and_estimates_max_cost_without_requests_or_writes(): void
	{
		$context = $this->trackedProject(['buty damskie']);
		$this->measure($context, ['buty damskie' => self::page('buty-damskie', [4 => 'example.pl'])]);
		$this->strategy->addKeywords($context, ['buty damskie', 'kozaki', 'botki']);
		$this->gscKeyword($context, 'site:example.pl buty', 200, 9.0);
		$this->strategy->refresh($context);
		$requests = count($this->dataForSeoRequests());
		$counts = $this->counts();

		$plan = $this->analysis->plan($context);

		self::assertNull($plan->skipReason);
		self::assertSame([
			'buty damskie' => ['reuse', 'fresh_measurement'],
			'kozaki' => ['measure', 'no_measurement'],
			'botki' => ['measure', 'no_measurement'],
			'site:example.pl buty' => ['rejected', 'keyword_search_operator'],
		], self::actions($plan), 'Kolejność Strategii: wpisy ręczne, potem GSC.');
		self::assertSame([2, self::TOP100_COST, round(2 * self::TOP100_COST, 6)], [$plan->tasks(), $plan->costPerTask, $plan->estimatedCost()]);
		self::assertSame(['desktop', 100], [$plan->context?->device->value, $plan->context?->depth], 'Kontekst pomiarów projektu.');
		self::assertSame(1, $plan->toArray()['reuse']);
		self::assertSame($requests, count($this->dataForSeoRequests()), 'Podgląd bez żądań.');
		self::assertSame($counts, $this->counts(), 'Podgląd bez zapisu (frazy monitorowane, przebiegi, koszty).');

		$explicit = $this->analysis->plan($context, ['kozaki', 'nieznana fraza']);
		self::assertSame(['nieznana fraza' => ['rejected', 'not_candidate'], 'kozaki' => ['measure', 'no_measurement']], self::actions($explicit));
	}

	public function test_analysis_is_measured_through_step14_and_stays_out_of_monitoring_but_is_gap_evidence(): void
	{
		$context = $this->gapProject();
		$this->serp->addKeywords($context, 'manual', ['buty damskie']);
		$this->strategy->addKeywords($context, ['kozaki', 'botki']);
		$this->strategy->refresh($context);

		$run = $this->analyze($context, ['kozaki', 'botki'], [
			'kozaki' => self::page('kozaki', [6 => 'example.pl']),
			'botki' => self::page('botki', []),
		]);

		self::assertSame(['analysis', SerpRun::COMPLETED, 2, 2], [$run->trigger, $run->status, $run->tasksSubmitted, $run->tasksCompleted]);
		$db = self::db();
		self::assertSame(['strategy', 'analysis', '6'], array_values($db->fetchRow(
			"SELECT t.source, t.status, t.last_rank FROM `{$db->table('serp_tracked_keywords')}` t JOIN `{$db->table('market_keywords')}` m ON m.id = t.market_keyword_id WHERE t.project_id = %d AND m.keyword = 'kozaki'",
			[$context->projectId()],
		) ?? []));
		self::assertSame([['serp_analysis', 'google_organic_serp', '2']], array_map('array_values', $db->fetchAll(
			"SELECT trigger_type, endpoint, keywords_count FROM `{$db->table('market_tasks')}` WHERE project_id = %d",
			[$context->projectId()],
		)), 'Jedno zlecenie we wspólnym rejestrze kosztów.');
		self::assertSame(2, $this->tasks->usageByPurpose('2026-01-01 00:00:00', $context->projectId())['serp']['tasks'], 'Koszt liczony jako Pozycje SERP (wspólne limity).');

		// Poza monitorowaniem: lista i liczniki Pozycji, miękki limit, harmonogram.
		self::assertSame(['buty damskie'], array_map(static fn ($row): string => $row->keyword, $this->serp->positions($context, PositionsFilters::fromInput([]))['rows']));
		self::assertSame(1, $this->serp->summary($context)['tracked']);
		self::assertSame(1, $this->tracked->activeCount($context->projectId()));
		$this->clock->advance(7 * 3600);
		self::assertSame(['buty damskie'], array_column($this->serp->plan($context)->keywords, 'keyword'), 'Harmonogram i „Sprawdź pozycje teraz” nie mierzą fraz analizy.');

		// Dowody Strategii: SERP Intelligence ze świeżego pomiaru; fraza analizy nie staje się źródłem „Pozycje”.
		$this->strategy->refresh($context);
		$boots = $this->strategy->keyword($context, 'kozaki');
		self::assertSame(['manual'], array_map(static fn ($source): string => $source->value, $boots->sources));
		$intel = $boots->evidence['serp']['intel'];
		self::assertSame([SerpFreshness::FRESH, 'analysis', ['found' => true, 'rank' => 6, 'url' => 'https://example.pl/kozaki/', 'featured' => false]], [$intel['freshness'], $intel['tracking'], $intel['project']]);
		self::assertSame(['subpage', 10, 10], [$intel['profile']['shape'], $intel['profile']['top10']['organic'], $intel['profile']['top10']['domains']]);
		self::assertStringNotContainsString('snapshot_id', (string) json_encode($boots->evidence), 'W dowodach tylko publiczne identyfikatory.');
		self::assertSame(2, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('serp_snapshot_profiles')}` WHERE project_id = %d", [$context->projectId()]), 'Profile zapisane raz, przy przeliczeniu.');

		// Pomiar analizy jest dowodem widoczności dla Luk SEO (świeży SERP przed GSC i Labs).
		$this->setRanked('konkurent.pl', [self::ranked('konkurent.pl', 'kozaki', 900, 2), self::ranked('konkurent.pl', 'botki', 500, 3)]);
		$this->gapRun($context, ['baseline' => '0']);
		self::assertSame(['visible', 'serp', '6'], [$this->gap($context, 'kozaki')['visibility'], $this->gap($context, 'kozaki')['visibility_source'], $this->gap($context, 'kozaki')['serp_rank']]);
		self::assertSame(['none', 'serp', 'missing'], [$this->gap($context, 'botki')['visibility'], $this->gap($context, 'botki')['visibility_source'], $this->gap($context, 'botki')['gap_type']]);

		// Miękki limit monitorowanych fraz: frazy analizy go nie zajmują, ale przejście do monitorowania się do niego liczy.
		putenv(SerpConfig::MAX_KEYWORDS . '=1');

		try {
			$this->serp->addKeywords($context, 'manual', ['kozaki']);
			self::fail('Przejście do monitorowania ponad limit.');
		} catch (ValidationException $exception) {
			self::assertStringContainsString('Limit monitorowanych fraz w projekcie: 1 (obecnie 1)', $exception->errors()['keywords']);
		}

		self::assertSame(2, $this->trackedCount($context, 'analysis'), 'Bez zmian po odrzuceniu.');
	}

	public function test_analysis_keyword_moves_to_active_monitoring_without_losing_history(): void
	{
		$context = $this->gapProject();
		$this->strategy->addKeywords($context, ['kozaki']);
		$this->strategy->refresh($context);
		$this->analyze($context, ['kozaki'], ['kozaki' => self::page('kozaki', [9 => 'example.pl'])]);
		$db = self::db();
		$before = $db->fetchRow("SELECT id, public_id, last_snapshot_id FROM `{$db->table('serp_tracked_keywords')}` WHERE project_id = %d", [$context->projectId()]) ?? [];

		$result = $this->serp->addKeywords($context, 'manual', ['kozaki']);

		self::assertSame(['added' => 0, 'restored' => 1, 'existing' => 0, 'rejected' => []], $result);
		$after = $db->fetchRow("SELECT id, public_id, last_snapshot_id, status, source FROM `{$db->table('serp_tracked_keywords')}` WHERE project_id = %d", [$context->projectId()]) ?? [];
		self::assertSame([$before['id'], $before['public_id'], $before['last_snapshot_id'], 'active', 'strategy'], array_values($after), 'Ta sama fraza, ten sam pomiar; źródło zostaje.');
		self::assertSame(1, $this->serp->positions($context, PositionsFilters::fromInput([]))['total']);

		$this->measureNextWeek($context, ['kozaki' => self::page('kozaki', [4 => 'example.pl'])]);

		$row = $this->row($context, 'kozaki');
		self::assertSame(['up', 5, 9], [$row->changeType, $row->changeValue, $row->previousRank], 'Zmiana względem pomiaru analizy (ten sam kontekst).');
		self::assertSame(2, (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('serp_snapshots')}` WHERE tracked_keyword_id = %d AND status = 'completed'", [$before['id']]));
	}

	public function test_shared_cooldown_budget_reservation_lock_pause_and_uncertain_state(): void
	{
		$context = $this->gapProject();
		$this->serp->addKeywords($context, 'manual', ['buty damskie']);
		$this->strategy->addKeywords($context, ['kozaki']);
		$this->strategy->refresh($context);
		$this->mockSerpPost();

		// Odstęp pomiaru ręcznego wspólny z „Sprawdź pozycje teraz” (CLI bez odstępu).
		self::assertSame(SerpStartResult::QUEUED, $this->serp->start($context)->status);
		$plan = $this->analysis->plan($context, ['kozaki']);
		self::assertTrue($plan->coolingDown);
		self::assertSame(SerpStartResult::RATE_LIMITED, $this->analysis->start($context, ['kozaki'], $plan->tasks(), $plan->estimatedCost())['status']);
		self::assertSame(0, $this->trackedCount($context, 'analysis'), 'Odrzucona próba niczego nie zapisuje.');
		delete_transient('osf_seo_serp_manual_' . $context->projectId());

		// Wspólny limit kosztów: rezerwacja pomiaru Pozycji liczy się do limitu analizy (cała analiza albo wcale).
		putenv(MarketDataConfig::DAILY_COST_LIMIT . '=' . (self::TOP100_COST + 0.001));
		$blocked = $this->analysis->plan($context, ['kozaki']);
		self::assertSame('daily_limit', $blocked->blockedBy());
		self::assertSame([SerpStartResult::OVER_BUDGET, 'daily_limit'], array_values(array_intersect_key($this->analysis->start($context, ['kozaki'], 1, $blocked->estimatedCost(), SerpAnalysisService::TRIGGER_CLI), ['status' => 0, 'reason' => 0])));
		self::assertSame(0, $this->trackedCount($context, 'analysis'));
		putenv(MarketDataConfig::DAILY_COST_LIMIT);

		// Plan droższy niż potwierdzony podgląd nie zostanie zakolejkowany.
		self::assertSame(SerpStartResult::PLAN_CHANGED, $this->analysis->start($context, ['kozaki'], 0, 0.0, SerpAnalysisService::TRIGGER_CLI)['status']);

		// Wstrzymanie płatnych wywołań po błędzie konta (wszystkie moduły).
		$this->market->pause(ProviderErrorCategory::Billing);
		self::assertSame(SerpStartResult::PAUSED, $this->analysis->start($context, ['kozaki'], 1, self::TOP100_COST, SerpAnalysisService::TRIGGER_CLI)['status']);
		$this->resetMarketOptions();

		// Blokada rezerwacji zajęta przez inny proces — nic nie zostaje po próbie.
		$other = $this->holdLock(SerpSubmitter::RESERVE_LOCK);

		try {
			self::assertSame(SerpStartResult::LOCKED, $this->analysis->start($context, ['kozaki'], 1, self::TOP100_COST, SerpAnalysisService::TRIGGER_CLI)['status']);
		} finally {
			$this->releaseHeldLock($other, SerpSubmitter::RESERVE_LOCK);
		}

		self::assertSame(0, $this->trackedCount($context, 'analysis') + $this->trackedCount($context, 'removed'), 'Wycofane przygotowanie analizy.');

		// Wynik nieznany (sieć): bez ponawiania; fraza „w toku” w oknie ponownego sprawdzenia — bez nowego kosztu.
		$this->google->always(self::SERP_POST, new \WP_Error('http_request_failed', 'cURL error 28: timeout'));
		$started = $this->analysis->start($context, ['kozaki'], 1, self::TOP100_COST, SerpAnalysisService::TRIGGER_CLI);
		self::assertSame(SerpStartResult::QUEUED, $started['status']);
		$report = $this->serp->execute($context, $started['run'] ?? throw new \RuntimeException('No run'));
		self::assertSame(['network', 1], [$report['stopped'], $report['uncertain']]);
		$db = self::db();
		self::assertSame(['uncertain'], array_column($db->fetchAll("SELECT status FROM `{$db->table('serp_snapshots')}` WHERE run_id = %d", [$started['run']->id]), 'status'));
		$posts = count($this->google->requestsTo(self::SERP_POST));
		$this->serp->execute($context, $started['run']);
		self::assertSame($posts, count($this->google->requestsTo(self::SERP_POST)), 'Niepewne zlecenie nie jest ponawiane.');
		$again = $this->analysis->plan($context, ['kozaki']);
		self::assertSame(['kozaki' => ['pending', 'requested_recently']], self::actions($again));
		self::assertSame(SerpAnalysisPlan::NOTHING_TO_DO, $again->skipReason);
	}

	public function test_run_limit_is_never_silently_truncated_and_priority_selection_respects_it(): void
	{
		putenv(StrategyConfig::SERP_MAX_PER_RUN . '=2');
		$this->buildStrategy();
		$context = $this->gapProject();
		$this->strategy->addKeywords($context, ['kozaki', 'botki', 'sandały']);
		$this->gscKeyword($context, 'buty zimowe', 500, 7.0);
		$this->strategy->refresh($context);

		$explicit = $this->analysis->plan($context, ['kozaki', 'botki', 'sandały']);
		self::assertSame([SerpAnalysisPlan::OVER_RUN_LIMIT, 3, 2], [$explicit->skipReason, $explicit->tasks(), $explicit->limit]);
		self::assertSame(SerpAnalysisPlan::OVER_RUN_LIMIT, $this->analysis->start($context, ['kozaki', 'botki', 'sandały'], 3, 1.0, SerpAnalysisService::TRIGGER_CLI)['status']);

		$priority = $this->analysis->plan($context);
		self::assertNull($priority->skipReason);
		self::assertSame(2, $priority->tasks());
		self::assertSame(['measure', 'measure'], array_values(array_map(static fn (array $action): string => $action[0], self::actions($priority))), 'Najwyżej limit nowych pomiarów — wpisy ręczne przed frazami GSC.');
		self::assertNotContains('buty zimowe', array_keys(self::actions($priority)));
	}

	public function test_overlap_safeguards_for_ubiquitous_domains_intents_freshness_and_contexts(): void
	{
		$others = ['sandały', 'klapki', 'trampki', 'mokasyny', 'baleriny', 'szpilki', 'kalosze'];
		$context = $this->trackedProject(['buty damskie', 'obuwie damskie', 'kozaki damskie', ...$others]);
		$shared = ['https://ccc.eu/pl/buty/', 'https://eobuwie.pl/buty/', 'https://deichmann.com/pl/buty/', 'https://zalando.pl/buty/'];
		$pages = [
			'buty damskie' => self::urls(['https://allegro.pl/kategoria/buty', ...$shared, 'https://example.pl/buty/']),
			'obuwie damskie' => self::urls(['https://allegro.pl/kategoria/buty', ...$shared]),
			'kozaki damskie' => self::urls(['https://allegro.pl/kategoria/buty', $shared[0], $shared[1], 'https://zalando.pl/']),
		];

		foreach ($others as $keyword) {
			$pages[$keyword] = self::urls(['https://allegro.pl/kategoria/' . str_replace(' ', '-', $keyword)]);
		}

		$this->measure($context, $pages);
		$this->strategy->refresh($context);

		$strong = $this->strategy->serpOverlap($context, 'buty damskie', 'obuwie damskie');
		self::assertSame(['strong', true, 5, 4, 1], [$strong['level'], $strong['mergeable'], $strong['shared_urls'], $strong['counted_urls'], $strong['discounted_urls']]);
		self::assertSame(['known' => true, 'measurements' => 10, 'threshold' => 4, 'domains' => ['allegro.pl']], $strong['ubiquitous'], 'Domena w TOP10 wszystkich pomiarów projektu — konkurenci tematyczni (3 z 10) nie.');
		self::assertSame([['url' => 'https://allegro.pl/kategoria/buty', 'rank_a' => 1, 'rank_b' => 1, 'counted' => false]], array_values(array_filter($strong['urls'], static fn (array $url): bool => ! $url['counted'])), 'Wspólna kategoria domeny wszechobecnej — odliczona.');

		$weak = $this->strategy->serpOverlap($context, 'buty damskie', 'kozaki damskie');
		self::assertSame(['moderate', 2, 1], [$weak['level'], $weak['counted_urls'], $weak['discounted_urls']], 'Strona główna i domena wszechobecna nie wchodzą do progu.');

		// Sprzeczne intencje dostawcy — najwyżej umiarkowany (bez automatycznego scalania).
		$db = self::db();
		$db->execute("UPDATE `{$db->table('market_keywords')}` SET search_intent = 'commercial' WHERE keyword = 'buty damskie'");
		$db->execute("UPDATE `{$db->table('market_keywords')}` SET search_intent = 'informational' WHERE keyword = 'obuwie damskie'");
		$mismatch = $this->strategy->serpOverlap($context, 'buty damskie', 'obuwie damskie');
		self::assertSame(['moderate', false], [$mismatch['level'], $mismatch['mergeable']]);
		self::assertContains('provider_intent_mismatch', $mismatch['reasons']);
		$db->execute("UPDATE `{$db->table('market_keywords')}` SET search_intent = NULL WHERE keyword IN ('buty damskie', 'obuwie damskie')");

		// Nieaktualne pomiary (31–90 dni): najwyżej umiarkowany, bez rozpoznania domen wszechobecnych, bez Pozycji SERP projektu.
		$this->clock->advance(31 * 86400);
		$stale = $this->strategy->serpOverlap($context, 'buty damskie', 'obuwie damskie');
		self::assertSame(['moderate', ['ubiquity_unknown', 'stale_measurement']], [$stale['level'], $stale['reasons']]);
		$detail = $this->strategy->serp($context, 'buty damskie');
		self::assertSame([SerpFreshness::STALE, null], [$detail['freshness'], $detail['project']]);
		self::assertSame(['https://example.pl/buty/', true], [$detail['results'][5]['url'], $detail['results'][5]['project']]);

		// Wygasłe (> 90 dni) — nieporównywalne.
		$this->clock->advance(60 * 86400);
		$expired = $this->strategy->serpOverlap($context, 'buty damskie', 'obuwie damskie');
		self::assertSame(['incomparable', ['expired_measurement']], [$expired['level'], $expired['reasons']]);

		// Inny kontekst pomiarów projektu (urządzenie) — pomiary desktop nie są zgodne.
		$this->serp->saveSettings($context, ['enabled' => '0', 'frequency' => 'weekly', 'device' => 'mobile', 'depth' => '100']);
		$mobile = $this->strategy->serpOverlap($context, 'buty damskie', 'obuwie damskie');
		self::assertSame(['incomparable', ['no_measurement']], [$mobile['level'], $mobile['reasons']]);
		self::assertNull($this->strategy->serp($context, 'buty damskie'));
	}

	public function test_projects_are_isolated_and_analysis_requires_both_capabilities(): void
	{
		$first = $this->trackedProject(['buty damskie']);
		$this->measure($first, ['buty damskie' => self::page('buty-damskie', [2 => 'example.pl'])]);
		$this->strategy->refresh($first);
		$publicId = $this->strategy->keyword($first, 'buty damskie')->publicId;
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');
		$this->strategy->addKeywords($second, ['buty damskie']);
		$this->strategy->refresh($second);

		// Pomiary są per projekt: ten sam rynek i fraza, ale pomiar innego projektu nie jest użyty.
		self::assertSame(['buty damskie' => ['measure', 'no_measurement']], self::actions($this->analysis->plan($second, ['buty damskie'])));
		self::assertSame([$publicId => ['rejected', 'not_candidate']], self::actions($this->analysis->plan($second, [$publicId])));
		self::assertNull($this->strategy->serp($second, 'buty damskie'));

		foreach ([fn () => $this->strategy->serp($second, $publicId), fn () => $this->strategy->serpOverlap($second, $publicId, 'buty damskie')] as $call) {
			try {
				$call();
				self::fail('Kandydat innego projektu.');
			} catch (StrategyNotFound) {
			}
		}

		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($first, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($first->publicId(), $client);
		self::assertSame('fresh', $this->strategy->serp($clientContext, 'buty damskie')['freshness'], 'Odczyt SERP Intelligence bez kosztów.');

		add_role('osf_seo_test_strategist', 'Strateg', ['read' => true, Capabilities::ACCESS => true, Capabilities::MANAGE_STRATEGY => true]);

		try {
			$strategist = $this->createUser('osf_seo_test_strategist');
			$this->service->assignUser($first, $strategist, ProjectRole::Manager);
			$strategistContext = $this->guard->authorize($first->publicId(), $strategist);

			foreach ([
				'klient: plan' => fn () => $this->analysis->plan($clientContext),
				'klient: start' => fn () => $this->analysis->start($clientContext, null, 1, 1.0),
				'bez osf_seo_manage_serp_tracking: plan' => fn () => $this->analysis->plan($strategistContext),
				'bez osf_seo_manage_serp_tracking: start' => fn () => $this->analysis->start($strategistContext, null, 1, 1.0),
			] as $label => $call) {
				try {
					$call();
					self::fail($label);
				} catch (AccessDenied) {
				}
			}
		} finally {
			remove_role('osf_seo_test_strategist');
		}

		self::assertSame(0, $this->trackedCount($first, 'analysis'));
	}

	/**
	 * Analiza z CLI: plan, zakolejkowanie (bez odstępu), wysyłka w bieżącym procesie i odbiór wyników po 10 minutach.
	 *
	 * @param list<string>|null $values
	 * @param array<string, list<array<string, mixed>>> $byKeyword
	 */
	private function analyze(ProjectContext $context, ?array $values, array $byKeyword): SerpRun
	{
		$this->mockSerpPost();
		$plan = $this->analysis->plan($context, $values);
		$result = $this->analysis->start($context, $values, $plan->tasks(), $plan->estimatedCost(), SerpAnalysisService::TRIGGER_CLI);
		self::assertSame(SerpStartResult::QUEUED, $result['status'], 'Analiza zakolejkowana: ' . $result['status'] . ' ' . ($result['reason'] ?? ''));
		$run = $result['run'] ?? throw new \RuntimeException('No run');
		$this->serp->execute($context, $run);
		$this->clock->advance(600);
		$this->mockSerpResults($byKeyword);
		$this->serp->collect(60.0);

		return $this->serpRuns->findById($run->id) ?? throw new \RuntimeException('No run');
	}

	/**
	 * TOP20 z podstronami (`/{slug}/`) domen syntetycznych, z wybranymi domenami na pozycjach.
	 *
	 * @param array<int, string> $domains pozycja → domena
	 * @return list<array<string, mixed>>
	 */
	private static function page(string $slug, array $domains): array
	{
		$items = [];

		for ($rank = 1; $rank <= 20; $rank++) {
			$domain = $domains[$rank] ?? 'wynik-' . $rank . '-' . $slug . '.example';
			$items[] = DataForSeoFakes::serpOrganic($rank, $domain, 'https://' . $domain . '/' . $slug . '/');
		}

		return $items;
	}

	/**
	 * TOP20: podane adresy na pierwszych pozycjach, reszta — unikalne adresy syntetyczne.
	 *
	 * @param list<string> $urls
	 * @return list<array<string, mixed>>
	 */
	private static function urls(array $urls): array
	{
		$items = [];

		for ($rank = 1; $rank <= 20; $rank++) {
			$url = $urls[$rank - 1] ?? 'https://unikalny-' . md5(implode('|', $urls) . $rank) . '.example/strona/';
			$items[] = DataForSeoFakes::serpOrganic($rank, (string) parse_url($url, PHP_URL_HOST), $url);
		}

		return $items;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function actions(SerpAnalysisPlan $plan, string $key = 'keyword'): array
	{
		$result = [];

		foreach ($plan->items as $item) {
			$result[(string) $item[$key]] = [$item['action'], $item['reason']];
		}

		return $result;
	}

	private function trackedCount(ProjectContext $context, string $status): int
	{
		$db = self::db();

		return (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table('serp_tracked_keywords')}` WHERE project_id = %d AND status = %s", [$context->projectId(), $status]);
	}

	/**
	 * @return array<string, int>
	 */
	private function counts(): array
	{
		$db = self::db();
		$result = [];

		foreach (['serp_tracked_keywords', 'serp_runs', 'serp_snapshots', 'market_tasks', 'serp_snapshot_profiles'] as $table) {
			$result[$table] = (int) $db->fetchValue("SELECT COUNT(*) FROM `{$db->table($table)}`");
		}

		return $result;
	}
}
