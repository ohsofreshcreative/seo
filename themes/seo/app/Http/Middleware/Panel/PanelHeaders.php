<?php

namespace App\Http\Middleware\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nagłówki każdej odpowiedzi panelu: prywatna aplikacja z danymi klientów — bez indeksowania,
 * bez cache pośredników, bez osadzania w ramkach i z ograniczonym Referer.
 */
final class PanelHeaders
{
	public function handle(Request $request, Closure $next): Response
	{
		$response = $next($request);

		$response->headers->set('X-Robots-Tag', 'noindex, nofollow');
		$response->headers->set('Cache-Control', 'private, no-store, max-age=0');
		$response->headers->set('Pragma', 'no-cache');
		$response->headers->set('X-Frame-Options', 'DENY');
		$response->headers->set('Referrer-Policy', 'same-origin');
		$response->headers->set('X-Content-Type-Options', 'nosniff');

		return $response;
	}
}
