<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Wynik rozpoznania strony docelowej (frazy albo tematu): stan, adres, rodziny dowodów wspierające adres, powody, wszystkie wskazania,
 * alternatywy, dowody braku widoczności, ślady możliwej istniejącej strony i sygnały pochodne (nieliczone jako niezależne).
 */
final class TargetResolution
{
	/**
	 * @param list<string> $families rodziny wspierające adres (co najmniej średnio; ręczne — `manual`)
	 * @param list<string> $reasons
	 * @param list<PageVote> $votes
	 * @param list<array{url: string, families: list<string>, strength: string}> $alternatives
	 * @param list<string> $noVisibility
	 * @param list<array{url: ?string, source: string}> $hints
	 * @param list<array{url: string, source: string}> $derived
	 */
	public function __construct(
		public readonly TargetState $state,
		public readonly ?string $url,
		public readonly bool $home,
		public readonly array $families,
		public readonly array $reasons,
		public readonly array $votes,
		public readonly array $alternatives,
		public readonly array $noVisibility,
		public readonly array $hints,
		public readonly array $derived,
		public readonly bool $manual = false,
		public readonly bool $manualNone = false,
	) {
	}

	/** Jedynym kandydatem jest słabe wskazanie (dopasowanie adresu, odległa pozycja SERP, pojedyncze średnie wskazanie). */
	public function weak(): bool
	{
		return $this->state === TargetState::Unknown && in_array('target_weak', $this->reasons, true);
	}

	/** Możliwa istniejąca strona: słabe wskazania albo ślady w starszych pomiarach / Labs. */
	public function possibleExistingPage(): bool
	{
		return $this->hints !== [] || $this->votes !== [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'state' => $this->state->value,
			'url' => $this->url,
			'home' => $this->home,
			'families' => $this->families,
			'manual' => $this->manual,
			'manual_none' => $this->manualNone,
			'reasons' => $this->reasons,
			'votes' => array_map(static fn (PageVote $vote): array => $vote->toArray(), $this->votes),
			'alternatives' => $this->alternatives,
			'no_visibility' => $this->noVisibility,
			'hints' => $this->hints,
			'derived' => $this->derived,
		];
	}
}
