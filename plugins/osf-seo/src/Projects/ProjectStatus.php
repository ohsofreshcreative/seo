<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

enum ProjectStatus: string
{
	case Active = 'active';
	case Paused = 'paused';
	case Archived = 'archived';

	public function label(): string
	{
		return match ($this) {
			self::Active => 'Aktywny',
			self::Paused => 'Wstrzymany',
			self::Archived => 'Zarchiwizowany',
		};
	}
}
