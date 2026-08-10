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

/* --- the new per-placement attributes ------------------------------------ */

check( 50 === Shortcode::apply_atts( $base, array( 'number' => '50' ) )['max_entries'], 'number caps the list for one placement' );
check( 0 === Shortcode::apply_atts( $base, array( 'number' => '-5' ) )['max_entries'], 'a negative cap means no cap' );
check( 10 === Shortcode::apply_atts( $base, array( 'offset' => '10' ) )['offset'], 'offset skips entries' );
check( 5 === Shortcode::apply_atts( $base, array( 'per_category' => '5' ) )['max_per_term'], 'per_category caps each category' );
check( false === Shortcode::apply_atts( $base, array( 'link_headings' => '0' ) )['link_headings'], 'headings can be unlinked per placement' );
check( 'ol' === Shortcode::apply_atts( $base, array( 'list_type' => 'ol' ) )['list_type'], 'the list element is settable' );
check( 'ul' === Shortcode::apply_atts( $base, array( 'list_type' => 'dl' ) )['list_type'], 'and an unknown one is ignored' );

check( 'title' === Shortcode::apply_atts( $base, array( 'orderby' => 'title' ) )['orderby'], 'ordering is settable per placement' );
check( 'rand' === Shortcode::apply_atts( $base, array( 'orderby' => 'rand' ) )['orderby'], 'including the new random order' );
check( $base['orderby'] === Shortcode::apply_atts( $base, array( 'orderby' => 'whatever' ) )['orderby'], 'an unknown ordering is ignored' );
check( 'ASC' === Shortcode::apply_atts( $base, array( 'order' => 'asc' ) )['order'], 'the direction is case insensitive' );

check( true === Shortcode::apply_atts( $base, array( 'show_date' => '1' ) )['show_date'], 'dates can be switched on per placement' );
check( 'Y-m-d' === Shortcode::apply_atts( $base, array( 'date_format' => 'Y-m-d' ) )['date_format'], 'with their own format' );
check( 5 === Shortcode::apply_atts( $base, array( 'excerpt_length' => '5' ) )['excerpt_length'], 'and excerpt length is settable too' );
check( false === Shortcode::apply_atts( $base, array( 'section_headings' => 'no' ) )['section_headings'], 'headings can be switched off per placement' );

/* --- menu ----------------------------------------------------------------- */

// Naming a menu is asking for that menu. Requiring source="menu" beside it
// would be a second way to say one thing, and forgetting it would silently show
// the ordinary sitemap.
$out = Shortcode::apply_atts( $base, array( 'menu' => 'primary' ) );
check( 'primary' === $out['menu'], 'a menu can be named by slug' );
check( 'menu' === $out['source'], 'and naming one selects it as the source' );

// Unless the placement said otherwise, in which case it meant what it said.
$out = Shortcode::apply_atts( $base, array( 'menu' => 'primary', 'source' => 'authors' ) );
check( 'authors' === $out['source'], 'an explicit source is not overridden' );
check( 'primary' === $out['menu'], 'though the menu is still remembered' );

check( $base['source'] === Shortcode::apply_atts( $base, array( 'menu' => '' ) )['source'], 'and an empty menu selects nothing' );

/* --- sections ------------------------------------------------------------ */

check(
	array( 'page', 'post', 'category', 'author' ) === Shortcode::apply_atts( $base, array( 'sections' => 'page, post,category author' ) )['sections'],
	'a section list is read in the loose format both migration sources use'
);
check(
	array( 'page', 'post' ) === Shortcode::apply_atts( $base, array( 'sections' => 'page,post,page' ) )['sections'],
	'and a section named twice is listed once'
);

// A placement on a site whose default composes several sections has to be able
// to say "just the one list", which an omitted attribute cannot mean.
check( array() === Shortcode::apply_atts( $base, array( 'sections' => '' ) )['sections'], 'an empty list is an instruction, not an omission' );

/* --- child_of ------------------------------------------------------------ */

check( 12 === Shortcode::apply_atts( $base, array( 'child_of' => '12' ) )['child_of'], 'a branch root can be named by ID' );
check( 0 === Shortcode::apply_atts( $base, array( 'child_of' => '-3' ) )['child_of'], 'and a negative one reads as unset' );

// Left as the literal string on purpose: which page this is cannot be known
// here, and resolving it later is what keeps two pages from sharing one cache
// entry. Settings::for_request() finishes the job.
check( 'current' === Shortcode::apply_atts( $base, array( 'child_of' => 'Current' ) )['child_of'], 'child_of="current" survives folding, in any case' );
check( 0 === Shortcode::apply_atts( $base, array( 'child_of' => 'nonsense' ) )['child_of'], 'and anything else is not a page ID' );

/* --- caching is global, never per placement ----------------------------- */

$out = Shortcode::apply_atts( $base, array( 'cache_ttl' => '0', 'load_styles' => '0' ) );
check( $base['cache_ttl'] === $out['cache_ttl'], 'cache_ttl cannot be overridden per placement' );
check( $base['load_styles'] === $out['load_styles'], 'load_styles cannot be overridden per placement' );

summary();
