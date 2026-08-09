<?php
/**
 * The `[wp_sitemap_page]` compatibility shortcode.
 *
 * Covers the attribute mapping and — more importantly — the guard that keeps
 * this plugin from stealing the tag while WP Sitemap Page is active.
 *
 *   php tests/smoke-legacy-shortcode.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

function post_type_exists( $type ) {
	return in_array( $type, array( 'page', 'post', 'event' ), true );
}

// Tracks what add_shortcode() has claimed, so shortcode_exists() can answer.
function shortcode_exists( $tag ) {
	return isset( $GLOBALS['rapls_hooks'][ 'shortcode:' . $tag ] );
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Frontend\LegacyShortcode;
use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

$base = array_merge(
	Settings::defaults(),
	array(
		'source'     => 'content',
		'post_types' => array( 'page', 'post' ),
		'taxonomy'   => '',
		'term_mode'  => 'posts',
	)
);

/* --- no attribute renders the configured sitemap ------------------------ */

check( $base === LegacyShortcode::apply_atts( $base, array() ), 'a bare [wp_sitemap_page] uses the site settings' );

/* --- only="<post type>" ------------------------------------------------- */

$out = LegacyShortcode::apply_atts( $base, array( 'only' => 'page' ) );
check( array( 'page' ) === $out['post_types'], 'only="page" narrows to pages' );
check( 'content' === $out['source'], 'and stays on the content source' );

check( array( 'post' ) === LegacyShortcode::apply_atts( $base, array( 'only' => 'post' ) )['post_types'], 'only="post" narrows to posts' );
check( array( 'event' ) === LegacyShortcode::apply_atts( $base, array( 'only' => 'event' ) )['post_types'], 'a custom post type slug works' );

/* --- only="category" and only="tag" ------------------------------------- */

$out = LegacyShortcode::apply_atts( $base, array( 'only' => 'category' ) );
check( 'terms_only' === $out['term_mode'], 'only="category" lists categories rather than posts' );
check( 'category' === $out['taxonomy'], 'and targets the category taxonomy' );
check( array( 'post' ) === $out['post_types'], 'from the post type categories belong to' );

$out = LegacyShortcode::apply_atts( $base, array( 'only' => 'tag' ) );
check( 'post_tag' === $out['taxonomy'], 'only="tag" targets the tag taxonomy' );
check( 'terms_only' === $out['term_mode'], 'and lists the tags themselves' );
check( false === $out['nest_terms'], 'tags are flat, so nesting is switched off' );

/* --- only="author" and only="archive" ----------------------------------- */

check( 'authors' === LegacyShortcode::apply_atts( $base, array( 'only' => 'author' ) )['source'], 'only="author" switches to the author listing' );
check( 'archives' === LegacyShortcode::apply_atts( $base, array( 'only' => 'archive' ) )['source'], 'only="archive" switches to the date archives' );

/* --- input the other plugin would have tolerated ------------------------ */

check( 'authors' === LegacyShortcode::apply_atts( $base, array( 'only' => ' AUTHOR ' ) )['source'], 'the value is trimmed and case insensitive' );
check( $base === LegacyShortcode::apply_atts( $base, array( 'only' => 'nonsense' ) ), 'an unknown section falls back to the full sitemap rather than erroring' );
check( $base === LegacyShortcode::apply_atts( $base, array( 'only' => '' ) ), 'an empty value falls back too' );

/* --- the registration guards -------------------------------------------- */

$legacy = new LegacyShortcode( new Cache() );

check( false === Settings::defaults()['legacy_shortcode'], 'the compatibility shortcode is off by default' );

$legacy->maybe_register();
check( ! shortcode_exists( LegacyShortcode::TAG ), 'and the tag is left alone until the setting is switched on' );

update_option( Settings::OPTION, array( 'legacy_shortcode' => true ) );

$legacy->maybe_register();
check( shortcode_exists( LegacyShortcode::TAG ), 'the tag is claimed once the setting is on and nobody owns it' );

// Simulate WP Sitemap Page having registered first, with our setting still on.
$GLOBALS['rapls_hooks'] = array();
$GLOBALS['rapls_hooks'][ 'shortcode:' . LegacyShortcode::TAG ] = array( 'wp_sitemap_page_shortcode' );

$legacy->maybe_register();
check(
	array( 'wp_sitemap_page_shortcode' ) === $GLOBALS['rapls_hooks'][ 'shortcode:' . LegacyShortcode::TAG ],
	'an existing owner keeps the tag — no fight over the shortcode'
);

summary();
