<?php

declare(strict_types=1);

namespace OsfSeo\Branding;

/**
 * Obraz z biblioteki mediów jako logo aplikacji (adresy z WordPressa, wymiary rozmiaru `medium`, tekst alternatywny).
 */
final class BrandLogo
{
	public function __construct(
		public readonly int $id,
		public readonly string $url,
		public readonly int $width,
		public readonly int $height,
		public readonly ?string $srcset,
		public readonly string $alt,
		public readonly string $thumbnailUrl,
		public readonly string $title,
	) {
	}
}
