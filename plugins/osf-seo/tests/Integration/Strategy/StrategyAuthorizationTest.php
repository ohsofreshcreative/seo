<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\StrategyNotFound;
use OsfSeo\Strategy\Topics\TopicFilters;
use OsfSeo\Strategy\Topics\TopicRow;
use OsfSeo\Strategy\Topics\TopicStatus;
use OsfSeo\Support\ValidationException;

/**
 * Strategia a autoryzacja: kandydaci i tematy wyłącznie przez ProjectContext (IDOR — rekord innego projektu jest nieodróżnialny od
 * nieistniejącego), przeliczenie, wpisy ręczne, status, strona docelowa i przypięcia tylko z `osf_seo_manage_strategy`, klient widzi
 * aktywny backlog tylko do odczytu — bez odrzuconych tematów i notatek wewnętrznych (D62).
 */
final class StrategyAuthorizationTest extends StrategyTestCase
{
	public function test_candidates_of_another_project_are_not_found_and_cannot_be_changed(): void
	{
		$first = $this->gapProject();
		$this->gscKeyword($first, 'sklep internetowy', 300, 8.0);
		$this->strategy->addKeywords($first, 'audyt seo');
		$this->strategy->refresh($first);
		$publicId = $this->strategy->keyword($first, 'audyt seo')->publicId;
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');

		foreach (['ULID' => $publicId, 'tekst frazy' => 'audyt seo', 'fraza GSC' => 'sklep internetowy'] as $label => $value) {
			try {
				$this->strategy->keyword($second, $value);
				self::fail('Kandydat innego projektu: ' . $label);
			} catch (StrategyNotFound) {
			}
		}

		self::assertSame(0, $this->strategy->removeKeywords($second, [$publicId, 'audyt seo']));
		self::assertTrue($this->strategy->keyword($first, $publicId)->manual, 'Wpis ręczny pierwszego projektu bez zmian.');
		self::assertSame(['rows' => [], 'total' => 0], $this->strategy->candidates($second, new CandidateFilters(status: 'all')));
		self::assertSame(['active' => 0, 'manual' => 0, 'inactive' => []], $this->strategy->status($second)['counts']);

		// Przeliczenie drugiego projektu nie dotyka kandydatów pierwszego.
		$this->strategy->refresh($second);
		self::assertSame(['audyt seo' => ['manual'], 'sklep internetowy' => ['gsc']], $this->activeCandidates($first));
	}

