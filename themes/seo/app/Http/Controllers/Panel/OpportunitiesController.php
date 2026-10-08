<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Analytics\Period;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Opportunities\AnalysisResult;
use OsfSeo\Opportunities\OpportunityFilters;
use OsfSeo\Opportunities\OpportunityNotFound;
use OsfSeo\Opportunities\OpportunityService;
use OsfSeo\Support\ValidationException;
use OsfSeo\Strategy\StrategyService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Szanse SEO projektu: lista (także wg podstron), szczegóły z dowodami, praca nad szansą i ręczne przeliczenie.
 * Logika, SQL i autoryzacja operacji — w pluginie (OpportunityService, ProjectGuard przez ResolveProject).
 */
final class OpportunitiesController
{
	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$filters = OpportunityFilters::fromInput($request->query->all());
		$service = $this->service();

		return response()->view('panel.opportunities.index', [
			'project' => $context->project(),
			'filters' => $filters,
			'page' => $service->list($context, $filters),
			'summary' => $service->summary($context, $filters->days),
			'analysis' => $service->analyses($context)[$filters->days] ?? null,
			'canManage' => $context->can('osf_seo_manage_opportunities'),
		]);
	}

	public function show(Request $request, string $project, string $opportunity): Response
	{
		return $this->detail($request, $opportunity);
	}

	public function update(Request $request, string $project, string $opportunity): Response
	{
		$context = $this->context($request);

		try {
			$updated = $this->service()->update($context, $opportunity, $request->only(['status', 'note', 'completed_on']), wp_date('Y-m-d'));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (OpportunityNotFound) {
			return PanelResponse::notFound();
		} catch (ValidationException $exception) {
			return $this->detail($request, $opportunity, $exception->errors(), $request->only(['status', 'note', 'completed_on']), 422);
		}

		Flash::success(sprintf('Zapisano: %s — %s.', $updated->title(), mb_strtolower($updated->status->label())));

		return redirect()->to(self::detailUrl($context->publicId(), $updated->publicId, Period::days($request->input('days'))));
	}

	/**
	 * „Przelicz szanse” — plugin pilnuje uprawnień, blokady projektu i limitu jednego przeliczenia na minutę.
	 */
	public function analyze(Request $request): Response
	{
		$context = $this->context($request);

		try {
			$result = $this->service()->requestAnalysis($context);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		match ($result['status']) {
			'rate_limited' => Flash::info('Szanse były przeliczane przed chwilą — spróbuj ponownie za minutę.'),
			'busy' => Flash::info('Analiza szans tego projektu właśnie trwa — odśwież stronę za chwilę.'),
			default => self::flashResults($result['results']),
		};

		return redirect()->to(PanelUrl::project($context->publicId(), 'opportunities'));
	}

	/**
	 * @param array<string, string> $errors
	 * @param array<string, mixed> $old
	 */
	private function detail(Request $request, string $publicId, array $errors = [], array $old = [], int $status = 200): Response
	{
		$context = $this->context($request);
		$days = Period::days($request->input('days'));
		$service = $this->service();

		try {
			$opportunity = $service->find($context, $publicId, $days);
		} catch (OpportunityNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.opportunities.show', [
			'project' => $context->project(),
			'opportunity' => $opportunity,
			'days' => $days,
			'detectedPeriods' => $service->detectedPeriods($context, $opportunity),
			'after' => $service->afterImplementation($context, $opportunity),
			// Dane rynkowe fraz z dowodów (DataForSEO) — wyłącznie kontekst; szansa nie zależy od nich.
			'marketMetrics' => $service->marketMetrics($context, $opportunity),
			// Tematy Strategii fraz szansy — tylko powiązania potwierdzone dowodem Strategii (query × page), bez samego `opportunities.keyword`.
			'strategyTopics' => osf_seo()->get(StrategyService::class)->topicsForOpportunity(
				$context,
				$opportunity->publicId,
				array_values(array_filter(array_map(static fn (mixed $item): ?string => is_array($item) && is_string($item['keyword'] ?? null) ? $item['keyword'] : null, (array) ($opportunity->evidence['keywords'] ?? [])))),
			),
			'canManage' => $context->can('osf_seo_manage_opportunities'),
			'errors' => $errors,
			'old' => $old,
		], $status);
	}

	/**
	 * @param list<AnalysisResult> $results
	 */
	private static function flashResults(array $results): void
	{
		$parts = [];
		$success = false;

		foreach ($results as $result) {
			$parts[] = match ($result->status) {
				AnalysisResult::SUCCESS => sprintf('%d dni — %d', $result->days, $result->opportunities),
				AnalysisResult::FAILED => sprintf('%d dni — błąd analizy', $result->days),
				default => sprintf('%d dni — pominięto (%s)', $result->days, $result->reasonLabel()),
			};
			$success = $success || $result->status === AnalysisResult::SUCCESS;
		}

		$message = 'Przeliczono szanse: ' . implode('; ', $parts) . '.';
		$success ? Flash::success($message) : Flash::info($message);
	}

	public static function detailUrl(string $projectId, string $opportunityId, int $days = Period::DEFAULT_DAYS): string
	{
		return PanelUrl::project($projectId, 'opportunities/' . rawurlencode($opportunityId)) . ($days !== Period::DEFAULT_DAYS ? '?days=' . $days : '');
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): OpportunityService
	{
		return osf_seo()->get(OpportunityService::class);
	}
}
