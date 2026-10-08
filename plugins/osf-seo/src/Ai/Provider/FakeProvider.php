<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

use Closure;
use OsfSeo\Ai\Analysis\AnalysisType;
use OsfSeo\Ai\Contract\AnalysisContract;
use OsfSeo\Ai\Contract\RecommendationContract;

/**
 * Dostawca testowy (zerowy koszt, bez sieci): deterministyczna odpowiedź zgodna z kontraktem, zbudowana wyłącznie ze znanych odwołań
 * do dowodów i braków danych przekazanych w `hints` żądania. Sprawdza cały przepływ (kontekst → instrukcje → walidacja → historia)
 * bez klucza API i bez internetu. Wynik NIE jest analizą — to atrapa struktury (oznaczona w treści).
 *
 * W testach można podać `responder` (dowolna odpowiedź albo wyjątek — np. uszkodzony JSON, timeout).
 */
final class FakeProvider implements AiProvider
{
	public const ID = 'fake';

	public const MODEL = 'fake-analysis-1';

	/** @var list<AiRequest> */
	public array $requests = [];

	/**
	 * @param (Closure(AiRequest): AiResponse)|null $responder
	 */
	public function __construct(private readonly ?Closure $responder = null)
	{
	}

	public function id(): string
	{
		return self::ID;
	}

	public function isPaid(): bool
	{
		return false;
	}

	public function model(): ?string
	{
		return self::MODEL;
	}

	public function problems(): array
	{
		return [];
	}

	public function generate(AiRequest $request): AiResponse
	{
		$this->requests[] = $request;

		if ($this->responder !== null) {
			return ($this->responder)($request);
		}

		$sample = in_array($request->hints['analysis_type'] ?? null, AnalysisType::RECOMMENDATIONS, true) ? self::recommendations($request->hints) : self::sample($request->hints);
		$text = (string) json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return new AiResponse(
			$text,
			new AiUsage((int) ceil((strlen($request->instructions) + strlen($request->input)) / 4), (int) ceil(strlen($text) / 4)),
			'fake_' . substr(hash('sha256', $request->input), 0, 24),
			self::MODEL,
		);
	}

	/**
	 * Przykładowa odpowiedź zgodna z kontraktem: odwołania wyłącznie do istniejących dowodów, braki danych jako brakujące informacje.
	 *
	 * @param array<string, mixed> $hints `refs` (lista znanych odwołań), `data_gaps` (kody braków), `action` (działanie Strategii)
	 * @return array<string, mixed>
	 */
	public static function sample(array $hints): array
	{
		$refs = array_values(array_filter((array) ($hints['refs'] ?? []), 'is_string'));
		$pick = static fn (string $prefix): array => array_values(array_filter($refs, static fn (string $ref): bool => $ref === $prefix || str_starts_with($ref, $prefix . ':')));
		$decision = array_slice([...$pick('decision'), ...$pick('topic')], 0, 2);
		$keywords = array_slice($pick('kw'), 0, 2);
		$gsc = $pick('gsc');
		$serp = $pick('serp');
		$target = $pick('target');
		$gaps = array_values(array_filter((array) ($hints['data_gaps'] ?? []), 'is_string'));
		$action = is_string($hints['action'] ?? null) ? $hints['action'] : 'investigate';
		$findings = [[
			'id' => 'F1',
			'kind' => 'opportunity',
			'title' => '[TEST] Decyzja Strategii: ' . $action,
			'explanation' => '[TEST] Odpowiedź dostawcy testowego — sprawdza strukturę i odwołania, to nie jest analiza.',
			'evidence_refs' => $decision !== [] ? $decision : array_slice($refs, 0, 1),
			'basis' => $decision !== [] || $refs !== [] ? 'evidence' : 'hypothesis',
			'confidence' => 'low',
		]];

		if ($gsc !== [] || $serp !== []) {
			$findings[] = [
				'id' => 'F2',
				'kind' => 'problem',
				'title' => '[TEST] Widoczność w danych projektu',
				'explanation' => '[TEST] Średnia pozycja (GSC) i Pozycja SERP to osobne metryki — atrapa tylko wskazuje dowody.',
				'evidence_refs' => array_values(array_unique([...array_slice($gsc, 0, 1), ...array_slice($serp, 0, 1), ...$keywords])),
				'basis' => 'evidence',
				'confidence' => 'low',
			];
		}

		return [
			'contract_version' => AnalysisContract::VERSION,
			'summary' => '[TEST] Atrapa analizy tematu (dostawca testowy, koszt 0) — struktura zgodna z kontraktem, bez wniosków merytorycznych.',
			'findings' => $findings,
			'recommendations' => [[
				'id' => 'R1',
				'action' => '[TEST] Sprawdź ręcznie dowody wskazane w odwołaniach.',
				'rationale' => '[TEST] Dostawca testowy nie analizuje danych.',
				'finding_ids' => ['F1'],
				'expected_impact' => 'unknown',
				'impact_rationale' => '[TEST] Bez oceny wpływu.',
				'evidence_refs' => $target !== [] ? $target : $findings[0]['evidence_refs'],
				'basis' => $target !== [] || $findings[0]['evidence_refs'] !== [] ? 'evidence' : 'hypothesis',
				'priority' => 3,
				'requires_manual_check' => true,
			]],
			'missing_information' => array_map(static fn (string $code): array => [
				'item' => '[TEST] Brak danych: ' . $code,
				'why_it_matters' => '[TEST] Ogranicza pewność wniosków.',
			], array_slice($gaps, 0, 3)),
			'caveats' => ['[TEST] Treść strony nie była pobierana — brak znanej strony nie oznacza, że strona nie istnieje.'],
			'manual_checks' => [[
				'check' => '[TEST] Zweryfikuj stronę docelową w witrynie.',
				'reason' => '[TEST] Indeks stron projektu jest niepełny.',
				'evidence_refs' => $target,
			]],
		];
	}

