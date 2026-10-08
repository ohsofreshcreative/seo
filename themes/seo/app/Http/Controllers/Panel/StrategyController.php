<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\Format;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Opportunities\Text;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\CandidateRow;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\StrategySource;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strategia: przegląd (liczniki z zapisanego stanu, wysoki priorytet, zmiany po decyzji, zdarzenia, aktualność źródeł), ustawienia
 * (limity, wpisy ręczne, zlecenie przeliczenia). Bez SQL i bez wywołań API — dane z usług pluginu. Przeliczenie nigdy nie działa
 * w żądaniu WWW: panel zapisuje zlecenie, wykona je krok w tle (faza E) albo `wp osf-seo strategy:refresh`.
 */
final class StrategyController
{
	public function index(Request $request): Response
	{
		$context = $this->context($request);

		return response()->view('panel.strategy.index', $this->service()->overview($context) + [
			'project' => $context->project(),
			'canManage' => $context->can('osf_seo_manage_strategy'),
			'canAnalyze' => $context->can('osf_seo_manage_strategy') && $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	public function settings(Request $request): Response
	{
		return $this->settingsView($this->context($request));
	}

	/** Ręczne zlecenie przeliczenia — tylko zapis zlecenia (bez przeliczenia w żądaniu). */
	public function refresh(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$this->service()->requestRefresh($context);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success('Przeliczenie Strategii zlecone (bez kosztów, bez zmiany statusów pracy). Automatyczne przeliczanie w tle nie jest jeszcze włączone — zlecenie wykona administrator komendą „wp osf-seo strategy:refresh”.');

		return redirect()->to(self::backUrl($context, $request->input('back'), PanelUrl::project($context->publicId(), 'strategy')));
	}

	/** Wpisy ręczne — bez żądań do API (fakty uzupełni przeliczenie). */
	public function addKeywords(Request $request): Response
	{
		$context = $this->context($request);
		$input = mb_substr((string) $request->input('keywords', ''), 0, 20000);

		try {
			$result = $this->service()->addKeywords($context, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			return $this->settingsView($context, $exception->errors(), ['keywords' => $input], 422);
		}

		$message = sprintf(
			'Dodano %s, już w Strategii: %s.',
			Format::number($result['added']) . ' ' . Text::plural($result['added'], 'frazę', 'frazy', 'fraz'),
			Format::number($result['existing']),
		);

		if ($result['rejected'] !== []) {
			$message .= ' Odrzucono: ' . implode(', ', array_map(static fn (array $item): string => '„' . $item['keyword'] . '”', array_slice($result['rejected'], 0, 10))) . '.';
		}

		$result['added'] > 0 ? Flash::success($message . ' Fakty i tematy uzupełni najbliższe przeliczenie.') : Flash::info($message);

		return redirect()->to(PanelUrl::project($context->publicId(), 'strategy/settings'));
	}

	public function removeKeywords(Request $request): Response
	{
		$context = $this->context($request);
		$values = $request->input('ids');
		$values = is_array($values) ? array_slice(array_values(array_filter($values, 'is_string')), 0, 200) : [];

		try {
			$removed = $this->service()->removeKeywords($context, $values);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		$removed > 0
			? Flash::success('Zdjęto wpis ręczny: ' . Format::number($removed) . ' ' . Text::plural($removed, 'fraza', 'frazy', 'fraz') . '. Fraza zostaje w Strategii, jeśli wskazuje ją inne źródło.')
			: Flash::info('Nie zdjęto żadnego wpisu ręcznego.');

		return redirect()->to(PanelUrl::project($context->publicId(), 'strategy/settings'));
	}

	/** Powrót tylko na adres panelu tego projektu (bez otwartego przekierowania). */
	public static function backUrl(ProjectContext $context, mixed $url, string $fallback): string
	{
		$base = PanelUrl::project($context->publicId());

		return is_string($url) && ($url === $base || str_starts_with($url, $base . '/') || str_starts_with($url, $base . '?')) && preg_match('/[\r\n\\\\]/', $url) !== 1
			? $url
			: $fallback;
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $old
	 */
	private function settingsView(ProjectContext $context, array $errors = [], array $old = [], int $status = 200): Response
	{
		$service = $this->service();

		return response()->view('panel.strategy.settings', [
			'project' => $context->project(),
			'state' => $service->panelState($context),
			'config' => $service->config()->effective(),
			'manual' => array_values(array_filter(
				$service->candidates($context, new CandidateFilters(source: StrategySource::Manual, status: 'all', sort: 'keyword', perPage: 500))['rows'],
				static fn (CandidateRow $row): bool => $row->manual,
			)),
			'canManage' => true,
			'canAnalyze' => $context->can('osf_seo_manage_serp_tracking'),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): StrategyService
	{
		return osf_seo()->get(StrategyService::class);
	}
}
