<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Gap\ContentGap;
use OsfSeo\Gap\GapNotFound;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStatus;
use OsfSeo\Strategy\StrategyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Luki treści (grupy fraz z heurystyką „Potencjalna luka treści / Istniejąca strona — do wzmocnienia / Bez luki treści /
 * Niejasne”) i strony konkurencji (agregaty fraz zbiorów domen). Bez SQL i bez wywołań API — dane z usług pluginu.
 */
final class GapContentController
{
	private const SORTS = ['priority', 'volume', 'keywords', 'competitor_rank'];

	private const PAGE_SORTS = ['gap', 'keywords', 'volume', 'top10'];

	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$content = ContentGap::fromInput($request->query('content'))?->value;
		$status = (string) $request->query('status', '');
		$q = mb_substr(trim((string) $request->query('q', '')), 0, 100);
		$sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'priority';
		$page = max(1, (int) $request->query('page', 1));

		return response()->view('panel.gaps.content', [
			'project' => $context->project(),
			'content' => $content,
			'status' => $status,
			'q' => $q,
			'sort' => $sort,
			'pageNumber' => $page,
			'result' => $this->service()->clusters($context, $content, $status, $q, $sort, $page),
			'counts' => $this->service()->counts($context),
			'canManage' => $context->can('osf_seo_manage_keyword_gap'),
		]);
	}

	public function show(Request $request, string $project, string $cluster): Response
	{
		return $this->detail($request, $cluster);
	}

	public function update(Request $request, string $project, string $cluster): Response
	{
		$context = $this->context($request);
		$status = GapStatus::fromInput($request->input('status'));

		if ($status === null) {
			return $this->detail($request, $cluster, ['status' => 'Wybierz status.'], 422);
		}

		try {
			$changed = $this->service()->setStatus($context, 'cluster', [$cluster], $status, mb_substr(trim((string) $request->input('note', '')), 0, 2000));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($changed === 0) {
			return PanelResponse::notFound();
		}

		Flash::success('Zapisano: ' . mb_strtolower($status->label()) . '.');

		return redirect()->to(self::clusterUrl($context->publicId(), $cluster));
	}

	public function pages(Request $request): Response
	{
		$context = $this->context($request);
		$competitor = (string) $request->query('competitor', '');
		$competitor = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $competitor) === 1 ? $competitor : null;
		$q = mb_substr(trim((string) $request->query('q', '')), 0, 200);
		$sort = in_array($request->query('sort'), self::PAGE_SORTS, true) ? (string) $request->query('sort') : 'gap';
		$page = max(1, (int) $request->query('page', 1));

		return response()->view('panel.gaps.pages', [
			'project' => $context->project(),
			'competitor' => $competitor,
			'q' => $q,
			'sort' => $sort,
			'pageNumber' => $page,
			'result' => $this->service()->pages($context, $competitor, $q, $sort, $page),
			'competitors' => $this->service()->competitors($context),
		]);
	}

	public function page(Request $request, string $project, string $competitor, string $url): Response
	{
		$context = $this->context($request);

		try {
			$data = $this->service()->page($context, $competitor, $url);
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.gaps.page', $data + ['project' => $context->project()]);
	}

	public static function clusterUrl(string $projectId, string $clusterId): string
	{
		return PanelUrl::project($projectId, 'gaps/content/' . rawurlencode($clusterId));
	}

	public static function pageUrl(string $projectId, string $competitorId, string $urlKey): string
	{
		return PanelUrl::project($projectId, 'gaps/pages/' . rawurlencode($competitorId) . '/' . rawurlencode($urlKey));
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function detail(Request $request, string $publicId, array $errors = [], int $status = 200): Response
	{
		$context = $this->context($request);

		try {
			$data = $this->service()->cluster($context, $publicId);
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.gaps.cluster', $data + [
			'project' => $context->project(),
			'strategyTopics' => osf_seo()->get(StrategyService::class)->topicsForMarketKeywords($context, array_map(static fn (array $row): int => (int) $row['market_keyword_id'], $data['keywords'])),
			'canManage' => $context->can('osf_seo_manage_keyword_gap'),
			'errors' => $errors,
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
