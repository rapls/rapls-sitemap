<?php
/**
 * TreeBuilder: hierarchy nesting, orphan handling, category grouping, depth.
 *
 * The query layer is stubbed from the fixtures below, so this exercises the
 * assembly logic without a database.
 *
 *   php tests/smoke-tree.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

/* --- fixtures ----------------------------------------------------------- */

function fixture_post( $id, $title, $parent = 0, $date = '2026-01-15 10:00:00', $password = '' ) {
	$post                = new stdClass();
	$post->ID            = $id;
	$post->post_title    = $title;
	$post->post_parent   = $parent;
	$post->post_date     = $date;
	$post->post_password = $password;
	return $post;
}

// Post 11 is noindex according to Yoast, post 12 according to Rank Math.
$GLOBALS['fixture_meta'] = array(
	11 => array( '_yoast_wpseo_meta-robots-noindex' => '1' ),
	12 => array( 'rank_math_robots' => array( 'noindex', 'nofollow' ) ),
);

// Pages 1-5 stand in for Cocoon's storage, which writes 0 rather than deleting
// the row, and falls back to Simplicity's `is_noindex` when its own key is
// empty. Page 4 has both, disagreeing, to prove which one is authoritative.
$GLOBALS['fixture_meta'][1] = array( 'the_page_noindex' => 1 );
$GLOBALS['fixture_meta'][2] = array( 'the_page_noindex' => 0 );
$GLOBALS['fixture_meta'][3] = array( 'is_noindex' => 1 );
$GLOBALS['fixture_meta'][4] = array( 'the_page_noindex' => 0, 'is_noindex' => 1 );

$GLOBALS['fixture_pages'] = array(
	fixture_post( 1, 'Parent' ),
	fixture_post( 2, 'Child', 1 ),
	fixture_post( 3, 'Grandchild', 2 ),
	fixture_post( 4, 'Standalone' ),
	fixture_post( 5, 'Orphan', 99 ), // parent is not in the result set
);

$GLOBALS['fixture_posts'] = array(
	fixture_post( 10, 'Newest', 0, '2026-03-01 09:00:00' ),
	fixture_post( 11, 'Middle', 0, '2026-01-15 09:00:00' ),
	fixture_post( 12, 'Deep', 0, '2025-11-20 09:00:00' ),
	fixture_post( 13, 'Loose', 0, '2025-11-05 09:00:00', 'secret' ),
);

function fixture_term( $id, $name, $parent, $count ) {
	$term          = new stdClass();
	$term->term_id = $id;
	$term->name    = $name;
	$term->parent  = $parent;
	$term->count   = $count;
	return $term;
}

$GLOBALS['fixture_terms'] = array(
	fixture_term( 5, 'News', 0, 2 ),
	fixture_term( 6, 'Sub', 5, 1 ),   // child of News
	fixture_term( 7, 'Empty', 0, 0 ), // holds nothing anywhere
);

// term 5 holds posts 11 and 10 — in the "wrong" order on purpose, so the
// grouping test proves membership comes from the term but ordering from the
// post query. Post 13 is in no term at all.
$GLOBALS['fixture_term_members'] = array(
	5 => array( 11, 10 ),
	6 => array( 12 ),
	7 => array(),
);

/* --- WordPress query stubs ---------------------------------------------- */

function is_post_type_hierarchical( $type ) {
	return 'page' === $type;
}

