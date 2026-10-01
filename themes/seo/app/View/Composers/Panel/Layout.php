<?php

namespace App\View\Composers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Projects\ProjectService;
use Roots\Acorn\View\Composer;

/**
 * Dane wspólne układu panelu: użytkownik, projekty do przełącznika, bieżący projekt, flash.
 */
class Layout extends Composer
{
	protected static $views = [
		'panel.layouts.app',
	];

	public function with(): array
	{
		$user = wp_get_current_user();
		$context = request()->attributes->get(ResolveProject::ATTRIBUTE);

		return [
			'panelUser' => [
				'name' => $user->display_name !== '' ? $user->display_name : $user->user_login,
				'email' => $user->user_email,
			],
			'navProjects' => osf_seo()->get(ProjectService::class)->listFor($user->ID),
			'currentProject' => $context instanceof ProjectContext ? $context->project() : null,
			'flashMessages' => Flash::pull(),
			'canManageProjects' => current_user_can('osf_seo_manage_projects'),
			'canManageSettings' => current_user_can('osf_seo_manage_settings'),
		];
	}
}
