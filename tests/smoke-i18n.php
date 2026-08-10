<?php
/**
 * Translation catalogue: coverage, staleness, and the ja style guide.
 *
 * The style rules checked here are the mechanical ones from
 * https://ja.wordpress.org/team/handbook/translation/translation-style-guide/ —
 * the ones a human reviewer reliably misses on the hundredth string. Judgement
 * calls (wording, politeness level, terminology choice) still need a person.
 *
 *   php tests/smoke-i18n.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

require_once __DIR__ . '/lib/bootstrap.php';

$root      = dirname( __DIR__ );
$languages = $root . '/languages';
$domain    = 'rapls-sitemap';

/**
 * Minimal PO/POT reader: msgid => array( msgstr, refs ).
 *
 * Continuation lines are joined, and every `#:` line is read rather than the
 * first. gettext writes both forms — a long string as `msgid ""` followed by
 * quoted fragments, a long reference list wrapped over several comment lines —
 * and any tool here that reads only the first line of either sees a smaller
 * catalogue than the one on disk. That failure is silent in the direction that
 * matters: entries vanish, so a coverage test passes by checking nothing.
 * `bin/make-json.php` had exactly this bug on the reference lines.
 */
function po_entries( $path ) {
	return po_parse( (string) file_get_contents( $path ) );
}

/**
 * The same, over PO text — so the reader itself can be tested on a fixture.
 */
function po_parse( $text ) {
	$entries = array();

	foreach ( preg_split( "/\n\n+/", trim( $text ) ) as $block ) {
		$id = po_value( $block, 'msgid' );
		if ( null === $id || '' === $id ) {
			continue;
		}

		$refs = array();
		if ( preg_match_all( '/^#: (.+)$/m', $block, $lines ) ) {
			foreach ( $lines[1] as $line ) {
				$refs = array_merge( $refs, preg_split( '/\s+/', trim( $line ) ) );
			}
		}

		preg_match_all( '/^#\. (.+)$/m', $block, $notes );

		$entries[ $id ] = array(
			'msgstr' => (string) po_value( $block, 'msgstr' ),
			'refs'   => $refs,
			'notes'  => implode( ' ', $notes[1] ),
		);
	}

	return $entries;
}

/**
 * One keyword's value from a PO block, with its continuation lines joined.
 */
function po_value( $block, $keyword ) {
	if ( ! preg_match( '/^' . $keyword . ' "(.*)"$((?:\n".*")*)/m', $block, $m ) ) {
		return null;
	}

	$value = $m[1];

	foreach ( preg_split( '/\n/', trim( (string) ( $m[2] ?? '' ) ) ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$value .= substr( $line, 1, -1 );
		}
	}

	return po_unescape( $value );
}

function po_unescape( $text ) {
	return str_replace( array( '\\n', '\\t', '\\"', '\\\\' ), array( "\n", "\t", '"', '\\' ), $text );
}

/* --- the catalogue exists ----------------------------------------------- */

$pot_path = $languages . '/' . $domain . '.pot';
$po_path  = $languages . '/' . $domain . '-ja.po';
$mo_path  = $languages . '/' . $domain . '-ja.mo';

check( file_exists( $pot_path ), 'the POT template exists' );
check( file_exists( $po_path ), 'the Japanese catalogue exists' );
check( file_exists( $mo_path ), 'the compiled MO exists (run msgfmt after editing the PO)' );

if ( ! file_exists( $pot_path ) || ! file_exists( $po_path ) ) {
	summary();
}

$pot = po_entries( $pot_path );
$po  = po_entries( $po_path );

check( array() !== $pot, 'the POT has entries' );

/* --- the reader survives a wrapped catalogue ---------------------------- */

// Everything below counts entries, so a reader that skips the wrapped ones
// reports full coverage of a smaller catalogue than the one on disk. The
// catalogue is unwrapped today; nothing stops the next `msgmerge` from
// wrapping it again.
$wrapped = po_parse(
	"#: src/A.php:1 src/B.php:2\n"
	. "#: assets/js/block.js:3\n"
	. "msgid \"\"\n\"A string long enough that gettext \"\n\"wraps it.\"\n"
	. "msgstr \"\"\n\"折り返された訳文\"\n"
);

check( isset( $wrapped['A string long enough that gettext wraps it.'] ), 'a wrapped msgid is read as one string' );
check( '折り返された訳文' === ( $wrapped['A string long enough that gettext wraps it.']['msgstr'] ?? '' ), 'and so is a wrapped msgstr' );
check(
	array( 'src/A.php:1', 'src/B.php:2', 'assets/js/block.js:3' ) === ( $wrapped['A string long enough that gettext wraps it.']['refs'] ?? array() ),
	'a reference list wrapped over two comment lines is read whole'
);

/* --- coverage and staleness --------------------------------------------- */

$untranslated = array();
foreach ( $pot as $msgid => $entry ) {
	if ( ! isset( $po[ $msgid ] ) || '' === $po[ $msgid ]['msgstr'] ) {
		$untranslated[] = $msgid;
	}
}

check(
	array() === $untranslated,
	sprintf( 'every one of the %d source strings is translated', count( $pot ) ),
	'missing: ' . implode( ' | ', array_slice( $untranslated, 0, 5 ) )
);

