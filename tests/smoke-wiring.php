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

// Users are in here because the author listing is built from display names: a
// rename would otherwise sit in the cache until it expired, twelve hours later.
foreach (
	array(
		'save_post',
		'deleted_post',
		'trashed_post',
		'edited_term',
		'delete_term',
		'profile_update',
		'user_register',
		'deleted_user',
	) as $hook
) {
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

/* --- no public method escapes the suite --------------------------------- */

/*
 * A ratchet, not a coverage report. Four audits in a row found a bug in code
 * that nothing ran — the last one a fatal error in an upgrade routine no test
 * called. There is no coverage extension here, so this settles for something
 * cruder and sufficient: every public method must either be named in a test, or
 * be listed below with the path that reaches it.
 *
 * Adding a method now forces the same decision at the time it is written:
 * cover it, or say where it is covered. Being on the list is not an excuse to
 * skip testing — it records a method reached through one that *is* tested.
 */
$covered_indirectly = array(
	'SupportPanel::render_support' => 'SettingsPage::render(), asserted in smoke-admin.php',
	'Styles::request'              => 'ContentMarker::replace(), in smoke-marker.php',
	'Cache::html'                  => 'ContentMarker::replace(), in smoke-marker.php',
	'Node::has_children'           => 'Renderer::item(), throughout smoke-renderer.php',
	'Design::merge'                => 'Renderer::__construct(), throughout smoke-renderer.php',
	'Settings::can_edit_css'       => 'Settings::sanitize(), in smoke-settings.php',
);

$suite = '';
foreach ( glob( __DIR__ . '/smoke-*.php' ) as $file ) {
	$suite .= (string) file_get_contents( $file );
}

$unreached = array();

foreach ( array_merge( glob( dirname( __DIR__ ) . '/src/*.php' ), glob( dirname( __DIR__ ) . '/src/*/*.php' ) ) as $file ) {
	$class = basename( $file, '.php' );

	if ( ! preg_match_all( '/public (?:static )?function ([a-z_]+)\s*\(/', (string) file_get_contents( $file ), $found ) ) {
		continue;
	}

	foreach ( $found[1] as $method ) {
		if ( '__construct' === $method ) {
			continue;
		}
		if ( isset( $covered_indirectly[ $class . '::' . $method ] ) ) {
			continue;
		}
		if ( preg_match( '/\b' . preg_quote( $method, '/' ) . '\s*\(/', $suite ) ) {
			continue;
		}

		$unreached[] = $class . '::' . $method . '()';
	}
}

check(
	array() === $unreached,
	'every public method is exercised, or recorded as reached through one that is',
	implode( ', ', $unreached )
);

// A stale entry is its own problem: it claims cover for something that is gone.
$stale = array();
foreach ( array_keys( $covered_indirectly ) as $entry ) {
	list( $class, $method ) = explode( '::', $entry );
	$found = glob( dirname( __DIR__ ) . '/src/*/' . $class . '.php' ) + glob( dirname( __DIR__ ) . '/src/' . $class . '.php' );

	if ( array() === $found || false === strpos( (string) file_get_contents( reset( $found ) ), 'function ' . $method . '(' ) ) {
		$stale[] = $entry;
	}
}

check( array() === $stale, 'and the list claims nothing that no longer exists', implode( ', ', $stale ) );

/* --- every class reference resolves ------------------------------------- */

/*
 * A missing `use` is invisible to `php -l` and to every test that does not
 * happen to run the offending line — PHP only resolves the name when it gets
 * there, and then it is a fatal error. One shipped in Plugin::maybe_upgrade(),
 * which nothing called, and it would have taken down the admin screen of every
 * existing install.
 *
 * Walked with the tokenizer rather than a regex so that class names inside
 * docblocks and translated strings cannot be mistaken for code.
 */
function class_references( $path ) {
	$tokens    = token_get_all( (string) file_get_contents( $path ) );
	$count     = count( $tokens );
	$namespace = '';
	$imports   = array();
	$refs      = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
			for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
					$namespace = $tokens[ $j ][1];
				}
			}
			continue;
		}

		if ( is_array( $token ) && T_USE === $token[0] ) {
			for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
					$parts               = explode( '\\', $tokens[ $j ][1] );
					$imports[ end( $parts ) ] = true;
				}
			}
			continue;
		}

		// `Foo::` and `new Foo`.
		if ( is_array( $token ) && T_STRING === $token[0] ) {
			$next = isset( $tokens[ $i + 1 ] ) ? $tokens[ $i + 1 ] : null;
			$prev = isset( $tokens[ $i - 2 ] ) ? $tokens[ $i - 2 ] : null;

			$is_static = is_array( $next ) && T_DOUBLE_COLON === $next[0];
			$is_new    = is_array( $prev ) && T_NEW === $prev[0];

			if ( $is_static || $is_new ) {
				$refs[ $token[1] ] = true;
			}
		}
	}

	return array( $namespace, $imports, array_keys( $refs ) );
}

