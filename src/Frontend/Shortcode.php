<?php
/**
 * The `[rapls_sitemap]` shortcode.
 *
 * Attributes override the stored settings for one placement, so a page can show
 * a narrower sitemap than the site default:
 *
 *   [rapls_sitemap post_types="page" depth="2" show_home="0"]
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Frontend;

use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the shortcode.
 */
final class Shortcode {

	/** Shortcode tag. */
	public const TAG = 'rapls_sitemap';

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
	 * Hook the shortcode.
	 */
	public function register(): void {
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
	 * Fold shortcode/block attributes into a settings array.
	 *
	 * Only the attributes that make sense per-placement are honoured; caching
	 * and stylesheet loading stay global. Kept static and WordPress-free so the
	 * block renderer and the smoke tests can both call it.
	 *
	 * @param array<string,mixed> $settings Base settings.
	 * @param array<string,mixed> $atts     Raw attributes.
	 * @return array<string,mixed>
	 */
	public static function apply_atts( array $settings, array $atts ): array {
		if ( isset( $atts['post_types'] ) ) {
			$types = is_array( $atts['post_types'] )
				? $atts['post_types']
				: preg_split( '/[\s,]+/', (string) $atts['post_types'] );

			$types = array_values( array_filter( array_map( 'trim', (array) $types ) ) );
			if ( array() !== $types ) {
				$settings['post_types'] = $types;
			}
		}

		if ( isset( $atts['depth'] ) ) {
			$settings['depth'] = max( 0, (int) $atts['depth'] );
		}

		if ( isset( $atts['design'] ) && in_array( $atts['design'], Settings::DESIGNS, true ) ) {
			$settings['design'] = (string) $atts['design'];
		}

		if ( isset( $atts['term_mode'] ) && in_array( $atts['term_mode'], Settings::TERM_MODES, true ) ) {
			$settings['term_mode'] = (string) $atts['term_mode'];
		}

		if ( isset( $atts['source'] ) && in_array( $atts['source'], Settings::SOURCES, true ) ) {
			$settings['source'] = (string) $atts['source'];
		}

		if ( isset( $atts['taxonomy'] ) ) {
			$settings['taxonomy'] = sanitize_key( (string) $atts['taxonomy'] );
		}

		foreach (
			array(
				'show_home',
				'group_by_term',
				'nest_terms',
				'exclude_current',
				'exclude_protected',
				'exclude_noindex',
				'duplicate_in_terms',
				'nofollow',
				'section_headings',
				'show_date',
				'show_excerpt',
				'show_count',
			) as $flag
		) {
			if ( isset( $atts[ $flag ] ) ) {
				$settings[ $flag ] = self::to_bool( $atts[ $flag ] );
			}
		}

		// `number` reads better in a shortcode than `max_entries`, and it is
		// what the plugins people are migrating from call it.
		foreach ( array( 'number' => 'max_entries', 'offset' => 'offset', 'excerpt_length' => 'excerpt_length' ) as $att => $key ) {
			if ( isset( $atts[ $att ] ) ) {
				$settings[ $key ] = max( 0, (int) $atts[ $att ] );
			}
		}

		if ( isset( $atts['list_type'] ) && in_array( $atts['list_type'], Settings::LIST_TYPES, true ) ) {
			$settings['list_type'] = (string) $atts['list_type'];
		}

		if ( isset( $atts['orderby'] ) && in_array( $atts['orderby'], Settings::ORDERBY, true ) ) {
			$settings['orderby'] = (string) $atts['orderby'];
		}

		if ( isset( $atts['order'] ) ) {
			$settings['order'] = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';
		}

		if ( isset( $atts['date_format'] ) ) {
			$settings['date_format'] = sanitize_text_field( (string) $atts['date_format'] );
		}

		foreach ( array( 'exclude_ids', 'exclude_terms' ) as $list ) {
			if ( isset( $atts[ $list ] ) ) {
				$settings[ $list ] = Settings::to_id_list( $atts[ $list ] );
			}
		}

		return $settings;
	}

	/**
	 * Interpret a shortcode attribute as a boolean.
	 *
	 * Shortcode attributes are always strings, and "0"/"false"/"no" all read as
	 * off to an author writing them by hand.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array(
			strtolower( trim( (string) $value ) ),
			array( '', '0', 'false', 'no', 'off' ),
			true
		);
	}
}
