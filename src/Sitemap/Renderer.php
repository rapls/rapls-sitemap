<?php
/**
 * Turns a Node tree into HTML.
 *
 * The only class allowed to emit markup. It touches no WordPress queries, which
 * is what makes the output testable from a plain `php tests/smoke-*.php` run:
 * give it nodes, compare the string.
 *
 * Class names follow BEM so a theme can restyle the whole sitemap without the
 * bundled stylesheet (`load_styles = false`).
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Sitemap;

use RaplsSitemap\Support\Design;
use RaplsSitemap\Support\Hooks;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders sitemap nodes as nested lists.
 */
final class Renderer {

	/** Root CSS class; every other class is derived from it. */
	public const BASE = 'rapls-sitemap';

	/**
	 * Effective settings.
	 *
	 * @var array<string,mixed>
	 */
	private $settings;

	/**
	 * Sanitized design tokens.
	 *
	 * @var array<string,string>
	 */
	private $style;

	/**
	 * @param array<string,mixed> $settings Settings from Settings::get().
	 */
	public function __construct( array $settings ) {
		$this->settings = $settings;
		$this->style    = Design::merge( isset( $settings['style'] ) ? $settings['style'] : array() );
	}

	/**
	 * The wrapper's full class list: block, preset, and token modifiers.
	 *
	 * @return string
	 */
	private function wrapper_class(): string {
		$classes = array( self::BASE, self::BASE . '--' . (string) $this->settings['design'] );

		$scope = Design::scope_class( $this->style );
		if ( '' !== $scope ) {
			$classes[] = $scope;
		}

		return implode( ' ', $classes );
	}

	/**
	 * The token stylesheet and the author's CSS, ahead of the sitemap.
	 *
	 * These sit in the returned markup rather than in `wp_head` so they travel
	 * with the cached HTML and appear only where a sitemap actually does. The
	 * author's CSS comes last so it can override the tokens.
	 *
	 * @return string
	 */
	private function styles(): string {
		// The design tokens only, which are per placement and a few hundred
		// bytes. The author's Additional CSS is the same on every placement and
		// can be kilobytes, so `Frontend\Styles` hands that to WordPress
		// instead — keeping it out of every cache entry and printing it once.
		$css = Design::style_block( $this->style );

		return '' === $css ? '' : '<style>' . $css . '</style>';
	}

	/**
	 * Render the whole sitemap.
	 *
	 * @param Node[] $roots Root nodes.
	 * @return string HTML, or the empty-state notice.
	 */
	public function render( array $roots ): string {
		$class = $this->wrapper_class();

		if ( array() === $roots ) {
			$html = '<div class="' . esc_attr( $class ) . '"><p class="' . esc_attr( self::BASE . '__empty' ) . '">'
				. esc_html__( 'No entries yet.', 'rapls-sitemap' )
				. '</p></div>';
		} else {
			$html = '<nav class="' . esc_attr( $class ) . '" aria-label="' . esc_attr__( 'Site map', 'rapls-sitemap' ) . '">'
				. $this->list( $roots, 0 )
				. '</nav>';
		}

		$html = $this->styles() . $html;

		/**
		 * Filters the rendered markup.
		 *
		 * @param string $html     Rendered HTML.
		 * @param Node[] $roots    Root nodes.
		 * @param array  $settings Effective settings.
		 */
		return (string) apply_filters( Hooks::OUTPUT, $html, $roots, $this->settings );
	}

	/**
	 * Render one `<ul>` level.
	 *
	 * @param Node[] $nodes Nodes at this level.
	 * @param int    $depth Zero-based nesting depth.
	 * @return string
	 */
	private function list( array $nodes, int $depth ): string {
		$tag = 'ol' === ( $this->settings['list_type'] ?? 'ul' ) ? 'ol' : 'ul';

		$html = '<' . $tag . ' class="' . esc_attr( self::BASE . '__list ' . self::BASE . '__list--depth-' . $depth ) . '">';

		foreach ( $nodes as $node ) {
			$html .= $this->item( $node, $depth );
		}

		return $html . '</' . $tag . '>';
	}

	/**
	 * Render one `<li>`, and its children if any.
	 *
	 * @param Node $node  Node to render.
	 * @param int  $depth Zero-based nesting depth.
	 * @return string
	 */
	private function item( Node $node, int $depth ): string {
		$classes = self::BASE . '__item ' . self::BASE . '__item--' . $node->kind;
		if ( $node->has_children() ) {
			$classes .= ' ' . self::BASE . '__item--has-children';
		}

		// A bullet belongs on an entry. A section heading is a label for the
		// list below it, and the "and more" line is an apology — neither is a
		// thing in the list, so neither gets a marker.
		$decorated = ! in_array( $node->kind, array( 'section', 'more' ), true );

		$html = '<li class="' . esc_attr( $classes ) . '">'
			. ( $decorated ? $this->icon( $depth ) : '' )
			. $this->heading_open( $node )
			. $this->link( $node )
			. $this->heading_close( $node )
			. $this->count( $node )
			. $this->date( $node )
			. $this->excerpt( $node );

		if ( $node->has_children() ) {
			$html .= $this->list( $node->children, $depth + 1 );
		}

		return $html . '</li>';
	}

