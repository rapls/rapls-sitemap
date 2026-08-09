<?php
/**
 * Design tokens: validation, CSS emission, and preset bookkeeping.
 *
 * Token values reach a stylesheet, so the validation here is a security
 * boundary as much as a usability one — a value that could carry a `;` or a
 * `}` could append declarations nobody wrote.
 *
 *   php tests/smoke-design.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Support\Design;
use RaplsSitemap\Support\Settings;

/* --- defaults are inert -------------------------------------------------- */

$defaults = Design::defaults();
check( ! Design::is_configured( $defaults ), 'untouched tokens count as unconfigured' );
check( '' === Design::scope_class( $defaults ), 'and produce no scope class' );
check( '' === Design::style_block( $defaults ), 'and no stylesheet at all' );

/* --- lengths ------------------------------------------------------------- */

$clean = Design::sanitize( array( 'font_size' => '18' ) );
check( '18px' === $clean['font_size'], 'a bare number is read as pixels' );

check( '1.5rem' === Design::sanitize( array( 'font_size' => '1.5rem' ) )['font_size'], 'a unit is kept' );
check( '90%' === Design::sanitize( array( 'font_size' => '90%' ) )['font_size'], 'percentages are accepted' );
check( '' === Design::sanitize( array( 'font_size' => '18px; color:red' ) )['font_size'], 'a smuggled declaration is rejected outright' );
check( '' === Design::sanitize( array( 'font_size' => 'calc(1em + 2px)' ) )['font_size'], 'calc() is not on the allow list' );

check( '1.7' === Design::sanitize( array( 'line_height' => '1.7' ) )['line_height'], 'line height may be unitless' );

/* --- the number-and-unit controls ---------------------------------------- */

// The settings screen posts a spinner value and a unit select separately, so a
// user can drag the arrows instead of typing "1.25em".
$clean = Design::sanitize( array( 'font_size' => '99px', 'font_size_value' => '20', 'font_size_unit' => 'rem' ) );
check( '20rem' === $clean['font_size'], 'the split controls are recombined, and win over a plain value' );

check( '18px' === Design::sanitize( array( 'font_size_value' => '18', 'font_size_unit' => 'px' ) )['font_size'], 'a px length round-trips' );
check( '1.6' === Design::sanitize( array( 'line_height_value' => '1.6', 'line_height_unit' => '' ) )['line_height'], 'line height stays unitless when no unit is picked' );
check( '' === Design::sanitize( array( 'font_size_value' => '', 'font_size_unit' => 'px' ) )['font_size'], 'clearing the number clears the token, unit notwithstanding' );
check( '' === Design::sanitize( array( 'font_size_value' => '20', 'font_size_unit' => '; color:red' ) )['font_size'], 'a forged unit is rejected with the whole value' );

// The plain form has to keep working for filters and programmatic updates.
check( '2em' === Design::sanitize( array( 'indent' => '2em' ) )['indent'], 'a single string is still accepted' );

check( array( '1.25', 'em' ) === Design::split( '1.25em' ), 'a stored length splits back for the UI' );
check( array( '18', 'px' ) === Design::split( '18px' ), 'and so does a pixel value' );
check( array( '1.7', '' ) === Design::split( '1.7' ), 'a unitless number splits with an empty unit' );
check( array( '', '' ) === Design::split( '' ), 'an unset length splits to nothing' );

/* --- colours ------------------------------------------------------------- */

foreach ( array( '#fff', '#0073aa', '#0073aaff', 'rebeccapurple', 'currentColor', 'transparent', 'rgb(1, 2, 3)', 'rgba(1,2,3,.5)', 'hsl(210 50% 40%)', 'var(--wp--preset--color--primary)' ) as $ok ) {
	check( $ok === Design::sanitize( array( 'link_color' => $ok ) )['link_color'], sprintf( '"%s" is accepted as a colour', $ok ) );
}

