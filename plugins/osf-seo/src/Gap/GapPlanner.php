<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorRepository;
use OsfSeo\Serp\DomainFamily;
use OsfSeo\Support\Clock;

/**
 * Plan importu bez wywołań API: aktywni konkurenci projektu (wyłącznie skonfigurowani — konkurenci organiczni z SERP
 * nigdy nie uruchamiają płatnego importu), opcjonalnie domena projektu (punkt odniesienia TOP100), stan wspólnych zbiorów
 * domen i budżet. Zbiór świeży o wystarczającym zakresie jest pomijany (bezpłatnie z pamięci), chyba że `force`.
 */
final class GapPlanner
{
	public function __construct(
		private readonly CompetitorKeywordsProvider $provider,
		private readonly GapDomainRepository $domains,
		private readonly CompetitorRepository $competitors,
		private readonly MarketSyncService $market,
		private readonly Clock $clock,
	) {
	}

	public function plan(ProjectContext $context, GapRequest $request): GapPlan
	{
		$project = $context->project();
		$market = $this->provider->resolveMarket($project->country, $project->language);
		$budget = $this->market->budget();
		$prices = [$this->provider->estimateCost(0), round($this->provider->estimateCost(1000) - $this->provider->estimateCost(0), 8) / 1000];
		$empty = static fn (?string $reason, array $skipped = []): GapPlan => new GapPlan(
			$market,
			$reason,
			$request,
			[],
			$skipped,
			$prices[0],
			$prices[1],
			$budget->dailyLimit,
			$budget->spentToday(),
			$budget->monthlyLimit,
			$budget->spentMonth(),
		);

		if ($market === null) {
			return $empty(GapPlan::UNSUPPORTED_MARKET);
		}

		$projectDomain = DomainFamily::normalize($project->domain);
		$selected = $request->competitors === [] ? null : array_flip($request->competitors);
		$candidates = [];
		$skipped = [];

		foreach ($this->competitors->active($context->projectId()) as $competitor) {
			if ($selected !== null && ! isset($selected[$competitor->publicId])) {
				continue;
			}

			$domain = DomainFamily::normalize($competitor->domain);

			if ($domain === null) {
				$skipped[] = ['domain' => $competitor->domain, 'label' => $competitor->name, 'reason' => 'invalid_domain'];

				continue;
			}

			if ($projectDomain !== null && DomainFamily::overlaps($domain, $projectDomain)) {
				$skipped[] = ['domain' => $domain, 'label' => $competitor->name, 'reason' => 'project_domain'];

				continue;
			}

			$candidates[] = [PlannedTarget::ROLE_COMPETITOR, $competitor, $domain, $request->coverage];
		}

		if ($candidates === []) {
			return $empty(GapPlan::NO_COMPETITORS, $skipped);
		}

		if ($request->baseline && $projectDomain !== null) {
			$candidates[] = [
				PlannedTarget::ROLE_PROJECT,
				null,
				$projectDomain,
				new Coverage(GapConfig::BASELINE_MAX_RANK, $request->coverage->minVolume, min($request->coverage->maxRows, $this->provider->maxRowsPerDomain())),
			];
		}

		$datasets = $this->domains->forDomains($market, array_map(static fn (array $candidate): string => $candidate[2], $candidates));
		$now = $this->clock->now()->format('Y-m-d H:i:s');
		$targets = [];
		$planned = [];

		foreach ($candidates as [$role, $competitor, $domain, $coverage]) {
			if (isset($planned[$domain])) {
				$skipped[] = ['domain' => $domain, 'label' => $competitor instanceof Competitor ? $competitor->name : $project->name, 'reason' => 'duplicate_domain'];

				continue;
			}

			$planned[$domain] = true;
			$targets[] = $this->target($role, $competitor, $role === PlannedTarget::ROLE_PROJECT ? $project->name : (string) $competitor?->name, $domain, $coverage, $datasets[$domain] ?? null, $request->force, $now);
		}

		return new GapPlan(
			$market,
			null,
			$request,
			$targets,
			$skipped,
			$prices[0],
			$prices[1],
			$budget->dailyLimit,
			$budget->spentToday(),
			$budget->monthlyLimit,
			$budget->spentMonth(),
		);
	}

	private function target(string $role, ?Competitor $competitor, string $label, string $domain, Coverage $coverage, ?GapDomain $dataset, bool $force, string $now): PlannedTarget
	{
		$state = match (true) {
			$dataset !== null && $dataset->status === GapDomain::IMPORTING => PlannedTarget::WAITING,
			! $force && $dataset !== null && $dataset->satisfies($coverage, $now) => PlannedTarget::CACHED,
			default => PlannedTarget::IMPORT,
		};
		$known = $dataset !== null && $dataset->totalCount !== null && $dataset->coverage !== null && $dataset->coverage->sameFilters($coverage)
			? $dataset->totalCount
			: null;
		$maxRows = min($coverage->maxRows, $this->provider->maxRowsPerDomain());
		$maxRequests = ImportPages::requests($maxRows, $this->provider->maxItemsPerRequest());
		$expectedRows = $known === null ? $maxRows : min($known, $maxRows);
		$expectedRequests = ImportPages::requests($expectedRows, $this->provider->maxItemsPerRequest());
		$request = $this->provider->estimateCost(0);
		$import = $state === PlannedTarget::IMPORT;

		return new PlannedTarget(
			role: $role,
			competitorId: $competitor?->id,
			competitorPublicId: $competitor?->publicId,
			label: $label,
			domain: $domain,
			coverage: $coverage,
			state: $state,
			dataset: $dataset,
			knownTotal: $known,
			maxRequests: $import ? $maxRequests : 0,
			maxCost: $import ? round($maxRequests * $request + ($this->provider->estimateCost($maxRows) - $request), 6) : 0.0,
			expectedRequests: $import ? $expectedRequests : 0,
			expectedCost: $import ? round($expectedRequests * $request + ($this->provider->estimateCost($expectedRows) - $request), 6) : 0.0,
		);
	}
}
