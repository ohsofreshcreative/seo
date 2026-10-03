<?php

declare(strict_types=1);

namespace OsfSeo\Strategy\Topics;

/**
 * Grupa fraz jednego tematu w trakcie przeliczenia: lider, członkowie (w kolejności lidera) i podstawa przynależności każdej frazy
 * (`leader`, `pin`, `same_target`, `serp_overlap`).
 */
final class TopicGroup
{
	public const LEADER = 'leader';

	public const PIN = 'pin';

	public const SAME_TARGET = 'same_target';

	public const SERP_OVERLAP = 'serp_overlap';

	/** @var list<int> */
	public array $members;

	/** @var array<int, string> */
	public array $basis;

	/** @var array<int, list<string>> indeks innej grupy → sygnały „możliwej grupy” */
	public array $suggestions = [];

	public function __construct(public int $leader, public readonly ?int $pinnedTopicId = null)
	{
		$this->members = [$leader];
		$this->basis = [$leader => self::LEADER];
	}

	public function add(int $marketKeywordId, string $basis): void
	{
		$this->members[] = $marketKeywordId;
		$this->basis[$marketKeywordId] = $basis;
	}
}
