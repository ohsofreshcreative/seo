<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

/**
 * Stabilne identyfikatory tematów (faza C — docs/ARCHITECTURE.md, sekcja 15.13). Poprzedni temat zachowuje ID, gdy co najmniej 50%
 * jego istotnych fraz (dawni członkowie, którzy nadal są aktywnymi kandydatami grupowanymi automatycznie) trafia do nowej grupy; remis →
 * grupa z poprzednim liderem. Przy scaleniu kilku tematów ID dostaje temat z największym pokryciem (potem starszy); przy podziale —
 * następca logiczny (grupa z poprzednim liderem), reszta dostaje nowe ID.
 *
 * Ręczne przypięcia mają pierwszeństwo: frazy przypięte do tematu T dołączają do grupy, która dostała ID T, a gdy takiej nie ma — tworzą
 * grupę z ID T. Wszystko deterministycznie (bez zależności od kolejności w bazie).
 */
final class TopicIdentity
{
	public const SHARE = 0.5;

	public const REASON_MERGED = 'merged';

	public const REASON_SPLIT = 'split';

	public const REASON_NO_KEYWORDS = 'no_keywords';

	/**
	 * @param list<TopicGroup> $groups grupy automatyczne (bez fraz przypiętych)
	 * @param array<int, array{leader: ?int, members: list<int>, active: bool}> $previous id tematu → poprzedni lider i członkowie (frazy rynkowe)
	 * @param array<int, list<int>> $pins id tematu → przypięte frazy (w kolejności lidera)
	 * @return array{groups: list<TopicGroup>, ids: array<int, ?int>, retired: array<int, array{reason: string, into: ?int}>, splits: array<int, int>}
	 *   groups: grupy ostateczne (z przypięciami); ids: indeks grupy → id tematu (null — nowy); retired: id tematu → powód i temat docelowy
	 *   (scalenie); splits: indeks nowej grupy → temat źródłowy
	 */
	public function assign(array $groups, array $previous, array $pins = []): array
	{
		ksort($previous);
		$groupOf = [];

		foreach ($groups as $index => $group) {
			foreach ($group->members as $member) {
				$groupOf[$member] = $index;
			}
		}

		$ids = array_fill_keys(array_keys($groups), null);
		$used = [];
		$significant = [];

		foreach ($previous as $topicId => $topic) {
			$significant[$topicId] = array_values(array_filter(array_unique($topic['members']), static fn (int $member): bool => isset($groupOf[$member])));
		}

		$pairs = [];

		foreach ($significant as $topicId => $members) {
			if ($members === []) {
				continue;
			}

			$overlap = [];

			foreach ($members as $member) {
				$overlap[$groupOf[$member]] = ($overlap[$groupOf[$member]] ?? 0) + 1;
			}

			foreach ($overlap as $index => $count) {
				$share = $count / count($members);

				if ($share >= self::SHARE) {
					$leader = $previous[$topicId]['leader'];
					$pairs[] = [
						'index' => $index,
						'topic' => $topicId,
						'share' => $share,
						'leader' => $leader !== null && in_array($leader, $groups[$index]->members, true) ? 1 : 0,
						'count' => $count,
						'active' => $previous[$topicId]['active'] ? 1 : 0,
					];
				}
			}
		}

		usort($pairs, static fn (array $a, array $b): int => [-$a['share'], -$a['leader'], -$a['count'], -$a['active'], $a['topic'], $a['index']]
			<=> [-$b['share'], -$b['leader'], -$b['count'], -$b['active'], $b['topic'], $b['index']]);

		foreach ($pairs as $pair) {
			if ($ids[$pair['index']] === null && ! isset($used[$pair['topic']])) {
				$ids[$pair['index']] = $pair['topic'];
				$used[$pair['topic']] = true;
			}
		}

		// Grupa bez ID z frazą, która była liderem nieaktywnego tematu bez fraz (np. scalonego przypięciem, które cofnięto) — dawny temat wraca.
		$dormant = [];

		foreach ($previous as $topicId => $topic) {
			if (! $topic['active'] && ! isset($used[$topicId]) && $significant[$topicId] === [] && $topic['leader'] !== null) {
				$dormant[$topic['leader']] ??= $topicId;
			}
		}

		foreach ($groups as $index => $group) {
			if ($ids[$index] !== null) {
				continue;
			}

			foreach ($group->members as $member) {
				$topicId = $dormant[$member] ?? null;

				if ($topicId !== null && ! isset($used[$topicId])) {
					$ids[$index] = $topicId;
					$used[$topicId] = true;

					break;
				}
			}
		}

		// Przypięcia: do grupy z ID tematu albo jako nowa grupa z tym ID.
		ksort($pins);
		$byTopic = array_flip(array_filter($ids, static fn (?int $id): bool => $id !== null));

		foreach ($pins as $topicId => $members) {
			if ($members === []) {
				continue;
			}

			if (isset($byTopic[$topicId])) {
				$index = $byTopic[$topicId];

				foreach ($members as $member) {
					$groups[$index]->add($member, TopicGroup::PIN);
				}
			} else {
				$group = new TopicGroup($members[0], (int) $topicId);
				$group->basis[$members[0]] = TopicGroup::PIN;

				foreach (array_slice($members, 1) as $member) {
					$group->add($member, TopicGroup::PIN);
				}

				$groups[] = $group;
				$index = count($groups) - 1;
				$ids[$index] = (int) $topicId;
				$used[$topicId] = true;
			}

			foreach ($members as $member) {
				$groupOf[$member] = $index;
			}
		}

		$retired = [];

		foreach ($previous as $topicId => $topic) {
			if (isset($used[$topicId]) || ! $topic['active']) {
				continue;
			}

			$members = array_values(array_filter(array_unique($topic['members']), static fn (int $member): bool => isset($groupOf[$member])));

			if ($members === []) {
				$retired[$topicId] = ['reason' => self::REASON_NO_KEYWORDS, 'into' => null];

				continue;
			}

			$overlap = [];

			foreach ($members as $member) {
				$overlap[$groupOf[$member]] = ($overlap[$groupOf[$member]] ?? 0) + 1;
			}

			uksort($overlap, static fn (int $x, int $y): int => [-$overlap[$x], $x] <=> [-$overlap[$y], $y]);
			$best = (int) array_key_first($overlap);
			$retired[$topicId] = $overlap[$best] / count($members) >= self::SHARE && $ids[$best] !== null
				? ['reason' => self::REASON_MERGED, 'into' => $ids[$best]]
				: ['reason' => self::REASON_SPLIT, 'into' => null];
		}

		$previousOf = [];

		foreach ($previous as $topicId => $topic) {
			foreach ($topic['members'] as $member) {
				$previousOf[$member] ??= $topicId;
			}
		}

		$splits = [];

		foreach ($groups as $index => $group) {
			if ($ids[$index] !== null) {
				continue;
			}

			$sources = [];

			foreach ($group->members as $member) {
				if (isset($previousOf[$member])) {
					$sources[$previousOf[$member]] = ($sources[$previousOf[$member]] ?? 0) + 1;
				}
			}

			if ($sources === []) {
				continue;
			}

			uksort($sources, static fn (int $x, int $y): int => [-$sources[$x], $x] <=> [-$sources[$y], $y]);
			$source = (int) array_key_first($sources);

			if (isset($used[$source]) || ($retired[$source]['reason'] ?? null) === self::REASON_SPLIT) {
				$splits[$index] = $source;
			}
		}

		return ['groups' => $groups, 'ids' => $ids, 'retired' => $retired, 'splits' => $splits];
	}

	/**
	 * Frazy przypięte według tematu (w kolejności lidera).
	 *
	 * @param list<KeywordSignals> $keywords
	 * @return array<int, list<int>>
	 */
	public static function pins(array $keywords): array
	{
		$pins = [];

		foreach (TopicClusterer::sorted($keywords) as $keyword) {
			if ($keyword->pinnedTopicId !== null) {
				$pins[$keyword->pinnedTopicId][] = $keyword->marketKeywordId;
			}
		}

		ksort($pins);

		return $pins;
	}
}
