<?php

declare(strict_types=1);

namespace OsfSeo\PageIntelligence\Robots;

/**
 * Parser robots.txt według RFC 9309: grupy `user-agent` (dopasowanie tokenu bez rozróżniania wielkości liter; grupy tego samego tokenu
 * łączone), reguły `allow` / `disallow` ze wzorcami `*` i `$`, najdłuższe dopasowanie wygrywa, przy remisie — `allow`. Grupa naszego
 * tokenu (`whack-a-mole`) ma pierwszeństwo przed `*`; brak pasującej grupy = wszystko dozwolone. `crawl-delay` (niestandardowe) — odczytane.
 * Przetwarzane najwyżej MAX_BYTES (RFC: co najmniej 500 KiB) i MAX_RULES reguł.
 */
final class RobotsTxt
{
	public const MAX_BYTES = 512000;

	public const MAX_RULES = 2000;

	/**
	 * @param list<array{0: bool, 1: string}> $rules [allow, wzorzec]
	 */
	private function __construct(
		public readonly array $rules,
		public readonly ?float $crawlDelay,
		public readonly string $group,
	) {
	}

	public static function parse(string $content, string $token): self
	{
		$content = substr($content, 0, self::MAX_BYTES);
		$token = strtolower($token);
		$groups = [];
		$current = [];
		$collectingAgents = false;
		$count = 0;

		foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
			$line = trim((string) preg_replace('/#.*$/', '', $line));

			if ($line === '' || ! str_contains($line, ':')) {
				continue;
			}

			[$field, $value] = array_map('trim', explode(':', $line, 2));
			$field = strtolower($field);

			if ($field === 'user-agent') {
				if (! $collectingAgents) {
					$current = [];
				}

				$collectingAgents = true;
				$agent = strtolower($value);
				$current[] = $agent;
				$groups[$agent] ??= ['rules' => [], 'delay' => null];

				continue;
			}

			$collectingAgents = false;

			if ($current === [] || ++$count > self::MAX_RULES) {
				continue;
			}

			foreach ($current as $agent) {
				if ($field === 'allow' || $field === 'disallow') {
					if ($value !== '') {
						$groups[$agent]['rules'][] = [$field === 'allow', $value];
					}
				} elseif ($field === 'crawl-delay' && is_numeric($value)) {
					$groups[$agent]['delay'] = max(0.0, (float) $value);
				}
			}
		}

		foreach ([$token, '*'] as $agent) {
			if (isset($groups[$agent])) {
				return new self($groups[$agent]['rules'], $groups[$agent]['delay'], $agent === '*' ? '*' : 'specific');
			}
		}

		return new self([], null, 'none');
	}

	/** Reguły pozwalają pobrać ścieżkę (ścieżka z zapytaniem, np. `/oferta?x=1`). */
	public function allows(string $path): bool
	{
		$path = $path === '' ? '/' : $path;
		$best = null;

		foreach ($this->rules as [$allow, $pattern]) {
			if (! self::matches($pattern, $path)) {
				continue;
			}

			$length = strlen($pattern);

			if ($best === null || $length > $best[0] || ($length === $best[0] && $allow)) {
				$best = [$length, $allow];
			}
		}

		return $best === null || $best[1];
	}

	private static function matches(string $pattern, string $path): bool
	{
		$anchored = str_ends_with($pattern, '$');
		$pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
		$regex = '#^' . implode('.*', array_map(static fn (string $part): string => preg_quote(self::decode($part), '#'), explode('*', $pattern))) . ($anchored ? '$' : '') . '#';

		return preg_match($regex, self::decode($path)) === 1;
	}

	/** Porównanie po zdekodowaniu znaków niezarezerwowanych (`%7E` = `~`), bez zmiany zarezerwowanych. */
	private static function decode(string $value): string
	{
		return (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $match): string {
			$char = chr((int) hexdec($match[1]));

			return preg_match('/[A-Za-z0-9\-._~]/', $char) === 1 ? $char : '%' . strtoupper($match[1]);
		}, $value);
	}
}
