<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

/**
 * Walidacja odpowiedzi modelu po stronie PHP (kontrakt wersji 1): poprawny JSON, dokładny zestaw pól, typy, wyliczenia, limity długości
 * i liczby elementów, unikalne identyfikatory, odwołania wyłącznie do dowodów obecnych w kontekście tego uruchomienia, twierdzenie
 * „evidence” z co najmniej jednym odwołaniem, rekomendacje wskazujące istniejące ustalenia, bez prognoz liczbowych w ocenie wpływu
 * i bez obietnic („gwarantuje”). Odpowiedź z jakimkolwiek błędem jest odrzucana w całości (status `invalid`, wynik niezapisany).
 */
final class OutputValidator extends JsonContractValidator
{
	public const MAX_BYTES = 200000;

	/** Prognozy liczbowe w ocenie wpływu (procenty, mnożniki, „+N kliknięć”). */
	private const FORECAST = '/(\d+(?:[.,]\d+)?\s*%|\d+(?:[.,]\d+)?\s*(?:x|×)(?![a-z])|\b\d+(?:[.,]\d+)?[\s-]*(?:razy|krotn)|\+\s*\d)/iu';

	/** Obietnice wyniku (rekomendacje to hipotezy do sprawdzenia). */
	private const PROMISE = '/(gwarant|guarantee)/iu';

	/**
	 * @param list<string> $knownRefs odwołania obecne w kontekście uruchomienia
	 */
	public function validate(string $text, array $knownRefs): ValidationResult
	{
		$this->errors = [];
		$this->known = array_fill_keys($knownRefs, true);

		$data = $this->decode($text, self::MAX_BYTES);

		if ($data === null) {
			return new ValidationResult(null, $this->errors);
		}

		if (! $this->keys($data, ['contract_version', 'summary', 'findings', 'recommendations', 'missing_information', 'caveats', 'manual_checks'], '$')) {
			return new ValidationResult(null, $this->errors);
		}

		if ($data['contract_version'] !== AnalysisContract::VERSION) {
			$this->error('$.contract_version', 'contract_version');
		}

		$result = [
			'contract_version' => AnalysisContract::VERSION,
			'summary' => $this->string($data['summary'], '$.summary', 'summary', true),
			'findings' => [],
			'recommendations' => [],
			'missing_information' => [],
			'caveats' => [],
			'manual_checks' => [],
		];
		$findingIds = [];

		foreach ($this->items($data['findings'], '$.findings') as $index => $item) {
			$path = '$.findings[' . $index . ']';

			if (! $this->keys($item, ['id', 'kind', 'title', 'explanation', 'evidence_refs', 'basis', 'confidence'], $path)) {
				continue;
			}

			$id = $this->id($item['id'], $path . '.id', $findingIds);
			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], AnalysisContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$result['findings'][] = [
				'id' => $id,
				'kind' => $this->enum($item['kind'], AnalysisContract::KINDS, $path . '.kind'),
				'title' => $this->string($item['title'], $path . '.title', 'title', true),
				'explanation' => $this->string($item['explanation'], $path . '.explanation', 'explanation', true),
				'evidence_refs' => $refs,
				'basis' => $basis,
				'confidence' => $this->enum($item['confidence'], AnalysisContract::CONFIDENCE, $path . '.confidence'),
			];
		}

		$recommendationIds = [];

