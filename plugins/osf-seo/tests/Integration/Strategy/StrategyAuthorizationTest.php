<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Strategy;

use OsfSeo\Auth\AccessDenied;
use OsfSeo\Auth\ProjectNotFound;
use OsfSeo\Auth\Roles;
use OsfSeo\Projects\ProjectRole;
use OsfSeo\Strategy\CandidateFilters;
use OsfSeo\Strategy\StrategyNotFound;

/**
 * Strategia a autoryzacja: kandydaci wyłącznie przez ProjectContext (IDOR — rekord innego projektu jest nieodróżnialny od
 * nieistniejącego), przeliczenie i wpisy ręczne tylko z `osf_seo_manage_strategy`, klient widzi kandydatów tylko do odczytu.
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
}
