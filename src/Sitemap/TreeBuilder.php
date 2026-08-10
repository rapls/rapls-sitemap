<?php
/**
 * Turns the configured post types into a tree of Node objects.
 *
 * Two shapes come out of here, decided per post type:
 *
 *   hierarchical (page)     -> parent/child nesting via post_parent
 *   non-hierarchical (post) -> optionally grouped under category headings,
 *                              otherwise a flat list
 *
 * Everything is fetched in one query per post type and assembled in memory.
 * Never call get_permalink() inside a loop over an unbounded result set without
 * the posts already primed — `get_posts()` does that priming for us.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Sitemap;

use RaplsSitemap\Support\Hooks;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the sitemap node tree from the current settings.
 */
final class TreeBuilder {

	/**
	 * Effective settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Post types whose list hit the entry cap, keyed by slug.
	 *
	 * @var array<string,bool>
	 */
	private $truncated = array();

	/**
	 * Yoast's taxonomy settings, read once, or null before they are read.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $yoast_terms = null;

	/**
	 * All in One SEO's verdict on the posts asked about so far.
	 *
	 * Static, so a composed sitemap does not repeat the query once per section
	 * — every section of one render asks about different posts, and the answer
	 * for a post cannot change while that render is in flight. It lives for the
	 * request, which is exactly as long as a render does.
	 *
	 * @var array<int,bool>
	 */
	private static $aioseo_noindex = array();

	/**
	 * @param array<string,mixed> $settings Settings from Settings::get().
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build the full tree.
	 *
	 * @return Node[] Root-level nodes, in output order.
	 */
	public function build(): array {
		$roots = array();

		if ( ! empty( $this->settings['show_home'] ) ) {
			$label   = (string) $this->settings['home_label'];
			$roots[] = new Node(
				0,
				'' !== $label ? $label : get_bloginfo( 'name' ),
				home_url( '/' ),
				'home'
			);
		}

		$depth    = (int) $this->settings['depth'];
		$sections = Settings::to_section_list( $this->settings['sections'] );

		$roots = array_merge(
			$roots,
			array() === $sections ? $this->from_source( $depth ) : $this->composed( $sections, $depth )
		);

		/**
		 * Filters the assembled tree before rendering.
		 *
		 * @param Node[] $roots    Root nodes.
		 * @param array  $settings Effective settings.
		 */
		$filtered = apply_filters( Hooks::TREE, $roots, $this->settings );

		if ( ! is_array( $filtered ) ) {
			return $roots;
		}

		// The renderer reads properties straight off these, so anything that is
		// not a Node would be a fatal error rather than a wrong sitemap. A
		// filter that returns arrays — an easy mistake, since that is what the
		// tree looks like — loses its entries instead of taking the site down.
		return array_values( array_filter( $filtered, static function ( $node ) {
			return $node instanceof Node;
		} ) );
	}

	/**
	 * The nodes one source contributes, with no front-page link and no filter.
	 *
	 * Split out of build() so a composed sitemap can call it once per section
	 * without firing Hooks::TREE for each one — the filter documents itself as
	 * seeing the assembled tree, and a site that appends a node to it would
	 * otherwise get that node once per section.
	 *
	 * @param int $depth Maximum depth, 0 for unlimited.
	 * @return Node[]
	 */
	private function from_source( int $depth ): array {
		switch ( (string) $this->settings['source'] ) {
			case 'authors':
				return $this->depth_limited( $this->authors(), $depth );

			case 'archives':
				return $this->depth_limited( $this->archives(), $depth );

			case 'menu':
				return $this->depth_limited( $this->menu(), $depth );

			default:
				return $this->content( $depth );
		}
	}

	/**
	 * The `menu` source: one navigation menu, as its editors arranged it.
	 *
	 * Nothing is derived here. The order is the menu's order — `orderby` is not
	 * consulted, because re-sorting a menu alphabetically throws away the only
	 * thing that makes it worth listing — and the labels are the menu's labels,
	 * which are frequently shorter than the page titles they point at. That is
	 * the point of this source: on a site with hundreds of pages, "the routes we
	 * decided on" is a different table of contents from "everything published",
	 * and often the one a reader wants.
	 *
	 * Items are nested by `menu_item_parent`, and an item whose parent was
	 * dropped surfaces at the root rather than disappearing — the same rule the
	 * page tree follows.
	 *
	 * @return Node[]
	 */
	private function menu(): array {
		$menu = wp_get_nav_menu_object( $this->settings['menu'] );
		if ( ! $menu ) {
			return array();
		}

		// `update_post_term_cache` primes the taxonomies of the menu items
		// themselves, which nothing here reads.
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		if ( ! is_array( $items ) || array() === $items ) {
			return array();
		}

		// The exclusions run BEFORE the cap, which is the opposite of the order
		// they read in. `wp_get_nav_menu_items()` has already fetched the whole
		// menu — there is no query to bound here — so capping first would count
		// items towards the limit and then drop them, leaving a "first N shown"
		// note above fewer than N. `fetch()` reaches the same end by asking for
		// max + 1 and re-slicing, because there the query is the expensive part.
		$items = $this->menu_items_kept( $items );

		$max = $this->cap();
		if ( $max > 0 && count( $items ) > $max ) {
			$items                   = array_slice( $items, 0, $max );
			$this->truncated['menu'] = true;
		}

		$nodes = array();
		foreach ( $items as $item ) {
			$title = trim( (string) $item->title );
			if ( '' === $title ) {
				/* translators: %d: menu item ID. */
				$title = sprintf( __( '(no title) #%d', 'rapls-sitemap' ), (int) $item->ID );
			}

			$nodes[ (int) $item->ID ] = new Node( (int) $item->ID, $title, $this->menu_url( $item ), 'post' );
		}

		$roots = array();
		foreach ( $items as $item ) {
			$id     = (int) $item->ID;
			$parent = (int) $item->menu_item_parent;

			if ( $parent > 0 && isset( $nodes[ $parent ] ) ) {
				$nodes[ $parent ]->add( $nodes[ $id ] );
				continue;
			}

			$roots[] = $nodes[ $id ];
		}

		return $this->note_if_truncated( $roots, 'menu' );
	}

	/**
	 * Drop the menu items this sitemap's exclusions rule out.
	 *
	 * Only the exclusions that can be answered from what a menu item already
	 * carries, plus two that are worth one extra query each and only when their
	 * setting is on. A menu is a curated list — a few dozen rows, not a few
	 * thousand — so the queries are bounded, but they are still queries and each
	 * is issued once for the whole menu rather than once per item.
	 *
	 * A custom link cannot be excluded by any of this: it names a URL, not
	 * anything this plugin can identify. Said in the readme rather than left for
	 * a site owner to discover.
	 *
	 * @param object[] $items Menu items.
	 * @return object[]
	 */
	private function menu_items_kept( array $items ): array {
		$excluded_ids   = array_flip( $this->excluded_ids() );
		$excluded_types = (array) $this->settings['exclude_types'];

		// One query each, and only when asked for. `$protected` is the set of
		// linked posts WordPress has a password on; priming the meta cache turns
		// the noindex check from one query per item into one for the lot.
		$post_ids = array();
		foreach ( $items as $item ) {
			if ( 'post_type' === $item->type ) {
				$post_ids[] = (int) $item->object_id;
			}
		}

		$protected = array();
		if ( ! empty( $this->settings['exclude_protected'] ) && array() !== $post_ids ) {
			$protected = array_flip(
				array_map(
					'intval',
					(array) get_posts(
						array(
							'post__in'    => $post_ids,
							'post_type'   => 'any',
							'post_status' => 'publish',
							'has_password' => true,
							'fields'      => 'ids',
							'numberposts' => -1,
						)
					)
				)
			);
		}

		if ( ! empty( $this->settings['exclude_noindex'] ) && array() !== $post_ids ) {
			if ( function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $post_ids );
			}

			$this->prime_aioseo( $post_ids );
		}

		$kept = array();

