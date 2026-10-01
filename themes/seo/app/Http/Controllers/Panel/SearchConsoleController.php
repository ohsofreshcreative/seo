<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConfig;
use OsfSeo\Google\OAuthFlow;
use OsfSeo\Google\OAuthFlowException;
use OsfSeo\Gsc\PropertySelectionException;
use OsfSeo\Gsc\PropertyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Połączenie projektu z Google Search Console (OAuth) i wybór property. Logika i tokeny — plugin
 * (OAuthFlow, PropertyService); tutaj tylko przekierowania i komunikaty.
 */
final class SearchConsoleController
{
	public function show(Request $request): Response
	{
		$context = $this->context($request);
		$project = $context->project();
		$config = osf_seo()->get(GoogleConfig::class);
		$canManage = $context->can('osf_seo_manage_connections');
		$connection = $project->connectionId === null ? null : osf_seo()->get(ConnectionRepository::class)->find($project->connectionId);

		// Lista properties z Google tylko dla zarządzających i tylko gdy jest potrzebna (brak wyboru albo zmiana).
		$properties = null;
		$propertiesError = null;
		$choosing = $canManage && $connection?->isActive() && ($project->gscProperty === null || $request->query('change') === '1');

		if ($choosing) {
			try {
				$properties = osf_seo()->get(PropertyService::class)->properties($context);
			} catch (PropertySelectionException $exception) {
				$propertiesError = $exception->userMessage();
			}
		}

		return response()->view('panel.projects.search-console', [
			'project' => $project,
			'connection' => $connection,
			'configured' => $config->isConfigured(),
			'configProblems' => $canManage ? $config->problems() : [],
			'redirectUri' => $config->redirectUri(),
			'canManage' => $canManage,
			'choosing' => $choosing,
			'properties' => $properties,
			'propertiesError' => $propertiesError,
		]);
	}

	public function selectProperty(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$context = osf_seo()->get(PropertyService::class)->select($context, (string) $request->input('property', ''), $request->input('reset_data') === '1');
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (PropertySelectionException $exception) {
			Flash::error($exception->userMessage());

			return redirect()->to(PanelUrl::project($context->publicId(), 'search-console') . '?change=1');
		}

		Flash::success(sprintf('Wybrano property %s.', $context->project()->gscProperty));

		return redirect()->to(PanelUrl::project($context->publicId(), 'search-console'));
	}

	public function connect(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$url = osf_seo()->get(OAuthFlow::class)->start($context);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (OAuthFlowException $exception) {
			Flash::error($exception->userMessage());

			return redirect()->to(PanelUrl::project($context->publicId(), 'search-console'));
		}

		return redirect()->away($url);
	}

	public function disconnect(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$result = osf_seo()->get(OAuthFlow::class)->disconnect($context);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if (! $result->detached) {
			Flash::info('Projekt nie był połączony z Google.');
		} elseif ($result->connectionRemoved && ! $result->revoked) {
			Flash::info('Odłączono projekt i usunięto zapisane dane dostępowe. Nie udało się potwierdzić odwołania dostępu w Google — możesz je usunąć ręcznie w ustawieniach konta Google (Bezpieczeństwo → Aplikacje innych firm).');
		} else {
			Flash::success('Odłączono projekt od Google Search Console.');
		}

		return redirect()->to(PanelUrl::project($context->publicId(), 'search-console'));
	}

	/**
	 * Redirect URI zarejestrowany w Google Cloud: GET /oauth/google/callback?state=…&code=… (albo &error=…).
	 */
	public function callback(Request $request): Response
	{
		try {
			$context = osf_seo()->get(OAuthFlow::class)->complete($request->query->all(), get_current_user_id());
		} catch (OAuthFlowException $exception) {
			Flash::error($exception->userMessage());
			$projectContext = $exception->context();

			return redirect()->to($projectContext === null ? PanelUrl::to() : PanelUrl::project($projectContext->publicId(), 'search-console'));
		} catch (ProjectNotFound) {
			return PanelResponse::notFound();
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success('Połączono projekt z Google Search Console. Wybierz property, z której mają być pobierane dane.');

		return redirect()->to(PanelUrl::project($context->publicId(), 'search-console'));
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}
}
