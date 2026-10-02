<?php

declare(strict_types=1);

namespace OsfSeo\Strategy;

use OsfSeo\Auth\Capabilities;
use OsfSeo\Auth\ProjectContext;
use OsfSeo\Market\KeywordMetricsProvider;
use OsfSeo\Market\Market;
use OsfSeo\Market\MarketKeyword;
use OsfSeo\Market\MarketMetricsRepository;
use OsfSeo\Serp\SerpKeywordRules;
use OsfSeo\Support\Clock;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Ulid;
use OsfSeo\Support\ValidationException;

/**
 * Strategia dla CLI i (od fazy D) panelu — wyłącznie przez ProjectContext (docs/ARCHITECTURE.md, sekcja 15). Żadna metoda nie
 * wysyła żądań do API. Przeliczenie i wpisy ręczne wymagają `osf_seo_manage_strategy`; odczyt — dostępu do projektu.
 */
final class StrategyService
{
	public function __construct(
		private readonly StrategyRefresher $refresher,
		private readonly StrategyKeywordRepository $keywords,
		private readonly StrategySettingsRepository $settings,
		private readonly StrategyConfig $config,
		private readonly KeywordMetricsProvider $provider,
		private readonly MarketMetricsRepository $metrics,
		private readonly Clock $clock,
		private readonly Logger $logger,
	) {
	}

	public function config(): StrategyConfig
	{
		return $this->config;
	}

	public function market(ProjectContext $context): ?Market
	{
		$project = $context->project();

		return $this->provider->resolveMarket($project->country, $project->language);
	}

	/**
	 * Stan Strategii projektu: limity, rynek, ostatnie przeliczenie, aktualność klucza danych, okno GSC, liczby kandydatów.
	 *
	 * @return array<string, mixed>
	 */
	public function status(ProjectContext $context): array
	{
		$settings = $this->settings->get($context->projectId());
		$scope = $this->refresher->scope($context->projectId(), true);
		$key = $scope === null ? null : $this->refresher->dataKey($scope);

		return [
			'market' => $scope?->market->label(),
			'config' => $this->config->effective(),
			'revision' => $settings->revision,
			'refreshed_at' => $settings->refreshedAt,
			'refresh_ms' => $settings->refreshMs,
			'up_to_date' => $key !== null && $settings->dataKey === $key,
			'gsc' => $scope === null ? null : $this->refresher->gscState($scope),
			'counts' => $this->keywords->counts($context->projectId()),
			'last_refresh' => $settings->stats,
		];
	}

	/**
	 * Podgląd kandydatów ze wszystkich źródeł bez żadnego zapisu.
	 *
	 * @return array<string, mixed>
	 */
	public function preview(ProjectContext $context, int $sample = 20): array
	{
		return $this->refresher->preview($context->projectId(), $sample);
	}

	/**
	 * Przeliczenie kandydatów, faktów i dowodów (bez API; tylko po zmianie klucza danych albo z `$force`).
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(ProjectContext $context, bool $force = false): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$report = $this->refresher->refresh($context->projectId(), $force);

		if ($report['skipped'] === null) {
			$this->logger->info('Strategy candidates of project {project} refreshed: {inserted} new, {updated} updated, {deactivated} deactivated in {ms} ms.', [
				'project' => $context->publicId(),
				'inserted' => $report['inserted'],
				'updated' => $report['updated'],
				'deactivated' => $report['deactivated'],
				'ms' => $report['duration_ms'],
			]);
		}

		return $report;
	}

	/**
	 * @return array{rows: list<CandidateRow>, total: int}
	 */
	public function candidates(ProjectContext $context, CandidateFilters $filters): array
	{
		return $this->keywords->list($context->projectId(), $filters);
	}

	/**
	 * Kandydat z faktami i dowodami — po ULID albo tekście frazy (klucz rynkowy na rynku projektu).
	 *
	 * @throws StrategyNotFound
	 */
	public function keyword(ProjectContext $context, string $value): CandidateRow
	{
		$value = trim($value);

		if (Ulid::normalize($value) !== null) {
			return $this->keywords->find($context->projectId(), $value) ?? throw new StrategyNotFound();
		}

		$market = $this->market($context) ?? throw new StrategyNotFound();

		return $this->keywords->findByKey($context->projectId(), $market, bin2hex(MarketKeyword::key($value))) ?? throw new StrategyNotFound();
	}

	/**
	 * Wpisy ręczne — bez żadnego żądania do API i bez wzbogacania (fakty uzupełni przeliczenie). Podbija rewizję (klucz danych).
	 *
	 * @param list<string>|string $input
	 * @return array{added: int, existing: int, rejected: list<array{keyword: string, reason: string}>}
	 *
	 * @throws ValidationException
	 */
	public function addKeywords(ProjectContext $context, array|string $input): array
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$market = $this->market($context) ?? throw new ValidationException(['keywords' => 'Rynek projektu nie jest obsługiwany.']);
		$texts = is_array($input) ? $input : (preg_split('/[\r\n,;]+/u', $input) ?: []);
		$keywords = [];
		$rejected = [];

		foreach ($texts as $raw) {
			if (! is_string($raw) || trim($raw) === '') {
				continue;
			}

			$keyword = MarketKeyword::normalize($raw);
			$reason = SerpKeywordRules::rejection($keyword);

			if ($reason !== null) {
				$rejected[] = ['keyword' => mb_substr($keyword, 0, 200), 'reason' => $reason];

				continue;
			}

			$keywords[bin2hex(MarketKeyword::key($keyword))] = $keyword;
		}

		if ($keywords === []) {
			if ($rejected === []) {
				throw new ValidationException(['keywords' => 'Podaj co najmniej jedną frazę.']);
			}

			return ['added' => 0, 'existing' => 0, 'rejected' => $rejected];
		}

		$ids = array_values($this->metrics->ensure($market, array_map('strval', array_values($keywords))));
		$result = $this->keywords->addManual($context->projectId(), array_map('intval', $ids), $context->userId(), $this->now());

		if ($result['added'] > 0) {
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return $result + ['rejected' => $rejected];
	}

	/**
	 * Zdjęcie wpisów ręcznych (ULID kandydatów albo teksty fraz). Podbija rewizję (klucz danych).
	 *
	 * @param list<string> $values
	 */
	public function removeKeywords(ProjectContext $context, array $values): int
	{
		$context->assertCan(Capabilities::MANAGE_STRATEGY);
		$publicIds = [];

		foreach ($values as $value) {
			try {
				$publicIds[] = $this->keyword($context, (string) $value)->publicId;
			} catch (StrategyNotFound) {
				continue;
			}
		}

		$removed = $this->keywords->removeManual($context->projectId(), array_values(array_unique($publicIds)), $this->now());

		if ($removed > 0) {
			$this->settings->bumpRevision($context->projectId(), $context->userId());
		}

		return $removed;
	}

	private function now(): string
	{
		return $this->clock->now()->format('Y-m-d H:i:s');
	}
}
