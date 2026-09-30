<?php

namespace App\Http\Controllers\Panel;

use App\Panel\PanelResponse;
use OsfSeo\Database\Migrator;
use OsfSeo\Plugin;
use Symfony\Component\HttpFoundation\Response;

final class SettingsController
{
	public function index(): Response
	{
		if (! current_user_can('osf_seo_manage_settings')) {
			return PanelResponse::forbidden();
		}

		$migrator = osf_seo()->get(Migrator::class);

		return response()->view('panel.settings', [
			'pluginVersion' => Plugin::VERSION,
			'schemaVersion' => $migrator->currentVersion(),
			'schemaLatest' => $migrator->latestVersion(),
			'environment' => wp_get_environment_type(),
			'phpVersion' => PHP_VERSION,
			'wpVersion' => get_bloginfo('version'),
		]);
	}
}
