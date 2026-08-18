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

function fake_object( $name, $singular, $plural, $public = true, $queryable = null ) {
	$object                        = new stdClass();
	$object->name                  = $name;
	$object->public                = $public;
	$object->publicly_queryable    = null === $queryable ? $public : $queryable;
	$object->labels                = new stdClass();
	$object->labels->name          = $plural;
	$object->labels->singular_name = $singular;
	return $object;
}

/**
 * The two registrations where `public` and `publicly_queryable` disagree.
 *
 * `hidden` has public pages while `broken` does not, which is the reverse of
 * what each one's `public` flag says — the whole reason the screen asks
 * `is_post_type_viewable()` rather than filtering the candidates on `public`.
 */
function get_post_types( $args = array(), $output = 'names' ) {
	return array(
		'post'       => fake_object( 'post', 'Post', 'Posts' ),
		'page'       => fake_object( 'page', 'Page', 'Pages' ),
		'attachment' => fake_object( 'attachment', 'Media', 'Media' ),
		'hidden'     => fake_object( 'hidden', 'Hidden', 'Hidden things', false, true ),
		'broken'     => fake_object( 'broken', 'Broken', 'Broken things', true, false ),
	);
}

function get_taxonomies( $args = array(), $output = 'names' ) {
	return array(
		'category' => fake_object( 'category', 'Category', 'Categories' ),
		'post_tag' => fake_object( 'post_tag', 'Tag', 'Tags' ),
		'shelved'  => fake_object( 'shelved', 'Shelf', 'Shelves', true, false ),
	);
}

function get_post_type_object( $name ) {
	$types = get_post_types();
	return isset( $types[ $name ] ) ? $types[ $name ] : null;
}

function get_taxonomy( $name ) {
	$taxonomies = get_taxonomies();
	return isset( $taxonomies[ $name ] ) ? $taxonomies[ $name ] : false;
}

function is_post_type_viewable( $type ) {
	$object = is_object( $type ) ? $type : get_post_type_object( $type );
	return null !== $object && ! empty( $object->publicly_queryable );
}

function is_taxonomy_viewable( $taxonomy ) {
	$object = is_object( $taxonomy ) ? $taxonomy : get_taxonomy( $taxonomy );
	return false !== $object && null !== $object && ! empty( $object->publicly_queryable );
}

function wp_get_nav_menus( $args = array() ) {
	$menu          = new stdClass();
	$menu->term_id = 7;
	$menu->name    = 'Main navigation';
	return array( $menu );
}

