<?php

namespace App\Http\Controllers\Panel;

use App\Panel\PanelResponse;
use OsfSeo\Database\Migrator;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConfig;
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
		$google = osf_seo()->get(GoogleConfig::class);

		return response()->view('panel.settings', [
			'pluginVersion' => Plugin::VERSION,
			'schemaVersion' => $migrator->currentVersion(),
			'schemaLatest' => $migrator->latestVersion(),
			'environment' => wp_get_environment_type(),
			'phpVersion' => PHP_VERSION,
			'wpVersion' => get_bloginfo('version'),
			'googleProblems' => $google->problems(),
			'googleRedirectUri' => $google->redirectUri(),
			'googleScopes' => GoogleConfig::SCOPES,
			'googleConnections' => osf_seo()->get(ConnectionRepository::class)->statusCounts(),
			// Dane rynkowe (DataForSEO): tylko nazwy brakujących stałych, limity i zużycie — nigdy wartości sekretów.
			'market' => osf_seo()->get(\OsfSeo\Market\MarketSyncService::class)->status(),
		]);
	}
}
