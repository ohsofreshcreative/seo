<?php

declare(strict_types=1);

namespace OsfSeo\Sync;

use DateTimeImmutable;
use DateTimeZone;
use OsfSeo\Gsc\Dataset;
use OsfSeo\Support\DateRange;

/**
 * Planowanie okien dat (czysta logika, bez bazy). Po jednym oczekującym zadaniu na dataset i rodzaj
 * (`refresh`, `backfill`); kolejne okna planowane są po zakończeniu poprzednich (łańcuch).
 *
 * site (sumy, [date] — kilkaset wierszy na całą historię):
 * - pierwszy import: cała historia (dziś − N miesięcy … wczoraj) jednym zadaniem → najstarsza i najnowsza
 *   dostępna data (ostatnia data `final`) bez zgadywania opóźnienia,
 * - odświeżanie co ≥ 20 h: [najnowsza − (REFRESH_DAYS − 1), wczoraj] — okno kroczące + nowe dni.
 *
 * query, query_page (dopiero gdy znana jest ostatnia dostępna data z `site`):
 * - pierwsze okno: najnowsze dni [latest − w + 1, latest] → dashboard działa po kilku zadaniach,
 * - odświeżanie: gdy pojawiła się nowsza data albo site został odświeżony — cykl od
 *   min(najnowsza + 1, latest − REFRESH_DAYS + 1) do latest, okno po oknie (kursor w sync_state),
 * - backfill: od najstarszej zaimportowanej daty wstecz do początku historii (najnowsze → najstarsze).
 *
 * Priorytet (mniejszy = wcześniej): site 10, odświeżanie fraz 20/25, backfill ostatnich 56 dni 30,
 * starszy backfill 40 (query) / 50 (query_page); zadania ręczne −5.
 */
final class WindowPlanner
{
	/**
	 * @return list<PlannedJob>
	 */
	public static function plan(PlanInput $in): array
	{
		$jobs = [];
		$yesterday = DateRange::shift($in->today, -1);
		$historyStart = self::historyStart($in->today, $in->historyMonths);
		$manual = $in->refreshTrigger === TriggerType::Manual ? -5 : 0;

		// site
		$site = $in->state(Dataset::Site);

		if (($in->force || ! $site->isCoolingDown($in->now)) && ! $in->isPending(Dataset::Site, 'refresh')) {
			$due = $site->lastRefreshAt === null || $in->force || self::hoursSince($site->lastRefreshAt, $in->now) >= SyncConfig::REFRESH_INTERVAL_HOURS;

			if ($due && $historyStart <= $yesterday) {
				$start = $site->newestDate === null
					? $historyStart
					: max($historyStart, DateRange::shift(min($site->newestDate, $yesterday), -($in->refreshDays - 1)));
				$trigger = $site->lastRefreshAt === null && $in->refreshTrigger === TriggerType::Schedule ? TriggerType::Connect : $in->refreshTrigger;
				$jobs[] = new PlannedJob(Dataset::Site, $trigger, new DateRange($start, $yesterday), 10 + $manual);
			}
		}

		if ($site->newestDate === null) {
			return $jobs;
		}

		$latest = $site->newestDate;
		$lower = max($historyStart, $site->oldestDate ?? $latest);

		if ($lower > $latest) {
			return $jobs;
		}

		foreach ([Dataset::Query, Dataset::QueryPage] as $dataset) {
			$state = $in->state($dataset);

			if (! $in->force && $state->isCoolingDown($in->now)) {
				continue;
			}

			$width = SyncConfig::windowDays($dataset, $in->density[$dataset->value] ?? null);
			$refreshPriority = ($dataset === Dataset::Query ? 20 : 25) + $manual;

			if (! $in->isPending($dataset, 'refresh')) {
				$refresh = self::refreshWindow($state, $site, $latest, $lower, $width, $in);

				if ($refresh !== null) {
					$trigger = $state->newestDate === null && $in->refreshTrigger === TriggerType::Schedule ? TriggerType::Connect : $in->refreshTrigger;
					$jobs[] = new PlannedJob($dataset, $trigger, $refresh, $refreshPriority);
				}
			}

			if (! $in->isPending($dataset, 'backfill') && $state->oldestDate !== null && $state->oldestDate > $lower) {
				$end = DateRange::shift($state->oldestDate, -1);
				$start = max($lower, DateRange::shift($state->oldestDate, -$width));
				$recent = DateRange::diffDays($end, $latest) < SyncConfig::RECENT_DAYS;
				$priority = $recent ? 30 : ($dataset === Dataset::Query ? 40 : 50);
				$jobs[] = new PlannedJob($dataset, TriggerType::Backfill, new DateRange($start, $end), $priority);
			}
		}

		return $jobs;
	}

	/** Pierwsza data historii: dziś − N miesięcy (nie zakładamy, że Google gwarantuje dokładnie 16 miesięcy). */
	public static function historyStart(string $today, int $months): string
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));

		return $date === false ? $today : $date->modify(sprintf('-%d months', $months))->format('Y-m-d');
	}

	/** Backfill datasetu zakończony: pokrycie sięga początku dostępnej historii. */
	public static function backfillComplete(SyncState $state, SyncState $site, string $today, int $historyMonths): bool
	{
		if ($state->oldestDate === null || $site->newestDate === null) {
			return false;
		}

		$lower = max(self::historyStart($today, $historyMonths), $site->oldestDate ?? $site->newestDate);

		return $state->oldestDate <= $lower;
	}

	private static function refreshWindow(SyncState $state, SyncState $site, string $latest, string $lower, int $width, PlanInput $in): ?DateRange
	{
		if ($state->newestDate === null) {
			return new DateRange(max($lower, DateRange::shift($latest, -($width - 1))), $latest);
		}

		$cursor = $state->refreshCursor;

		if ($cursor === null) {
			$stale = $state->newestDate < $latest
				|| $state->lastRefreshAt === null
				|| ($site->lastRefreshAt !== null && $state->lastRefreshAt < $site->lastRefreshAt)
				|| $in->force;

			if (! $stale) {
				return null;
			}

			$cursor = max($lower, min(DateRange::shift($state->newestDate, 1), DateRange::shift($latest, -($in->refreshDays - 1))));
		}

		if ($cursor > $latest) {
			return null;
		}

		return new DateRange($cursor, min(DateRange::shift($cursor, $width - 1), $latest));
	}

	private static function hoursSince(string $from, string $now): float
	{
		return (strtotime($now . ' UTC') - strtotime($from . ' UTC')) / 3600;
	}
}
