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

		$depth = (int) $this->settings['depth'];

		switch ( (string) $this->settings['source'] ) {
			case 'authors':
				$roots = array_merge( $roots, $this->depth_limited( $this->authors(), $depth ) );
				break;

			case 'archives':
				$roots = array_merge( $roots, $this->depth_limited( $this->archives(), $depth ) );
				break;

			default:
				$roots = array_merge( $roots, $this->content( $depth ) );
		}

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

		$nodes[] = new Node(
			0,
			sprintf(
				/* translators: %s: number of entries shown. */
				__( 'Only the first %s entries are listed.', 'rapls-sitemap' ),
				number_format_i18n( (int) $this->settings['max_entries'] )
			),
			'',
			'more'
		);

		return $nodes;
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

		// A category-only listing needs no posts at all — skip the query.
		if ( $terms_only ) {
			return $this->term_tree( $taxonomy );
		}

		$posts = $this->fetch( $post_type );
		if ( array() === $posts ) {
			return array();
		}

		if ( is_post_type_hierarchical( $post_type ) ) {
			return $this->nest( $posts );
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

		$claimed = array();
		foreach ( $terms as $term ) {
			$ids = get_objects_in_term( array( (int) $term->term_id ), $taxonomy );
			if ( is_wp_error( $ids ) ) {
				continue;
			}

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
				$nodes[ (int) $term->term_id ]->add( $this->to_node( $by_id[ $id ] ) );
				$claimed[ $id ] = true;
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
	private function term_tree( string $taxonomy ): array {
		$terms = $this->fetch_terms( $taxonomy );
		if ( array() === $terms ) {
			return array();
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

		if ( $this->cocoon_noindex( $id ) ) {
			return $this->filter_noindex( true, $id );
		}

		return $this->filter_noindex( false, $id );
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
			'order'               => 'ASC',
		);

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
