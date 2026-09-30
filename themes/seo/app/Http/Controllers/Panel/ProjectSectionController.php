<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sekcje projektu, które powstaną w kolejnych etapach — na razie informacja „w przygotowaniu”.
 */
final class ProjectSectionController
{
	public const SECTIONS = [
		'keywords' => ['title' => 'Frazy', 'description' => 'Ranking fraz z Google Search Console: średnia pozycja (GSC), kliknięcia, wyświetlenia, CTR i porównanie okresów.'],
		'opportunities' => ['title' => 'Szanse SEO', 'description' => 'Frazy z potencjałem wzrostu i przejrzystym Opportunity Score (MVP 2).'],
		'pages' => ['title' => 'Strony', 'description' => 'Landing pages z Google Search Console i frazy dla każdego adresu (MVP 2).'],
		'search-console' => ['title' => 'Search Console', 'description' => 'Połączenie projektu z Google Search Console i wybór property.'],
		'audit' => ['title' => 'Audyt', 'description' => 'Techniczny audyt SEO z własnego crawlera (MVP 3).'],
	];

	public function show(Request $request, string $project, string $section): Response
	{
		return response()->view('panel.projects.section', [
			'project' => $request->attributes->get(ResolveProject::ATTRIBUTE)->project(),
			'section' => $section,
			'title' => self::SECTIONS[$section]['title'],
			'description' => self::SECTIONS[$section]['description'],
		]);
	}
}
