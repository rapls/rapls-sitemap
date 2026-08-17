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

/* --- headings that are not links ----------------------------------------- */

$term_node    = new Node( 50, 'News', 'https://example.test/news', 'term' );
$section_node = new Node( 0, 'Pages', 'https://example.test/blog/', 'section' );
$year_node    = new Node( 2026, '2026', 'https://example.test/2026/', 'archive' );
$post_node    = new Node( 51, 'A page', 'https://example.test/a' );
$home_node    = new Node( 0, 'Example Site', 'https://example.test/', 'home' );

$html = ( new Renderer( Settings::defaults() ) )->render( array( $term_node, $post_node ) );
check( 2 === substr_count( $html, '<a class' ), 'headings are links by default' );

$settings                  = Settings::defaults();
$settings['link_headings'] = false;
$html                      = ( new Renderer( $settings ) )->render(
	array( $section_node, $term_node, $year_node, $post_node, $home_node )
);

check( false === strpos( $html, 'href="https://example.test/news"' ), 'a category heading stops linking to its archive' );
check( false === strpos( $html, 'href="https://example.test/blog/"' ), 'so does a section heading' );
check( false === strpos( $html, 'href="https://example.test/2026/"' ), 'and a year heading' );
check( 3 === substr_count( $html, 'rapls-sitemap__label' ), 'all three become plain text instead' );

// An entry that is not a link is not a sitemap entry, and the front-page link
// is a link by definition — neither is a heading.
check( false !== strpos( $html, 'href="https://example.test/a"' ), 'the entries themselves still link' );
check( false !== strpos( $html, 'href="https://example.test/"' ), 'and so does the front-page link' );

/* --- entries that have entries under them -------------------------------- */

// A section page that exists only to hold its children is a link to a page
// nobody wants to read. Off, it becomes the heading it already was.
$parent = new Node( 60, 'About us', 'https://example.test/about', 'post' );
$parent->add( new Node( 61, 'Our history', 'https://example.test/history', 'post' ) );

$settings                 = Settings::defaults();
$settings['link_parents'] = false;
$html                     = ( new Renderer( $settings ) )->render( array( $parent, $post_node ) );

check( false === strpos( $html, 'href="https://example.test/about"' ), 'a parent entry stops linking' );
check( false !== strpos( $html, 'href="https://example.test/history"' ), 'while the child it holds still links' );
check( false !== strpos( $html, 'href="https://example.test/a"' ), 'and so does an entry with nothing under it' );

$html = ( new Renderer( Settings::defaults() ) )->render( array( $parent ) );
check( false !== strpos( $html, 'href="https://example.test/about"' ), 'and it links again by default' );

/* --- heading elements ---------------------------------------------------- */

$section         = new Node( 0, 'Pages', 'https://example.test/blog/', 'section' );
$term            = new Node( 40, 'News', 'https://example.test/news', 'term' );
$post            = new Node( 41, 'A page', 'https://example.test/a' );
$more            = new Node( 0, 'Only the first 10 are listed.', '', 'more' );

$plain = ( new Renderer( Settings::defaults() ) )->render( array( $section, $term, $post, $more ) );
check( false === strpos( $plain, '<h2' ), 'labels stay plain text until a level is chosen' );

$settings                  = Settings::defaults();
$settings['heading_level'] = 'h3';
$html                      = ( new Renderer( $settings ) )->render( array( $section, $term, $post, $more ) );

check( 2 === substr_count( $html, '<h3 ' ), 'a section and a category each become a heading' );
check( 2 === substr_count( $html, '</h3>' ), 'and each one is closed' );
check( false !== strpos( $html, '<h3 class="rapls-sitemap__heading"><a' ), 'the link stays inside the heading, so it is still a link' );

// An entry is not a heading, and neither is the truncation note — putting them
// in the outline would defeat the point of having one.
check( false === strpos( $html, 'A page</a></h3>' ), 'an ordinary entry is not made a heading' );
check( false === strpos( $html, 'listed.</span></h3>' ), 'nor is the truncation note' );

$settings['heading_level'] = 'h9';
check( false === strpos( ( new Renderer( $settings ) )->render( array( $term ) ), '<h9' ), 'an invalid level is ignored rather than emitted' );

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

// The tokens are attached with wp_add_inline_style() by Frontend\Styles, not
// printed here: WordPress asks that CSS go through the enqueue API, and a style
// element inside post content is one no theme or optimiser can see coming.
// smoke-marker.php holds the other half of this.
check( false === strpos( $html, '<style' ), 'the renderer emits no style element of its own' );
check( false === strpos( $html, 'color:#c00' ), 'and no token declarations in the markup' );
check( false !== strpos( $html, 'rapls-sitemap--t' ), 'the wrapper carries the scope class the stylesheet targets' );
check( false !== strpos( $html, '<i class="rapls-sitemap__icon fa-solid fa-star" aria-hidden="true">' ), 'an icon bullet renders as a real element' );

// A heading labels the list below it and the "and more" line apologises for it.
// Neither is an entry, so neither takes a bullet.
// All three sit at depth 0, where the icon bullet is configured, so any
// difference between them is down to their kind and nothing else.
$section = new Node( 0, 'Pages', '', 'section' );
$note    = new Node( 0, 'Only the first 10 entries are listed.', '', 'more' );
$entry   = new Node( 30, 'A page', 'https://example.test/a' );

$html = ( new Renderer( $settings ) )->render( array( $section, $note, $entry ) );
check( 1 === substr_count( $html, '<i class' ), 'only the entry itself is given a bullet' );
check( false === strpos( $html, 'item--section"><i' ), 'a section heading gets none' );
check( false === strpos( $html, 'item--more"><i' ), 'and neither does the truncation note' );
check( 0 === substr_count( $html, '<style' ), 'and none at any nesting level' );

// Untouched tokens must add nothing at all — a sitemap that was fine before
// should render byte-identically after the feature landed.
$plain = ( new Renderer( Settings::defaults() ) )->render( array( $flat ) );
check( false === strpos( $plain, 'rapls-sitemap--t' ), 'unconfigured tokens produce no scope class' );
check( false === strpos( $plain, '<i ' ), 'and no icon element' );

/* --- no CSS reaches the cached markup ------------------------------------ */

// This markup is cached per placement *and* per page, so anything embedded in
// it is stored once per entry and printed once per placement. Nothing is: the
// scope class travels with the markup, and the declarations it points at are
// attached out in Frontend\Styles. See smoke-marker.php for that half.
$settings          = Settings::defaults();
$settings['style'] = RaplsSitemap\Support\Design::sanitize( array( 'link_color' => '#c00' ) );
$html              = ( new Renderer( $settings ) )->render( array( $flat ) );

check( false !== strpos( $html, 'rapls-sitemap--t' ), 'the scope class travels with the markup' );
check( false === strpos( $html, 'color:#c00' ), 'but the declarations it points at do not' );

summary();