	public function test_client_sees_candidates_read_only_and_app_admin_can_manage(): void
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'sklep internetowy', 300, 8.0);
		$this->strategy->refresh($context);
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);
		$publicId = $this->strategy->keyword($context, 'sklep internetowy')->publicId;

		self::assertSame(1, $this->strategy->candidates($clientContext, new CandidateFilters())['total']);
		self::assertSame($publicId, $this->strategy->keyword($clientContext, $publicId)->publicId);
		self::assertSame(1, $this->strategy->status($clientContext)['counts']['active']);
		self::assertNull($this->strategy->preview($clientContext)['skipped'], 'Podgląd bez zapisu jest odczytem.');

		foreach ([
			'refresh' => fn () => $this->strategy->refresh($clientContext, true),
			'addKeywords' => fn () => $this->strategy->addKeywords($clientContext, 'audyt seo'),
			'removeKeywords' => fn () => $this->strategy->removeKeywords($clientContext, [$publicId]),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		self::assertSame(['sklep internetowy' => ['gsc']], $this->activeCandidates($context));
		self::assertSame(0, $this->strategySettings->get($context->projectId())->revision);

		// Członek projektu z rolą managera, ale bez capability — nadal brak dostępu do operacji (uprawnienia z capabilities, nie z roli).
		$manager = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $manager, ProjectRole::Manager);

		try {
			$this->strategy->addKeywords($this->guard->authorize($context->publicId(), $manager), 'audyt seo');
			self::fail('Bez osf_seo_manage_strategy.');
		} catch (AccessDenied) {
		}

		$admin = $this->createUser(Roles::ADMIN);
		$adminContext = $this->guard->authorize($context->publicId(), $admin);
		self::assertSame(1, $this->strategy->addKeywords($adminContext, 'audyt seo')['added']);
		self::assertNull($this->strategy->refresh($adminContext)['skipped']);
		self::assertSame(1, $this->strategySettings->get($context->projectId())->revision);

		$outsider = $this->createUser(Roles::CLIENT);
		$this->expectException(ProjectNotFound::class);
		$this->guard->authorize($context->publicId(), $outsider);
	}

	public function test_topics_of_another_project_are_not_found_and_cannot_be_changed(): void
	{
		$first = $this->gapProject();
		$this->gscKeyword($first, 'sklep internetowy', 300, 8.0, 'https://example.pl/sklep/');
		$this->strategy->addKeywords($first, 'audyt seo');
		$this->strategy->refresh($first);
		$topic = $this->strategy->topic($first, 'sklep internetowy')['topic'];
		$second = $this->gapProject(['inny.pl' => 'Inny'], 'drugi-projekt.pl');
		$this->strategy->refresh($second);
		$candidate = $this->strategy->keyword($first, 'audyt seo')->publicId;

		foreach ([
			'temat (ULID)' => fn () => $this->strategy->topic($second, $topic->publicId),
			'temat po frazie' => fn () => $this->strategy->topic($second, 'sklep internetowy'),
			'kontekst' => fn () => $this->strategy->context($second, $topic->publicId),
			'status' => fn () => $this->strategy->setStatus($second, $topic->publicId, TopicStatus::Dismissed),
			'strona docelowa' => fn () => $this->strategy->setTarget($second, $topic->publicId, 'https://drugi-projekt.pl/sklep/'),
			'przypięcie do tematu' => fn () => $this->strategy->pin($second, ['sklep internetowy'], $topic->publicId),
		] as $label => $call) {
			try {
				$call();
				self::fail('Temat innego projektu: ' . $label);
			} catch (StrategyNotFound | ValidationException) {
			}
		}

		self::assertSame(0, $this->strategy->unpin($second, [$candidate, 'audyt seo']));
		self::assertSame(['rows' => [], 'total' => 0], $this->strategy->topics($second, new TopicFilters(status: 'all', state: 'all')));
		$unchanged = $this->strategy->topic($first, $topic->publicId)['topic'];
		self::assertSame(['new', null, null], [$unchanged->status, $unchanged->manualTargetUrl, $unchanged->statusChangedAt]);
		self::assertSame(0, (int) self::db()->fetchValue("SELECT COUNT(*) FROM `" . self::db()->table('strategy_keywords') . "` WHERE pinned_topic_id IS NOT NULL"));
	}

	public function test_client_sees_the_active_backlog_read_only_without_notes_and_dismissed_topics(): void
	{
		$context = $this->gapProject();
		$this->gscKeyword($context, 'sklep internetowy', 300, 8.0, 'https://example.pl/sklep/');
		$this->gscKeyword($context, 'audyt seo', 200, 9.0, 'https://example.pl/audyt/');
		$this->strategy->refresh($context);
		$this->strategy->setStatus($context, 'sklep internetowy', TopicStatus::Planned, 'Notatka wewnętrzna agencji');
		$dismissed = $this->strategy->setStatus($context, 'audyt seo', TopicStatus::Dismissed, 'Klient nie chce');
		$client = $this->createUser(Roles::CLIENT);
		$this->service->assignUser($context, $client, ProjectRole::Viewer);
		$clientContext = $this->guard->authorize($context->publicId(), $client);

		$rows = $this->strategy->topics($clientContext, new TopicFilters(status: 'all'))['rows'];
		self::assertSame(['sklep internetowy'], array_map(static fn (TopicRow $row): ?string => $row->label, $rows));
		self::assertNull($rows[0]->note, 'Bez notatek wewnętrznych.');
		self::assertNull($this->strategy->topic($clientContext, 'sklep internetowy')['topic']->note);
		self::assertNull($this->strategy->context($clientContext, 'sklep internetowy')['workflow']['note']);
		self::assertSame('Notatka wewnętrzna agencji', $this->strategy->topic($context, 'sklep internetowy')['topic']->note);

		try {
			$this->strategy->topic($clientContext, $dismissed->publicId);
			self::fail('Odrzucony temat niewidoczny dla klienta.');
		} catch (StrategyNotFound) {
		}

		foreach ([
			'status' => fn () => $this->strategy->setStatus($clientContext, 'sklep internetowy', TopicStatus::Completed),
			'strona docelowa' => fn () => $this->strategy->setTarget($clientContext, 'sklep internetowy', 'https://example.pl/inna/'),
			'przypięcie' => fn () => $this->strategy->pin($clientContext, ['audyt seo'], null),
			'odpięcie' => fn () => $this->strategy->unpin($clientContext, ['audyt seo']),
		] as $operation => $call) {
			try {
				$call();
				self::fail('Klient nie może: ' . $operation);
			} catch (AccessDenied) {
			}
		}

		self::assertSame('planned', $this->strategy->topic($context, 'sklep internetowy')['topic']->status);
	}
}
