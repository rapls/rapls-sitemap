<?php
/**
 * Activation routine.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap;

use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation. This plugin owns no tables — it only seeds
 * defaults and the cache salt.
 */
final class Activator {

	/**
	 * Seed options.
	 */
	public static function activate(): void {
		// Not autoloaded. The settings are read only where a sitemap actually
		// renders — one page on most sites — while autoload would load them on
		// every request, admin screens and REST calls included. They also hold
		// the Additional CSS, which is the one field here with no natural size.
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		// A salt must exist before the first render, or every cache key would
		// hash the same empty string across reinstalls.
		update_option( Cache::SALT_OPTION, (string) wp_generate_uuid4(), false );

		if ( false === get_option( 'rapls_sitemap_activated_at' ) ) {
			add_option( 'rapls_sitemap_activated_at', gmdate( 'Y-m-d H:i:s' ), '', false );
		}
	}
}