function get_posts( $args ) {
	// Counted so a test can assert that a code path issued no query at all,
	// and recorded so the ordering tests can inspect what was asked for.
	$GLOBALS['fixture_posts_fetched'] = ( $GLOBALS['fixture_posts_fetched'] ?? 0 ) + 1;
	$GLOBALS['fixture_last_args']     = $args;

	$posts = 'page' === $args['post_type'] ? $GLOBALS['fixture_pages'] : $GLOBALS['fixture_posts'];

	// WP_Query drops password-protected posts when has_password is false; the
	// stub has to do the same or the exclusion test would prove nothing.
	if ( isset( $args['has_password'] ) && false === $args['has_password'] ) {
		$posts = array_values(
			array_filter(
				$posts,
				function ( $post ) {
					return '' === $post->post_password;
				}
			)
		);
	}

	if ( ! empty( $args['post__not_in'] ) ) {
		$posts = array_values(
			array_filter(
				$posts,
				function ( $post ) use ( $args ) {
					return ! in_array( (int) $post->ID, array_map( 'intval', $args['post__not_in'] ), true );
				}
			)
		);
	}

	return $posts;
}

function get_the_title( $post ) {
	return $post->post_title;
}

function get_permalink( $post ) {
	return 'https://example.test/?p=' . $post->ID;
}

function get_post_type_object( $type ) {
	$object                       = new stdClass();
	$object->labels               = new stdClass();
	$object->labels->name         = 'page' === $type ? 'Pages' : 'Posts';
	$object->labels->singular_name = 'page' === $type ? 'Page' : 'Post';
	return $object;
}

function get_post_type_archive_link( $type ) {
	return 'post' === $type ? 'https://example.test/blog/' : false;
}

function get_the_date( $format, $post ) {
	return date( $format, strtotime( $post->post_date ) );
}

