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

// The bootstrap's apply_filters hands the value back untouched, which is right
// everywhere else. Settings::get() documents an invariant that has to hold
// against whatever a filter returns, so this one actually calls something.
$GLOBALS['rapls_settings_filter'] = null;

function apply_filters( $hook, $value ) {
	if ( 'rapls_sitemap/settings' === $hook && $GLOBALS['rapls_settings_filter'] ) {
		return call_user_func( $GLOBALS['rapls_settings_filter'], $value );
	}
	return $value;
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

/* --- the schema survives whatever a filter returns ----------------------- */

/*
 * Every consumer indexes this array without isset(), on the strength of the
 * promise in defaults(). A filter returning a partial array is an ordinary
 * thing for a site to do, and without protection one missing key becomes an
 * undefined-index warning on every line that reads it — printed inside
 * the_content, where it lands in the page.
 */
// Captured with no filter installed, so the comparison below is against the
// real unfiltered value rather than against itself.
$before = Settings::get()['depth'];

$GLOBALS['rapls_settings_filter'] = static function ( $settings ) {
	unset( $settings['depth'], $settings['post_types'] );
	return $settings;
};

$filtered = Settings::get();

check( array_key_exists( 'depth', $filtered ), 'a filter cannot remove a key from the schema' );
check( array_key_exists( 'post_types', $filtered ), 'nor any other one' );

// It comes back as whatever it was before the filter ran — the stored value if
// there is one, the default otherwise. Removing a key is simply not an edit.
check( $before === $filtered['depth'], 'and a removed key keeps the value it had' );

// It must still be able to do its actual job.
$GLOBALS['rapls_settings_filter'] = static function ( $settings ) {
	$settings['depth'] = 4;
	return $settings;
};
check( 4 === Settings::get()['depth'], 'while a filter that changes a value still changes it' );

// The nested token array has the same promise and needs the same treatment.
$GLOBALS['rapls_settings_filter'] = static function ( $settings ) {
	$settings['style'] = array( 'link_color' => '#0f0' );
	return $settings;
};
$filtered = Settings::get();
check( '#0f0' === $filtered['style']['link_color'], 'a filter can replace the token array' );
check( array_key_exists( 'marker', $filtered['style'] ), 'and the tokens it left out are filled back in' );

$GLOBALS['rapls_settings_filter'] = static function () {
	return 'not an array at all';
};
check( is_array( Settings::get() ), 'and nonsense from a filter is ignored outright' );

$GLOBALS['rapls_settings_filter'] = null;

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

/* --- exclusion lists ----------------------------------------------------- */

$clean = Settings::sanitize( array( 'exclude_types' => array( 'product', 'Bad Slug!', '' ) ) );
check( array( 'product', 'badslug' ) === $clean['exclude_types'], 'excluded post types are reduced to key-safe slugs' );

$clean = Settings::sanitize( array( 'exclude_tax' => array( 'post_tag' ) ) );
check( array( 'post_tag' ) === $clean['exclude_tax'], 'an excluded taxonomy is kept' );

// The screen submits a hidden empty entry so clearing every box still posts the
// key; it must not survive as a slug.
check( array() === Settings::sanitize( array( 'exclude_types' => array( '' ) ) )['exclude_types'], 'the hidden placeholder entry is dropped' );

/* --- the new toggles ------------------------------------------------------ */

$clean = Settings::sanitize( array( 'depth' => 1 ) );
check( false === $clean['nofollow'], 'nofollow is off unless asked for' );
check( false === $clean['exclude_protected'], 'protected entries are listed unless asked otherwise' );
check( false === $clean['exclude_noindex'], 'noindex entries too' );
check( false === $clean['duplicate_in_terms'], 'an omitted checkbox saves as off, like the others' );

$defaults = Settings::defaults();
check( true === $defaults['duplicate_in_terms'], 'but listing under every category is the default' );
check( false === $defaults['nofollow'], 'and links are followed by default' );

/* --- the CSS field has a ceiling ----------------------------------------- */

// It is the one field with no natural size, and it lives in an option read on
// every sitemap render. WordPress 6.6 stops autoloading an option above 150 KB
// on its own; this stays well under that on purpose.
$huge = str_repeat( '.rapls-sitemap { color: red } ', 5000 );
check( strlen( $huge ) > Settings::MAX_CSS_BYTES, 'the fixture is genuinely oversized' );
check( Settings::MAX_CSS_BYTES === strlen( Settings::sanitize_css( $huge ) ), 'oversized CSS is cut to the ceiling' );
check( Settings::MAX_CSS_BYTES < 150 * 1024, 'and the ceiling sits below the size at which WordPress stops autoloading' );

$small = '.rapls-sitemap { color: red }';
check( $small === Settings::sanitize_css( $small ), 'ordinary CSS is untouched by the ceiling' );

/* --- headings ------------------------------------------------------------- */

check( '' === Settings::defaults()['heading_level'], 'labels are plain text until a level is chosen' );
check( 'h3' === Settings::sanitize( array( 'heading_level' => 'h3' ) )['heading_level'], 'a real level is kept' );
check( '' === Settings::sanitize( array( 'heading_level' => 'h1' ) )['heading_level'], 'h1 is not offered — a page has one already' );
check( '' === Settings::sanitize( array( 'heading_level' => 'div' ) )['heading_level'], 'and anything that is not a heading is rejected' );

/* --- who may write CSS ----------------------------------------------------*/

// `manage_options` is not enough: on a network every site administrator holds
// it, and CSS is printed verbatim. unfiltered_html is the capability WordPress
// already reserves for that, and reserves for super admins on multisite.
check( 'unfiltered_html' === Settings::CSS_CAPABILITY, 'CSS editing is gated on unfiltered_html, not manage_options' );

update_option( Settings::OPTION, array( 'custom_css' => '.rapls-sitemap { color: red }' ) );

$GLOBALS['rapls_caps']['unfiltered_html'] = true;
check(
	'.rapls-sitemap { color: blue }' === Settings::sanitize( array( 'custom_css' => '.rapls-sitemap { color: blue }' ) )['custom_css'],
	'someone with the capability can change it'
);

// A form field is forgeable, so the check has to hold at the save, not only at
// the render — and it must not wipe what is already stored either.
$GLOBALS['rapls_caps']['unfiltered_html'] = false;
$clean = Settings::sanitize( array( 'custom_css' => 'body { display: none }' ) );
check( false === strpos( $clean['custom_css'], 'display: none' ), 'a forged submission from someone without it is discarded' );
check( '.rapls-sitemap { color: red }' === $clean['custom_css'], 'and the stored CSS survives their save untouched' );

// The field is not rendered for them at all, so it never posts — absence must
// preserve rather than reset, unlike every other key.
$clean = Settings::sanitize( array( 'depth' => 3 ) );
check( '.rapls-sitemap { color: red }' === $clean['custom_css'], 'an unsubmitted field keeps the stored CSS' );

$GLOBALS['rapls_caps']['unfiltered_html'] = true;
update_option( Settings::OPTION, array() );

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

// Recorded apart from exclude_ids, not appended to it. Both keep the page out
// of the query, but only the listed exclusions take a page's children with
// them — leaving the sitemap page out of its own list is no reason to hide the
// pages filed under it.
check( 7 === $out['exclude_self'], 'the current page is recorded on its own key' );
check( array( 42 ) === $out['exclude_ids'], 'and the listed exclusions are left as they were' );

$out = Settings::for_request( $out );
check( 7 === $out['exclude_self'], 'applying it twice changes nothing' );

$GLOBALS['rapls_current_post'] = 0;
check( 0 === Settings::for_request( $base )['exclude_self'], 'outside a post nothing is recorded' );

$GLOBALS['rapls_current_post'] = 7;
$off                          = array_merge( $base, array( 'exclude_current' => false ) );
check( 0 === Settings::for_request( $off )['exclude_self'], 'the setting can be turned off' );

/* --- request context: child_of="current" -------------------------------- */

// This is the whole reason `child_of` is resolved here rather than where the
// shortcode is parsed: the answer differs per page, and the cache key is taken
// from the settings after this runs.
$GLOBALS['rapls_current_post'] = 7;
$out                          = Settings::for_request( array_merge( Settings::defaults(), array( 'child_of' => 'current' ) ) );
check( 7 === $out['child_of'], 'child_of="current" becomes the current page' );

$GLOBALS['rapls_current_post'] = 0;
$out                          = Settings::for_request( array_merge( Settings::defaults(), array( 'child_of' => 'current' ) ) );
check( 0 === $out['child_of'], 'and off a singular page it scopes to nothing, which is the whole site' );

// The two are separate decisions, and the gate that used to join them made
// child_of="current" quietly list the whole site whenever a placement turned
// exclude_current off — the one combination nothing tested.
$GLOBALS['rapls_current_post'] = 7;
$out                          = Settings::for_request(
	array_merge( Settings::defaults(), array( 'child_of' => 'current', 'exclude_current' => false ) )
);
check( 7 === $out['child_of'], 'child_of="current" resolves even with exclude_current off' );
check( 0 === $out['exclude_self'], 'and the page is still listed, which is what off means' );

// The reverse pairing, so neither one can start depending on the other.
$out = Settings::for_request( array_merge( Settings::defaults(), array( 'exclude_current' => true ) ) );
check( 7 === $out['exclude_self'], 'and exclude_current resolves with no child_of in play' );

// Only the page tree has branches. A resolved ID left on the other sources
// would change nothing about the output and give every page its own cache
// entry for an identical list.
foreach ( array( 'authors', 'archives' ) as $source ) {
	$out = Settings::for_request(
		array_merge( Settings::defaults(), array( 'child_of' => 'current', 'source' => $source ) )
	);
	check( 0 === $out['child_of'], sprintf( 'the %s listing carries no branch, so it cannot split the cache', $source ) );
}

$GLOBALS['rapls_current_post'] = 7;
check( 12 === Settings::for_request( array_merge( Settings::defaults(), array( 'child_of' => 12 ) ) )['child_of'], 'a literal ID is left alone' );
check( 0 === Settings::for_request( array_merge( Settings::defaults(), array( 'child_of' => -5 ) ) )['child_of'], 'and a negative one cannot become a query for post -5' );

summary();
