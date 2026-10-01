<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

/**
 * Properties konta Google dla projektu + sugestia na podstawie domeny projektu.
 * Sugestia nigdy nie jest wybierana automatycznie — wymaga potwierdzenia użytkownika.
 */
final class PropertyList
{
	/**
	 * @param list<GscProperty> $properties
	 */
	public function __construct(
		public readonly array $properties,
		public readonly ?string $suggested,
		/** Projekt ma zapisane dane GSC. */
		public readonly bool $hasData,
		/** Property, z której pochodzą zapisane dane (null przy braku danych albo nieznanym pochodzeniu). */
		public readonly ?string $dataProperty,
	) {
	}

	public function find(string $siteUrl): ?GscProperty
	{
		foreach ($this->properties as $property) {
			if ($property->siteUrl === $siteUrl) {
				return $property;
			}
		}

		return null;
	}

	/** Wybór tej property usunie istniejące dane (wymaga jawnego potwierdzenia). */
	public function requiresReset(string $siteUrl): bool
	{
		return $this->hasData && $this->dataProperty !== $siteUrl;
	}

	/**
	 * @param list<GscProperty> $properties
	 */
	public static function suggest(array $properties, string $projectDomain): ?string
	{
		$best = null;
		$bestScore = 0;

		foreach ($properties as $property) {
			$score = $property->canQuerySearchAnalytics() ? $property->matchScore($projectDomain) : 0;

			if ($score > $bestScore) {
				$best = $property->siteUrl;
				$bestScore = $score;
			}
		}

		return $best;
	}
}