$stale = array_diff( array_keys( $po ), array_keys( $pot ) );
check(
	array() === $stale,
	'the catalogue has no entries the source no longer uses',
	'stale: ' . implode( ' | ', array_slice( $stale, 0, 5 ) ) . ' (re-run bin/make-pot.php)'
);

/* --- format specifiers survive translation ------------------------------ */

$broken = array();
foreach ( $po as $msgid => $entry ) {
	if ( '' === $entry['msgstr'] ) {
		continue;
	}

	preg_match_all( '/%[0-9]*\$?[sd]/', $msgid, $source );
	preg_match_all( '/%[0-9]*\$?[sd]/', $entry['msgstr'], $target );

	sort( $source[0] );
	sort( $target[0] );

	if ( $source[0] !== $target[0] ) {
		$broken[] = $msgid;
	}
}

check( array() === $broken, 'placeholders match between source and translation', implode( ' | ', $broken ) );

/* --- ja.wordpress.org style guide, the mechanical rules ------------------ */

// Ranges: hiragana, katakana (including the ー prolonged mark), and kanji.
$jp = '\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}';

$rules = array(
	'preferred kana spellings are used (ください / すべて / すでに)' => '/(下さい|全て|既に)/u',
	'symbols and punctuation are half-width (no ！ ？ （ ）)'        => '/[！？（）]/u',
	'commas and periods are 、and 。(no ，．)'                       => '/[，．]/u',
	'a half-width space separates Latin letters from Japanese'       => '/([A-Za-z][' . $jp . ']|[' . $jp . '][A-Za-z])/u',
);

foreach ( $rules as $label => $pattern ) {
	$offenders = array();

	foreach ( $po as $msgid => $entry ) {
		if ( '' === $entry['msgstr'] ) {
			continue;
		}

		// A date format is not prose — "Y年" is the correct translation of "Y",
		// and the spacing rule would flag it. The translator note is what marks
		// these, so a new one is exempt automatically.
		if ( false !== stripos( $entry['notes'], 'date format' ) ) {
			continue;
		}

		// Placeholders are removed before the spacing rule runs. `%s` is a
		// Latin letter on the page but not in the rendered string, and what it
		// expands to — a number in 先頭の%s件, a link elsewhere — decides
		// whether a space belongs there. Nothing static can tell those apart,
		// so the rule declines to guess rather than reporting either way.
		$subject = preg_replace( '/%[0-9]*\$?[sd]/', '', $entry['msgstr'] );

		if ( preg_match( $pattern, $subject ) ) {
			$offenders[] = $entry['msgstr'];
		}
	}

	check( array() === $offenders, $label, implode( ' | ', array_slice( $offenders, 0, 3 ) ) );
}

/* --- every source string declares our text domain ----------------------- */

$wrong_domain = array();
foreach ( glob( $root . '/src/*/*.php' ) as $path ) {
	if ( preg_match_all( "/\b(?:esc_html__|esc_attr__|__)\(\s*'(?:[^'\\\\]|\\\\.)*'\s*,\s*'([^']+)'/", (string) file_get_contents( $path ), $m ) ) {
		foreach ( $m[1] as $found ) {
			if ( $domain !== $found ) {
				$wrong_domain[] = basename( $path ) . ": '{$found}'";
			}
		}
	}
}

check( array() === $wrong_domain, 'every translator call uses the rapls-sitemap text domain', implode( ' | ', $wrong_domain ) );

/* --- block.json metadata is translated too ------------------------------ */

// WordPress translates these through the plugin's text domain at registration,
// so they need to be in the catalogue even though no __() call mentions them.
// Nothing breaks loudly when they are missing — the block just shows its
// English title in the editor, which is easy to ship without noticing.
foreach ( glob( $root . '/blocks/*/block.json' ) as $block_file ) {
	$block = json_decode( (string) file_get_contents( $block_file ), true );

	foreach ( array( 'title', 'description' ) as $field ) {
		if ( empty( $block[ $field ] ) ) {
			continue;
		}

		check(
			isset( $pot[ $block[ $field ] ] ),
			sprintf( '%s: %s is in the catalogue', basename( dirname( $block_file ) ), $field ),
			$block[ $field ]
		);
	}
}

/* --- the editor script gets its Jed payload ----------------------------- */

$json_path = $languages . '/' . $domain . '-ja-' . $domain . '-block.json';
check( file_exists( $json_path ), 'the block editor has a JS translation file (bin/make-json.php)' );

if ( file_exists( $json_path ) ) {
	$json = json_decode( (string) file_get_contents( $json_path ), true );
	check( is_array( $json ) && isset( $json['locale_data']['messages'] ), 'the JS translation file is valid Jed data' );

	$js_strings = array();
	foreach ( $pot as $msgid => $entry ) {
		foreach ( $entry['refs'] as $ref ) {
			if ( preg_match( '/\.js:\d+$/', $ref ) ) {
				$js_strings[] = $msgid;
				break;
			}
		}
	}

	$missing_js = array();
	foreach ( $js_strings as $msgid ) {
		if ( ! isset( $json['locale_data']['messages'][ $msgid ] ) ) {
			$missing_js[] = $msgid;
		}
	}

	check(
		array() === $missing_js,
		sprintf( 'all %d editor strings reached the JS payload', count( $js_strings ) ),
		implode( ' | ', $missing_js )
	);
}

summary();
