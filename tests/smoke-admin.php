<?php
/**
 * The admin surface and the activation lifecycle.
 *
 * These paths had no test at all, and that is the whole reason this file
 * exists. A class named without a `use`, a renamed method, a typo in a helper
 * — none of it is visible to `php -l`, and PHP only complains when the line
 * runs. Four audits in a row turned up a bug in code nothing executed.
 *
 * So the assertions here are deliberately shallow: what matters is that every
 * one of these methods *runs to completion*. Where an output is easy to check,
 * it is checked, but the coverage is the point.
 *
 *   php tests/smoke-admin.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

define( 'RAPLS_SITEMAP_URL', 'https://example.test/wp-content/plugins/rapls-sitemap/' );
define( 'RAPLS_SITEMAP_DIR', dirname( __DIR__ ) . '/' );
define( 'RAPLS_SITEMAP_BASENAME', 'rapls-sitemap/rapls-sitemap.php' );

/* --- WordPress admin stubs ---------------------------------------------- */

/** Raised in place of the exit() that follows a redirect. */
class Rapls_Redirect extends Exception {}

/** Raised in place of wp_die(). */
class Rapls_Died extends Exception {}

function post_type_exists( $type ) {
	return in_array( $type, array( 'page', 'post' ), true );
}

function taxonomy_exists( $taxonomy ) {
	return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
}

function fake_object( $name, $singular, $plural ) {
	$object                        = new stdClass();
	$object->name                  = $name;
	$object->labels                = new stdClass();
	$object->labels->name          = $plural;
	$object->labels->singular_name = $singular;
	return $object;
}

function get_post_types( $args = array(), $output = 'names' ) {
	return array(
		'post'       => fake_object( 'post', 'Post', 'Posts' ),
		'page'       => fake_object( 'page', 'Page', 'Pages' ),
		'attachment' => fake_object( 'attachment', 'Media', 'Media' ),
	);
}

function get_taxonomies( $args = array(), $output = 'names' ) {
	return array(
		'category' => fake_object( 'category', 'Category', 'Categories' ),
		'post_tag' => fake_object( 'post_tag', 'Tag', 'Tags' ),
	);
}

function add_options_page( $page, $menu, $cap, $slug, $callback ) {
	$GLOBALS['rapls_admin_page'] = $slug;
	return 'settings_page_' . $slug;
}

function register_setting( $group, $option, $args = array() ) {
	$GLOBALS['rapls_registered_setting'] = array( $group, $option, $args );
}

function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . $group . '" />';
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = null ) {
	echo '<button class="' . $type . '">' . $text . '</button>';
}

function checked( $checked, $current = true, $echo = true ) {
	$out = (string) $checked === (string) $current ? ' checked' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function selected( $selected, $current = true, $echo = true ) {
	$out = (string) $selected === (string) $current ? ' selected' : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( $args, $url = '' ) {
	return $url . '?' . http_build_query( $args );
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />';
}

function check_admin_referer( $action ) {
	$GLOBALS['rapls_nonce_checked'] = $action;
	return true;
}

function wp_safe_redirect( $location ) {
	throw new Rapls_Redirect( $location );
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Rapls_Died( (string) $message );
}

function wp_register_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['rapls_registered_styles'][] = $handle;
}

function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) {
	$GLOBALS['rapls_registered_scripts'][ $handle ] = $deps;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['rapls_enqueued'][] = $handle;
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['rapls_inline_script'] = $data;
	return true;
}

function wp_set_script_translations( $handle, $domain, $path = '' ) {
	$GLOBALS['rapls_script_translations'] = array( $handle, $domain, $path );
	return true;
}

function register_block_type_from_metadata( $path, $args = array() ) {
	$GLOBALS['rapls_block_metadata_path'] = $path;
	$GLOBALS['rapls_block_args']          = $args;
	return true;
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
	$GLOBALS['rapls_textdomain'] = array( $domain, $path );
	return true;
}

function is_admin() {
	return true;
}

function get_bloginfo( $key ) {
	return 'Example Site';
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Activator;
use RaplsSitemap\Admin\SettingsPage;
use RaplsSitemap\Admin\SupportPanel;
use RaplsSitemap\Deactivator;
use RaplsSitemap\Frontend\Block;
use RaplsSitemap\Frontend\Styles;
use RaplsSitemap\Plugin;
use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

/* --- activation and deactivation ---------------------------------------- */

Activator::activate();

check( is_array( get_option( Settings::OPTION ) ), 'activation seeds the settings' );
check( false === $GLOBALS['rapls_autoload'][ Settings::OPTION ], 'and does not autoload them' );
check( '' !== (string) get_option( Cache::SALT_OPTION ), 'a cache salt exists before the first render' );

$first = get_option( Cache::SALT_OPTION );
Deactivator::deactivate();
check( $first !== get_option( Cache::SALT_OPTION ), 'deactivation rotates the salt so a reinstall serves nothing stale' );

// Re-activating must not overwrite the settings somebody already saved.
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'depth' => 7 ) ) );
Activator::activate();
check( 7 === Settings::get()['depth'], 're-activating leaves existing settings alone' );

