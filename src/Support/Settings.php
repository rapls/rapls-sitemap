<?php
/**
 * Settings storage.
 *
 * Unlike the sibling rapls-* plugins (which spread configuration across many
 * discrete options), every setting here lives in ONE array option. A sitemap is
 * rendered from the whole configuration at once, and the cache key is a hash of
 * it — a single option makes both the read path and the invalidation trivial.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, sanitizes, and writes the plugin's single settings option.
 */
final class Settings {

	/** The one and only option name. */
	public const OPTION = 'rapls_sitemap_settings';

	/**
	 * Available design presets.
	 *
	 * `none` emits the structural markup with no bundled styling at all, for
	 * themes that want to do the work themselves. The other twelve are original
	 * CSS written for this plugin — no images, no sprites, so they inherit the
	 * theme's colours through `currentColor` and survive a dark theme.
	 */
	public const DESIGNS = array(
		'none',
		// Plain and utilitarian.
		'simple',
		'list',
		'compact',
		'tree',
		'index',
		'table',
		'columns',
		'outline',
		'numbered',
		// Structured.
		'card',
		'business',
		'panel',
		'timeline',
		'accordion',
		'grid',
		'underline',
		// Decorative.
		'marker',
		'checklist',
		'label',
		'arrow',
		'dots',
		'pill',
		'ribbon',
		'magazine',
		'book',
		'neon',
		'terminal',
	);

	/** Orderings offered for the entries within a list. */
	public const ORDERBY = array( 'default', 'date', 'title', 'ID', 'menu_order', 'modified', 'meta' );

	/** Term display modes. */
	public const TERM_MODES = array( 'posts', 'terms_only' );

	/**
	 * What the tree is built from.
	 *
	 * `content` is the sitemap proper — posts and pages. The other two list the
	 * archives that WordPress generates around that content, and exist so a
	 * placement can reproduce the sections other sitemap plugins offer.
	 */
	public const SOURCES = array( 'content', 'authors', 'archives' );

	/**
	 * Defaults. Every key present here is the complete schema — get() never
	 * returns a key that is not in this list, so callers can index freely.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// Post types included. The ARRAY ORDER IS THE OUTPUT ORDER — this is
			// how the settings screen expresses "which list comes first".
			'post_types'       => array( 'page', 'post' ),
			// Maximum nesting depth; 0 means unlimited.
			'depth'            => 0,
			// Post/page IDs to omit (their descendants are omitted too).
			'exclude_ids'      => array(),
			// Term IDs (category) whose posts are omitted.
			'exclude_terms'    => array(),
			// Prepend a link to the front page.
			'show_home'        => true,
			// Label for that link; empty falls back to the site title.
			'home_label'       => '',
			// Group `post` entries under their category headings.
			'group_by_term'    => true,
			// What to build the tree from; see SOURCES.
			'source'           => 'content',
			// Taxonomy used for grouping. Empty picks the post type's first
			// public hierarchical taxonomy, which is `category` for posts.
			'taxonomy'         => '',
			// 'posts' lists entries under each heading; 'terms_only' stops at
			// the category links themselves.
			'term_mode'        => 'posts',
			// Nest child categories inside their parents.
			'nest_terms'       => true,
			// Omit the page the sitemap is rendering on from its own listing.
			'exclude_current'  => true,
			// Migration compatibility, both OFF by default. Each one makes this
			// plugin answer to another plugin's published interface, which is a
			// surprise unless the user asked for it — and `legacy_shortcode` in
			// particular claims a shortcode tag this plugin does not own.
			//
			// Honour the `<!-- SITEMAP CONTENT REPLACE POINT -->` comment left
			// in page content by PS Auto Sitemap.
			'legacy_marker'    => false,
			// Answer to `[wp_sitemap_page]`, unless WP Sitemap Page is active.
			'legacy_shortcode' => false,
			// How entries are sorted; 'default' leaves it to the post type
			// (menu order for hierarchical types, newest first for the rest).
			'orderby'          => 'default',
			'order'            => 'DESC',
			// Meta key holding a sort value — the only way to get a true
			// 五十音順, since WordPress stores no reading for a kanji title.
			'sort_meta_key'    => '',
			// Design preset slug; see DESIGNS.
			'design'           => 'simple',
			// Typography, colour, and bullet overrides; see Support\Design.
			'style'            => array(),
			// Author-supplied CSS, printed with the sitemap.
			'custom_css'       => '',
			// Enqueue the bundled stylesheet. Off = theme supplies all styling.
			'load_styles'      => true,
			// Cached markup lifetime in seconds; 0 disables caching.
			'cache_ttl'        => 12 * HOUR_IN_SECONDS,
		);
	}

	/**
	 * The current settings, defaults merged in and the filter applied.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );

		// `style` is the one nested key, so array_merge would replace it whole
		// and a catalogue saved before a token was added would lose that token.
		$merged['style'] = Design::merge( $merged['style'] );

		/**
		 * Filters the effective settings.
		 *
		 * @param array $merged Settings with defaults applied.
		 */
		$filtered = apply_filters( Hooks::SETTINGS, $merged );

