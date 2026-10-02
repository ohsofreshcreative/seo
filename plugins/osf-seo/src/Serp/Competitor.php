<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

/**
 * Konkurent dodany ręcznie (monitorowany). Jego pozycje nie są zapisywane osobno — wynikają z pełnych SERP-ów,
 * więc dodanie konkurenta później odtwarza jego historię z wcześniejszych pomiarów.
 */
final class Competitor
{
	public const ACTIVE = 'active';

	public const INACTIVE = 'inactive';

	public const ARCHIVED = 'archived';

	public const STATUSES = [self::ACTIVE, self::INACTIVE, self::ARCHIVED];

	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly string $name,
		public readonly string $domain,
		public readonly string $status,
		public readonly string $createdAt,
		public readonly string $updatedAt,
		/** Warianty marki konkurenta (Luki SEO: frazy markowe nie są lukami) — składnia wykluczeń. */
		public readonly string $brandTerms = '',
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			(string) $row['name'],
			(string) $row['domain'],
			(string) $row['status'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
			(string) ($row['brand_terms'] ?? ''),
		);
	}

	public function isActive(): bool
	{
		return $this->status === self::ACTIVE;
	}

	public static function statusLabel(string $status): string
	{
		return match ($status) {
			self::ACTIVE => 'aktywny',
			self::INACTIVE => 'nieaktywny',
			self::ARCHIVED => 'zarchiwizowany',
			default => $status,
		};
	}

	/**
	 * @return array<string, string>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->publicId,
			'name' => $this->name,
			'domain' => $this->domain,
			'status' => $this->status,
			'brand_terms' => $this->brandTerms,
			'created_at' => $this->createdAt,
			'updated_at' => $this->updatedAt,
		];
	}
}