foreach ( array( 'red;}body{display:none', 'url(evil.css)', '#12345678901', 'expression(alert(1))', '}' ) as $bad ) {
	check( '' === Design::sanitize( array( 'link_color' => $bad ) )['link_color'], sprintf( '"%s" is rejected', $bad ) );
}

/* --- glyphs go into a CSS content string --------------------------------- */

check( '🍀' === Design::sanitize( array( 'marker_text' => '🍀' ) )['marker_text'], 'an emoji bullet survives' );
check( false === strpos( Design::sanitize( array( 'marker_text' => '"};x{y:z' ) )['marker_text'], '"' ), 'a quote cannot reach the content string' );
check( false === strpos( Design::sanitize( array( 'marker_text' => 'a}b{c' ) )['marker_text'], '}' ), 'braces cannot reach it either' );

/* --- icon classes -------------------------------------------------------- */

check( 'fa-solid fa-angle-right' === Design::sanitize( array( 'marker_icon' => 'fa-solid fa-angle-right' ) )['marker_icon'], 'an icon class is kept' );
check( '' === Design::sanitize( array( 'marker_icon' => 'x" onload="alert(1)' ) )['marker_icon'], 'an attribute break-out is rejected' );

/* --- enums --------------------------------------------------------------- */

check( 'hover' === Design::sanitize( array( 'underline' => 'hover' ) )['underline'], 'a known underline mode is kept' );
check( 'default' === Design::sanitize( array( 'underline' => 'sideways' ) )['underline'], 'an unknown one falls back' );
check( 'default' === Design::sanitize( array( 'marker' => 'lasers' ) )['marker'], 'an unknown bullet falls back' );

/* --- emission: only what was set ----------------------------------------- */

$style = Design::sanitize( array( 'link_color' => '#c00' ) );
$css   = Design::style_block( $style );

check( '' !== $css, 'a configured token produces a stylesheet' );
check( false !== strpos( $css, 'color:#c00' ), 'the value reaches the declaration' );
check( false === strpos( $css, 'font-size' ), 'unset tokens produce no declaration at all' );
check( false === strpos( $css, 'inherit' ), 'and no "inherit" fallback that would fight the theme' );

$scope = Design::scope_class( $style );
check( '' !== $scope, 'a configured token produces a scope class' );
check( false !== strpos( $css, '.' . $scope ), 'every rule is scoped to it' );
check( $scope === Design::scope_class( Design::sanitize( array( 'link_color' => '#c00' ) ) ), 'the scope class is deterministic, so the render cache still hits' );
check( $scope !== Design::scope_class( Design::sanitize( array( 'link_color' => '#c01' ) ) ), 'and differs when the tokens differ' );

/* --- bullets ------------------------------------------------------------- */

$css = Design::style_block( Design::sanitize( array( 'marker' => 'emoji', 'marker_text' => '🍀' ) ) );
check( false !== strpos( $css, 'content:"🍀"' ), 'an emoji bullet becomes a content string' );
check( false !== strpos( $css, 'position:static' ), 'and first flattens the geometry a preset ornament used' );

$style = Design::sanitize( array( 'marker' => 'icon', 'marker_icon' => 'fa-solid fa-star' ) );
check( 'fa-solid fa-star' === Design::icon_class( $style, 0 ), 'the icon class is exposed for the top level' );
check( '' === Design::icon_class( $style, 1 ), 'but not for a level that did not ask for icons' );
check( '' === Design::icon_class( Design::sanitize( array( 'marker' => 'disc' ) ), 0 ), 'and not when the bullet is not an icon' );

/* --- additional CSS ------------------------------------------------------ */

