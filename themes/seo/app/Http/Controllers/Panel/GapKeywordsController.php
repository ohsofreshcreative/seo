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
use OsfSeo\Gap\GapFilters;
use OsfSeo\Gap\GapNotFound;
use OsfSeo\Gap\GapService;
use OsfSeo\Gap\GapStatus;
use OsfSeo\Opportunities\Text;
use OsfSeo\Strategy\StrategyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Luki fraz (Keyword Gap): lista z filtrami, szczegóły z dowodami (konkurenci w Labs, pomiar SERP, GSC, punkt odniesienia),
 * praca nad luką (status, notatka) i akcje zbiorcze. Bez SQL i bez wywołań API — dane z usług pluginu.
 */
final class GapKeywordsController
{
	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$service = $this->service();
		$filters = GapFilters::fromInput($request->query->all());

		return response()->view('panel.gaps.keywords', [
			'project' => $context->project(),
			'filters' => $filters,
			'page' => $service->keywords($context, $filters),
			'counts' => $service->counts($context),
			'settings' => $service->settings($context),
			'competitors' => $service->competitors($context),
			'canManage' => $context->can('osf_seo_manage_keyword_gap'),
			'canTrack' => $context->can('osf_seo_manage_serp_tracking'),
		]);
	}

	public function show(Request $request, string $project, string $gap): Response
	{
		return $this->detail($request, $gap);
	}

	public function update(Request $request, string $project, string $gap): Response
	{
		$context = $this->context($request);
		$status = GapStatus::fromInput($request->input('status'));

		if ($status === null) {
			return $this->detail($request, $gap, ['status' => 'Wybierz status.'], 422);
		}

		try {
			$changed = $this->service()->setStatus($context, 'keyword', [$gap], $status, mb_substr(trim((string) $request->input('note', '')), 0, 2000));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		if ($changed === 0) {
			return PanelResponse::notFound();
		}

		Flash::success('Zapisano: ' . mb_strtolower($status->label()) . '.');

		return redirect()->to(self::keywordUrl($context->publicId(), $gap));
	}

	/** Akcja zbiorcza: status zaznaczonych luk fraz albo grup (maks. 200). */
	public function bulk(Request $request): Response
	{
		$context = $this->context($request);
		$ids = $request->input('ids');
		$ids = is_array($ids) ? array_slice(array_values(array_filter($ids, 'is_string')), 0, 200) : [];
		$kind = $request->input('kind') === 'cluster' ? 'cluster' : 'keyword';
		$status = GapStatus::fromInput($request->input('status'));
		parse_str((string) $request->input('return'), $query);
		$query = is_array($query) ? $query : [];
		$path = $kind === 'cluster' ? 'gaps/content' : 'gaps/keywords';
		$back = redirect()->to(PanelUrl::project($context->publicId(), $path) . ($query === [] ? '' : '?' . http_build_query($query)));

		if ($status === null || $ids === []) {
			Flash::error('Zaznacz co najmniej jedną pozycję i wybierz status.');

			return $back;
		}

		try {
			$changed = $this->service()->setStatus($context, $kind, $ids, $status);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success(sprintf(
			'Zmieniono status: %s.',
			Format::number($changed) . ' ' . ($kind === 'cluster' ? Text::plural($changed, 'grupa', 'grupy', 'grup') : Text::plural($changed, 'fraza', 'frazy', 'fraz')),
		));

		return $back;
	}

	public static function keywordUrl(string $projectId, string $gapId): string
	{
		return PanelUrl::project($projectId, 'gaps/keywords/' . rawurlencode($gapId));
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function detail(Request $request, string $publicId, array $errors = [], int $status = 200): Response
	{
		$context = $this->context($request);

		try {
			$data = $this->service()->keyword($context, $publicId);
		} catch (GapNotFound) {
			return PanelResponse::notFound();
		}

		$marketKeywordId = (int) ($data['row']['market_keyword_id'] ?? 0);

		return response()->view('panel.gaps.keyword', $data + [
			'project' => $context->project(),
			'strategyTopic' => osf_seo()->get(StrategyService::class)->topicsForMarketKeywords($context, [$marketKeywordId])[$marketKeywordId] ?? null,
			'canManage' => $context->can('osf_seo_manage_keyword_gap'),
			'canTrack' => $context->can('osf_seo_manage_serp_tracking'),
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
