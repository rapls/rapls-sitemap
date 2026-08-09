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

		switch ( (string) $this->settings['source'] ) {
			case 'authors':
				$roots = array_merge( $roots, $this->authors() );
				break;

			case 'archives':
				$roots = array_merge( $roots, $this->archives() );
				break;

			default:
				foreach ( (array) $this->settings['post_types'] as $post_type ) {
					foreach ( $this->build_post_type( (string) $post_type ) as $node ) {
						$roots[] = $node;
					}
				}
		}

		$depth = (int) $this->settings['depth'];
		if ( $depth > 0 ) {
			$roots = $this->prune( $roots, $depth );
		}

		/**
		 * Filters the assembled tree before rendering.
		 *
		 * @param Node[] $roots    Root nodes.
		 * @param array  $settings Effective settings.
		 */
		$filtered = apply_filters( Hooks::TREE, $roots, $this->settings );

		return is_array( $filtered ) ? $filtered : $roots;
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

		$args = array_merge(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				// Meta is only primed when something is actually going to read
				// it; otherwise this is a wasted query on every render.
				'update_post_meta_cache' => $noindex,
				'suppress_filters'    => false,
			),
			$this->order_args( $post_type )
		);

		if ( ! empty( $this->settings['exclude_protected'] ) ) {
			$args['has_password'] = false;
		}

		$exclude = (array) $this->settings['exclude_ids'];
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
		$args = apply_filters( Hooks::QUERY_ARGS, $args, $post_type );

		$posts = get_posts( $args );

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
	 * Nest posts by `post_parent`.
	 *
	 * Entries whose parent was excluded (or is not published) surface at the
	 * root rather than vanishing — an orphaned page is still a real page.
	 *
	 * @param \WP_Post[] $posts Flat post list.
	 * @return Node[] Root nodes.
	 */
	private function nest( array $posts ): array {
		$nodes = array();
		foreach ( $posts as $post ) {
			$nodes[ (int) $post->ID ] = $this->to_node( $post );
		}

		$roots = array();
		foreach ( $posts as $post ) {
			$id     = (int) $post->ID;
			$parent = (int) $post->post_parent;

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

		$nodes = array();
		foreach ( $terms as $term ) {
			$nodes[ (int) $term->term_id ] = new Node(
				(int) $term->term_id,
				$term->name,
				(string) get_term_link( $term ),
				'term'
			);
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

		foreach ( $posts as $post ) {
			if ( ! isset( $claimed[ (int) $post->ID ] ) ) {
				$groups[] = $this->to_node( $post );
			}
		}

		return $groups;
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

		$nodes  = array();
		$counts = array();
		foreach ( $terms as $term ) {
			$id            = (int) $term->term_id;
			$nodes[ $id ]  = new Node( $id, $term->name, (string) get_term_link( $term ), 'term' );
			$counts[ $id ] = (int) $term->count;
		}

		$roots = $this->nest_terms( $terms, $nodes );

		return $this->drop_empty_terms( $roots, $counts );
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
		$terms = get_terms(
			array(
				'taxonomy'     => $taxonomy,
				'hide_empty'   => false,
				'exclude_tree' => (array) $this->settings['exclude_terms'],
			)
		);

		return ( is_wp_error( $terms ) || ! is_array( $terms ) ) ? array() : $terms;
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
	 * Has an SEO plugin marked this post noindex?
	 *
	 * WordPress stores nothing per post, so this reads the two plugins that
	 * cover most of the market and leaves the rest to a filter. Guessing at a
	 * third plugin's storage would risk hiding content that was never marked.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_noindex( $post ): bool {
		$id      = (int) $post->ID;
		$noindex = false;

		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			$noindex = true;
		}

		$rank_math = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			$noindex = true;
		}

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
		$users = get_users(
			array(
				'has_published_posts' => (array) $this->settings['post_types'],
				'orderby'             => 'display_name',
				'order'               => 'ASC',
			)
		);

		$nodes = array();
		foreach ( $users as $user ) {
			$nodes[] = new Node(
				(int) $user->ID,
				(string) $user->display_name,
				(string) get_author_posts_url( (int) $user->ID ),
				'author'
			);
		}

		return $nodes;
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

		return $nodes;
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

		return new Node( (int) $post->ID, (string) $title, (string) get_permalink( $post ), 'post' );
	}
}