function wp_trim_words( $text, $words, $more = '' ) {
	$parts = preg_split( '/\s+/', trim( $text ) );
	return count( $parts ) > $words ? implode( ' ', array_slice( $parts, 0, $words ) ) . $more : implode( ' ', $parts );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function strip_shortcodes( $text ) {
	return preg_replace( '/\[[^\]]*\]/', '', (string) $text );
}

function number_format_i18n( $number ) {
	return number_format( (float) $number );
}

function get_object_taxonomies( $type, $output = 'names' ) {
	if ( 'post' !== $type ) {
		return array();
	}

	$category               = new stdClass();
	$category->name         = 'category';
	$category->public       = true;
	$category->hierarchical = true;

	// Flat, so auto-detection must never pick it — only an explicit setting can.
	$tag               = new stdClass();
	$tag->name         = 'post_tag';
	$tag->public       = true;
	$tag->hierarchical = false;

	if ( 'names' === $output ) {
		return array( 'category', 'post_tag' );
	}

	return array( 'category' => $category, 'post_tag' => $tag );
}

function get_users( $args ) {
	$GLOBALS['fixture_last_user_args'] = $args;

	$out = array();
	foreach ( array( 3 => 'Aoi', 1 => 'Yuki' ) as $id => $name ) {
		$user               = new stdClass();
		$user->ID           = $id;
		$user->display_name = $name;
		$out[]              = $user;
	}
	return $out;
}

function get_author_posts_url( $id ) {
	return 'https://example.test/author/' . $id;
}

function get_year_link( $year ) {
	return 'https://example.test/' . $year . '/';
}

function get_month_link( $year, $month ) {
	return sprintf( 'https://example.test/%d/%02d/', $year, $month );
}

function date_i18n( $format, $timestamp ) {
	return date( $format, $timestamp );
}

// The bootstrap's apply_filters returns the value untouched, which is right for
// every other test. The noindex filter is a documented extension point, so it
// needs one that actually calls something.
$GLOBALS['rapls_noindex_filter'] = null;

function apply_filters( $hook, $value ) {
	if ( 'rapls_sitemap/is_noindex' === $hook && $GLOBALS['rapls_noindex_filter'] ) {
		$args = func_get_args();
		return call_user_func( $GLOBALS['rapls_noindex_filter'], $value, $args[2] ?? 0 );
	}
	return $value;
}

function get_post_meta( $id, $key, $single = false ) {
	$meta = $GLOBALS['fixture_meta'][ $id ][ $key ] ?? '';
	return $single ? $meta : array( $meta );
}

function get_terms( $args ) {
	// Recorded so a test can prove which taxonomy the builder asked for.
	$GLOBALS['fixture_last_taxonomy'] = $args['taxonomy'];

	$excluded = array_map( 'intval', (array) ( $args['exclude_tree'] ?? array() ) );
	$out      = array();

	foreach ( $GLOBALS['fixture_terms'] as $term ) {
		// exclude_tree drops the term and its descendants; the fixture is only
		// two levels deep, so one parent check covers it.
		if ( in_array( (int) $term->term_id, $excluded, true ) ) {
			continue;
		}
		if ( in_array( (int) $term->parent, $excluded, true ) ) {
			continue;
		}
		$out[] = $term;
	}

	return $out;
}

function get_objects_in_term( $term_ids, $taxonomy ) {
	$out = array();
	foreach ( (array) $term_ids as $id ) {
		foreach ( $GLOBALS['fixture_term_members'][ (int) $id ] ?? array() as $object_id ) {
			$out[] = $object_id;
		}
	}
	return $out;
}

function get_term_link( $term ) {
	return 'https://example.test/category/' . $term->term_id;
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function get_bloginfo( $key ) {
	return 'Example Site';
}

require_once __DIR__ . '/lib/bootstrap.php';

use RaplsSitemap\Sitemap\TreeBuilder;
use RaplsSitemap\Support\Settings;

/**
 * Settings with the given overrides, starting from a bare baseline.
 */
function tree_settings( array $overrides = array() ) {
	return array_merge(
		Settings::defaults(),
		array(
			'post_types'       => array( 'page' ),
			'show_home'        => false,
			'group_by_term'    => false,
			// Off unless a test is about them, so every other assertion keeps
			// reading against the tree it means to test.
			'section_headings' => false,
			'max_entries'      => 0,
		),
		$overrides
	);
}

/**
 * Titles of a node list, one level only.
 */
function titles( array $nodes ) {
	return array_map(
		function ( $node ) {
			return $node->title;
		},
		$nodes
	);
}

/* --- hierarchy ---------------------------------------------------------- */

$roots = ( new TreeBuilder( tree_settings() ) )->build();

check( array( 'Parent', 'Standalone', 'Orphan' ) === titles( $roots ), 'only true roots stay at the top level' );
check( array( 'Child' ) === titles( $roots[0]->children ), 'children nest under their parent' );
check( array( 'Grandchild' ) === titles( $roots[0]->children[0]->children ), 'nesting is recursive' );
check( 3 === $roots[0]->total(), 'total() walks the whole subtree (Parent + Child + Grandchild)' );

/* --- exclusion removes the subtree, and orphans still surface ----------- */

$roots = ( new TreeBuilder( tree_settings( array( 'exclude_ids' => array( 2 ) ) ) ) )->build();
check( ! in_array( 'Child', titles( $roots ), true ), 'an excluded page is gone' );
check( in_array( 'Grandchild', titles( $roots ), true ), 'its orphaned child surfaces rather than vanishing' );

/* --- depth -------------------------------------------------------------- */

$roots = ( new TreeBuilder( tree_settings( array( 'depth' => 2 ) ) ) )->build();
check( array( 'Child' ) === titles( $roots[0]->children ), 'depth 2 keeps the second level' );
check( array() === $roots[0]->children[0]->children, 'depth 2 drops the third level' );

$roots = ( new TreeBuilder( tree_settings( array( 'depth' => 1 ) ) ) )->build();
check( array() === $roots[0]->children, 'depth 1 flattens everything' );

/* --- home link ---------------------------------------------------------- */

$roots = ( new TreeBuilder( tree_settings( array( 'show_home' => true ) ) ) )->build();
check( 'home' === $roots[0]->kind, 'the home link comes first' );
check( 'Example Site' === $roots[0]->title, 'an empty label falls back to the site title' );

$roots = ( new TreeBuilder( tree_settings( array( 'show_home' => true, 'home_label' => 'Top' ) ) ) )->build();
check( 'Top' === $roots[0]->title, 'a configured label wins' );

/* --- category grouping -------------------------------------------------- */

/**
 * Settings for the grouped `post` listing.
 */
function grouped( array $overrides = array() ) {
	return tree_settings( array_merge( array( 'post_types' => array( 'post' ), 'group_by_term' => true ), $overrides ) );
}

$roots = ( new TreeBuilder( grouped() ) )->build();

check( 'term' === $roots[0]->kind, 'grouping produces a term heading first' );
check( 'News' === $roots[0]->title, 'the heading is the term name' );
check( array( 'News', 'Loose' ) === titles( $roots ), 'posts in no term follow the groups' );
check( array( 'Sub', 'Newest', 'Middle' ) === titles( $roots[0]->children ), 'sub-categories precede the parent\'s own posts' );
check( array( 'Deep' ) === titles( $roots[0]->children[0]->children ), 'a child category keeps its own posts' );
check( ! in_array( 'Empty', titles( $roots ), true ), 'a category holding nothing anywhere is dropped' );

// Membership comes from the term (11, 10) but order from the post query (10, 11).
check( array( 'Newest', 'Middle' ) === titles( array_slice( $roots[0]->children, 1 ) ), 'members keep the post query order, not the term order' );

/* --- nesting off flattens the categories -------------------------------- */

$roots = ( new TreeBuilder( grouped( array( 'nest_terms' => false ) ) ) )->build();
check( array( 'News', 'Sub', 'Loose' ) === titles( $roots ), 'nesting off puts every category at the top level' );
check( array( 'Newest', 'Middle' ) === titles( $roots[0]->children ), 'a flattened parent keeps only its own posts' );

/* --- categories only ----------------------------------------------------- */

$GLOBALS['fixture_posts_fetched'] = 0;
$roots                            = ( new TreeBuilder( grouped( array( 'term_mode' => 'terms_only' ) ) ) )->build();

check( array( 'News' ) === titles( $roots ), 'terms_only lists categories and no posts' );
check( array( 'Sub' ) === titles( $roots[0]->children ), 'terms_only still nests categories' );
check( 0 === $GLOBALS['fixture_posts_fetched'], 'terms_only skips the post query entirely' );
check( ! in_array( 'Empty', titles( $roots ), true ), 'an empty category is dropped from the category list' );

/* --- excluding a parent category takes its children with it ------------- */

$roots = ( new TreeBuilder( grouped( array( 'exclude_terms' => array( 5 ) ) ) ) )->build();
check( ! in_array( 'News', titles( $roots ), true ), 'the excluded category is gone' );
check( ! in_array( 'Sub', titles( $roots ), true ), 'its child category goes with it, rather than orphaning' );

/* --- grouping off ------------------------------------------------------- */

$roots = ( new TreeBuilder( grouped( array( 'group_by_term' => false ) ) ) )->build();
check( array( 'Newest', 'Middle', 'Deep', 'Loose' ) === titles( $roots ), 'grouping off yields a flat list' );

/* --- the taxonomy is selectable ----------------------------------------- */

$GLOBALS['fixture_last_taxonomy'] = null;
( new TreeBuilder( grouped() ) )->build();
check( 'category' === $GLOBALS['fixture_last_taxonomy'], 'auto-detection picks the hierarchical taxonomy' );

$GLOBALS['fixture_last_taxonomy'] = null;
( new TreeBuilder( grouped( array( 'taxonomy' => 'post_tag' ) ) ) )->build();
check( 'post_tag' === $GLOBALS['fixture_last_taxonomy'], 'an explicit setting reaches a flat taxonomy auto-detection would skip' );

$GLOBALS['fixture_last_taxonomy'] = null;
$roots = ( new TreeBuilder( grouped( array( 'taxonomy' => 'nonexistent' ) ) ) )->build();
check( null === $GLOBALS['fixture_last_taxonomy'], 'a taxonomy the post type lacks disables grouping instead of erroring' );
check( array( 'Newest', 'Middle', 'Deep', 'Loose' ) === titles( $roots ), 'and the entries still render, ungrouped' );

/* --- ordering ------------------------------------------------------------ */

/**
 * The query args produced for one post type under the given settings.
 */
function order_for( $post_type, array $overrides ) {
	( new TreeBuilder( tree_settings( array_merge( array( 'post_types' => array( $post_type ) ), $overrides ) ) ) )->build();
	return $GLOBALS['fixture_last_args'];
}

$args = order_for( 'page', array() );
check( 'menu_order title' === $args['orderby'] && 'ASC' === $args['order'], 'pages default to their editor-set order' );

$args = order_for( 'post', array() );
check( 'date' === $args['orderby'] && 'DESC' === $args['order'], 'posts default to newest first' );

$args = order_for( 'post', array( 'orderby' => 'date', 'order' => 'ASC' ) );
check( 'date' === $args['orderby'] && 'ASC' === $args['order'], 'oldest first is expressible' );

$args = order_for( 'page', array( 'orderby' => 'title', 'order' => 'ASC' ) );
check( 'title' === $args['orderby'] && 'ASC' === $args['order'], 'an explicit ordering overrides the per-type default' );

$args = order_for( 'post', array( 'orderby' => 'ID', 'order' => 'ASC' ) );
check( 'ID' === $args['orderby'], 'ID ordering is passed through' );

$args = order_for( 'post', array( 'orderby' => 'meta', 'sort_meta_key' => 'yomi', 'order' => 'ASC' ) );
check( 'meta_value' === $args['orderby'] && 'yomi' === $args['meta_key'], 'a custom field ordering names the key' );

// The reading-based sort is the only true 五十音順; without a key there is
// nothing to sort on, so it must not silently return an arbitrary order.
$args = order_for( 'post', array( 'orderby' => 'meta', 'sort_meta_key' => '' ) );
check( 'title' === $args['orderby'] && ! isset( $args['meta_key'] ), 'a custom field ordering with no key falls back to title' );

/* --- a post in several categories --------------------------------------- */

// Post 10 sits in both News (5) and Sub (6) for this block only.
$GLOBALS['fixture_term_members'][6][] = 10;

$roots = ( new TreeBuilder( grouped() ) )->build();
check(
	in_array( 'Newest', titles( $roots[0]->children ), true ) && in_array( 'Newest', titles( $roots[0]->children[0]->children ), true ),
	'a post in two categories is listed under both by default'
);

$roots = ( new TreeBuilder( grouped( array( 'duplicate_in_terms' => false ) ) ) )->build();
$under_news = titles( $roots[0]->children );
$under_sub  = titles( $roots[0]->children[0]->children );
check(
	1 === (int) in_array( 'Newest', $under_news, true ) + (int) in_array( 'Newest', $under_sub, true ),
	'switched off, it appears exactly once',
	'News: ' . implode( ',', $under_news ) . ' / Sub: ' . implode( ',', $under_sub )
);

array_pop( $GLOBALS['fixture_term_members'][6] );

/* --- excluding whole post types and taxonomies -------------------------- */

$roots = ( new TreeBuilder( tree_settings( array( 'exclude_types' => array( 'page' ) ) ) ) )->build();
check( array() === $roots, 'an excluded post type contributes nothing, even though it is in post_types' );

$GLOBALS['fixture_last_taxonomy'] = null;
$roots = ( new TreeBuilder( grouped( array( 'exclude_tax' => array( 'category' ) ) ) ) )->build();
check( null === $GLOBALS['fixture_last_taxonomy'], 'an excluded taxonomy is never queried' );
check( 'post' === $roots[0]->kind, 'and the entries fall back to a flat list' );

// An exclusion has to beat an explicit choice, or it is not an exclusion.
$GLOBALS['fixture_last_taxonomy'] = null;
( new TreeBuilder( grouped( array( 'taxonomy' => 'post_tag', 'exclude_tax' => array( 'post_tag' ) ) ) ) )->build();
check( null === $GLOBALS['fixture_last_taxonomy'], 'an exclusion beats an explicitly chosen taxonomy' );

/* --- password-protected entries ----------------------------------------- */

$roots = ( new TreeBuilder( grouped( array( 'group_by_term' => false ) ) ) )->build();
check( in_array( 'Loose', titles( $roots ), true ), 'a protected entry is listed by default, as WordPress does' );

$roots = ( new TreeBuilder( grouped( array( 'group_by_term' => false, 'exclude_protected' => true ) ) ) )->build();
check( ! in_array( 'Loose', titles( $roots ), true ), 'and is dropped once the setting is on' );
check( array( 'Newest', 'Middle', 'Deep' ) === titles( $roots ), 'while everything else survives' );

/* --- noindex entries ----------------------------------------------------- */

$roots = ( new TreeBuilder( grouped( array( 'group_by_term' => false, 'exclude_noindex' => true ) ) ) )->build();
check( ! in_array( 'Middle', titles( $roots ), true ), 'a Yoast noindex entry is dropped' );
check( ! in_array( 'Deep', titles( $roots ), true ), 'a Rank Math noindex entry is dropped' );
check( in_array( 'Newest', titles( $roots ), true ), 'an entry with no SEO meta at all is kept' );

/* --- Cocoon's per-post noindex ------------------------------------------- */

$roots  = ( new TreeBuilder( tree_settings( array( 'exclude_noindex' => true ) ) ) )->build();
$listed = titles( $roots );

check( ! in_array( 'Parent', $listed, true ), 'a Cocoon noindex page is dropped (the_page_noindex = 1)' );
check( in_array( 'Child', $listed, true ), 'an explicit 0 is kept — Cocoon stores 0 rather than removing the row' );
check( ! in_array( 'Grandchild', $listed, true ), 'the Simplicity key Cocoon inherits is honoured when its own is absent' );
check( in_array( 'Standalone', $listed, true ), 'but Cocoon\'s own 0 wins over a stale Simplicity 1, as Cocoon itself decides it' );
check( in_array( 'Orphan', $listed, true ), 'a page with no SEO meta at all is kept' );

/* --- the filter has the last word ---------------------------------------- */

$GLOBALS['rapls_noindex_filter'] = function ( $noindex, $id ) {
	return 13 === (int) $id ? true : $noindex;
};

$roots = ( new TreeBuilder( grouped( array( 'group_by_term' => false, 'exclude_noindex' => true ) ) ) )->build();
check( ! in_array( 'Loose', titles( $roots ), true ), 'a plugin can mark a post noindex through the filter' );

$GLOBALS['rapls_noindex_filter'] = null;

// Priming meta for every post costs a query, so it must only happen when
// something is going to read it.
order_for( 'post', array() );
check( false === $GLOBALS['fixture_last_args']['update_post_meta_cache'], 'meta is not primed when nothing reads it' );
order_for( 'post', array( 'exclude_noindex' => true ) );
check( true === $GLOBALS['fixture_last_args']['update_post_meta_cache'], 'and is primed when the noindex check needs it' );

/* --- section headings ---------------------------------------------------- */

$both = array( 'post_types' => array( 'page', 'post' ), 'section_headings' => true );

$roots = ( new TreeBuilder( tree_settings( $both ) ) )->build();
check( array( 'Pages', 'Posts' ) === titles( $roots ), 'each post type gets a heading, in the configured order' );
check( 'section' === $roots[0]->kind, 'headings are marked as sections' );
check( 'https://example.test/blog/' === $roots[1]->url, 'a heading links to the post type archive when there is one' );
check( '' === $roots[0]->url, 'and stays plain text when there is not' );
check( in_array( 'Parent', titles( $roots[0]->children ), true ), 'the type\'s own tree hangs underneath' );

// One list has nothing to be told apart from, so a label would be noise.
$roots = ( new TreeBuilder( tree_settings( array( 'section_headings' => true ) ) ) )->build();
check( 'section' !== $roots[0]->kind, 'a single post type gets no heading' );

$roots = ( new TreeBuilder( tree_settings( array_merge( $both, array( 'section_headings' => false ) ) ) ) )->build();
check( 'section' !== $roots[0]->kind, 'and headings can be switched off entirely' );

// A heading must not eat a level of the depth budget.
$plain    = ( new TreeBuilder( tree_settings( array( 'depth' => 2 ) ) ) )->build();
$sections = ( new TreeBuilder( tree_settings( array_merge( $both, array( 'depth' => 2 ) ) ) ) )->build();
check(
	titles( $plain[0]->children ) === titles( $sections[0]->children[0]->children ),
	'depth is counted below the heading, not through it'
);

/* --- the entry cap ------------------------------------------------------- */

/**
 * Entries anywhere in the tree, ignoring the "and more" note.
 *
 * The cap limits posts fetched, not root nodes — three of the five fixture
 * pages nest under another, so counting roots would count the wrong thing.
 */
function entries( array $nodes ) {
	$total = 0;
	foreach ( $nodes as $node ) {
		if ( 'more' !== $node->kind ) {
			$total += 1 + entries( $node->children );
		}
	}
	return $total;
}

$roots = ( new TreeBuilder( tree_settings( array( 'max_entries' => 0 ) ) ) )->build();
check( 5 === entries( $roots ), 'no cap lists every one of the five pages' );

$roots = ( new TreeBuilder( tree_settings( array( 'max_entries' => 2 ) ) ) )->build();
$kinds = array_map( function ( $n ) { return $n->kind; }, $roots );
check( in_array( 'more', $kinds, true ), 'a truncated list says so rather than just ending' );
check( 2 === entries( $roots ), 'and stops at the cap' );

// The cap is enforced by asking for one extra row, so the query must request
// max + 1 — otherwise there is no evidence to detect truncation from.
order_for( 'page', array( 'max_entries' => 10 ) );
check( 11 === $GLOBALS['fixture_last_args']['posts_per_page'], 'the query asks for one more than the cap' );

order_for( 'page', array( 'max_entries' => 0 ) );
check( -1 === $GLOBALS['fixture_last_args']['posts_per_page'], 'and for everything when the cap is lifted' );

$roots = ( new TreeBuilder( tree_settings( array( 'max_entries' => 99 ) ) ) )->build();
check(
	! in_array( 'more', array_map( function ( $n ) { return $n->kind; }, $roots ), true ),
	'a cap nobody reaches adds no note'
);

order_for( 'page', array( 'offset' => 2 ) );
check( 2 === $GLOBALS['fixture_last_args']['offset'], 'the offset reaches the query' );

/* --- dates, excerpts, and counts ----------------------------------------- */

$roots = ( new TreeBuilder( tree_settings() ) )->build();
check( '' === $roots[0]->date && '' === $roots[0]->excerpt, 'nothing extra is attached by default' );
check( -1 === $roots[0]->count, 'and a missing count is distinguishable from zero' );

$roots = ( new TreeBuilder( tree_settings( array( 'show_date' => true, 'date_format' => 'Y-m-d' ) ) ) )->build();
check( '2026-01-15' === $roots[0]->date, 'the date is formatted with the configured format' );

$GLOBALS['fixture_pages'][0]->post_content = 'One two three four five six seven.';
$GLOBALS['fixture_pages'][0]->post_excerpt = '';

$roots = ( new TreeBuilder( tree_settings( array( 'show_excerpt' => true, 'excerpt_length' => 3 ) ) ) )->build();
check( 'One two three…' === $roots[0]->excerpt, 'the excerpt falls back to the content and is trimmed to length' );

$GLOBALS['fixture_pages'][0]->post_excerpt = 'A written excerpt.';
$roots = ( new TreeBuilder( tree_settings( array( 'show_excerpt' => true, 'excerpt_length' => 20 ) ) ) )->build();
check( 'A written excerpt.' === $roots[0]->excerpt, 'a hand-written excerpt wins over the content' );

$roots = ( new TreeBuilder( grouped( array( 'show_count' => true ) ) ) )->build();
check( 2 === $roots[0]->count, 'a term heading carries its entry count when asked' );

/* --- the new orderings --------------------------------------------------- */

$args = order_for( 'post', array( 'orderby' => 'comment_count', 'order' => 'DESC' ) );
check( 'comment_count' === $args['orderby'], 'comment count ordering is passed through' );

$args = order_for( 'post', array( 'orderby' => 'rand' ) );
check( 'rand' === $args['orderby'] && ! isset( $args['order'] ), 'a random order carries no direction' );

/* --- truncation is never silent, whatever the source -------------------- */

/**
 * Does this node list end with the "and more" note?
 */
function has_note( array $nodes ) {
	foreach ( $nodes as $node ) {
		if ( 'more' === $node->kind ) {
			return true;
		}
	}
	return false;
}

// The archive list is derived from the post query, so it inherits its cap. A
// year missing because the 2001st post was never fetched looks like a year with
// nothing published in it — the one truncation a reader cannot possibly spot.
$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'archives', 'post_types' => array( 'post' ), 'max_entries' => 2 ) ) ) )->build();
check( has_note( $roots ), 'a truncated archive listing says so' );

