<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use OsfSeo\Strategy\Topics\TopicContextBuilder;

/**
 * Kontekst AI tematu (wynik `TopicContextAssembler`): treść bez odcisku, odcisk (SHA-256 kanonicznego JSON-u treści), odcisk dowodów
 * (bez stanu pracy tematu), odwołania
 * do dowodów, braki danych i powiązanie z tematem. Ten sam stan danych → ta sama treść i ten sam odcisk (bez czasu budowania).
 * `strategyHash` — odcisk dowodów tematu zapisany przy przeliczeniu Strategii (`strategy_topics.evidence_hash`, poza treścią i odciskami):
 * tani wskaźnik zmian Strategii w historii analiz (faza D) bez odbudowy kontekstu.
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
		public readonly ?string $strategyHash = null,
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

	/** Rodzaj dowodu odwołania (faza C): `fact` (dane mierzone), `third_party_estimate` (dane dostawcy) albo `heuristic` (reguły aplikacji). */
	public static function refKind(string $ref): string
	{
		$prefix = explode(':', $ref, 2)[0];

		return match ($prefix) {
			'gsc', 'serp', 'page', 'cpage', 'kw' => 'fact',
			'market', 'gap' => 'third_party_estimate',
			default => 'heuristic',
		};
	}

	/** Typ analizy rekomendacji (kontekst v3) albo null. */
	public function analysisType(): ?string
	{
		$type = $this->body['analysis']['type'] ?? null;

		return is_string($type) ? $type : null;
	}

	/**
	 * Ograniczenia zgodności z działaniem Strategii (kontekst v3).
	 *
	 * @return list<string>
	 */
	public function constraints(): array
	{
		return array_values(array_filter(array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['code'] ?? null) : null, (array) ($this->body['analysis']['constraints'] ?? [])), 'is_string'));
	}

	/**
	 * Adresy obecne w kontekście (jedyne dozwolone w propozycjach linkowania).
	 *
	 * @return list<string>
	 */
	public function knownUrls(): array
	{
		$content = (array) ($this->body['target_page']['page_content'] ?? []);
		$urls = [$this->body['target_page']['url'] ?? null, $content['url'] ?? null, $content['final_url'] ?? null];

		foreach ((array) ($content['internal_links'] ?? []) as $link) {
			$urls[] = is_array($link) ? ($link['url'] ?? null) : null;
		}

		foreach ((array) ($this->body['site']['pages'] ?? []) as $page) {
			$urls[] = is_array($page) ? ($page['url'] ?? null) : null;
		}

		foreach ((array) ($this->body['keywords'] ?? []) as $keyword) {
			$urls[] = is_array($keyword) ? ($keyword['target']['url'] ?? null) : null;
		}

		return array_values(array_unique(array_filter($urls, static fn (mixed $url): bool => is_string($url) && $url !== '')));
	}

	/**
	 * Teksty zewnętrzne danego źródła (np. `ui_label`, `competitor_excerpt`).
	 *
	 * @param list<string> $sources
	 * @return list<string>
	 */
	public function externalTexts(array $sources): array
	{
		$texts = [];

		foreach ((array) ($this->body['external_texts'] ?? []) as $item) {
			if (is_array($item) && in_array($item['source'] ?? null, $sources, true) && is_string($item['text'] ?? null)) {
				$texts[] = $item['text'];
			}
		}

		return $texts;
	}

	/**
	 * Teksty nagłówków sekcji prawie bez treści (`thin_section`: karty realizacji, kafle kategorii) ze strony projektu i stron konkurencji.
	 *
	 * @return list<string>
	 */
	public function cardHeadings(): array
	{
		$texts = [];

		foreach ((array) ($this->body['external_texts'] ?? []) as $item) {
			if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['text'] ?? null)) {
				$texts[$item['id']] = $item['text'];
			}
		}

		$headings = (array) ($this->body['target_page']['page_content']['headings'] ?? []);

		foreach ((array) ($this->body['evidence']['competitor_pages']['items'] ?? []) as $page) {
			array_push($headings, ...array_values((array) (is_array($page) ? ($page['headings'] ?? []) : [])));
		}

		$cards = [];

		foreach ($headings as $heading) {
			if (is_array($heading) && ($heading['thin_section'] ?? false) === true && isset($texts[(string) ($heading['text_ref'] ?? '')])) {
				$cards[] = $texts[(string) $heading['text_ref']];
			}
		}

		return array_values(array_unique($cards));
	}

	/** Jakość ekstrakcji strony docelowej (null — brak snapshotu). */
	public function pageQuality(): ?string
	{
		$quality = $this->body['target_page']['page_content']['content_quality'] ?? null;

		return is_string($quality) ? $quality : null;
	}

	/** Status meta description strony docelowej (`present`, `not_detected`, `not_detected_unconfirmed`) albo null. */
	public function descriptionStatus(): ?string
	{
		$status = $this->body['target_page']['page_content']['meta']['description_status'] ?? null;

		return is_string($status) ? $status : null;
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
