<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Support\Clock;

/**
 * Plan pomiaru bez wywołań API (podgląd kosztu w panelu, `wp osf-seo serp:plan`, kontrola przed zakolejkowaniem).
 * Pomija frazy, których pomiar zlecono w ciągu `OSF_SEO_SERP_MIN_RECHECK_HOURS` (nakładanie się crona i pomiarów ręcznych).
 */
final class SerpPlanner
{
	public function __construct(
		private readonly SerpProvider $provider,
		private readonly TrackedKeywordRepository $tracked,
		private readonly SerpConfig $config,
		private readonly MarketSyncService $market,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @param list<string>|null $only ULID-y monitorowanych fraz (pomiar wybranych); null — wszystkie aktywne
	 */
	public function plan(ProjectContext $context, SerpSettings $settings, ?array $only = null, ?SerpFrequency $frequency = null, ?SerpDevice $device = null, ?int $depth = null): SerpPlan
	{
		$project = $context->project();
		$market = $this->provider->resolveMarket($project->country, $project->language);
		$budget = $this->market->budget()->toArray();
		$frequency ??= $settings->frequency;
		$serpContext = $market === null ? null : SerpContext::forMarket($market, $device ?? $settings->device, $depth ?? $settings->depth);
		$costPerTask = $serpContext === null ? 0.0 : $this->provider->estimateCost($serpContext);
		$total = $this->tracked->activeCount($context->projectId());

		$skip = match (true) {
			! $this->provider->isConfigured() => SerpPlan::NOT_CONFIGURED,
			$market === null => SerpPlan::UNSUPPORTED_MARKET,
			$total === 0 => SerpPlan::NO_KEYWORDS,
			default => null,
		};

		if ($skip === SerpPlan::UNSUPPORTED_MARKET || $skip === SerpPlan::NO_KEYWORDS) {
			return new SerpPlan($market, $serpContext, $frequency, $skip, [], $total, 0, $costPerTask, $this->provider->maxTasksPerPost(), $budget);
		}

		$selection = $this->tracked->eligible($context->projectId(), $this->cutoff(), $only);
		$skip ??= $selection['eligible'] === [] ? SerpPlan::NOTHING_TO_DO : null;

		return new SerpPlan($market, $serpContext, $frequency, $skip, $selection['eligible'], $total, $selection['recent'], $costPerTask, $this->provider->maxTasksPerPost(), $budget);
	}

	/** Frazy zlecone po tej chwili nie są sprawdzane ponownie. */
	public function cutoff(): string
	{
		return $this->clock->now()->modify('-' . $this->config->minRecheckHours() . ' hours')->format('Y-m-d H:i:s');
	}
}
