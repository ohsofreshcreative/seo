<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

/**
 * Rola użytkownika w projekcie (osf_project_users). W MVP decyduje o widoczności projektu
 * dla użytkowników bez `osf_seo_view_all_projects`; operacje zmieniające dane wymagają
 * dodatkowo globalnych capabilities. Rozróżnienie manager/viewer jest zapisane z myślą
 * o panelu klienta (MVP 4).
 */
enum ProjectRole: string
{
	case Manager = 'manager';
	case Viewer = 'viewer';

	public function label(): string
	{
		return match ($this) {
			self::Manager => 'Menedżer',
			self::Viewer => 'Podgląd',
		};
	}
}
