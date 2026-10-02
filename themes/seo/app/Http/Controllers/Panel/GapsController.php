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
use OsfSeo\Gap\ContentGap;
use OsfSeo\Gap\GapFilters;
use OsfSeo\Gap\GapNotFound;
use OsfSeo\Gap\GapPlan;
use OsfSeo\Gap\GapRequest;
use OsfSeo\Gap\GapRun;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStartResult;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Opportunities\Text;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Luki SEO (frazy domen konkurentów z DataForSEO Labs): przegląd, import z podglądem kosztu, postęp, ustawienia analizy,
 * harmonogram i warianty marki konkurentów. Kontroler nie wywołuje API — podgląd liczy plan bez żądań, uruchomienie
 * tylko kolejkuje potwierdzony plan, płatne żądania wysyła przetwarzanie w tle (plugin, pod wspólnymi limitami kosztów).
 */
final class GapsController
{
	private const REQUEST_FIELDS = ['competitors', 'preset', 'max_rank', 'min_volume', 'max_rows', 'baseline', 'force'];

	private const SETTINGS_FIELDS = ['competitor_max_rank', 'min_volume', 'max_difficulty', 'include_terms', 'brand_terms', 'fetch_max_rank', 'fetch_min_volume', 'max_rows', 'refresh_days'];

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$service = $this->service();
		$canManage = $context->can('osf_seo_manage_keyword_gap');
		$active = $service->activeRun($context);

