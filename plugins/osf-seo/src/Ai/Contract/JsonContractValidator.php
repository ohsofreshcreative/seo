<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Contract;

use OsfSeo\Ai\Context\TextSanitizer;

/**
 * Wspólne prymitywy walidacji odpowiedzi modelu (kontrakty v1 i v2): dokładny zestaw pól, listy z limitem elementów, teksty z limitem długości
 * (po oczyszczeniu ze znaków sterujących), wyliczenia, identyfikatory, odwołania wyłącznie do dowodów kontekstu. Błędy to ścieżka + kod —
 * bez treści odpowiedzi (do historii i logów trafiają tylko kody).
 */
abstract class JsonContractValidator
{
	private const ID = '/^[A-Za-z][A-Za-z0-9_\-]{0,15}$/';

	/** @var list<array{path: string, code: string}> */
	protected array $errors = [];

	/** @var array<string, true> */
	protected array $known = [];

	/** Najdłuższy dozwolony tekst danego rodzaju (znaki). */
	abstract protected function limit(string $kind): int;

	abstract protected function maxItems(): int;

	abstract protected function maxRefs(): int;

	/**
	 * JSON → tablica asocjacyjna albo null (błąd zapisany).
	 *
	 * @return array<string, mixed>|null
	 */
	protected function decode(string $text, int $maxBytes): ?array
	{
		if (strlen($text) > $maxBytes) {
			$this->error('$', 'too_large');

			return null;
		}

		$data = json_decode(trim($text), true, 32, JSON_BIGINT_AS_STRING);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->error('$', 'invalid_json');

			return null;
		}

		// Odpowiedź musi być obiektem JSON — także pusta lista `[]` (po dekodowaniu pusta tablica) nim nie jest.
		if (! is_array($data) || ($data !== [] && array_is_list($data)) || ! str_starts_with(trim($text), '{')) {
			$this->error('$', 'not_object');

			return null;
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|mixed $value
	 * @param list<string> $expected
	 */
	protected function keys(mixed $value, array $expected, string $path): bool
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
	protected function items(mixed $value, string $path, ?int $max = null): array
	{
		if (! is_array($value) || ! array_is_list($value)) {
			$this->error($path, 'wrong_type');

			return [];
		}

		if (count($value) > ($max ?? $this->maxItems())) {
			$this->error($path, 'too_many_items');

			return [];
		}

		return $value;
	}

	protected function string(mixed $value, string $path, string $limit, bool $required): ?string
	{
		if (! is_string($value)) {
			$this->error($path, 'wrong_type');

			return null;
		}

		$max = $this->limit($limit);
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
	protected function enum(mixed $value, array $allowed, string $path): ?string
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
	protected function id(mixed $value, string $path, array &$seen): ?string
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
	protected function refs(mixed $value, string $path): array
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

		if (count($refs) > $this->maxRefs()) {
			$this->error($path, 'too_many_refs');
		}

		return array_keys($refs);
	}

	/**
	 * @param array<string, true> $findings
	 * @return list<string>
	 */
	protected function findingIds(mixed $value, array $findings, string $path): array
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

	protected function error(string $path, string $code): void
	{
		$this->errors[] = ['path' => $path, 'code' => $code];
	}

	/** Nazwa nieoczekiwanego pola w ścieżce błędu — bez dowolnej treści z odpowiedzi. */
	protected static function key(string $key): string
	{
		return preg_match('/^[A-Za-z0-9_]{1,40}$/', $key) === 1 ? $key : '?';
	}
}
