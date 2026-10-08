<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Provider;

use Closure;
use OsfSeo\Ai\Contract\AnalysisContract;

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

		$text = (string) json_encode(self::sample($request->hints), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
}
