<?php

declare(strict_types=1);

namespace OsfSeo\Tests\Unit\Ai;

use OsfSeo\Ai\AiConfig;
use OsfSeo\Ai\Budget\AiPricing;
use OsfSeo\Ai\Provider\AiProviderRegistry;
use OsfSeo\Ai\Provider\AiUsage;
use OsfSeo\Ai\Provider\FakeProvider;
use OsfSeo\Ai\Provider\OpenAiProvider;
use OsfSeo\Support\Logger;
use OsfSeo\Support\Redactor;
use OsfSeo\Tests\Support\AiFakes;
use OsfSeo\Tests\Support\FakeHttpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Konfiguracja AI: bezpieczne wartości domyślne (wyłączone, bez modelu, cen i limitów), ceny tylko z konfiguracji, rejestr dostawców
 * (9 — nieznany dostawca), brak sekretów w ustawieniach efektywnych i w logach (15).
 */
final class AiConfigTest extends TestCase
{
	private const ENV = [
		AiConfig::ENABLED, AiConfig::PROVIDER, AiConfig::MODEL, AiConfig::OPENAI_API_KEY, AiConfig::PRICE_INPUT, AiConfig::PRICE_CACHED_INPUT,
		AiConfig::PRICE_OUTPUT, AiConfig::DAILY_LIMIT, AiConfig::MONTHLY_LIMIT, AiConfig::PROJECT_MONTHLY_LIMIT, AiConfig::MAX_RUN_COST,
		AiConfig::MAX_OUTPUT_TOKENS, AiConfig::TIMEOUT, AiConfig::TEMPERATURE, AiConfig::RETENTION_DAYS,
	];

	protected function tearDown(): void
	{
		foreach (self::ENV as $name) {
			putenv($name);
		}
	}

	public function test_defaults_are_safe_no_calls_no_model_no_prices_zero_limits(): void
	{
		$config = new AiConfig();

		self::assertFalse($config->enabled());
		self::assertNull($config->provider());
		self::assertNull($config->model());
		self::assertNull($config->priceInput());
		self::assertNull($config->priceOutput());
		self::assertSame([0.0, 0.0, 0.0, 0.0], [$config->dailyLimit(), $config->monthlyLimit(), $config->projectMonthlyLimit(), $config->maxRunCost()]);
		self::assertFalse((new AiPricing($config))->known());
		self::assertNull((new AiPricing($config))->maxCost(1000, 1000), 'Bez cen nie ma szacunku — płatne wywołanie zablokowane.');
		self::assertSame(AiConfig::DEFAULT_MAX_OUTPUT_TOKENS, $config->maxOutputTokens());
		self::assertNull($config->temperature());
	}

	public function test_values_are_parsed_and_clamped(): void
	{
		putenv(AiConfig::ENABLED . '=yes');
		putenv(AiConfig::PROVIDER . '=OpenAI');
		putenv(AiConfig::MODEL . '=bad model!');
		putenv(AiConfig::PRICE_INPUT . '=1.25');
		putenv(AiConfig::PRICE_OUTPUT . '=abc');
		putenv(AiConfig::MAX_OUTPUT_TOKENS . '=999999');
		putenv(AiConfig::RETENTION_DAYS . '=1');
		putenv(AiConfig::DAILY_LIMIT . '=-5');
		$config = new AiConfig();

		self::assertTrue($config->enabled());
		self::assertSame('openai', $config->provider());
		self::assertNull($config->model(), 'Niepoprawny identyfikator modelu = brak modelu.');
		self::assertSame(1.25, $config->priceInput());
		self::assertSame(1.25, $config->priceCachedInput(), 'Bez ceny cache — cena zwykłego wejścia (ostrożnie).');
		self::assertNull($config->priceOutput());
		self::assertSame(16000, $config->maxOutputTokens());
		self::assertSame(AiConfig::MIN_RETENTION_DAYS, $config->retentionDays());
		self::assertSame(0.0, $config->dailyLimit());
	}

	public function test_pricing_comes_only_from_configuration(): void
	{
		putenv(AiConfig::PRICE_INPUT . '=2');
		putenv(AiConfig::PRICE_CACHED_INPUT . '=0.5');
		putenv(AiConfig::PRICE_OUTPUT . '=8');
		$pricing = new AiPricing(new AiConfig());

		self::assertSame(0.018, $pricing->maxCost(5000, 1000));
		self::assertSame(round((4000 * 2 + 1000 * 0.5 + 800 * 8) / 1e6, 6), $pricing->actualCost(new AiUsage(5000, 800, 1000)));
		self::assertSame((int) ceil(10 / AiPricing::BYTES_PER_TOKEN) + AiPricing::OVERHEAD_TOKENS, AiPricing::estimateInputTokens('12345', '67890'));
	}

	public function test_9_registry_knows_only_registered_providers(): void
	{
		$registry = new AiProviderRegistry([new FakeProvider(), new OpenAiProvider(new AiConfig(), new FakeHttpTransport())]);

		self::assertSame(['fake', 'openai'], $registry->ids());
		self::assertNull($registry->get('anthropic'));
		self::assertNull($registry->get('../openai'));
		self::assertInstanceOf(OpenAiProvider::class, $registry->get(' OpenAI '));
		self::assertFalse($registry->get('fake')?->isPaid());
		self::assertTrue($registry->get('openai')?->isPaid());
	}

	public function test_15_effective_settings_and_logs_never_contain_the_key(): void
	{
		$key = AiFakes::apiKey();
		putenv(AiConfig::OPENAI_API_KEY . '=' . $key);
		$config = new AiConfig();

		self::assertTrue($config->hasApiKey(AiConfig::OPENAI_API_KEY));
		self::assertStringNotContainsString($key, (string) json_encode($config->effective()));
		self::assertStringNotContainsString($key, (string) json_encode((new OpenAiProvider($config, new FakeHttpTransport()))->problems()));
		self::assertStringNotContainsString($key, print_r(new OpenAiProvider($config, new FakeHttpTransport()), true));

		$lines = [];
		$logger = new Logger(Logger::DEBUG, static function (string $line) use (&$lines): void {
			$lines[] = $line;
		});
		$logger->error('Provider said: invalid key ' . $key, ['authorization' => 'Bearer ' . $key, 'x-api-key' => $key, 'detail' => 'key=' . $key]);
		self::assertStringNotContainsString($key, implode("\n", $lines));
		self::assertStringNotContainsString(substr($key, 8), (new Redactor())->string('Authorization: Bearer ' . $key));
	}
}
