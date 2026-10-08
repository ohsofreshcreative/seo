<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

use OsfSeo\Gap\TextFold;
use OsfSeo\Strategy\Serp\SerpOverlap;
use OsfSeo\Strategy\Target\TargetResolution;
use OsfSeo\Strategy\Target\TargetState;

/**
 * Grupowanie fraz w tematy (faza C, D60 — docs/ARCHITECTURE.md, sekcja 15.13). Tożsamość frazy = fraza rynkowa. Grupa powstaje wokół
 * lidera (największy popyt), każda fraza jest porównywana **wyłącznie z liderami** istniejących grup (bez łańcuchów A~B~C).
 *
 * Automatyczne scalenie tylko przy silnym sygnale:
 * - A — ręczne przypięcie do tematu (pierwszeństwo przed wszystkim: fraza przypięta nie jest grupowana automatycznie, `TopicIdentity`
 *   dołącza ją do tematu z przypięcia),
 * - B — ta sama potwierdzona albo prawdopodobna strona docelowa (sama strona główna nie wystarcza),
 * - C — silny overlap SERP (D64) bez konfliktu stron docelowych (sprzeczność intencji już obniża overlap do umiarkowanego).
 *
 * Silny overlap przy różnych znanych stronach docelowych blokuje scalenie i staje się dowodem konfliktu URL. `core_key`, zbiór wyrazów
 * (`tokenKey`), grupa luk, umiarkowany overlap i wspólna strona główna — wyłącznie „możliwa grupa” (sugestia, bez scalania).
 */
final class TopicClusterer
{
	/** Klucze sygnałów pomocniczych wspólne dla większej liczby grup są zbyt ogólne na sugestię. */
	private const MAX_KEY_GROUPS = 20;

	/** Najwięcej sugestii „możliwej grupy” na temat. */
	public const MAX_SUGGESTIONS = 10;

	/**
	 * @param list<KeywordSignals> $keywords aktywni kandydaci (przypięci są pomijani)
	 * @param array<int, TargetResolution> $targets id frazy rynkowej → strona docelowa frazy
	 * @return array{groups: list<TopicGroup>, blocked: list<array{a: int, b: int, level: string, targets: array{0: ?string, 1: ?string}, confirmed: bool}>}
	 */
	public function cluster(array $keywords, array $targets, OverlapIndex $overlaps): array
	{
		$sorted = self::sorted($keywords);

		/** @var list<TopicGroup> $groups */
		$groups = [];
		// Frazy przypięte nie biorą udziału w grupowaniu automatycznym — dołącza je `TopicIdentity` do tematu z przypięcia.
		$byTarget = [];
		$byLeader = [];
		$blocked = [];

		foreach ($sorted as $keyword) {
			if ($keyword->pinnedTopicId !== null) {
				continue;
			}

			$id = $keyword->marketKeywordId;
			$target = $targets[$id] ?? null;
			$candidates = [];

			if (self::targetKey($target) !== null) {
				foreach ($byTarget[self::targetKey($target)] ?? [] as $index) {
					$candidates[$index] = true;
				}
			}

			foreach ($overlaps->sharing($id) as $other) {
				if (isset($byLeader[$other])) {
					$candidates[$byLeader[$other]] = true;
				}
			}

			ksort($candidates);
			$attached = false;

			foreach (array_keys($candidates) as $index) {
				$leader = $groups[$index]->leader;
				$link = $this->link($leader, $id, $targets, $overlaps);

				if ($link['blocked'] !== null) {
					$blocked[] = $link['blocked'];
				}

				if ($link['basis'] !== null && ! $attached) {
					$groups[$index]->add($id, $link['basis']);
					$attached = true;
				}
			}

			if (! $attached) {
				$groups[] = new TopicGroup($id);
				$this->indexLeader(count($groups) - 1, $id, $targets, $byTarget, $byLeader);
			}
		}

		return ['groups' => $groups, 'blocked' => $blocked];
	}

	/**
	 * Kolejność liderów: wolumen (brak na końcu), wyświetlenia GSC, wpis ręczny, poziom źródła, id frazy — deterministycznie.
	 *
	 * @param list<KeywordSignals> $keywords
	 * @return list<KeywordSignals>
	 */
	public static function sorted(array $keywords): array
	{
		usort($keywords, static fn (KeywordSignals $a, KeywordSignals $b): int => [
			$a->volume === null ? 1 : 0, -($a->volume ?? 0), $a->gscImpressions() === null ? 1 : 0, -($a->gscImpressions() ?? 0),
			$a->manual ? 0 : 1, $a->tier ?? 99, $a->marketKeywordId,
		] <=> [
			$b->volume === null ? 1 : 0, -($b->volume ?? 0), $b->gscImpressions() === null ? 1 : 0, -($b->gscImpressions() ?? 0),
			$b->manual ? 0 : 1, $b->tier ?? 99, $b->marketKeywordId,
		]);

		return $keywords;
	}

