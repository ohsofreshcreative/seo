<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

/**
 * Sygnały jednego źródła. `omitted` — frazy, które spełniają kryteria źródła, ale nie zostały zwrócone, bo i tak nie zmieszczą
 * się w limicie kandydatów (źródło GSC zwraca najwyżej limit fraz — reszta jest tylko liczona jako nadmiar).
 */
final class SignalBatch
{
	/**
	 * @param list<SourceSignal> $signals
	 */
	public function __construct(
		public readonly array $signals,
		public readonly int $omitted = 0,
	) {
	}
}
