<?php
/**
 * Renderer markup, nesting, and escaping.
 *
 * The renderer is the only class that emits HTML, so this is where escaping
 * regressions get caught.
 *
 *   php tests/smoke-renderer.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Sitemap\Node;
use RaplsSitemap\Sitemap\Renderer;
use RaplsSitemap\Support\Settings;

$settings = Settings::defaults();

/* --- empty state -------------------------------------------------------- */

$html = ( new Renderer( $settings ) )->render( array() );
check( false !== strpos( $html, 'rapls-sitemap__empty' ), 'an empty tree renders the empty state' );
check( false === strpos( $html, '<ul' ), 'the empty state emits no list' );

/* --- nesting ------------------------------------------------------------ */

$parent = new Node( 1, 'Parent', 'https://example.test/parent' );
$parent->add( new Node( 2, 'Child', 'https://example.test/child' ) );
$flat = new Node( 3, 'Flat', 'https://example.test/flat' );

$html = ( new Renderer( $settings ) )->render( array( $parent, $flat ) );

check( 2 === substr_count( $html, '<ul' ), 'one list per level' );
check( false !== strpos( $html, 'rapls-sitemap__list--depth-1' ), 'nested lists carry their depth class' );
check( false !== strpos( $html, 'rapls-sitemap__item--has-children' ), 'parents are flagged for styling' );
check( substr_count( $html, '<ul' ) === substr_count( $html, '</ul>' ), 'lists are balanced' );
check( substr_count( $html, '<li' ) === substr_count( $html, '</li>' ), 'items are balanced' );

/* --- headings ----------------------------------------------------------- */

$heading = new Node( 5, 'News', '', 'term' );
$heading->add( new Node( 6, 'Entry', 'https://example.test/entry' ) );
$html = ( new Renderer( $settings ) )->render( array( $heading ) );

check( false !== strpos( $html, 'rapls-sitemap__label">News' ), 'a URL-less node renders as plain text' );
check( false === strpos( $html, '<a class="rapls-sitemap__link" href="">' ), 'no empty href is emitted' );

/* --- escaping ----------------------------------------------------------- */

$hostile = new Node( 9, '<script>alert(1)</script>', 'https://example.test/?a=1&b="2"' );
$html    = ( new Renderer( $settings ) )->render( array( $hostile ) );

check( false === strpos( $html, '<script>' ), 'titles are escaped' );
check( false !== strpos( $html, '&lt;script&gt;' ), 'the escaped title is still present' );
check( false === strpos( $html, 'b="2"' ), 'quotes in URLs cannot break out of the attribute' );

$js   = new Node( 10, 'Bad', 'javascript:alert(1)' );
$html = ( new Renderer( $settings ) )->render( array( $js ) );
check( false === strpos( $html, 'javascript:' ), 'unsafe protocols are dropped by esc_url' );

/* --- design preset lands on the wrapper --------------------------------- */

$settings['design'] = 'tree';
$html               = ( new Renderer( $settings ) )->render( array( $flat ) );
check( false !== strpos( $html, 'rapls-sitemap--tree' ), 'the design preset reaches the wrapper class' );
check( false !== strpos( $html, '<nav ' ), 'a populated sitemap is a landmark' );

/* --- ordered lists ------------------------------------------------------- */

$settings              = Settings::defaults();
$settings['list_type'] = 'ol';
$html                  = ( new Renderer( $settings ) )->render( array( $parent ) );

check( 2 === substr_count( $html, '<ol ' ), 'every level becomes an ordered list' );
check( false === strpos( $html, '<ul' ), 'and no unordered list is left behind' );
check( substr_count( $html, '<ol ' ) === substr_count( $html, '</ol>' ), 'the tags balance' );

/* --- date, excerpt, and count ------------------------------------------- */

$dated          = new Node( 20, 'Dated', 'https://example.test/dated' );
$dated->date    = '2026-03-01';
$dated->excerpt = 'A short summary.';

$counted        = new Node( 21, 'News', '', 'term' );
$counted->count = 12;

$html = ( new Renderer( Settings::defaults() ) )->render( array( $dated, $counted ) );

