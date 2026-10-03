<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Target;

use OsfSeo\Database\Connection;
use OsfSeo\DataForSeo\DataForSeoSerpProvider;

/**
 * Strony projektu znane z Google Search Console (`pages`, adresy znormalizowane jak w słowniku SERP) — niepełny inwentarz:
 * strony bez wyświetleń w GSC nie istnieją dla tego indeksu, więc nigdy nie dowodzi on braku strony.
 */
final class GscPageIndex implements ProjectPageIndex
{
	public function __construct(private readonly Connection $db)
	{
	}

	public function source(): string
	{
		return 'gsc';
	}

	public function complete(): bool
	{
		return false;
	}

	public function pages(int $projectId): array
	{
		$pages = [];

		foreach ($this->db->fetchAll("SELECT url FROM `{$this->db->table('pages')}` WHERE project_id = %d ORDER BY id", [$projectId]) as $row) {
			$url = DataForSeoSerpProvider::url((string) $row['url']);

			if ($url !== null) {
				$pages[$url] = true;
			}
		}

		return array_keys($pages);
	}
}
