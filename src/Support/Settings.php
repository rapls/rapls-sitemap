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
	 * Capability required to edit the Additional CSS field.
	 *
	 * Not `manage_options`, which every site administrator holds — including,
	 * on a network, administrators of individual sites. `unfiltered_html` is
	 * the capability WordPress already reserves for "may write markup and
	 * styles that get printed verbatim", and on multisite core grants it to
	 * super admins only. Using it here means CSS editing follows the same rule
	 * as everything else on the site that is printed unescaped.
	 */
	public const CSS_CAPABILITY = 'unfiltered_html';

	/** Ceiling on the Additional CSS field, in bytes. */
	public const MAX_CSS_BYTES = 65536;

	/** Heading elements a section or category heading may be rendered as. */
	public const HEADING_LEVELS = array( '', 'h2', 'h3', 'h4', 'h5', 'h6' );

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
	public const ORDERBY = array( 'default', 'date', 'title', 'ID', 'menu_order', 'modified', 'comment_count', 'rand', 'meta' );

	/** List elements the markup can be built from. */
	public const LIST_TYPES = array( 'ul', 'ol' );

	/**
	 * Entries fetched per post type before the list is truncated.
	 *
	 * A sitemap asks for every post of every type at once, so on a large site
	 * this is the query that runs out of memory. The cap is deliberately high
	 * enough that an ordinary site never meets it, and truncation is always
	 * visible in the output — a silently short sitemap would be worse than a
	 * slow one.
	 */
	public const DEFAULT_MAX_ENTRIES = 2000;

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
			// Post type slugs never listed, whatever `post_types` says. Belt
			// and braces for a type a plugin registers later.
			'exclude_types'    => array(),
			// Taxonomy slugs never used for grouping.
			'exclude_tax'      => array(),
			// Skip entries WordPress has a password on.
			'exclude_protected' => false,
			// Skip entries an SEO plugin has marked noindex.
			'exclude_noindex'  => false,
			// A post in several categories appears under each of them. Off
			// lists it once, under the first category that claims it.
			'duplicate_in_terms' => true,
			// Add rel="nofollow" to every link the sitemap emits.
			'nofollow'         => false,
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
			// Entries per post type; 0 lifts the cap entirely.
			'max_entries'      => self::DEFAULT_MAX_ENTRIES,
			// Entries to skip at the start of each list.
			'offset'           => 0,
			// A heading above each post type's list when more than one is shown.
			'section_headings' => true,
			// `ul` or `ol`.
			'list_type'        => 'ul',
			// Render section and category headings as a real heading element.
			// Empty keeps the plain span this plugin has always emitted, since
			// the right level depends on the page the sitemap sits in.
			'heading_level'    => '',
			// Show the published date beside each entry.
			'show_date'        => false,
			// PHP date format; empty uses the site's own setting.
			'date_format'      => '',
			// Show an excerpt beneath each entry.
			'show_excerpt'     => false,
			// Words of excerpt to show.
			'excerpt_length'   => 20,
			// Show the entry count beside each category heading.
			'show_count'       => false,
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
				'legacy_marker',
				'legacy_shortcode',
				'load_styles',
			) as $key
		) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		if ( isset( $input['exclude_types'] ) && is_array( $input['exclude_types'] ) ) {
			$clean['exclude_types'] = array_values(
				array_filter( array_map( 'sanitize_key', $input['exclude_types'] ) )
			);
		}

		if ( isset( $input['exclude_tax'] ) && is_array( $input['exclude_tax'] ) ) {
			$clean['exclude_tax'] = array_values(
				array_filter( array_map( 'sanitize_key', $input['exclude_tax'] ) )
			);
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

		$clean['custom_css'] = self::resolve_css( $input );

		if ( isset( $input['cache_ttl'] ) ) {
			$clean['cache_ttl'] = max( 0, (int) $input['cache_ttl'] );
		}

		foreach ( array( 'max_entries', 'offset' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}

		if ( isset( $input['excerpt_length'] ) ) {
			$clean['excerpt_length'] = max( 1, min( 200, (int) $input['excerpt_length'] ) );
		}

		if ( isset( $input['list_type'] ) && in_array( $input['list_type'], self::LIST_TYPES, true ) ) {
			$clean['list_type'] = $input['list_type'];
		}

		if ( isset( $input['heading_level'] ) && in_array( $input['heading_level'], self::HEADING_LEVELS, true ) ) {
			$clean['heading_level'] = $input['heading_level'];
		}

		if ( isset( $input['date_format'] ) ) {
			// A date format is passed to date_i18n, not printed raw, so the
			// only thing to strip is markup somebody pasted by mistake.
			$clean['date_format'] = sanitize_text_field( (string) $input['date_format'] );
		}

		return $clean;
	}

	/**
	 * May the current user edit the Additional CSS field?
	 *
	 * @return bool
	 */
	public static function can_edit_css(): bool {
		return current_user_can( self::CSS_CAPABILITY );
	}

	/**
	 * Decide what `custom_css` should be after a save.
	 *
	 * Two cases have to be kept apart, and getting them backwards would either
	 * discard somebody's stylesheet or let the wrong person write one:
	 *
	 * - The field was not submitted. That means it was not rendered, because a
	 *   textarea posts even when empty — so the stored CSS is preserved rather
	 *   than reset to the default, which is what every other absent key does.
	 * - It *was* submitted by someone without the capability. A form field can
	 *   be forged, so the check lives here at the save rather than only at the
	 *   render, and the submitted value is discarded.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return string
	 */
	private static function resolve_css( array $input ): string {
		if ( isset( $input['custom_css'] ) && self::can_edit_css() ) {
			return self::sanitize_css( (string) $input['custom_css'] );
		}

		$stored = get_option( self::OPTION, array() );

		return ( is_array( $stored ) && isset( $stored['custom_css'] ) ) ? (string) $stored['custom_css'] : '';
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

		$css = trim( (string) $css );

		// The only field here with no natural size, and it lives in an option
		// that is read on every sitemap render and shipped in every cache
		// entry. 64 KB is far more than a sitemap's styling needs and well
		// under the 150 KB at which WordPress 6.6 stops autoloading an option
		// on its own.
		if ( strlen( $css ) > self::MAX_CSS_BYTES ) {
			$css = substr( $css, 0, self::MAX_CSS_BYTES );
		}

		return $css;
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
