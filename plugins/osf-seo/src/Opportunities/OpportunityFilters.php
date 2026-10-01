<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

use OsfSeo\Analytics\Period;

/**
 * Filtry listy szans z zapytania HTTP/CLI — wyłącznie przez białe listy (nic nie trafia do SQL bez walidacji).
 */
final class OpportunityFilters
{
	public const PRIORITIES = [0, 30, 50, 70];

	/** open = Nowa, Do analizy, Zaplanowana, W trakcie; all = wszystkie statusy. */
	public const STATUS_OPEN = 'open';

	public const STATUS_ALL = 'all';

	public const STATES = ['active', 'inactive', 'archived'];

	public const VIEWS = ['list', 'pages'];

	public const PER_PAGE = 25;

	public function __construct(
		public readonly int $days = Period::DEFAULT_DAYS,
		public readonly ?string $type = null,
		public readonly string $status = self::STATUS_OPEN,
		public readonly int $minPriority = 0,
		/** 0 = dowolna, 1–3 = co najmniej Niska/Średnia/Wysoka. */
		public readonly int $minConfidence = 0,
		public readonly string $search = '',
		public readonly string $state = 'active',
		public readonly string $view = 'list',
		public readonly int $page = 1,
		public readonly int $perPage = self::PER_PAGE,
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public static function fromInput(array $input): self
	{
		$status = $input['status'] ?? self::STATUS_OPEN;
		$priority = (int) ($input['priority'] ?? 0);
		$confidence = (int) ($input['confidence'] ?? 0);

		return new self(
			days: Period::days($input['days'] ?? null),
			type: in_array($input['type'] ?? null, OpportunityType::values(), true) ? (string) $input['type'] : null,
			status: in_array($status, [self::STATUS_OPEN, self::STATUS_ALL, ...OpportunityStatus::values()], true) ? (string) $status : self::STATUS_OPEN,
			minPriority: in_array($priority, self::PRIORITIES, true) ? $priority : 0,
			minConfidence: $confidence >= 1 && $confidence <= 3 ? $confidence : 0,
			search: is_string($input['q'] ?? null) ? mb_substr(trim((string) $input['q']), 0, 100) : '',
			state: in_array($input['state'] ?? null, self::STATES, true) ? (string) $input['state'] : 'active',
			view: in_array($input['view'] ?? null, self::VIEWS, true) ? (string) $input['view'] : 'list',
			page: max(1, min((int) ($input['page'] ?? 1), 100000)),
		);
	}

	/**
	 * @param array<string, mixed> $changes
	 */
	public function with(array $changes): self
	{
		return new self(...array_merge(get_object_vars($this), $changes));
	}

	/**
	 * Statusy do filtra SQL (null = bez filtra).
	 *
	 * @return list<string>|null
	 */
	public function statuses(): ?array
	{
		return match ($this->status) {
			self::STATUS_ALL => null,
			self::STATUS_OPEN => OpportunityStatus::openValues(),
			default => [$this->status],
		};
	}

	/** Widok grupowany po podstronach — tylko dla aktywnych szans. */
	public function groupsByPage(): bool
	{
		return $this->view === 'pages' && $this->state === 'active';
	}

	/**
	 * Parametry zapytania dla linków (bez wartości domyślnych).
	 *
	 * @return array<string, string|int>
	 */
	public function toQuery(): array
	{
		return array_filter([
			'days' => $this->days !== Period::DEFAULT_DAYS ? $this->days : null,
			'type' => $this->type,
			'status' => $this->status !== self::STATUS_OPEN ? $this->status : null,
			'priority' => $this->minPriority > 0 ? $this->minPriority : null,
			'confidence' => $this->minConfidence > 0 ? $this->minConfidence : null,
			'q' => $this->search !== '' ? $this->search : null,
			'state' => $this->state !== 'active' ? $this->state : null,
			'view' => $this->view !== 'list' ? $this->view : null,
			'page' => $this->page > 1 ? $this->page : null,
		], static fn (mixed $value): bool => $value !== null);
	}
}
