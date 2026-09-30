<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\VerifyNonce;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\LoginThrottle;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logowanie do panelu przez mechanizm WordPressa (wp_signon — działają też wtyczki
 * bezpieczeństwa podpięte pod `authenticate`). Reset hasła: standardowy formularz WordPressa.
 */
final class AuthController
{
	public const NONCE_ACTION = 'osf_seo_login';

	public function show(Request $request): Response
	{
		if (is_user_logged_in() && current_user_can('osf_seo_access')) {
			return redirect()->to(PanelUrl::afterLogin($request->query('redirect_to')));
		}

		return $this->form($request, $request->query('wylogowano') ? ['info' => 'Wylogowano.'] : []);
	}

	public function login(Request $request): Response
	{
		if (! VerifyNonce::sameOrigin($request) || ! wp_verify_nonce((string) $request->input('_wpnonce'), self::NONCE_ACTION)) {
			return $this->form($request, ['error' => 'Sesja formularza wygasła. Spróbuj ponownie.'], 403);
		}

		$login = trim((string) $request->input('log'));
		$password = (string) $request->input('pwd');
		$ip = (string) $request->server('REMOTE_ADDR', '');
		$throttle = osf_seo()->get(LoginThrottle::class);

		if ($throttle->isLocked($login, $ip)) {
			return $this->form($request, ['error' => 'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za kilkanaście minut.'], 429);
		}

		if ($login === '' || $password === '') {
			return $this->form($request, ['error' => 'Podaj login i hasło.'], 422);
		}

		$user = wp_signon([
			'user_login' => $login,
			'user_password' => $password,
			'remember' => (bool) $request->input('remember'),
		], is_ssl());

		if (is_wp_error($user)) {
			$throttle->recordFailure($login, $ip);

			return $this->form($request, ['error' => 'Nieprawidłowy login lub hasło.'], 422);
		}

		if (! user_can($user, 'osf_seo_access')) {
			wp_logout();

			return $this->form($request, ['error' => 'To konto nie ma dostępu do OSF SEO.'], 403);
		}

		$throttle->clear($login, $ip);

		return redirect()->to(PanelUrl::afterLogin($request->input('redirect_to')));
	}

	public function logout(): Response
	{
		wp_logout();

		return redirect()->to(add_query_arg('wylogowano', '1', PanelUrl::to('login')));
	}

	/**
	 * @param array{error?: string, info?: string} $messages
	 */
	private function form(Request $request, array $messages = [], int $status = 200): Response
	{
		return response()->view('panel.auth.login', [
			'messages' => $messages,
			'login' => (string) $request->input('log', ''),
			'redirectTo' => PanelUrl::safeReturnPath($request->input('redirect_to', $request->query('redirect_to'))),
			'nonceAction' => self::NONCE_ACTION,
			'lostPasswordUrl' => wp_lostpassword_url(PanelUrl::to('login')),
		], $status);
	}
}
