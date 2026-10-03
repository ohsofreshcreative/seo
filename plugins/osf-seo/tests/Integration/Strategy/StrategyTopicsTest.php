<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\ProjectContext;
use OsfSeo\Strategy\Decision\StrategyAction;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\Topics\TopicEvent;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\ValidationException;

/**
 * Rdzeń Strategii (STEP 16, faza C) na prawdziwej bazie: tematy z kandydatów (strona docelowa, działanie, pewność, priorytet), zdarzenia
 * tylko istotnych zmian, status pracy odporny na przeliczenie (O, P), stabilne ID (dodanie i usunięcie frazy, podział i scalenie
 * przypięciem, zmiana lidera), ręczna strona docelowa, pakiet kontekstu — bez żadnych żądań do API.
 */
final class StrategyTopicsTest extends StrategyTestCase
{
	private const PAGE = 'https://example.pl/pozycjonowanie/';

	public function test_refresh_builds_topics_from_candidates_without_requests(): void
	{
		$context = $this->scenario();
		$requests = count($this->dataForSeoRequests());

		$report = $this->strategy->refresh($context);

		self::assertSame($requests, count($this->dataForSeoRequests()), 'Przeliczenie tematów nie wysyła żądań.');
		self::assertSame(['topics' => 3, 'inserted' => 3, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0, 'events' => 3], $report['topics']);
		self::assertSame([
			'pozycjonowanie stron' => ['optimize', 'gsc_position', 'probable', 2],
			'audyt seo' => ['optimize', 'gsc_position', 'probable', 1],
			'agencja seo łódź' => ['investigate', 'data_incomplete', 'unknown', 1],
		], $this->summary($context));

		$detail = $this->strategy->topic($context, 'pozycjonowanie stron www');
		self::assertSame('pozycjonowanie stron', $detail['topic']->label, 'Temat po frazie członkowskiej.');
		self::assertSame(self::PAGE, $detail['topic']->targetUrl);
		self::assertSame(['pozycjonowanie stron', 'pozycjonowanie stron www'], array_column($detail['members'], 'keyword'));
		self::assertSame(['same_target'], array_values(array_unique(array_column(array_slice($detail['topic']->analysis['members'], 1), 'basis'))));
		self::assertSame([TopicEvent::CREATED], array_column($detail['events'], 'type'));
		self::assertSame(TopicStatus::New->value, $detail['topic']->status);

		// Fraza zna swój temat i stan strony docelowej (pole pomocnicze).
		$db = self::db();
		self::assertSame(['probable', self::PAGE], array_values($db->fetchRow(
			"SELECT s.target_state, u.url FROM `{$db->table('strategy_keywords')}` s LEFT JOIN `{$db->table('serp_urls')}` u ON u.id = s.target_url_id
			JOIN `{$db->table('market_keywords')}` m ON m.id = s.market_keyword_id WHERE s.project_id = %d AND m.keyword = 'pozycjonowanie stron www'",
			[$context->projectId()],
		) ?? []));

		// Ponowne przeliczenie bez zmian danych — tematy bez zapisu i bez zdarzeń.
		$again = $this->strategy->refresh($context, true);
		self::assertSame(['topics' => 3, 'inserted' => 0, 'updated' => 0, 'unchanged' => 3, 'deactivated' => 0, 'events' => 0], $again['topics']);
		self::assertSame(3, $this->strategy->status($context)['topics']['active']);
	}

	public function test_o_p_user_status_survives_refresh_and_changes_after_decision_are_flagged(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$audit = $this->strategy->setStatus($context, 'audyt seo', TopicStatus::Dismissed, 'Nie robimy audytów.');
		$main = $this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::Completed);

		self::assertSame(['dismissed', 'Nie robimy audytów.', 'optimize'], [$audit->status, $audit->note, $audit->statusBasis['action'] ?? null]);
		self::assertSame(['completed', '2026-01-15', 'optimize'], [$main->status, $main->completedOn, $main->baseline['action'] ?? null], 'Punkt odniesienia do oceny efektu.');
		self::assertSame(900, $main->baseline['gsc']['impressions'] ?? null);

