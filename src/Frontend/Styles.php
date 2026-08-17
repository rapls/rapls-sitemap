<?php
/**
 * Front-end stylesheet loading.
 *
 * The stylesheet is registered on `wp_enqueue_scripts` but only enqueued when a
 * sitemap actually renders — a shortcode fires during `the_content`, long after
 * that hook, so WordPress prints the late enqueue in the footer. A sitemap is
 * page-body content, so footer styles cause no visible reflow, and pages
 * without a sitemap stay free of the request entirely.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Frontend;

use RaplsSitemap\Support\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the stylesheet and enqueues it on demand.
 */
final class Styles {

	/** Registered handle. */
	public const HANDLE = 'rapls-sitemap';

	/**
	 * Handle the design tokens are attached to.
	 *
	 * Registered with no source, which is the documented way to hold inline
	 * styles without also asking the browser for a file. It exists separately
	 * from HANDLE because the two are switched on and off independently: a
	 * theme can supply all the structural styling (`load_styles` off, or the
	 * `none` design) and still want its own CSS printed.
	 */
	public const INLINE_HANDLE = 'rapls-sitemap-inline';

	/**
	 * Scope classes whose token block has already been attached this request.
	 *
	 * @var array<string,bool>
	 */
	private static $attached = array();

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) the stylesheet.
	 */
	public function register_assets(): void {
		wp_register_style(
			self::HANDLE,
			RAPLS_SITEMAP_URL . 'assets/css/rapls-sitemap.css',
			array(),
			RAPLS_SITEMAP_VERSION
		);

		wp_register_style( self::INLINE_HANDLE, false, array(), RAPLS_SITEMAP_VERSION );
	}

	/**
	 * Ask for the stylesheet, if the settings allow it.
	 *
	 * Called by every renderer entry point (shortcode, block).
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 */
	public static function request( array $settings ): void {
		self::request_tokens( $settings );

		if ( empty( $settings['load_styles'] ) ) {
			return;
		}

		// The "no style" preset means exactly that — structural markup and not
		// one byte of CSS over the wire.
		if ( 'none' === $settings['design'] ) {
			return;
		}

		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Attach the design tokens, once per distinct set of them.
	 *
	 * These used to be printed as a `<style>` element inside the rendered
	 * markup. WordPress asks that CSS go through `wp_add_inline_style()`
	 * instead, and it is right to: a style element in post content is a style
	 * element no theme, plugin or optimiser can see coming.
	 *
	 * The reason it was ever inline is still real, though, so this runs here
	 * rather than in the renderer. The markup is cached per placement *and* per
	 * page — `exclude_current` alone gives a distinct entry for every page a
	 * sitemap appears on — so CSS embedded in it would be stored once per entry
	 * and printed once per placement. Attaching it out here stores it nowhere
	 * and prints it once.
	 *
	 * Keyed by the scope class rather than a plain "done" flag. Two placements
	 * with different tokens each need their own block; two with the same tokens
	 * need one between them. The scope class is a hash of the tokens, so it is
	 * exactly that key.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private static function request_tokens( array $settings ): void {
		$style = Design::merge( isset( $settings['style'] ) ? $settings['style'] : array() );
		$css   = Design::style_block( $style );

		if ( '' === $css ) {
			return;
		}

		$scope = Design::scope_class( $style );
		if ( isset( self::$attached[ $scope ] ) ) {
			return;
		}

		if ( ! wp_style_is( self::INLINE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_enqueue_style( self::INLINE_HANDLE );
		wp_add_inline_style( self::INLINE_HANDLE, $css );

		self::$attached[ $scope ] = true;
	}
}
