<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;

/**
 * Konkurenci dodani ręcznie (`serp_competitors`). UNIQUE (projekt, domena) — bez duplikatów znormalizowanej domeny.
 */
final class CompetitorRepository
{
	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return list<Competitor>
	 */
	public function all(int $projectId, bool $withArchived = false): array
	{
		return array_map(
			static fn (array $row): Competitor => Competitor::fromRow($row),
			$this->db->fetchAll(
				"SELECT * FROM `{$this->table()}` WHERE project_id = %d" . ($withArchived ? '' : " AND status <> 'archived'") . ' ORDER BY status = \'active\' DESC, name, id',
				[$projectId],
			),
		);
	}

	/**
	 * @return list<Competitor>
	 */
	public function active(int $projectId): array
	{
		return array_values(array_filter($this->all($projectId), static fn (Competitor $competitor): bool => $competitor->isActive()));
	}

	public function find(int $projectId, string $publicId): ?Competitor
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND public_id = %s", [$projectId, $publicId]);

		return $row === null ? null : Competitor::fromRow($row);
	}

	public function findByDomain(int $projectId, string $domain): ?Competitor
	{
		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE project_id = %d AND domain_key = UNHEX(%s)", [$projectId, md5($domain)]);

		return $row === null ? null : Competitor::fromRow($row);
	}

	public function create(int $projectId, string $name, string $domain, ?int $userId): Competitor
	{
		$now = $this->now();
		$publicId = Ulid::generate();
		$this->db->execute(
			"INSERT INTO `{$this->table()}` (public_id, project_id, name, domain, domain_key, status, created_by, created_at, updated_by, updated_at)
			VALUES (%s, %d, %s, %s, UNHEX(%s), 'active', " . ($userId === null ? 'NULL' : '%d') . ', %s, ' . ($userId === null ? 'NULL' : '%d') . ', %s)',
			array_values(array_filter([$publicId, $projectId, $name, $domain, md5($domain), $userId, $now, $userId, $now], static fn (mixed $value): bool => $value !== null)),
		);

		return $this->find($projectId, $publicId) ?? throw new \RuntimeException('Competitor was not created.');
	}

	public function update(Competitor $competitor, string $name, string $domain, string $status, ?int $userId): Competitor
	{
		$this->db->execute(
			"UPDATE `{$this->table()}` SET name = %s, domain = %s, domain_key = UNHEX(%s), status = %s, updated_by = " . ($userId === null ? 'NULL' : '%d') . ', updated_at = %s
			WHERE id = %d AND project_id = %d',
			array_values(array_filter([$name, $domain, md5($domain), $status, $userId, $this->now(), $competitor->id, $competitor->projectId], static fn (mixed $value): bool => $value !== null)),
		);

		return $this->find($competitor->projectId, $competitor->publicId) ?? $competitor;
	}

	private function table(): string
	{
		return $this->db->table('serp_competitors');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
