<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class App extends Composer
{
	/**
	 * List of views served by this composer.
	 *
	 * @var array
	 */
	protected static $views = [
		'*', // działa globalnie
	];

	/**
	 * Dane dostępne we wszystkich widokach Blade.
	 */
	public function with(): array
	{
		return [
			'siteName' => $this->siteName(),
			'logo' => $this->option('logo'),
			'logo_footer' => $this->option('logo_footer'),
			'footer_contact' => $this->option('footer_contact'),
		];
	}

	/**
	 * Opcja ACF albo null — composer działa dla wszystkich widoków (także panelu OSF SEO),
	 * a instalacja aplikacji nie wymaga ACF.
	 */
	private function option(string $name): mixed
	{
		return function_exists('get_field') ? get_field($name, 'option') : null;
	}

	/**
	 * Zwraca nazwę strony.
	 */
	public function siteName(): string
	{
		return get_bloginfo('name', 'display');
	}
}
