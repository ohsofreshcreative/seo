<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

use OsfSeo\Ai\Context\TextSanitizer;

/**
 * Walidacja odpowiedzi modelu po stronie PHP (kontrakt wersji 1): poprawny JSON, dokładny zestaw pól, typy, wyliczenia, limity długości
 * i liczby elementów, unikalne identyfikatory, odwołania wyłącznie do dowodów obecnych w kontekście tego uruchomienia, twierdzenie
 * „evidence” z co najmniej jednym odwołaniem, rekomendacje wskazujące istniejące ustalenia, bez prognoz liczbowych w ocenie wpływu
 * i bez obietnic („gwarantuje”). Odpowiedź z jakimkolwiek błędem jest odrzucana w całości (status `invalid`, wynik niezapisany).
 */
final class OutputValidator
{
	public const MAX_BYTES = 200000;

	private const ID = '/^[A-Za-z][A-Za-z0-9_\-]{0,15}$/';

	/** Prognozy liczbowe w ocenie wpływu (procenty, mnożniki, „+N kliknięć”). */
	private const FORECAST = '/(\d+(?:[.,]\d+)?\s*%|\d+(?:[.,]\d+)?\s*(?:x|×)(?![a-z])|\b\d+(?:[.,]\d+)?[\s-]*(?:razy|krotn)|\+\s*\d)/iu';

	/** Obietnice wyniku (rekomendacje to hipotezy do sprawdzenia). */
	private const PROMISE = '/(gwarant|guarantee)/iu';

	/** @var list<array{path: string, code: string}> */
	private array $errors = [];

	/** @var array<string, true> */
	private array $known = [];

	/**
	 * @param list<string> $knownRefs odwołania obecne w kontekście uruchomienia
	 */
	public function validate(string $text, array $knownRefs): ValidationResult
	{
		$this->errors = [];
		$this->known = array_fill_keys($knownRefs, true);

		if (strlen($text) > self::MAX_BYTES) {
			return new ValidationResult(null, [['path' => '$', 'code' => 'too_large']]);
		}

		$data = json_decode(trim($text), true, 32, JSON_BIGINT_AS_STRING);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new ValidationResult(null, [['path' => '$', 'code' => 'invalid_json']]);
		}

		if (! is_array($data) || ($data !== [] && array_is_list($data))) {
			return new ValidationResult(null, [['path' => '$', 'code' => 'not_object']]);
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
	 * @param array<string, mixed>|mixed $value
	 * @param list<string> $expected
	 */
	private function keys(mixed $value, array $expected, string $path): bool
	{
		if (! is_array($value) || ($value !== [] && array_is_list($value))) {
			$this->error($path, 'wrong_type');

			return false;
		}

		$ok = true;

		foreach ($expected as $key) {
			if (! array_key_exists($key, $value)) {
				$this->error($path . '.' . $key, 'missing_field');
				$ok = false;
			}
		}

		foreach (array_keys($value) as $key) {
			if (! in_array($key, $expected, true)) {
				$this->error($path . '.' . self::key((string) $key), 'unexpected_field');
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function items(mixed $value, string $path): array
	{
		if (! is_array($value) || ! array_is_list($value)) {
			$this->error($path, 'wrong_type');

			return [];
		}

		if (count($value) > AnalysisContract::MAX_ITEMS) {
			$this->error($path, 'too_many_items');

			return [];
		}

		return $value;
	}

	private function string(mixed $value, string $path, string $limit, bool $required): ?string
	{
		if (! is_string($value)) {
			$this->error($path, 'wrong_type');

			return null;
		}

		$max = AnalysisContract::LIMITS[$limit];
		$clean = TextSanitizer::text($value, $max + 1);

		if ($required && $clean === '') {
			$this->error($path, 'empty');
		} elseif (mb_strlen($clean, 'UTF-8') > $max) {
			$this->error($path, 'too_long');
		}

		return $clean;
	}

	/**
	 * @param list<string> $allowed
	 */
	private function enum(mixed $value, array $allowed, string $path): ?string
	{
		if (! is_string($value) || ! in_array($value, $allowed, true)) {
			$this->error($path, 'invalid_enum');

			return null;
		}

		return $value;
	}

	/**
	 * @param array<string, true> $seen
	 */
	private function id(mixed $value, string $path, array &$seen): ?string
	{
		if (! is_string($value) || preg_match(self::ID, $value) !== 1) {
			$this->error($path, 'invalid_id');

			return null;
		}

		if (isset($seen[$value])) {
			$this->error($path, 'duplicate_id');
		}

		$seen[$value] = true;

		return $value;
	}

	/**
	 * @return list<string>
	 */
	private function refs(mixed $value, string $path): array
	{
		if (! is_array($value) || ! array_is_list($value)) {
			$this->error($path, 'wrong_type');

			return [];
		}

		$refs = [];

		foreach ($value as $index => $ref) {
			if (! is_string($ref)) {
				$this->error($path . '[' . $index . ']', 'wrong_type');
			} elseif (! isset($this->known[$ref])) {
				$this->error($path . '[' . $index . ']', 'unknown_ref');
			} else {
				$refs[$ref] = true;
			}
		}

		if (count($refs) > AnalysisContract::MAX_REFS) {
			$this->error($path, 'too_many_refs');
		}

		return array_keys($refs);
	}

	/**
	 * @param array<string, true> $findings
	 * @return list<string>
	 */
	private function findingIds(mixed $value, array $findings, string $path): array
	{
		if (! is_array($value) || ! array_is_list($value)) {
			$this->error($path, 'wrong_type');

			return [];
		}

		$ids = [];

		foreach ($value as $index => $id) {
			if (! is_string($id) || ! isset($findings[$id])) {
				$this->error($path . '[' . $index . ']', 'unknown_finding');
			} else {
				$ids[$id] = true;
			}
		}

		return array_keys($ids);
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

	private function error(string $path, string $code): void
	{
		$this->errors[] = ['path' => $path, 'code' => $code];
	}

	/** Nazwa nieoczekiwanego pola w ścieżce błędu — bez dowolnej treści z odpowiedzi. */
	private static function key(string $key): string
	{
		return preg_match('/^[A-Za-z0-9_]{1,40}$/', $key) === 1 ? $key : '?';
	}
}
