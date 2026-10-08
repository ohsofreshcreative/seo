<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

use OsfSeo\Gap\GapConfig;
use OsfSeo\Gap\TextFold;
use OsfSeo\Strategy\Topics\KeywordSignals;

/**
 * Strona docelowa frazy i tematu (faza C, D57 — docs/ARCHITECTURE.md, sekcja 15.13). Kolejność źródeł: wskazanie ręczne → świeży
 * pomiar SERP projektu (siła według pozycji: TOP20 silne, 21–50 średnie, > 50 słabe) → GSC query × page (dominująca strona ≥ 60%
 * silna, udział ≥ 30% średni; obie strony z udziałem ≥ 30% — rywalizujące adresy) → Labs (strona docelowa luki ze źródłem Labs —
 * najwyżej średnia) → dopasowanie adresu (zawsze słabe, tylko bez innych wskazań).
 *
 * Każda rodzina dowodów liczy się raz: strona szansy SEO, strona docelowa Nowych fraz i strona luki ze źródłem SERP/GSC pochodzą
 * z tych samych danych — są zapisywane jako dowody pochodne, nie jako drugie potwierdzenie.
 *
 * Stany: potwierdzona (ręczna albo ≥ 2 rodziny co najmniej średnio, w tym jedna silnie, bez silnego rywala), prawdopodobna (jedna rodzina
 * silnie albo dwie średnio), konflikt (rywalizujące adresy z mocnym wsparciem), brak (żadnego wskazania ani śladu strony i dodatkowy dowód
 * braku widoczności: świeży pomiar bez projektu albo wiarygodny brak w punkcie odniesienia Labs), nieznana (pozostałe — także tylko
 * słabe wskazanie).
 */
final class TargetPageResolver
{
	public const SERP_STRONG_RANK = 20;

	public const SERP_MEDIUM_RANK = 50;

	public const GSC_DOMINANT_SHARE = GapConfig::TARGET_SHARE;

	public const GSC_SECONDARY_SHARE = 0.3;

	public const GSC_MINOR_SHARE = 0.1;

	public const GSC_MIN_PAGE_IMPRESSIONS = 20;

	public const LABS_MEDIUM_RANK = 20;

	/**
	 * @return array{votes: list<PageVote>, derived: list<array{url: string, source: string}>}
	 */
	public function votes(KeywordSignals $keyword, ?SlugMatcher $slugs = null): array
	{
		$votes = [];
		$derived = [];
		$serpUrl = $keyword->serpUrl();
		$rank = $keyword->serpRank();

		if ($keyword->serpFound() === true && $serpUrl !== null && $rank !== null) {
			$votes[] = new PageVote($serpUrl, EvidenceFamily::Serp, match (true) {
				$rank <= self::SERP_STRONG_RANK => VoteStrength::Strong,
				$rank <= self::SERP_MEDIUM_RANK => VoteStrength::Medium,
				default => VoteStrength::Weak,
			}, $rank <= self::SERP_STRONG_RANK ? 'serp_top20' : ($rank <= self::SERP_MEDIUM_RANK ? 'serp_top50' : 'serp_beyond50'), ['rank' => $rank]);
		}

		foreach (array_slice($keyword->gscPages(), 0, 3) as $page) {
			$share = $page['share'];

			if ($page['impressions'] <= 0) {
				continue;
			}

			if ($share !== null && $share >= self::GSC_DOMINANT_SHARE && $page['impressions'] >= self::GSC_MIN_PAGE_IMPRESSIONS) {
				$votes[] = new PageVote($page['url'], EvidenceFamily::Gsc, VoteStrength::Strong, 'gsc_dominant', ['share' => $share, 'impressions' => $page['impressions']]);
			} elseif ($share !== null && $share >= self::GSC_SECONDARY_SHARE && $page['impressions'] >= self::GSC_MIN_PAGE_IMPRESSIONS) {
				$votes[] = new PageVote($page['url'], EvidenceFamily::Gsc, VoteStrength::Medium, 'gsc_share', ['share' => $share, 'impressions' => $page['impressions']]);
			} elseif ($share === null || $share >= self::GSC_MINOR_SHARE) {
				$votes[] = new PageVote($page['url'], EvidenceFamily::Gsc, VoteStrength::Weak, 'gsc_minor', ['share' => $share, 'impressions' => $page['impressions']]);
			}
		}

		$gap = $keyword->gap();
		$gapTarget = is_array($gap['target'] ?? null) ? $gap['target'] : null;

		if ($gapTarget !== null && is_string($gapTarget['url'] ?? null)) {
			if (($gapTarget['source'] ?? null) === 'labs') {
				$labsRank = isset($gap['project_labs_rank']) ? (int) $gap['project_labs_rank'] : null;
				$votes[] = new PageVote(
					$gapTarget['url'],
					EvidenceFamily::Labs,
					$labsRank !== null && $labsRank <= self::LABS_MEDIUM_RANK ? VoteStrength::Medium : VoteStrength::Weak,
					'labs_target',
					['rank_labs' => $labsRank],
				);
			} else {
				$derived[] = ['url' => $gapTarget['url'], 'source' => 'gap_' . (string) ($gapTarget['source'] ?? 'unknown')];
			}
		}

		foreach ($keyword->opportunities() as $opportunity) {
			if (is_string($opportunity['page'] ?? null)) {
				$derived[] = ['url' => $opportunity['page'], 'source' => 'opportunity'];
			}
		}

		$discoveryUrl = $keyword->discovery()['target_url'] ?? null;

		if (is_string($discoveryUrl) && $discoveryUrl !== '') {
			$derived[] = ['url' => $discoveryUrl, 'source' => 'discovery'];
		}

		if ($votes === [] && $slugs !== null) {
			foreach ($slugs->match($keyword->keyword) as $match) {
				$votes[] = new PageVote($match['url'], EvidenceFamily::Slug, VoteStrength::Weak, 'slug_match', ['coverage' => $match['coverage']]);
			}
		}

		return ['votes' => $votes, 'derived' => self::unique($derived)];
	}

