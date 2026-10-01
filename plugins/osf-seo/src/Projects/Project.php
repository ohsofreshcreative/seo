<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Projekt (niezmienny DTO). W URL-ach i UI używaj wyłącznie `publicId`;
 * `internalId` służy tylko do zapytań wewnątrz pluginu.
 */
final class Project
{
	public function __construct(
		public readonly int $internalId,
		public readonly string $publicId,
		public readonly string $name,
		public readonly string $domain,
		public readonly string $country,
		public readonly string $language,
		public readonly ProjectStatus $status,
		public readonly ?int $connectionId,
		public readonly ?string $gscProperty,
		public readonly ?string $gscPermission,
		public readonly ?DateTimeImmutable $lastSyncedAt,
		public readonly int $createdBy,
		public readonly DateTimeImmutable $createdAt,
		public readonly DateTimeImmutable $updatedAt,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			internalId: (int) $row['id'],
			publicId: (string) $row['public_id'],
			name: (string) $row['name'],
			domain: (string) $row['domain'],
			country: (string) $row['country'],
			language: (string) $row['language'],
			status: ProjectStatus::from((string) $row['status']),
			connectionId: $row['connection_id'] === null ? null : (int) $row['connection_id'],
			gscProperty: $row['gsc_property'],
			gscPermission: $row['gsc_permission'],
			lastSyncedAt: self::date($row['last_synced_at']),
			createdBy: (int) $row['created_by'],
			createdAt: self::date($row['created_at']) ?? new DateTimeImmutable('@0'),
			updatedAt: self::date($row['updated_at']) ?? new DateTimeImmutable('@0'),
		);
	}

	public function isArchived(): bool
	{
		return $this->status === ProjectStatus::Archived;
	}

	private static function date(?string $value): ?DateTimeImmutable
	{
		if ($value === null || $value === '') {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

		return $date === false ? null : $date;
	}
}
