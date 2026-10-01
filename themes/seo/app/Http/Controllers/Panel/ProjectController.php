<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Analytics\OverviewReport;
use OsfSeo\Analytics\Period;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Projects\ProjectService;
use OsfSeo\Projects\ProjectStatus;
use OsfSeo\Support\ValidationException;
use OsfSeo\Sync\SyncService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lista, tworzenie, edycja i archiwizacja projektów. Autoryzacja: ResolveProject (ProjectGuard)
 * + capabilities; ProjectService dodatkowo sprawdza uprawnienia przy każdej zmianie.
 */
final class ProjectController
{
	private const FIELDS = ['name', 'domain', 'country', 'language'];

	public function index(Request $request): Response
	{
		$archived = $request->query('status') === 'archived';

		return response()->view('panel.projects.index', [
			'projects' => $this->service()->listFor(get_current_user_id(), $archived ? ProjectStatus::Archived : null),
			'archived' => $archived,
			'canManageProjects' => current_user_can('osf_seo_manage_projects'),
			'canViewArchive' => current_user_can('osf_seo_view_all_projects'),
		]);
	}

	public function create(): Response
	{
		if (! current_user_can('osf_seo_manage_projects')) {
			return PanelResponse::forbidden();
		}

		return $this->form(null, ['country' => 'pl', 'language' => 'pl']);
	}

	public function store(Request $request): Response
	{
		$input = $request->only(self::FIELDS);

		try {
			$context = $this->service()->create($input, get_current_user_id());
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			return $this->form(null, $input, $exception->errors(), 422);
		}

		Flash::success(sprintf('Utworzono projekt „%s”.', $context->project()->name));

		return redirect()->to(PanelUrl::project($context->publicId()));
	}

	/**
	 * Przegląd projektu: dashboard z danych GSC (plugin: OverviewReport), a bez danych — stan połączenia/synchronizacji.
	 */
	public function show(Request $request): Response
	{
		$context = $this->context($request);
		$project = $context->project();
		$days = Period::days($request->query('days'));

		return response()->view('panel.projects.show', [
			'project' => $project,
			'canManageProjects' => $context->can('osf_seo_manage_projects'),
			'overview' => $project->gscProperty !== null ? osf_seo()->get(OverviewReport::class)->overview($context, $days) : null,
			'sync' => $project->gscProperty !== null ? osf_seo()->get(SyncService::class)->status($context) : null,
			'days' => $days,
		]);
	}

	public function edit(Request $request): Response
	{
		$project = $this->context($request)->project();

		return $this->form($project, [
			'name' => $project->name,
			'domain' => $project->domain,
			'country' => $project->country,
			'language' => $project->language,
		]);
	}

	public function update(Request $request): Response
	{
		$context = $this->context($request);
		$input = $request->only(self::FIELDS);

		try {
			$context = $this->service()->update($context, $input);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			return $this->form($context->project(), $input, $exception->errors(), 422);
		}

		Flash::success('Zapisano zmiany projektu.');

		return redirect()->to(PanelUrl::project($context->publicId()));
	}

	public function archive(Request $request): Response
	{
		return $this->changeStatus($request, ProjectStatus::Archived, 'Projekt został zarchiwizowany.', PanelUrl::to('projects'));
	}

	public function restore(Request $request): Response
	{
		$context = $this->context($request);

		return $this->changeStatus($request, ProjectStatus::Active, 'Projekt został przywrócony.', PanelUrl::project($context->publicId()));
	}

	public function pause(Request $request): Response
	{
		$context = $this->context($request);

		return $this->changeStatus($request, ProjectStatus::Paused, 'Projekt został wstrzymany.', PanelUrl::project($context->publicId()));
	}

	private function changeStatus(Request $request, ProjectStatus $status, string $message, string $redirect): Response
	{
		try {
			$this->service()->changeStatus($this->context($request), $status);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		Flash::success($message);

		return redirect()->to($redirect);
	}

	/**
	 * @param array<string, mixed> $values
	 * @param array<string, string> $errors
	 */
	private function form(?\OsfSeo\Projects\Project $project, array $values, array $errors = [], int $status = 200): Response
	{
		// Obsługiwane rynki danych rynkowych (DataForSEO) — podpowiedź przy kraju i języku projektu.
		$markets = osf_seo()->get(\OsfSeo\Market\KeywordMetricsProvider::class)->markets();

		return response()->view('panel.projects.form', compact('project', 'values', 'errors', 'markets'), $status);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): ProjectService
	{
		return osf_seo()->get(ProjectService::class);
	}
}
