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
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\ProjectController;
use App\Http\Controllers\Panel\ProjectSectionController;
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

	Route::middleware(ResolveProject::class)->group(function () {
		Route::get('/projects/{project}', [ProjectController::class, 'show']);
		Route::get('/projects/{project}/{section}', [ProjectSectionController::class, 'show'])
			->whereIn('section', array_keys(ProjectSectionController::SECTIONS));
	});

	// Nieznane adresy w przestrzeni projektów — 404 panelu zamiast strony motywu WordPressa.
	Route::any('/projects/{path}', fn () => PanelResponse::notFound())->where('path', '.+');
});
