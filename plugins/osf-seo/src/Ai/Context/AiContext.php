<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use OsfSeo\Strategy\Topics\TopicContextBuilder;

/**
 * Kontekst AI tematu (wynik `TopicContextAssembler`): treść bez odcisku, odcisk (SHA-256 kanonicznego JSON-u treści), odcisk dowodów
 * (bez stanu pracy tematu), odwołania
 * do dowodów, braki danych i powiązanie z tematem. Ten sam stan danych → ta sama treść i ten sam odcisk (bez czasu budowania).
 */
final class AiContext
{
	public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

	/** W instrukcjach dla modelu: `<`, `>`, `&` i apostrofy jako sekwencje \u — dane nie mogą zamknąć bloku ani udawać znacznika. */
	public const PROMPT_FLAGS = self::JSON_FLAGS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * @param array<string, mixed> $body
	 */
	public function __construct(
		public readonly array $body,
		public readonly int $topicId,
		public readonly string $topicPublicId,
	) {
	}

	public function fingerprint(): string
	{
		return hash('sha256', self::encode($this->body));
	}

	/**
	 * Odcisk samych dowodów — bez stanu pracy tematu (`workflow_status`, `decision_changed_since_status`): zmiana statusu pracy nie jest
	 * zmianą danych. Pełny odcisk (`fingerprint`) opisuje dokładne wejście uruchomienia; ten — czy dowody od tamtej pory się zmieniły.
	 */
	public function evidenceFingerprint(): string
	{
		$body = $this->body;
		unset($body['topic']['workflow_status'], $body['topic']['decision_changed_since_status']);

		return hash('sha256', self::encode($body));
	}

	public function evidenceHash(): ?string
	{
		$hash = $this->body['strategy']['evidence_hash'] ?? null;

		return is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $hash : null;
	}

	/**
	 * Odwołania do dowodów obecnych w kontekście (jedyne dozwolone w odpowiedzi).
	 *
	 * @return list<string>
	 */
	public function refs(): array
	{
		return array_values(array_filter((array) ($this->body['refs'] ?? []), 'is_string'));
	}

	/**
	 * @return list<string>
	 */
	public function dataGaps(): array
	{
		return array_values(array_filter(array_map(static fn (mixed $gap): mixed => is_array($gap) ? ($gap['code'] ?? null) : null, (array) ($this->body['data_gaps'] ?? [])), 'is_string'));
	}

	public function withinBudget(): bool
	{
		return ($this->body['limits']['within_budget'] ?? false) === true;
	}

	public function action(): ?string
	{
		$action = $this->body['decision']['action'] ?? null;

		return is_string($action) && $action !== '' ? $action : null;
	}

	/** Pełny kontekst (z odciskiem) — podgląd i zapis wejścia uruchomienia. */
	public function json(int $flags = JSON_PRETTY_PRINT): string
	{
		return (string) json_encode(TopicContextBuilder::canonical($this->toArray()), self::JSON_FLAGS | $flags);
	}

	/**
	 * Dowody strukturalne (bez treści zewnętrznych) dla modelu.
	 */
	public function evidenceJson(): string
	{
		$body = $this->body;
		unset($body['external_texts']);

		return (string) json_encode(TopicContextBuilder::canonical($body + ['fingerprint' => $this->fingerprint()]), self::PROMPT_FLAGS);
	}

	/**
	 * Treści zewnętrzne (tytuły wyników SERP; tytuły, opisy, nagłówki i fragmenty pobranych stron) — osobny blok niezaufany.
	 */
	public function externalJson(): string
	{
		return (string) json_encode(array_values((array) ($this->body['external_texts'] ?? [])), self::PROMPT_FLAGS);
	}

	public function bytes(): int
	{
		return strlen(self::encode($this->body));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return $this->body + ['fingerprint' => $this->fingerprint()];
	}

	/**
	 * @param array<string, mixed> $body
	 */
	public static function encode(array $body): string
	{
		return (string) json_encode(TopicContextBuilder::canonical($body), self::JSON_FLAGS);
	}
}
