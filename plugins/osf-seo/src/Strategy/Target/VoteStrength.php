<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

/**
 * Siła wskazania strony przez jedną rodzinę dowodów.
 */
enum VoteStrength: string
{
	case Strong = 'strong';
	case Medium = 'medium';
	case Weak = 'weak';

	public function points(): int
	{
		return match ($this) {
			self::Strong => 3,
			self::Medium => 2,
			self::Weak => 1,
		};
	}

	public static function max(?self $a, self $b): self
	{
		return $a === null || $b->points() > $a->points() ? $b : $a;
	}
}
