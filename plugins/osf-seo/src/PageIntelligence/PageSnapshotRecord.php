<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence;

/**
 * Zapisany snapshot strony (`page_snapshots`): wyekstrahowana treść z pobrania, odciski treści i odpowiedzi, czas pierwszego pobrania tej
 * treści (`fetchedAt`) i ostatniego potwierdzenia, że się nie zmieniła (`lastSeenAt`).
 */
final class PageSnapshotRecord
{
	/**
	 * @param array<string, mixed> $data wynik ekstrakcji (`PageExtraction::toArray`) + łańcuch przekierowań
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $publicId,
		public readonly int $projectId,
		public readonly int $targetId,
		public readonly int $extractorVersion,
		public readonly string $fetchedAt,
		public readonly string $lastSeenAt,
		public readonly int $httpStatus,
		public readonly string $contentType,
		public readonly ?string $charset,
		public readonly string $finalUrl,
		public readonly int $bytes,
		public readonly int $fetchMs,
		public readonly string $bodyHash,
		public readonly string $contentHash,
		public readonly ?string $etag,
		public readonly ?string $lastModified,
		public readonly ?string $title,
		public readonly int $wordCount,
		public readonly string $contentQuality,
		public readonly string $indexability,
		public readonly string $canonicalStatus,
		public readonly array $data,
	) {
	}

	/**
	 * @param array<string, string|null> $row
	 */
	public static function fromRow(array $row): self
	{
		$data = json_decode((string) ($row['data'] ?? '{}'), true);

		return new self(
			(int) $row['id'],
			(string) $row['public_id'],
			(int) $row['project_id'],
			(int) $row['target_id'],
			(int) $row['extractor_version'],
			(string) $row['fetched_at'],
			(string) $row['last_seen_at'],
			(int) $row['http_status'],
			(string) $row['content_type'],
			$row['charset'],
			(string) $row['final_url'],
			(int) $row['bytes'],
			(int) $row['fetch_ms'],
			bin2hex((string) $row['body_hash']),
			bin2hex((string) $row['content_hash']),
			$row['etag'],
			$row['last_modified'],
			$row['title'],
			(int) $row['word_count'],
			(string) $row['content_quality'],
			(string) $row['indexability'],
			(string) $row['canonical_status'],
			is_array($data) ? $data : [],
		);
	}

	/**
	 * Podsumowanie (lista, CLI) albo pełne dane.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(bool $full = false): array
	{
		return [
			'id' => $this->publicId,
			'fetched_at' => $this->fetchedAt,
			'last_seen_at' => $this->lastSeenAt,
			'http_status' => $this->httpStatus,
			'content_type' => $this->contentType,
			'final_url' => $this->finalUrl,
			'bytes' => $this->bytes,
			'fetch_ms' => $this->fetchMs,
			'content_hash' => $this->contentHash,
			'extractor_version' => $this->extractorVersion,
			'title' => $this->title,
			'word_count' => $this->wordCount,
			'content_quality' => $this->contentQuality,
			'indexability' => $this->indexability,
			'canonical_status' => $this->canonicalStatus,
		] + ($full ? ['data' => $this->data] : []);
	}
}