	/**
	 * Przykładowa odpowiedź analizy rekomendacji (kontrakt v2, faza C) — struktura typu analizy zbudowana wyłącznie ze znanych odwołań
	 * i ograniczeń z `hints`; każdy tekst oznaczony `[TEST]` (to nie jest analiza SEO).
	 *
	 * @param array<string, mixed> $hints `analysis_type`, `refs`, `data_gaps`, `limitations`, `constraints`, `action`
	 * @return array<string, mixed>
	 */
	public static function recommendations(array $hints): array
	{
		$type = (string) $hints['analysis_type'];
		$refs = array_values(array_filter((array) ($hints['refs'] ?? []), 'is_string'));
		$pick = static fn (string $prefix): array => array_values(array_filter($refs, static fn (string $ref): bool => $ref === $prefix || str_starts_with($ref, $prefix . ':')));
		$page = array_slice($pick('page'), 0, 1);
		$competitors = array_slice($pick('cpage'), 0, 3);
		$keywords = array_slice($pick('kw'), 0, 2);
		$decision = $pick('decision');
		$target = $pick('target');
		$site = array_slice($pick('site'), 0, 2);
		$action = is_string($hints['action'] ?? null) ? $hints['action'] : 'investigate';
		$constraints = array_values(array_filter((array) ($hints['constraints'] ?? []), 'is_string'));
		$limits = array_values(array_unique(array_filter([...(array) ($hints['limitations'] ?? []), ...(array) ($hints['data_gaps'] ?? [])], 'is_string')));
		$supporting = $page !== [] ? $page : ($keywords !== [] ? $keywords : $decision);
		$basis = static fn (array $refs): string => $refs === [] ? 'hypothesis' : 'inference';
		$recommendation = static fn (string $id, string $kind, string $title, array $refs, int $priority): array => [
			'id' => $id,
			'type' => $kind,
			'title' => $title,
			'description' => '[TEST] Opis rekomendacji dostawcy testowego — sprawdza strukturę, odwołania i walidację wyniku.',
			'rationale' => '[TEST] Dostawca testowy nie analizuje danych; uzasadnienie wskazuje tylko dowody z kontekstu.',
			'target' => '[TEST] Element wskazany w dowodach',
			'finding_ids' => ['F1'],
			'priority' => $priority,
			'urgency' => 'next',
			'expected_impact' => 'unknown',
			'impact_rationale' => '[TEST] Bez oceny wpływu.',
			'evidence_refs' => $refs,
			'basis' => $refs === [] ? 'hypothesis' : 'inference',
			'confidence' => 'low',
			'requires_manual_check' => true,
			'verification' => '[TEST] Zweryfikuj ręcznie w witrynie i w danych projektu.',
		];
		$findings = [[
			'id' => 'F1',
			'kind' => 'observation',
			'title' => '[TEST] Działanie Strategii: ' . $action,
			'explanation' => '[TEST] Odpowiedź dostawcy testowego — to nie jest analiza SEO. Decyzja Strategii pozostaje bez zmian.',
			'evidence_refs' => $decision,
			'basis' => $basis($decision),
			'confidence' => 'low',
		]];

		if ($page !== []) {
			$findings[] = [
				'id' => 'F2',
				'kind' => 'observation',
				'title' => '[TEST] Zapisany snapshot strony projektu',
				'explanation' => '[TEST] Snapshot pochodzi z HTML bez JavaScriptu — brak elementu w snapshocie nie dowodzi jego braku na stronie.',
				'evidence_refs' => $page,
				'basis' => 'fact',
				'confidence' => 'low',
			];
		}

		$result = [
			'contract_version' => RecommendationContract::VERSION,
			'analysis_type' => $type,
			'summary' => '[TEST] Atrapa analizy „' . AnalysisType::label($type) . '” (dostawca testowy, koszt 0) — struktura zgodna z kontraktem, bez wniosków merytorycznych.',
			'search_intent' => [
				'primary' => 'unknown',
				'explanation' => '[TEST] Intencja nieoceniana przez dostawcę testowego.',
				'evidence_refs' => $keywords,
				'basis' => $basis($keywords),
				'confidence' => 'low',
			],
			'findings' => $findings,
			'recommendations' => [],
			'content_outline' => ['h1' => '', 'sections' => []],
			'title_suggestions' => [],
			'meta_description_suggestions' => [],
			'internal_links' => [],
			'content_topics' => [],
			'user_questions' => [],
			'cta_suggestions' => [],
			'client_data_needed' => [],
			'cannibalization_risks' => [],
			'missing_information' => array_map(static fn (string $code): array => [
				'item' => '[TEST] Ograniczenie danych: ' . $code,
				'why_it_matters' => '[TEST] Ogranicza pewność wniosków.',
			], array_slice($limits, 0, 4)),
			'warnings' => ['[TEST] Odpowiedź dostawcy testowego — nie przedstawiaj jej jako analizy SEO.'],
			'manual_checks' => [[
				'check' => '[TEST] Zweryfikuj stronę docelową i dowody w witrynie.',
				'reason' => '[TEST] Indeks stron projektu jest niepełny, a snapshoty nie renderują JavaScriptu.',
				'evidence_refs' => $target,
			]],
		];

		if ($type === AnalysisType::PAGE_OPTIMIZATION) {
			$result['recommendations'][] = $recommendation('R1', 'expand_section', '[TEST] Sprawdź zakres sekcji strony wskazanej w dowodach', $supporting, 2);
			$result['recommendations'][] = $recommendation('R2', 'internal_linking', '[TEST] Przejrzyj linkowanie wewnętrzne do strony', $site !== [] ? $site : $target, 3);
		} elseif ($type === AnalysisType::NEW_PAGE_BRIEF) {
			array_unshift($result['warnings'], '[TEST] Kandydat na nową stronę: brak znanej strony w dostępnych źródłach nie oznacza, że takiej strony nie ma w witrynie.');
			$result['recommendations'][] = $recommendation('R1', 'page_role', '[TEST] Ustal rolę kandydata na nową stronę w serwisie', $keywords !== [] ? $keywords : $decision, 1);
			$result['content_outline'] = [
				'h1' => '[TEST] Nagłówek H1 kandydata na nową stronę',
				'sections' => [
					['level' => 2, 'heading' => '[TEST] Sekcja główna tematu', 'scope' => '[TEST] Zakres do ustalenia na podstawie fraz tematu.', 'evidence_refs' => $keywords, 'basis' => $basis($keywords)],
					['level' => 2, 'heading' => '[TEST] Sekcja uzupełniająca', 'scope' => '[TEST] Zakres do potwierdzenia z klientem.', 'evidence_refs' => [], 'basis' => 'hypothesis'],
				],
			];
			$result['title_suggestions'] = [['text' => '[TEST] Propozycja tytułu kandydata', 'rationale' => '[TEST] Atrapa propozycji.', 'evidence_refs' => $keywords, 'basis' => $basis($keywords)]];
			$result['meta_description_suggestions'] = [['text' => '[TEST] Propozycja opisu meta dla kandydata na nową stronę — atrapa dostawcy testowego bez analizy.', 'rationale' => '[TEST] Atrapa propozycji.', 'evidence_refs' => $keywords, 'basis' => $basis($keywords)]];
			$result['user_questions'] = ['[TEST] Pytanie użytkownika do potwierdzenia w danych?'];
			$result['cta_suggestions'] = ['[TEST] Wezwanie do działania do ustalenia z klientem'];
			$result['client_data_needed'] = ['[TEST] Szczegóły oferty i przykłady realizacji od klienta'];

			if ($site !== []) {
				$result['cannibalization_risks'] = [['description' => '[TEST] Sprawdź, czy inny temat projektu nie obejmuje tych samych fraz.', 'evidence_refs' => $site, 'basis' => 'hypothesis']];
			}
		} elseif ($type === AnalysisType::CONTENT_GAP) {
			array_unshift($result['warnings'], '[TEST] Snapshoty konkurencji i pomiar SERP to różne chwile; obecność tematu u konkurenta nie wyjaśnia jego pozycji.');
			$result['recommendations'][] = $recommendation('R1', 'expand_section', '[TEST] Rozważ rozbudowę sekcji po porównaniu z konkurencją', [...$page, ...$competitors], 2);
			$result['content_topics'] = [
				['label' => '[TEST] Temat obecny na stronie projektu', 'status' => 'present_on_project_page', 'evidence_refs' => $page, 'basis' => $page === [] ? 'hypothesis' : 'fact', 'confidence' => 'low', 'note' => '[TEST] Atrapa tematu.'],
				['label' => '[TEST] Temat do sprawdzenia u konkurencji', 'status' => 'potentially_missing', 'evidence_refs' => $competitors, 'basis' => 'hypothesis', 'confidence' => 'low', 'note' => '[TEST] Brak w snapshocie projektu nie dowodzi braku na stronie.'],
			];
		}

		return $result;
	}
}
