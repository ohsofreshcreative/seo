<?php

namespace App\Http\Middleware\Panel;

/**
 * Middleware stosowane do wszystkich tras panelu (functions.php → withRouting).
 */
final class PanelMiddleware
{
	public const GLOBAL = [
		PanelHeaders::class,
		UnslashInput::class,
		RequirePlugin::class,
	];
}
