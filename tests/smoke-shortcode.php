<?php
/**
 * Shortcode/block attribute folding.
 *
 * Both entry points share Shortcode::apply_atts(), so this covers the block's
 * overrides as well.
 *
 *   php tests/smoke-shortcode.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Frontend\Shortcode;
use RaplsSitemap\Support\Settings;

$base = array_merge(
	Settings::defaults(),
	array(
		'post_types' => array( 'page', 'post' ),
		'depth'      => 4,
		'design'     => 'card',
		'show_home'  => true,
	)
);

/* --- no attributes changes nothing -------------------------------------- */

check( $base === Shortcode::apply_atts( $base, array() ), 'no attributes leaves settings untouched' );

/* --- post types --------------------------------------------------------- */

$out = Shortcode::apply_atts( $base, array( 'post_types' => 'page' ) );
check( array( 'page' ) === $out['post_types'], 'a single post type narrows the list' );

$out = Shortcode::apply_atts( $base, array( 'post_types' => 'page, post' ) );
check( array( 'page', 'post' ) === $out['post_types'], 'a comma separated list is split' );

$out = Shortcode::apply_atts( $base, array( 'post_types' => '   ' ) );
check( array( 'page', 'post' ) === $out['post_types'], 'a blank attribute does not wipe the setting' );

/* --- depth -------------------------------------------------------------- */

check( 2 === Shortcode::apply_atts( $base, array( 'depth' => '2' ) )['depth'], 'depth is read from a string' );
check( 0 === Shortcode::apply_atts( $base, array( 'depth' => '-3' ) )['depth'], 'a negative depth clamps to unlimited' );

/* --- booleans: shortcode attributes are always strings ------------------ */

foreach ( array( '0', 'false', 'no', 'off', '' ) as $falsey ) {
	check(
		false === Shortcode::apply_atts( $base, array( 'show_home' => $falsey ) )['show_home'],
		sprintf( '"%s" reads as off', $falsey )
	);
}

foreach ( array( '1', 'true', 'yes' ) as $truthy ) {
	check(
		true === Shortcode::apply_atts( $base, array( 'show_home' => $truthy ) )['show_home'],
		sprintf( '"%s" reads as on', $truthy )
	);
}

/* --- design ------------------------------------------------------------- */

check( 'tree' === Shortcode::apply_atts( $base, array( 'design' => 'tree' ) )['design'], 'a known design is applied' );
check( 'card' === Shortcode::apply_atts( $base, array( 'design' => 'nope' ) )['design'], 'an unknown design is ignored' );

/* --- category handling -------------------------------------------------- */

check( 'terms_only' === Shortcode::apply_atts( $base, array( 'term_mode' => 'terms_only' ) )['term_mode'], 'the category-only mode is settable per placement' );
check( 'posts' === Shortcode::apply_atts( $base, array( 'term_mode' => 'sideways' ) )['term_mode'], 'an unknown term mode is ignored' );
check( false === Shortcode::apply_atts( $base, array( 'nest_terms' => '0' ) )['nest_terms'], 'category nesting can be switched off per placement' );
check( false === Shortcode::apply_atts( $base, array( 'exclude_current' => 'no' ) )['exclude_current'], 'self-exclusion can be switched off per placement' );

/* --- exclusions --------------------------------------------------------- */

$out = Shortcode::apply_atts( $base, array( 'exclude_ids' => '4 8, 15' ) );
check( array( 4, 8, 15 ) === $out['exclude_ids'], 'exclusions use the same loose parser as the settings screen' );

/* --- caching is global, never per placement ----------------------------- */

$out = Shortcode::apply_atts( $base, array( 'cache_ttl' => '0', 'load_styles' => '0' ) );
check( $base['cache_ttl'] === $out['cache_ttl'], 'cache_ttl cannot be overridden per placement' );
check( $base['load_styles'] === $out['load_styles'], 'load_styles cannot be overridden per placement' );

summary();
