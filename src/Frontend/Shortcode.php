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

		// Clamped to the same ceiling the settings screen applies: ten levels is
		// a limit on what the renderer will nest, not a preference, and a
		// placement is written by anyone who can edit a post.
		if ( isset( $atts['depth'] ) ) {
			$settings['depth'] = max( 0, min( 10, (int) $atts['depth'] ) );
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

		// Several sections in one placement:
		//
		//   [rapls_sitemap sections="page,post,category,author,archive"]
		//
		// An empty value is how a placement says "one list, the ordinary way"
		// on a site whose default composes several — so this is set even when
		// the attribute is blank.
		if ( isset( $atts['sections'] ) ) {
			$settings['sections'] = Settings::to_section_list( $atts['sections'] );
		}

		if ( isset( $atts['taxonomy'] ) ) {
			$settings['taxonomy'] = sanitize_key( (string) $atts['taxonomy'] );
		}

		// An ID, a slug or a name — all three are what wp_get_nav_menu_object()
		// takes, and a shortcode reads far better with a slug in it than with
		// the term ID the settings screen posts.
		if ( isset( $atts['menu'] ) ) {
			$settings['menu'] = sanitize_text_field( (string) $atts['menu'] );

			// Naming a menu is asking for that menu. Making the author write
			// source="menu" beside it would be a second way to say one thing,
			// and forgetting it would silently show the ordinary sitemap.
			if ( '' !== $settings['menu'] && ! isset( $atts['source'] ) ) {
				$settings['source'] = 'menu';
			}
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
				'link_headings',
				'show_date',
				'show_excerpt',
				'show_count',
				'menu_headings',
				'link_parents',
			) as $flag
		) {
			if ( isset( $atts[ $flag ] ) ) {
				$settings[ $flag ] = self::to_bool( $atts[ $flag ] );
			}
		}

		// `number` reads better in a shortcode than `max_entries`, and it is
		// what the plugins people are migrating from call it.
		foreach (
			array(
				'number'         => 'max_entries',
				'offset'         => 'offset',
				'excerpt_length' => 'excerpt_length',
				'per_category'   => 'max_per_term',
			) as $att => $key
		) {
			if ( isset( $atts[ $att ] ) ) {
				$settings[ $key ] = max( 0, (int) $atts[ $att ] );
			}
		}

		// Bounded on the settings screen and therefore here: 1 because a
		// zero-word excerpt is not an excerpt, 200 because past that it stops
		// being one. A placement is written by anyone who can edit a post.
		if ( isset( $atts['excerpt_length'] ) ) {
			$settings['excerpt_length'] = max( 1, min( 200, (int) $atts['excerpt_length'] ) );
		}

		if ( isset( $atts['list_type'] ) && in_array( $atts['list_type'], Settings::LIST_TYPES, true ) ) {
			$settings['list_type'] = (string) $atts['list_type'];
		}

		if ( isset( $atts['orderby'] ) && in_array( $atts['orderby'], Settings::ORDERBY, true ) ) {
			$settings['orderby'] = (string) $atts['orderby'];
		}

		if ( isset( $atts['term_orderby'] ) && in_array( $atts['term_orderby'], Settings::TERM_ORDERBY, true ) ) {
			$settings['term_orderby'] = (string) $atts['term_orderby'];
		}

		if ( isset( $atts['term_order'] ) ) {
			$settings['term_order'] = 'DESC' === strtoupper( (string) $atts['term_order'] ) ? 'DESC' : 'ASC';
		}

		if ( isset( $atts['order'] ) ) {
			$settings['order'] = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';
		}

		if ( isset( $atts['date_format'] ) ) {
			$settings['date_format'] = sanitize_text_field( (string) $atts['date_format'] );
		}

		// `current` stays a string until Settings::for_request() resolves it —
		// this runs too early to know which page is being rendered, and hashing
		// the settings before that would give every page one shared cache entry.
		if ( isset( $atts['child_of'] ) ) {
			$child_of = trim( (string) $atts['child_of'] );

			$word = strtolower( $child_of );

			$settings['child_of'] = in_array( $word, array( 'current', 'parent' ), true )
				? $word
				: max( 0, (int) $child_of );
		}

		foreach ( array( 'date_after', 'date_before' ) as $bound ) {
			if ( isset( $atts[ $bound ] ) ) {
				$settings[ $bound ] = Settings::to_date( $atts[ $bound ] );
			}
		}

		foreach ( array( 'exclude_ids', 'exclude_terms', 'exclude_users' ) as $list ) {
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
