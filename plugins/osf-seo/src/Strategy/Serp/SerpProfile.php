<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Serp;

/**
 * Profil zakończonego pomiaru SERP (`serp_snapshot_profiles`) — pochodna wyników, niezmienna w obrębie wersji reguł: kompozycja
 * TOP10/TOP20, kształty wyników z pewnością, cechy strony wyników i sygnał intencji z SERP. Bez danych zależnych od projektu
 * (konkurenci, wszechobecne domeny, świeżość) — te są liczone przy odczycie.
 */
final class SerpProfile
{
	/**
	 * @param array<string, int> $shapesTop10 kształt → liczba wyników
	 * @param array<string, int> $shapesTop20
	 * @param list<array{0: int, 1: string, 2: string, 3: string}> $results TOP20: [pozycja, kształt, pewność, powód]
	 * @param list<string> $features cechy strony wyników (z elementów SERP)
	 */
	public function __construct(
		public readonly int $snapshotId,
		public readonly int $version,
		public readonly int $organicTop10,
		public readonly int $organicTop20,
		public readonly int $domainsTop10,
		public readonly int $domainsTop20,
		public readonly int $topDomainTop10,
		public readonly int $homeTop10,
		public readonly string $shape,
		public readonly ?float $shapeShare,
		public readonly string $shapeConfidence,
		public readonly SerpIntentSignal $intent,
		public readonly string $intentConfidence,
		public readonly array $shapesTop10,
		public readonly array $shapesTop20,
		public readonly array $results,
		public readonly array $features,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$composition = json_decode((string) $row['composition'], true);
		$composition = is_array($composition) ? $composition : [];

		return new self(
			(int) $row['snapshot_id'],
			(int) $row['version'],
			(int) $row['organic_top10'],
			(int) $row['organic_top20'],
			(int) $row['domains_top10'],
			(int) $row['domains_top20'],
			(int) $row['top_domain_top10'],
			(int) $row['home_top10'],
			(string) $row['shape'],
			$row['shape_share'] === null ? null : (float) $row['shape_share'],
			(string) $row['shape_confidence'],
			SerpIntentSignal::tryFrom((string) $row['intent']) ?? SerpIntentSignal::Unknown,
			(string) $row['intent_confidence'],
			array_map('intval', (array) ($composition['top10'] ?? [])),
			array_map('intval', (array) ($composition['top20'] ?? [])),
			array_values(array_filter((array) ($composition['results'] ?? []), 'is_array')),
			array_values(array_map('strval', (array) ($composition['features'] ?? []))),
		);
	}

	/**
	 * Kolumny wiersza `serp_snapshot_profiles` (bez `project_id` i `created_at`).
	 *
	 * @return array<string, int|float|string|null>
	 */
	public function toRow(): array
	{
		return [
			'snapshot_id' => $this->snapshotId,
			'version' => $this->version,
			'organic_top10' => $this->organicTop10,
			'organic_top20' => $this->organicTop20,
			'domains_top10' => $this->domainsTop10,
			'domains_top20' => $this->domainsTop20,
			'top_domain_top10' => $this->topDomainTop10,
			'home_top10' => $this->homeTop10,
			'shape' => $this->shape,
			'shape_share' => $this->shapeShare,
			'shape_confidence' => $this->shapeConfidence,
			'intent' => $this->intent->value,
			'intent_confidence' => $this->intentConfidence,
			'composition' => (string) json_encode([
				'top10' => $this->shapesTop10,
				'top20' => $this->shapesTop20,
				'results' => $this->results,
				'features' => $this->features,
			], JSON_UNESCAPED_SLASHES),
		];
	}

	/**
	 * Profil do dowodów i CLI — pewności skorygowane o świeżość pomiaru (nieaktualny — o poziom niżej).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(?string $freshness = SerpFreshness::FRESH): array
	{
		return [
			'shape' => $this->shape,
			'shape_share' => $this->shapeShare,
			'shape_confidence' => SerpConfidence::forFreshness($this->shapeConfidence, $freshness),
			'intent_signal' => $this->intent->value,
			'intent_confidence' => SerpConfidence::forFreshness($this->intentConfidence, $freshness),
			'top10' => [
				'organic' => $this->organicTop10,
				'domains' => $this->domainsTop10,
				'top_domain_results' => $this->topDomainTop10,
				'home' => $this->homeTop10,
				'shapes' => $this->shapesTop10,
			],
			'top20' => ['organic' => $this->organicTop20, 'domains' => $this->domainsTop20, 'shapes' => $this->shapesTop20],
			'features' => $this->features,
		];
	}
}
