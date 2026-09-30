<?php

declare(strict_types=1);

namespace OsfSeo\Support;

use Closure;
use InvalidArgumentException;
use Stringable;

/**
 * Logger pluginu. Każdy wpis (komunikat i kontekst) przechodzi przez Redactor, więc tokeny,
 * sekrety i hasła nie trafiają do logów. Domyślnie zapisuje przez error_log()
 * (przy WP_DEBUG_LOG — do wp-content/debug.log).
 *
 * Poziom: stała lub zmienna środowiskowa OSF_SEO_LOG_LEVEL (debug|info|warning|error);
 * domyślnie warning, a przy WP_DEBUG — debug.
 */
final class Logger
{
	public const DEBUG = 'debug';

	public const INFO = 'info';

	public const WARNING = 'warning';

	public const ERROR = 'error';

	private const PRIORITIES = [
		self::DEBUG => 100,
		self::INFO => 200,
		self::WARNING => 300,
		self::ERROR => 400,
	];

	/** @var Closure(string): void */
	private Closure $writer;

	/**
	 * @param (callable(string): void)|null $writer
	 */
	public function __construct(
		private readonly string $level = self::WARNING,
		?callable $writer = null,
		private readonly Redactor $redactor = new Redactor(),
	) {
		self::assertLevel($level);

		$this->writer = $writer !== null
			? Closure::fromCallable($writer)
			: static function (string $line): void {
				error_log($line);
			};
	}

	public static function fromConfig(Config $config): self
	{
		$default = defined('WP_DEBUG') && WP_DEBUG ? self::DEBUG : self::WARNING;
		$level = strtolower((string) $config->get('OSF_SEO_LOG_LEVEL', $default));

		return new self(isset(self::PRIORITIES[$level]) ? $level : $default);
	}

	public function level(): string
	{
		return $this->level;
	}

	/** @param array<string, mixed> $context */
	public function debug(string $message, array $context = []): void
	{
		$this->log(self::DEBUG, $message, $context);
	}

	/** @param array<string, mixed> $context */
	public function info(string $message, array $context = []): void
	{
		$this->log(self::INFO, $message, $context);
	}

	/** @param array<string, mixed> $context */
	public function warning(string $message, array $context = []): void
	{
		$this->log(self::WARNING, $message, $context);
	}

	/** @param array<string, mixed> $context */
	public function error(string $message, array $context = []): void
	{
		$this->log(self::ERROR, $message, $context);
	}

	/**
	 * Placeholdery `{klucz}` w komunikacie są zastępowane wartościami z kontekstu (już zamaskowanymi).
	 *
	 * @param array<string, mixed> $context
	 */
	public function log(string $level, string $message, array $context = []): void
	{
		self::assertLevel($level);

		if (self::PRIORITIES[$level] < self::PRIORITIES[$this->level]) {
			return;
		}

		$context = $this->redactor->context($context);
		$line = sprintf('[osf-seo] %s: %s', strtoupper($level), $this->redactor->string($this->interpolate($message, $context)));

		if ($context !== []) {
			$line .= ' ' . json_encode(
				$context,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
			);
		}

		($this->writer)($line);
	}

	/**
	 * @param array<array-key, mixed> $context
	 */
	private function interpolate(string $message, array $context): string
	{
		$replacements = [];

		foreach ($context as $key => $value) {
			if (is_bool($value)) {
				$replacements['{' . $key . '}'] = $value ? 'true' : 'false';
			} elseif ($value === null || is_scalar($value) || $value instanceof Stringable) {
				$replacements['{' . $key . '}'] = (string) $value;
			}
		}

		return strtr($message, $replacements);
	}

	private static function assertLevel(string $level): void
	{
		if (! isset(self::PRIORITIES[$level])) {
			throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $level));
		}
	}
}
