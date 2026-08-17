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

// Every unit the validator accepts has to survive a trip through the settings
// screen. One it accepts but the screen does not offer would fall back to the
// select's first entry, and the next save would rewrite the value.
$strays = array();

foreach ( array( 'px', 'rem', 'em', '%', 'pt', 'vw', 'ch' ) as $unit ) {
	$kept = Design::sanitize( array( 'font_size' => '50' . $unit ) )['font_size'];
	if ( '50' . $unit !== $kept ) {
		continue;
	}

	list( , $split ) = Design::split( $kept );

	if ( ! isset( RaplsSitemap\Admin\SettingsPage::length_units( false, $split )[ $split ] ) ) {
		$strays[] = $unit;
	}
}

check(
	array() === $strays,
	'every accepted unit is one the settings screen can show',
	'no option for: ' . implode( ', ', $strays )
);

// The common units lead the list; an unusual one is appended rather than
// replacing anything, so the menu stays short for the people who never use it.
check( 5 === count( RaplsSitemap\Admin\SettingsPage::length_units( false, 'px' ) ), 'an ordinary unit adds nothing to the menu' );
check( 6 === count( RaplsSitemap\Admin\SettingsPage::length_units( false, 'vw' ) ), 'and an unusual one is added rather than swapped in' );

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

/* --- columns -------------------------------------------------------------- */

// A token rather than a preset: it composes with whichever design is on, which
// is the whole point of the tokens sitting on top of them.
$columns = Design::style_block( Design::sanitize( array( 'columns' => '3' ) ) );

check( false !== strpos( $columns, 'column-count:3' ), 'a column count reaches the declaration' );
check( false !== strpos( $columns, 'column-gap:2.5rem' ), 'and brings a gap with it — two columns of links touching read as one column of nonsense' );
check( false !== strpos( $columns, 'break-inside:avoid' ), 'and stops a category being split down the middle of the page' );

// Four presets lay the top-level list out as a grid or a flex row, and CSS
// multi-column has no effect on either. A token that was set and visibly did
// nothing would be worse than one that overrules the preset.
check( false !== strpos( $columns, 'display:block' ), 'and takes the list back from a preset that made it a grid' );

$gapped = Design::style_block( Design::sanitize( array( 'columns' => '2', 'column_gap' => '4rem' ) ) );
check( false !== strpos( $gapped, 'column-gap:4rem' ), 'a gap of its own wins over the default' );

check( '' === Design::style_block( Design::sanitize( array( 'column_gap' => '4rem' ) ) ), 'a gap with no columns is not a layout, so nothing is emitted' );
check( '1' === Design::sanitize( array( 'columns' => '1' ) )['columns'], 'one column is a real answer on a design that would otherwise flow' );
check( '' === Design::sanitize( array( 'columns' => '200' ) )['columns'], 'and a number nobody could read is no answer at all' );
check( '' === Design::sanitize( array( 'columns' => '0' ) )['columns'], 'nor is none' );

/* --- bullets ------------------------------------------------------------- */

$css = Design::style_block( Design::sanitize( array( 'marker' => 'emoji', 'marker_text' => '🍀' ) ) );
check( false !== strpos( $css, 'content:"🍀"' ), 'an emoji bullet becomes a content string' );
check( false !== strpos( $css, 'position:static' ), 'and first flattens the geometry a preset ornament used' );

$style = Design::sanitize( array( 'marker' => 'icon', 'marker_icon' => 'fa-solid fa-star' ) );
check( 'fa-solid fa-star' === Design::icon_class( $style, 0 ), 'the icon class is exposed for the top level' );
check( '' === Design::icon_class( $style, 1 ), 'but not for a level that did not ask for icons' );
check( '' === Design::icon_class( Design::sanitize( array( 'marker' => 'disc' ) ), 0 ), 'and not when the bullet is not an icon' );

/* --- additional CSS ------------------------------------------------------ */


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

