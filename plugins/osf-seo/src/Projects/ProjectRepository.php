<?php

declare(strict_types=1);

namespace OsfSeo\Projects;

use OsfSeo\Database\Connection;
use OsfSeo\Database\DatabaseException;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Ulid;
use RuntimeException;

/**
 * Dostęp do tabel projektów. @internal — używany wyłącznie przez ProjectGuard i ProjectService.
 *
 * Nie ma tu metody „pobierz projekt po ID” bez kontekstu użytkownika: odczyt zawsze przechodzi
 * przez filtr widoczności w SQL (`canViewAll` albo członkostwo w projekcie, bez zarchiwizowanych).
 * Metody przyjmujące wewnętrzne ID służą zmianom wykonywanym PO autoryzacji (ID z ProjectContext).
 */
final class ProjectRepository
{
	private const VISIBILITY = '(%d = 1 OR (pu.user_id IS NOT NULL AND p.status <> \'archived\'))';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{project: Project, member_role: ProjectRole|null}|null
	 */
	public function findVisibleByPublicId(string $publicId, int $userId, bool $canViewAll): ?array
	{
		$row = $this->db->fetchRow(
			"SELECT p.*, pu.role AS member_role
			FROM `{$this->db->table('projects')}` p
			LEFT JOIN `{$this->db->table('project_users')}` pu ON pu.project_id = p.id AND pu.user_id = %d
			WHERE p.public_id = %s AND " . self::VISIBILITY . '
			LIMIT 1',
			[$userId, $publicId, $canViewAll ? 1 : 0],
		);

		if ($row === null) {
			return null;
		}

		return [
			'project' => Project::fromRow($row),
			'member_role' => $row['member_role'] === null ? null : ProjectRole::from($row['member_role']),
		];
	}

	/**
	 * Projekty widoczne dla użytkownika. Bez filtra statusu — wszystkie poza zarchiwizowanymi.
	 *
	 * @return list<Project>
	 */
	public function listVisible(int $userId, bool $canViewAll, ?ProjectStatus $status = null, int $limit = 200, int $offset = 0): array
	{
		$params = [$userId, $canViewAll ? 1 : 0];
		$statusSql = "AND p.status <> 'archived'";

		if ($status !== null) {
			$statusSql = 'AND p.status = %s';
			$params[] = $status->value;
		}

		$params[] = max(1, min($limit, 1000));
		$params[] = max(0, $offset);

		$rows = $this->db->fetchAll(
			"SELECT p.*
			FROM `{$this->db->table('projects')}` p
			LEFT JOIN `{$this->db->table('project_users')}` pu ON pu.project_id = p.id AND pu.user_id = %d
			WHERE " . self::VISIBILITY . " {$statusSql}
			ORDER BY p.name ASC, p.id ASC
			LIMIT %d OFFSET %d",
			$params,
		);

		return array_map(static fn (array $row): Project => Project::fromRow($row), $rows);
	}

	/**
	 * @param array{name: string, domain: string, country: string, language: string} $data
	 */
	public function insert(array $data, int $createdBy): Project
	{
		$now = $this->now();

		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$publicId = Ulid::generate($this->clock->now());

			try {
				$this->db->insert($this->db->table('projects'), [
					'public_id' => $publicId,
					'name' => $data['name'],
					'domain' => $data['domain'],
					'country' => $data['country'],
					'language' => $data['language'],
					'status' => ProjectStatus::Active->value,
					'created_by' => $createdBy,
					'created_at' => $now,
					'updated_at' => $now,
				]);

				return $this->loadByPublicId($publicId);
			} catch (DatabaseException $exception) {
				// Kolizja ULID jest praktycznie niemożliwa, ale obsłużona; inne błędy — dalej.
				if ($attempt === 3 || ! str_contains($exception->getMessage(), 'Duplicate')) {
					throw $exception;
				}
			}
		}

		throw new RuntimeException('Could not create project.');
	}

	/**
	 * @param array<string, string> $fields dozwolone: name, domain, country, language, status
	 */
	public function update(int $projectId, array $fields): void
	{
		$allowed = array_intersect_key($fields, array_flip(['name', 'domain', 'country', 'language', 'status']));
		$allowed['updated_at'] = $this->now();

		$this->db->update($this->db->table('projects'), $allowed, ['id' => $projectId]);
	}

	public function reload(Project $project): Project
	{
		return $this->loadByPublicId($project->publicId);
	}

	public function assign(int $projectId, int $userId, ProjectRole $role): void
	{
		$this->db->execute(
			"INSERT INTO `{$this->db->table('project_users')}` (project_id, user_id, role, created_at)
			VALUES (%d, %d, %s, %s)
			ON DUPLICATE KEY UPDATE role = VALUES(role)",
			[$projectId, $userId, $role->value, $this->now()],
		);
	}

	public function unassign(int $projectId, int $userId): bool
	{
		return $this->db->delete($this->db->table('project_users'), ['project_id' => $projectId, 'user_id' => $userId]) > 0;
	}

	/**
	 * @return list<array{user_id: int, role: ProjectRole}>
	 */
	public function members(int $projectId): array
	{
		$rows = $this->db->fetchAll(
			"SELECT user_id, role FROM `{$this->db->table('project_users')}` WHERE project_id = %d ORDER BY created_at, user_id",
			[$projectId],
		);

		return array_map(
			static fn (array $row): array => ['user_id' => (int) $row['user_id'], 'role' => ProjectRole::from((string) $row['role'])],
			$rows,
		);
	}

	/** Po usunięciu konta WordPress — usuwa jego przypisania do projektów. */
	/**
	 * Podpina połączenie Google do projektu (null = odłącza). Zmiana połączenia czyści wybrane property GSC.
	 */
	public function setConnection(int $projectId, ?int $connectionId): void
	{
		$table = $this->db->table('projects');

		if ($connectionId === null) {
			$this->db->execute(
				"UPDATE `{$table}` SET connection_id = NULL, gsc_property = NULL, gsc_permission = NULL, updated_at = %s WHERE id = %d",
				[$this->now(), $projectId],
			);

			return;
		}

		// MySQL wykonuje przypisania SET od lewej — IF() widzi jeszcze poprzednie connection_id.
		$this->db->execute(
			"UPDATE `{$table}`
			SET gsc_property = IF(connection_id <=> %d, gsc_property, NULL),
				gsc_permission = IF(connection_id <=> %d, gsc_permission, NULL),
				connection_id = %d,
				updated_at = %s
			WHERE id = %d",
			[$connectionId, $connectionId, $connectionId, $this->now(), $projectId],
		);
	}

	public function countByConnection(int $connectionId): int
	{
		return (int) $this->db->fetchValue(
			"SELECT COUNT(*) FROM `{$this->db->table('projects')}` WHERE connection_id = %d",
			[$connectionId],
		);
	}

	public function removeUserEverywhere(int $userId): void
	{
		$this->db->delete($this->db->table('project_users'), ['user_id' => $userId]);
	}

	private function loadByPublicId(string $publicId): Project
	{
		$row = $this->db->fetchRow(
			"SELECT * FROM `{$this->db->table('projects')}` WHERE public_id = %s",
			[$publicId],
		);

		if ($row === null) {
			throw new RuntimeException('Project disappeared after write.');
		}

		return Project::fromRow($row);
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
