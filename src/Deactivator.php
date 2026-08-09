<?php
/**
 * Deactivation routine.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap;

use RaplsSitemap\Sitemap\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin deactivation. Settings survive — only derived data goes.
 */
final class Deactivator {

	/**
	 * Drop cached renderings by rotating the salt.
	 */
	public static function deactivate(): void {
		update_option( Cache::SALT_OPTION, (string) wp_generate_uuid4(), false );
	}
}
