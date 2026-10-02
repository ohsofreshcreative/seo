<?php

declare(strict_types=1);

namespace OsfSeo\Gap;

/**
 * Deterministyczne grupowanie fraz wokół lidera (bez embeddingów i AI, docs/ARCHITECTURE.md, sekcja 14.11).
 *
 * Frazy w kolejności: wolumen malejąco, id rosnąco. Fraza dołącza do pierwszej (najstarszej) grupy, z której LIDEREM jest
 * powiązana — porównanie zawsze z liderem, więc grupy nie „pełzną” łańcuchem podobieństw. Powiązanie (wystarczy jedno):
 *
 * 1. wspólne adresy konkurentów: co najmniej 2, albo wspólny jedyny adres jednej ze stron (`urls`),
 * 2. ta sama grupa synonimów dostawcy (`synonyms`),
 * 3. ta sama strona docelowa projektu (`target`),
 * 4. ten sam zbiór wyrazów, bez kolejności i znaków diakrytycznych (`words`),
 * 5. frazy monitorowane: co najmniej N wspólnych adresów w TOP10 naszych migawek SERP (`serp`).
 *
 * Bez stemmingu: „strona internetowa” i „strony internetowe” łączy dopiero wspólny adres albo grupa dostawcy.
 */
final class KeywordClusterer
{
	public function __construct(private readonly int $serpOverlap = GapConfig::SERP_OVERLAP_URLS)
	{
	}

	/**
	 * @param list<ClusterItem> $items
	 * @return array{assignments: array<int, int>, leaders: list<int>, reasons: array<int, string>}
	 *   assignments: id frazy → numer grupy, leaders: numer grupy → id lidera, reasons: id frazy → powód dołączenia
	 */
	public function cluster(array $items): array
	{
		usort($items, static fn (ClusterItem $a, ClusterItem $b): int => [$b->volume, $a->id] <=> [$a->volume, $b->id]);

		/** @var list<ClusterItem> $leaders */
		$leaders = [];
		$byUrl = [];
		$byCore = [];
		$byTarget = [];
		$byWords = [];
		$withSerp = [];
		$assignments = [];
		$reasons = [];

		foreach ($items as $item) {
			$candidates = [];

			foreach ($item->urls as $url) {
				foreach ($byUrl[$url] ?? [] as $index) {
					$candidates[$index] = true;
				}
			}

			foreach ([[$byCore, $item->coreKey], [$byTarget, $item->target], [$byWords, $item->tokenKey === '' ? null : $item->tokenKey]] as [$index, $key]) {
				if ($key !== null && isset($index[$key])) {
					$candidates[$index[$key]] = true;
				}
			}

			if ($item->serpTop10 !== null) {
				foreach ($withSerp as $index) {
					$candidates[$index] = true;
				}
			}

			$joined = null;
			$candidates = array_keys($candidates);
			sort($candidates);

			foreach ($candidates as $index) {
				$reason = $this->link($item, $leaders[$index]);

				if ($reason !== null) {
					$joined = $index;
					$reasons[$item->id] = $reason;

					break;
				}
			}

			if ($joined === null) {
				$joined = count($leaders);
				$leaders[] = $item;
				$reasons[$item->id] = 'leader';

				foreach (array_unique($item->urls) as $url) {
					$byUrl[$url][] = $joined;
				}

				if ($item->coreKey !== null) {
					$byCore[$item->coreKey] ??= $joined;
				}

				if ($item->target !== null) {
					$byTarget[$item->target] ??= $joined;
				}

				if ($item->tokenKey !== '') {
					$byWords[$item->tokenKey] ??= $joined;
				}

				if ($item->serpTop10 !== null) {
					$withSerp[] = $joined;
				}
			}

			$assignments[$item->id] = $joined;
		}

		return ['assignments' => $assignments, 'leaders' => array_map(static fn (ClusterItem $leader): int => $leader->id, $leaders), 'reasons' => $reasons];
	}

	private function link(ClusterItem $item, ClusterItem $leader): ?string
	{
		$shared = count(array_intersect(array_unique($item->urls), array_unique($leader->urls)));

		if ($shared >= 2 || ($shared >= 1 && (count(array_unique($item->urls)) === 1 || count(array_unique($leader->urls)) === 1))) {
			return 'urls';
		}

		if ($item->coreKey !== null && $item->coreKey === $leader->coreKey) {
			return 'synonyms';
		}

		if ($item->target !== null && $item->target === $leader->target) {
			return 'target';
		}

		if ($item->tokenKey !== '' && $item->tokenKey === $leader->tokenKey) {
			return 'words';
		}

		if ($item->serpTop10 !== null && $leader->serpTop10 !== null && count(array_intersect($item->serpTop10, $leader->serpTop10)) >= $this->serpOverlap) {
			return 'serp';
		}

		return null;
	}

	public static function reasonLabel(string $reason): string
	{
		return match ($reason) {
			'leader' => 'fraza wiodąca',
			'urls' => 'te same strony konkurentów',
			'synonyms' => 'grupa synonimów dostawcy',
			'target' => 'ta sama strona projektu',
			'words' => 'te same wyrazy',
			'serp' => 'wspólne wyniki TOP10 (pomiar SERP)',
			default => $reason,
		};
	}
}