check( false !== strpos( $html, 'rapls-sitemap__date">2026-03-01' ), 'the date is rendered in its own element' );
check( false !== strpos( $html, 'rapls-sitemap__excerpt">A short summary.' ), 'so is the excerpt' );
check( false !== strpos( $html, 'rapls-sitemap__count' ), 'and the count' );
check( false !== strpos( $html, '12' ), 'with the number in it' );

// Zero is a real count and must still print; -1 is what "no count" looks like.
$zero        = new Node( 22, 'Empty', '', 'term' );
$zero->count = 0;
$html        = ( new Renderer( Settings::defaults() ) )->render( array( $zero ) );
check( false !== strpos( $html, 'rapls-sitemap__count' ), 'a count of zero is printed, not mistaken for absent' );

$plain = ( new Renderer( Settings::defaults() ) )->render( array( $flat ) );
check( false === strpos( $plain, '__date' ) && false === strpos( $plain, '__excerpt' ) && false === strpos( $plain, '__count' ), 'and none of the three appear when unset' );

/* --- escaping applies to the new fields too ------------------------------ */

$hostile          = new Node( 23, 'Fine', 'https://example.test/x' );
$hostile->excerpt = '<script>alert(1)</script>';
$hostile->date    = '<b>2026</b>';
$html             = ( new Renderer( Settings::defaults() ) )->render( array( $hostile ) );

check( false === strpos( $html, '<script>' ), 'an excerpt cannot inject markup' );
check( false === strpos( $html, '<b>2026' ), 'nor can a date' );

/* --- nofollow ------------------------------------------------------------ */

$plain = ( new Renderer( Settings::defaults() ) )->render( array( $flat ) );
check( false === strpos( $plain, 'rel=' ), 'no rel attribute is emitted by default' );

$settings             = Settings::defaults();
$settings['nofollow'] = true;
$html                 = ( new Renderer( $settings ) )->render( array( $parent ) );

check( false !== strpos( $html, 'rel="nofollow"' ), 'nofollow reaches the links' );
check( 2 === substr_count( $html, 'rel="nofollow"' ), 'every link, not only the top level' );
check( false === strpos( $html, '__label" rel=' ), 'a heading with no link gains no rel attribute' );

/* --- design tokens reach the markup ------------------------------------- */

$settings          = Settings::defaults();
$settings['style'] = RaplsSitemap\Support\Design::sanitize(
	array(
		'link_color' => '#c00',
		'marker'     => 'icon',
		'marker_icon' => 'fa-solid fa-star',
	)
);

$html = ( new Renderer( $settings ) )->render( array( $flat ) );

check( 0 === strpos( $html, '<style>' ), 'the token stylesheet is emitted ahead of the sitemap' );
check( false !== strpos( $html, 'color:#c00' ), 'and carries the configured value' );
check( false !== strpos( $html, 'rapls-sitemap--t' ), 'the wrapper carries the scope class the stylesheet targets' );
check( false !== strpos( $html, '<i class="rapls-sitemap__icon fa-solid fa-star" aria-hidden="true">' ), 'an icon bullet renders as a real element' );
check( 1 === substr_count( $html, '<style>' ), 'one style element, not one per level' );

// Untouched tokens must add nothing at all — a sitemap that was fine before
// should render byte-identically after the feature landed.
$plain = ( new Renderer( Settings::defaults() ) )->render( array( $flat ) );
check( false === strpos( $plain, '<style>' ), 'unconfigured tokens emit no stylesheet' );
check( false === strpos( $plain, 'rapls-sitemap--t' ), 'and no scope class' );
check( false === strpos( $plain, '<i ' ), 'and no icon element' );

/* --- additional CSS ------------------------------------------------------ */

$settings               = Settings::defaults();
$settings['custom_css'] = '.rapls-sitemap { border: 1px solid red }';
$html                   = ( new Renderer( $settings ) )->render( array( $flat ) );

check( false !== strpos( $html, 'border: 1px solid red' ), 'the author CSS is printed' );

$settings['custom_css'] = 'a{} </style><script>alert(1)</script>';
$html                   = ( new Renderer( $settings ) )->render( array( $flat ) );
check( false === strpos( $html, '</style><script' ), 'author CSS is re-sanitized at render time, not trusted from the option' );

summary();
