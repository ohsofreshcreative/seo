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
use OsfSeo\Discovery\CandidateFilters;
use OsfSeo\Discovery\DiscoveryNotFound;
use OsfSeo\Discovery\DiscoveryPlan;
use OsfSeo\Discovery\DiscoveryRequest;
use OsfSeo\Discovery\DiscoveryService;
use OsfSeo\Discovery\DiscoveryStartResult;
use OsfSeo\Market\CostBudget;
use OsfSeo\Opportunities\Text;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nowe frazy (wyszukiwanie fraz, DataForSEO Labs): lista kandydatów, szczegóły i praca nad frazą, podgląd kosztu
 * i uruchomienie przebiegu, postęp. Kontroler nie wywołuje API — podgląd liczy plan bez żądań, uruchomienie tylko
 * kolejkuje potwierdzony plan, płatne żądania wysyła przetwarzanie w tle (plugin, pod limitami kosztów).
 */
final class DiscoveryController
{
	private const REQUEST_FIELDS = ['seeds', 'seeds_gsc', 'seeds_opportunity', 'method', 'depth', 'max_candidates', 'min_volume', 'max_kd', 'other_language', 'force'];

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$service = $this->service();
		$filters = CandidateFilters::fromInput($request->query->all());
		$canManage = $context->can('osf_seo_manage_keyword_discovery');

