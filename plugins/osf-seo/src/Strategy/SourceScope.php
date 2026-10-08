<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Market\Market;

/**
 * Zakres przeliczenia Strategii jednego projektu: rynek (tożsamość fraz), domena i nazwa (marka), okno GSC i konfiguracja.
 * Adaptery źródeł czytają wyłącznie dane tego projektu i frazy tego rynku.
 */
final class SourceScope
{
	/**
	 * @param array{0: string, 1: string}|null $window okno GSC [początek, koniec] (daty GSC) albo null — projekt bez danych GSC
	 */
	public function __construct(
		public readonly int $projectId,
		public readonly Market $market,
		public readonly ?string $projectDomain,
		public readonly string $projectName,
		public readonly ?array $window,
		public readonly StrategyConfig $config,
		/** Podgląd: żadnych zapisów (bez nowych wierszy rynkowych i słownika adresów). */
		public readonly bool $dryRun = false,
	) {
	}

	public function hasGsc(): bool
	{
		return $this->window !== null;
	}
}
