<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Analytics\ReportCache;
use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Support\Logger;
use OsfSeo\Support\ValidationException;

/**
 * Konkurenci projektu (docs/ARCHITECTURE.md, sekcja 13):
 *
 * - dodani ręcznie (monitorowani) — pozycje wyliczane z zapisanych pełnych SERP-ów (także wstecz, po dodaniu później),
 * - organiczni — domeny, które najczęściej pojawiają się w najnowszych SERP-ach monitorowanych fraz (fakty, bez ocen).
 *
 * Zmiany wymagają `osf_seo_manage_serp_tracking`; żadna operacja nie wysyła płatnych żądań.
 */
final class CompetitorService
{
	public function __construct(
		private readonly CompetitorRepository $competitors,
		private readonly SerpReports $reports,
		private readonly SerpTrackingService $serp,
		private readonly Logger $logger,
		private readonly ?ReportCache $cache = null,
	) {
	}

	/**
	 * Konkurenci z podsumowaniem z najnowszych pomiarów (liczba fraz z domeną, TOP3/10/20, średnia pozycja SERP).
	 *
	 * @return list<array{competitor: Competitor, stats: array<string, int|float|null>}>
	 */
	public function list(ProjectContext $context, bool $withArchived = false): array
	{
		$competitors = $this->competitors->all($context->projectId(), $withArchived);
		$latest = $this->reports->latestSnapshots($context->projectId());
		$results = $this->reports->familyResults(array_keys($latest), $this->serp->families($competitors));

		return array_map(fn (Competitor $competitor): array => [
			'competitor' => $competitor,
			'stats' => self::stats(array_map(static fn (array $bySnapshot): ?int => $bySnapshot[$competitor->publicId][0]['rank'] ?? null, $results), count($latest)),
		], $competitors);
	}

	/**
	 * Szczegóły konkurenta: pozycje na monitorowanych frazach (ostatni pomiar i zmiana względem poprzedniego porównywalnego),
	 * adresy, które rankują, i podsumowanie.
	 *
	 * @return array<string, mixed>
	 */
	public function detail(ProjectContext $context, string $publicId): array
	{
		$competitor = $this->competitors->find($context->projectId(), $publicId) ?? throw new SerpNotFound();
		$latest = $this->reports->latestSnapshots($context->projectId());
		$families = $this->serp->families([$competitor]);
		$snapshotIds = array_keys($latest);
		$previousIds = array_values(array_filter(array_map(static fn (array $row): ?int => $row['prev_snapshot_id'], $latest)));
		$results = $this->reports->familyResults([...$snapshotIds, ...$previousIds], $families);
		$rows = [];
		$urls = [];

		foreach ($latest as $snapshotId => $keyword) {
			$found = $results[$snapshotId][$competitor->publicId] ?? [];
			$previous = $keyword['prev_snapshot_id'] === null ? null : ($results[$keyword['prev_snapshot_id']][$competitor->publicId] ?? []);
			$best = $found[0]['rank'] ?? null;
			$previousBest = $previous === null ? null : ($previous[0]['rank'] ?? null);
			$change = RankChange::compare($previous !== null, $previous !== null, $previousBest, $best);

			foreach ($found as $item) {
				$urls[$item['url']] = ($urls[$item['url']] ?? 0) + 1;
			}

			$rows[] = [
				'keyword' => $keyword['keyword'],
				'tracked_id' => $keyword['public_id'],
				'search_volume' => $keyword['search_volume'],
				'project_rank' => $keyword['rank'],
				'project_found' => $keyword['found'],
				'depth' => $keyword['depth'],
				'rank' => $best,
				'url' => $found[0]['url'] ?? null,
				'other_urls' => array_slice(array_map(static fn (array $item): array => ['rank' => $item['rank'], 'url' => $item['url']], $found), 1),
				'change' => $change,
				'checked_at' => $keyword['checked_at'],
			];
		}

		usort($rows, static fn (array $a, array $b): int => [$a['rank'] === null, $a['rank'], $a['keyword']] <=> [$b['rank'] === null, $b['rank'], $b['keyword']]);
		arsort($urls);

		return [
			'competitor' => $competitor,
			'stats' => self::stats(array_map(static fn (array $row): ?int => $row['rank'], $rows), count($latest)),
			'rows' => $rows,
			'urls' => $urls,
		];
	}

	/**
	 * @param array<string, mixed> $input name, domain
	 *
	 * @throws ValidationException
	 */
	public function create(ProjectContext $context, array $input): Competitor
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		[$name, $domain] = $this->validate($context, $input, null);
		$competitor = $this->competitors->create($context->projectId(), $name, $domain, $context->userId());
		$this->logger->info('Competitor {domain} added to project {project} by user {user}.', ['domain' => $domain, 'project' => $context->publicId(), 'user' => $context->userId()]);

