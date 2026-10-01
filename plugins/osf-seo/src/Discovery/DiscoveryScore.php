<?php

declare(strict_types=1);

namespace OsfSeo\Discovery;

/**
 * Priorytet odkrycia (0–100) z rozbiciem na składniki — pokazywany w UI, żeby wynik był przejrzysty.
 */
final class DiscoveryScore
{
	/**
	 * @param array{demand: float, attainability: float, relevance: float, gap: float, commercial: float} $components
	 */
	public function __construct(
		public readonly int $priority,
		public readonly array $components,
	) {
	}

	/**
	 * @return array{priority: int, demand: float, attainability: float, relevance: float, gap: float, commercial: float}
	 */
	public function toArray(): array
	{
		return ['priority' => $this->priority] + $this->components;
	}

	/**
	 * @param array<string, mixed>|null $data
	 */
	public static function fromArray(?array $data): ?self
	{
		if (! is_array($data) || ! isset($data['priority'])) {
			return null;
		}

		$components = [];

		foreach (DiscoveryScorer::MAX_POINTS as $name => $max) {
			$components[$name] = max(0.0, min((float) $max, (float) ($data[$name] ?? 0)));
		}

		return new self(max(0, min(100, (int) $data['priority'])), $components);
	}
}