		foreach ($this->items($data['recommendations'], '$.recommendations') as $index => $item) {
			$path = '$.recommendations[' . $index . ']';

			if (! $this->keys($item, ['id', 'action', 'rationale', 'finding_ids', 'expected_impact', 'impact_rationale', 'evidence_refs', 'basis', 'priority', 'requires_manual_check'], $path)) {
				continue;
			}

			$refs = $this->refs($item['evidence_refs'], $path . '.evidence_refs');
			$basis = $this->enum($item['basis'], AnalysisContract::BASIS, $path . '.basis');
			$this->basis($basis, $refs, $path);
			$impactRationale = $this->string($item['impact_rationale'], $path . '.impact_rationale', 'impact_rationale', true);

			if ($impactRationale !== null && preg_match(self::FORECAST, $impactRationale) === 1) {
				$this->error($path . '.impact_rationale', 'numeric_forecast');
			}

			if (! is_bool($item['requires_manual_check'])) {
				$this->error($path . '.requires_manual_check', 'wrong_type');
			}

			if (! is_int($item['priority']) || ! in_array($item['priority'], AnalysisContract::PRIORITIES, true)) {
				$this->error($path . '.priority', 'invalid_enum');
			}

			$result['recommendations'][] = [
				'id' => $this->id($item['id'], $path . '.id', $recommendationIds),
				'action' => $this->string($item['action'], $path . '.action', 'action', true),
				'rationale' => $this->string($item['rationale'], $path . '.rationale', 'rationale', true),
				'finding_ids' => $this->findingIds($item['finding_ids'], $findingIds, $path . '.finding_ids'),
				'expected_impact' => $this->enum($item['expected_impact'], AnalysisContract::IMPACT, $path . '.expected_impact'),
				'impact_rationale' => $impactRationale,
				'evidence_refs' => $refs,
				'basis' => $basis,
				'priority' => is_int($item['priority']) ? $item['priority'] : null,
				'requires_manual_check' => $item['requires_manual_check'] === true,
			];
		}

		foreach ($this->items($data['missing_information'], '$.missing_information') as $index => $item) {
			$path = '$.missing_information[' . $index . ']';

			if ($this->keys($item, ['item', 'why_it_matters'], $path)) {
				$result['missing_information'][] = [
					'item' => $this->string($item['item'], $path . '.item', 'item', true),
					'why_it_matters' => $this->string($item['why_it_matters'], $path . '.why_it_matters', 'why_it_matters', true),
				];
			}
		}

		foreach ($this->items($data['caveats'], '$.caveats') as $index => $caveat) {
			$result['caveats'][] = $this->string($caveat, '$.caveats[' . $index . ']', 'caveat', true);
		}

		foreach ($this->items($data['manual_checks'], '$.manual_checks') as $index => $item) {
			$path = '$.manual_checks[' . $index . ']';

			if ($this->keys($item, ['check', 'reason', 'evidence_refs'], $path)) {
				$result['manual_checks'][] = [
					'check' => $this->string($item['check'], $path . '.check', 'check', true),
					'reason' => $this->string($item['reason'], $path . '.reason', 'reason', true),
					'evidence_refs' => $this->refs($item['evidence_refs'], $path . '.evidence_refs'),
				];
			}
		}

		foreach (['summary' => $result['summary'], ...$this->promiseFields($result)] as $path => $value) {
			if (is_string($value) && preg_match(self::PROMISE, $value) === 1) {
				$this->error($path === 'summary' ? '$.summary' : $path, 'promise');
			}
		}

		return new ValidationResult($this->errors === [] ? $result : null, $this->errors);
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, ?string>
	 */
	private function promiseFields(array $result): array
	{
		$fields = [];

		foreach ($result['recommendations'] as $index => $recommendation) {
			foreach (['action', 'rationale', 'impact_rationale'] as $field) {
				$fields['$.recommendations[' . $index . '].' . $field] = $recommendation[$field];
			}
		}

		return $fields;
	}

	/**
	 * @param list<string> $refs
	 */
	private function basis(?string $basis, array $refs, string $path): void
	{
		if ($basis === 'evidence' && $refs === []) {
			$this->error($path . '.evidence_refs', 'evidence_without_refs');
		}
	}

	protected function limit(string $kind): int
	{
		return AnalysisContract::LIMITS[$kind];
	}

	protected function maxItems(): int
	{
		return AnalysisContract::MAX_ITEMS;
	}

	protected function maxRefs(): int
	{
		return AnalysisContract::MAX_REFS;
	}
}
