<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

use OsfSeo\Opportunities\OpportunityType;
use OsfSeo\Strategy\Serp\ResultShape;
use OsfSeo\Strategy\Serp\SerpIntentSignal;
use OsfSeo\Strategy\Target\TargetResolution;
use OsfSeo\Strategy\Topics\ConflictSignal;
use OsfSeo\Strategy\Topics\KeywordSignals;

/**
 * Fakty tematu dla klasyfikatora, pewności i priorytetu (czysta logika): lider i członkowie, strona docelowa tematu, sygnały konfliktu URL
 * i stan danych projektu. Pozycja SERP wyłącznie ze świeżego pomiaru (pierwszy członek ze świeżym pomiarem, zaczynając od lidera).
 */
final class TopicFacts
{
	/** Kształty wyników oznaczające dedykowane strony (nie strona główna, nie wideo/nieznany). */
	private const DEDICATED_SHAPES = [ResultShape::Subpage, ResultShape::Article, ResultShape::Listing, ResultShape::Product];

	/** Intencje porównywalne między dostawcą a SERP (komercyjna i transakcyjna — jedna rodzina). */
	private const INTENT_FAMILIES = ['informational' => 'informational', 'commercial' => 'commercial', 'transactional' => 'commercial', 'navigational' => 'navigational'];

	/**
	 * @param list<KeywordSignals> $members lider pierwszy
	 * @param list<ConflictSignal> $conflicts
	 */
	public function __construct(
		public readonly KeywordSignals $leader,
		public readonly array $members,
		public readonly TargetResolution $target,
		public readonly array $conflicts,
		public readonly bool $gscComplete,
		public readonly bool $pageIndexComplete = false,
	) {
	}

	/** Projekt ma dane GSC w oknie. */
	public function gscKnown(): bool
	{
		return $this->leader->gscKnown();
	}

	/** Suma znanych wolumenów (null — żadna fraza nie ma wolumenu; brak ≠ 0). */
	public function demand(): ?int
	{
		$known = array_filter(array_map(static fn (KeywordSignals $member): ?int => $member->volume, $this->members), static fn (?int $volume): bool => $volume !== null);

		return $known === [] ? null : (int) array_sum($known);
	}

	public function gscImpressions(): ?int
	{
		return $this->gscKnown() ? (int) array_sum(array_map(static fn (KeywordSignals $member): int => (int) $member->gscImpressions(), $this->members)) : null;
	}

	public function gscClicks(): ?int
	{
		return $this->gscKnown() ? (int) array_sum(array_map(static fn (KeywordSignals $member): int => (int) $member->gscClicks(), $this->members)) : null;
	}

	/** Średnia pozycja (GSC) tematu ważona wyświetleniami (`Σ pozycja × wyświetlenia / Σ wyświetlenia`). */
	public function gscPosition(): ?float
	{
		$sum = 0.0;
		$impressions = 0;

		foreach ($this->members as $member) {
			$position = $member->gscPosition();

			if ($position !== null && (int) $member->gscImpressions() > 0) {
				$sum += $position * (int) $member->gscImpressions();
				$impressions += (int) $member->gscImpressions();
			}
		}

		return $impressions > 0 ? round($sum / $impressions, 2) : null;
	}

	/** Fraza z odniesieniem SERP: lider, jeśli ma świeży pomiar, inaczej pierwszy członek ze świeżym pomiarem. */
	public function primarySerp(): ?KeywordSignals
	{
		foreach ($this->members as $member) {
			if ($member->serpFresh()) {
				return $member;
			}
		}

		return null;
	}

	/** Fraza z profilem SERP (świeży albo nieaktualny pomiar ≤ 90 dni). */
	public function profiledSerp(): ?KeywordSignals
	{
		return $this->primarySerp() ?? array_values(array_filter($this->members, static fn (KeywordSignals $member): bool => $member->serpUsable()))[0] ?? null;
	}

	/** Są wyłącznie nieaktualne (31–90 dni) pomiary — bez Pozycji SERP projektu. */
	public function staleSerpOnly(): bool
	{
		return $this->primarySerp() === null && $this->profiledSerp() !== null;
	}

	/**
	 * Największy spadek Pozycji SERP (świeży pomiar porównany z poprzednim porównywalnym).
	 *
	 * @return array{keyword: string, rank: ?int, prev_rank: ?int, lost: int, change: ?string}|null
	 */
	public function serpDecline(): ?array
	{
		$best = null;

		foreach ($this->members as $member) {
			$change = $member->serpChange();

			if ($change === null || ! $change['decline']) {
				continue;
			}

			$rank = $member->serpRank();
			$lost = $change['value'] !== null && $change['value'] < 0 ? -$change['value'] : ($rank === null && $change['prev_rank'] !== null ? 101 - $change['prev_rank'] : 0);

			if ($best === null || $lost > $best['lost']) {
				$best = ['keyword' => $member->keyword, 'rank' => $rank, 'prev_rank' => $change['prev_rank'], 'lost' => $lost, 'change' => $change['change']];
			}
		}

		return $best;
	}

