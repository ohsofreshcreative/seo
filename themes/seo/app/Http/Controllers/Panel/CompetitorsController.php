<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Serp\Competitor;
use OsfSeo\Serp\CompetitorService;
use OsfSeo\Serp\SerpNotFound;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Konkurenci projektu: monitorowani (dodani ręcznie — pozycje odtwarzane z zapisanych pełnych SERP-ów, także wstecz)
 * i organiczni (domeny najczęściej obecne w najnowszych SERP-ach monitorowanych fraz). Żadna akcja nie wywołuje API.
 */
final class CompetitorsController
{
	private const SORTS = ['keywords', 'top10', 'top3', 'overlap', 'avg_rank'];

	public function index(Request $request): Response
	{
		return $this->list($this->context($request), $request->query('archived') === '1');
	}

	public function organic(Request $request): Response
	{
		$context = $this->context($request);
		$page = max(1, min(10000, (int) $request->query('page', '1')));
		$sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'keywords';

		return response()->view('panel.competitors.organic', [
			'project' => $context->project(),
			'data' => $this->service()->organic($context, $page, $sort),
			'page' => $page,
			'sort' => $sort,
			'canManage' => $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	public function show(Request $request, string $project, string $competitor): Response
	{
		$context = $this->context($request);

		try {
			$detail = $this->service()->detail($context, $competitor);
		} catch (SerpNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.competitors.show', $detail + [
			'project' => $context->project(),
			'canManage' => $context->can('osf_seo_manage_serp_tracking'),
			'errors' => [],
		]);
	}

	public function store(Request $request): Response
	{
		$context = $this->context($request);
		$input = $request->only(['name', 'domain']);

		try {
			$competitor = $this->service()->create($context, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			if ($request->input('from') === 'organic') {
				Flash::error(implode(' ', $exception->errors()));

				return redirect()->to(PanelUrl::project($context->publicId(), 'competitors/organic'));
			}

			return $this->list($context, false, $exception->errors(), $input, 422);
		}

		Flash::success(sprintf('Dodano konkurenta %s (%s). Pozycje odczytano z zapisanych pomiarów — bez dodatkowych kosztów.', $competitor->name, $competitor->domain));

		return redirect()->to(self::url($context->publicId(), $competitor->publicId));
	}

	public function update(Request $request, string $project, string $competitor): Response
	{
		$context = $this->context($request);
		$input = $request->only(['name', 'domain', 'status']);

		try {
			$updated = $this->service()->update($context, $competitor, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (SerpNotFound) {
			return PanelResponse::notFound();
		} catch (ValidationException $exception) {
			Flash::error(implode(' ', $exception->errors()));

			return redirect()->to(self::url($context->publicId(), $competitor));
		}

		Flash::success(match (true) {
			isset($input['status']) && $updated->status === Competitor::INACTIVE => sprintf('%s: monitorowanie wstrzymane — konkurent nie jest pokazywany w pozycjach, dane zostają.', $updated->name),
			isset($input['status']) && $updated->status === Competitor::ARCHIVED => sprintf('%s: przeniesiono do archiwum. Dane historyczne zostają.', $updated->name),
			isset($input['status']) && $updated->status === Competitor::ACTIVE => sprintf('%s: konkurent znów aktywny.', $updated->name),
			default => sprintf('Zapisano: %s (%s).', $updated->name, $updated->domain),
		});

		return redirect()->to(self::url($context->publicId(), $updated->publicId));
	}

	public static function url(string $projectId, ?string $competitorId = null): string
	{
		return PanelUrl::project($projectId, 'competitors' . ($competitorId === null ? '' : '/' . rawurlencode($competitorId)));
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $old
	 */
	private function list(ProjectContext $context, bool $archived, array $errors = [], array $old = [], int $status = 200): Response
	{
		return response()->view('panel.competitors.index', [
			'project' => $context->project(),
			'items' => $this->service()->list($context, $archived),
			'archived' => $archived,
			'canManage' => $context->can('osf_seo_manage_serp_tracking'),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): CompetitorService
	{
		return osf_seo()->get(CompetitorService::class);
	}
}
