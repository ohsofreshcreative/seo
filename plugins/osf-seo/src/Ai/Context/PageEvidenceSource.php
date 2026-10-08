<?php

declare(strict_types=1);

namespace OsfSeo\Ai\Context;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Strategy\Topics\TopicRow;

/**
 * Zapisana treść stron tematu dla kontekstu AI (wersja 2): strona docelowa i strony konkurencji powiązane z wynikami SERP fraz tematu.
 * Wyłącznie odczyt zapisanych snapshotów projektu — nigdy pobieranie ani żądanie sieciowe przy budowaniu kontekstu.
 */
interface PageEvidenceSource
{
	/**
	 * @param list<array<string, mixed>> $members frazy tematu (`StrategyService::topicView()['members']`)
	 * @return array{project: ?array<string, mixed>, competitors: list<array<string, mixed>>, competitors_total: int}
	 */
	public function forTopic(ProjectContext $context, TopicRow $topic, array $members): array;
}
