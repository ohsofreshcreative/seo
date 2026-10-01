<?php

namespace App\Http\Middleware\Panel;

use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF dla żądań zmieniających dane: nonce WordPressa (powiązany z użytkownikiem i jego sesją)
 * oraz zgodność nagłówka Origin/Referer z adresem aplikacji (obrona w głąb).
 */
final class VerifyNonce
{
	public const ACTION = 'osf_seo_panel';

	public const FIELD = '_wpnonce';

	public function handle(Request $request, Closure $next, string $action = self::ACTION): Response
	{
		if ($request->isMethodSafe()) {
			return $next($request);
		}

		$nonce = (string) $request->input(self::FIELD, $request->header('X-WP-Nonce', ''));

		if (! self::sameOrigin($request) || ! wp_verify_nonce($nonce, $action)) {
			return PanelResponse::forbidden('Sesja formularza wygasła albo żądanie nie pochodzi z panelu. Odśwież stronę i spróbuj ponownie.');
		}

		return $next($request);
	}

	/**
	 * Origin (lub Referer, gdy przeglądarka nie wysłała Origin) musi wskazywać na adres aplikacji.
	 * Brak obu nagłówków nie blokuje żądania — decyduje wtedy nonce.
	 */
	public static function sameOrigin(Request $request): bool
	{
		$source = $request->header('Origin') ?: $request->header('Referer');

		if (! is_string($source) || $source === '') {
			return true;
		}

		return PanelUrl::origin($source) === PanelUrl::origin(home_url('/'));
	}
}
