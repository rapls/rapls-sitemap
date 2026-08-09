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
	 * What produced this node: 'post', 'term', 'home'.
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
