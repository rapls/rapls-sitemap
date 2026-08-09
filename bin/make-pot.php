<?php
/**
 * Build languages/rapls-sitemap.pot from the source tree.
 *
 * The family ships no build toolchain and wp-cli is not assumed, so extraction
 * is done here: PHP through the tokenizer (which cannot be fooled by a
 * translator function name appearing inside a string or comment), and JS
 * through a regex, which is adequate for the deliberately plain block script.
 *
 *   php bin/make-pot.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

$root   = dirname( __DIR__ );
$domain = 'rapls-sitemap';

/**
 * Translator functions => how many leading arguments are msgids.
 *
 * `_x()` and `_n()` are absent on purpose: they carry a msgctxt / plural form
 * that this extractor does not yet emit, and a POT that silently drops those
 * would be worse than one that never saw the string. Add msgctxt output here
 * before using them in `src/`; disambiguate with a `translators:` comment until
 * then, which is emitted as a `#.` line.
 */
$targets = array(
	'__'         => 1,
	'esc_html__' => 1,
	'esc_attr__' => 1,
	'_e'         => 1,
	'esc_html_e' => 1,
	'esc_attr_e' => 1,
);

/**
 * All entries, keyed by msgid.
 *
 * @var array<string,array{refs:string[],comments:string[]}>
 */
$entries = array();

/**
 * Record one string.
 */
function record( $msgid, $ref, $comment = '' ) {
	global $entries;

	if ( '' === $msgid ) {
		return;
	}

	if ( ! isset( $entries[ $msgid ] ) ) {
		$entries[ $msgid ] = array( 'refs' => array(), 'comments' => array() );
	}

	$entries[ $msgid ]['refs'][] = $ref;

	if ( '' !== $comment && ! in_array( $comment, $entries[ $msgid ]['comments'], true ) ) {
		$entries[ $msgid ]['comments'][] = $comment;
	}
}

/**
 * Every file under a directory with one of the given extensions.
 */
function files( $dir, array $extensions ) {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out      = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
			$out[] = $file->getPathname();
		}
	}

	sort( $out );
	return $out;
}

/**
 * Extract from one PHP file using the tokenizer.
 */
function scan_php( $path, $relative, array $targets, $domain ) {
	$tokens = token_get_all( file_get_contents( $path ) );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $targets[ $token[1] ] ) ) {
			continue;
		}

		// Skip method calls and declarations: `$obj->__(`, `Foo::__(`, `function __(`.
		$prev = prev_significant( $tokens, $i );
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		$next = next_significant( $tokens, $i );
		if ( '(' !== $next['token'] ) {
			continue;
		}

		$args = read_args( $tokens, $next['index'] );
		if ( null === $args ) {
			continue;
		}

		// The domain is always the trailing argument; a mismatch means the
		// string belongs to someone else and must not enter our catalogue.
		$last = end( $args );
		if ( $last !== $domain ) {
			continue;
		}

		$comment = translator_comment( $tokens, $i );

		for ( $n = 0; $n < $targets[ $token[1] ]; $n++ ) {
			if ( isset( $args[ $n ] ) && is_string( $args[ $n ] ) ) {
				record( $args[ $n ], $relative . ':' . $token[2], $comment );
			}
		}
	}
}

/**
 * The previous non-whitespace, non-comment token.
 */
function prev_significant( array $tokens, $index ) {
	for ( $i = $index - 1; $i >= 0; $i-- ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $tokens[ $i ];
	}
	return null;
}

/**
 * The next significant token, with its index.
 */
function next_significant( array $tokens, $index ) {
	$count = count( $tokens );

	for ( $i = $index + 1; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return array(
			'token' => is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ],
			'index' => $i,
		);
	}

	return array( 'token' => '', 'index' => $count );
}

/**
 * Read a call's arguments, starting at its opening parenthesis.
 *
 * Literal strings come back as PHP strings; anything else (a variable, a
 * concatenation, a nested call) comes back as null so the caller can tell the
 * difference between "empty string" and "not extractable".
 *
 * @return array|null Arguments, or null if the call never closes.
 */
function read_args( array $tokens, $open ) {
	$count = count( $tokens );
	$depth = 0;
	$args  = array();
	$parts = array();

	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$text  = is_array( $token ) ? $token[1] : $token;

		if ( '(' === $text || '[' === $text ) {
			$depth++;
			if ( 1 === $depth ) {
				continue;
			}
		}

		if ( ')' === $text || ']' === $text ) {
			$depth--;
			if ( 0 === $depth ) {
				$args[] = collapse( $parts );
				return $args;
			}
		}

		if ( 1 === $depth && ',' === $text ) {
			$args[] = collapse( $parts );
			$parts  = array();
			continue;
		}

		if ( $depth >= 1 && is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		if ( $depth >= 1 ) {
			$parts[] = $token;
		}
	}

	return null;
}

/**
 * Turn one argument's tokens into a literal string, or null if it is not one.
 */