/* --- the block's three files agree on its attributes --------------------- */

// block.json declares them, block.js draws a control for each, and
// Block::to_atts translates them into settings. An attribute missing from any
// one of the three is a control that saves nothing, or a saved value that never
// reaches the renderer — both silent.
$root     = dirname( __DIR__ );
$json     = json_decode( (string) file_get_contents( $root . '/blocks/sitemap/block.json' ), true );
$block_js = (string) file_get_contents( $root . '/assets/js/block.js' );
$block_php = (string) file_get_contents( $root . '/src/Frontend/Block.php' );

check( is_array( $json ) && isset( $json['attributes'] ), 'block.json parses and declares attributes' );

$no_control = array();
$no_mapping = array();

foreach ( array_keys( $json['attributes'] ) as $attribute ) {
	if ( false === strpos( $block_js, "'" . $attribute . "'" ) && false === strpos( $block_js, 'atts.' . $attribute ) ) {
		$no_control[] = $attribute;
	}
	if ( false === strpos( $block_php, "'" . $attribute . "'" ) ) {
		$no_mapping[] = $attribute;
	}
}

check( array() === $no_control, sprintf( 'all %d block attributes have an editor control', count( $json['attributes'] ) ), implode( ', ', $no_control ) );
check( array() === $no_mapping, 'and all of them are mapped onto settings in PHP', implode( ', ', $no_mapping ) );

// Every attribute's default has to MEAN "not set", or a block dropped on a page
// overrides the site settings before anybody touches it. Booleans are the trap:
// `"default": false` is indistinguishable from "the author switched this off",
// so a new block silently cancelled a published date the site had asked for.
// Empty string is the inherit value; -1 is it for the three numbers where 0
// already means something.
$forcing = array();
foreach ( $json['attributes'] as $attribute => $schema ) {
	$default = $schema['default'] ?? null;

	if ( 'string' === ( $schema['type'] ?? '' ) && '' === $default ) {
		continue;
	}

	if ( in_array( $schema['type'] ?? '', array( 'integer', 'number' ), true ) && -1 === $default ) {
		continue;
	}

	$forcing[] = $attribute;
}

check(
	array() === $forcing,
	'every block attribute defaults to a value meaning "use the site setting"',
	implode( ', ', $forcing )
);

// ...and the switches are therefore selects. A ToggleControl cannot express the
// third state, so one in this sidebar would be a switch that lies.
check(
	false === strpos( $block_js, 'ToggleControl' ),
	'so the editor draws no toggle, which could only offer two of the three'
);

// block.json is the single declaration of the block's metadata. A copy in the
// editor script wins over the JSON, so changing the icon there would do
// nothing — the same reason Block::NAME was deleted.
$duplicated = array();
foreach ( array( 'title', 'description', 'category', 'icon', 'supports' ) as $key ) {
	if ( preg_match( '/^\t\t' . $key . ':/m', $block_js ) ) {
		$duplicated[] = $key;
	}
}

check( array() === $duplicated, 'the editor script does not restate block.json metadata', implode( ', ', $duplicated ) );

/* --- the stylesheet parses ------------------------------------------------ */

// A stray character in a selector is invisible: the browser drops the rule and
// the page still renders, just without whatever that rule did. One went
// unnoticed for several commits after a search-and-replace ate a selector.
$css   = (string) file_get_contents( $root . '/assets/css/rapls-sitemap.css' );
$code  = (string) preg_replace_callback( '#/\*.*?\*/#s', function ( $m ) {
	return preg_replace( '/[^\n]/', ' ', $m[0] );
}, $css );

$depth     = 0;
$unmatched = 0;
foreach ( str_split( $code ) as $char ) {
	if ( '{' === $char ) {
		$depth++;
	} elseif ( '}' === $char ) {
		$depth--;
		if ( $depth < 0 ) {
			$unmatched++;
			$depth = 0;
		}
	}
}

