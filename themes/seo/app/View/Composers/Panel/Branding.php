<?php

namespace App\View\Composers\Panel;

use OsfSeo\Branding\BrandingService;
use Roots\Acorn\View\Composer;
use Throwable;

/**
 * Logo aplikacji dla układów panelu (sidebar i ekrany gościa: logowanie, błędy). Bez pluginu albo bez logo — null, układ pokazuje
 * napis „Whack-a-mole”.
 */
class Branding extends Composer
{
	protected static $views = [
		'panel.layouts.app',
		'panel.layouts.guest',
	];

	public function with(): array
	{
		return ['brandLogo' => self::logo()];
	}

	private static function logo(): ?object
	{
		if (! function_exists('osf_seo') || ! class_exists(BrandingService::class)) {
			return null;
		}

		try {
			return osf_seo()->get(BrandingService::class)->logo();
		} catch (Throwable) {
			return null;
		}
	}
}
