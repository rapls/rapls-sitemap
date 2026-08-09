<?php
/**
 * Boots the plugin exactly as `plugins_loaded` does, to catch constructor type
 * mismatches and missing `use` imports that linting cannot see.
 *
 *   php tests/smoke-wiring.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

function is_admin() {
	return true;
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Admin\SettingsPage;
use RaplsSitemap\Frontend\Shortcode;
use RaplsSitemap\Plugin;
use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

$plugin = Plugin::instance();
$plugin->boot();

check( true, 'the full object graph boots' );
check( $plugin->cache() instanceof Cache, 'the render cache is exposed after boot' );

/* --- the entry points actually registered ------------------------------- */

$hooks = $GLOBALS['rapls_hooks'];

check( isset( $hooks[ 'shortcode:' . Shortcode::TAG ] ), 'the shortcode is registered' );
check( isset( $hooks['the_content'] ), 'the legacy marker filter is registered' );
// The shim must wait for `init` so it can see whether WP Sitemap Page has
// already claimed the tag; asserting on the callback rather than a count keeps
// this from breaking every time another init hook is added.
$init_targets = array();
foreach ( $hooks['init'] ?? array() as $callback ) {
	$init_targets[] = is_array( $callback ) ? get_class( $callback[0] ) . '::' . $callback[1] : (string) $callback;
}

check(
	in_array( 'RaplsSitemap\Frontend\LegacyShortcode::maybe_register', $init_targets, true ),
	'the wp_sitemap_page shim defers its registration to init',
	implode( ', ', $init_targets )
);
check( isset( $hooks['init'] ), 'block registration is deferred to init' );
check( isset( $hooks['wp_enqueue_scripts'] ), 'the stylesheet is registered on the front end' );
check( isset( $hooks['admin_menu'] ), 'the settings page is added in the admin' );
check( isset( $hooks[ 'admin_post_' . SettingsPage::RESET_ACTION ] ), 'the reset button has a handler behind admin-post.php' );
check( isset( $hooks['plugin_row_meta'] ), 'the support link is added to the plugin row, not to a notice' );
check( isset( $hooks['admin_enqueue_scripts'] ), 'the settings screen stylesheet is enqueued through the proper hook' );

// Guideline 10: nothing this plugin adds may reach the front end uninvited.
foreach ( array( 'wp_footer', 'wp_head', 'admin_notices', 'all_admin_notices', 'wp_dashboard_setup' ) as $forbidden ) {
	check( ! isset( $hooks[ $forbidden ] ), sprintf( 'nothing is hooked to %s', $forbidden ) );
}

/* --- cache invalidation is wired to content changes --------------------- */

foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'edited_term', 'delete_term' ) as $hook ) {
	check( isset( $hooks[ $hook ] ), sprintf( 'the cache flushes on %s', $hook ) );
}

check(
	isset( $hooks[ 'update_option_' . Settings::OPTION ] ),
	'the cache flushes when the settings change'
);

/* --- boot() is idempotent ----------------------------------------------- */

$before = count( $GLOBALS['rapls_hooks']['init'] );
$plugin->boot();
check( $before === count( $GLOBALS['rapls_hooks']['init'] ), 'a second boot() registers nothing twice' );

/* --- the salt actually changes on flush --------------------------------- */

$cache = $plugin->cache();
$cache->flush();
$first = get_option( Cache::SALT_OPTION );
$cache->flush();
check( $first !== get_option( Cache::SALT_OPTION ), 'flushing rotates the cache salt' );

summary();
