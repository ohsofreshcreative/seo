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
use OsfSeo\Serp\PositionsFilters;
use OsfSeo\Serp\SerpConfig;
use OsfSeo\Serp\SerpDevice;
use OsfSeo\Serp\SerpFrequency;
use OsfSeo\Serp\SerpKeywordRules;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Serp\SerpStartResult;
use OsfSeo\Serp\SerpTrackingService;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pozycje SERP (DataForSEO Google Organic): lista monitorowanych fraz, szczegóły z historią i pełnym TOP N, ustawienia
 * śledzenia z podglądem kosztu, „Sprawdź pozycje teraz” (podgląd → potwierdzenie → kolejka) i postęp pomiaru.
 * Kontroler nie wywołuje API: podgląd liczy plan lokalnie, pomiar ręczny tylko kolejkuje potwierdzony plan, płatne
 * zlecenia wysyła przetwarzanie w tle (plugin, pod wspólnymi limitami kosztów).
 */
final class PositionsController
{
	private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$service = $this->service();
		$filters = PositionsFilters::fromInput($request->query->all());
		$canManage = $context->can('osf_seo_manage_serp_tracking');
		$page = $service->positions($context, $filters);

		return response()->view('panel.positions.index', [
			'project' => $context->project(),
			'filters' => $filters,
			'rows' => $page['rows'],
			'total' => $page['total'],
			'competitors' => $page['competitors'],
			'summary' => $service->summary($context),
			'settings' => $service->settings($context),
			'market' => $service->market($context),
			'configured' => $service->provider()->isConfigured(),
			'active' => $service->activeRun($context)?->toArray($canManage),
			'recent' => $service->recentRuns($context, 5),
			'maxKeywords' => $service->config()->maxKeywords(),
			'canManage' => $canManage,
		]);
	}

	public function show(Request $request, string $project, string $keyword): Response
	{
		$context = $this->context($request);
		$snapshot = $request->query('snapshot');
		$snapshot = is_string($snapshot) && preg_match(self::ULID, $snapshot) === 1 ? $snapshot : null;

		try {
			$data = $this->service()->keyword($context, $keyword, $snapshot);
		} catch (SerpNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.positions.show', $data + [
			'project' => $context->project(),
			'chart' => self::chart($data, $context->project()->domain),
			'canManage' => $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	/** „Sprawdź pozycje teraz” — podgląd planu bez żadnego wywołania API (wszystkie frazy albo zaznaczone). */
	public function check(Request $request): Response
	{
		$context = $this->context($request);
		$only = self::ids($request->query('ids'));

		return response()->view('panel.positions.check', [
			'project' => $context->project(),
			'plan' => $this->service()->plan($context, $only),
			'only' => $only,
			'active' => $this->service()->activeRun($context)?->toArray(true),
			'paused' => $this->service()->paused(),
		]);
	}

	/** Uruchomienie pomiaru — kolejkuje plan nie większy i nie droższy niż potwierdzony podgląd. */
	public function start(Request $request): Response
	{
		$context = $this->context($request);
		$only = self::ids($request->input('ids'));
		$tasks = filter_var($request->input('expected_tasks'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
		$cost = filter_var($request->input('expected_cost'), FILTER_VALIDATE_FLOAT);
		$back = redirect()->to(self::checkUrl($context->publicId(), $only));

		if ($tasks === false || $cost === false || $cost < 0) {
			Flash::error('Brak potwierdzonego podglądu kosztu. Sprawdź koszt i uruchom ponownie.');

			return $back;
		}

		try {
			$result = $this->service()->start($context, $tasks, (float) $cost, $only);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($result->queued() && $result->run !== null) {
			Flash::success(sprintf(
				'Pomiar zakolejkowany: %s, maks. %s. Wyniki pojawią się w tle (zwykle w ciągu kilku do kilkudziesięciu minut) — możesz opuścić stronę.',
				Format::number($result->plan?->tasks() ?? 0) . ' ' . Text::plural($result->plan?->tasks() ?? 0, 'fraza', 'frazy', 'fraz'),
				Format::usd($result->plan?->estimatedCost() ?? 0, 4),
			));

			return redirect()->to(self::runUrl($context->publicId(), $result->run->publicId));
		}

		$result->status === SerpStartResult::NOTHING_TO_DO ? Flash::info($result->message()) : Flash::error($result->message());

		return $back;
	}

	public function run(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$serpRun = $this->service()->run($context, $run);
		} catch (SerpNotFound) {
			return PanelResponse::notFound();
		}

		$canManage = $context->can('osf_seo_manage_serp_tracking');

		return response()->view('panel.positions.run', [
			'project' => $context->project(),
			'run' => $serpRun,
			'progress' => $serpRun->toArray($canManage),
			'canManage' => $canManage,
		]);
	}

	/** Postęp pomiaru (JSON, odpytywany przez stronę pomiaru i listę). */
	public function runStatus(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$serpRun = $this->service()->run($context, $run);
		} catch (SerpNotFound) {
			return response()->json(['error' => 'not_found'], 404);
		}

		return response()->json($serpRun->toArray($context->can('osf_seo_manage_serp_tracking')));
	}

	public function cancel(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$this->service()->run($context, $run);
			$cancelled = $this->service()->cancel($context, $run);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (SerpNotFound) {
			return PanelResponse::notFound();
		}

		$cancelled
			? Flash::success('Niewysłane zadania anulowane, rezerwacja kosztu zwolniona. Zadania już zlecone zostają w rejestrze kosztów.')
			: Flash::info('Nie ma czego anulować — zadania zostały już zlecone albo pomiar się zakończył.');

		return redirect()->to(self::runUrl($context->publicId(), $run));
	}

	/** Formularz „Dodaj frazy” (ręcznie) — bez żadnego płatnego żądania. */
	public function create(Request $request): Response
	{
		$context = $this->context($request);

		return $this->addForm($context);
	}

	/**
	 * Dodanie fraz do monitorowania: ręcznie (tekst), z listy Frazy (GSC) albo z Nowych fraz (ULID kandydatów).
	 * Nigdy nie wysyła płatnego żądania i nie uruchamia wzbogacania danymi rynkowymi.
	 */
	public function store(Request $request): Response
	{
		$context = $this->context($request);
		$source = in_array($request->input('source'), ['manual', 'gsc', 'discovery'], true) ? (string) $request->input('source') : 'manual';
		$single = $request->input('single');
		$input = match (true) {
			is_string($single) && $single !== '' => [$single],
			$source === 'manual' => (string) $request->input('keywords', ''),
			default => array_values(array_filter((array) $request->input($source === 'discovery' ? 'ids' : 'keywords', []), 'is_string')),
		};
		$back = $this->back($context, $request->input('back'));

		if ($input === [] || $input === '') {
			Flash::error('Nie wybrano żadnej frazy.');

			return $source === 'manual' ? redirect()->to(PanelUrl::project($context->publicId(), 'positions/add')) : $back;
		}

		try {
			$result = $this->service()->addKeywords($context, $source, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			if ($source === 'manual') {
				return $this->addForm($context, $exception->errors(), (string) $input, 422);
			}

			Flash::error(implode(' ', $exception->errors()));

			return $back;
		}

		$changed = $result['added'] + $result['restored'];
		$message = $changed > 0
			? sprintf('Monitorowanie pozycji: dodano %s.', Format::number($changed) . ' ' . Text::plural($changed, 'frazę', 'frazy', 'fraz'))
			: 'Wybrane frazy są już monitorowane.';

		if ($result['existing'] > 0 && $changed > 0) {
			$message .= sprintf(' Już monitorowane: %s.', Format::number($result['existing']));
		}

		if ($changed > 0) {
			$message .= ' Bez kosztów — pozycje sprawdzi harmonogram albo „Sprawdź pozycje teraz”.';
		}

		$changed > 0 ? Flash::success($message) : Flash::info($message);

		if ($result['rejected'] !== []) {
			Flash::error('Pominięto: ' . implode('; ', array_map(
				static fn (array $item): string => '„' . mb_substr($item['keyword'], 0, 60) . '” — ' . ($item['reason'] === 'not_found' ? 'nie ma jej w frazach GSC projektu' : SerpKeywordRules::label($item['reason'])),
				array_slice($result['rejected'], 0, 10),
			)) . (count($result['rejected']) > 10 ? ' i inne.' : '.'));
		}

		return $back;
	}

	/** Zakończenie monitorowania zaznaczonych fraz — historia pomiarów zostaje. */
	public function remove(Request $request): Response
	{
		$context = $this->context($request);
		$ids = self::ids($request->input('ids')) ?? [];

		try {
			$removed = $this->service()->removeKeywords($context, $ids);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		$removed > 0
			? Flash::success(sprintf('Zakończono monitorowanie: %s. Historia pomiarów jest zachowana — po ponownym dodaniu frazy wraca.', Format::number($removed) . ' ' . Text::plural($removed, 'fraza', 'frazy', 'fraz')))
			: Flash::info('Nie wybrano monitorowanych fraz.');

		return $this->back($context, $request->input('back'));
	}

	/** Ustawienia śledzenia z podglądem kosztu dla wybranych (jeszcze niezapisanych) opcji — bez API. */
	public function settings(Request $request): Response
	{
		return $this->settingsForm($this->context($request), $request->query->all());
	}

	public function saveSettings(Request $request): Response
	{
		$context = $this->context($request);
		$input = $request->only(['enabled', 'frequency', 'device', 'depth', 'confirm']);

		try {
			$settings = $this->service()->saveSettings($context, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			return $this->settingsForm($context, $input, $exception->errors(), 422);
		}

		Flash::success($settings->enabled
			? sprintf('Zapisano: pomiary %s, %s, TOP%d. Najbliższy pomiar: %s.', $settings->frequency->label(), $settings->device->label(), $settings->depth, Format::datetime($settings->nextRunAt))
			: 'Zapisano: automatyczne pomiary są wyłączone (bez kosztów). Pomiar ręczny nadal jest dostępny.');

		return redirect()->to(PanelUrl::project($context->publicId(), 'positions/settings'));
	}

	public static function keywordUrl(string $projectId, string $keywordId, ?string $snapshotId = null): string
	{
		return PanelUrl::project($projectId, 'positions/keywords/' . rawurlencode($keywordId)) . ($snapshotId === null ? '' : '?snapshot=' . rawurlencode($snapshotId));
	}

	public static function runUrl(string $projectId, string $runId): string
	{
		return PanelUrl::project($projectId, 'positions/runs/' . rawurlencode($runId));
	}

	/**
	 * @param list<string>|null $ids
	 */
	public static function checkUrl(string $projectId, ?array $ids = null): string
	{
		$query = $ids === null ? '' : http_build_query(['ids' => $ids]);

		return PanelUrl::project($projectId, 'positions/check') . ($query === '' ? '' : '?' . $query);
	}

	/**
	 * Wykres historii: pozycje projektu i aktywnych konkurentów (od najstarszego pomiaru); poza TOP = przerwa w linii.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function chart(array $data, string $domain): array
	{
		$history = array_reverse($data['history']);
		$series = [[
			'label' => $domain,
			'project' => true,
			'data' => array_map(static fn (array $item): ?int => $item['project_rank'] === null ? null : (int) $item['project_rank'], $history),
		]];

		foreach ($data['competitors'] as $competitor) {
			$series[] = [
				'label' => $competitor->name,
				'project' => false,
				'data' => array_map(static fn (array $item): ?int => $data['history_competitors'][(int) $item['id']][$competitor->publicId][0]['rank'] ?? null, $history),
			];
		}

		return [
			'labels' => array_map(static fn (array $item): string => Format::datetime((string) $item['checked_at']), $history),
			'depth' => (int) ($data['context']?->depth ?? SerpConfig::DEFAULT_DEPTH),
			'series' => $series,
		];
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function addForm(ProjectContext $context, array $errors = [], string $old = '', int $status = 200): Response
	{
		return response()->view('panel.positions.add', [
			'project' => $context->project(),
			'tracked' => $this->service()->summary($context)['tracked'] ?? 0,
			'maxKeywords' => $this->service()->config()->maxKeywords(),
			'market' => $this->service()->market($context),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, string> $errors
	 */
	private function settingsForm(ProjectContext $context, array $input, array $errors = [], int $status = 200): Response
	{
		$service = $this->service();
		$current = $service->settings($context);
		$frequency = SerpFrequency::tryFrom(is_string($input['frequency'] ?? null) ? $input['frequency'] : '') ?? $current->frequency;
		$device = SerpDevice::tryFrom(is_string($input['device'] ?? null) ? $input['device'] : '') ?? $current->device;
		$depth = (int) ($input['depth'] ?? $current->depth);
		$depth = SerpConfig::validDepth($depth) ? $depth : $current->depth;
		$enabled = array_key_exists('enabled', $input) || array_key_exists('frequency', $input) ? in_array($input['enabled'] ?? '', ['1', 'on'], true) : $current->enabled;

		return response()->view('panel.positions.settings', [
			'project' => $context->project(),
			'settings' => $current,
			'frequency' => $frequency,
			'device' => $device,
			'depth' => $depth,
			'enabled' => $enabled,
			'preview' => $service->preview($context, $frequency, $device, $depth),
			'pricing' => $service->pricing(),
			'paused' => $service->paused(),
			'configured' => $service->provider()->isConfigured(),
			'errors' => $errors,
		], $status);
	}

	/** Powrót tylko na adres panelu tego projektu (bez otwartego przekierowania). */
	private function back(ProjectContext $context, mixed $url): \Illuminate\Http\RedirectResponse
	{
		$base = PanelUrl::project($context->publicId());

		return redirect()->to(is_string($url) && ($url === $base || str_starts_with($url, $base . '/') || str_starts_with($url, $base . '?')) && preg_match('/[\r\n\\\\]/', $url) !== 1
			? $url
			: PanelUrl::project($context->publicId(), 'positions'));
	}

	/**
	 * @return list<string>|null
	 */
	private static function ids(mixed $value): ?array
	{
		if (! is_array($value)) {
			return null;
		}

		$ids = array_values(array_unique(array_filter($value, static fn (mixed $id): bool => is_string($id) && preg_match(self::ULID, $id) === 1)));

		return $ids === [] ? null : array_slice($ids, 0, 1000);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): SerpTrackingService
	{
		return osf_seo()->get(SerpTrackingService::class);
	}
}
