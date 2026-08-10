<?php
/**
 * Compatibility with the `[wp_sitemap_page]` shortcode.
 *
 * WP Sitemap Page is where most sites that once ran PS Auto Sitemap actually
 * landed, so its shortcode — not the PS comment marker — is what a page adopting
 * this plugin in 2026 is most likely to contain. Honouring it means switching
 * plugins is a deactivate-and-activate, with no content edited.
 *
 * Only the shortcode's documented interface is reproduced (the `only` attribute
 * and its values); none of that plugin's code is used, and its output markup is
 * deliberately NOT imitated — a placement renders as a rapls-sitemap, with this
 * plugin's own designs and classes.
 *
 * Registration is skipped whenever WP Sitemap Page is active. Two plugins
 * fighting over one shortcode tag is worse than either behaviour alone.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Frontend;

use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Hooks;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the compatibility shortcode.
 */
final class LegacyShortcode {

	/** Shortcode tag owned by WP Sitemap Page. */
	public const TAG = 'wp_sitemap_page';

	/**
	 * Late enough on `init` that a plugin registering the tag normally — that
	 * is, on `init` at the default priority — has already claimed it.
	 */
	private const PRIORITY = 20;

	/**
	 * Renderer/cache facade.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * @param Cache $cache Cache facade.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_register' ), self::PRIORITY );
	}

	/**
	 * Claim the shortcode tag, if asked to and if nobody already owns it.
	 *
	 * Three gates, cheapest first: the setting (off by default — this plugin
	 * does not answer to a tag it does not own unless told to), the filter, and
	 * finally whether WP Sitemap Page has already registered the tag itself.
	 */
	public function maybe_register(): void {
		$settings = Settings::get();
		if ( empty( $settings['legacy_shortcode'] ) ) {
			return;
		}

		/**
		 * Vetoes claiming the tag even when the setting is on.
		 *
		 * @param bool $claim Whether to register the shortcode.
		 */
		if ( ! apply_filters( Hooks::LEGACY_SHORTCODE, true ) ) {
			return;
		}

		if ( shortcode_exists( self::TAG ) ) {
			return;
		}

		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$settings = self::apply_atts( Settings::get(), is_array( $atts ) ? $atts : array() );
		$settings = Settings::for_request( $settings );

		Styles::request( $settings );

		return $this->cache->html( $settings );
	}

	/**
	 * Translate `[wp_sitemap_page]` attributes into settings.
	 *
	 * Unrecognised values of `only` are ignored rather than rendered as an
	 * error: the shortcode may well have been written for a section that plugin
	 * offered and this one does not, and a page that silently shows the default
	 * sitemap beats one that shows a complaint to every visitor.
	 *
	 * @param array<string,mixed> $settings Base settings.
	 * @param array<string,mixed> $atts     Raw attributes.
	 * @return array<string,mixed>
	 */
	public static function apply_atts( array $settings, array $atts ): array {
		if ( ! isset( $atts['only'] ) ) {
			return $settings;
		}

		$only = strtolower( trim( (string) $atts['only'] ) );

		// `only` names ONE section, so a site whose default composes several
		// has to stop composing them here — but only once the value has been
		// recognised. An unrecognised one falls through to the site default,
		// composition and all, which is the whole point of that fallback.
		if ( isset( Settings::SECTIONS[ $only ] ) ) {
			return array_merge( $settings, array( 'sections' => array() ), Settings::SECTIONS[ $only ] );
		}

		// Anything else is a post type slug — `page`, `post`, or a custom one.
		if ( post_type_exists( $only ) ) {
			$settings['sections']   = array();
			$settings['source']     = 'content';
			$settings['post_types'] = array( $only );
		}

		return $settings;
	}
}
