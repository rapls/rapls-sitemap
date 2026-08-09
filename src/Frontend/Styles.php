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

use RaplsSitemap\Support\Settings;

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
	 * Handle the author's Additional CSS is attached to.
	 *
	 * Registered with no source, which is the documented way to hold inline
	 * styles without also asking the browser for a file. It exists separately
	 * from HANDLE because the two are switched on and off independently: a
	 * theme can supply all the structural styling (`load_styles` off, or the
	 * `none` design) and still want its own CSS printed.
	 */
	public const INLINE_HANDLE = 'rapls-sitemap-inline';

	/**
	 * Whether the Additional CSS has already been attached this request.
	 *
	 * @var bool
	 */
	private static $css_attached = false;

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
		self::request_custom_css( $settings );

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
	 * Attach the author's Additional CSS, at most once per request.
	 *
	 * It deliberately does not travel inside the rendered markup. That markup
	 * is cached per placement and per page — `exclude_current` alone gives a
	 * distinct entry for every page a sitemap appears on — so a stylesheet
	 * embedded in it would be stored once per entry and printed once per
	 * placement. Handing it to WordPress instead stores it nowhere and prints
	 * it once, wherever late styles go.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private static function request_custom_css( array $settings ): void {
		if ( self::$css_attached ) {
			return;
		}

		$css = isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : '';
		if ( '' === trim( $css ) ) {
			return;
		}

		if ( ! wp_style_is( self::INLINE_HANDLE, 'registered' ) ) {
			return;
		}

		// Sanitized on save; re-run here because CSS can reach the option by
		// other routes — a filter, an import, a direct update_option().
		wp_enqueue_style( self::INLINE_HANDLE );
		wp_add_inline_style( self::INLINE_HANDLE, Settings::sanitize_css( $css ) );

		self::$css_attached = true;
	}
}
