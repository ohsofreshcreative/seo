<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Support\Clock;

/**
 * Plan przebiegu bez żadnego wywołania API: seedy, żądania (strony) na seed, maksymalna liczba elementów, koszt
 * i ocena wspólnych limitów kosztów. Seed pobrany w projekcie w ciągu TTL z co najmniej tak szerokimi parametrami
 * jest pomijany (koszt 0), chyba że użytkownik wymusi odświeżenie.
 *
 * Limit elementów na seed = limit kandydatów przebiegu / liczba seedów (górna granica: maks. wyników metody, np. 72 dla
 * Related Keywords przy głębokości 2) — suma limitów nie przekracza limitu kandydatów, więc maksymalny koszt jest znany.
 */
final class DiscoveryPlanner
{
	public function __construct(
		private readonly KeywordDiscoveryProvider $provider,
		private readonly DiscoveryRunRepository $runs,
		private readonly DiscoveryConfig $config,
		private readonly MarketSyncService $market,
		private readonly Clock $clock,
	) {
	}

	public function plan(ProjectContext $context, DiscoveryRequest $request): DiscoveryPlan
	{
		$project = $context->project();
		$market = $this->provider->resolveMarket($project->country, $project->language);
		$budget = $this->market->budget()->toArray();
		$ttl = $this->config->ttlDays();
		$seeds = $request->seeds->seeds();

		if ($market === null) {
			return new DiscoveryPlan(null, $request, DiscoveryPlan::UNSUPPORTED_MARKET, [], $request->seeds->rejected, 0, $budget, $ttl);
		}

		if ($seeds === []) {
			return new DiscoveryPlan($market, $request, DiscoveryPlan::NO_SEEDS, [], $request->seeds->rejected, 0, $budget, $ttl);
		}

		$pageSize = $this->provider->maxItemsPerRequest();
		$seedLimit = max(1, min($pageSize * DiscoveryConfig::MAX_PAGES_PER_SEED, intdiv($request->maxCandidates, count($seeds))));
		$items = min($seedLimit, $this->provider->maxResults($request->method, $request->depth) ?? $seedLimit);
		$requests = (int) ceil($items / $pageSize);
		$cost = 0.0;

		for ($page = 0; $page < $requests; $page++) {
			$cost += $this->provider->estimateCost(min($pageSize, $items - $page * $pageSize));
		}

		$cached = $request->force ? [] : $this->runs->cachedSeeds(
			$context->projectId(),
			$market,
			$request,
			$seedLimit,
			$this->clock->now()->modify('-' . $ttl . ' days')->format('Y-m-d H:i:s'),
			$seeds,
		);
		$planned = [];

		foreach ($request->seeds->accepted as $seed => $source) {
			$planned[] = isset($cached[$seed])
				? new PlannedSeed($seed, $source, 0, 0, 0.0, $cached[$seed])
				: new PlannedSeed($seed, $source, $requests, $items, round($cost, 6));
		}

		$skip = $this->provider->isConfigured() ? null : DiscoveryPlan::NOT_CONFIGURED;

		return new DiscoveryPlan($market, $request, $skip, $planned, $request->seeds->rejected, $seedLimit, $budget, $ttl);
	}
}
