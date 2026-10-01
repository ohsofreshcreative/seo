<?php

namespace App\Http\Middleware\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WordPress dodaje ukośniki do $_GET/$_POST (wp_magic_quotes) zanim Acorn przechwyci żądanie —
 * bez tego `O'Brien` trafiłby do bazy jako `O\'Brien`.
 */
final class UnslashInput
{
	public function handle(Request $request, Closure $next): Response
	{
		$request->query->replace(wp_unslash($request->query->all()));
		$request->request->replace(wp_unslash($request->request->all()));

		return $next($request);
	}
}
