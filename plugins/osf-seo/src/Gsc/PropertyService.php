<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Database\Connection;
use OsfSeo\Google\ConnectionRepository;
use OsfSeo\Google\GoogleConnection;
use OsfSeo\Google\ReauthorizationRequired;
use OsfSeo\Google\VaultException;
use OsfSeo\Projects\ProjectRepository;
use OsfSeo\Support\Logger;

/**
 * Wybór property Search Console dla projektu.
 *
 * - lista pochodzi zawsze z sites.list połączonego konta (wybór też jest weryfikowany po stronie
 *   serwera — wartość z formularza nie jest zaufana),
 * - property bez dostępu do Search Analytics (siteUnverifiedUser) jest odrzucana,
 * - sugestia z domeny projektu nigdy nie jest wybierana automatycznie,
 * - zmiana property przy istniejących danych innej property wymaga jawnego resetu: dane projektu
 *   są usuwane i importowane od nowa (decyzja MVP: bez izolacji danych per property — docs/ARCHITECTURE.md).
 */
final class PropertyService
{
	/** @var list<\Closure(ProjectContext): void> */
	private array $selectedListeners = [];

	/** @var list<\Closure(ProjectContext): void> */
	private array $detachListeners = [];

	public function __construct(
		private readonly GscClient $client,
		private readonly ConnectionRepository $connections,
		private readonly ProjectRepository $projects,
		private readonly GscDataStore $data,
		private readonly Connection $db,
		private readonly Logger $logger,
	) {
	}

	/**
	 * Wywoływane po zapisaniu property (np. planowanie pierwszej synchronizacji).
	 *
	 * @param \Closure(ProjectContext): void $listener
	 */
	public function onSelected(\Closure $listener): void
	{
		$this->selectedListeners[] = $listener;
	}

	/**
	 * Wywoływane w transakcji odpinania property przed resetem danych (np. anulowanie zadań).
	 *
	 * @param \Closure(ProjectContext): void $listener
	 */
	public function onDetach(\Closure $listener): void
	{
		$this->detachListeners[] = $listener;
	}

	/**
	 * @throws \OsfSeo\Auth\AccessDenied brak osf_seo_manage_connections
	 * @throws PropertySelectionException
	 */
	public function properties(ProjectContext $context): PropertyList
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		$properties = $this->fetch($this->activeConnection($context));
		$project = $context->project();

		return new PropertyList(
			$properties,
			PropertyList::suggest($properties, $project->domain),
			$this->data->hasData($context),
			$project->gscDataProperty,
		);
	}

	/**
	 * @param bool $resetData jawna zgoda na usunięcie danych innej property
	 *
	 * @throws \OsfSeo\Auth\AccessDenied
	 * @throws PropertySelectionException
	 */
	public function select(ProjectContext $context, string $siteUrl, bool $resetData = false): ProjectContext
	{
		$context->assertCan(Capabilities::MANAGE_CONNECTIONS);

		$property = null;

		foreach ($this->fetch($this->activeConnection($context)) as $candidate) {
			if ($candidate->siteUrl === $siteUrl) {
				$property = $candidate;

				break;
			}
		}

		if ($property === null) {
			throw new PropertySelectionException(PropertySelectionException::NOT_FOUND);
		}

		if (! $property->canQuerySearchAnalytics()) {
			throw new PropertySelectionException(PropertySelectionException::INSUFFICIENT_PERMISSION);
		}

		// Kontrola danych i zapis property pod blokadą wiersza projektu — tą samą, którą bierze import
		// przy zatwierdzaniu, więc dane innej property nie mogą pojawić się między sprawdzeniem a zapisem.
		$saved = $this->db->transaction(function () use ($context, $property, $resetData): bool {
			$project = $this->projects->lockForUpdate($context->projectId());
			$hasData = $this->data->hasData($context);
			$sameSource = $project?->gscDataProperty === $property->siteUrl;

			if ($hasData && ! $sameSource && ! $resetData) {
				throw new PropertySelectionException(PropertySelectionException::RESET_REQUIRED);
			}

			if ($hasData && $resetData) {
				return false;
			}

			// Brak danych → nowe dane będą pochodzić z tej property; te same dane → znacznik bez zmian.
			$this->projects->setProperty($context->projectId(), $property->siteUrl, $property->permissionLevel, $property->siteUrl);

			return true;
		});

		if (! $saved) {
			$this->resetData($context, $property);

			$this->db->transaction(function () use ($context, $property): void {
				$this->projects->lockForUpdate($context->projectId());
				$this->projects->setProperty($context->projectId(), $property->siteUrl, $property->permissionLevel, $property->siteUrl);
			});
		}

		$context = $context->withProject($this->projects->reload($context->project()));

		$this->logger->info('Search Console property {property} ({permission}) selected for project {project} by user {user}.', [
			'property' => $property->siteUrl,
			'permission' => $property->permissionLevel,
			'project' => $context->publicId(),
			'user' => $context->userId(),
		]);

		foreach ($this->selectedListeners as $listener) {
			$listener($context);
		}

		return $context;
	}

	private function resetData(ProjectContext $context, GscProperty $next): void
	{
		// 1. Odpięcie w transakcji z blokadą wiersza projektu: trwający import poprzedniej property
		//    nie przejdzie kontroli przy zatwierdzaniu (porównuje property zadania z projektem).
		$this->db->transaction(function () use ($context): void {
			$this->projects->lockForUpdate($context->projectId());
			$this->projects->detachProperty($context->projectId());

			foreach ($this->detachListeners as $listener) {
				$listener($context);
			}
		});

		// 2. Usunięcie danych partiami (bez property projekt nie jest synchronizowany).
		$deleted = $this->data->purge($context);

		$this->logger->warning('GSC data of project {project} was reset by user {user} before switching to property {property} ({rows} rows deleted).', [
			'project' => $context->publicId(),
			'user' => $context->userId(),
			'property' => $next->siteUrl,
			'rows' => $deleted,
		]);
	}

	private function activeConnection(ProjectContext $context): GoogleConnection
	{
		$connectionId = $this->projects->reload($context->project())->connectionId;
		$connection = $connectionId === null ? null : $this->connections->find($connectionId);

		if ($connection === null) {
			throw new PropertySelectionException(PropertySelectionException::NO_CONNECTION);
		}

		if (! $connection->isActive()) {
			throw new PropertySelectionException(PropertySelectionException::CONNECTION_INACTIVE);
		}

		return $connection;
	}

	/**
	 * @return list<GscProperty>
	 */
	private function fetch(GoogleConnection $connection): array
	{
		try {
			return $this->client->listSites($connection);
		} catch (ReauthorizationRequired $exception) {
			throw new PropertySelectionException(PropertySelectionException::CONNECTION_INACTIVE, $exception);
		} catch (VaultException $exception) {
			throw new PropertySelectionException(PropertySelectionException::CONFIGURATION, $exception);
		} catch (GscApiException $exception) {
			throw new PropertySelectionException(PropertySelectionException::API_ERROR, $exception);
		}
	}
}