		return $competitor;
	}

	/**
	 * @param array<string, mixed> $input name, domain, status
	 *
	 * @throws ValidationException
	 */
	public function update(ProjectContext $context, string $publicId, array $input): Competitor
	{
		$context->assertCan(Capabilities::MANAGE_SERP_TRACKING);
		$competitor = $this->competitors->find($context->projectId(), $publicId) ?? throw new SerpNotFound();
		[$name, $domain] = $this->validate($context, $input + ['name' => $competitor->name, 'domain' => $competitor->domain], $competitor);
		$status = is_string($input['status'] ?? null) && in_array($input['status'], Competitor::STATUSES, true) ? $input['status'] : $competitor->status;

		return $this->competitors->update($competitor, $name, $domain, $status, $context->userId());
	}

	/**
	 * Konkurenci organiczni (bez domen projektu), z oznaczeniem już monitorowanych. Agregacja najnowszych pełnych SERP-ów
	 * (setki tysięcy wierszy przy tysiącach fraz) jest zapamiętywana; klucz zawiera stan pomiarów projektu (nowy pomiar,
	 * dodanie lub usunięcie frazy zmienia klucz), więc nie wymaga jawnego unieważniania.
	 *
	 * @return array{rows: list<array<string, mixed>>, total: int, keywords: int}
	 */
	public function organic(ProjectContext $context, int $page = 1, string $sort = 'keywords', int $perPage = 50): array
	{
		$project = DomainFamily::normalize($context->project()->domain) ?? $context->project()->domain;
		$exclude = $this->reports->familyDomainIds($project);
		$version = $this->reports->measurementsVersion($context->projectId());
		$compute = fn (): array => $this->reports->organicCompetitors($context->projectId(), $exclude, $perPage, ($page - 1) * $perPage, $sort);
		$data = $this->cache === null || $version['checked'] === 0
			? $compute()
			: $this->cache->remember(['serp_organic', $context->projectId(), $version['key'], $exclude, $sort, $page, $perPage], $compute);
		$configured = $this->competitors->all($context->projectId(), true);

		foreach ($data['rows'] as &$row) {
			$row['competitor'] = null;

			foreach ($configured as $competitor) {
				if (DomainFamily::matches($row['host'], $competitor->domain)) {
					$row['competitor'] = $competitor;

					break;
				}
			}
		}

		unset($row);

		return $data + ['keywords' => $version['checked']];
	}

	/**
	 * @param list<?int> $ranks najlepsza pozycja na frazę (null — brak w wynikach)
	 * @return array<string, int|float|null>
	 */
	public static function stats(array $ranks, int $checked): array
	{
		$found = array_values(array_filter($ranks, static fn (?int $rank): bool => $rank !== null));

		return [
			'checked' => $checked,
			'found' => count($found),
			'top3' => count(array_filter($found, static fn (int $rank): bool => $rank <= 3)),
			'top10' => count(array_filter($found, static fn (int $rank): bool => $rank <= 10)),
			'top20' => count(array_filter($found, static fn (int $rank): bool => $rank <= 20)),
			'avg_rank' => $found === [] ? null : round(array_sum($found) / count($found), 1),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array{0: string, 1: string}
	 *
	 * @throws ValidationException
	 */
	private function validate(ProjectContext $context, array $input, ?Competitor $current): array
	{
		$domain = DomainFamily::normalize(is_string($input['domain'] ?? null) ? $input['domain'] : '');
		$name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
		$errors = [];

		if ($domain === null) {
			$errors['domain'] = 'Podaj prawidłową domenę, np. konkurent.pl.';
		} else {
			$project = DomainFamily::normalize($context->project()->domain) ?? $context->project()->domain;

			if (DomainFamily::overlaps($domain, $project)) {
				$errors['domain'] = 'To domena projektu (albo jej subdomena / domena nadrzędna) — nie może być konkurentem.';
			} else {
				$existing = $this->competitors->findByDomain($context->projectId(), $domain);

				if ($existing !== null && $existing->id !== $current?->id) {
					$errors['domain'] = $existing->status === Competitor::ARCHIVED
						? 'Ta domena jest w archiwum konkurentów — przywróć ją zamiast dodawać ponownie.'
						: 'Ten konkurent jest już na liście.';
				}
			}
		}

		if (mb_strlen($name) > 190) {
			$errors['name'] = 'Nazwa może mieć najwyżej 190 znaków.';
		}

		if ($errors !== []) {
			throw new ValidationException($errors);
		}

		return [$name === '' ? (string) $domain : $name, (string) $domain];
	}
}
