<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Strategy\CandidateSource;
use OsfSeo\Strategy\SignalBatch;
use OsfSeo\Strategy\SourceScope;
use OsfSeo\Strategy\StrategySource;

/**
 * Źródło testowe: deleguje do prawdziwego źródła i przed zbieraniem sygnałów wywołuje hak (np. zapis danych modułu w trakcie
 * przeliczenia albo wyjątek) — ten sam kod źródła, odcisk i dowody.
 */
final class HookedSource implements CandidateSource
{
	public function __construct(
		private readonly CandidateSource $inner,
		private readonly \Closure $hook,
	) {
	}

	public function source(): StrategySource
	{
		return $this->inner->source();
	}

	public function fingerprint(SourceScope $scope): string
	{
		return $this->inner->fingerprint($scope);
	}

	public function signals(SourceScope $scope): SignalBatch
	{
		if (! $scope->dryRun) {
			($this->hook)();
		}

		return $this->inner->signals($scope);
	}

	public function evidence(SourceScope $scope, array $keys, array $collected): array
	{
		return $this->inner->evidence($scope, $keys, $collected);
	}
}
