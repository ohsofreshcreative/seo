<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Integration\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\AiRefused;
use OsfSeo\Ai\Provider\AiProviderException;
use OsfSeo\Ai\Run\AiRun;
use OsfSeo\Tests\Support\AiFakes;
use WP_Error;

/**
 * Płatny dostawca (adapter OpenAI za atrapą `pre_http_request`, syntetyczny klucz): odmowy przed jakimkolwiek żądaniem (9, 10, 11),
 * budżet AI z rezerwacją i ochroną przed równoległym przekroczeniem (12, 13), wyniki niepewne bez ponowień (14), brak sekretów
 * w logach i historii (15). Limity DataForSEO nie są dotykane.
 */
final class AiPaidProviderTest extends AiTestCase
{
	public function test_9_10_11_paid_provider_is_refused_until_consciously_configured(): void
	{
		$context = $this->aiProject();

		// 9: nieznany dostawca.
		self::assertSame(['provider_unknown'], $this->ai->plan($context, 'pozycjonowanie stron', 'anthropic')->blockers);

		// Domyślnie: wyłączone, bez dostawcy, klucza, modelu i cen.
		self::assertSame(['ai_disabled', 'provider_not_configured', 'missing_api_key', 'model_not_configured', 'missing_prices'], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		// 10: wszystko poza kluczem — odmowa bez żądania.
		$this->configureOpenAi([AiConfig::OPENAI_API_KEY => '']);
		self::assertSame(['missing_api_key'], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		// 11: wyłącznik wyłączony mimo pełnej konfiguracji.
		$this->configureOpenAi([AiConfig::ENABLED => '0']);
		self::assertSame(['ai_disabled'], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		// Inny dostawca w konfiguracji niż żądany.
		$this->configureOpenAi([AiConfig::PROVIDER => 'anthropic']);
		self::assertSame(['provider_not_configured'], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		// Limity domyślne (0 USD) blokują każde płatne wywołanie.
		$this->configureOpenAi([AiConfig::DAILY_LIMIT => '', AiConfig::MONTHLY_LIMIT => '', AiConfig::PROJECT_MONTHLY_LIMIT => '', AiConfig::MAX_RUN_COST => '']);
		self::assertSame(['run_cost_limit', 'budget_daily', 'budget_monthly', 'budget_project'], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		foreach ([
			'confirmation_required' => [],
			'missing_api_key' => [AiConfig::OPENAI_API_KEY => ''],
			'ai_disabled' => [AiConfig::ENABLED => '0'],
			'missing_prices' => [AiConfig::PRICE_OUTPUT => ''],
		] as $code => $overrides) {
			$this->configureOpenAi($overrides);

			try {
				// Pełna konfiguracja bez potwierdzenia też jest odmową.
				$this->ai->run($context, 'pozycjonowanie stron', 'openai', null, $overrides !== []);
				self::fail('Płatne wywołanie musi zostać odrzucone: ' . $code);
			} catch (AiRefused $refused) {
				self::assertSame($code, $refused->code());
			}
		}

		self::assertSame([], $this->openAiRequests(), 'Żadna odmowa nie wysyła żądania.');
		self::assertSame(0, $this->aiRunCount(), 'Odmowa nie tworzy uruchomienia ani rezerwacji.');
	}

	public function test_12_13_budget_reserves_before_the_call_settles_on_usage_and_blocks_overspend(): void
	{
		$context = $this->aiProject();
		$this->configureOpenAi();
		$plan = $this->ai->plan($context, 'pozycjonowanie stron', 'openai');
		self::assertTrue($plan->runnable(), implode(', ', $plan->blockers));
		$max = (float) $plan->maxCost;
		self::assertGreaterThan(0.0, $max);

		// Dzienny limit na 1,9 maksymalnego kosztu: w trakcie wywołania liczy się cała rezerwacja (równoległa analiza zablokowana).
		$this->configureOpenAi([AiConfig::DAILY_LIMIT => (string) round($max * 1.9, 6)]);
		$during = null;
		$this->google->always('https://api.openai.com/v1/responses', function (array $request) use ($context, &$during): array {
			$during = $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers;

			return ['status' => 200, 'json' => AiFakes::openAiResponse(AiFakes::validAnalysis(self::refsFromRequest($request)), 6000, 900)];
		});

		$run = $this->ai->run($context, 'pozycjonowanie stron', 'openai', null, true);

		self::assertSame(['budget_daily'], $during, 'Rezerwacja w toku liczy się do limitu.');
		self::assertSame(AiRun::STATUS_SUCCEEDED, $run->status);
		self::assertTrue($run->paid);
		self::assertSame(round($max, 6), round($run->reservedCost, 6));
		self::assertSame(round((6000 * 1 + 900 * 4) / 1e6, 6), $run->actualCost, 'Rozliczenie ze zgłoszonego zużycia.');
		self::assertSame(AiRun::COST_USAGE, $run->costBasis);
		self::assertSame([6000, 0, 900], [$run->inputTokens, $run->cachedTokens, $run->outputTokens]);
		self::assertSame('test-model-1', $run->model);
		self::assertCount(1, $this->openAiRequests());
		self::assertSame($run->actualCost, $this->ai->budget($context)['spent']['today']);

		// Po rozliczeniu limit znowu pozwala (koszt rzeczywisty < rezerwacji)…
		self::assertLessThan($max * 0.9, (float) $run->actualCost);
		self::assertSame([], $this->ai->plan($context, 'pozycjonowanie stron', 'openai')->blockers);

		// …a przekroczenie blokuje przed wywołaniem.
		$this->configureOpenAi([AiConfig::DAILY_LIMIT => (string) round((float) $run->actualCost + $max / 2, 6)]);
		self::assertSame(['budget_daily'], $this->ai->plan($context, 'audyt seo', 'openai')->blockers);
		$this->configureOpenAi([AiConfig::MAX_RUN_COST => (string) round($max / 2, 6)]);
		self::assertSame(['run_cost_limit'], $this->ai->plan($context, 'audyt seo', 'openai')->blockers);
		$this->configureOpenAi([AiConfig::PROJECT_MONTHLY_LIMIT => (string) round((float) $run->actualCost + $max / 2, 6)]);
		self::assertSame(['budget_project'], $this->ai->plan($context, 'audyt seo', 'openai')->blockers);

		// Limit projektu nie blokuje innego projektu; limity globalne — tak.
		$other = $this->aiProject('drugi-projekt.pl');
		self::assertSame([], $this->ai->plan($other, 'audyt seo', 'openai')->blockers);

		// 13: rezerwacja pod blokadą — blokada trzymana przez inny proces → odmowa bez wywołania.
		$this->configureOpenAi();
		$held = $this->holdBudgetLock();

		try {
			$this->ai->run($other, 'audyt seo', 'openai', null, true);
			self::fail('Zajęta blokada budżetu musi odmówić.');
		} catch (AiRefused $refused) {
			self::assertSame('budget_busy', $refused->code());
		} finally {
			$held->query("SELECT RELEASE_LOCK('" . $held->real_escape_string(self::db()->lockName('ai_budget')) . "')");
			$held->close();
		}

		self::assertCount(1, $this->openAiRequests());
		self::assertSame(1, $this->aiRunCount());

		// Limity DataForSEO bez zmian (osobny budżet).
		self::assertSame(1.0, $this->market->budget()->dailyLimit);
		self::assertSame(10.0, $this->market->budget()->monthlyLimit);
	}

	public function test_14_timeouts_and_uncertain_results_charge_the_reservation_without_retry(): void
	{
		$context = $this->aiProject();
		$this->configureOpenAi();
		$endpoint = 'https://api.openai.com/v1/responses';
		$cases = [
			'timeout' => [new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'), AiRun::STATUS_UNCERTAIN, AiRun::COST_RESERVATION, AiProviderException::TRANSPORT],
			'server' => [['status' => 500, 'json' => ['error' => ['message' => 'x', 'type' => 'server_error', 'code' => null]]], AiRun::STATUS_UNCERTAIN, AiRun::COST_RESERVATION, AiProviderException::SERVER],
			'rate limit' => [['status' => 429, 'json' => ['error' => ['message' => 'x', 'type' => 'requests', 'code' => 'rate_limit_exceeded']]], AiRun::STATUS_FAILED, AiRun::COST_NOT_CHARGED, AiProviderException::RATE_LIMITED],
			'auth' => [['status' => 401, 'json' => ['error' => ['message' => 'x', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]], AiRun::STATUS_FAILED, AiRun::COST_NOT_CHARGED, AiProviderException::AUTH],
			'incomplete' => [['status' => 200, 'json' => ['incomplete_details' => ['reason' => 'max_output_tokens']] + AiFakes::openAiResponse('{"summary": "', 6000, 3000, 0, 'incomplete')], AiRun::STATUS_FAILED, AiRun::COST_USAGE, AiProviderException::INCOMPLETE],
			'invalid output' => [['status' => 200, 'json' => AiFakes::openAiResponse('To nie jest JSON', 6000, 50)], AiRun::STATUS_INVALID, AiRun::COST_USAGE, 'contract_invalid'],
		];
		$expectedRequests = 0;

		foreach ($cases as $label => [$reply, $status, $basis, $error]) {
			$this->google->on($endpoint, $reply);
			$run = $this->ai->run($context, 'pozycjonowanie stron', 'openai', null, true);
			$expectedRequests++;

			self::assertCount($expectedRequests, $this->openAiRequests(), $label . ': dokładnie jedno żądanie, bez ponowień.');
			self::assertSame([$status, $basis, $error], [$run->status, $run->costBasis, $run->errorCode], $label);

			if ($basis === AiRun::COST_RESERVATION) {
				self::assertNull($run->actualCost, $label);
				self::assertSame($run->reservedCost, $run->chargedCost(), $label . ': niepewny wynik liczy całą rezerwację.');
			} elseif ($basis === AiRun::COST_NOT_CHARGED) {
				self::assertSame(0.0, $run->chargedCost(), $label);
			} else {
				self::assertGreaterThan(0.0, $run->chargedCost(), $label);
			}
		}

		// Porzucone: rezerwacja bez wysłania → bez kosztu; w toku → niepewne (rezerwacja).
		$db = self::db();
		$runs = $this->aiRuns->list($context->projectId());
		$old = $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s');
		$db->execute("UPDATE `{$db->table('ai_runs')}` SET status = 'reserved', started_at = NULL, created_at = %s, actual_cost = NULL WHERE public_id = %s", [$old, $runs[0]->publicId]);
		$db->execute("UPDATE `{$db->table('ai_runs')}` SET status = 'running', started_at = %s, actual_cost = NULL WHERE public_id = %s", [$old, $runs[1]->publicId]);
		self::assertSame(2, $this->ai->recoverStale());
		$reserved = $this->aiRuns->find($context->projectId(), $runs[0]->publicId);
		$running = $this->aiRuns->find($context->projectId(), $runs[1]->publicId);
		self::assertSame([AiRun::STATUS_FAILED, 'not_sent', 0.0], [$reserved?->status, $reserved?->errorCode, $reserved?->chargedCost()]);
		self::assertSame([AiRun::STATUS_UNCERTAIN, 'interrupted', $running?->reservedCost], [$running?->status, $running?->errorCode, $running?->chargedCost()]);
		self::assertCount($expectedRequests, $this->openAiRequests(), 'Odzyskanie nie ponawia żądania.');
	}

	public function test_15_api_key_never_reaches_logs_history_plans_or_status(): void
	{
		$context = $this->aiProject();
		$this->configureOpenAi();
		$key = AiFakes::apiKey();
		$this->mockOpenAiSuccess();
		$ok = $this->ai->run($context, 'pozycjonowanie stron', 'openai', null, true);
		$this->google->on('https://api.openai.com/v1/responses', ['status' => 401, 'json' => ['error' => ['message' => 'Incorrect API key provided: ' . $key, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']]]);
		$failed = $this->ai->run($context, 'pozycjonowanie stron', 'openai', null, true);

		self::assertSame([AiRun::STATUS_SUCCEEDED, AiRun::STATUS_FAILED], [$ok->status, $failed->status]);
		self::assertSame('Bearer ' . $key, $this->openAiRequests()[0]['headers']['Authorization'], 'Klucz wyłącznie w nagłówku żądania.');
		self::assertStringNotContainsString($key, $this->openAiRequests()[0]['body']);

		$db = self::db();
		$stored = (string) json_encode([
			$db->fetchAll("SELECT * FROM `{$db->table('ai_runs')}`"),
			$db->fetchAll("SELECT input, output_raw, result, validation FROM `{$db->table('ai_run_payloads')}`"),
		]);
		$surfaces = [
			'logs' => implode("\n", $this->logLines),
			'history' => $stored,
			'plan' => (string) json_encode($this->ai->plan($context, 'pozycjonowanie stron', 'openai')->toArray()),
			'status' => (string) json_encode($this->ai->status()),
			'budget' => (string) json_encode($this->ai->budget($context)),
			'run' => (string) json_encode($failed->toArray(true)),
		];

		foreach ($surfaces as $surface => $text) {
			self::assertStringNotContainsString($key, $text, $surface);
			self::assertStringNotContainsString(substr($key, 10), $text, $surface);
		}

		self::assertStringContainsString('invalid_api_key', $surfaces['logs'], 'Log zawiera kod błędu dostawcy, nie komunikat.');
		self::assertStringNotContainsString('Incorrect API key', $surfaces['logs'] . $stored);
	}

	private function holdBudgetLock(): \mysqli
	{
		$other = $this->secondConnection();
		$other->query("SELECT GET_LOCK('" . $other->real_escape_string(self::db()->lockName('ai_budget')) . "', 0)");

		return $other;
	}
}
