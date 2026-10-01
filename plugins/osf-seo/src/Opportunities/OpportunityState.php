<?php

declare(strict_types=1);

namespace OsfSeo\Opportunities;

/**
 * Stan wykrycia (ustawiany przez analizę, niezależny od statusu pracy):
 * - active — sygnał spełnia kryteria w co najmniej jednym przeanalizowanym okresie,
 * - inactive — sygnał przestał spełniać kryteria (historia i stan pracy zostają),
 * - archived — dane pochodziły z poprzedniej property GSC (reset danych przy zmianie property).
 */
enum OpportunityState: string
{
	case Active = 'active';
	case Inactive = 'inactive';
	case Archived = 'archived';

	public function label(): string
	{
		return match ($this) {
			self::Active => 'Aktywna',
			self::Inactive => 'Nieaktywna — sygnał nie spełnia już kryteriów',
			self::Archived => 'Archiwalna — dane poprzedniej property GSC',
		};
	}
}
