<?php

namespace App\Panel;

use Illuminate\Http\Response;

/**
 * Odpowiedzi błędów panelu renderowane w układzie panelu (nie w layoucie marketingowym).
 */
final class PanelResponse
{
	public static function notFound(): Response
	{
		return self::error(404, 'Nie znaleziono', 'Ta strona nie istnieje albo nie masz do niej dostępu.');
	}

	public static function forbidden(string $message = 'Nie masz uprawnień do wykonania tej operacji.'): Response
	{
		return self::error(403, 'Brak uprawnień', $message);
	}

	public static function unavailable(): Response
	{
		return self::error(503, 'Aplikacja niedostępna', 'Whack-a-mole jest chwilowo niedostępny (plugin aplikacji nie jest aktywny). Skontaktuj się z administratorem.');
	}

	private static function error(int $status, string $title, string $message): Response
	{
		$layout = is_user_logged_in() && function_exists('osf_seo') && current_user_can('osf_seo_access') ? 'app' : 'guest';

		return response()->view('panel.error', compact('status', 'title', 'message', 'layout'), $status);
	}
}
