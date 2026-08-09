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
	}

	/**
	 * Ask for the stylesheet, if the settings allow it.
	 *
	 * Called by every renderer entry point (shortcode, block).
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 */
	public static function request( array $settings ): void {
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
}
