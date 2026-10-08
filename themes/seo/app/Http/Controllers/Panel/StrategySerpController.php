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
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpRun;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Strategy\Serp\SerpAnalysisPlan;
use OsfSeo\Strategy\Serp\SerpAnalysisService;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * SERP Intelligence Strategii: lista fraz według stanu zgodnego pomiaru, szczegóły pomiaru frazy (TOP20) oraz płatna analiza SERP:
 * wybór fraz → podgląd (bez żadnego żądania) → potwierdzenie → kolejka (SerpSubmitter STEP 14 przez SerpAnalysisService — wspólne
 * limity kosztów, blokada, odstęp pomiaru ręcznego, obsługa niepewnych zleceń) → status. Kontroler nigdy nie wywołuje API.
 */
final class StrategySerpController
{
	public const FILTERS = ['all', 'fresh', 'stale', 'measured', 'missing'];

	private const PER_PAGE = 50;

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$filter = in_array($request->query('filter'), self::FILTERS, true) ? (string) $request->query('filter') : 'all';
		$page = max(1, (int) filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1]]));
		$service = $this->service();

		return response()->view('panel.strategy.serp', [
			'project' => $context->project(),
			'filter' => $filter,
			'pageNumber' => $page,
			'perPage' => self::PER_PAGE,
			'list' => $service->serpKeywords($context, $filter, $page, self::PER_PAGE),
			'domains' => $service->serpDomains($context),
			'state' => $service->panelState($context),
			'canManage' => $context->can('osf_seo_manage_strategy'),
			'canAnalyze' => $context->can('osf_seo_manage_strategy') && $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	public function show(Request $request, string $project, string $candidate): Response
	{
		$context = $this->context($request);

		try {
			$data = $this->service()->serpDetail($context, $candidate);
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.strategy.serp-keyword', $data + [
			'project' => $context->project(),
			'canAnalyze' => $context->can('osf_seo_manage_strategy') && $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	/** Wybór fraz i podgląd kosztu — bez żadnego żądania do API. */
	public function analysis(Request $request): Response
	{
		$context = $this->context($request);

		if (! $context->can('osf_seo_manage_serp_tracking')) {
			return PanelResponse::forbidden('Płatna analiza SERP wymaga uprawnienia do pomiarów pozycji.');
		}

		$values = self::values($request->query('ids'), $request->query('keywords'));
		$analysis = $this->analysisService();

		try {
			$plan = $analysis->plan($context, $values);
			$status = $analysis->status($context, true);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		return response()->view('panel.strategy.analysis', [
			'project' => $context->project(),
			'plan' => $plan,
			'values' => $values,
			'status' => $status,
			'cooldownMinutes' => (int) ceil(SerpConfig::MANUAL_COOLDOWN / 60),
		]);
	}

	/** Zakolejkowanie analizy — plan nie większy i nie droższy niż potwierdzony podgląd. */
	public function start(Request $request): Response
	{
		$context = $this->context($request);
		$values = self::values($request->input('ids'), $request->input('keywords'));
		$tasks = filter_var($request->input('expected_tasks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
		$cost = filter_var($request->input('expected_cost'), FILTER_VALIDATE_FLOAT);
		$back = redirect()->to(self::analysisUrl($context->publicId(), $values));

		if (! $context->can('osf_seo_manage_serp_tracking')) {
			return PanelResponse::forbidden('Płatna analiza SERP wymaga uprawnienia do pomiarów pozycji.');
		}

		if ($request->input('confirm') !== '1' || $tasks === false || $cost === false || $cost < 0) {
			Flash::error('Brak potwierdzonego podglądu kosztu. Sprawdź koszt i potwierdź ponownie.');

			return $back;
		}

		try {
			$result = $this->analysisService()->start($context, $values, $tasks, (float) $cost, SerpAnalysisService::TRIGGER_MANUAL);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($result['status'] === SerpStartResult::QUEUED && $result['run'] !== null) {
			Flash::success(sprintf(
				'Analiza SERP zakolejkowana: %s, maks. %s. Pomiary działają w tle (zwykle kilka do kilkudziesięciu minut); SERP Intelligence zaktualizuje się po przeliczeniu Strategii.',
				Format::number($result['plan']->tasks()) . ' ' . Text::plural($result['plan']->tasks(), 'nowy pomiar', 'nowe pomiary', 'nowych pomiarów'),
				Format::usd($result['plan']->estimatedCost(), 4),
			));

			return redirect()->to(PositionsController::runUrl($context->publicId(), $result['run']->publicId));
		}

		$message = self::message($result['status'], $result['reason']);
		$result['status'] === SerpStartResult::NOTHING_TO_DO ? Flash::info($message) : Flash::error($message);

		return $back;
	}

	/** Komunikat wyniku zlecenia analizy (bez zakolejkowania nic nie zostało wysłane ani zarezerwowane). */
	public static function message(string $status, ?string $reason): string
	{
		return match ($status) {
			SerpStartResult::NO_KEYWORDS => 'Brak kandydatów Strategii do analizy — najpierw przelicz Strategię.',
			SerpStartResult::NOTHING_TO_DO, SerpAnalysisPlan::NOTHING_TO_DO => 'Nic do zlecenia — wybrane frazy mają świeży pomiar (użyty bez kosztu) albo pomiar w toku.',
			SerpAnalysisPlan::OVER_RUN_LIMIT => 'Wybrano więcej nowych pomiarów niż limit na jedno uruchomienie — wybierz mniej fraz (bez cichego obcinania).',
			SerpStartResult::RATE_LIMITED => 'Pomiar ręczny był uruchomiony przed chwilą (wspólny odstęp z modułem Pozycje). Spróbuj ponownie za kilka minut.',
			SerpStartResult::OVER_BUDGET => 'Analiza przekracza wspólny limit kosztów DataForSEO (' . SerpRun::skipLabel($reason) . ') — nic nie zostało zlecone.',
			default => (new SerpStartResult($status, null, null, $reason))->message(),
		};
	}

	/**
	 * @param list<string>|null $values
	 */
	public static function analysisUrl(string $projectId, ?array $values = null): string
	{
		$query = $values === null ? '' : http_build_query(['ids' => $values]);

		return PanelUrl::project($projectId, 'strategy/analysis') . ($query === '' ? '' : '?' . $query);
	}

	public static function keywordUrl(string $projectId, string $candidateId): string
	{
		return PanelUrl::project($projectId, 'strategy/serp/' . rawurlencode($candidateId));
	}

	/**
	 * Jawny wybór: ULID-y kandydatów (`ids[]`) i/lub frazy z pola tekstowego; null — kolejność priorytetu Strategii.
	 *
	 * @return list<string>|null
	 */
	private static function values(mixed $ids, mixed $keywords): ?array
	{
		$values = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];

		if (is_string($keywords)) {
			$values = [...$values, ...(preg_split('/[\r\n]+/u', mb_substr($keywords, 0, 20000)) ?: [])];
		}

		$values = array_slice(array_values(array_unique(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''))), 0, 200);

		return $values === [] ? null : $values;
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): StrategyService
	{
		return osf_seo()->get(StrategyService::class);
	}

	private function analysisService(): SerpAnalysisService
	{
		return osf_seo()->get(SerpAnalysisService::class);
	}
}
