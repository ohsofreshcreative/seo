<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use Illuminate\Http\Request;
use OsfSeo\Analytics\KeywordFilters;
use OsfSeo\Analytics\KeywordReport;
use OsfSeo\Serp\SerpTrackingService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista fraz projektu z porównaniem okresów. Filtry, sortowanie i paginacja w query stringu;
 * walidacja (biała lista) i SQL — w pluginie (KeywordFilters, KeywordReport). Monitorowane frazy dostają kolumnę
 * „Pozycja SERP” (bez żadnego żądania do API).
 */
final class KeywordsController
{
	public function index(Request $request): Response
	{
		$context = $request->attributes->get(ResolveProject::ATTRIBUTE);
		$filters = KeywordFilters::fromInput($request->query->all());
		$report = osf_seo()->get(KeywordReport::class);
		$page = $report->keywords($context, $filters);

		return response()->view('panel.projects.keywords', [
			'project' => $context->project(),
			'page' => $page,
			'filters' => $filters,
			'moversMinImpressions' => $report->moversMinImpressions(),
			// Pozycja SERP (ostatni pomiar) tylko dla fraz monitorowanych — osobna metryka obok średniej pozycji GSC.
			'serpRanks' => osf_seo()->get(SerpTrackingService::class)->ranksForKeywords($context, array_map(static fn ($row): string => $row->keyword, $page->rows)),
			'canTrack' => $context->can('osf_seo_manage_serp_tracking'),
		]);
	}
}