	/**
	 * Nieodrzucone szanse SEO powiązane bezpośrednio z frazami tematu (bez powtórzeń).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function opportunities(): array
	{
		$result = [];

		foreach ($this->members as $member) {
			foreach ($member->opportunities() as $opportunity) {
				$result[(string) ($opportunity['id'] ?? count($result))] = $opportunity;
			}
		}

		ksort($result);

		return array_values($result);
	}

	/**
	 * @return list<string>
	 */
	public function opportunityTypes(): array
	{
		$types = array_values(array_unique(array_map(static fn (array $item): string => (string) ($item['type'] ?? ''), $this->opportunities())));
		sort($types);

		return $types;
	}

	public function hasOpportunity(OpportunityType $type): bool
	{
		return in_array($type->value, $this->opportunityTypes(), true);
	}

	/** Zaakceptowana fraza Nowych fraz (decyzja użytkownika — popyt potwierdzony ręcznie). */
	public function acceptedDiscovery(): bool
	{
		foreach ($this->members as $member) {
			if (($member->discovery()['status'] ?? null) === 'accepted') {
				return true;
			}
		}

		return false;
	}

	/**
	 * Najmocniejsza luka treści fraz tematu (nowa strona / wzmocnienie) z pewnością.
	 *
	 * @return array{content_gap: string, confidence: string, reason: ?string}|null
	 */
	public function contentGap(): ?array
	{
		$rank = ['high' => 2, 'medium' => 1];
		$best = null;

		foreach ($this->members as $member) {
			$gap = $member->contentGap();

			if ($gap === null || ($gap['status'] ?? null) === 'dismissed' || ! in_array($gap['content_gap'] ?? null, ['new_page', 'improve'], true) || ! isset($rank[$gap['confidence'] ?? ''])) {
				continue;
			}

			if ($best === null || $rank[$gap['confidence']] > $rank[$best['confidence']]) {
				$best = ['content_gap' => (string) $gap['content_gap'], 'confidence' => (string) $gap['confidence'], 'reason' => isset($gap['reason']) ? (string) $gap['reason'] : null];
			}
		}

		return $best;
	}

	/** Konkurenci w TOP10: świeży albo nieaktualny pomiar SERP (aktywni konkurenci projektu) albo Luki fraz (Labs). */
	public function competitorsTop10(): int
	{
		$best = 0;

		foreach ($this->members as $member) {
			$best = max($best, (int) $member->serpCompetitorsTop10(), (int) ($member->gap()['competitors_top10'] ?? 0));
		}

		return $best;
	}

	/** Luka fraz „słaba widoczność” (projekt widoczny, konkurenci wyraźnie wyżej). */
	public function gapWeak(): bool
	{
		foreach ($this->members as $member) {
			if (($member->gap()['gap_type'] ?? null) === 'weak') {
				return true;
			}
		}

		return false;
	}

	/** Dane tematu pochodzą wyłącznie z DataForSEO Labs (brak wyświetleń GSC i pomiaru SERP, brak wpisu ręcznego). */
	public function labsOnly(): bool
	{
		if ((int) $this->gscImpressions() > 0 || $this->profiledSerp() !== null || $this->leader->manual) {
			return false;
		}

		foreach ($this->members as $member) {
			if ($member->gap() !== null) {
				return true;
			}
		}

		return false;
	}

	/** Korekta pisowni wyszukiwarki w pomiarze referencyjnym. */
	public function spellCorrection(): ?string
	{
		return $this->profiledSerp()?->serpSpell();
	}

	/**
	 * Sprzeczność intencji: zdecydowany (wysoka pewność) sygnał intencji z SERP w innej rodzinie niż intencja dostawcy.
	 *
	 * @return array{provider: string, serp: string}|null
	 */
	public function intentMismatch(): ?array
	{
		$reference = $this->profiledSerp();
		$profile = $reference?->serpProfile();
		$provider = $reference?->intent;

		if ($profile === null || $provider === null || ($profile['intent_confidence'] ?? null) !== 'high') {
			return null;
		}

		$signal = (string) ($profile['intent_signal'] ?? SerpIntentSignal::Unknown->value);
		$a = self::INTENT_FAMILIES[$provider] ?? null;
		$b = self::INTENT_FAMILIES[$signal] ?? null;

		return $a !== null && $b !== null && $a !== $b ? ['provider' => $provider, 'serp' => $signal] : null;
	}

	/** Wyniki SERP zdominowane przez dedykowane strony konkurentów (kształt z pewnością co najmniej średnią). */
	public function serpDedicatedPages(): bool
	{
		$profile = $this->profiledSerp()?->serpProfile();
		$shapes = array_map(static fn (ResultShape $shape): string => $shape->value, self::DEDICATED_SHAPES);

		return $profile !== null && in_array($profile['shape'] ?? null, $shapes, true) && in_array($profile['shape_confidence'] ?? null, ['medium', 'high'], true);
	}

	/**
	 * Sygnały konfliktu danej siły.
	 *
	 * @return list<ConflictSignal>
	 */
	public function conflicts(string $strength): array
	{
		return array_values(array_filter($this->conflicts, static fn (ConflictSignal $signal): bool => $signal->strength === $strength));
	}

	public function hasConflict(string $type): bool
	{
		foreach ($this->conflicts as $signal) {
			if ($signal->type === $type) {
				return true;
			}
		}

		return false;
	}
}
