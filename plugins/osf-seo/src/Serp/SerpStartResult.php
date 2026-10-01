<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Wynik próby zakolejkowania pomiaru (bez wywołania API).
 */
final class SerpStartResult
{
	public const QUEUED = 'queued';

	public const NOTHING_TO_DO = 'nothing_to_do';

	public const PLAN_CHANGED = 'plan_changed';

	public const RATE_LIMITED = 'rate_limited';

	public const NOT_CONFIGURED = 'not_configured';

	public const UNSUPPORTED_MARKET = 'unsupported_market';

	public const NO_KEYWORDS = 'no_keywords';

	public const OVER_BUDGET = 'over_budget';

	public const PAUSED = 'paused';

	public const LOCKED = 'locked';

	public function __construct(
		public readonly string $status,
		public readonly ?SerpPlan $plan = null,
		public readonly ?SerpRun $run = null,
		public readonly ?string $reason = null,
	) {
	}

	public function queued(): bool
	{
		return $this->status === self::QUEUED;
	}

	public function message(): string
	{
		return match ($this->status) {
			self::QUEUED => 'Pomiar zakolejkowany — wyniki pojawią się w tle (zwykle w ciągu kilku do kilkudziesięciu minut).',
			self::NOTHING_TO_DO => 'Brak fraz do sprawdzenia — wszystkie były sprawdzane niedawno.',
			self::PLAN_CHANGED => 'Plan się zmienił (więcej zadań albo wyższy koszt niż w podglądzie). Sprawdź koszt ponownie.',
			self::RATE_LIMITED => 'Pomiar ręczny był uruchomiony przed chwilą. Spróbuj ponownie za kilka minut.',
			self::NOT_CONFIGURED => 'DataForSEO nie jest skonfigurowane.',
			self::UNSUPPORTED_MARKET => 'Rynek projektu nie jest obsługiwany.',
			self::NO_KEYWORDS => 'Projekt nie ma monitorowanych fraz.',
			self::OVER_BUDGET => 'Pomiar przekracza wspólny limit kosztów DataForSEO (' . SerpRun::skipLabel($this->reason) . ').',
			self::PAUSED => 'Płatne wywołania DataForSEO są wstrzymane po błędzie konta.',
			self::LOCKED => 'Inny pomiar jest właśnie planowany. Spróbuj ponownie za chwilę.',
			default => $this->status,
		};
	}
}