check( 0 === $unmatched, 'the stylesheet has no unmatched closing brace' );
check( 0 === $depth, 'and no unclosed rule' );

// Every selector must start with a class, an element, or a pseudo — anything
// else is debris from an edit.
$debris = array();
foreach ( preg_split( '/\R/', $code ) as $number => $line ) {
	$line = trim( $line );
	if ( '' === $line || 0 === strpos( $line, '@' ) || preg_match( '/^[a-z-]+\s*:/i', $line ) ) {
		continue;
	}
	if ( preg_match( '/[,{]\s*$/', $line ) && ! preg_match( '/^[.#a-zA-Z:\[*]/', $line ) ) {
		$debris[] = ( $number + 1 ) . ': ' . $line;
	}
}

check( array() === $debris, 'every selector line starts like a selector', implode( ' | ', $debris ) );

/* --- headings reach through to the preset styling ------------------------ */

// A heading element sits between the item and its link, so a preset selector
// written as `item > link` stops matching. Each one needs a twin that goes
// through the heading, or turning headings on silently unstyles the sitemap.
$orphans = array();
foreach ( preg_split( '/\R/', $code ) as $line ) {
	$line = trim( rtrim( trim( $line ), ',{' ) );

	// A twin ends the same way, so it would otherwise be asked for a twin of
	// its own.
	if ( ! preg_match( '/> \.rapls-sitemap__(link|label)$/', $line ) || false !== strpos( $line, '__heading >' ) ) {
		continue;
	}

	$twin = preg_replace( '/> \.rapls-sitemap__(link|label)$/', '> .rapls-sitemap__heading > .rapls-sitemap__$1', $line );
	if ( false === strpos( $code, $twin ) ) {
		$orphans[] = $line;
	}
}

check(
	array() === $orphans,
	'every selector reaching a label has a twin that goes through a heading',
	implode( ' | ', array_slice( $orphans, 0, 3 ) )
);

// The same shape of problem for `link_headings`: a heading rendered as text is
// a __label, so a rule that only names __link stops applying and the heading
// silently loses its styling.
$unpaired = array();
foreach ( preg_split( '/\R/', $code ) as $line ) {
	$line = trim( rtrim( trim( $line ), ',{' ) );

	if ( ! preg_match( '/> \.rapls-sitemap__link$/', $line ) ) {
		continue;
	}

	$twin = substr( $line, 0, -strlen( '__link' ) ) . '__label';
	if ( false === strpos( $code, $twin ) ) {
		$unpaired[] = $line;
	}
}

check(
	array() === $unpaired,
	'and every one has a __label counterpart, for headings rendered as text',
	implode( ' | ', array_slice( $unpaired, 0, 3 ) )
);

/* --- every preset is registered in all four places ----------------------- */

$css_file = (string) file_get_contents( $root . '/assets/css/rapls-sitemap.css' );
$admin    = (string) file_get_contents( $root . '/src/Admin/SettingsPage.php' );

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

/* --- the count nobody remembers to update ------------------------------- */

/*
 * `readme.txt` sells the presets by number, and the number kept going stale.
 * The stylesheet header said "Twelve presets" through two rounds of additions,
 * and `Settings::DESIGNS` said "the other twelve" until a submission audit
 * caught it. Both of those comments now say nothing about the count, but the
 * readme still needs to, because a directory listing is a shop window.
 *
 * So the readme's number is checked against the array rather than remembered.
 * The "no styling at all" entry is `none`, which is why it is one fewer.
 */
$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
$styled = count( Settings::DESIGNS ) - 1;

check(
	1 === preg_match( '/(\d+) CSS presets/', $readme, $m ),
	'readme.txt states how many presets there are'
);
check(
	isset( $m[1] ) && (int) $m[1] === $styled,
	'and the number matches the presets that actually exist',
	sprintf( 'readme says %s, DESIGNS has %d styled', $m[1] ?? '(none)', $styled )
);

summary();
