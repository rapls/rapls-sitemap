<?php
/**
 * The legacy `<!-- SITEMAP CONTENT REPLACE POINT -->` placement marker.
 *
 * This is the migration path for sites coming from PS Auto Sitemap, so the
 * cases that matter are the messy ones: the editor wrapping the comment in a
 * paragraph, odd whitespace, and content that has no marker at all.
 *
 *   php tests/smoke-marker.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

// The tree is not the subject here — no posts, no taxonomies, so the sitemap
// reduces to its home link and the assertions stay about the marker.
function get_posts( $args ) {
	return array();
}

function get_object_taxonomies( $type, $output = 'names' ) {
	return array();
}

function is_post_type_hierarchical( $type ) {
	return 'page' === $type;
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function get_bloginfo( $key ) {
	return 'Example Site';
}

// The marker renders a real sitemap, so the builder's public-visibility gate
// runs — a post type nothing can describe is not one this plugin will list.
function get_post_type_object( $type ) {
	$object                        = new stdClass();
	$object->public                = true;
	$object->labels                = new stdClass();
	$object->labels->name          = ucfirst( $type ) . 's';
	$object->labels->singular_name = ucfirst( $type );
	return $object;
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Frontend\ContentMarker;
use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

$marker = new ContentMarker( new Cache() );

// Compatibility is off out of the box; everything below is about how it behaves
// once switched on. The off case is asserted at the end.
update_option( Settings::OPTION, array( 'legacy_marker' => true ) );

check( false === Settings::defaults()['legacy_marker'], 'the marker is off by default' );

/**
 * Did the sitemap land in this content?
 */
function rendered( $html ) {
	return false !== strpos( $html, 'rapls-sitemap' );
}

/* --- content with no marker is returned untouched ----------------------- */

$plain = '<p>Nothing to see.</p>';
check( $plain === $marker->replace( $plain ), 'content without the marker is passed straight through' );
check( '' === $marker->replace( '' ), 'empty content is safe' );

/* --- the bare marker ---------------------------------------------------- */

$out = $marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
check( rendered( $out ), 'the bare marker renders the sitemap' );
check( false === strpos( $out, 'REPLACE POINT' ), 'the marker itself is consumed' );

/* --- the shapes the editor actually produces ---------------------------- */

$wrapped = "<p>Intro.</p>\n<p><!-- SITEMAP CONTENT REPLACE POINT --></p>\n<p>Outro.</p>";
$out     = $marker->replace( $wrapped );
check( rendered( $out ), 'a paragraph-wrapped marker still renders' );
check( false === strpos( $out, '<p><nav' ), 'the wrapping paragraph is swallowed, not left around the sitemap' );
check( false !== strpos( $out, 'Intro.' ) && false !== strpos( $out, 'Outro.' ), 'surrounding content survives' );

check( rendered( $marker->replace( '<!--SITEMAP  CONTENT   REPLACE POINT-->' ) ), 'whitespace inside the comment is tolerated' );
check( rendered( $marker->replace( '<!-- sitemap content replace point -->' ) ), 'the marker is case insensitive' );

/* --- more than one marker on a page ------------------------------------- */

$twice = '<!-- SITEMAP CONTENT REPLACE POINT --><hr /><!-- SITEMAP CONTENT REPLACE POINT -->';
check( 2 === substr_count( $marker->replace( $twice ), '<nav' ), 'every marker on the page is replaced' );

/* --- one cache entry per language --------------------------------------- */

// WPML and Polylang narrow the queries themselves, inside the render — the
// settings are identical in every language, so without the locale in the key
// whichever language was rendered first would be served to all of them. The
// page ID from exclude_current usually differs between translations and hid
// this; a placement with that switched off did not.
$GLOBALS['rapls_transients'] = array();

$marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
$one = count( $GLOBALS['rapls_transients'] );

$GLOBALS['rapls_locale'] = 'en_US';
$marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
$GLOBALS['rapls_locale'] = 'ja';

check( 1 === $one, 'a render is cached', (string) $one );
check( 2 === count( $GLOBALS['rapls_transients'] ), 'and the same sitemap in another language gets an entry of its own' );

$marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
check( 2 === count( $GLOBALS['rapls_transients'] ), 'while the same language reuses the one it already has' );

/* --- the author CSS is attached once, not once per placement ------------ */

update_option(
	Settings::OPTION,
	array(
		'legacy_marker' => true,
		'custom_css'    => '.rapls-sitemap { border: 1px solid red }',
	)
);

$GLOBALS['rapls_inline_styles'] = array();

$twice = '<!-- SITEMAP CONTENT REPLACE POINT --><hr /><!-- SITEMAP CONTENT REPLACE POINT -->';
$out   = $marker->replace( $twice );

check( 1 === count( $GLOBALS['rapls_inline_styles'] ), 'two placements attach the CSS once between them', (string) count( $GLOBALS['rapls_inline_styles'] ) );
check( 'rapls-sitemap-inline' === $GLOBALS['rapls_inline_styles'][0][0], 'on a handle of its own, so it works with the bundled stylesheet switched off' );
check( false !== strpos( $GLOBALS['rapls_inline_styles'][0][1], 'border: 1px solid red' ), 'and carries the author\'s CSS' );
check( false === strpos( $out, 'border: 1px solid red' ), 'while the markup itself carries none of it' );

// Reaching the option by another route must not get past the sanitizer.
$GLOBALS['rapls_inline_styles'] = array();
$GLOBALS['rapls_styles_reset']  = ( function () {
	$reflection = new ReflectionClass( RaplsSitemap\Frontend\Styles::class );
	$property   = $reflection->getProperty( 'css_attached' );

	// Required on the 7.4 floor, a no-op since 8.1, and deprecated in 8.5 —
	// so the test has to keep making the call and stop making it at once.
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	$property->setValue( null, false );
	return true;
} )();

update_option(
	Settings::OPTION,
	array(
		'legacy_marker' => true,
		'custom_css'    => 'a{} </style><script>alert(1)</script>',
	)
);

$marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
check(
	false === strpos( $GLOBALS['rapls_inline_styles'][0][1], '</style' ),
	'CSS is re-sanitized on the way out, not trusted from the option'
);

update_option( Settings::OPTION, array( 'legacy_marker' => true ) );

/* --- PCRE giving up must not blank the post ----------------------------- */

// A backtrack limit is the realistic way preg_replace_callback returns null on
// a very long page. Casting that null to a string would wipe the content, which
// is a far worse failure than leaving the marker unreplaced.
$limit = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '1' );

$long = str_repeat( 'sitemap ', 200 ) . '<p><!-- SITEMAP CONTENT REPLACE POINT --></p>';
$out  = $marker->replace( $long );

check( '' !== $out, 'content survives a PCRE failure' );
check( false !== strpos( $out, 'sitemap sitemap' ), 'and comes back intact rather than blanked' );

ini_set( 'pcre.backtrack_limit', false === $limit ? '1000000' : $limit );

/* --- the escape hatches ------------------------------------------------- */

$GLOBALS['rapls_is_feed'] = true;
check( ! rendered( $marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' ) ), 'feeds are left alone' );
$GLOBALS['rapls_is_feed'] = false;

update_option( Settings::OPTION, array( 'legacy_marker' => false ) );
$out = $marker->replace( '<!-- SITEMAP CONTENT REPLACE POINT -->' );
check( ! rendered( $out ), 'the setting disables the marker' );
check( false !== strpos( $out, 'REPLACE POINT' ), 'and leaves the comment in place' );

summary();