	public function forKeyword(KeywordSignals $keyword, ?SlugMatcher $slugs = null): TargetResolution
	{
		$votes = $this->votes($keyword, $slugs);

		return $this->resolve($votes['votes'], self::noVisibility($keyword), self::hints($keyword), $votes['derived']);
	}

	/**
	 * Strona docelowa tematu — wskazania wszystkich fraz (dla adresu i rodziny najsilniejsze), wskazanie ręczne tematu ma pierwszeństwo.
	 *
	 * @param list<TargetResolution> $members
	 */
	public function forTopic(array $members, ?string $manualUrl = null, bool $manualNone = false): TargetResolution
	{
		$votes = [];
		$noVisibility = [];
		$hints = [];
		$derived = [];

		foreach ($members as $member) {
			foreach ($member->votes as $vote) {
				$key = $vote->url . '|' . $vote->family->value;

				if (! isset($votes[$key]) || $vote->strength->points() > $votes[$key]->strength->points()) {
					$votes[$key] = $vote;
				}
			}

			$noVisibility = [...$noVisibility, ...$member->noVisibility];
			$hints = [...$hints, ...$member->hints];
			$derived = [...$derived, ...$member->derived];
		}

		return $this->resolve(array_values($votes), array_values(array_unique($noVisibility)), self::unique($hints), self::unique($derived), $manualUrl, $manualNone);
	}