		// Dane się zmieniają: wyświetlenia frazy rozkładają się na dwie strony (konsolidacja), nowa strona dla frazy głównej.
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://example.pl/blog/audyt/', 0, '2026-01-13');
		$this->gscKeyword($context, 'pozycjonowanie stron', 300, 12.0, self::PAGE, 0, '2026-01-13');
		// Wiersze GSC wstawione bezpośrednio (bez importu) nie zmieniają klucza danych — przeliczenie wymuszone.
		$this->strategy->refresh($context, true);

		$audit = $this->strategy->topic($context, 'audyt seo')['topic'];
		$main = $this->strategy->topic($context, 'pozycjonowanie stron')['topic'];
		self::assertSame(['dismissed', 'consolidate', true], [$audit->status, $audit->action, $audit->decisionChanged], 'O: odrzucony zostaje odrzucony (flaga zmiany po decyzji).');
		self::assertSame(['completed', 'optimize', false], [$main->status, $main->action, $main->decisionChanged], 'P: zrealizowany zostaje zrealizowany.');
		self::assertSame('2026-01-15', $main->completedOn);
		self::assertSame(900, $main->baseline['gsc']['impressions'] ?? null, 'Punkt odniesienia nie jest nadpisywany przez przeliczenie.');
		self::assertContains(TopicEvent::ACTION_CHANGED, array_column($this->strategy->topic($context, 'audyt seo')['events'], 'type'));
		self::assertSame(['status_changed'], array_values(array_unique(array_filter(array_column($this->strategy->topic($context, 'audyt seo')['events'], 'type'), static fn (string $type): bool => $type === TopicEvent::STATUS_CHANGED))));

		// Lista domyślna: otwarte; odrzucone i zrealizowane osobno.
		self::assertSame(['agencja seo łódź'], array_map(static fn (TopicRow $row): string => (string) $row->label, $this->strategy->topics($context, new TopicFilters())['rows']));
		self::assertSame(['audyt seo'], array_map(static fn (TopicRow $row): string => (string) $row->label, $this->strategy->topics($context, new TopicFilters(status: 'dismissed'))['rows']));

