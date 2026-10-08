<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Wskazanie strony projektu przez jedną rodzinę dowodów (z podstawą, np. `serp_top20`, `gsc_dominant`).
 */
final class PageVote
{
	/**
	 * @param array<string, int|float|string|null> $detail
	 */
	public function __construct(
		public readonly string $url,
		public readonly EvidenceFamily $family,
		public readonly VoteStrength $strength,
		public readonly string $basis,
		public readonly array $detail = [],
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return ['url' => $this->url, 'family' => $this->family->value, 'strength' => $this->strength->value, 'basis' => $this->basis, 'detail' => $this->detail];
	}
}