	/**
	 * Silne powiązanie frazy z liderem grupy albo blokada (silny overlap przy różnych stronach docelowych).
	 *
	 * @param array<int, TargetResolution> $targets
	 * @return array{basis: ?string, blocked: ?array{a: int, b: int, level: string, targets: array{0: ?string, 1: ?string}, confirmed: bool}}
	 */
	public function link(int $leader, int $keyword, array $targets, OverlapIndex $overlaps): array
	{
		$a = $targets[$leader] ?? null;
		$b = $targets[$keyword] ?? null;
		$bothKnown = $a !== null && $b !== null && $a->state->hasTarget() && $b->state->hasTarget();

		if ($bothKnown && $a->url === $b->url && ! $a->home) {
			return ['basis' => TopicGroup::SAME_TARGET, 'blocked' => null];
		}

		$overlap = $overlaps->compare($leader, $keyword);

		if ($overlap === null || $overlap['level'] !== SerpOverlap::STRONG || ! $overlap['mergeable']) {
			return ['basis' => null, 'blocked' => null];
		}

		if ($bothKnown && $a->url !== $b->url) {
			return ['basis' => null, 'blocked' => [
				'a' => $leader,
				'b' => $keyword,
				'level' => (string) $overlap['level'],
				'targets' => [$a->url, $b->url],
				'confirmed' => $a->state === TargetState::Confirmed && $b->state === TargetState::Confirmed,
			]];
		}

		return ['basis' => TopicGroup::SERP_OVERLAP, 'blocked' => null];
	}

	/**
	 * @param array<int, TargetResolution> $targets
	 * @param array<string, list<int>> $byTarget
	 * @param array<int, int> $byLeader
	 */
	private function indexLeader(int $index, int $leader, array $targets, array &$byTarget, array &$byLeader): void
	{
		$key = self::targetKey($targets[$leader] ?? null);

		if ($key !== null) {
			$byTarget[$key][] = $index;
		}

		$byLeader[$leader] = $index;
	}

	private static function targetKey(?TargetResolution $target): ?string
	{
		return $target !== null && $target->state->hasTarget() && $target->url !== null && ! $target->home ? $target->url : null;
	}

	/**
	 * Sugestie „możliwej grupy” między grupami (bez scalania): `core_key`, zbiór wyrazów, grupa luk, umiarkowany overlap liderów,
	 * wspólna strona główna jako strona docelowa.
	 *
	 * @param list<TopicGroup> $groups grupy ostateczne (także z przypięć)
	 * @param list<KeywordSignals> $keywords
	 * @param array<int, TargetResolution> $targets
	 */
	public function suggest(array $groups, array $keywords, array $targets, OverlapIndex $overlaps): void
	{
		$byId = [];

		foreach ($keywords as $keyword) {
			$byId[$keyword->marketKeywordId] = $keyword;
		}

		$keys = [];
		$leaders = [];

		foreach ($groups as $index => $group) {
			$leaders[$group->leader] = $index;

			foreach ($group->members as $member) {
				$keyword = $byId[$member] ?? null;

				if ($keyword === null) {
					continue;
				}

				if ($keyword->coreKey !== null) {
					$keys['core_key'][$keyword->coreKey][$index] = true;
				}

				$tokens = TextFold::tokenKey($keyword->keyword);

				if ($tokens !== '') {
					$keys['token_key'][$tokens][$index] = true;
				}

				if ($keyword->gapClusterId() !== null) {
					$keys['gap_cluster'][$keyword->gapClusterId()][$index] = true;
				}
			}

			$target = $targets[$group->leader] ?? null;

			if ($target !== null && $target->state->hasTarget() && $target->home && $target->url !== null) {
				$keys['same_home'][$target->url][$index] = true;
			}
		}

		$pairs = [];

		foreach ($keys as $signal => $values) {
			foreach ($values as $indexes) {
				if (count($indexes) < 2 || count($indexes) > self::MAX_KEY_GROUPS) {
					continue;
				}

				$list = array_keys($indexes);
				sort($list);

				foreach ($list as $i) {
					foreach ($list as $j) {
						if ($i !== $j) {
							$pairs[$i][$j][$signal] = true;
						}
					}
				}
			}
		}

		foreach ($leaders as $leader => $index) {
			foreach ($overlaps->sharing($leader) as $other) {
				$otherIndex = $leaders[$other] ?? null;
				$overlap = $otherIndex === null || $otherIndex === $index ? null : $overlaps->compare($leader, $other);

				if ($overlap !== null && in_array($overlap['level'], [SerpOverlap::STRONG, SerpOverlap::MODERATE], true)) {
					// Silny overlap, który nie scalił grup (konflikt stron albo porównanie z liderem innej grupy) — też tylko sugestia.
					$pairs[$index][$otherIndex]['serp_' . $overlap['level']] = true;
				}
			}
		}

		foreach ($pairs as $index => $others) {
			uksort($others, static fn (int $x, int $y): int => [-count($others[$x]), $x] <=> [-count($others[$y]), $y]);
			$groups[$index]->suggestions = array_map(static function (array $signals): array {
				$list = array_keys($signals);
				sort($list);

				return $list;
			}, array_slice($others, 0, self::MAX_SUGGESTIONS, true));
		}
	}
}
