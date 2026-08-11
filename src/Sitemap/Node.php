<?php
/**
 * One entry in the sitemap tree.
 *
 * Deliberately dumb: TreeBuilder fills these in, Renderer walks them. Holding
 * only scalars (never WP_Post) is what lets the whole tree be built, filtered,
 * and unit-tested without WordPress loaded.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A link plus its children.
 */
final class Node {

	/**
	 * Post ID, term ID, or 0 for synthetic nodes (the home link, headings).
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Display text, unescaped.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Destination URL, unescaped. Empty renders as a non-link heading.
	 *
	 * @var string
	 */
	public $url;

	/**
	 * What produced this node, which is also its BEM modifier in the markup:
	 *
	 *   post           an entry — a post, a page, or a navigation menu item
	 *   term           a category or other term
	 *   section        a heading over a post type, a section, or a menu
	 *   home           the front-page link
	 *   author         a name in the author listing
	 *   archive        a year in the date archives
	 *   archive-month  a month under one
	 *   more           the note saying a list stopped short
	 *
	 * The settings screen prints this list as a CSS class reference, and
	 * `smoke-admin.php` checks the two agree.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Child nodes.
	 *
	 * @var Node[]
	 */
	public $children = array();

	/**
	 * Formatted publication date, or '' when dates are switched off.
	 *
	 * Formatted here rather than in the renderer so the renderer stays free of
	 * anything that needs the site's locale or timezone.
	 *
	 * @var string
	 */
	public $date = '';

	/**
	 * Trimmed excerpt, or '' when excerpts are switched off.
	 *
	 * @var string
	 */
	public $excerpt = '';

	/**
	 * Entry count for a term heading; -1 when there is nothing to show.
	 *
	 * Zero is a real count worth printing, so absence needs its own value.
	 *
	 * @var int
	 */
	public $count = -1;

	/**
	 * Whether `$count` survives the tree being cut.
	 *
	 * Two different numbers wear this property. Under a category heading with
	 * entries listed below it, the count IS those entries, so cutting them has
	 * to change it. In a category-only listing nothing is below the heading and
	 * the count is what the category holds — a fact about the site, not about
	 * the page, and re-counting it would replace it with zero.
	 *
	 * The node carries the answer because the code that cuts may be a different
	 * builder entirely: a composed sitemap trims a category section it did not
	 * build, and its own settings say nothing about how that section counts.
	 *
	 * @var bool
	 */
	public $preserve_count = false;

	/**
	 * @param int    $id    Source ID, or 0.
	 * @param string $title Display text.
	 * @param string $url   Destination URL, or ''.
	 * @param string $kind  Node kind.
	 */
	public function __construct( int $id, string $title, string $url = '', string $kind = 'post' ) {
		$this->id    = $id;
		$this->title = $title;
		$this->url   = $url;
		$this->kind  = $kind;
	}

	/**
	 * Append a child.
	 *
	 * @param Node $child Child node.
	 */
	public function add( Node $child ): void {
		$this->children[] = $child;
	}

	/**
	 * Does this node have children?
	 *
	 * @return bool
	 */
	public function has_children(): bool {
		return array() !== $this->children;
	}

	/**
	 * Total nodes in this subtree, including itself.
	 *
	 * Named `total()` rather than `count()` so it cannot be confused with the
	 * `$count` property beside it, which means something else entirely — the
	 * number of entries a category holds.
	 *
	 * @return int
	 */
	public function total(): int {
		$total = 1;
		foreach ( $this->children as $child ) {
			$total += $child->total();
		}
		return $total;
	}
}
