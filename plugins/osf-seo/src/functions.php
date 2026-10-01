<?php

declare(strict_types=1);

use OsfSeo\Plugin;

/**
 * Punkt dostępu do pluginu i jego usług, także z motywu.
 * W motywie zawsze sprawdzaj wcześniej `function_exists('osf_seo')`.
 */
function osf_seo(): Plugin
{
	return Plugin::instance();
}
