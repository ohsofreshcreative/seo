<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use Illuminate\Http\Request;
use OsfSeo\Analytics\KeywordFilters;
use OsfSeo\Analytics\KeywordReport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista fraz projektu z porównaniem okresów. Filtry, sortowanie i paginacja w query stringu;
 * walidacja (biała lista) i SQL — w pluginie (KeywordFilters, KeywordReport).
 */
final class KeywordsController
{
	public function index(Request $request): Response
	{
		$context = $request->attributes->get(ResolveProject::ATTRIBUTE);
		$filters = KeywordFilters::fromInput($request->query->all());
		$report = osf_seo()->get(KeywordReport::class);

		return response()->view('panel.projects.keywords', [
			'project' => $context->project(),
			'page' => $report->keywords($context, $filters),
			'filters' => $filters,
			'moversMinImpressions' => $report->moversMinImpressions(),
		]);
	}
}
