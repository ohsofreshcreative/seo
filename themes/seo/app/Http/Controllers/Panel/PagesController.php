<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PageLabels;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Ai\Workspace\AiWorkspaceService;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\PageIntelligence\PageIntelligenceService;
use OsfSeo\PageIntelligence\PageJobService;
use OsfSeo\PageIntelligence\PageNotFound;
use OsfSeo\PageIntelligence\PageRefused;
use OsfSeo\PageIntelligence\PageSelection;
use OsfSeo\Strategy\StrategyNotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page Intelligence w panelu (STEP 17, faza D): lista stron i szczegóły zapisanych kopii (bez pobierania przy renderowaniu, bez surowego
 * HTML) oraz jawne zlecenie pobrania: plan (zakres projektu, polityka adresów, pamięć, limity hosta — zero HTTP i DNS) → potwierdzenie →
 * zlecenie w tle (`PageJobService`, wyłącznie przez `PageIntelligenceService::fetch`). Najwyżej 5 adresów; wybór tylko z zakresu
 * projektu (strona tematu, wyniki zapisanego SERP-u, adresy domeny projektu i konkurentów). Pobieranie: `osf_seo_manage_page_intelligence`
 * (trasa, kontroler i usługa); klient tylko odczytuje.
 */
final class PagesController
{
	public const MODES = ['topic', 'serp', 'url', 'page'];

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$filters = [];

		foreach (['kind', 'status', 'quality', 'cache', 'q'] as $key) {
			$value = $request->query($key);
			$filters[$key] = is_string($value) && $value !== '' ? mb_substr($value, 0, 200) : null;
		}

