<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

/**
 * Strona w obrębie projektu (`page_targets`): adres, host, rodzaj (`project` / `competitor`), źródło (`manual`, `topic`, `serp`), stan
 * ostatniej próby i ostatni poprawny snapshot. Ta sama strona w innym projekcie to osobny rekord (bez współdzielenia treści).
 */
final class PageTarget
{
	public const KIND_PROJECT = 'project';

	public const KIND_COMPETITOR = 'competitor';

	public const SOURCE_MANUAL = 'manual';

	public const SOURCE_TOPIC = 'topic';

	public const SOURCE_SERP = 'serp';

	public const STATUS_NEW = 'new';

	public const STATUS_OK = 'ok';

	public const STATUS_FAILED = 'failed';

	public const STATUS_BLOCKED = 'blocked';

	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly string $url,
		public readonly string $host,
		public readonly string $kind,
		public readonly string $source,
		public readonly ?int $topicId,
		public readonly string $status,
		public readonly ?string $lastAttemptAt,
		public readonly ?string $lastSuccessAt,
		public readonly ?string $lastError,
		public readonly ?int $lastHttpStatus,
		public readonly ?int $lastSnapshotId,
		public readonly ?string $finalUrl,
		public readonly string $createdAt,
		public readonly string $updatedAt,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$int = static fn (?string $value): ?int => $value === null ? null : (int) $value;

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			(string) $row['url'],
			(string) $row['host'],
			(string) $row['kind'],
			(string) $row['source'],
			$int($row['topic_id'] ?? null),
			(string) $row['status'],
			$row['last_attempt_at'],
			$row['last_success_at'],
			$row['last_error'],
			$int($row['last_http_status']),
			$int($row['last_snapshot_id']),
			$row['final_url'],
			(string) $row['created_at'],
			(string) $row['updated_at'],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->publicId,
			'url' => $this->url,
			'host' => $this->host,
			'kind' => $this->kind,
			'source' => $this->source,
			'status' => $this->status,
			'last_attempt_at' => $this->lastAttemptAt,
			'last_success_at' => $this->lastSuccessAt,
			'last_error' => $this->lastError,
			'last_http_status' => $this->lastHttpStatus,
			'final_url' => $this->finalUrl,
		];
	}
}
