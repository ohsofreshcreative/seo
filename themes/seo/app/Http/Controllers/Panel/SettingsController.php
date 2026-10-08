<?php

namespace App\Http\Controllers\Panel;

use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Branding\BrandingService;
use OsfSeo\Database\Migrator;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Plugin;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ustawienia aplikacji (`osf_seo_manage_settings`): stan, integracje i wygląd aplikacji (logo z biblioteki mediów WordPressa — zapis
 * identyfikatora załącznika w pluginie, `BrandingService`; uprawnienie sprawdzane w trasie, kontrolerze i usłudze).
 */
final class SettingsController
{
	public function index(): Response
	{
		if (! current_user_can('osf_seo_manage_settings')) {
			return PanelResponse::forbidden();
		}

		$migrator = osf_seo()->get(Migrator::class);
		$google = osf_seo()->get(GoogleConfig::class);

		$branding = osf_seo()->get(BrandingService::class);

		return response()->view('panel.settings', [
			'logo' => $branding->logo(),
			'logoLibrary' => $branding->libraryImages(24),
			'logoMaxBytes' => BrandingService::MAX_BYTES,
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
			'discovery' => osf_seo()->get(\OsfSeo\Discovery\DiscoveryService::class)->config()->effective(),
			'serp' => [
				'config' => osf_seo()->get(\OsfSeo\Serp\SerpTrackingService::class)->config()->effective(),
				'pricing' => osf_seo()->get(\OsfSeo\Serp\SerpTrackingService::class)->pricing(),
			],
			// Analizy AI i pobieranie stron (STEP 17): stan konfiguracji serwera, budżet AI i kolejki — bez wartości sekretów; zmiana tylko w wp-config.php.
			'ai' => osf_seo()->get(\OsfSeo\Ai\Workspace\AiWorkspaceService::class)->settings(),
			'pages' => [
				'config' => osf_seo()->get(\OsfSeo\PageIntelligence\PageIntelligenceConfig::class)->effective(),
				'transport' => \OsfSeo\PageIntelligence\PageIntelligenceService::transportSupported(),
			],
		]);
	}

	/** Nowe logo: przesłany obraz trafia do biblioteki mediów WordPressa (PNG, JPG, WebP — bez SVG). */
	public function uploadLogo(Request $request): Response
	{
		if (! current_user_can('osf_seo_manage_settings')) {
			return PanelResponse::forbidden();
		}

		$file = $request->file('logo');

		if ($file === null || is_array($file) || ! $file->isValid()) {
			Flash::error($file !== null && ! is_array($file) && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
				? 'Plik jest za duży dla ustawień serwera.'
				: 'Wybierz plik obrazu PNG, JPG albo WebP do przesłania.');

			return $this->back();
		}

		return $this->apply(static fn (BrandingService $branding, int $userId): mixed => $branding->upload([
			'name' => $file->getClientOriginalName(),
			'tmp_name' => $file->getPathname(),
			'error' => $file->getError(),
			'size' => (int) $file->getSize(),
		], $userId), 'Logo przesłane do biblioteki mediów i ustawione.');
	}

	/** Logo wybrane z biblioteki mediów (identyfikator załącznika). */
	public function selectLogo(Request $request): Response
	{
		$attachmentId = (int) $request->input('attachment_id', 0);

		return $this->apply(static fn (BrandingService $branding, int $userId): mixed => $branding->select($attachmentId, $userId), 'Logo zmienione.');
	}

	/** Usunięcie logo — panel wraca do napisu „Whack-a-mole” (obraz zostaje w bibliotece mediów). */
	public function removeLogo(): Response
	{
		return $this->apply(static function (BrandingService $branding, int $userId): void {
			$branding->remove($userId);
		}, 'Logo usunięte — panel pokazuje napis „Whack-a-mole”.');
	}

	/**
	 * @param \Closure(BrandingService, int): mixed $action
	 */
	private function apply(\Closure $action, string $success): Response
	{
		if (! current_user_can('osf_seo_manage_settings')) {
			return PanelResponse::forbidden();
		}

		try {
			$action(osf_seo()->get(BrandingService::class), get_current_user_id());
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			Flash::error(implode(' ', $exception->errors()));

			return $this->back();
		}

		Flash::success($success);

		return $this->back();
	}

	private function back(): Response
	{
		return redirect()->to(PanelUrl::to('settings'));
	}
}