function collapse( array $parts ) {
	if ( array() === $parts ) {
		return null;
	}

	$out = '';
	foreach ( $parts as $part ) {
		if ( ! is_array( $part ) || T_CONSTANT_ENCAPSED_STRING !== $part[0] ) {
			// A '.' between two literals is still extractable; anything else
			// (variable, function call) is not.
			if ( '.' === $part ) {
				continue;
			}
			return null;
		}
		$out .= unquote( $part[1] );
	}

	return $out;
}

/**
 * Strip the quotes from a PHP string literal and resolve its escapes.
 */
function unquote( $literal ) {
	$quote = substr( $literal, 0, 1 );
	$body  = substr( $literal, 1, -1 );

	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}

	return stripcslashes( $body );
}

/**
 * The `translators:` comment immediately preceding a call, if any.
 */
function translator_comment( array $tokens, $index ) {
	for ( $i = $index - 1; $i >= 0 && $i > $index - 40; $i-- ) {
		if ( ! is_array( $tokens[ $i ] ) ) {
			continue;
		}

		if ( in_array( $tokens[ $i ][0], array( T_COMMENT, T_DOC_COMMENT ), true )
			&& false !== stripos( $tokens[ $i ][1], 'translators:' ) ) {
			$text = preg_replace( '#^/\*+|\*+/$|^//#', '', $tokens[ $i ][1] );
			return trim( preg_replace( '/\s+/', ' ', $text ) );
		}
	}

	return '';
}

/**
 * Extract from one JS file.
 */
function scan_js( $path, $relative, $domain ) {
	$source = file_get_contents( $path );
	$lines  = explode( "\n", $source );

	foreach ( $lines as $number => $line ) {
		if ( ! preg_match_all( "/\b__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'" . preg_quote( $domain, '/' ) . "'\s*\)/", $line, $matches ) ) {
			continue;
		}

		foreach ( $matches[1] as $match ) {
			record( str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $match ), $relative . ':' . ( $number + 1 ) );
		}
	}
}

/**
 * Extract the translatable fields from one block.json.
 *
 * WordPress translates these through the plugin's text domain when the block
 * registers, so they belong in the catalogue even though no `__()` call
 * mentions them. Leaving them out is easy to do and hard to notice: the block
 * simply shows its English title in the editor.
 *
 * The field list is WordPress's own i18n schema for block metadata, minus the
 * parts this plugin does not use (styles, variations).
 *
 * @param string $path     Absolute path to a block.json.
 * @param string $relative Path recorded in the POT.
 */
function scan_block_json( $path, $relative ) {
	$data = json_decode( (string) file_get_contents( $path ), true );

	if ( ! is_array( $data ) ) {
		return;
	}

	foreach ( array( 'title', 'description' ) as $field ) {
		if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
			record( $data[ $field ], $relative, 'block.json: ' . $field );
		}
	}

	if ( ! empty( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
		foreach ( $data['keywords'] as $keyword ) {
			if ( is_string( $keyword ) ) {
				record( $keyword, $relative, 'block.json: keyword' );
			}
		}
	}
}

/**
 * Escape a string for a PO file.
 */
function po_escape( $text ) {
	return str_replace(
		array( '\\', '"', "\t", "\n" ),
		array( '\\\\', '\\"', '\\t', '\\n' ),
		$text
	);
}

/* --- run ---------------------------------------------------------------- */

foreach ( files( $root . '/src', array( 'php' ) ) as $path ) {
	scan_php( $path, ltrim( str_replace( $root, '', $path ), '/' ), $targets, $domain );
}

scan_php( $root . '/rapls-sitemap.php', 'rapls-sitemap.php', $targets, $domain );

foreach ( files( $root . '/assets/js', array( 'js' ) ) as $path ) {
	scan_js( $path, ltrim( str_replace( $root, '', $path ), '/' ), $domain );
}

foreach ( files( $root . '/blocks', array( 'json' ) ) as $path ) {
	scan_block_json( $path, ltrim( str_replace( $root, '', $path ), '/' ) );
}

ksort( $entries );

$version = '0.1.0';
if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', file_get_contents( $root . '/rapls-sitemap.php' ), $m ) ) {
	$version = $m[1];
}

$out  = "# Copyright (C) Rapls\n";
$out .= "# This file is distributed under the GPL-2.0-or-later license.\n";
$out .= "# Generated by bin/make-pot.php — do not edit by hand.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Rapls Sitemap {$version}\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://raplsworks.com/plugins/rapls-sitemap/\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"X-Domain: {$domain}\\n\"\n";

foreach ( $entries as $msgid => $entry ) {
	$out .= "\n";

	foreach ( $entry['comments'] as $comment ) {
		$out .= "#. {$comment}\n";
	}

	$out .= '#: ' . implode( ' ', array_unique( $entry['refs'] ) ) . "\n";
	$out .= 'msgid "' . po_escape( $msgid ) . "\"\n";
	$out .= "msgstr \"\"\n";
}

$target = $root . '/languages/' . $domain . '.pot';
file_put_contents( $target, $out );

printf( "Wrote %s (%d strings)\n", str_replace( $root . '/', '', $target ), count( $entries ) );
