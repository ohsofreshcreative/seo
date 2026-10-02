<?php

/**
 * Trasy panelu OSF SEO. Middleware globalne (nagłówki, ukośniki WP, wymagany plugin):
 * App\Http\Middleware\Panel\PanelMiddleware::GLOBAL (functions.php).
 *
 * Capabilities jako literały — plik tras ładuje się przy każdym żądaniu, także gdy plugin
 * jest nieaktywny (wtedy RequirePlugin zwraca 503 zamiast błędu krytycznego).
 * Wymaga włączonych „ładnych” odnośników WordPressa (każda ścieżka trafia do index.php).
 */

use App\Http\Controllers\Panel\AuthController;
use App\Http\Controllers\Panel\CompetitorsController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\DiscoveryController;
use App\Http\Controllers\Panel\GapContentController;
use App\Http\Controllers\Panel\GapKeywordsController;
use App\Http\Controllers\Panel\GapsController;
use App\Http\Controllers\Panel\KeywordsController;
use App\Http\Controllers\Panel\MarketDataController;
use App\Http\Controllers\Panel\OpportunitiesController;
use App\Http\Controllers\Panel\PositionsController;
use App\Http\Controllers\Panel\ProjectController;
use App\Http\Controllers\Panel\ProjectSectionController;
use App\Http\Controllers\Panel\SearchConsoleController;
use App\Http\Controllers\Panel\SettingsController;
use App\Http\Middleware\Panel\Authenticate;
use App\Http\Middleware\Panel\ResolveProject;
use App\Http\Middleware\Panel\VerifyNonce;
use App\Panel\PanelResponse;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware([Authenticate::class, VerifyNonce::class])->group(function () {
	Route::post('/logout', [AuthController::class, 'logout']);

	Route::get('/', [DashboardController::class, 'index']);
	Route::get('/settings', [SettingsController::class, 'index']);

	Route::get('/projects', [ProjectController::class, 'index']);
	Route::get('/projects/create', [ProjectController::class, 'create']);
	Route::post('/projects', [ProjectController::class, 'store']);

	Route::middleware(ResolveProject::class . ':osf_seo_manage_projects')->group(function () {
		Route::get('/projects/{project}/edit', [ProjectController::class, 'edit']);
		Route::post('/projects/{project}', [ProjectController::class, 'update']);
		Route::post('/projects/{project}/archive', [ProjectController::class, 'archive']);
		Route::post('/projects/{project}/restore', [ProjectController::class, 'restore']);
		Route::post('/projects/{project}/pause', [ProjectController::class, 'pause']);
	});

	Route::middleware(ResolveProject::class . ':osf_seo_manage_connections')->group(function () {
		Route::post('/projects/{project}/search-console/connect', [SearchConsoleController::class, 'connect']);
		Route::post('/projects/{project}/search-console/disconnect', [SearchConsoleController::class, 'disconnect']);
		Route::post('/projects/{project}/search-console/property', [SearchConsoleController::class, 'selectProperty']);
		Route::post('/projects/{project}/search-console/sync', [SearchConsoleController::class, 'sync']);
	});

	// Szanse SEO: zmiany stanu pracy i ręczne przeliczenie (identyfikator szansy = public_id ULID).
	Route::middleware(ResolveProject::class . ':osf_seo_manage_opportunities')->group(function () {
		Route::post('/projects/{project}/opportunities/analyze', [OpportunitiesController::class, 'analyze']);
		Route::post('/projects/{project}/opportunities/{opportunity}', [OpportunitiesController::class, 'update'])
			->where('opportunity', '[0-9A-Za-z]{26}');
	});

	// Dane rynkowe (DataForSEO, płatne API): ręczna synchronizacja tylko z uprawnieniem, nonce, Origin i potwierdzonym podglądem.
	Route::middleware(ResolveProject::class . ':osf_seo_manage_market_data')->group(function () {
		Route::post('/projects/{project}/market-data/sync', [MarketDataController::class, 'sync']);
	});

	// Nowe frazy (wyszukiwanie fraz, płatne API): podgląd kosztu, uruchomienie, praca nad frazami i wykluczenia tylko
	// z uprawnieniem, nonce i Origin; identyfikatory przebiegu i frazy = public_id (ULID).
	Route::middleware(ResolveProject::class . ':osf_seo_manage_keyword_discovery')->group(function () {
		Route::get('/projects/{project}/discovery/new', [DiscoveryController::class, 'create']);
		Route::post('/projects/{project}/discovery/preview', [DiscoveryController::class, 'preview']);
		Route::post('/projects/{project}/discovery/runs', [DiscoveryController::class, 'start']);
		Route::post('/projects/{project}/discovery/runs/{run}/cancel', [DiscoveryController::class, 'cancel'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::post('/projects/{project}/discovery/bulk', [DiscoveryController::class, 'bulk']);
		Route::post('/projects/{project}/discovery/exclusions', [DiscoveryController::class, 'exclusions']);
		Route::post('/projects/{project}/discovery/keywords/{candidate}', [DiscoveryController::class, 'update'])
			->where('candidate', '[0-9A-Za-z]{26}');
	});

	// Pozycje SERP i konkurenci (pomiary płatne): ustawienia, podgląd kosztu i uruchomienie pomiaru, monitorowane frazy
	// i konkurenci tylko z uprawnieniem, nonce i Origin; identyfikatory = public_id (ULID). Kontroler nigdy nie wywołuje API.
	Route::middleware(ResolveProject::class . ':osf_seo_manage_serp_tracking')->group(function () {
		Route::get('/projects/{project}/positions/check', [PositionsController::class, 'check']);
		Route::post('/projects/{project}/positions/check', [PositionsController::class, 'start']);
		Route::post('/projects/{project}/positions/runs/{run}/cancel', [PositionsController::class, 'cancel'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/positions/add', [PositionsController::class, 'create']);
		Route::post('/projects/{project}/positions/keywords', [PositionsController::class, 'store']);
		Route::post('/projects/{project}/positions/remove', [PositionsController::class, 'remove']);
		Route::get('/projects/{project}/positions/settings', [PositionsController::class, 'settings']);
		Route::post('/projects/{project}/positions/settings', [PositionsController::class, 'saveSettings']);
		Route::post('/projects/{project}/competitors', [CompetitorsController::class, 'store']);
		Route::post('/projects/{project}/competitors/{competitor}', [CompetitorsController::class, 'update'])
			->where('competitor', '[0-9A-Za-z]{26}');
	});

	// Luki SEO (frazy domen konkurentów, płatne API): podgląd kosztu, import, ustawienia, warianty marki i praca nad lukami
	// tylko z uprawnieniem, nonce i Origin; identyfikatory = public_id (ULID). Kontroler nigdy nie wywołuje API.
	Route::middleware(ResolveProject::class . ':osf_seo_manage_keyword_gap')->group(function () {
		Route::get('/projects/{project}/gaps/import', [GapsController::class, 'import']);
		Route::post('/projects/{project}/gaps/preview', [GapsController::class, 'preview']);
		Route::post('/projects/{project}/gaps/runs', [GapsController::class, 'start']);
		Route::post('/projects/{project}/gaps/runs/{run}/cancel', [GapsController::class, 'cancel'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::post('/projects/{project}/gaps/recalculate', [GapsController::class, 'recalculate']);
		Route::get('/projects/{project}/gaps/settings', [GapsController::class, 'settings']);
		Route::post('/projects/{project}/gaps/settings', [GapsController::class, 'saveSettings']);
		Route::post('/projects/{project}/gaps/schedule', [GapsController::class, 'schedule']);
		Route::post('/projects/{project}/gaps/competitors/{competitor}/brand', [GapsController::class, 'brand'])
			->where('competitor', '[0-9A-Za-z]{26}');
		Route::post('/projects/{project}/gaps/bulk', [GapKeywordsController::class, 'bulk']);
		Route::post('/projects/{project}/gaps/keywords/{gap}', [GapKeywordsController::class, 'update'])
			->where('gap', '[0-9A-Za-z]{26}');
		Route::post('/projects/{project}/gaps/content/{cluster}', [GapContentController::class, 'update'])
			->where('cluster', '[0-9A-Za-z]{26}');
	});

	Route::middleware(ResolveProject::class)->group(function () {
		Route::get('/projects/{project}', [ProjectController::class, 'show']);
		Route::get('/projects/{project}/gaps', [GapsController::class, 'index']);
		Route::get('/projects/{project}/gaps/runs/{run}', [GapsController::class, 'run'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/gaps/runs/{run}/status', [GapsController::class, 'runStatus'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/gaps/keywords', [GapKeywordsController::class, 'index']);
		Route::get('/projects/{project}/gaps/keywords/{gap}', [GapKeywordsController::class, 'show'])
			->where('gap', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/gaps/content', [GapContentController::class, 'index']);
		Route::get('/projects/{project}/gaps/content/{cluster}', [GapContentController::class, 'show'])
			->where('cluster', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/gaps/pages', [GapContentController::class, 'pages']);
		Route::get('/projects/{project}/gaps/pages/{competitor}/{url}', [GapContentController::class, 'page'])
			->where(['competitor' => '[0-9A-Za-z]{26}', 'url' => '[0-9a-f]{32}']);
		Route::get('/projects/{project}/positions', [PositionsController::class, 'index']);
		Route::get('/projects/{project}/positions/keywords/{keyword}', [PositionsController::class, 'show'])
			->where('keyword', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/positions/runs/{run}', [PositionsController::class, 'run'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/positions/runs/{run}/status', [PositionsController::class, 'runStatus'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/competitors', [CompetitorsController::class, 'index']);
		Route::get('/projects/{project}/competitors/organic', [CompetitorsController::class, 'organic']);
		Route::get('/projects/{project}/competitors/{competitor}', [CompetitorsController::class, 'show'])
			->where('competitor', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/discovery', [DiscoveryController::class, 'index']);
		Route::get('/projects/{project}/discovery/runs/{run}', [DiscoveryController::class, 'run'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/discovery/runs/{run}/status', [DiscoveryController::class, 'runStatus'])
			->where('run', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/discovery/keywords/{candidate}', [DiscoveryController::class, 'show'])
			->where('candidate', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/market-data', [MarketDataController::class, 'show']);
		Route::get('/projects/{project}/opportunities', [OpportunitiesController::class, 'index']);
		Route::get('/projects/{project}/opportunities/{opportunity}', [OpportunitiesController::class, 'show'])
			->where('opportunity', '[0-9A-Za-z]{26}');
		Route::get('/projects/{project}/search-console', [SearchConsoleController::class, 'show']);
		Route::get('/projects/{project}/search-console/status', [SearchConsoleController::class, 'status']);
		Route::get('/projects/{project}/keywords', [KeywordsController::class, 'index']);
		Route::get('/projects/{project}/{section}', [ProjectSectionController::class, 'show'])
			->whereIn('section', array_keys(ProjectSectionController::SECTIONS));
	});

	// Redirect URI OAuth (zarejestrowany w Google Cloud; = OsfSeo\Google\GoogleConfig::CALLBACK_PATH).
	// `state` wiąże callback z zalogowanym użytkownikiem i projektem — weryfikuje go plugin.
	Route::get('/oauth/google/callback', [SearchConsoleController::class, 'callback']);

	// Nieznane adresy w przestrzeni projektów — 404 panelu zamiast strony motywu WordPressa.
	Route::any('/projects/{path}', fn () => PanelResponse::notFound())->where('path', '.+');
});