function wp_roles() {
	$roles              = new stdClass();
	$roles->role_names  = array( 'administrator' => 'Administrator', 'author' => 'Author' );
	return $roles;
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
use RaplsSitemap\Support\PsMigration;
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
// The slider is the one design control on the Basic tab, and it posts into the
// same token array as the Advanced boxes — a range input needs no script to do
// that, which is what makes it a real control rather than an enhancement.
check( false !== strpos( $html, 'rapls_sitemap_settings[style][text_scale]' ), 'the text-size slider is on the Basic tab' );
check( false !== strpos( $html, 'type="range"' ), 'and it is a slider, which posts on its own with no script at all' );
check( false === strpos( $html, 'custom_css' ), 'no Additional CSS field — the directory does not permit storing arbitrary CSS' );
check( false !== strpos( $html, 'rapls-sitemap__item--term' ), 'but the class reference that made it useful is still there' );
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

// A menu can be the whole sitemap or one of its sections, so it appears twice —
// once in the source select, once as a section box. Both are only worth showing
// when the site has a menu to point at.
check( false !== strpos( $html, 'name="rapls_sitemap_settings[menu]"' ), 'the menu select is on the page' );
check( false !== strpos( $html, 'value="7"' ), 'with the site\'s menus in it' );
// One box per menu rather than a generic "Navigation menu": the bare alias could
// only mean whichever menu the source select is on, and a site with a global nav
// and a footer nav wants both.
check( false !== strpos( $html, 'name="rapls_sitemap_settings[sections][]" value="menu:7"' ), 'and each menu is offered as a section of its own' );
check( false !== strpos( $html, 'name="rapls_sitemap_settings[menu_headings]"' ), 'the placeholder-heading toggle is on the page' );
check( false !== strpos( $html, 'name="rapls_sitemap_settings[author_roles][]" value="author"' ), 'the author-role filter lists the site\'s roles' );
check( false !== strpos( $html, 'name="rapls_sitemap_settings[exclude_users]"' ), 'and user exclusions have a field' );

// What the screen offers is what the tree will list — asked through the same
// predicate, so neither a box that does nothing nor a missing box for a type
// that works can appear. Both directions matter: filtering the candidates on
// `public` first would get each of these two backwards.
check( false !== strpos( $html, 'value="hidden"' ), 'a type that is publicly queryable is offered even with public => false' );
check( false === strpos( $html, 'value="broken"' ), 'and one whose pages 404 is not offered even with public => true' );
check( false === strpos( $html, 'value="shelved"' ), 'the same question is asked of taxonomies' );
check( false === strpos( $html, 'value="attachment"' ), 'media is left out whatever its flags say' );

/* --- the two tabs ------------------------------------------------------- */

// The tabs are radio buttons and CSS, so the test for them is the markup that
// CSS reaches: two inputs and two panes, one of each per tab.
check( false !== strpos( $html, 'id="rapls-sitemap-tab-basic"' ), 'the Basic tab has its radio' );
check( false !== strpos( $html, 'id="rapls-sitemap-tab-advanced"' ), 'and so does Advanced' );
check( 1 === substr_count( $html, 'rapls-pane rapls-pane--basic' ), 'the Basic pane is on the page exactly once' );
check( 1 === substr_count( $html, 'rapls-pane rapls-pane--advanced' ), 'and so is the Advanced pane' );

// The radios must sit outside the form: inside it they would post a value the
// option knows nothing about, and the `~ form` selector that hides a pane
// could not reach across.
$before_form = substr( $html, 0, (int) strpos( $html, '<form ' ) );
check( false !== strpos( $before_form, 'rapls-tab-input' ), 'and both sit outside the form, so neither posts' );

// This is the check the split exists to keep honest. Moving eighteen rows into
// two panes is exactly the edit that loses one silently: the screen still
// renders, still saves, and one setting is simply no longer reachable.
$no_control = array(
	// Written by Settings::for_request() from `exclude_current`, never by a
	// human — see the note on cascading in TreeBuilder::nest().
	'exclude_self',
	// Posted as `style[<token>_value]` + `style[<token>_unit]` pairs, which the
	// split-length assertion above covers.
	'style',
);

$missing = array();
foreach ( array_keys( Settings::defaults() ) as $key ) {
	if ( in_array( $key, $no_control, true ) ) {
		continue;
	}
	if ( false === strpos( $html, 'rapls_sitemap_settings[' . $key . ']' ) ) {
		$missing[] = $key;
	}
}

check( array() === $missing, 'every setting still has a control somewhere on the screen', implode( ', ', $missing ) );

// Sections nest, so counting a closing pair proves nothing. What matters is
// that the page's tags balance overall — an unclosed section would swallow
// everything after it into a collapsed panel.
//
// The prefix is matched with the element name attached on purpose. The panels
// used to be `<div class="rapls-section">`, and each still holds a
// `<div class="rapls-section__body">` — so a bare `class="rapls-section` would
// have gone on counting the bodies after the panels stopped being divs, and
// this assertion would have passed while measuring the wrong thing.
$sections = substr_count( $html, '<details class="rapls-section' );
$opened   = preg_match_all( '/<div\b/', $html );
$closed   = substr_count( $html, '</div>' );

check( $sections >= 3, 'the collapsible sections are on the page', (string) $sections );
check( $opened === $closed, 'and every div on the page is closed', "opened {$opened}, closed {$closed}" );
check(
	$sections === substr_count( $html, '</details>' ) && $sections === substr_count( $html, '<summary ' ),
	'each one is a details with exactly one summary'
);

// The disclosure is the element's own, not a checkbox pressed into service. A
// checkbox on a settings form reads as "on or off", which is what eight of them
// down the Advanced tab looked like.
check( false === strpos( $html, 'rapls-toggle' ), 'and no nameless checkbox is left standing in for it' );

// A summary is a button to a screen reader; the heading inside it is how one
// moves through this screen. Losing the h2 in the swap would have been silent.
check( $sections === substr_count( $html, '<h2 class="rapls-section__title">' ), 'each panel title is still a real heading' );

// Same for the two forms: the reset form must not end up inside the settings
// form, which HTML forbids and browsers resolve by dropping one of them.
check( 2 === substr_count( $html, '<form ' ), 'the settings form and the reset form are both present' );
check( 2 === substr_count( $html, '</form>' ), 'and both are closed' );

/* --- reading a PS Auto Sitemap configuration ---------------------------- */

// The option survives that plugin's deletion, so a site that ran it years ago
// still holds the answers its owner already gave.
check( ! PsMigration::available(), 'nothing is offered on a site that never ran it' );

$before = $html;
update_option(
	PsMigration::OPTION,
	array(
		'home_list'      => '1',
		'post_tree'      => '1',
		'page_tree'      => '1',
		'disp_first'     => 'page',
		'disp_level'     => '3',
		'disp_posts'     => 'divide',
		'ex_cat_ids'     => '5, 9',
		'ex_post_ids'    => '12,34',
		'prepared_style' => 'marker',
		'use_cache'      => '',
		'post_id'        => '7',
		'suppress_link'  => '1',
	)
);

check( PsMigration::available(), 'and offered on a site that did' );

$mapped = PsMigration::to_settings( PsMigration::stored(), Settings::defaults() );

check( array( 'page', 'post' ) === $mapped['post_types'], 'disp_first decides which list comes first' );
check( 3 === $mapped['depth'], 'the depth carries over' );
check( true === $mapped['show_home'], 'so does the home link' );
check( 'terms_only' === $mapped['term_mode'], '"divide" becomes the category listing on its own' );
check( array( 5, 9 ) === $mapped['exclude_terms'], 'the excluded categories carry over' );
check( array( 12, 34 ) === $mapped['exclude_ids'], 'and the excluded posts' );
check( 'marker' === $mapped['design'], 'a preset with a counterpart here is matched' );
check( 0 === $mapped['cache_ttl'], 'and caching switched off there is switched off here' );

// The other direction too, and only where caching is currently off — importing
// must not overwrite a lifetime somebody chose here with one from nowhere.
$cached = PsMigration::to_settings( array( 'use_cache' => '1' ), array_merge( Settings::defaults(), array( 'cache_ttl' => 0 ) ) );
check( Settings::defaults()['cache_ttl'] === $cached['cache_ttl'], 'caching switched on there is switched on here' );

$kept = PsMigration::to_settings( array( 'use_cache' => '1' ), array_merge( Settings::defaults(), array( 'cache_ttl' => 900 ) ) );
check( 900 === $kept['cache_ttl'], 'and a lifetime already set here is left alone' );

// `post_id` named the page the sitemap sat on, so that page could be kept out
// of its own list. `exclude_current` does that without being told which page.
check( ! in_array( 7, $mapped['exclude_ids'], true ), 'the page it was placed on is not imported as an exclusion' );

$partial = PsMigration::to_settings( array( 'disp_level' => '2' ), Settings::defaults() );
check( array( 'page', 'post' ) === $partial['post_types'], 'a configuration missing keys leaves those settings alone' );

$none = PsMigration::to_settings( array( 'post_tree' => '', 'page_tree' => '' ), Settings::defaults() );
check( array( 'page', 'post' ) === $none['post_types'], 'and both lists switched off is not read as a sitemap of nothing' );

ob_start();
$page->render();
$html = (string) ob_get_clean();

check( false === strpos( $before, 'rapls_sitemap_import_ps' ), 'the import button is absent with nothing to import' );
check( false !== strpos( $html, 'rapls_sitemap_import_ps' ), 'and present once there is' );

$GLOBALS['rapls_nonce_checked'] = null;
try {
	$page->handle_import();
	check( false, 'importing redirects' );
} catch ( Rapls_Redirect $e ) {
	check( false !== strpos( $e->getMessage(), 'rapls-import=1' ), 'importing redirects back with a notice' );
}

check( SettingsPage::IMPORT_ACTION === $GLOBALS['rapls_nonce_checked'], 'behind its own nonce' );
check( 3 === Settings::get()['depth'], 'and the settings were actually written' );

delete_option( PsMigration::OPTION );
update_option( Settings::OPTION, Settings::defaults() );

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
foreach ( array( 'rapls_sitemap_settings', 'rapls_sitemap_cache_salt', 'rapls_sitemap_version' ) as $option ) {
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

/*
 * Translations come from translate.wordpress.org, so the plugin must NOT call
 * load_plugin_textdomain(). WordPress has loaded a hosted plugin's catalogue by
 * itself since 4.6, and Plugin Check flags the call. The stub above records any
 * call, so an empty record is the assertion.
 *
 * This is asserted rather than remembered because the call is one line, reads
 * as obviously correct, and every other plugin in the family used to have it.
 */
/*
 * Read through the tokenizer, not with strpos: the note in Plugin.php explaining
 * why the call is gone contains the call's own name, and a plain search matches
 * it. `bin/make-pot.php` solves the identical problem the identical way — a
 * function name inside a comment or a string is not a call.
 */
$called = array();
$files  = array( RAPLS_SITEMAP_DIR . 'rapls-sitemap.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( RAPLS_SITEMAP_DIR . 'src' ) ) as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
foreach ( $files as $file ) {
	foreach ( token_get_all( (string) file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && T_STRING === $token[0] && 'load_plugin_textdomain' === $token[1] ) {
			$called[] = basename( $file ) . ':' . $token[2];
		}
	}
}

check( array() === $called, 'nothing calls load_plugin_textdomain() anywhere in the plugin', implode( ', ', $called ) );
check( ! method_exists( Plugin::class, 'load_textdomain' ), 'and the method that used to is gone' );

// The script translations must not name a path either, for the same reason.
check(
	isset( $GLOBALS['rapls_script_translations'] ) && '' === $GLOBALS['rapls_script_translations'][2],
	'wp_set_script_translations() names no local path, so the JSON comes from WordPress.org too',
	var_export( $GLOBALS['rapls_script_translations'][2] ?? null, true )
);

summary();