		return response()->view('panel.discovery.index', [
			'project' => $context->project(),
			'filters' => $filters,
			'page' => $service->list($context, $filters),
			'status' => $service->status($context),
			'recent' => $service->recentRuns($context, 5),
			'exclusions' => $canManage ? $service->exclusions($context)->toText() : '',
			'canManage' => $canManage,
		]);
	}

	public function create(Request $request): Response
	{
		return $this->form($this->context($request));
	}

	/** „Sprawdź koszt” — plan bez żadnego wywołania API. */
	public function preview(Request $request): Response
	{
		$context = $this->context($request);
		$discoveryRequest = $this->service()->request($request->only(self::REQUEST_FIELDS));

		try {
			$plan = $this->service()->plan($context, $discoveryRequest);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		return $this->form($context, $discoveryRequest, $plan);
	}

	/** „Uruchom wyszukiwanie” — kolejkuje plan nie większy i nie droższy niż potwierdzony podgląd. */
	public function start(Request $request): Response
	{
		$context = $this->context($request);
		$requests = filter_var($request->input('expected_requests'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
		$cost = filter_var($request->input('expected_cost'), FILTER_VALIDATE_FLOAT);
		$back = redirect()->to(PanelUrl::project($context->publicId(), 'discovery/new'));

		if ($requests === false || $cost === false || $cost < 0) {
			Flash::error('Brak potwierdzonego podglądu kosztu. Sprawdź koszt i uruchom ponownie.');

			return $back;
		}

		try {
			$result = $this->service()->start($context, $this->service()->request($request->only(self::REQUEST_FIELDS)), DiscoveryService::TRIGGER_MANUAL, $requests, (float) $cost);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($result->isQueued() && $result->run !== null) {
			Flash::success(sprintf(
				'Wyszukiwanie zakolejkowane: %s, maks. %s. Wyniki pojawią się w ciągu kilku minut — możesz opuścić stronę.',
				Format::number($result->plan?->requests() ?? 0) . ' ' . Text::plural($result->plan?->requests() ?? 0, 'żądanie', 'żądania', 'żądań'),
				Format::usd($result->plan?->estimatedCost() ?? 0, 4),
			));

			return redirect()->to(self::runUrl($context->publicId(), $result->run->publicId));
		}

		[$type, $message] = self::startMessage($result);
		Flash::{$type}($message);

		return $result->status === DiscoveryStartResult::ALREADY_RUNNING && $result->run !== null
			? redirect()->to(self::runUrl($context->publicId(), $result->run->publicId))
			: $back;
	}

	public function run(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);
		$service = $this->service();

		try {
			$discoveryRun = $service->run($context, $run);
		} catch (DiscoveryNotFound) {
			return PanelResponse::notFound();
		}

		$canManage = $context->can('osf_seo_manage_keyword_discovery');

		return response()->view('panel.discovery.run', [
			'project' => $context->project(),
			'run' => $discoveryRun,
			'seeds' => $service->runSeeds($discoveryRun),
			'progress' => $service->progress($discoveryRun, $canManage),
			'canManage' => $canManage,
		]);
	}

	/** Postęp przebiegu (JSON, odpytywany przez stronę przebiegu i listę). */
	public function runStatus(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$discoveryRun = $this->service()->run($context, $run);
		} catch (DiscoveryNotFound) {
			return response()->json(['error' => 'not_found'], 404);
		}

		return response()->json($this->service()->progress($discoveryRun, $context->can('osf_seo_manage_keyword_discovery')));
	}

	public function cancel(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$cancelled = $this->service()->cancel($context, $run);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (DiscoveryNotFound) {
			return PanelResponse::notFound();
		}

		$cancelled
			? Flash::success('Wyszukiwanie anulowane. Wysłane już żądania pozostają w rejestrze kosztów.')
			: Flash::info('Wyszukiwanie już się zakończyło.');

		return redirect()->to(self::runUrl($context->publicId(), $run));
	}

	public function show(Request $request, string $project, string $candidate): Response
	{
		return $this->detail($request, $candidate);
	}

	public function update(Request $request, string $project, string $candidate): Response
	{
		$context = $this->context($request);

		try {
			$updated = $this->service()->update($context, $candidate, $request->only(['status', 'note']));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (DiscoveryNotFound) {
			return PanelResponse::notFound();
		} catch (ValidationException $exception) {
			return $this->detail($request, $candidate, $exception->errors(), $request->only(['status', 'note']), 422);
		}

		Flash::success(sprintf('Zapisano: %s — %s.', $updated->keyword, mb_strtolower($updated->status->label())));

		return redirect()->to(self::candidateUrl($context->publicId(), $updated->publicId));
	}

	/** Akcja zbiorcza: status zaznaczonych fraz (maks. 100). */
	public function bulk(Request $request): Response
	{
		$context = $this->context($request);
		$ids = $request->input('ids');
		$ids = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
		parse_str((string) $request->input('return'), $query);
		$back = redirect()->to($this->listUrl($context->publicId(), CandidateFilters::fromInput(is_array($query) ? $query : [])));

		try {
			$updated = $this->service()->bulkUpdate($context, $ids, (string) $request->input('status'));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			Flash::error(implode(' ', $exception->errors()));

			return $back;
		}

		Flash::success(sprintf('Zmieniono status %s.', Format::number($updated) . ' ' . Text::plural($updated, 'frazy', 'fraz', 'fraz')));

		return $back;
	}

	public function exclusions(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$list = $this->service()->saveExclusions($context, (string) $request->input('excluded_terms'));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success(sprintf('Zapisano wykluczenia (%d) — lista fraz została przeliczona.', count($list->terms)));

		return redirect()->to(PanelUrl::project($context->publicId(), 'discovery'));
	}

	public static function runUrl(string $projectId, string $runId): string
	{
		return PanelUrl::project($projectId, 'discovery/runs/' . rawurlencode($runId));
	}

	public static function candidateUrl(string $projectId, string $candidateId): string
	{
		return PanelUrl::project($projectId, 'discovery/keywords/' . rawurlencode($candidateId));
	}

	private function listUrl(string $projectId, CandidateFilters $filters): string
	{
		$query = http_build_query($filters->toQuery());

		return PanelUrl::project($projectId, 'discovery') . ($query === '' ? '' : '?' . $query);
	}

	private function form(ProjectContext $context, ?DiscoveryRequest $discoveryRequest = null, ?DiscoveryPlan $plan = null): Response
	{
		$service = $this->service();

		return response()->view('panel.discovery.new', [
			'project' => $context->project(),
			'status' => $service->status($context),
			'suggestions' => $service->suggestions($context),
			'request' => $discoveryRequest,
			'plan' => $plan,
			'config' => $service->config(),
			'methods' => $service->provider()->methods(),
		]);
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $old
	 */
	private function detail(Request $request, string $publicId, array $errors = [], array $old = [], int $status = 200): Response
	{
		$context = $this->context($request);

		try {
			$candidate = $this->service()->candidate($context, $publicId);
		} catch (DiscoveryNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.discovery.show', [
			'project' => $context->project(),
			'candidate' => $candidate,
			'market' => $this->service()->market($context),
			'settings' => $this->service()->config()->effective(),
			'canManage' => $context->can('osf_seo_manage_keyword_discovery'),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	/**
	 * @return array{0: string, 1: string} typ komunikatu flash, treść
	 */
	private static function startMessage(DiscoveryStartResult $result): array
	{
		return match ($result->status) {
			DiscoveryStartResult::NOTHING_TO_DO => ['info', 'Wszystkie seedy były sprawdzone w ostatnich dniach (cache) — nic nie wysłano. Zaznacz „Pobierz ponownie”, aby odświeżyć wyniki.'],
			DiscoveryStartResult::PLAN_CHANGED => ['error', 'Plan zmienił się od podglądu (więcej żądań lub wyższy koszt) — sprawdź koszt ponownie.'],
			DiscoveryStartResult::ALREADY_RUNNING => ['info', 'W tym projekcie trwa już wyszukiwanie — poczekaj na jego zakończenie.'],
			DiscoveryStartResult::RATE_LIMITED => ['info', 'Wyszukiwanie uruchomiono przed chwilą — spróbuj ponownie za minutę.'],
			DiscoveryStartResult::NOT_CONFIGURED => ['error', 'DataForSEO nie jest skonfigurowane (stałe w wp-config.php).'],
			DiscoveryStartResult::UNSUPPORTED_MARKET => ['error', 'Rynek projektu nie jest obsługiwany — zmień kraj i język w ustawieniach projektu.'],
			DiscoveryStartResult::NO_SEEDS => ['error', 'Podaj co najmniej jedną poprawną frazę startową (seed).'],
			DiscoveryStartResult::OVER_BUDGET => ['error', 'Maksymalny koszt przekracza pozostały limit: ' . CostBudget::label((string) $result->reason) . '. Zmniejsz limit fraz lub liczbę seedów.'],
			DiscoveryStartResult::PAUSED => ['error', 'Płatne żądania DataForSEO są wstrzymane po błędzie konta (logowanie lub środki) — sprawdź stronę „Dane rynkowe”.'],
			default => ['error', 'Nie uruchomiono wyszukiwania.'],
		};
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): DiscoveryService
	{
		return osf_seo()->get(DiscoveryService::class);
	}
}
