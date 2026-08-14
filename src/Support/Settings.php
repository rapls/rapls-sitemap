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
	 * themes that want to do the work themselves. The others are original CSS
	 * written for this plugin — no images, no sprites, so they inherit the
	 * theme's colours through `currentColor` and survive a dark theme.
	 *
	 * The count is deliberately not written here. It said "twelve" for long
	 * enough to outlive two rounds of new presets, and a number in a comment is
	 * only ever as fresh as the last person who remembered it. The array below
	 * is the count.
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

	/**
	 * Orderings offered for the category and tag headings.
	 *
	 * Not the same list as ORDERBY, which is about entries: a term has no
	 * publication date to sort by, and an entry has no count.
	 *
	 * `term_order` is the odd one. WordPress accepts it and, on its own, treats
	 * it as the term ID — the column it refers to is only joined when the query
	 * asks about one post's terms. What makes it useful is that the ordering
	 * plugins a site installs to drag categories into a chosen order are the
	 * ones that answer it. Offered because "商品カテゴリー" and "診療科" are
	 * ordinary things to want in a chosen order rather than alphabetically, and
	 * documented as needing one of those plugins.
	 */
	public const TERM_ORDERBY = array( 'name', 'count', 'slug', 'term_id', 'term_order' );

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
	 * `content` is the sitemap proper — posts and pages. `authors` and
	 * `archives` list what WordPress generates around that content, and exist so
	 * a placement can reproduce the sections other sitemap plugins offer.
	 *
	 * `menu` is the odd one out and deliberately so: it lists a navigation menu
	 * exactly as the site's editors arranged it, rather than deriving anything
	 * from the content. That is the whole point — on a site with hundreds of
	 * pages, "the routes we decided on" is a different and often better table of
	 * contents than "everything we have published".
	 */
	public const SOURCES = array( 'content', 'authors', 'archives', 'menu' );

	/**
	 * Sections that name something other than a post type.
	 *
	 * Each is a set of overrides onto the settings this plugin already has —
	 * none of them is a fourth source. Two things read this: `sections`, which
	 * composes several of them into one placement, and the `only` attribute of
	 * `[wp_sitemap_page]`, whose vocabulary this is. Keeping one table means the
	 * two cannot drift, and `tag` is the reason the `taxonomy` setting exists.
	 *
	 * Anything not listed here is a post type or taxonomy slug, resolved when
	 * the tree is built rather than here — a slug belonging to a plugin that is
	 * momentarily deactivated is still the setting the site means to have.
	 */
	public const SECTIONS = array(
		'category' => array(
			'source'        => 'content',
			'post_types'    => array( 'post' ),
			'group_by_term' => true,
			'term_mode'     => 'terms_only',
			'taxonomy'      => 'category',
		),
		'tag'      => array(
			'source'        => 'content',
			'post_types'    => array( 'post' ),
			'group_by_term' => true,
			'term_mode'     => 'terms_only',
			'taxonomy'      => 'post_tag',
			// Tags are flat; nesting them would be a no-op that still costs a
			// pass over the tree.
			'nest_terms'    => false,
		),
		'author'   => array( 'source' => 'authors' ),
		'archive'  => array( 'source' => 'archives' ),
		'menu'     => array( 'source' => 'menu' ),
	);

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
			// Limit the listing to what sits under one page. 0 is the whole
			// site. The page itself is not listed — WordPress means
			// "descendants of" by child_of, and a heading for the page you are
			// already on is noise.
			'child_of'         => 0,
			// Post/page IDs to omit, along with their descendants.
			'exclude_ids'      => array(),
			// The page the sitemap is rendering on, resolved by for_request().
			// Kept apart from exclude_ids because it must NOT cascade: leaving
			// the sitemap page out of its own list is no reason to hide the
			// pages filed under it.
			'exclude_self'     => 0,
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
			// Which navigation menu the `menu` source lists: a term ID, slug,
			// or name — whatever `wp_get_nav_menu_object()` will take. Kept a
			// string so all three survive a save; the settings screen posts the
			// ID, but a shortcode is far more readable with a slug in it.
			'menu'             => '',
			// Only list entries published within these bounds. Both are
			// inclusive, both are `YYYY-MM-DD` (or `YYYY-MM`, or `YYYY`), and
			// either may stand alone — "since April" is a range with one end.
			// A school or a council listing one school year at a time is the
			// case this exists for.
			'date_after'       => '',
			'date_before'      => '',
			// User IDs never listed in the author listing. A site's own admin
			// account, or the agency that built it, is on the user list without
			// being someone a reader should be sent to.
			'exclude_users'    => array(),
			// Roles the author listing is limited to; empty is every role that
			// has published something.
			'author_roles'     => array(),
			// An entry that has entries under it is printed as a heading rather
			// than as a link. For a section landing page that exists only to
			// hold its children, the link is a page nobody wants to read.
			'link_parents'     => true,
			// A menu item with no real destination — the `#` that holds open a
			// dropdown — is printed as plain text rather than as a link that
			// goes nowhere. Off restores the literal href.
			'menu_headings'    => true,
			// Several sections in one placement — post type slugs, taxonomy
			// slugs, and the aliases in SECTIONS, in the order they should
			// appear. Empty is the ordinary single-source sitemap, and `source`
			// is ignored while this is not: a composed sitemap says what each of
			// its own sections is built from.
			'sections'         => array(),
			// Taxonomy used for grouping. Empty picks the post type's first
			// viewable hierarchical taxonomy, which is `category` for posts.
			'taxonomy'         => '',
			// How the category and tag headings are ordered; see TERM_ORDERBY.
			'term_orderby'     => 'name',
			'term_order'       => 'ASC',
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
			// Entries listed under each category heading; 0 lifts that cap.
			// Separate from max_entries, which bounds the query — this one
			// bounds how long any single group gets on the page.
			'max_per_term'     => 0,
			// Entries to skip at the start of each post type's list. The term
			// and author queries do not take it: an offset into a list of
			// categories or of people answers no question anyone has asked, and
			// the cap that bounds those queries is there for memory, not for
			// paging.
			'offset'           => 0,
			// A heading above each list when more than one is shown — over each
			// post type, and over each entry of `sections`.
			'section_headings' => true,
			// `ul` or `ol`.
			'list_type'        => 'ul',
			// Whether a section or category heading is a link to its archive.
			// Off leaves the text in place without linking it, for sites whose
			// archives are thin or noindexed and should not be pointed at.
			'link_headings'    => true,
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

		if ( ! is_array( $filtered ) ) {
			return $merged;
		}

		/*
		 * Merged over the defaults a second time, on purpose.
		 *
		 * The promise above — that callers may index this array without
		 * isset() — has to survive the filter, and a filter that returns a
		 * partial array is an ordinary thing for a site to do. Without this,
		 * one missing key turns into an undefined-index warning on every line
		 * that reads it, inside `the_content`, where a warning becomes part of
		 * the page. The filter can still change any value; it just cannot
		 * remove one.
		 */
		$filtered          = array_merge( $merged, array_intersect_key( $filtered, $merged ) );
		$filtered['style'] = Design::merge( $filtered['style'] );

		return $filtered;
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

		if ( isset( $input['sections'] ) ) {
			// Ordered the same way the post types are, by a companion field the
			// screen posts alongside the boxes and storage never sees. The array
			// order IS the section order.
			$order = isset( $input['sections_order'] ) && is_array( $input['sections_order'] )
				? $input['sections_order']
				: array();

			$clean['sections'] = self::sort_by_order( self::to_section_list( $input['sections'] ), $order );
		}

		if ( isset( $input['depth'] ) ) {
			$clean['depth'] = max( 0, min( 10, (int) $input['depth'] ) );
		}

		foreach ( array( 'exclude_ids', 'exclude_terms', 'exclude_users' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = self::to_id_list( $input[ $key ] );
			}
		}

		if ( isset( $input['author_roles'] ) ) {
			// Not checked against the roles that exist: a role a plugin adds is
			// not there when its plugin is being updated, and dropping it would
			// silently widen the listing to everybody.
			$clean['author_roles'] = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', (array) $input['author_roles'] )
					)
				)
			);
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
				'legacy_marker',
				'legacy_shortcode',
				'load_styles',
				'menu_headings',
				'link_parents',
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

		foreach ( array( 'date_after', 'date_before' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = self::to_date( $input[ $key ] );
			}
		}

		if ( isset( $input['term_orderby'] ) && in_array( $input['term_orderby'], self::TERM_ORDERBY, true ) ) {
			$clean['term_orderby'] = (string) $input['term_orderby'];
		}

		if ( isset( $input['term_order'] ) ) {
			$clean['term_order'] = 'DESC' === strtoupper( (string) $input['term_order'] ) ? 'DESC' : 'ASC';
		}

		if ( isset( $input['menu'] ) ) {
			$clean['menu'] = sanitize_text_field( (string) $input['menu'] );
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

		foreach ( array( 'max_entries', 'offset', 'max_per_term', 'child_of' ) as $key ) {
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
	 * this field already holds `unfiltered_html` — see CSS_CAPABILITY, which is
	 * deliberately not `manage_options`.
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
	 * Two things need this, and both for the same reason: they depend on which
	 * page is rendering, and they have to be resolved BEFORE the cache hashes
	 * the settings — otherwise every page shares one entry built from somebody
	 * else's context.
	 *
	 * They are resolved independently. `exclude_current` and
	 * `child_of="current"` are separate decisions: "do not list the page the
	 * reader is on" and "list what is filed under it" are useful together and
	 * useful apart, and gating one on the other made `child_of="current"` fall
	 * back to the whole site the moment `exclude_current` was switched off.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return array<string,mixed>
	 */
	public static function for_request( array $settings ): array {
		// Whether the listing can name the current page at all, and whether it
		// has a page tree to take a branch out of. `source` alone answers
		// neither: a composed sitemap ignores it, and each of its sections says
		// for itself what it is built from — so a placement whose saved source
		// is `authors` can still be listing pages.
		$composed = array() !== self::to_section_list( $settings['sections'] );

		// A navigation menu links to pages, so leaving the sitemap's own page
		// out of it means something. It has no `post_parent` hierarchy to walk,
		// so `child_of` does not.
		$names_pages   = $composed || in_array( $settings['source'], array( 'content', 'menu' ), true );
		$lists_content = $composed || 'content' === $settings['source'];

		if ( ! empty( $settings['exclude_current'] ) && $names_pages ) {
			$settings['exclude_self'] = self::current_post_id();
		}

		// `child_of="current"` is the form that makes this useful in a
		// shortcode — "the pages under this one" without hard-coding an ID that
		// changes between staging and live.
		if ( 'current' === $settings['child_of'] ) {
			$settings['child_of'] = self::current_post_id();
		}

		// `parent` is the same idea one level up: the same template on every
		// page of a section lists that section, so a reader always sees where
		// they are among their siblings rather than what is below them. A page
		// with no parent IS the top of its section, so it stands in for one —
		// otherwise the one page where the answer matters most would fall back
		// to the whole site.
		if ( 'parent' === $settings['child_of'] ) {
			$parent               = self::current_parent_id();
			$settings['child_of'] = $parent > 0 ? $parent : self::current_post_id();
		}

		$settings['child_of'] = max( 0, (int) $settings['child_of'] );

		// Both of these are the current page's ID, and a listing that cannot use
		// one would only be split into a cache entry per page by carrying it.
		if ( ! $lists_content ) {
			$settings['child_of'] = 0;
		}

		if ( ! $names_pages ) {
			$settings['exclude_self'] = 0;
		}

		return $settings;
	}

	/**
	 * The parent of the post the sitemap is being rendered inside.
	 *
	 * @return int Post ID, or 0 outside a post and for a top-level one.
	 */
	private static function current_parent_id(): int {
		$id = self::current_post_id();

		if ( $id <= 0 || ! function_exists( 'wp_get_post_parent_id' ) ) {
			return 0;
		}

		return max( 0, (int) wp_get_post_parent_id( $id ) );
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
	 * A date bound, or nothing.
	 *
	 * `YYYY`, `YYYY-MM` and `YYYY-MM-DD` only. WP_Date_Query would take
	 * anything `strtotime()` understands, including "last tuesday", and a
	 * sitemap whose contents depend on how a phrase was parsed is worse than
	 * one that ignores what it cannot read. A value that is not one of these
	 * three shapes is no bound at all, which is what the field being empty
	 * means, so a typo widens the listing rather than emptying it.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function to_date( $raw ): string {
		$value = trim( (string) $raw );

		if ( ! preg_match( '/^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/', $value, $parts ) ) {
			return '';
		}

		// The shape is not the date. `2026-13` and `2026-02-31` both match the
		// pattern and neither exists; WP_Date_Query documents that it lets an
		// impossible range through rather than refusing it, so an empty sitemap
		// is what a fat finger would produce. Checked here instead, where "not a
		// date" can still mean "no bound".
		$month = isset( $parts[2] ) ? (int) $parts[2] : 1;
		$day   = isset( $parts[3] ) ? (int) $parts[3] : 1;

		return checkdate( $month, $day, (int) $parts[1] ) ? $value : '';
	}

	/**
	 * Read a section list from an array or a comma/space separated string.
	 *
	 * Deliberately does not check that a slug names anything: a post type from
	 * a plugin that is momentarily deactivated must survive a save, and the
	 * builder skips what it cannot resolve anyway.
	 *
	 * A slug named twice is kept once. Listing it twice would print the same
	 * list twice, under the same heading, which nobody has ever meant.
	 *
	 * @param mixed $raw Array or separated string.
	 * @return string[]
	 */
	public static function to_section_list( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', (string) $raw ) ?: array();
		}

		$sections = array();
		foreach ( $raw as $value ) {
			$slug = self::sanitize_section( (string) $value );
			if ( '' !== $slug ) {
				$sections[ $slug ] = $slug;
			}
		}

		return array_values( $sections );
	}

	/**
	 * One section slug, in the only two shapes there are.
	 *
	 * A bare slug names a post type, a taxonomy, or one of SECTIONS. The one
	 * qualified form is `menu:<id-or-slug>`, which exists because a site can
	 * have several navigation menus and a sitemap listing both the global nav
	 * and the footer nav is an ordinary thing to want — the bare `menu` alias
	 * can only mean the one the settings screen selected.
	 *
	 * `sanitize_key()` would eat the colon, so the two halves are cleaned
	 * separately. A menu named in Japanese therefore cannot be reached this way;
	 * its ID can, and that is what the settings screen posts.
	 *
	 * @param string $value Raw slug.
	 * @return string Empty when there is nothing usable in it.
	 */
	private static function sanitize_section( string $value ): string {
		// Lowercased first: sanitize_key() would do it to a bare slug anyway, and
		// a qualifier that only worked in lower case would be a rule nobody was
		// told about.
		$value = strtolower( trim( $value ) );

		if ( 0 !== strpos( $value, 'menu:' ) ) {
			return sanitize_key( $value );
		}

		$menu = sanitize_key( substr( $value, strlen( 'menu:' ) ) );

		// `menu:` with nothing after it is not the bare `menu` alias — it is a
		// typo, and answering it with the settings screen's menu would hide it.
		return '' === $menu ? '' : 'menu:' . $menu;
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