/* --- the settings screen renders ---------------------------------------- */

$page = new SettingsPage();
$page->register();
$page->add_page();
$page->register_setting();

check( 'rapls-sitemap' === $GLOBALS['rapls_admin_page'], 'the options page is added under its own slug' );
check( Settings::OPTION === $GLOBALS['rapls_registered_setting'][1], 'the option is registered with the Settings API' );
check(
	array( Settings::class, 'sanitize' ) === $GLOBALS['rapls_registered_setting'][2]['sanitize_callback'],
	'and options.php will run it through Settings::sanitize'
);

$page->enqueue_assets( 'settings_page_rapls-sitemap' );
check( in_array( 'rapls-sitemap-admin', $GLOBALS['rapls_enqueued'], true ), 'the screen loads its script' );
check( false !== strpos( (string) $GLOBALS['rapls_inline_script'], 'raplsSitemapAdmin' ), 'and the emoji palette travels with it' );

$GLOBALS['rapls_enqueued'] = array();
$page->enqueue_assets( 'edit.php' );
check( array() === $GLOBALS['rapls_enqueued'], 'and nothing at all on any other screen' );

// The whole form: every field helper, both help panels, the reset form and the
// support panel run here. This is the assertion that would have caught a class
// referenced without a `use`.
ob_start();
$page->render();
$html = (string) ob_get_clean();

check( '' !== $html, 'the settings screen renders without a fatal error' );
check( false !== strpos( $html, 'option_page' ), 'the Settings API nonce fields are present' );
check( false !== strpos( $html, 'rapls_sitemap_settings[design]' ), 'a design control is on the page' );
check( false !== strpos( $html, 'rapls_sitemap_settings[style][font_size_value]' ), 'so is a split length control' );
check( false !== strpos( $html, 'rapls_sitemap_settings[custom_css]' ), 'and the CSS field, for a user who may edit it' );
check( false !== strpos( $html, 'rapls-sitemap__item--archive-month' ), 'the class reference lists every node kind' );
check( false !== strpos( $html, 'buymeacoffee.com/rapls' ), 'the support panel is at the foot of the screen' );

// The section boxes carry the slugs TreeBuilder::section() resolves, and each
// slug is offered once. A box labelled with a taxonomy that turns out to list a
// post type is worse than one option fewer, so the screen follows that method's
// order — alias, then post type, then taxonomy — rather than overwriting as it
// goes.
check(
	1 === substr_count( $html, 'name="rapls_sitemap_settings[sections][]" value="category"' ),
	'each section slug is offered exactly once'
);
check( false !== strpos( $html, 'name="rapls_sitemap_settings[sections][]" value="author"' ), 'the author listing is offered as a section' );
check( false !== strpos( $html, 'name="rapls_sitemap_settings[sections_order][archive]"' ), 'and each box has its position field' );

// Sections nest, so counting a closing pair proves nothing. What matters is
// that the page's divs balance overall — an unclosed section would swallow
// everything after it into a collapsed panel.
$sections = substr_count( $html, '<div class="rapls-section' );
$opened   = preg_match_all( '/<div\b/', $html );
$closed   = substr_count( $html, '</div>' );

check( $sections >= 3, 'the collapsible sections are on the page', (string) $sections );
check( $opened === $closed, 'and every div on the page is closed', "opened {$opened}, closed {$closed}" );

// Same for the two forms: the reset form must not end up inside the settings
// form, which HTML forbids and browsers resolve by dropping one of them.
check( 2 === substr_count( $html, '<form ' ), 'the settings form and the reset form are both present' );
check( 2 === substr_count( $html, '</form>' ), 'and both are closed' );

// Someone without the capability sees an explanation instead of a textarea.
$GLOBALS['rapls_caps']['unfiltered_html'] = false;
ob_start();
$page->render();
$restricted = (string) ob_get_clean();
$GLOBALS['rapls_caps']['unfiltered_html'] = true;

check( false === strpos( $restricted, 'rapls_sitemap_settings[custom_css]' ), 'and no CSS field for a user who may not' );
check( false !== strpos( $restricted, 'unfiltered_html' ), 'with the reason given rather than an empty space' );

/* --- the reset button --------------------------------------------------- */

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'depth' => 9 ) ) );

try {
	$page->handle_reset();
	check( false, 'handle_reset redirects when it is done' );
} catch ( Rapls_Redirect $e ) {
	check( false !== strpos( $e->getMessage(), 'rapls-reset' ), 'handle_reset redirects back with a notice flag' );
}

