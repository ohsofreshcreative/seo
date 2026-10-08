<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Decision;

/**
 * Wynik klasyfikatora: działanie, kod powodu, wszystkie spełnione podstawy i ślad sprawdzonych reguł (wyjaśnialność bez AI).
 */
final class Decision
{
	/**
	 * @param list<string> $basis
	 * @param list<array{rule: string, passed: bool, why: string}> $checks
	 */
	public function __construct(
		public readonly StrategyAction $action,
		public readonly string $reason,
		public readonly array $basis,
		public readonly array $checks,
		public readonly bool $positionDependent,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'action' => $this->action->value,
			'reason' => $this->reason,
			'basis' => $this->basis,
			'position_dependent' => $this->positionDependent,
			'checks' => $this->checks,
		];
	}
}
