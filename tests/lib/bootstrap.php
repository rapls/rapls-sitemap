<?php
/**
 * Shared harness for the standalone smoke tests.
 *
 * The rapls-* family runs tests as plain `php tests/smoke-*.php` invocations
 * with no PHPUnit and no WordPress. This file supplies the three things every
 * such test needs: ABSPATH, the plugin's autoloader, and the handful of generic
 * WordPress functions that almost every class touches.
 *
 * Query-layer functions (get_posts, get_terms, …) are deliberately NOT stubbed
 * here — a test defines those itself, so its fixtures stay next to its
 * assertions. Because top-level function declarations in the running script are
 * hoisted, a test can declare them before this file is required.
 *
 * Every stub is guarded by function_exists so a test can override any of them.
 *
 * @package RaplsSitemap
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'RAPLS_SITEMAP_VERSION', 'test' );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

spl_autoload_register( function ( $class ) {
	$prefix = 'RaplsSitemap\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( file_exists( $path ) ) {
		require $path;
	}
} );

/* --- assertions --------------------------------------------------------- */

$GLOBALS['rapls_passed'] = 0;
$GLOBALS['rapls_failed'] = 0;

/**
 * Assert and report.
 */
function check( $condition, $label, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['rapls_passed']++;
		echo "  PASS  {$label}\n";
		return true;
	}

	$GLOBALS['rapls_failed']++;
	echo "  FAIL  {$label}\n";
	if ( '' !== $detail ) {
		echo "        {$detail}\n";
	}
	return false;
}

/**
 * Print the tally and exit non-zero on any failure, so CI and `bin/run-tests.sh`
 * can tell a broken build from a passing one.
 */
function summary() {
	echo "\n  {$GLOBALS['rapls_passed']} passed, {$GLOBALS['rapls_failed']} failed\n";
	exit( $GLOBALS['rapls_failed'] > 0 ? 1 : 0 );
}

/* --- option store ------------------------------------------------------- */

$GLOBALS['rapls_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['rapls_options'] ) ? $GLOBALS['rapls_options'][ $name ] : $default;
	}
}

// Autoload flags, tracked alongside the values so a test can assert on them.
$GLOBALS['rapls_autoload'] = array();

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		// Core returns here when the serialized value is unchanged, and it does
		// so *before* looking at $autoload. Reproducing that is the point: a
		// migration that writes an option back to itself to change its autoload
		// flag silently does nothing, and only a stub that behaves like core
		// will catch it.
		$exists = array_key_exists( $name, $GLOBALS['rapls_options'] );
		if ( $exists && serialize( $GLOBALS['rapls_options'][ $name ] ) === serialize( $value ) ) {
			return false;
		}

		$GLOBALS['rapls_options'][ $name ] = $value;

		if ( null !== $autoload ) {
			$GLOBALS['rapls_autoload'][ $name ] = (bool) $autoload;
		}

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value, $deprecated = '', $autoload = true ) {
		if ( array_key_exists( $name, $GLOBALS['rapls_options'] ) ) {
			return false;
		}
		$GLOBALS['rapls_options'][ $name ]  = $value;
		$GLOBALS['rapls_autoload'][ $name ] = (bool) $autoload;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['rapls_options'][ $name ], $GLOBALS['rapls_autoload'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'wp_set_option_autoload' ) ) {
	function wp_set_option_autoload( $name, $autoload ) {
		if ( ! array_key_exists( $name, $GLOBALS['rapls_options'] ) ) {
			return false;
		}
		$GLOBALS['rapls_autoload'][ $name ] = (bool) $autoload;
		return true;
	}
}

/* --- transients --------------------------------------------------------- */

$GLOBALS['rapls_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['rapls_transients'] ) ? $GLOBALS['rapls_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['rapls_transients'][ $key ] = $value;
		return true;
	}
}

/* --- locale -------------------------------------------------------------- */

// The render cache keys on this: a multilingual plugin narrows the queries
// inside the render, and the settings are identical in every language.
$GLOBALS['rapls_locale'] = 'ja';

if ( ! function_exists( 'determine_locale' ) ) {
	function determine_locale() {
		return $GLOBALS['rapls_locale'];
	}
}

/* --- hooks -------------------------------------------------------------- */

$GLOBALS['rapls_hooks'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['rapls_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['rapls_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['rapls_hooks'][ 'shortcode:' . $tag ][] = $callback;
		return true;
	}
}

/* --- escaping and sanitization ------------------------------------------ */

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		$url = (string) $url;
		// Mirrors the part of core's behaviour the renderer relies on: unsafe
		// protocols are dropped entirely rather than escaped.
		if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = null ) {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

// Capabilities the fake current user holds. A test assigns to this rather than
// redefining the function.
$GLOBALS['rapls_caps'] = array( 'manage_options' => true, 'unfiltered_html' => true );

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return ! empty( $GLOBALS['rapls_caps'][ $capability ] );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		static $n = 0;
		return 'uuid-' . ( ++$n );
	}
}

/* --- asset registration ------------------------------------------------- */

$GLOBALS['rapls_enqueued'] = array();

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $list = 'enqueued' ) {
		// The plugin only asks whether a handle was registered, and in a test
		// the stylesheet always is — Styles::register_assets never runs.
		return 'registered' === $list ? true : in_array( $handle, $GLOBALS['rapls_enqueued'], true );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle ) {
		$GLOBALS['rapls_enqueued'][] = $handle;
	}
}

$GLOBALS['rapls_inline_styles'] = array();

if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( $handle, $css ) {
		$GLOBALS['rapls_inline_styles'][] = array( $handle, $css );
		return true;
	}
}

/* --- request context ---------------------------------------------------- */

// The ID Settings::for_request() should treat as "the page we are rendering
// inside". A test assigns to this rather than redefining the function.
$GLOBALS['rapls_current_post'] = 0;

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		return $GLOBALS['rapls_current_post'] > 0 ? $GLOBALS['rapls_current_post'] : false;
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	function is_feed() {
		return ! empty( $GLOBALS['rapls_is_feed'] );
	}
}
