<?php

declare(strict_types=1);

namespace OsfSeo\Google;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Tabela `osf_connections`. Refresh token wyłącznie zaszyfrowany (TokenVault); access tokenów nie zapisujemy.
 */
final class ConnectionRepository
{
	private const COLUMNS = 'id, owner_user_id, google_sub, email, status, scopes, last_error, last_refreshed_at, created_at, updated_at';

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function find(int $id): ?GoogleConnection
	{
		$row = $this->db->fetchRow('SELECT ' . self::COLUMNS . " FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : GoogleConnection::fromRow($row);
	}

	public function findByAccount(int $ownerUserId, string $googleSub): ?GoogleConnection
	{
		$row = $this->db->fetchRow(
			'SELECT ' . self::COLUMNS . " FROM `{$this->table()}` WHERE provider = 'google' AND google_sub = %s AND owner_user_id = %d",
			[$googleSub, $ownerUserId],
		);

		return $row === null ? null : GoogleConnection::fromRow($row);
	}

	public function encryptedRefreshToken(int $id): ?string
	{
		return $this->db->fetchValue("SELECT refresh_token_enc FROM `{$this->table()}` WHERE id = %d", [$id]);
	}

	/**
	 * Zapisuje połączenie (nowe albo ponownie autoryzowane konto tego samego właściciela) i zwraca jego ID.
	 *
	 * @param list<string> $scopes
	 */
	public function save(int $ownerUserId, string $googleSub, string $email, string $encryptedRefreshToken, array $scopes): int
	{
		$now = $this->now();

		$this->db->execute(
			"INSERT INTO `{$this->table()}`
				(owner_user_id, provider, google_sub, email, refresh_token_enc, scopes, status, last_error, created_at, updated_at)
			VALUES (%d, 'google', %s, %s, %s, %s, 'active', NULL, %s, %s)
			ON DUPLICATE KEY UPDATE email = VALUES(email), refresh_token_enc = VALUES(refresh_token_enc),
				scopes = VALUES(scopes), status = 'active', last_error = NULL, updated_at = VALUES(updated_at)",
			[$ownerUserId, $googleSub, $email, $encryptedRefreshToken, implode(' ', $scopes), $now, $now],
		);

		return (int) $this->db->fetchValue(
			"SELECT id FROM `{$this->table()}` WHERE provider = 'google' AND google_sub = %s AND owner_user_id = %d",
			[$googleSub, $ownerUserId],
		);
	}

	public function updateRefreshToken(int $id, string $encryptedRefreshToken): void
	{
		$this->db->update($this->table(), ['refresh_token_enc' => $encryptedRefreshToken, 'updated_at' => $this->now()], ['id' => $id]);
	}

	public function markRefreshed(int $id): void
	{
		$now = $this->now();
		$this->db->update($this->table(), ['last_refreshed_at' => $now, 'updated_at' => $now], ['id' => $id]);
	}

	public function markNeedsReauth(int $id, string $reason): void
	{
		$this->db->update($this->table(), [
			'status' => ConnectionStatus::NeedsReauth->value,
			'last_error' => substr($reason, 0, 255),
			'updated_at' => $this->now(),
		], ['id' => $id]);
	}

	public function delete(int $id): void
	{
		$this->db->delete($this->table(), ['id' => $id]);
	}

	/**
	 * @return array<string, int> status => liczba połączeń
	 */
	public function statusCounts(): array
	{
		$counts = array_fill_keys(array_column(ConnectionStatus::cases(), 'value'), 0);

		foreach ($this->db->fetchAll("SELECT status, COUNT(*) AS total FROM `{$this->table()}` GROUP BY status") as $row) {
			$counts[(string) $row['status']] = (int) $row['total'];
		}

		return $counts;
	}

	private function table(): string
	{
		return $this->db->table('connections');
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
