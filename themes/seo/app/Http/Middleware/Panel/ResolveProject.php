<?php

namespace App\Http\Middleware\Panel;

use App\Panel\PanelResponse;
use Closure;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectGuard;
use OsfSeo\Auth\ProjectNotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parametr trasy `{project}` (public_id) → ProjectContext z pluginu (ProjectGuard).
 * Niewidoczny lub nieistniejący projekt → 404; brak uprawnienia do operacji → 403.
 * Kontrolery czytają kontekst z `$request->attributes->get(ResolveProject::ATTRIBUTE)`.
 */
final class ResolveProject
{
	public const ATTRIBUTE = 'osf_seo.project';

	public function handle(Request $request, Closure $next, string $capability = 'osf_seo_access'): Response
	{
		try {
			$context = osf_seo()->get(ProjectGuard::class)->authorizeCurrentUser((string) $request->route('project'), $capability);
		} catch (ProjectNotFound) {
			return PanelResponse::notFound();
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		$request->attributes->set(self::ATTRIBUTE, $context);

		return $next($request);
	}
}
