<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\Format;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Ai\Workspace\AiWorkspaceService;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Opportunities\Text;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\StrategyService;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backlog Strategii (tematy z filtrami i stronicowaniem) i szczegóły tematu („Dlaczego ten temat jest w Strategii?”, strona docelowa,
 * SERP Intelligence, frazy, historia) oraz praca nad tematem: status, notatka, ręczna strona docelowa, przypięcia fraz. Bez SQL i bez
 * wywołań API — dane z usług pluginu; autoryzacja w middleware i ponownie w usłudze.
 */
final class StrategyTopicsController
{
	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$service = $this->service();
		$filters = TopicFilters::fromInput($request->query->all());
		$page = $service->topics($context, $filters);

		return response()->view('panel.strategy.topics', [
			'project' => $context->project(),
			'filters' => $filters,
			'page' => $page,
			'state' => $service->panelState($context),
			'canManage' => $context->can('osf_seo_manage_strategy'),
		]);
	}

	public function show(Request $request, string $project, string $topic): Response
	{
		return $this->detail($request, $topic);
	}

	public function status(Request $request, string $project, string $topic): Response
	{
		$context = $this->context($request);
		$status = TopicStatus::fromInput($request->input('status'));

		if ($status === null) {
			return $this->detail($request, $topic, ['status' => 'Wybierz status.'], 422);
		}

		$note = $request->has('note') ? mb_substr(trim((string) $request->input('note')), 0, 5000) : null;

		try {
			$row = $this->service()->setStatus($context, $topic, $status, $note);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		}

		Flash::success('Status tematu: ' . mb_strtolower($status->label()) . '. Przeliczenie nie zmienia statusu pracy.');

		return redirect()->to(self::topicUrl($context->publicId(), $row->publicId));
	}

	public function note(Request $request, string $project, string $topic): Response
	{
		$context = $this->context($request);

		try {
			$row = $this->service()->setNote($context, $topic, mb_substr((string) $request->input('note', ''), 0, 5000));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		}

		Flash::success('Zapisano notatkę (widoczna tylko dla zespołu agencji).');

		return redirect()->to(self::topicUrl($context->publicId(), $row->publicId));
	}

	/** Ręczna strona docelowa: adres w domenie projektu, ręczne potwierdzenie braku strony albo usunięcie wskazania. */
	public function target(Request $request, string $project, string $topic): Response
	{
		$context = $this->context($request);
		$mode = (string) $request->input('mode', 'url');
		$url = $mode === 'url' ? trim(mb_substr((string) $request->input('target_url', ''), 0, 2000)) : null;

		if ($mode === 'url' && $url === '') {
			return $this->detail($request, $topic, ['target_url' => 'Podaj adres strony w domenie projektu.'], 422);
		}

		try {
			$row = $this->service()->setTarget($context, $topic, $mode === 'url' ? $url : null, $mode === 'none');
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		} catch (ValidationException $exception) {
			return $this->detail($request, $topic, ['target_url' => $exception->errors()['url'] ?? 'Nieprawidłowy adres.'], 422);
		}

		Flash::success(match ($mode) {
			'none' => 'Zapisano ręczne potwierdzenie braku strony. Działanie i pewność zmienią się po przeliczeniu.',
			'clear' => 'Usunięto ręczne wskazanie strony. Strona docelowa wróci do rozpoznania z danych po przeliczeniu.',
			default => 'Zapisano ręczną stronę docelową. Działanie i pewność zmienią się po przeliczeniu.',
		});

		return redirect()->to(self::topicUrl($context->publicId(), $row->publicId));
	}

	/** Przypięcie fraz (kandydatów Strategii) do tematu z adresu albo do nowego tematu (`topic` = new). */
	public function pin(Request $request, string $project): Response
	{
		$context = $this->context($request);
		$values = self::values($request);
		$target = (string) $request->input('topic', '');
		$back = StrategyController::backUrl($context, $request->input('back'), PanelUrl::project($context->publicId(), 'strategy/topics'));

		if ($values === []) {
			Flash::error('Podaj co najmniej jedną frazę Strategii.');

			return redirect()->to($back);
		}

		try {
			$result = $this->service()->pin($context, $values, $target === 'new' || $target === '' ? null : $target);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		} catch (ValidationException $exception) {
			Flash::error($exception->errors()['keywords'] ?? 'Nie przypięto fraz.');

			return redirect()->to($back);
		}

		$message = 'Przypięto ' . Format::number($result['pinned']) . ' ' . Text::plural($result['pinned'], 'frazę', 'frazy', 'fraz') . '. Tematy przegrupuje najbliższe przeliczenie.';

		if ($result['missing'] !== []) {
			$message .= ' Spoza Strategii: ' . implode(', ', array_map(static fn (string $value): string => '„' . $value . '”', array_slice($result['missing'], 0, 10))) . '.';
		}

		Flash::success($message);

		return redirect()->to(self::topicUrl($context->publicId(), $result['topic']));
	}

	public function unpin(Request $request, string $project): Response
	{
		$context = $this->context($request);
		$values = self::values($request);
		$back = StrategyController::backUrl($context, $request->input('back'), PanelUrl::project($context->publicId(), 'strategy/topics'));

		try {
			$unpinned = $values === [] ? 0 : $this->service()->unpin($context, $values);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (ValidationException $exception) {
			Flash::error($exception->errors()['keywords'] ?? 'Nie odpięto fraz.');

			return redirect()->to($back);
		}

		$unpinned > 0
			? Flash::success('Odpięto ' . Format::number($unpinned) . ' ' . Text::plural($unpinned, 'frazę', 'frazy', 'fraz') . ' — wrócą do grupowania automatycznego po przeliczeniu.')
			: Flash::info('Żadna z fraz nie była przypięta.');

		return redirect()->to($back);
	}

	public static function topicUrl(string $projectId, string $topicId): string
	{
		return PanelUrl::project($projectId, 'strategy/topics/' . rawurlencode($topicId));
	}

	/**
	 * @return list<string> ULID-y kandydatów (`ids[]`) albo frazy z pola tekstowego (po jednej w wierszu, maks. 200)
	 */
	private static function values(Request $request): array
	{
		$ids = $request->input('ids');
		$values = is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
		$text = $request->input('keywords');

		if (is_string($text)) {
			$values = [...$values, ...(preg_split('/[\r\n]+/u', mb_substr($text, 0, 20000)) ?: [])];
		}

		return array_slice(array_values(array_unique(array_filter(array_map('trim', $values), static fn (string $value): bool => $value !== ''))), 0, 200);
	}

	/**
	 * @param array<string, string> $errors
	 */
	private function detail(Request $request, string $publicId, array $errors = [], int $status = 200): Response
	{
		$context = $this->context($request);
		$service = $this->service();

		try {
			$data = $service->topicView($context, $publicId);
			$state = $service->panelState($context);
			// Sekcja „Analiza AI”: wyłącznie odczyt zapisanych danych (bez pobierania stron i bez wywołań modelu), z tych samych odczytów Strategii.
			$ai = osf_seo()->get(AiWorkspaceService::class)->topicSectionFromView($context, $data, $state);
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.strategy.topic', $data + [
			'ai' => $ai,
			'project' => $context->project(),
			'state' => $state,
			'canManage' => $context->can('osf_seo_manage_strategy'),
			'canAnalyze' => $context->can('osf_seo_manage_strategy') && $context->can('osf_seo_manage_serp_tracking'),
			'errors' => $errors,
			'old' => $request->only(['target_url', 'note']),
		], $status);
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): StrategyService
	{
		return osf_seo()->get(StrategyService::class);
	}
}