		return is_array( $filtered ) ? $filtered : $merged;
	}

	/**
	 * Coerce raw form input into the stored shape.
	 *
	 * Registered as the `sanitize_callback` for the option, so it also runs on
	 * programmatic `update_option()` calls.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$clean = $defaults;

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$types = array_values(
				array_filter(
					array_map( 'sanitize_key', $input['post_types'] ),
					'post_type_exists'
				)
			);

			// `post_types_order` is a UI-only companion field (slug => position)
			// that never reaches storage; the resulting order does, because the
			// array order IS the output order.
			$order = isset( $input['post_types_order'] ) && is_array( $input['post_types_order'] )
				? $input['post_types_order']
				: array();

			$clean['post_types'] = self::sort_by_order( $types, $order );
		}

		if ( isset( $input['depth'] ) ) {
			$clean['depth'] = max( 0, min( 10, (int) $input['depth'] ) );
		}

		foreach ( array( 'exclude_ids', 'exclude_terms' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = self::to_id_list( $input[ $key ] );
			}
		}

		foreach ( array( 'show_home', 'group_by_term', 'nest_terms', 'exclude_current', 'legacy_marker', 'legacy_shortcode', 'load_styles' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		if ( isset( $input['home_label'] ) ) {
			$clean['home_label'] = sanitize_text_field( (string) $input['home_label'] );
		}

		if ( isset( $input['term_mode'] ) && in_array( $input['term_mode'], self::TERM_MODES, true ) ) {
			$clean['term_mode'] = $input['term_mode'];
		}

		if ( isset( $input['source'] ) && in_array( $input['source'], self::SOURCES, true ) ) {
			$clean['source'] = $input['source'];
		}

		if ( isset( $input['taxonomy'] ) ) {
			$taxonomy = sanitize_key( (string) $input['taxonomy'] );
			// '' is meaningful (auto-detect), so only a real-but-unregistered
			// taxonomy is rejected.
			$clean['taxonomy'] = ( '' === $taxonomy || taxonomy_exists( $taxonomy ) ) ? $taxonomy : '';
		}

		if ( isset( $input['design'] ) && in_array( $input['design'], self::DESIGNS, true ) ) {
			$clean['design'] = $input['design'];
		}

		if ( isset( $input['orderby'] ) && in_array( $input['orderby'], self::ORDERBY, true ) ) {
			$clean['orderby'] = $input['orderby'];
		}

		if ( isset( $input['order'] ) ) {
			$clean['order'] = 'ASC' === strtoupper( (string) $input['order'] ) ? 'ASC' : 'DESC';
		}

		if ( isset( $input['sort_meta_key'] ) ) {
			$clean['sort_meta_key'] = sanitize_key( (string) $input['sort_meta_key'] );
		}

		$clean['style'] = Design::sanitize( isset( $input['style'] ) ? $input['style'] : array() );

		if ( isset( $input['custom_css'] ) ) {
			$clean['custom_css'] = self::sanitize_css( (string) $input['custom_css'] );
		}

		if ( isset( $input['cache_ttl'] ) ) {
			$clean['cache_ttl'] = max( 0, (int) $input['cache_ttl'] );
		}

		return $clean;
	}

	/**
	 * Make author-supplied CSS safe to print inside a `<style>` element.
	 *
	 * The one thing CSS must never be able to do here is leave its element, so
	 * every `<` that starts a tag goes. `>` stays — it is the child combinator,
	 * and stripping it would break ordinary selectors. Comment delimiters go
	 * too, because `<!--` is the historical way out of a style block.
	 *
	 * This is a containment measure, not a CSS parser. Anyone who can reach
	 * this field already holds `manage_options`.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize_css( string $css ): string {
		$css = str_replace( array( '<!--', '-->' ), '', $css );

		// Kills `</style`, `<script`, and anything else tag-shaped, while
		// leaving `a > b` and `@media (max-width: 40em)` untouched.
		$css = preg_replace( '#<\s*/?\s*[a-zA-Z!]#', '', $css );

		return trim( (string) $css );
	}

	/**
	 * Order post types by a companion slug => position map.
	 *
	 * Slugs with no position keep their incoming relative order and sort last,
	 * so a post type registered by a plugin after the settings were saved does
	 * not silently jump to the front.
	 *
	 * @param string[]            $types Post type slugs.
	 * @param array<string,mixed> $order slug => position.
	 * @return string[]
	 */
	private static function sort_by_order( array $types, array $order ): array {
		if ( array() === $order ) {
			return $types;
		}

		$positions = array();
		foreach ( $types as $index => $type ) {
			$positions[ $type ] = isset( $order[ $type ] )
				? (int) $order[ $type ]
				: PHP_INT_MAX - count( $types ) + $index;
		}

		// A stable sort keyed on (position, original index): usort is not
		// guaranteed stable before PHP 8.0, and 7.4 is the floor here.
		$indexed = array();
		foreach ( $types as $index => $type ) {
			$indexed[] = array( $positions[ $type ], $index, $type );
		}

		usort(
			$indexed,
			static function ( $a, $b ) {
				return $a[0] === $b[0] ? $a[1] <=> $b[1] : $a[0] <=> $b[0];
			}
		);

		return array_column( $indexed, 2 );
	}

	/**
	 * Fold request-time context into the settings.
	 *
	 * Only one thing needs this today: `exclude_current` has to become a real
	 * entry in `exclude_ids` BEFORE the cache hashes the settings, or every page
	 * would share one cache entry built with somebody else's exclusion.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return array<string,mixed>
	 */
	public static function for_request( array $settings ): array {
		if ( empty( $settings['exclude_current'] ) ) {
			return $settings;
		}

		$current = self::current_post_id();
		if ( $current > 0 && ! in_array( $current, (array) $settings['exclude_ids'], true ) ) {
			$settings['exclude_ids'][] = $current;
		}

		return $settings;
	}

	/**
	 * The post the sitemap is being rendered inside, if any.
	 *
	 * @return int Post ID, or 0.
	 */
	private static function current_post_id(): int {
		$id = get_the_ID();
		if ( $id ) {
			return (int) $id;
		}

		if ( function_exists( 'get_queried_object_id' ) ) {
			return (int) get_queried_object_id();
		}

		return 0;
	}

	/**
	 * Parse a comma/space separated ID list (or an array) into unique positive ints.
	 *
	 * Accepts the same loose input PS Auto Sitemap did ("12, 34 56"), because
	 * that is what users paste when migrating.
	 *
	 * @param mixed $raw Raw list.
	 * @return int[]
	 */
	public static function to_id_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', (string) $raw ) ?: array();
		}

		$ids = array();
		foreach ( $raw as $value ) {
			$id = absint( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}
}