$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'archives', 'post_types' => array( 'post' ), 'max_entries' => 0 ) ) ) )->build();
check( ! has_note( $roots ), 'and an untruncated one does not' );

$GLOBALS['fixture_last_user_args'] = null;
$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'authors', 'max_entries' => 1 ) ) ) )->build();
check( has_note( $roots ), 'a truncated author listing says so too' );
check( 2 === $GLOBALS['fixture_last_user_args']['number'], 'the user query is capped rather than loading every account' );

$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'authors', 'max_entries' => 0 ) ) ) )->build();
check( ! has_note( $roots ), 'lifting the cap lifts it for authors as well' );
check( ! isset( $GLOBALS['fixture_last_user_args']['number'] ), 'and the user query goes back to unbounded' );

/* --- authors ------------------------------------------------------------ */

$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'authors' ) ) ) )->build();
check( array( 'Aoi', 'Yuki' ) === titles( $roots ), 'the author listing uses the display names' );
check( 'author' === $roots[0]->kind, 'author nodes are marked as such' );
check( 'https://example.test/author/3' === $roots[0]->url, 'authors link to their archive' );

/* --- date archives ------------------------------------------------------ */

$roots = ( new TreeBuilder( tree_settings( array( 'source' => 'archives', 'post_types' => array( 'post' ) ) ) ) )->build();

check( array( '2026', '2025' ) === titles( $roots ), 'years are listed newest first' );
check( 'archive' === $roots[0]->kind, 'a year is an archive heading' );
check( array( 'March 2026', 'January 2026' ) === titles( $roots[0]->children ), 'months nest under their year, newest first' );
check( 'archive-month' === $roots[0]->children[0]->kind, 'months carry a distinct kind so designs can style them as entries' );
check( array( 'November 2025' ) === titles( $roots[1]->children ), 'two posts in one month produce one entry' );
check( 'https://example.test/2026/03/' === $roots[0]->children[0]->url, 'months link to the month archive' );

// The archive listing reuses fetch(), so it inherits the exclusions for free.
$roots = ( new TreeBuilder(
	tree_settings( array( 'source' => 'archives', 'post_types' => array( 'post' ), 'exclude_ids' => array( 10 ) ) )
) )->build();
check( array( 'January 2026' ) === titles( $roots[0]->children ), 'excluded posts do not create an archive entry' );

summary();