check( '.rapls-sitemap a { color: red }' === Settings::sanitize_css( '.rapls-sitemap a { color: red }' ), 'ordinary CSS passes through' );
check( false === strpos( Settings::sanitize_css( 'a{} </style><script>alert(1)</script>' ), '</style' ), 'the style element cannot be closed' );
check( false === strpos( Settings::sanitize_css( 'a{} <script>x' ), '<script' ), 'nor a script opened' );
check( false !== strpos( Settings::sanitize_css( '.a > .b { color: red }' ), '>' ), 'the child combinator survives' );
check( false !== strpos( Settings::sanitize_css( '@media (max-width: 40em) { .a { color: red } }' ), '@media' ), 'at-rules survive' );
check( false === strpos( Settings::sanitize_css( 'a{} <!-- x -->' ), '<!--' ), 'comment delimiters are removed' );

/* --- every emoji the picker offers must survive being saved -------------- */

// The palette writes straight into the bullet field, and that field ends up in
// a CSS `content` string. A glyph the sanitizer trims or rejects would be a
// picker that silently does something other than what was clicked.
$mangled = array();
$dupes   = array();
$seen    = array();
$total   = 0;
$palette = RaplsSitemap\Admin\SettingsPage::emoji_palette();

foreach ( $palette as $group => $glyphs ) {
	foreach ( $glyphs as $glyph ) {
		$total++;

		if ( $glyph !== Design::sanitize( array( 'marker_text' => $glyph ) )['marker_text'] ) {
			$mangled[] = $group . ': ' . $glyph;
		}

		// One tab per glyph: the same emoji in two tabs is a wasted slot and
		// makes the picker feel arbitrary.
		if ( isset( $seen[ $glyph ] ) ) {
			$dupes[] = $glyph . ' (' . $seen[ $glyph ] . ' / ' . $group . ')';
		}
		$seen[ $glyph ] = $group;
	}
}

check( $total > 0, 'the emoji palette is not empty' );
check( array() === $mangled, sprintf( 'all %d palette emoji round-trip through the sanitizer', $total ), implode( ' | ', $mangled ) );
check( array() === $dupes, 'no emoji appears in two tabs', implode( ' | ', $dupes ) );

// Tabs only earn their complexity if there are several, and a tab with two
// entries in it looks broken next to one with twenty.
$thin = array();
foreach ( $palette as $group => $glyphs ) {
	if ( count( $glyphs ) < 8 ) {
		$thin[] = $group . ' (' . count( $glyphs ) . ')';
	}
}

check( count( $palette ) >= 2, 'there is more than one tab' );
check( array() === $thin, 'every tab is worth switching to', implode( ' | ', $thin ) );

// A note keyed to a tab that no longer exists would never be shown, and the
// caveat it carries — flags do not render on Windows — is one a user needs
// before choosing, not after.
$orphans = array_diff( array_keys( RaplsSitemap\Admin\SettingsPage::emoji_notes() ), array_keys( $palette ) );
check( array() === $orphans, 'every palette note belongs to a tab that exists', implode( ' | ', $orphans ) );

/* --- every preset is registered in all four places ----------------------- */

$root      = dirname( __DIR__ );
$css_file  = (string) file_get_contents( $root . '/assets/css/rapls-sitemap.css' );
$admin     = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );
$block_js  = (string) file_get_contents( $root . '/assets/js/block.js' );

$missing = array( 'css' => array(), 'admin' => array(), 'js' => array() );

foreach ( Settings::DESIGNS as $design ) {
	if ( 'none' !== $design && false === strpos( $css_file, '.rapls-sitemap--' . $design . ' ' ) ) {
		$missing['css'][] = $design;
	}
	if ( false === strpos( $admin, "'" . $design . "'" ) ) {
		$missing['admin'][] = $design;
	}
	if ( false === strpos( $block_js, "value: '" . $design . "'" ) ) {
		$missing['js'][] = $design;
	}
}

check( array() === $missing['css'], sprintf( 'all %d presets have styles', count( Settings::DESIGNS ) - 1 ), implode( ', ', $missing['css'] ) );
check( array() === $missing['admin'], 'every preset is offered on the settings screen', implode( ', ', $missing['admin'] ) );
check( array() === $missing['js'], 'every preset is offered in the block sidebar', implode( ', ', $missing['js'] ) );

summary();