	/**
	 * The heading element for a node, when one is configured.
	 *
	 * A sitemap is a page people read to find the shape of a site, and a screen
	 * reader user finds that shape by jumping between headings. Section and
	 * category labels are headings in every sense except the markup, so this
	 * makes them headings in the markup too.
	 *
	 * It is off by default and the level is chosen, not fixed: the right level
	 * depends on what surrounds the sitemap on its page, and guessing wrong
	 * produces a broken outline, which is worse than none.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function heading_open( Node $node ): string {
		$tag = $this->heading_tag( $node );

		return '' === $tag ? '' : '<' . $tag . ' class="' . esc_attr( self::BASE . '__heading' ) . '">';
	}

	/**
	 * The closing half of the above.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function heading_close( Node $node ): string {
		$tag = $this->heading_tag( $node );

		return '' === $tag ? '' : '</' . $tag . '>';
	}

	/**
	 * Which element this node's label should be, or '' for none.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function heading_tag( Node $node ): string {
		$level = isset( $this->settings['heading_level'] ) ? (string) $this->settings['heading_level'] : '';

		if ( ! in_array( $level, Settings::HEADING_LEVELS, true ) || '' === $level ) {
			return '';
		}

		// Only labels for a list below them. A post is an entry, and the "and
		// more" line is a note; making either a heading would clutter the
		// outline a heading list is supposed to simplify.
		return in_array( $node->kind, array( 'section', 'term', 'archive' ), true ) ? $level : '';
	}

	/**
	 * The entry count beside a term heading.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function count( Node $node ): string {
		if ( $node->count < 0 ) {
			return '';
		}

		return ' <span class="' . esc_attr( self::BASE . '__count' ) . '">'
			/* translators: %s: number of entries in a category. */
			. esc_html( sprintf( __( '(%s)', 'rapls-sitemap' ), number_format_i18n( $node->count ) ) )
			. '</span>';
	}

	/**
	 * The publication date beside an entry.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function date( Node $node ): string {
		if ( '' === $node->date ) {
			return '';
		}

		return ' <span class="' . esc_attr( self::BASE . '__date' ) . '">' . esc_html( $node->date ) . '</span>';
	}

	/**
	 * The excerpt beneath an entry.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function excerpt( Node $node ): string {
		if ( '' === $node->excerpt ) {
			return '';
		}

		return '<span class="' . esc_attr( self::BASE . '__excerpt' ) . '">' . esc_html( $node->excerpt ) . '</span>';
	}

	/**
	 * Render the bullet icon element for a depth, when one is configured.
	 *
	 * An element rather than a CSS `content` rule, because icon fonts identify
	 * their glyphs by class — which is also what makes this work with Font
	 * Awesome, Dashicons, or any other library the site already loads. This
	 * plugin bundles none of them; if nothing loads the font, nothing shows,
	 * and the sitemap is unharmed.
	 *
	 * `aria-hidden` because a bullet is decoration, not content.
	 *
	 * @param int $depth Zero-based nesting depth.
	 * @return string
	 */
	private function icon( int $depth ): string {
		$class = Design::icon_class( $this->style, $depth );
		if ( '' === $class ) {
			return '';
		}

		return '<i class="' . esc_attr( self::BASE . '__icon ' . $class ) . '" aria-hidden="true"></i> ';
	}

	/**
	 * Should this node's label be left as text even though it has a URL?
	 *
	 * Only headings, and only when asked. Sites whose category archives are
	 * thin, or noindexed, do not want a table of contents pointing at them —
	 * the same reason the `nofollow` option exists, taken one step further. The
	 * entries themselves are always links; a sitemap of plain text would not be
	 * a sitemap.
	 *
	 * @param Node $node Node to render.
	 * @return bool
	 */
	private function unlinked( Node $node ): bool {
		if ( ! isset( $this->settings['link_headings'] ) || $this->settings['link_headings'] ) {
			return false;
		}

		return in_array( $node->kind, array( 'section', 'term', 'archive' ), true );
	}

	/**
	 * Render a node's label — a link when it has a URL, plain text otherwise.
	 *
	 * @param Node $node Node to render.
	 * @return string
	 */
	private function link( Node $node ): string {
		$label = esc_html( $node->title );

		if ( '' === $node->url || $this->unlinked( $node ) ) {
			return '<span class="' . esc_attr( self::BASE . '__label' ) . '">' . $label . '</span>';
		}

		$rel = empty( $this->settings['nofollow'] ) ? '' : ' rel="nofollow"';

		return '<a class="' . esc_attr( self::BASE . '__link' ) . '" href="' . esc_url( $node->url ) . '"' . $rel . '>' . $label . '</a>';
	}
}