		// Ponowne otwarcie jest ręczne.
		$reopened = $this->strategy->setStatus($context, 'audyt seo', TopicStatus::Review);
		self::assertSame(['review', false, 'consolidate'], [$reopened->status, $reopened->decisionChanged, $reopened->statusBasis['action'] ?? null]);
	}

	public function test_topic_id_is_stable_when_keywords_are_added_removed_or_the_leader_changes(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$id = $this->strategy->topic($context, 'pozycjonowanie stron')['topic']->publicId;

		// Dodanie frazy z tą samą stroną docelową.
		$this->gscKeyword($context, 'pozycjonowanie www', 150, 18.0, self::PAGE);
		$this->strategy->refresh($context, true);
		$added = $this->strategy->topic($context, 'pozycjonowanie www');
		self::assertSame([$id, 3], [$added['topic']->publicId, $added['topic']->keywordsCount]);

		// Zmiana lidera (nowa fraza ma największy popyt).
		$this->gscKeyword($context, 'pozycjonowanie www', 5000, 18.0, self::PAGE);
		$this->strategy->refresh($context, true);
		$leader = $this->strategy->topic($context, $id);
		self::assertSame([$id, 'pozycjonowanie www'], [$leader['topic']->publicId, $leader['topic']->label]);
		self::assertContains(TopicEvent::LEADER_CHANGED, array_column($leader['events'], 'type'));

		// Usunięcie frazy (przestaje być kandydatem — brak danych GSC).
		$db = self::db();
		$db->execute(
			"DELETE q FROM `{$db->table('gsc_query_daily')}` q JOIN `{$db->table('keywords')}` k ON k.id = q.keyword_id WHERE q.project_id = %d AND k.keyword = 'pozycjonowanie www'",
			[$context->projectId()],
		);
		$this->strategy->refresh($context, true);
		$removed = $this->strategy->topic($context, $id);
		self::assertSame([$id, 2, 'pozycjonowanie stron'], [$removed['topic']->publicId, $removed['topic']->keywordsCount, $removed['topic']->label]);
	}

	public function test_pins_split_and_merge_topics_with_stable_ids_and_events(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$main = $this->strategy->topic($context, 'pozycjonowanie stron')['topic']->publicId;
		$audit = $this->strategy->topic($context, 'audyt seo')['topic']->publicId;
		$revision = $this->strategy->status($context)['revision'];

		// Podział: fraza przypięta do nowego tematu; temat główny zostaje z liderem.
		$split = $this->strategy->pin($context, ['pozycjonowanie stron www'], null);
		self::assertSame([1, []], [$split['pinned'], $split['missing']]);
		self::assertGreaterThan($revision, $this->strategy->status($context)['revision'], 'Przypięcie podbija rewizję (klucz danych).');
		self::assertFalse($this->strategy->status($context)['up_to_date']);
		$this->strategy->refresh($context);
		self::assertSame([$main, 1], [$this->strategy->topic($context, 'pozycjonowanie stron')['topic']->publicId, $this->strategy->topic($context, $main)['topic']->keywordsCount]);
		$new = $this->strategy->topic($context, 'pozycjonowanie stron www');
		self::assertSame([$split['topic'], true, 'pin'], [$new['topic']->publicId, $new['members'][0]['pinned'], $new['topic']->analysis['members'][0]['basis']]);
		self::assertSame([TopicEvent::CREATED, TopicEvent::PINNED], array_column($new['events'], 'type'));

		// Scalenie: fraza tematu „audyt seo” przypięta do tematu głównego — temat audytu nieaktywny (scalony).
		$this->strategy->pin($context, ['audyt seo'], 'pozycjonowanie stron');
		$this->strategy->refresh($context);
		$merged = $this->strategy->topic($context, $main);
		self::assertSame(['pozycjonowanie stron', 'audyt seo'], array_column($merged['members'], 'keyword'));
		$old = $this->strategy->topic($context, $audit)['topic'];
		self::assertSame([false, 'merged', $main], [$old->active, $old->inactiveReason, $old->mergedInto]);
		self::assertSame(TopicEvent::MERGED, $this->strategy->topic($context, $audit)['events'][0]['type']);

		// Odpięcie — fraza wraca do grupowania automatycznego (ta sama strona docelowa co temat główny).
		self::assertSame(1, $this->strategy->unpin($context, ['pozycjonowanie stron www']));
		$this->strategy->refresh($context);
		self::assertSame($main, $this->strategy->topic($context, 'pozycjonowanie stron www')['topic']->publicId);
		$former = $this->strategy->topic($context, $split['topic'])['topic'];
		self::assertSame([false, 'merged', $main], [$former->active, $former->inactiveReason, $former->mergedInto], 'Temat z przypięcia scalony z tematem głównym.');
	}

	public function test_manual_target_and_manual_no_page_confirmation(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);

		try {
			$this->strategy->setTarget($context, 'agencja seo łódź', 'https://konkurent.pl/agencja/');
			self::fail('Adres spoza domeny projektu.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('url', $exception->errors());
		}

		$topic = $this->strategy->setTarget($context, 'agencja seo łódź', 'https://www.example.pl/agencja-seo-lodz/#oferta');
		self::assertSame('https://www.example.pl/agencja-seo-lodz/', $topic->manualTargetUrl, 'Adres znormalizowany (bez fragmentu), subdomena projektu dozwolona.');
		$this->strategy->refresh($context);
		$topic = $this->strategy->topic($context, 'agencja seo łódź')['topic'];
		self::assertSame(['confirmed', 'https://www.example.pl/agencja-seo-lodz/', ['manual']], [$topic->targetState, $topic->targetUrl, $topic->analysis['target']['families']]);

		$this->strategy->setTarget($context, 'agencja seo łódź', null, true);
		$this->strategy->refresh($context);
		$topic = $this->strategy->topic($context, 'agencja seo łódź')['topic'];
		self::assertSame(['none', true], [$topic->targetState, $topic->manualNoPage]);
		self::assertNotSame(StrategyAction::Create->value, $topic->action, 'Bez popytu i dowodów nie ma kandydata na nową stronę nawet po potwierdzeniu braku strony.');

		$this->strategy->setTarget($context, 'agencja seo łódź', null);
		$this->strategy->refresh($context);
		self::assertSame('unknown', $this->strategy->topic($context, 'agencja seo łódź')['topic']->targetState);
		self::assertSame([TopicEvent::TARGET_CHANGED, TopicEvent::MANUAL_TARGET], array_slice(array_column($this->strategy->topic($context, 'agencja seo łódź')['events'], 'type'), 0, 2));
	}

	public function test_context_package_is_deterministic_and_marks_external_data(): void
	{
		$context = $this->scenario();
		$this->strategy->refresh($context);
		$this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::Planned, 'Notatka wewnętrzna');

		$first = $this->strategy->context($context, 'pozycjonowanie stron');
		$second = $this->strategy->context($context, 'pozycjonowanie stron www');

		self::assertSame($first, $second);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['evidence_hash']);
		self::assertSame(['optimize', 'gsc_position'], [$first['decision']['action'], $first['decision']['reason']]);
		self::assertSame(['leader', 'member'], array_column($first['keywords'], 'role'));
		self::assertContains('keywords[].keyword', $first['untrusted']);
		self::assertSame(['planned', 'Notatka wewnętrzna'], [$first['workflow']['status'], $first['workflow']['note']]);
		self::assertStringNotContainsString('_facts', (string) json_encode($first));

		// Zmiana stanu pracy nie zmienia odcisku dowodów; zmiana danych — tak.
		$this->strategy->setStatus($context, 'pozycjonowanie stron', TopicStatus::InProgress);
		self::assertSame($first['evidence_hash'], $this->strategy->context($context, 'pozycjonowanie stron')['evidence_hash']);
		$this->gscKeyword($context, 'pozycjonowanie stron', 300, 12.0, self::PAGE, 0, '2026-01-13');
		$this->strategy->refresh($context, true);
		self::assertNotSame($first['evidence_hash'], $this->strategy->context($context, 'pozycjonowanie stron')['evidence_hash']);

		$this->expectException(StrategyNotFound::class);
		$this->strategy->context($context, 'nieznana fraza');
	}

	/**
	 * Projekt example.pl: dwie frazy z tą samą stroną (GSC), osobna strona audytu, wpis ręczny bez danych.
	 */
	private function scenario(): ProjectContext
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'pozycjonowanie stron', 600, 14.0, self::PAGE);
		$this->gscKeyword($context, 'pozycjonowanie stron www', 300, 16.0, self::PAGE);
		$this->gscKeyword($context, 'audyt seo', 200, 8.0, 'https://example.pl/audyt/');
		$this->strategy->addKeywords($context, ['agencja seo łódź']);

		return $context;
	}

	/**
	 * Aktywne tematy: etykieta → [działanie, powód, stan strony docelowej, liczba fraz] (kolejność priorytetu).
	 *
	 * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: int}>
	 */
	private function summary(ProjectContext $context): array
	{
		$result = [];

		foreach ($this->strategy->topics($context, new TopicFilters(status: 'all', includeMonitor: true))['rows'] as $row) {
			$result[(string) $row->label] = [$row->action, $row->actionReason, $row->targetState, $row->keywordsCount];
		}

		return $result;
	}
}
