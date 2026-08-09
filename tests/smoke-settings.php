<?php
/**
 * Settings defaults, sanitization, and the loose ID-list parser.
 *
 *   php tests/smoke-settings.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

function post_type_exists( $type ) {
	return in_array( $type, array( 'page', 'post', 'product' ), true );
}

function taxonomy_exists( $taxonomy ) {
	return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Support\Settings;

/* --- defaults ----------------------------------------------------------- */

$defaults = Settings::defaults();
check( array( 'page', 'post' ) === $defaults['post_types'], 'defaults include pages and posts' );
check( 0 === $defaults['depth'], 'default depth is unlimited' );
check( in_array( $defaults['design'], Settings::DESIGNS, true ), 'default design is a known preset' );

/* --- get() merges and never leaks unknown keys -------------------------- */

update_option( Settings::OPTION, array( 'depth' => 3, 'bogus' => 'x' ) );
$settings = Settings::get();
check( 3 === $settings['depth'], 'stored value wins over the default' );
check( array( 'page', 'post' ) === $settings['post_types'], 'missing keys fall back to defaults' );
check( ! array_key_exists( 'bogus', $settings ), 'unknown stored keys are dropped' );

/* --- sanitize ----------------------------------------------------------- */

$clean = Settings::sanitize(
	array(
		'post_types' => array( 'page', 'nope', 'product' ),
		'depth'      => '99',
		'design'     => 'card',
		'cache_ttl'  => '-5',
		'home_label' => "  <b>Top</b>  ",
	)
);

check( array( 'page', 'product' ) === $clean['post_types'], 'unregistered post types are stripped' );
check( 10 === $clean['depth'], 'depth is clamped to 10' );
check( 'card' === $clean['design'], 'a known design is kept' );
check( 0 === $clean['cache_ttl'], 'a negative TTL clamps to 0' );
check( 'Top' === $clean['home_label'], 'the home label is sanitized and trimmed' );

$clean = Settings::sanitize( array( 'design' => 'evil' ) );
check( 'simple' === $clean['design'], 'an unknown design falls back to the default' );

check( array() === Settings::sanitize( 'not an array' )['exclude_ids'], 'non-array input yields defaults' );

/* --- unchecked checkboxes must read as false, not as "missing" ---------- */

$clean = Settings::sanitize( array( 'depth' => 1 ) );
check( false === $clean['show_home'], 'an omitted checkbox saves as off' );
check( false === $clean['group_by_term'], 'an omitted grouping checkbox saves as off' );

/* --- the loose ID-list parser (PS Auto Sitemap paste compatibility) ------ */

check( array( 12, 34, 56 ) === Settings::to_id_list( '12, 34 56' ), 'commas and spaces both separate' );
check( array( 7 ) === Settings::to_id_list( '7, 7, -7, 0, abc' ), 'duplicates, zero, and junk are dropped' );
check( array() === Settings::to_id_list( '' ), 'an empty string yields an empty list' );
check( array( 3, 9 ) === Settings::to_id_list( array( '3', 9 ) ), 'an array is accepted directly' );

/* --- source and taxonomy ------------------------------------------------ */

check( 'archives' === Settings::sanitize( array( 'source' => 'archives' ) )['source'], 'a known source is kept' );
check( 'content' === Settings::sanitize( array( 'source' => 'elsewhere' ) )['source'], 'an unknown source falls back to content' );
check( 'post_tag' === Settings::sanitize( array( 'taxonomy' => 'post_tag' ) )['taxonomy'], 'a registered taxonomy is kept' );
check( '' === Settings::sanitize( array( 'taxonomy' => 'imaginary' ) )['taxonomy'], 'an unregistered taxonomy resets to auto-detect' );
check( '' === Settings::sanitize( array( 'taxonomy' => '' ) )['taxonomy'], 'an empty taxonomy stays empty — it means auto-detect' );

/* --- output order of the lists ------------------------------------------ */

$clean = Settings::sanitize(
	array(
		'post_types'       => array( 'page', 'post' ),
		'post_types_order' => array( 'page' => 5, 'post' => 1 ),
	)
);
check( array( 'post', 'page' ) === $clean['post_types'], 'the order field decides which list comes first' );
check( ! array_key_exists( 'post_types_order', $clean ), 'the order field itself is never stored' );

$clean = Settings::sanitize(
	array(
		'post_types'       => array( 'page', 'post', 'product' ),
		'post_types_order' => array( 'page' => 2, 'post' => 2 ),
	)
);
check( array( 'page', 'post', 'product' ) === $clean['post_types'], 'a tie keeps the incoming order, and an unranked type sorts last' );

/* --- ordering ------------------------------------------------------------ */

check( 'title' === Settings::sanitize( array( 'orderby' => 'title' ) )['orderby'], 'a known ordering is kept' );
check( 'default' === Settings::sanitize( array( 'orderby' => 'popularity' ) )['orderby'], 'an unknown ordering falls back' );
check( 'ASC' === Settings::sanitize( array( 'order' => 'asc' ) )['order'], 'the direction is case insensitive' );
check( 'DESC' === Settings::sanitize( array( 'order' => 'sideways' ) )['order'], 'and anything unrecognised means descending' );
check( 'yomi' === Settings::sanitize( array( 'sort_meta_key' => 'yomi' ) )['sort_meta_key'], 'a sort key is kept' );
// sanitize_key strips rather than rejects, so the property to assert is that
// nothing punctuation-shaped survives into a meta_key.
$key = Settings::sanitize( array( 'sort_meta_key' => 'a b;DROP TABLE' ) )['sort_meta_key'];
check( 1 === preg_match( '/^[a-z0-9_-]*$/', $key ), 'a sort key keeps only characters legal in a meta key', $key );

/* --- the nested style key -------------------------------------------------*/

// array_merge would replace the nested array whole, so a catalogue saved before
// a token existed must still come back with that token present.
update_option( Settings::OPTION, array( 'style' => array( 'link_color' => '#c00' ) ) );
$settings = Settings::get();

check( '#c00' === $settings['style']['link_color'], 'a stored token survives' );
check( array_key_exists( 'child_marker', $settings['style'] ), 'and tokens absent from the stored array are filled in from defaults' );

update_option( Settings::OPTION, array() );

/* --- request context: the sitemap page excludes itself ------------------ */

$base = array_merge( Settings::defaults(), array( 'exclude_ids' => array( 42 ) ) );

$GLOBALS['rapls_current_post'] = 7;
$out                          = Settings::for_request( $base );
check( array( 42, 7 ) === $out['exclude_ids'], 'the current page joins the exclusion list' );

$out = Settings::for_request( $out );
check( array( 42, 7 ) === $out['exclude_ids'], 'applying it twice does not duplicate the ID' );

$GLOBALS['rapls_current_post'] = 0;
check( array( 42 ) === Settings::for_request( $base )['exclude_ids'], 'outside a post nothing is added' );

$GLOBALS['rapls_current_post'] = 7;
$off                          = array_merge( $base, array( 'exclude_current' => false ) );
check( array( 42 ) === Settings::for_request( $off )['exclude_ids'], 'the setting can be turned off' );

summary();
