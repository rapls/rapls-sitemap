<?php
/**
 * Build the JS translation files WordPress loads via wp_set_script_translations().
 *
 * Strings used by `assets/js/block.js` live in the same PO catalogue as the PHP
 * ones, but `wp.i18n` cannot read a MO file — it wants a Jed 1.x JSON payload
 * named `<domain>-<locale>-<handle>.json`. This extracts exactly the entries
 * whose references point at a `.js` file and writes that payload.
 *
 * wp-cli would do this with `wp i18n make-json`; the family assumes neither
 * wp-cli nor a build toolchain, so it is done here.
 *
 *   php bin/make-json.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

$root   = dirname( __DIR__ );
$domain = 'rapls-sitemap';

/** Script handles that consume translations, as registered in src/Frontend/. */
$handles = array( 'rapls-sitemap-block' );

/**
 * Parse a PO file into entries: msgid => array( 'msgstr' => ..., 'refs' => ... ).
 */
function parse_po( $path ) {
	$entries = array();
	$blocks  = preg_split( "/\n\n+/", trim( file_get_contents( $path ) ) );

	foreach ( $blocks as $block ) {
		$refs = array();
		if ( preg_match( '/^#: (.+)$/m', $block, $m ) ) {
			$refs = preg_split( '/\s+/', trim( $m[1] ) );
		}

		$msgid  = collect( $block, 'msgid' );
		$msgstr = collect( $block, 'msgstr' );

		if ( null === $msgid || '' === $msgid ) {
			continue;
		}

		$entries[ $msgid ] = array( 'msgstr' => (string) $msgstr, 'refs' => $refs );
	}

	return $entries;
}

/**
 * Read one keyword's value from a PO block, joining its continuation lines.
 */
function collect( $block, $keyword ) {
	if ( ! preg_match( '/^' . $keyword . ' "(.*)"$((\n".*")*)/m', $block, $m ) ) {
		return null;
	}

	$value = $m[1];
	if ( isset( $m[2] ) && '' !== $m[2] ) {
		foreach ( explode( "\n", trim( $m[2] ) ) as $line ) {
			$value .= substr( trim( $line ), 1, -1 );
		}
	}

	return str_replace(
		array( '\\n', '\\t', '\\"', '\\\\' ),
		array( "\n", "\t", '"', '\\' ),
		$value
	);
}

/* --- run ---------------------------------------------------------------- */

$written = 0;

foreach ( glob( $root . '/languages/' . $domain . '-*.po' ) as $po ) {
	$locale = preg_replace( '/^' . preg_quote( $domain, '/' ) . '-|\.po$/', '', basename( $po ) );
	$all    = parse_po( $po );

	$messages = array();
	foreach ( $all as $msgid => $entry ) {
		$from_js = false;
		foreach ( $entry['refs'] as $ref ) {
			if ( preg_match( '/\.js:\d+$/', $ref ) ) {
				$from_js = true;
				break;
			}
		}

		if ( $from_js && '' !== $entry['msgstr'] ) {
			$messages[ $msgid ] = array( $entry['msgstr'] );
		}
	}

	if ( array() === $messages ) {
		continue;
	}

	ksort( $messages );

	// Jed calls the catalogue "messages" regardless of the WordPress text
	// domain — this is the shape wp.i18n expects, not a copy of our domain.
	$messages[''] = array(
		'domain'       => 'messages',
		'lang'         => $locale,
		'plural-forms' => 'nplurals=1; plural=0;',
	);

	$payload = array(
		'translation-revision-date' => gmdate( 'Y-m-d H:i:sO' ),
		'generator'                 => 'rapls-sitemap/bin/make-json.php',
		'domain'                    => 'messages',
		'locale_data'               => array( 'messages' => $messages ),
	);

	foreach ( $handles as $handle ) {
		$target = sprintf( '%s/languages/%s-%s-%s.json', $root, $domain, $locale, $handle );
		file_put_contents( $target, json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n" );
		printf( "Wrote %s (%d strings)\n", basename( $target ), count( $messages ) - 1 );
		$written++;
	}
}

if ( 0 === $written ) {
	echo "No JS strings found — nothing written.\n";
}
