<?php

declare(strict_types=1);

namespace OsfSeo\Serp;

use OsfSeo\Database\Connection;
use OsfSeo\Support\Clock;

/**
 * Konteksty pomiarów (`serp_contexts`) — tożsamość po kluczu MD5 wszystkich parametrów; wiersz tworzony przy pierwszym użyciu.
 */
final class SerpContextRepository
{
	/** @var array<string, SerpContext> */
	private array $byKey = [];

	/** @var array<int, SerpContext> */
	private array $byId = [];

	public function __construct(
		private readonly Connection $db,
		private readonly Clock $clock,
	) {
	}

	public function ensure(SerpContext $context): SerpContext
	{
		$key = $context->key();

		if (isset($this->byKey[$key])) {
			return $this->byKey[$key];
		}

		$id = $this->db->fetchValue("SELECT id FROM `{$this->table()}` WHERE context_key = UNHEX(%s)", [$key]);

		if ($id === null) {
			$this->db->execute(
				"INSERT INTO `{$this->table()}` (context_key, engine, serp_type, location_code, language_code, device, os, depth, created_at)
				VALUES (UNHEX(%s), %s, %s, %d, %s, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE id = id",
				[$key, $context->engine, $context->serpType, $context->locationCode, $context->languageCode, $context->device->value, $context->os(), $context->depth, $this->clock->now()->format('Y-m-d H:i:s')],
			);
			$id = $this->db->fetchValue("SELECT id FROM `{$this->table()}` WHERE context_key = UNHEX(%s)", [$key]);
		}

		return $this->remember($context->withId((int) $id));
	}

	public function find(int $id): ?SerpContext
	{
		if (isset($this->byId[$id])) {
			return $this->byId[$id];
		}

		$row = $this->db->fetchRow("SELECT * FROM `{$this->table()}` WHERE id = %d", [$id]);

		return $row === null ? null : $this->remember(SerpContext::fromRow($row));
	}

	private function remember(SerpContext $context): SerpContext
	{
		$this->byKey[$context->key()] = $context;
		$this->byId[(int) $context->id] = $context;

		return $context;
	}

	private function table(): string
	{
		return $this->db->table('serp_contexts');
	}
}