$known = array( 'self', 'static', 'parent', 'Settings', 'Design', 'Hooks', 'Node', 'Cache', 'Renderer', 'TreeBuilder' );
$unresolved = array();

foreach ( array_merge( glob( dirname( __DIR__ ) . '/src/*.php' ), glob( dirname( __DIR__ ) . '/src/*/*.php' ) ) as $file ) {
	list( $namespace, $imports, $refs ) = class_references( $file );

	foreach ( $refs as $class ) {
		if ( in_array( $class, array( 'self', 'static', 'parent' ), true ) || isset( $imports[ $class ] ) ) {
			continue;
		}

		// Resolvable in its own namespace?
		$sibling = dirname( __DIR__ ) . '/src/' . str_replace(
			'\\',
			'/',
			ltrim( substr( $namespace . '\\' . $class, strlen( 'RaplsSitemap' ) ), '\\' )
		) . '.php';

		if ( file_exists( $sibling ) ) {
			continue;
		}

		// A WordPress or SPL class, which is global and needs no import.
		if ( class_exists( $class ) || 0 === strpos( $class, 'WP_' ) || 0 === strpos( $class, 'Recursive' ) ) {
			continue;
		}

		$unresolved[] = basename( $file ) . ': ' . $class;
	}
}

check( array() === $unresolved, 'every class a source file names is imported or local to it', implode( ' | ', $unresolved ) );

/* --- the upgrade routine actually changes the autoload flag -------------- */

// The settings are read only where a sitemap renders, so autoloading them
// spends bytes on every other request. Installs created before that decision
// have to be migrated — and the obvious way to do it does not work, because
// update_option() returns before touching autoload when the value is unchanged.
$GLOBALS['rapls_options']  = array( Settings::OPTION => Settings::defaults() );
$GLOBALS['rapls_autoload'] = array( Settings::OPTION => true );

$plugin->maybe_upgrade();

check( false === $GLOBALS['rapls_autoload'][ Settings::OPTION ], 'an existing install stops autoloading its settings' );
check( Settings::defaults() === $GLOBALS['rapls_options'][ Settings::OPTION ], 'and its settings survive the migration unchanged' );
check( RAPLS_SITEMAP_VERSION === get_option( Plugin::VERSION_OPTION ), 'the version is recorded so it runs once' );

// Second run must be a no-op rather than repeating the work.
$GLOBALS['rapls_autoload'][ Settings::OPTION ] = true;
$plugin->maybe_upgrade();
check( true === $GLOBALS['rapls_autoload'][ Settings::OPTION ], 'a second run does nothing, because the version already matches' );

// A fresh install has no settings row yet; the routine must not create one.
$GLOBALS['rapls_options'] = array();
$plugin->maybe_upgrade();
check( ! isset( $GLOBALS['rapls_options'][ Settings::OPTION ] ), 'and it invents no settings where there were none' );

/* --- every option the plugin writes is removed on uninstall ------------- */

$written = array();
foreach ( glob( dirname( __DIR__ ) . '/src/*/*.php' ) as $file ) {
	if ( preg_match_all( "/'(rapls_sitemap_[a-z_]+)'/", (string) file_get_contents( $file ), $found ) ) {
		$written = array_merge( $written, $found[1] );
	}
}
if ( preg_match_all( "/'(rapls_sitemap_[a-z_]+)'/", (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' ), $found ) ) {
	$written = array_merge( $written, $found[1] );
}

$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
$leftover  = array();

foreach ( array_unique( $written ) as $option ) {
	// Not everything shaped like an option name is one. Transient keys expire
	// on their own, and the two `admin-post.php` actions are hook names that
	// nothing ever writes to the database.
	if ( in_array( $option, array( 'rapls_sitemap_html_', 'rapls_sitemap_reset', 'rapls_sitemap_import_ps' ), true ) ) {
		continue;
	}
	if ( false === strpos( $uninstall, "'" . $option . "'" ) ) {
		$leftover[] = $option;
	}
}

check( array() === $leftover, 'uninstall removes every option the plugin creates', implode( ', ', $leftover ) );

summary();