check( 'rapls_sitemap_reset' === $GLOBALS['rapls_nonce_checked'], 'and checks the nonce before doing anything' );
check( Settings::defaults() === get_option( Settings::OPTION ), 'the settings are back to their defaults' );

// Capability first, so a request that gets past the nonce still cannot reset.
$GLOBALS['rapls_caps']['manage_options'] = false;
try {
	$page->handle_reset();
	check( false, 'handle_reset refuses a user without the capability' );
} catch ( Rapls_Died $e ) {
	check( true, 'handle_reset refuses a user without the capability' );
}
$GLOBALS['rapls_caps']['manage_options'] = true;

/* --- the support panel -------------------------------------------------- */

$support = new SupportPanel();

$links = $support->row_meta( array( 'View details' ), RAPLS_SITEMAP_BASENAME );
check( 2 === count( $links ), 'the plugin row gains one link' );
check( false !== strpos( $links[1], 'buymeacoffee.com/rapls' ), 'pointing at the support page' );
check( false !== strpos( $links[1], 'rel="noopener noreferrer"' ), 'and opening safely' );

check(
	array( 'View details' ) === $support->row_meta( array( 'View details' ), 'other-plugin/other.php' ),
	'another plugin\'s row is left alone'
);

/* --- block and style registration --------------------------------------- */

( new Block( new Cache() ) )->register_block();

check( RAPLS_SITEMAP_DIR . 'blocks/sitemap' === $GLOBALS['rapls_block_metadata_path'], 'the block registers from its block.json' );
check( isset( $GLOBALS['rapls_block_args']['render_callback'] ), 'with a server-side renderer' );
check( in_array( 'wp-i18n', $GLOBALS['rapls_registered_scripts']['rapls-sitemap-block'], true ), 'the editor script declares its dependencies' );
check( 'rapls-sitemap' === $GLOBALS['rapls_script_translations'][1], 'and is pointed at the translation payload' );

( new Styles() )->register_assets();
check( in_array( Styles::HANDLE, $GLOBALS['rapls_registered_styles'], true ), 'the front-end stylesheet is registered' );
check( in_array( Styles::INLINE_HANDLE, $GLOBALS['rapls_registered_styles'], true ), 'and so is the sourceless handle the author CSS attaches to' );

/* --- uninstall removes everything, on one site and on a network --------- */

// Never executed before this: uninstall.php is a file WordPress includes on
// its own, so nothing here would ever have caught a typo in it.
$deleted = array();
$network = array();

$run_uninstall = static function ( $multisite ) use ( &$deleted, &$network ) {
	$command = sprintf(
		'php -r %s',
		escapeshellarg(
			'define("WP_UNINSTALL_PLUGIN", true);' .
			'$GLOBALS["log"] = [];' .
			'$GLOBALS["site"] = 0;' .
			'function is_multisite(){ return ' . ( $multisite ? 'true' : 'false' ) . '; }' .
			'function get_sites($a){ $all = [1,2,3]; return array_slice($all, $a["offset"], $a["number"]); }' .
			'function switch_to_blog($id){ $GLOBALS["site"] = $id; }' .
			'function restore_current_blog(){ $GLOBALS["site"] = 0; }' .
			'function delete_option($n){ $GLOBALS["log"][] = $GLOBALS["site"] . ":" . $n; return true; }' .
			'require "' . dirname( __DIR__ ) . '/uninstall.php";' .
			'echo implode(",", $GLOBALS["log"]);'
		)
	);

	return (string) shell_exec( $command );
};

$single = $run_uninstall( false );
foreach ( array( 'rapls_sitemap_settings', 'rapls_sitemap_cache_salt', 'rapls_sitemap_activated_at', 'rapls_sitemap_version' ) as $option ) {
	if ( false === strpos( $single, '0:' . $option ) ) {
		$deleted[] = $option;
	}
}
check( array() === $deleted, 'uninstall clears every option on a single site', implode( ', ', $deleted ) );

$multi = $run_uninstall( true );
foreach ( array( 1, 2, 3 ) as $site ) {
	if ( false === strpos( $multi, $site . ':rapls_sitemap_settings' ) ) {
		$network[] = 'site ' . $site;
	}
}
check( array() === $network, 'and on every site of a network, not just the one it ran on', implode( ', ', $network ) );
check( false === strpos( $multi, '0:rapls_sitemap_settings' ), 'without also acting outside a switched-to site' );

Plugin::instance()->load_textdomain();
check( 'rapls-sitemap' === $GLOBALS['rapls_textdomain'][0], 'the text domain is loaded from the bundled catalogue' );
check( false !== strpos( $GLOBALS['rapls_textdomain'][1], 'languages' ), 'out of the languages directory' );

summary();