		foreach ( $items as $item ) {
			$object_id = (int) $item->object_id;

			if ( 'post_type' === $item->type ) {
				// A menu item whose target was deleted out from under it. Core
				// normally clears these, and this is not a substitute for that
				// — but a row pointing at nothing cannot be excluded, counted
				// or linked usefully either.
				if ( $object_id <= 0 ) {
					continue;
				}

				if ( isset( $excluded_ids[ $object_id ] ) || isset( $protected[ $object_id ] ) ) {
					continue;
				}

				if ( in_array( (string) $item->object, $excluded_types, true ) ) {
					continue;
				}

				$post     = new \stdClass();
				$post->ID = $object_id;

				if ( ! empty( $this->settings['exclude_noindex'] ) && $this->is_noindex( $post ) ) {
					continue;
				}
			}

			if ( 'taxonomy' === $item->type && $this->term_excluded( $object_id, (string) $item->object ) ) {
				continue;
			}

			$kept[] = $item;
		}

		return $this->cascade_menu_exclusions( $kept, $items );
	}

	/**
	 * The terms holding at least one entry inside the publication window.
	 *
	 * Null when there is no window, which is the signal to list every term —
	 * the ordinary case, and the one where a category listing costs no post
	 * query at all.
	 *
	 * An ancestor of a matching term is kept too, or a category whose only
	 * matching entries are filed under its children would take them down with
	 * it. That is the same rule the term listing already follows for emptiness.
	 *
	 * @param string $post_type Post type the entries belong to.
	 * @param string $taxonomy  Taxonomy being listed.
	 * @return array<int,bool>|null
	 */
	private function terms_in_window( string $post_type, string $taxonomy ) {
		if ( array() === $this->date_query() ) {
			return null;
		}

		$keep = array();

		foreach ( $this->fetch( $post_type ) as $post ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$id          = (int) $term->term_id;
				$keep[ $id ] = true;

				foreach ( (array) get_ancestors( $id, $taxonomy, 'taxonomy' ) as $ancestor ) {
					$keep[ (int) $ancestor ] = true;
				}
			}
		}

		return $keep;
	}

	/**
	 * The publication window, if there is one.
	 *
	 * Inclusive at both ends, and either end may stand alone. The archive
	 * listing is derived from this same query, so it narrows to the window for
	 * free — a year with nothing inside the window simply is not a year this
	 * sitemap has.
	 *
	 * @return array<string,mixed>
	 */
	private function date_query(): array {
		$after  = Settings::to_date( $this->settings['date_after'] );
		$before = Settings::to_date( $this->settings['date_before'] );

		if ( '' === $after && '' === $before ) {
			return array();
		}

		$query = array( 'inclusive' => true );

		if ( '' !== $after ) {
			$query['after'] = $after;
		}

		if ( '' !== $before ) {
			$query['before'] = $before;
		}

		return $query;
	}

	/**
	 * Where a menu item points, or nowhere at all.
	 *
	 * `#` is how a menu holds open a dropdown whose parent is not itself a
	 * page. In a menu that is a real affordance; in a table of contents it is a
	 * link that goes nowhere, which is worse than plain text for everyone and
	 * worst for a screen reader. An empty URL is already how this plugin says
	 * "print the label, do not link it", so the node simply gets one and every
	 * preset styles it as the unlinked label it is.
	 *
	 * @param object $item Menu item.
	 * @return string
	 */
	private function menu_url( $item ): string {
		$url = trim( (string) $item->url );

		if ( empty( $this->settings['menu_headings'] ) ) {
			return $url;
		}

		return ( '' === $url || '#' === $url ) ? '' : $url;
	}

	/**
	 * Is this term excluded, itself or through an ancestor?
	 *
	 * The term query says `exclude_tree`, so excluding a parent category takes
	 * its children with it. A menu item naming one of those children has to go
	 * the same way, or the promise holds everywhere except the one listing a
	 * site is most likely to hand-build.
	 *
	 * @param int    $term_id  Term the menu item points at.
	 * @param string $taxonomy Its taxonomy.
	 * @return bool
	 */
	private function term_excluded( int $term_id, string $taxonomy ): bool {
		$excluded = array_map( 'intval', (array) $this->settings['exclude_terms'] );

		if ( array() === $excluded || $term_id <= 0 ) {
			return false;
		}

		if ( in_array( $term_id, $excluded, true ) ) {
			return true;
		}

		if ( '' === $taxonomy || ! function_exists( 'get_ancestors' ) ) {
			return false;
		}

		$ancestors = array_map( 'intval', (array) get_ancestors( $term_id, $taxonomy, 'taxonomy' ) );

		return array() !== array_intersect( $ancestors, $excluded );
	}

	/**
	 * Take the descendants of an ID-excluded menu item with it.
	 *
	 * Only `exclude_ids` cascades, exactly as it does in the page tree — naming
	 * an ID means "not this branch". The others do not: leaving the sitemap's
	 * own page out of the menu, or dropping one item because its target is
	 * noindexed, says nothing about what hangs below it, and those children
	 * surface at the root the way any item with a missing parent does.
	 *
	 * @param object[] $kept  Items that survived the per-item rules.
	 * @param object[] $items Every item, so a dropped parent is still known.
	 * @return object[]
	 */
	private function cascade_menu_exclusions( array $kept, array $items ): array {
		$ids = array_map( 'intval', (array) $this->settings['exclude_ids'] );
		if ( array() === $ids ) {
			return $kept;
		}

		// The menu item IDs whose target was named, whether or not the item
		// itself survived the loop above.
		$cascading = array();
		foreach ( $items as $item ) {
			if ( 'post_type' === $item->type && in_array( (int) $item->object_id, $ids, true ) ) {
				$cascading[ (int) $item->ID ] = true;
			}
		}

		if ( array() === $cascading ) {
			return $kept;
		}

		// One pass per level: an item joins once its parent has, so a
		// grandchild goes with its grandparent.
		do {
			$added = false;

			foreach ( $items as $item ) {
				$id = (int) $item->ID;

				if ( isset( $cascading[ $id ] ) || ! isset( $cascading[ (int) $item->menu_item_parent ] ) ) {
					continue;
				}

				$cascading[ $id ] = true;
				$added            = true;
			}
		} while ( $added );

		return array_values(
			array_filter(
				$kept,
				static function ( $item ) use ( $cascading ) {
					return ! isset( $cascading[ (int) $item->ID ] );
				}
			)
		);
	}

	/**
	 * Several sections in one placement.
	 *
	 * The shape a site migrating from WP Sitemap Page expects: pages, then
	 * posts, then categories, then authors, then the date archives, all from one
	 * shortcode. Each section is built by a builder of its own carrying the same
	 * settings with that section's overrides on top, so every behaviour the
	 * single-source sitemap has — exclusions, caps, ordering, grouping, the
	 * truncation note — applies inside each section without being written twice.
	 *
	 * `show_home` is switched off for those sub-builders: the front-page link
	 * belongs to the sitemap, not to its first section.
	 *
	 * @param string[] $sections Section slugs, in output order.
	 * @param int      $depth    Maximum depth, 0 for unlimited.
	 * @return Node[]
	 */
	private function composed( array $sections, int $depth ): array {
		$built = array();

		foreach ( $sections as $slug ) {
			$section = $this->section( $slug );
			if ( null === $section ) {
				continue;
			}

			$builder = new self(
				array_merge(
					$this->settings,
					$section['settings'],
					// Without this the sub-builder would compose the same
					// sections again, each of those would compose them again,
					// and the recursion would only stop at the memory limit.
					array( 'sections' => array(), 'show_home' => false )
				)
			);

			$nodes = $builder->from_source( $depth );
			if ( array() === $nodes ) {
				continue;
			}

			$section['nodes'] = $nodes;
			$built[]          = $section;
		}

		// Same rule as the post-type sections: one list needs no label to tell
		// it apart from the others.
		if ( empty( $this->settings['section_headings'] ) || count( $built ) < 2 ) {
			return array_merge( array(), ...array_column( $built, 'nodes' ) );
		}

		$roots = array();
		foreach ( $built as $section ) {
			$heading = new Node( 0, $section['label'], $section['url'], 'section' );

			foreach ( $section['nodes'] as $node ) {
				$heading->add( $node );
			}

			$roots[] = $heading;
		}

		return $roots;
	}

	/**
	 * Resolve one section slug into its overrides, heading label, and link.
	 *
	 * Three kinds, in the order a slug is tried: an alias from
	 * Settings::SECTIONS, a post type, a taxonomy. Anything that resolves to
	 * none of them is skipped rather than reported — a slug can name a post type
	 * a plugin registers, and a sitemap that prints a complaint to every visitor
	 * because that plugin is being updated is worse than one section short.
	 *
	 * @param string $slug Section slug.
	 * @return array{settings:array,label:string,url:string}|null
	 */
	private function section( string $slug ) {
		if ( isset( Settings::SECTIONS[ $slug ] ) ) {
			$overrides = Settings::SECTIONS[ $slug ];

			if ( isset( $overrides['taxonomy'] ) ) {
				return $this->taxonomy_section( (string) $overrides['taxonomy'], $overrides );
			}

			return array(
				'settings' => $overrides,
				'label'    => $this->source_label( (string) $overrides['source'] ),
				'url'      => '',
			);
		}

		// `menu:<id-or-slug>` names one menu among several, which the bare alias
		// cannot: it can only mean whatever the settings screen selected.
		if ( 0 === strpos( $slug, 'menu:' ) ) {
			$identifier = substr( $slug, strlen( 'menu:' ) );

			return array(
				'settings' => array( 'source' => 'menu', 'menu' => $identifier ),
				'label'    => $this->menu_label( $identifier ),
				'url'      => '',
			);
		}

		if ( post_type_exists( $slug ) ) {
			return array(
				'settings' => array( 'source' => 'content', 'post_types' => array( $slug ) ),
				'label'    => $this->post_type_label( $slug ),
				'url'      => $this->post_type_url( $slug ),
			);
		}

		if ( taxonomy_exists( $slug ) ) {
			return $this->taxonomy_section( $slug, array() );
		}

		return null;
	}

	/**
	 * The heading a whole-source section is given.
	 *
	 * A menu gets its own name rather than the generic label: which menu this is
	 * is the one thing the source name cannot say.
	 *
	 * @param string $source One of Settings::SOURCES.
	 * @return string
	 */
	private function source_label( string $source ): string {
		if ( 'authors' === $source ) {
			return __( 'Authors', 'rapls-sitemap' );
		}

		if ( 'menu' === $source ) {
			return $this->menu_label( (string) $this->settings['menu'] );
		}

		return __( 'Archives', 'rapls-sitemap' );
	}

	/**
	 * A menu's own name, for the heading over it.
	 *
	 * Two menus in one sitemap is the reason `menu:<id-or-slug>` exists, and
	 * "Navigation menu" twice would tell a reader nothing about which is which.
	 *
	 * @param string $identifier Menu ID, slug or name.
	 * @return string
	 */
	private function menu_label( string $identifier ): string {
		$menu = wp_get_nav_menu_object( $identifier );

		return ( $menu && '' !== trim( (string) $menu->name ) )
			? (string) $menu->name
			: __( 'Navigation menu', 'rapls-sitemap' );
	}

	/**
	 * A section that lists a taxonomy's terms rather than any posts.
	 *
	 * The post type matters even though no post is listed: `build_post_type()`
	 * only reaches the term listing for a post type with no hierarchy of its
	 * own, since a hierarchical type nests by parent instead. Handing it a
	 * taxonomy attached only to pages would quietly list the pages. So the
	 * section takes the first flat post type the taxonomy is attached to, and
	 * declines to exist when there is none.
	 *
	 * @param string $taxonomy  Taxonomy slug.
	 * @param array  $overrides Settings overrides already decided for it.
	 * @return array{settings:array,label:string,url:string}|null
	 */
	private function taxonomy_section( string $taxonomy, array $overrides ) {
		$object = get_taxonomy( $taxonomy );
		if ( ! $object ) {
			return null;
		}

		// An excluded taxonomy cannot be a section: primary_taxonomy() would
		// refuse it, grouping would switch itself off, and the section would
		// silently become a list of posts.
		if ( in_array( $taxonomy, (array) $this->settings['exclude_tax'], true ) ) {
			return null;
		}

		$excluded = (array) $this->settings['exclude_types'];

		$types = array_values(
			array_filter(
				(array) $object->object_type,
				static function ( $type ) use ( $excluded ) {
					// An excluded type is skipped here rather than left to
					// build_post_type(), which would return nothing and take the
					// whole section with it — even where the taxonomy has
					// another post type that is perfectly listable.
					return post_type_exists( $type )
						&& ! is_post_type_hierarchical( $type )
						&& ! in_array( $type, $excluded, true );
				}
			)
		);

		if ( array() === $types ) {
			return null;
		}

		$label = ! empty( $object->labels->name ) ? (string) $object->labels->name : $taxonomy;

		return array(
			'settings' => array_merge(
				array(
					'source'        => 'content',
					// One, not all of them: the term listing ignores the post
					// type, so a second would print the same terms again.
					'post_types'    => array( $types[0] ),
					'group_by_term' => true,
					'term_mode'     => 'terms_only',
					'taxonomy'      => $taxonomy,
					'nest_terms'    => ! empty( $object->hierarchical ) && ! empty( $this->settings['nest_terms'] ),
				),
				$overrides
			),
			'label'    => $label,
			// A taxonomy has no single archive to link a heading to.
			'url'      => '',
		);
	}

	/**
	 * The `content` source: every configured post type, optionally sectioned.
	 *
	 * Depth is applied to each post type's own tree *before* a section heading
	 * is put above it, so turning headings on does not silently cost a level of
	 * nesting to everything underneath.
	 *
	 * @param int $depth Maximum depth, 0 for unlimited.
	 * @return Node[]
	 */
	private function content( int $depth ): array {
		$sections = array();

		foreach ( (array) $this->settings['post_types'] as $post_type ) {
			$post_type = (string) $post_type;
			$nodes     = $this->depth_limited( $this->build_post_type( $post_type ), $depth );

			if ( array() === $nodes ) {
				continue;
			}

			$sections[ $post_type ] = $this->note_if_truncated( $nodes, $post_type );
		}

		// One list needs no label to tell it apart from the others.
		if ( empty( $this->settings['section_headings'] ) || count( $sections ) < 2 ) {
			return array_merge( array(), ...array_values( $sections ) );
		}

		$roots = array();
		foreach ( $sections as $post_type => $nodes ) {
			$heading = new Node( 0, $this->post_type_label( $post_type ), $this->post_type_url( $post_type ), 'section' );

			foreach ( $nodes as $node ) {
				$heading->add( $node );
			}

			$roots[] = $heading;
		}

		return $roots;
	}

	/**
	 * Append the "and more" note when this key's query hit the cap.
	 *
	 * Every source funnels through here rather than only the content listing:
	 * a truncated archive is *more* misleading than a truncated page list,
	 * because a missing year looks like a year with nothing published in it.
	 *
	 * @param Node[] $nodes Nodes produced for this key.
	 * @param string $key   Whatever `fetch()`-alike recorded truncation under.
	 * @return Node[]
	 */
	private function note_if_truncated( array $nodes, string $key ): array {
		if ( empty( $this->truncated[ $key ] ) ) {
			return $nodes;
		}

		$nodes[] = $this->more_note( (int) $this->settings['max_entries'] );

		return $nodes;
	}

	/**
	 * The node that says a list stopped short.
	 *
	 * @param int $shown How many entries were listed.
	 * @return Node
	 */
	private function more_note( int $shown ): Node {
		return new Node(
			0,
			sprintf(
				/* translators: %s: number of entries shown. */
				__( 'Only the first %s entries are listed.', 'rapls-sitemap' ),
				number_format_i18n( $shown )
			),
			'',
			'more'
		);
	}

	/**
	 * The entry cap, or 0 when there is none.
	 *
	 * @return int
	 */
	private function cap(): int {
		return max( 0, (int) $this->settings['max_entries'] );
	}

	/**
	 * A post type's plural label, for a section heading.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		return ( $object && ! empty( $object->labels->name ) ) ? (string) $object->labels->name : $post_type;
	}

	/**
	 * Where a section heading points, when the post type has an archive.
	 *
	 * @param string $post_type Post type slug.
	 * @return string Empty when there is no archive to link to.
	 */
	private function post_type_url( string $post_type ): string {
		$link = get_post_type_archive_link( $post_type );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Apply the depth limit, if there is one.
	 *
	 * @param Node[] $nodes Nodes.
	 * @param int    $depth Maximum depth, 0 for unlimited.
	 * @return Node[]
	 */
	private function depth_limited( array $nodes, int $depth ): array {
		return $depth > 0 ? $this->prune( $nodes, $depth ) : $nodes;
	}

	/**
	 * Build the nodes contributed by one post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return Node[]
	 */
	private function build_post_type( string $post_type ): array {
		// A page subtree is the scope, so a flat post type has nothing to
		// contribute to it. Listing every blog post beside "the pages under
		// this one" would be a different sitemap wearing the same settings.
		if ( (int) $this->settings['child_of'] > 0 && ! is_post_type_hierarchical( $post_type ) ) {
			return array();
		}

		// An explicit exclusion wins over the inclusion list, so a post type a
		// plugin registers later can be kept out for good rather than only
		// until somebody re-saves the settings screen.
		if ( in_array( $post_type, (array) $this->settings['exclude_types'], true ) ) {
			return array();
		}

		$taxonomy  = $this->primary_taxonomy( $post_type );
		$grouping  = ! empty( $this->settings['group_by_term'] ) && '' !== $taxonomy
			&& ! is_post_type_hierarchical( $post_type );
		$terms_only = $grouping && 'terms_only' === $this->settings['term_mode'];

		// A category-only listing needs no posts at all — skip the query, unless
		// a publication window has been set. A window is a claim about what the
		// sitemap covers, and a category holding nothing from the school year
		// the page is about does not belong on it. Then the posts have to be
		// asked for after all, to find out which categories they are in.
		if ( $terms_only ) {
			return $this->term_tree( $taxonomy, $this->terms_in_window( $post_type, $taxonomy ) );
		}

		$posts = $this->fetch( $post_type );
		if ( array() === $posts ) {
			return array();
		}

		if ( is_post_type_hierarchical( $post_type ) ) {
			return $this->nest( $this->descendants_of( $posts, (int) $this->settings['child_of'] ) );
		}

		if ( $grouping ) {
			return $this->group( $posts, $taxonomy );
		}

		return array_map( array( $this, 'to_node' ), $posts );
	}

	/**
	 * Fetch the published entries of one post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return \WP_Post[]
	 */
	private function fetch( string $post_type ): array {
		$noindex = ! empty( $this->settings['exclude_noindex'] );
		$max     = $this->cap();
		$offset  = max( 0, (int) $this->settings['offset'] );

		$args = array_merge(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				// One more than the cap, so the extra row is the evidence that
				// there was more to show. Counting separately would mean a
				// second query on every render just to say "and 40 more".
				'posts_per_page'      => $max > 0 ? $max + 1 : -1,
				'offset'              => $offset,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				// Meta is only primed when something is actually going to read
				// it; otherwise this is a wasted query on every render.
				'update_post_meta_cache' => $noindex,
				/*
				 * Load-bearing, and not obviously so. get_posts() defaults this
				 * to true, which switches off the query filters — and query
				 * filters are exactly how WPML and Polylang limit results to
				 * the current language. Setting it false is the whole of this
				 * plugin's multilingual support; removing it as redundant
				 * would make every sitemap list every language at once.
				 */
				'suppress_filters'    => false,
			),
			$this->order_args( $post_type )
		);

		if ( ! empty( $this->settings['exclude_protected'] ) ) {
			$args['has_password'] = false;
		}

		$exclude = $this->excluded_ids();
		if ( array() !== $exclude ) {
			$args['post__not_in'] = $exclude;
		}

		$dates = $this->date_query();
		if ( array() !== $dates ) {
			$args['date_query'] = array( $dates );
		}

		$exclude_terms = (array) $this->settings['exclude_terms'];
		if ( array() !== $exclude_terms ) {
			$taxonomy = $this->primary_taxonomy( $post_type );
			if ( '' !== $taxonomy ) {
				$args['tax_query'] = array(
					array(
						'taxonomy'         => $taxonomy,
						'field'            => 'term_id',
						'terms'            => $exclude_terms,
						'operator'         => 'NOT IN',
						'include_children' => true,
					),
				);
			}
		}

		/**
		 * Filters the query args for one post type.
		 *
		 * @param array  $args      Query args.
		 * @param string $post_type Post type slug.
		 */
		$filtered = apply_filters( Hooks::QUERY_ARGS, $args, $post_type );
		$args     = is_array( $filtered ) ? $filtered : $args;

		$posts = get_posts( $args );

		if ( ! is_array( $posts ) ) {
			return array();
		}

		/*
		 * Whether the query had more to give, decided here — on what came back
		 * from the database, before anything is filtered out of it.
		 *
		 * Deciding it after the noindex pass would ask the wrong question. The
		 * query asked for max + 1 and the extra row is the evidence there was
		 * more; if the noindex filter then removes even one post, the surviving
		 * count drops to max or below and the evidence is gone — so the list
		 * would stop short of the site's content and say nothing about it,
		 * which is the one outcome the cap is written to avoid.
		 */
		$more = $max > 0 && count( $posts ) > $max;

		// Filtered in PHP rather than with a meta_query: the SEO plugins store
		// noindex in different shapes, and a meta_query would also drop every
		// post with no meta row at all, which is most of them.
		if ( $noindex ) {
			$this->prime_aioseo( array_map( static function ( $post ) {
				return (int) $post->ID;
			}, $posts ) );

			$posts = array_values(
				array_filter(
					$posts,
					function ( $post ) {
						return ! $this->is_noindex( $post );
					}
				)
			);
		}

		if ( $max > 0 && count( $posts ) > $max ) {
			$posts = array_slice( $posts, 0, $max );
		}

		if ( $more ) {
			// Recorded rather than rendered here: the note belongs at the end
			// of the list this post type produces, which the caller assembles.
			$this->truncated[ $post_type ] = true;
		}

		return $posts;
	}

	/**
	 * The ordering half of the query args.
	 *
	 * `default` keeps each post type's natural order: pages by the menu order
	 * an editor dragged them into, everything else newest first.
	 *
	 * A word on 五十音順: `title` sorts on the database collation, so it is
	 * correct for kana and Latin titles and *not* correct for kanji — MySQL has
	 * no idea that 大阪 reads おおさか. Sites that need a true kana ordering
	 * store the reading in a custom field and point `sort_meta_key` at it,
	 * which is what `meta` is for. Offering `title` as "五十音順" without
	 * saying this would be a lie the first time somebody adds a kanji title.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string,mixed>
	 */
	private function order_args( string $post_type ): array {
		$orderby = (string) $this->settings['orderby'];
		$order   = 'ASC' === strtoupper( (string) $this->settings['order'] ) ? 'ASC' : 'DESC';

		if ( 'default' === $orderby ) {
			return is_post_type_hierarchical( $post_type )
				? array( 'orderby' => 'menu_order title', 'order' => 'ASC' )
				: array( 'orderby' => 'date', 'order' => 'DESC' );
		}

		// A random order and a render cache are a contradiction; the cache wins
		// until it expires, so this shuffles per cache entry, not per view.
		if ( 'rand' === $orderby ) {
			return array( 'orderby' => 'rand' );
		}

		if ( 'meta' === $orderby ) {
			$key = (string) $this->settings['sort_meta_key'];

			// Without a key there is nothing to sort on; falling back to title
			// beats returning the posts in an arbitrary order.
			if ( '' === $key ) {
				return array( 'orderby' => 'title', 'order' => $order );
			}

			return array(
				'orderby'  => 'meta_value',
				'meta_key' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'    => $order,
			);
		}

		return array( 'orderby' => $orderby, 'order' => $order );
	}

	/**
	 * Every ID kept out of the query: the listed ones and the current page.
	 *
	 * @return int[]
	 */
	private function excluded_ids(): array {
		$ids  = array_map( 'intval', (array) $this->settings['exclude_ids'] );
		$self = (int) $this->settings['exclude_self'];

		if ( $self > 0 ) {
			$ids[] = $self;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Narrow a post list to what sits under one page.
	 *
	 * Worked out from the `post_parent` links already in hand rather than with
	 * another query, and the root does not have to be among them — only its ID
	 * is needed. That matters, because the most useful form of this is
	 * `child_of="current"` on a page that `exclude_current` has just taken out
	 * of its own listing.
	 *
	 * The root is never included. WordPress means "descendants of" by
	 * `child_of`, and a heading for the page the reader is already on says
	 * nothing.
	 *
	 * @param \WP_Post[] $posts Flat post list.
	 * @param int        $root  Page to descend from; 0 for the whole site.
	 * @return \WP_Post[]
	 */
	private function descendants_of( array $posts, int $root ): array {
		if ( $root <= 0 ) {
			return $posts;
		}

		$inside = array( $root => true );

		// One pass per level of nesting: a page joins once its parent has.
		do {
			$added = false;

			foreach ( $posts as $post ) {
				$id = (int) $post->ID;

				if ( isset( $inside[ $id ] ) || ! isset( $inside[ (int) $post->post_parent ] ) ) {
					continue;
				}

				$inside[ $id ] = true;
				$added         = true;
			}
		} while ( $added );

		unset( $inside[ $root ] );

		return array_values(
			array_filter(
				$posts,
				static function ( $post ) use ( $inside ) {
					return isset( $inside[ (int) $post->ID ] );
				}
			)
		);
	}

	/**
	 * Nest posts by `post_parent`.
	 *
	 * Two kinds of absent parent, and they mean different things:
	 *
	 * - **Listed in `exclude_ids`.** Removing a page is meant to remove the
	 *   branch, which is what the settings screen promises and what
	 *   `exclude_tree` already does for categories. The descendants go too,
	 *   however deep.
	 * - **Absent for any other reason** — unpublished, filtered away, or simply
	 *   the current page kept out of its own list. The child surfaces at the
	 *   root rather than vanishing, because an orphaned page is still a real
	 *   page and nobody asked for it to go.
	 *
	 * @param \WP_Post[] $posts Flat post list.
	 * @return Node[] Root nodes.
	 */
	private function nest( array $posts ): array {
		$nodes = array();
		foreach ( $posts as $post ) {
			$nodes[ (int) $post->ID ] = $this->to_node( $post );
		}

		// Only the listed exclusions cascade, and a child dropped this way is
		// itself an exclusion for the next pass — hence the loop rather than a
		// single sweep, so a grandchild goes with its grandparent.
		$cascading = array_flip( array_map( 'intval', (array) $this->settings['exclude_ids'] ) );

		do {
			$dropped = false;

			foreach ( $posts as $post ) {
				$id     = (int) $post->ID;
				$parent = (int) $post->post_parent;

				if ( ! isset( $nodes[ $id ] ) || $parent <= 0 || ! isset( $cascading[ $parent ] ) ) {
					continue;
				}

				unset( $nodes[ $id ] );
				$cascading[ $id ] = true;
				$dropped          = true;
			}
		} while ( $dropped );

		$roots = array();
		foreach ( $posts as $post ) {
			$id     = (int) $post->ID;
			$parent = (int) $post->post_parent;

			if ( ! isset( $nodes[ $id ] ) ) {
				continue;
			}

			if ( $parent > 0 && isset( $nodes[ $parent ] ) ) {
				$nodes[ $parent ]->add( $nodes[ $id ] );
				continue;
			}

			$roots[] = $nodes[ $id ];
		}

		return $roots;
	}

	/**
	 * Group posts under headings for each term of a taxonomy.
	 *
	 * A post in several terms appears under each of them — that is what readers
	 * expect from a table of contents. Posts in no term at all follow the
	 * groups as a flat tail.
	 *
	 * Child categories are nested inside their parents when `nest_terms` is on.
	 * Each heading therefore reads: sub-categories first, then its own posts.
	 *
	 * @param \WP_Post[] $posts    Flat post list.
	 * @param string     $taxonomy Taxonomy slug.
	 * @return Node[] Term heading nodes, then any ungrouped posts.
	 */
	private function group( array $posts, string $taxonomy ): array {
		$terms = $this->fetch_terms( $taxonomy );
		if ( array() === $terms ) {
			return array_map( array( $this, 'to_node' ), $posts );
		}

		$show_count = ! empty( $this->settings['show_count'] );

		$nodes = array();
		foreach ( $terms as $term ) {
			$node = new Node(
				(int) $term->term_id,
				$term->name,
				(string) get_term_link( $term ),
				'term'
			);

			if ( $show_count ) {
				$node->count = (int) $term->count;
			}

			$nodes[ (int) $term->term_id ] = $node;
		}

		// Nest BEFORE attaching posts, so sub-categories precede the parent's
		// own entries instead of being buried under them.
		$roots = $this->nest_terms( $terms, $nodes );

		$by_id = array();
		foreach ( $posts as $post ) {
			$by_id[ (int) $post->ID ] = $post;
		}

		// With this off, the first term to claim a post keeps it — which is why
		// the loop below checks `$claimed` before adding rather than only
		// after. Terms are walked in the order get_terms returned them, so the
		// choice is at least stable between renders.
		$duplicate = ! empty( $this->settings['duplicate_in_terms'] );

		// A separate cap from max_entries, and a different job: that one bounds
		// the query, this one bounds how long any single group gets on the
		// page. A category with four hundred posts in it makes the rest of the
		// sitemap unreachable without scrolling past all of them.
		$per_term = max( 0, (int) $this->settings['max_per_term'] );

		$claimed = array();
		foreach ( $terms as $term ) {
			$ids = get_objects_in_term( array( (int) $term->term_id ), $taxonomy );
			if ( is_wp_error( $ids ) ) {
				continue;
			}

			$node  = $nodes[ (int) $term->term_id ];
			$shown = 0;
			$more  = false;

			// Membership comes from the term, ordering from $posts — walk $posts
			// so each group keeps the post type's configured sort order.
			$members = array_flip( array_map( 'intval', $ids ) );
			foreach ( $posts as $post ) {
				$id = (int) $post->ID;
				if ( ! isset( $members[ $id ] ) ) {
					continue;
				}
				if ( ! $duplicate && isset( $claimed[ $id ] ) ) {
					continue;
				}

				// Claimed either way: with duplication off, a post the cap kept
				// out of this group has still been spoken for, and letting the
				// next group take it would put it somewhere arbitrary.
				$claimed[ $id ] = true;

				if ( $per_term > 0 && $shown >= $per_term ) {
					$more = true;
					continue;
				}

				$node->add( $this->to_node( $by_id[ $id ] ) );
				$shown++;
			}

			if ( $more ) {
				$node->add( $this->more_note( $per_term ) );
			}
		}

		// hide_empty is off so ancestors survive; empty branches go here instead.
		$groups = $this->drop_postless_terms( $roots );

		if ( $show_count ) {
			$this->count_entries( $groups );
		}

		foreach ( $posts as $post ) {
			if ( ! isset( $claimed[ (int) $post->ID ] ) ) {
				$groups[] = $this->to_node( $post );
			}
		}

		return $this->note_if_truncated( $groups, self::term_key( $taxonomy ) );
	}

	/**
	 * Replace each heading's count with the entries actually beneath it.
	 *
	 * A term's own count is how many posts are assigned to it — not how many
	 * this sitemap ends up showing, which the exclusions, the noindex filter
	 * and the entry cap have all had a say in. Printing the former next to the
	 * latter is a number that contradicts the list under it, so where entries
	 * are on screen they are what gets counted.
	 *
	 * @param Node[] $nodes Nodes to walk.
	 * @return int Entries in this level and below.
	 */
	private function count_entries( array $nodes ): int {
		$total = 0;

		foreach ( $nodes as $node ) {
			// The "and more" line is a note about the list, not a member of it.
			// Counting it would put a category at one above what it shows.
			if ( 'more' === $node->kind ) {
				continue;
			}

			$below = $this->count_entries( $node->children );

			if ( 'term' === $node->kind ) {
				$node->count = $below;
				$total      += $below;
				continue;
			}

			$total += 1 + $below;
		}

		return $total;
	}

	/**
	 * Build a category tree with no posts in it at all.
	 *
	 * Terms holding nothing anywhere in their subtree are dropped — a table of
	 * contents pointing at empty archives helps nobody.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return Node[]
	 */
	private function term_tree( string $taxonomy, ?array $only = null ): array {
		$terms = $this->fetch_terms( $taxonomy );
		if ( array() === $terms ) {
			return array();
		}

		if ( ! empty( $this->settings['exclude_noindex'] ) ) {
			$terms = array_values(
				array_filter(
					$terms,
					function ( $term ) use ( $taxonomy ) {
						return ! $this->is_term_noindex( (int) $term->term_id, $taxonomy );
					}
				)
			);

			if ( array() === $terms ) {
				return array();
			}
		}

		if ( null !== $only ) {
			$terms = array_values(
				array_filter(
					$terms,
					static function ( $term ) use ( $only ) {
						return isset( $only[ (int) $term->term_id ] );
					}
				)
			);

			if ( array() === $terms ) {
				return array();
			}
		}

		$show_count = ! empty( $this->settings['show_count'] );

		$nodes  = array();
		$counts = array();
		foreach ( $terms as $term ) {
			$id            = (int) $term->term_id;
			$nodes[ $id ]  = new Node( $id, $term->name, (string) get_term_link( $term ), 'term' );
			$counts[ $id ] = (int) $term->count;

			if ( $show_count ) {
				$nodes[ $id ]->count = (int) $term->count;
			}
		}

		$roots = $this->nest_terms( $terms, $nodes );

		return $this->note_if_truncated( $this->drop_empty_terms( $roots, $counts ), self::term_key( $taxonomy ) );
	}

	/**
	 * Fetch the terms of a taxonomy for grouping.
	 *
	 * `hide_empty` is off on purpose: a parent category whose posts all live in
	 * its children counts as empty, and dropping it here would orphan the
	 * children. Emptiness is decided after the tree exists.
	 *
	 * `exclude_tree` (not `exclude`) removes an excluded term's descendants too,
	 * so excluding a parent cannot leave its children behind at the root.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return object[] Term objects; empty on error.
	 */
	private function fetch_terms( string $taxonomy ): array {
		$cap = $this->cap();

		$args = array(
			'taxonomy'     => $taxonomy,
			'hide_empty'   => false,
			'exclude_tree' => (array) $this->settings['exclude_terms'],
			// Alphabetical unless told otherwise, which is what get_terms()
			// would have done anyway — but saying it is what lets a site say
			// something else. A category order is frequently a decision rather
			// than an alphabet: 診療科, 地域, 商品カテゴリー.
			'orderby'      => (string) $this->settings['term_orderby'],
			'order'        => 'DESC' === strtoupper( (string) $this->settings['term_order'] ) ? 'DESC' : 'ASC',
		);

		// Counts only mean what a reader expects when nesting is off. With it
		// on, a parent shows its own direct assignments while displaying its
		// children's entries too, so the number contradicts the list beneath
		// it. pad_counts folds the descendants in.
		if ( ! empty( $this->settings['show_count'] ) && ! empty( $this->settings['nest_terms'] ) ) {
			$args['pad_counts'] = true;
		}

		// Bounded for the same reason posts and users are. A tag-heavy blog or
		// a large store can have more terms than it is safe to load at once.
		if ( $cap > 0 ) {
			$args['number'] = $cap + 1;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		if ( $cap > 0 && count( $terms ) > $cap ) {
			$terms = array_slice( $terms, 0, $cap );
			$this->truncated[ self::term_key( $taxonomy ) ] = true;
		}

		return $terms;
	}

	/**
	 * The truncation key for one taxonomy.
	 *
	 * Namespaced away from post type slugs, which share the same array — a
	 * taxonomy and a post type can legitimately have the same name.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private static function term_key( string $taxonomy ): string {
		return 'tax:' . $taxonomy;
	}

	/**
	 * Nest term nodes by `parent`, honouring the `nest_terms` setting.
	 *
	 * A term whose parent is missing from the set (excluded, or filtered away)
	 * surfaces at the root, exactly as an orphaned page does.
	 *
	 * @param object[]        $terms Term objects.
	 * @param array<int,Node> $nodes Term nodes, keyed by term ID.
	 * @return Node[] Root nodes.
	 */
	private function nest_terms( array $terms, array $nodes ): array {
		$nest  = ! empty( $this->settings['nest_terms'] );
		$roots = array();

		foreach ( $terms as $term ) {
			$id     = (int) $term->term_id;
			$parent = (int) $term->parent;

			if ( $nest && $parent > 0 && isset( $nodes[ $parent ] ) ) {
				$nodes[ $parent ]->add( $nodes[ $id ] );
				continue;
			}

			$roots[] = $nodes[ $id ];
		}

		return $roots;
	}

	/**
	 * Recursively drop term branches containing no post anywhere.
	 *
	 * @param Node[] $nodes Nodes at this level.
	 * @return Node[]
	 */
	private function drop_postless_terms( array $nodes ): array {
		$kept = array();

		foreach ( $nodes as $node ) {
			if ( 'term' !== $node->kind ) {
				$kept[] = $node;
				continue;
			}

			$node->children = $this->drop_postless_terms( $node->children );
			if ( $node->has_children() ) {
				$kept[] = $node;
			}
		}

		return $kept;
	}

	/**
	 * Recursively drop term branches whose subtree holds no entries.
	 *
	 * Used by the posts-free listing, where emptiness comes from each term's
	 * own count rather than from attached nodes.
	 *
	 * @param Node[]         $nodes  Nodes at this level.
	 * @param array<int,int> $counts term ID => entry count.
	 * @return Node[]
	 */
	private function drop_empty_terms( array $nodes, array $counts ): array {
		$kept = array();

		foreach ( $nodes as $node ) {
			$node->children = $this->drop_empty_terms( $node->children, $counts );

			$own = isset( $counts[ $node->id ] ) ? $counts[ $node->id ] : 0;
			if ( $own > 0 || $node->has_children() ) {
				$kept[] = $node;
			}
		}

		return $kept;
	}

	/**
	 * The taxonomy used for grouping and term exclusion on a post type.
	 *
	 * An explicit `taxonomy` setting wins, which is how a flat taxonomy such as
	 * `post_tag` gets listed at all. Otherwise the first public hierarchical
	 * taxonomy is picked (`category` for `post`), and a post type with only
	 * flat taxonomies is left ungrouped rather than grouped by something
	 * arbitrary.
	 *
	 * @param string $post_type Post type slug.
	 * @return string Taxonomy slug, or '' when there is none.
	 */
	private function primary_taxonomy( string $post_type ): string {
		$excluded = (array) $this->settings['exclude_tax'];

		$configured = (string) $this->settings['taxonomy'];
		if ( '' !== $configured ) {
			if ( in_array( $configured, $excluded, true ) ) {
				return '';
			}
			return in_array( $configured, get_object_taxonomies( $post_type, 'names' ), true ) ? $configured : '';
		}

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, $excluded, true ) ) {
				continue;
			}
			if ( $taxonomy->public && $taxonomy->hierarchical ) {
				return $taxonomy->name;
			}
		}

		return '';
	}

	/**
	 * Has an SEO plugin or theme marked this post noindex?
	 *
	 * WordPress stores nothing per post, so each source has to be read on its
	 * own terms. Only sources whose storage has been verified against their
	 * source code are listed here; guessing at another one's meta key would
	 * risk hiding content nobody marked. Everything else hooks the filter.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_noindex( $post ): bool {
		$id = (int) $post->ID;

		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return $this->filter_noindex( true, $id );
		}

		$rank_math = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return $this->filter_noindex( true, $id );
		}

		// SEO SIMPLE PACK stores one string: 'noindex', 'noindex,nofollow', or
		// the 'noindex,follow' its own metabox rewrites on sight. Matching the
		// substring covers all three without pinning the list.
		$ssp = (string) get_post_meta( $id, 'ssp_meta_robots', true );
		if ( '' !== $ssp && false !== strpos( $ssp, 'noindex' ) ) {
			return $this->filter_noindex( true, $id );
		}

		// SEOPress. The key reads as "index" and the value as "yes", but the
		// box it belongs to is "do not show this in search results".
		if ( 'yes' === (string) get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return $this->filter_noindex( true, $id );
		}

		// The SEO Framework keeps a tri-state: 1 forces noindex, -1 forces
		// index, 0 defers to the site default. Only the first is an answer, so
		// this must be a comparison and not a truth test — -1 is truthy and
		// means the opposite.
		if ( (int) get_post_meta( $id, '_genesis_noindex', true ) > 0 ) {
			return $this->filter_noindex( true, $id );
		}

		if ( $this->cocoon_noindex( $id ) ) {
			return $this->filter_noindex( true, $id );
		}

		// Answered from what prime_aioseo() was told to ask about. Both callers
		// prime before they check, so a miss here means AIOSEO is not active
		// rather than that the post was never asked about.
		if ( ! empty( self::$aioseo_noindex[ $id ] ) ) {
			return $this->filter_noindex( true, $id );
		}

		return $this->filter_noindex( false, $id );
	}

	/**
	 * Has an SEO plugin marked this term noindex?
	 *
	 * Only the term LISTING asks. Where posts are grouped under category
	 * headings the entries are the posts, and dropping the heading would take
	 * perfectly indexable posts down with it — `link_headings` is the setting
	 * for "the archive is noindexed, do not link to it". A term listing is
	 * different: there, the term is the entry.
	 *
	 * Three sources, each read from the plugin itself rather than guessed, and
	 * each keeping this somewhere different:
	 *
	 *   Yoast      one option, `wpseo_taxonomy_meta`, keyed
	 *              [taxonomy][term_id]['wpseo_noindex'] with 'noindex'
	 *   Rank Math  term meta `rank_math_robots`, a list containing 'noindex'
	 *   Cocoon     term meta `the_category_noindex` for categories and
	 *              `the_tag_noindex` for everything else, a checkbox
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Its taxonomy.
	 * @return bool
	 */
	private function is_term_noindex( int $term_id, string $taxonomy ): bool {
		if ( 'noindex' === $this->yoast_term_setting( $term_id, $taxonomy ) ) {
			return $this->filter_term_noindex( true, $term_id, $taxonomy );
		}

		$rank_math = get_term_meta( $term_id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return $this->filter_term_noindex( true, $term_id, $taxonomy );
		}

		// Cocoon writes 0 rather than deleting the row, the same as it does for
		// posts, so this has to be a truth test on the value.
		$cocoon = 'category' === $taxonomy ? 'the_category_noindex' : 'the_tag_noindex';
		if ( ! empty( get_term_meta( $term_id, $cocoon, true ) ) ) {
			return $this->filter_term_noindex( true, $term_id, $taxonomy );
		}

		return $this->filter_term_noindex( false, $term_id, $taxonomy );
	}

	/**
	 * Yoast's stored setting for one term, or ''.
	 *
	 * Yoast keeps every term's settings in a single option rather than in term
	 * meta, so this is one read for the whole render however many terms are
	 * listed — remembered statically, because each section of a composed
	 * sitemap is a builder of its own.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Its taxonomy.
	 * @return string
	 */
	private function yoast_term_setting( int $term_id, string $taxonomy ): string {
		if ( null === self::$yoast_terms ) {
			$stored            = get_option( 'wpseo_taxonomy_meta' );
			self::$yoast_terms = is_array( $stored ) ? $stored : array();
		}

		$setting = self::$yoast_terms[ $taxonomy ][ $term_id ]['wpseo_noindex'] ?? '';

		return is_string( $setting ) ? $setting : '';
	}

	/**
	 * Let a site answer for the plugins this one does not read.
	 *
	 * @param bool   $noindex  What this plugin concluded.
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Its taxonomy.
	 * @return bool
	 */
	private function filter_term_noindex( bool $noindex, int $term_id, string $taxonomy ): bool {
		/**
		 * Filters whether a term is treated as noindexed.
		 *
		 * @param bool   $noindex  Whether this plugin found it noindexed.
		 * @param int    $term_id  Term ID.
		 * @param string $taxonomy Taxonomy name.
		 */
		return (bool) apply_filters( Hooks::IS_TERM_NOINDEX, $noindex, $term_id, $taxonomy );
	}

	/**
	 * Ask All in One SEO about a set of posts, once.
	 *
	 * AIOSEO is the one that keeps none of this in post meta: it has a table of
	 * its own, one row per post. Read per post that would be a query per entry —
	 * the thing a sitemap must never do — so both callers hand over the whole
	 * list they are about to check and this answers for all of them at once.
	 *
	 * Bounded by what was asked about rather than by how many posts the site has
	 * marked: a shop with fifty thousand noindexed product pages is not a reason
	 * to build a fifty-thousand-entry array to render a page listing.
	 *
	 * `robots_default` is the row's "use the site setting" flag, so only a row
	 * that has turned it off and set `robots_noindex` is an answer about this
	 * post — that is AIOSEO's own rule, read from its Post model.
	 *
	 * @param int[] $ids Post IDs about to be checked.
	 */
	private function prime_aioseo( array $ids ): void {
		// The plugin's own bootstrap function. Present only while it is active,
		// which is also the only time its table can be relied on to exist.
		if ( ! function_exists( 'aioseo' ) ) {
			return;
		}

		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return $id > 0 && ! isset( self::$aioseo_noindex[ $id ] );
				}
			)
		);

		if ( array() === $ids ) {
			return;
		}

		// Answered up front, so an ID the query says nothing about is a "no"
		// rather than a second query.
		foreach ( $ids as $id ) {
			self::$aioseo_noindex[ $id ] = false;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// Its table can be missing for a moment during the plugin's own upgrade,
		// and a sitemap is not the place to print a database error about it.
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		// Prepared, even though every value in it is already an int: the IDs
		// went through intval() above, so this changes nothing about what runs
		// — but a raw IN list is a shape somebody copies into a query where the
		// values did not, and Plugin Check is right to call it out.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are built above; the sniff only reads literals.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table prefix and the placeholder list, neither of which can be prepared.
				"SELECT post_id FROM {$wpdb->prefix}aioseo_posts WHERE robots_default = 0 AND robots_noindex = 1 AND post_id IN ({$placeholders})",
				$ids
			)
		);

		if ( null !== $suppress ) {
			$wpdb->suppress_errors( $suppress );
		}

		foreach ( (array) $rows as $id ) {
			self::$aioseo_noindex[ (int) $id ] = true;
		}
	}

	/**
	 * The Cocoon theme's per-post noindex checkbox.
	 *
	 * Cocoon writes `1` when ticked and `0` when not — it keeps the row rather
	 * than deleting it, so an emptiness test is what distinguishes them, and
	 * `'0'` is conveniently falsy in PHP.
	 *
	 * The `is_noindex` fallback is Cocoon's own: it is the key inherited from
	 * Simplicity, the theme Cocoon succeeded, and Cocoon consults it exactly
	 * this way when its own key is empty. Reading it unconditionally would be
	 * wrong — the name is generic enough for something else to own it — so it
	 * is only consulted in the same situation Cocoon consults it.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	private function cocoon_noindex( int $id ): bool {
		$value = get_post_meta( $id, 'the_page_noindex', true );

		if ( '' !== $value && null !== $value ) {
			return ! empty( $value );
		}

		return ! empty( get_post_meta( $id, 'is_noindex', true ) );
	}

	/**
	 * Let anything else have the last word on one post.
	 *
	 * @param bool $noindex Verdict so far.
	 * @param int  $id      Post ID.
	 * @return bool
	 */
	private function filter_noindex( bool $noindex, int $id ): bool {
		/**
		 * Filters whether a post counts as noindex.
		 *
		 * @param bool $noindex Whether it is already considered noindex.
		 * @param int  $id      Post ID.
		 */
		return (bool) apply_filters( Hooks::IS_NOINDEX, $noindex, $id );
	}

	/**
	 * List the authors who have published something.
	 *
	 * @return Node[]
	 */
	private function authors(): array {
		$cap = $this->cap();

		// The same post types the content listing would use. Asking for the
		// unfiltered list would keep an author whose only posts are in a type
		// this sitemap excludes — a name linking to an archive with nothing on
		// it that the reader is allowed to see.
		$types = array_values(
			array_diff(
				array_map( 'strval', (array) $this->settings['post_types'] ),
				array_map( 'strval', (array) $this->settings['exclude_types'] )
			)
		);

		$args = array(
			'has_published_posts' => array() === $types ? true : $types,
			'orderby'             => 'display_name',
			// Always ascending, and deliberately not `order`. That setting means
			// "newest first" and defaults to DESC; spending it on a list of
			// names would turn every author listing on a default install
			// upside-down to answer a question nobody asked about people.
			'order'               => 'ASC',
		);

		// The account that installed WordPress, and the agency that built the
		// site, are on the user list without being anyone a reader should be
		// sent to. Excluded in the query rather than after it, so an excluded
		// name cannot eat one of the capped slots.
		$excluded = array_map( 'intval', (array) $this->settings['exclude_users'] );
		if ( array() !== $excluded ) {
			$args['exclude'] = $excluded;
		}

		$roles = array_values( array_filter( array_map( 'strval', (array) $this->settings['author_roles'] ) ) );
		if ( array() !== $roles ) {
			$args['role__in'] = $roles;
		}

		// Capped for the same reason posts are: a site with a large membership
		// would otherwise load every user object to draw a list.
		if ( $cap > 0 ) {
			$args['number'] = $cap + 1;
		}

		$users = get_users( $args );

		if ( $cap > 0 && count( $users ) > $cap ) {
			$users                       = array_slice( $users, 0, $cap );
			$this->truncated['authors'] = true;
		}

		$nodes = array();
		foreach ( $users as $user ) {
			$nodes[] = new Node(
				(int) $user->ID,
				(string) $user->display_name,
				(string) get_author_posts_url( (int) $user->ID ),
				'author'
			);
		}

		return $this->note_if_truncated( $nodes, 'authors' );
	}

	/**
	 * List the date archives, months nested inside years.
	 *
	 * Derived from the same post query the content listing uses rather than
	 * from a bespoke `GROUP BY` — one fewer query shape to keep correct, and it
	 * inherits the exclusions for free.
	 *
	 * @return Node[]
	 */
	private function archives(): array {
		$months = array();

		foreach ( (array) $this->settings['post_types'] as $post_type ) {
			foreach ( $this->fetch( (string) $post_type ) as $post ) {
				$year  = (int) substr( $post->post_date, 0, 4 );
				$month = (int) substr( $post->post_date, 5, 2 );

				if ( $year <= 0 || $month <= 0 ) {
					continue;
				}

				$months[ $year ][ $month ] = true;
			}
		}

		krsort( $months );

		$nodes = array();
		foreach ( $months as $year => $found ) {
			$node = new Node( $year, $this->year_label( $year ), (string) get_year_link( $year ), 'archive' );

			krsort( $found );
			foreach ( array_keys( $found ) as $month ) {
				// A distinct kind from the year: years are headings and get the
				// designs' heading treatment, months are the entries under them.
				$node->add(
					new Node(
						( $year * 100 ) + $month,
						$this->month_label( $year, $month ),
						(string) get_month_link( $year, $month ),
						'archive-month'
					)
				);
			}

			$nodes[] = $node;
		}

		// The archive list is derived from the post query, so it inherits that
		// query's cap — and a year that quietly vanished because the 2001st
		// post was never fetched is exactly the kind of gap a reader cannot see.
		$truncated = array_intersect_key( $this->truncated, array_flip( (array) $this->settings['post_types'] ) );
		if ( array() !== $truncated ) {
			$this->truncated['archives'] = true;
		}

		return $this->note_if_truncated( $nodes, 'archives' );
	}

	/**
	 * Localised label for a year archive.
	 *
	 * The date *format* is what gets translated, the way core does it — ja
	 * renders "2026年" where en renders "2026".
	 *
	 * @param int $year Four-digit year.
	 * @return string
	 */
	private function year_label( int $year ): string {
		/* translators: PHP date format for a yearly archive heading. Japanese uses Y年. */
		return (string) date_i18n( __( 'Y', 'rapls-sitemap' ), (int) mktime( 0, 0, 0, 1, 1, $year ) );
	}

	/**
	 * Localised label for a month archive.
	 *
	 * @param int $year  Four-digit year.
	 * @param int $month Month, 1-12.
	 * @return string
	 */
	private function month_label( int $year, int $month ): string {
		/* translators: PHP date format for a monthly archive heading. Japanese uses Y年n月. */
		return (string) date_i18n( __( 'F Y', 'rapls-sitemap' ), (int) mktime( 0, 0, 0, $month, 1, $year ) );
	}

	/**
	 * Drop everything below the given depth.
	 *
	 * @param Node[] $nodes     Nodes at the current level.
	 * @param int    $remaining Levels still allowed, 1 = this level only.
	 * @return Node[]
	 */
	private function prune( array $nodes, int $remaining ): array {
		if ( $remaining <= 1 ) {
			foreach ( $nodes as $node ) {
				$node->children = array();
			}
			return $nodes;
		}

		foreach ( $nodes as $node ) {
			$node->children = $this->prune( $node->children, $remaining - 1 );
		}

		return $nodes;
	}

	/**
	 * Convert a post to a node.
	 *
	 * @param \WP_Post $post Post object.
	 * @return Node
	 */
	private function to_node( $post ): Node {
		$title = get_the_title( $post );
		if ( '' === trim( (string) $title ) ) {
			/* translators: %d: post ID. */
			$title = sprintf( __( '(no title) #%d', 'rapls-sitemap' ), (int) $post->ID );
		}

		$node = new Node( (int) $post->ID, (string) $title, (string) get_permalink( $post ), 'post' );

		if ( ! empty( $this->settings['show_date'] ) ) {
			$format = (string) $this->settings['date_format'];
			// An empty format means the site's own, which is what most people
			// want and nobody should have to retype here.
			$node->date = (string) get_the_date( '' !== $format ? $format : get_option( 'date_format' ), $post );
		}

		if ( ! empty( $this->settings['show_excerpt'] ) ) {
			$node->excerpt = $this->excerpt( $post );
		}

		return $node;
	}

	/**
	 * A trimmed excerpt for one post.
	 *
	 * Uses the hand-written excerpt when there is one and falls back to the
	 * content, exactly as the loop does — but without `the_content`, because
	 * running every shortcode on the site inside a sitemap render is how a
	 * table of contents turns into a page build.
	 *
	 * @param \WP_Post $post Post object.
	 * @return string
	 */
	private function excerpt( $post ): string {
		$words = max( 1, (int) $this->settings['excerpt_length'] );

		$raw = trim( (string) $post->post_excerpt );
		if ( '' === $raw ) {
			$raw = strip_shortcodes( (string) $post->post_content );
		}

		$raw = wp_strip_all_tags( str_replace( ']]>', ']]&gt;', $raw ) );

		return trim( wp_trim_words( $raw, $words, '…' ) );
	}
}
