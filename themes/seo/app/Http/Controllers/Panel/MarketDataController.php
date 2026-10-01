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
use OsfSeo\Market\CostBudget;
use OsfSeo\Market\MarketSyncResult;
use OsfSeo\Market\MarketSyncService;
use OsfSeo\Market\MarketTaskRepository;
use OsfSeo\Opportunities\Text;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dane rynkowe projektu (DataForSEO): stan, podgląd planu i ręczna synchronizacja. Kontroler nie wywołuje API —
 * płatne żądania wysyła wyłącznie MarketSyncService (po kontroli uprawnień, limitów i potwierdzonego podglądu).
 */
final class MarketDataController
{
	public function show(Request $request): Response
	{
		$context = $this->context($request);
		$service = osf_seo()->get(MarketSyncService::class);
		$status = $service->status($context);
		$canManage = $context->can('osf_seo_manage_market_data');
		// Podgląd liczony bez API (jak dry-run) — tylko dla zarządzających, gdy dostawca i rynek są gotowe.
		$plan = $canManage && $status['configured'] && $status['market'] !== null ? $service->plan($context) : null;

		return response()->view('panel.projects.market-data', [
			'project' => $context->project(),
			'status' => $status,
			'plan' => $plan,
			'canManage' => $canManage,
			'recent' => $canManage ? osf_seo()->get(MarketTaskRepository::class)->recent(10, $context->projectId()) : [],
			'markets' => $service->provider()->markets(),
		]);
	}

	public function sync(Request $request): Response
	{
		$context = $this->context($request);
		$back = redirect()->to(PanelUrl::project($context->publicId(), 'market-data'));
		$expected = filter_var($request->input('expected_keywords'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);

		if ($expected === false) {
			Flash::error('Brak potwierdzonego podglądu synchronizacji. Odśwież stronę i spróbuj ponownie.');

			return $back;
		}

		try {
			$result = osf_seo()->get(MarketSyncService::class)->sync($context, MarketSyncService::TRIGGER_MANUAL, null, false, $expected);
		} catch (AccessDenied) {
			return PanelResponse::forbidden();
		}

		[$type, $message] = self::message($result);
		Flash::{$type}($message);

		return $back;
	}

	/**
	 * @return array{0: string, 1: string} typ komunikatu flash, treść
	 */
	private static function message(MarketSyncResult $result): array
	{
		$sent = sprintf(
			'Wysłano do DataForSEO: wolumen — %s (wyniki w ciągu ok. 1–3 h), trudność SEO — %s. Koszt: ok. %s.',
			Format::number($result->volumeKeywords) . ' ' . Text::plural($result->volumeKeywords, 'fraza', 'frazy', 'fraz'),
			Format::number($result->difficultyKeywords) . ' ' . Text::plural($result->difficultyKeywords, 'fraza', 'frazy', 'fraz'),
			Format::usd($result->cost, 4),
		);

		return match ($result->status) {
			MarketSyncResult::SUCCESS => ['success', $sent],
			MarketSyncResult::PARTIAL => $result->error !== null
				? ['error', $sent . ' Synchronizacja przerwana: ' . $result->error->label() . '.']
				: ['info', $sent . ' Pozostałe frazy w kolejnym przebiegu (' . CostBudget::label((string) $result->blockedBy) . ').'],
			MarketSyncResult::NOTHING_TO_DO => ['info', 'Wybrane frazy mają aktualne dane rynkowe — nic nie wysłano.'],
			MarketSyncResult::SKIPPED => ['error', $result->reason === 'unsupported_market'
				? 'Rynek projektu nie jest obsługiwany przez DataForSEO — zmień kraj i język w ustawieniach projektu.'
				: 'Nie wysłano zadań: ' . CostBudget::label((string) $result->blockedBy) . '.'],
			MarketSyncResult::FAILED => ['error', 'Synchronizacja nie powiodła się: ' . ($result->error?->label() ?? 'błąd') . '. Szczegóły w historii zadań.'],
			MarketSyncResult::NOT_CONFIGURED => ['error', 'DataForSEO nie jest skonfigurowane (stałe w wp-config.php).'],
			MarketSyncResult::ALREADY_RUNNING => ['info', 'Synchronizacja danych rynkowych już trwa — spróbuj za chwilę.'],
			MarketSyncResult::RATE_LIMITED => ['info', 'Ręczną synchronizację projektu można uruchomić raz na 5 minut.'],
			MarketSyncResult::PLAN_CHANGED => ['error', 'Liczba fraz do wysłania zmieniła się od podglądu — sprawdź podgląd i potwierdź ponownie.'],
			default => ['info', 'Synchronizacja zakończona.'],
		};
	}

	private function context(Request $request): ProjectContext
	{
		return $request->attributes->get(ResolveProject::ATTRIBUTE);
	}
}
