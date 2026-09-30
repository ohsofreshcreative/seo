<?php

namespace App\Http\Middleware\Panel;

use App\Panel\PanelResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel jest tylko interfejsem — bez aktywnego pluginu osf-seo nie ma danych ani autoryzacji.
 */
final class RequirePlugin
{
	public function handle(Request $request, Closure $next): Response
	{
		if (! function_exists('osf_seo')) {
			return PanelResponse::unavailable();
		}

		return $next($request);
	}
}
