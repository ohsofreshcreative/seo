<?php

namespace App\Http\Middleware\Panel;

use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zalogowany użytkownik WordPressa z uprawnieniem `osf_seo_access`.
 * Niezalogowany → /login (z powrotem na żądany adres), zalogowany bez dostępu → 403.
 */
final class Authenticate
{
	public function handle(Request $request, Closure $next): Response
	{
		if (! is_user_logged_in()) {
			return redirect()->to(PanelUrl::login($request->isMethod('GET') ? $request->getRequestUri() : null));
		}

		if (! current_user_can('osf_seo_access')) {
			return PanelResponse::forbidden('To konto nie ma dostępu do Whack-a-mole.');
		}

		return $next($request);
	}
}
