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
		$fresh = false === get_option( Settings::OPTION );

		if ( $fresh ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		// A salt must exist before the first render, or every cache key would
		// hash the same empty string across reinstalls.
		update_option( Cache::SALT_OPTION, (string) wp_generate_uuid4(), false );

		// Stamped ONLY on a fresh install, so `Plugin::maybe_upgrade()` has
		// nothing to do on a site that has nothing to migrate — without it the
		// migration runs on the first admin page load of every new site,
		// deleting and re-adding the option it just created.
		//
		// Never on an existing one. An update installed the ordinary way
		// deactivates and reactivates the plugin, and stamping the new version
		// here would tell maybe_upgrade() its work was already done — skipping
		// the migration that very update shipped.
		if ( $fresh ) {
			update_option( Plugin::VERSION_OPTION, RAPLS_SITEMAP_VERSION, false );
		}
	}
}