	/**
	 * @param list<PageVote> $votes
	 * @param list<string> $noVisibility
	 * @param list<array{url: ?string, source: string}> $hints
	 * @param list<array{url: string, source: string}> $derived
	 */
	public function resolve(array $votes, array $noVisibility, array $hints, array $derived, ?string $manualUrl = null, bool $manualNone = false): TargetResolution
	{
		$byUrl = [];

		foreach ($votes as $vote) {
			$current = $byUrl[$vote->url][$vote->family->value] ?? null;
			$byUrl[$vote->url][$vote->family->value] = VoteStrength::max($current, $vote->strength);
		}

		$ranked = [];

		foreach ($byUrl as $url => $families) {
			$strong = 0;
			$solid = 0;
			$score = 0;
			$supporting = [];

			foreach ($families as $family => $strength) {
				$score += $strength->points();

				if (! EvidenceFamily::from($family)->confirms() || $strength === VoteStrength::Weak) {
					continue;
				}

				$solid++;
				$strong += $strength === VoteStrength::Strong ? 1 : 0;
				$supporting[] = $family;
			}

			sort($supporting);
			$best = null;

			foreach ($families as $strength) {
				$best = VoteStrength::max($best, $strength);
			}

			$ranked[] = ['url' => (string) $url, 'strong' => $strong, 'solid' => $solid, 'score' => $score, 'families' => $supporting, 'strength' => $best?->value ?? VoteStrength::Weak->value];
		}

		usort($ranked, static fn (array $a, array $b): int => [$b['solid'], $b['strong'], $b['score'], $a['url']] <=> [$a['solid'], $a['strong'], $a['score'], $b['url']]);
		$solid = array_values(array_filter($ranked, static fn (array $item): bool => $item['solid'] > 0));
		$alternatives = static fn (array $items, ?string $except): array => array_values(array_map(
			static fn (array $item): array => ['url' => $item['url'], 'families' => $item['families'], 'strength' => $item['strength']],
			array_filter($items, static fn (array $item): bool => $item['url'] !== $except && $item['solid'] > 0),
		));

		if ($manualNone) {
			// Ręczne potwierdzenie braku strony — decyzja użytkownika; mocne wskazania zostają jako alternatywy (sprzeczność do pokazania).
			return new TargetResolution(TargetState::None, null, false, ['manual'], ['manual_none'], $votes, $alternatives($ranked, null), $noVisibility, $hints, $derived, true, true);
		}

		if ($manualUrl !== null) {
			$families = ['manual'];

			foreach ($ranked as $item) {
				if ($item['url'] === $manualUrl) {
					$families = ['manual', ...$item['families']];
				}
			}

			return new TargetResolution(TargetState::Confirmed, $manualUrl, TextFold::isRoot($manualUrl), $families, ['manual'], $votes, $alternatives($ranked, $manualUrl), $noVisibility, $hints, $derived, true);
		}

		if ($solid === []) {
			if ($ranked !== []) {
				return new TargetResolution(TargetState::Unknown, $ranked[0]['url'], TextFold::isRoot($ranked[0]['url']), [], ['target_weak'], $votes, [], $noVisibility, $hints, $derived);
			}

			if ($hints !== []) {
				return new TargetResolution(TargetState::Unknown, null, false, [], ['possible_existing_page'], $votes, [], $noVisibility, $hints, $derived);
			}

			if ($noVisibility !== []) {
				return new TargetResolution(TargetState::None, null, false, [], ['no_known_page', ...$noVisibility], $votes, [], $noVisibility, $hints, $derived);
			}

			return new TargetResolution(TargetState::Unknown, null, false, [], ['no_target_evidence'], $votes, [], $noVisibility, $hints, $derived);
		}

		$best = $solid[0];
		$rivals = array_slice($solid, 1);
		$confirmed = $best['solid'] >= 2 && $best['strong'] >= 1;
		$probable = $best['strong'] >= 1 || $best['solid'] >= 2;
		$strongRival = array_filter($rivals, static fn (array $item): bool => $item['strong'] >= 1 || $item['solid'] >= 2) !== [];
		$home = TextFold::isRoot($best['url']);

		if ($rivals !== [] && ($strongRival || ! $probable)) {
			return new TargetResolution(TargetState::Conflict, $best['url'], $home, $best['families'], ['competing_urls'], $votes, $alternatives($solid, $best['url']), $noVisibility, $hints, $derived);
		}

		$reasons = $confirmed ? ['independent_families'] : ($probable ? [$best['strong'] >= 1 ? 'single_strong_family' : 'two_medium_families'] : ['target_weak']);

		if ($rivals !== []) {
			$reasons[] = 'secondary_url';
		}

		return new TargetResolution(
			$confirmed ? TargetState::Confirmed : ($probable ? TargetState::Probable : TargetState::Unknown),
			$best['url'],
			$home,
			$best['families'],
			$reasons,
			$votes,
			$alternatives($solid, $best['url']),
			$noVisibility,
			$hints,
			$derived,
		);
	}

	/**
	 * Dodatkowe dowody braku widoczności: świeży pomiar bez projektu, wiarygodny brak w punkcie odniesienia Labs (luka „Brak widoczności”
	 * ze źródłem Labs). Fraza bez wyświetleń w GSC nie jest takim dowodem.
	 *
	 * @return list<string>
	 */
	public static function noVisibility(KeywordSignals $keyword): array
	{
		$result = [];

		if ($keyword->serpFound() === false) {
			$result[] = 'serp_not_found';
		}

		$gap = $keyword->gap();

		if ($gap !== null && ($gap['gap_type'] ?? null) === 'missing' && ($gap['visibility_source'] ?? null) === 'labs') {
			$result[] = 'labs_missing';
		}

		return $result;
	}

	/**
	 * Ślady możliwej istniejącej strony (bez wskazania strony docelowej): adres z pomiaru starszego niż świeży albo z poprzedniego pomiaru,
	 * pozycja projektu w punkcie odniesienia Labs.
	 *
	 * @return list<array{url: ?string, source: string}>
	 */
	public static function hints(KeywordSignals $keyword): array
	{
		$hints = $keyword->serpHistoricUrls();
		$gap = $keyword->gap();

		if ($gap !== null && isset($gap['project_labs_rank'])) {
			$hints[] = ['url' => null, 'source' => 'labs_rank'];
		}

		return self::unique($hints);
	}

	/**
	 * @template T of array
	 * @param list<T> $items
	 * @return list<T>
	 */
	private static function unique(array $items): array
	{
		$result = [];

		foreach ($items as $item) {
			$result[md5((string) json_encode($item))] = $item;
		}

		return array_values($result);
	}
}