		return response()->view('panel.gaps.index', [
			'project' => $context->project(),
			'market' => $service->market($context),
			'configured' => $service->provider()->isConfigured(),
			'settings' => $service->settings($context),
			'datasets' => $service->datasets($context),
			'counts' => $service->counts($context),
			'top' => $service->keywords($context, GapFilters::fromInput([])->withPerPage(10))['rows'],
			'content' => $service->clusters($context, ContentGap::NewPage->value)['rows'],
			'active' => $active === null ? null : self::progress($active, $canManage),
			'recent' => $service->runs($context, 5),
			'canManage' => $canManage,
		]);
	}

	public function import(Request $request): Response
	{
		$context = $this->context($request);

		return $this->form($context, $this->service()->request($context, []));
	}

	/** „Sprawdź koszt” — plan bez żadnego wywołania API. */
	public function preview(Request $request): Response
	{
		$context = $this->context($request);
		$gapRequest = $this->service()->request($context, $request->only(self::REQUEST_FIELDS));

		try {
			$plan = $this->service()->plan($context, $gapRequest);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		return $this->form($context, $gapRequest, $plan);
	}

	/** „Uruchom import” — kolejkuje plan nie większy i nie droższy niż potwierdzony podgląd. */
	public function start(Request $request): Response
	{
		$context = $this->context($request);
		$requests = filter_var($request->input('expected_requests'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
		$cost = filter_var($request->input('expected_cost'), FILTER_VALIDATE_FLOAT);
		$back = redirect()->to(PanelUrl::project($context->publicId(), 'gaps/import'));

		if ($requests === false || $cost === false || $cost < 0) {
			Flash::error('Brak potwierdzonego podglądu kosztu. Sprawdź koszt i uruchom ponownie.');

			return $back;
		}

		try {
			$result = $this->service()->start($context, $this->service()->request($context, $request->only(self::REQUEST_FIELDS)), GapService::TRIGGER_MANUAL, $requests, (float) $cost);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($result->isQueued() && $result->run !== null) {
			Flash::success(sprintf(
				'Import zakolejkowany: maks. %s, maks. %s. Działa w tle — możesz opuścić stronę.',
				Format::number($result->plan?->requests() ?? 0) . ' ' . Text::plural($result->plan?->requests() ?? 0, 'żądanie', 'żądania', 'żądań'),
				Format::usd($result->plan?->estimatedCost() ?? 0, 4),
			));

			return redirect()->to(self::runUrl($context->publicId(), $result->run->publicId));
		}

		if ($result->status === GapStartResult::NOTHING_TO_DO) {
			Flash::info($result->message());

			return redirect()->to(PanelUrl::project($context->publicId(), 'gaps'));
		}

		$result->status === GapStartResult::ALREADY_RUNNING ? Flash::info($result->message()) : Flash::error($result->message());

		return $result->status === GapStartResult::ALREADY_RUNNING && $result->run !== null
			? redirect()->to(self::runUrl($context->publicId(), $result->run->publicId))
			: $back;
	}

	public function run(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);
		$service = $this->service();

		try {
			$gapRun = $service->run($context, $run);
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		$canManage = $context->can('osf_seo_manage_keyword_gap');

		return response()->view('panel.gaps.run', [
			'project' => $context->project(),
			'run' => $gapRun,
			'targets' => $service->runTargets($context, $gapRun),
			'progress' => self::progress($gapRun, $canManage),
			'canManage' => $canManage,
		]);
	}

	/** Postęp importu (JSON, odpytywany przez stronę importu i przegląd). */
	public function runStatus(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$gapRun = $this->service()->run($context, $run);
		} catch (GapNotFound) {
			return response()->json(['error' => 'not_found'], 404);
		}

		return response()->json(self::progress($gapRun, $context->can('osf_seo_manage_keyword_gap')));
	}

	public function cancel(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$cancelled = $this->service()->cancel($context, $run);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		$cancelled
			? Flash::success('Import anulowany. Pobrane strony wyników zostają, wysłane żądania pozostają w rejestrze kosztów.')
			: Flash::info('Import już się zakończył.');

		return redirect()->to(self::runUrl($context->publicId(), $run));
	}

	/** Bezpłatne przeliczenie luk z zapisanych danych (GSC, pomiary SERP, zbiory domen, ustawienia) — w tle. */
	public function recalculate(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$this->service()->scheduleRecalculation($context);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success('Przeliczenie zaplanowane — luki odświeżą się w tle w najbliższym kroku przetwarzania (bez kosztów).');

		return redirect()->to(PanelUrl::project($context->publicId(), 'gaps'));
	}

	public function settings(Request $request): Response
	{
		return $this->settingsView($this->context($request));
	}

	public function saveSettings(Request $request): Response
	{
		$context = $this->context($request);
		$input = $request->only(self::SETTINGS_FIELDS);

		try {
			$this->service()->saveSettings($context, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			return $this->settingsView($context, $exception->errors(), $input, 422);
		}

		Flash::success('Zapisano ustawienia Luk SEO — luki przeliczą się w tle (bez kosztów).');

		return redirect()->to(PanelUrl::project($context->publicId(), 'gaps/settings'));
	}

	/** Harmonogram odświeżania: włączenie oznacza przyszłe płatne importy — wymaga potwierdzenia kosztu. */
	public function schedule(Request $request): Response
	{
		$context = $this->context($request);
		$enable = $request->input('enabled') === '1';

		try {
			$this->service()->setSchedule($context, $enable, $request->input('confirm') === '1');
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			Flash::error(implode(' ', $exception->errors()));

			return redirect()->to(PanelUrl::project($context->publicId(), 'gaps/settings'));
		}

		$enable
			? Flash::success('Harmonogram odświeżania włączony — import wystartuje w tle, jeśli zmieści się w limitach kosztów.')
			: Flash::success('Harmonogram odświeżania wyłączony.');

		return redirect()->to(PanelUrl::project($context->publicId(), 'gaps/settings'));
	}

	public function brand(Request $request, string $project, string $competitor): Response
	{
		$context = $this->context($request);

		try {
			$saved = $this->service()->saveBrandTerms($context, $competitor, (string) $request->input('brand_terms'));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		Flash::success(sprintf('Zapisano warianty marki: %s — luki przeliczą się w tle.', $saved->name));

		return redirect()->to(PanelUrl::project($context->publicId(), 'gaps/settings'));
	}

	public static function runUrl(string $projectId, string $runId): string
	{
		return PanelUrl::project($projectId, 'gaps/runs/' . rawurlencode($runId));
	}

	/**
	 * Postęp importu dla strony i odpytywania (koszty tylko dla osób z uprawnieniem do importu).
	 *
	 * @return array<string, mixed>
	 */
	public static function progress(GapRun $run, bool $withCost): array
	{
		$progress = $run->toArray();

		if (! $withCost) {
			unset($progress['cost'], $progress['estimated_cost']);
		}

		return $progress;
	}

	private function form(ProjectContext $context, GapRequest $gapRequest, ?GapPlan $plan = null): Response
	{
		$service = $this->service();
		$active = $service->activeRun($context);

		return response()->view('panel.gaps.import', [
			'project' => $context->project(),
			'market' => $service->market($context),
			'configured' => $service->provider()->isConfigured(),
			'competitors' => array_values(array_filter($service->datasets($context), static fn (array $entry): bool => $entry['role'] === 'competitor')),
			'request' => $gapRequest,
			'plan' => $plan,
			'active' => $active === null ? null : self::progress($active, true),
			'paused' => osf_seo()->get(MarketSyncService::class)->paused(),
			'ttlDays' => $service->config()->ttlDays(),
			'maxRows' => $service->provider()->maxRowsPerDomain(),
		]);
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $old
	 */
	private function settingsView(ProjectContext $context, array $errors = [], array $old = [], int $status = 200): Response
	{
		$service = $this->service();

		return response()->view('panel.gaps.settings', [
			'project' => $context->project(),
			'settings' => $service->settings($context),
			'datasets' => $service->datasets($context),
			'monthly' => $service->monthlyEstimate($context),
			'config' => $service->config()->effective(),
			'maxRows' => $service->provider()->maxRowsPerDomain(),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): GapService
	{
		return osf_seo()->get(GapService::class);
	}
}
