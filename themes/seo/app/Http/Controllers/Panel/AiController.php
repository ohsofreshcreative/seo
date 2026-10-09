<?php

namespace App\Http\Controllers\Panel;

use App\Http\Middleware\Panel\ResolveProject;
use App\Panel\Flash;
use App\Panel\PanelResponse;
use App\Panel\PanelUrl;
use Illuminate\Http\Request;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\AiRunNotFound;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Ai\Workspace\AiWorkspaceService;
use OsfSeo\Ai\Workspace\ReportLabels;
use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Strategy\StrategyNotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Analizy AI w panelu (STEP 17, faza D): historia, raport, przygotowanie analizy tematu (plan i koszt — zero żądań), zakolejkowanie po
 * jawnym potwierdzeniu, status, decyzja i anulowanie. Kontroler nigdy nie wywołuje modelu (wykonuje krok w tle) i nie przyjmuje kosztu
 * z formularza — wyłącznie odcisk planu, który usługa przelicza i porównuje. Odczyt: każdy z dostępem do projektu (klient — tylko gotowe
 * analizy, bez kosztów; egzekwuje usługa); przygotowanie i zlecenie: `osf_seo_manage_ai` (trasa, kontroler i usługa).
 */
final class AiController
{
	public function index(Request $request): Response
	{
		$context = $this->context($request);
		$filters = [
			'type' => is_string($request->query('type')) ? $request->query('type') : null,
			'status' => is_string($request->query('status')) ? $request->query('status') : null,
			'topic' => is_string($request->query('topic')) ? $request->query('topic') : null,
		];
		$history = $this->service()->history($context, $filters, max(1, (int) $request->query('page', 1)));

		return response()->view('panel.ai.index', [
			'project' => $context->project(),
			'history' => $history,
			'filters' => $filters,
		]);
	}

	public function show(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$data = $this->service()->report($context, $run);
		} catch (AiRunNotFound) {
			return PanelResponse::notFound();
		}

		return response()->view('panel.ai.report', $data + [
			'project' => $context->project(),
			'manage' => $context->can('osf_seo_manage_ai'),
		]);
	}

	public function status(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			return response()->json($this->service()->runStatus($context, $run));
		} catch (AccessDenied) {
			return response()->json(['error' => 'forbidden'], 403);
		} catch (AiRunNotFound) {
			return response()->json(['error' => 'not_found'], 404);
		}
	}

	/** Ekran przygotowania: typ, gotowość, dowody, braki, koszt maksymalny, budżet — bez żadnego wywołania modelu. */
	public function prepare(Request $request, string $project, string $topic): Response
	{
		$context = $this->context($request);
		$type = AnalysisType::fromInput($request->query('type'));

		try {
			$data = $this->service()->prepare($context, $topic, $type, $request->boolean('explicit'), self::provider($request->query('provider')));
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		} catch (AiRefused $refused) {
			Flash::error(ReportLabels::error($refused->code()) ?? 'Nie można przygotować analizy.');

			return redirect()->to(StrategyTopicsController::topicUrl($context->publicId(), $topic));
		}

		return response()->view('panel.ai.prepare', $data + [
			'project' => $context->project(),
			'canFetch' => $context->can('osf_seo_manage_page_intelligence'),
		]);
	}

	/**
	 * Zakolejkowanie analizy po potwierdzeniu planu. Usługa przelicza plan teraz i porównuje odcisk (inny koszt, model, ceny albo dane →
	 * odmowa „plan się zmienił”); podwójne kliknięcie trafia na blokadę zlecenia albo „analiza w toku” — jedno zlecenie.
	 */
	public function queue(Request $request, string $project, string $topic): Response
	{
		$context = $this->context($request);
		$type = AnalysisType::fromInput($request->input('type')) ?? '';
		$explicit = $request->boolean('explicit');
		$provider = self::provider($request->input('provider'));
		$back = self::prepareUrl($context->publicId(), $topic, $type, $explicit, $provider);

		try {
			$run = $this->service()->queue(
				$context,
				$topic,
				$type,
				(string) $request->input('plan', ''),
				$provider,
				$explicit,
				$request->boolean('explicit_confirmed'),
				$request->boolean('confirmed'),
				$request->boolean('repeat'),
			);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (StrategyNotFound) {
			return PanelResponse::notFound();
		} catch (AiRefused $refused) {
			Flash::error(ReportLabels::error($refused->code()) ?? 'Analiza nie została zlecona.');

			return redirect()->to($back);
		}

		Flash::success($run->paid
			? 'Zlecono analizę — koszt maksymalny został zarezerwowany. Wykona ją przetwarzanie w tle; wynik pojawi się na tej stronie.'
			: 'Zlecono analizę (dostawca testowy, bez kosztów). Wykona ją przetwarzanie w tle; wynik pojawi się na tej stronie.');

		return redirect()->to(self::runUrl($context->publicId(), $run->publicId));
	}

	public function cancel(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);

		try {
			$this->ai()->cancel($context, $run);
			Flash::success('Anulowano zlecenie — rezerwacja kosztu została zwolniona.');
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (AiRunNotFound) {
			return PanelResponse::notFound();
		} catch (AiRefused $refused) {
			Flash::error(ReportLabels::error($refused->code()) ?? 'Nie można anulować.');
		}

		return redirect()->to(self::runUrl($context->publicId(), $run));
	}

	/** Decyzja o wyniku (przyjęta / odrzucona / cofnięcie) — osobno od wyniku; nie zmienia Strategii ani statusu pracy tematu. */
	public function decide(Request $request, string $project, string $run): Response
	{
		$context = $this->context($request);
		$decision = (string) $request->input('decision', '');

		try {
			$this->ai()->decide($context, $run, in_array($decision, AiRun::DECISIONS, true) ? $decision : null);
			Flash::success(match ($decision) {
				AiRun::DECISION_ACCEPTED => 'Oznaczono analizę jako przyjętą. Strategia i status pracy tematu nie zmieniają się.',
				AiRun::DECISION_REJECTED => 'Oznaczono analizę jako odrzuconą — klient jej nie zobaczy.',
				default => 'Cofnięto decyzję.',
			});
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		} catch (AiRunNotFound) {
			return PanelResponse::notFound();
		} catch (AiRefused $refused) {
			Flash::error(ReportLabels::error($refused->code()) ?? 'Nie zapisano decyzji.');
		}

		return redirect()->to(self::runUrl($context->publicId(), $run));
	}

	public static function runUrl(string $projectId, string $runId): string
	{
		return PanelUrl::project($projectId, 'ai/runs/' . rawurlencode($runId));
	}

	public static function prepareUrl(string $projectId, string $topicId, ?string $type = null, bool $explicit = false, string $provider = FakeProvider::ID): string
	{
		$query = array_filter(['type' => $type, 'explicit' => $explicit ? '1' : null, 'provider' => $provider === FakeProvider::ID ? null : $provider]);

		return StrategyTopicsController::topicUrl($projectId, $topicId) . '/ai' . ($query === [] ? '' : '?' . http_build_query($query));
	}

	private static function provider(mixed $value): string
	{
		return is_string($value) && preg_match('/^[a-z0-9_-]{1,32}$/', $value) === 1 ? $value : FakeProvider::ID;
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}

	private function service(): AiWorkspaceService
	{
		return osf_seo()->get(AiWorkspaceService::class);
	}

	private function ai(): \OsfSeo\Ai\AiAnalysisService
	{
		return osf_seo()->get(\OsfSeo\Ai\AiAnalysisService::class);
	}
}
