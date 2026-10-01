<?php

declare(strict_types=1);

namespace OsfSeo\Gsc;

use InvalidArgumentException;
use OsfSeo\Projects\DomainNormalizer;

/**
 * Property Search Console z sites.list. `siteUrl` to dokładny identyfikator Google
 * (`sc-domain:example.pl` albo `https://www.example.pl/`) — przechowywany i wysyłany bez zmian.
 */
final class GscProperty
{
	/** Limit kolumny `projects.gsc_property`. */
	public const MAX_LENGTH = 255;

	public function __construct(
		public readonly string $siteUrl,
		/** Surowa wartość `permissionLevel` (także nieznana enumowi). */
		public readonly string $permissionLevel,
	) {
	}

	/**
	 * @param array<string, mixed> $entry element `siteEntry`
	 * @throws InvalidArgumentException gdy wpis nie pasuje do kontraktu API
	 */
	public static function fromApi(array $entry): self
	{
		$siteUrl = $entry['siteUrl'] ?? null;
		$permission = $entry['permissionLevel'] ?? null;

		if (! is_string($siteUrl) || ! self::isValidSiteUrl($siteUrl) || ! is_string($permission) || preg_match('/^[A-Za-z]{1,32}$/', $permission) !== 1) {
			throw new InvalidArgumentException('Invalid siteEntry.');
		}

		return new self($siteUrl, $permission);
	}

	public static function isValidSiteUrl(string $siteUrl): bool
	{
		if ($siteUrl === '' || strlen($siteUrl) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $siteUrl) === 1) {
			return false;
		}

		if (str_starts_with($siteUrl, 'sc-domain:')) {
			return preg_match('/^sc-domain:[a-z0-9.-]+$/i', $siteUrl) === 1;
		}

		$parts = parse_url($siteUrl);

		return is_array($parts)
			&& in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			&& ($parts['host'] ?? '') !== ''
			&& str_ends_with($siteUrl, '/');
	}

	public function isDomainProperty(): bool
	{
		return str_starts_with($this->siteUrl, 'sc-domain:');
	}

	public function typeLabel(): string
	{
		return $this->isDomainProperty() ? 'Domena' : 'Prefiks URL';
	}

	public function permission(): ?PermissionLevel
	{
		return PermissionLevel::tryFrom($this->permissionLevel);
	}

	/** Czy konto może pobierać dane Search Analytics tej property. */
	public function canQuerySearchAnalytics(): bool
	{
		return $this->permission()?->allowsSearchAnalytics() ?? false;
	}

	/** Host property bez `www.` (do dopasowania z domeną projektu); null, gdy nie da się go ustalić. */
	public function host(): ?string
	{
		try {
			return DomainNormalizer::normalize($this->siteUrl);
		} catch (InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * Dopasowanie do domeny projektu (znormalizowanej, np. `example.pl`): 0 = brak.
	 * Preferujemy property domenową (obejmuje wszystkie protokoły i subdomeny), potem prefiks https.
	 * Prefiks ze ścieżką (np. `https://example.pl/blog/`) obejmuje tylko część serwisu — niższa ocena.
	 */
	public function matchScore(string $projectDomain): int
	{
		if ($this->host() !== strtolower($projectDomain)) {
			return 0;
		}

		if ($this->isDomainProperty()) {
			return 100;
		}

		$path = (string) parse_url($this->siteUrl, PHP_URL_PATH);
		$score = str_starts_with(strtolower($this->siteUrl), 'https://') ? 90 : 80;

		return $path === '/' ? $score : $score - 40;
	}
}