		return response()->view('panel.pages.index', [
			'project' => $context->project(),
			'list' => $this->pages()->list($context, $filters, max(1, (int) $request->query('page', 1))),
			'filters' => $filters,
			'canFetch' => $context->can('osf_seo_manage_page_intelligence'),
			'jobs' => $context->can('osf_seo_manage_page_intelligence') ? array_map(static fn ($job): array => $job->toArray(), $this->jobs()->recent($context, null, 5)) : [],
		]);
	}

	public function show(Request $request, string $project, string $page): Response
	{
		$context = $this->context($request);
		$snapshot = $request->query('snapshot');

		try {
			$data = $this->pages()->pageView($context, $page, is_string($snapshot) ? $snapshot : null);
		} catch (PageNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.pages.show', $data + ['project' => $context->project()]);
	}

	/** Plan pobrania (bez HTTP i DNS): co zostanie pobrane, co jest w pamięci, czego nie wolno pobrać i dlaczego. */
	public function plan(Request $request): Response
	{
		$context = $this->context($request);
		[$selection, $form] = $this->selection($context, $request->query->all());

		if ($selection === null) {
			return response()->view('panel.pages.fetch', ['project' => $context->project(), 'form' => $form, 'plan' => null, 'maxUrls' => $this->jobs()->maxUrls()]);
		}

		try {
			$plan = $this->jobs()->plan($context, $selection, $form['force']);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.pages.fetch', [
			'project' => $context->project(),
			'form' => $form,
			'plan' => $plan,
			'maxUrls' => $this->jobs()->maxUrls(),
		]);
	}

	/** Zlecenie pobrania po potwierdzeniu — plan przeliczany ponownie w usłudze (odmowa całego zlecenia przy adresie spoza zakresu). */
	public function queue(Request $request): Response
	{
		$context = $this->context($request);
		[$selection, $form] = $this->selection($context, $request->request->all());
		$back = self::planUrl($context->publicId(), $form);

		if ($selection === null) {
			Flash::error('Wybierz strony do pobrania.');

			return redirect()->to($back);
		}

		if (! $request->boolean('confirmed')) {
			Flash::error('Potwierdź pobranie wybranych stron.');

			return redirect()->to($back);
		}

		try {
			$result = $this->jobs()->queue($context, $selection, $form['force'], $form['topic'] !== '' ? $form['topic'] : null);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		} catch (PageRefused $refused) {
			Flash::error(PageLabels::error($refused->reason()) ?? 'Nie zlecono pobrania.');

			return redirect()->to($back);
		}

		$result['existing']
			? Flash::info('To samo pobranie jest już w toku — pokazujemy istniejące zlecenie.')
			: Flash::success('Zlecono pobranie. Strony pobierze przetwarzanie w tle (z odstępami między żądaniami do tej samej witryny).');

		return redirect()->to(self::jobUrl($context->publicId(), $result['job']->publicId));
	}

	public function job(Request $request, string $project, string $job): Response
	{
		$context = $this->context($request);

		try {
			$row = $this->jobs()->job($context, $job);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (PageNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.pages.job', ['project' => $context->project(), 'job' => $row->toArray()]);
	}

	/** Anulowanie zlecenia czekającego w kolejce (bez żadnego żądania); w trakcie przebiegu — odmowa. */
	public function cancel(Request $request, string $project, string $job): Response
	{
		$context = $this->context($request);

		try {
			$this->jobs()->cancel($context, $job);
			Flash::success('Anulowano zlecenie pobrania.');
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (PageNotFound) {
			return PanelResponse::notFound();
		} catch (PageRefused $refused) {
			Flash::error(PageLabels::error($refused->reason()) ?? 'Nie można anulować.');
		}

		return redirect()->to(self::jobUrl($context->publicId(), $job));
	}

	public function jobStatus(Request $request, string $project, string $job): Response
	{
		$context = $this->context($request);

		try {
			$row = $this->jobs()->job($context, $job);
		} catch (AccessDenied) {
			return response()->json(['error' => 'forbidden'], 403);
		} catch (PageNotFound) {
			return response()->json(['error' => 'not_found'], 404);
		}

		return response()->json([
			'id' => $row->publicId,
			'status' => $row->status,
			'status_label' => PageLabels::jobStatus($row->status),
			'active' => $row->isActive(),
			'items_done' => $row->itemsDone,
			'items_total' => $row->itemsTotal,
			'stuck' => $row->status === 'queued' && AiWorkspaceService::waiting($row->createdAt),
		]);
	}

	public static function pageUrl(string $projectId, string $pageId, ?string $snapshotId = null): string
	{
		return PanelUrl::project($projectId, 'pages/' . rawurlencode($pageId)) . ($snapshotId === null ? '' : '?snapshot=' . rawurlencode($snapshotId));
	}

	public static function jobUrl(string $projectId, string $jobId): string
	{
		return PanelUrl::project($projectId, 'pages/jobs/' . rawurlencode($jobId));
	}

	/**
	 * @param array<string, mixed> $form
	 */
	public static function planUrl(string $projectId, array $form): string
	{
		$query = array_filter([
			'mode' => $form['mode'] ?? null,
			'topic' => $form['topic'] ?? null,
			'keyword' => $form['keyword'] ?? null,
			'ranks' => ($form['ranks'] ?? []) === [] ? null : array_values($form['ranks']),
			'urls' => $form['urls'] ?? null,
			'page' => $form['page'] ?? null,
			'force' => ($form['force'] ?? false) ? '1' : null,
		], static fn (mixed $value): bool => $value !== null && $value !== '');

		return PanelUrl::project($projectId, 'pages/fetch') . ($query === [] ? '' : '?' . http_build_query($query));
	}

	/**
	 * Wybór z formularza → `PageSelection` (zawsze ograniczony do zakresu projektu przez plan usługi). Tryb `page` (ponowne pobranie) —
	 * adres istniejącej strony projektu odczytany po identyfikatorze w obrębie projektu.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0: ?PageSelection, 1: array<string, mixed>}
	 */
	private function selection(ProjectContext $context, array $input): array
	{
		$mode = in_array($input['mode'] ?? null, self::MODES, true) ? (string) $input['mode'] : 'url';
		$text = static fn (string $key, int $max = 200): string => is_string($input[$key] ?? null) ? trim(mb_substr($input[$key], 0, $max)) : '';
		$ranks = array_values(array_unique(array_filter(array_map('intval', is_array($input['ranks'] ?? null) ? $input['ranks'] : []), static fn (int $rank): bool => $rank >= 1 && $rank <= 100)));
		$urls = $text('urls', 5000);
		$form = [
			'mode' => $mode,
			'topic' => $text('topic', 40),
			'keyword' => $text('keyword', 200),
			'ranks' => array_slice($ranks, 0, 20),
			'urls' => $urls,
			'page' => $text('page', 40),
			'force' => filter_var($input['force'] ?? false, FILTER_VALIDATE_BOOL),
			'page_url' => null,
		];
		$selection = match ($mode) {
			'topic' => $form['topic'] === '' ? null : PageSelection::topic($form['topic']),
			'serp' => $form['keyword'] === '' || $form['ranks'] === [] ? null : PageSelection::serp($form['keyword'], $form['ranks']),
			'page' => null,
			default => $urls === '' ? null : PageSelection::urls(array_slice(preg_split('/[\s,]+/u', $urls) ?: [], 0, 20)),
		};

		if ($mode === 'page' && $form['page'] !== '') {
			try {
				$url = (string) $this->pages()->pageView($context, $form['page'])['page']['url'];
				$form['page_url'] = $url;
				$selection = PageSelection::urls([$url]);
			} catch (PageNotFound) {
				$selection = null;
			}
		}

		return [$selection, $form];
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function pages(): PageIntelligenceService
	{
		return osf_seo()->get(PageIntelligenceService::class);
	}

	private function jobs(): PageJobService
	{
		return osf_seo()->get(PageJobService::class);
	}
}
