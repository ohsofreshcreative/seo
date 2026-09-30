<?php

namespace App\Http\Controllers\Panel;

use OsfSeo\Projects\Project;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Projects\ProjectStatus;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController
{
	public function index(): Response
	{
		$projects = osf_seo()->get(ProjectService::class)->listFor(get_current_user_id());

		return response()->view('panel.dashboard', [
			'projects' => $projects,
			'activeCount' => count(array_filter($projects, static fn (Project $p): bool => $p->status === ProjectStatus::Active)),
			'pausedCount' => count(array_filter($projects, static fn (Project $p): bool => $p->status === ProjectStatus::Paused)),
			'canManageProjects' => current_user_can('osf_seo_manage_projects'),
		]);
	}
}
