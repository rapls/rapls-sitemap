<?php
/**
 * The `rapls/sitemap` block.
 *
 * Server-rendered: the editor script is a plain, unbundled file using the
 * `wp.*` globals and `ServerSideRender`, so the plugin keeps the family's
 * no-build-toolchain rule. The block shares Shortcode::apply_atts(), which
 * means block attributes and shortcode attributes can never drift apart.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Frontend;

use RaplsSitemap\Sitemap\Cache;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the block.
 */
final class Block {

	/**
	 * Editor script handle.
	 *
	 * The block's own name is not duplicated here: `blocks/sitemap/block.json`
	 * owns it, and a second copy in PHP is a copy that can drift.
	 */
	private const SCRIPT = 'rapls-sitemap-block';

	/**
	 * Renderer/cache facade.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * @param Cache $cache Cache facade.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Hook block registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block type and its editor script.
	 */
	public function register_block(): void {
		wp_register_script(
			self::SCRIPT,
			RAPLS_SITEMAP_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			RAPLS_SITEMAP_VERSION,
			true
		);

		// The editor script's `wp.i18n.__()` calls read a Jed JSON payload, not
		// the MO file — `bin/make-json.php` writes it next to the catalogue.
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::SCRIPT, 'rapls-sitemap', RAPLS_SITEMAP_DIR . 'languages' );
		}

		/*
		 * Registered from block.json, which is what WordPress prefers and what
		 * lets the editor learn the block's shape without running our PHP.
		 *
		 * The metadata names the script by its registered handle rather than by
		 * `file:`, because a `file:` reference makes WordPress register the
		 * script itself — with no dependencies, since there is no build step
		 * here to emit the `.asset.php` that would declare them. Registering it
		 * above keeps the dependency list and the translations explicit.
		 */
		register_block_type_from_metadata(
			RAPLS_SITEMAP_DIR . 'blocks/sitemap',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Render the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( array $attributes ): string {
		$settings = Shortcode::apply_atts( Settings::get(), $this->to_atts( $attributes ) );
		$settings = Settings::for_request( $settings );

		Styles::request( $settings );

		return $this->cache->html( $settings );
	}

	/**
	 * Map camelCase block attributes onto the shortcode's snake_case names.
	 *
	 * `postTypes`/`design` inherit the stored setting when empty, and `depth`
	 * inherits when negative — an author who never opened the block sidebar
	 * gets whatever the settings screen says. The two toggles always override,
	 * because a checkbox has no third state to mean "inherit".
	 *
	 * @param array $attributes Block attributes.
	 * @return array<string,mixed>
	 */
	private function to_atts( array $attributes ): array {
		$map = array(
			// Empty means inherit.
			'source'          => 'source',
			'postTypes'       => 'post_types',
			'taxonomy'        => 'taxonomy',
			'childOf'         => 'child_of',
			'termMode'        => 'term_mode',
			'orderby'         => 'orderby',
			'order'           => 'order',
			'design'          => 'design',
			'listType'        => 'list_type',
			// Toggles always override.
			'showHome'        => 'show_home',
			'groupByTerm'     => 'group_by_term',
			'nestTerms'       => 'nest_terms',
			'sectionHeadings' => 'section_headings',
			'linkHeadings'    => 'link_headings',
			'showDate'        => 'show_date',
			'showExcerpt'     => 'show_excerpt',
			'showCount'       => 'show_count',
			'nofollow'        => 'nofollow',
		);

		$atts = array();

		foreach ( $map as $from => $to ) {
			if ( ! isset( $attributes[ $from ] ) || '' === $attributes[ $from ] ) {
				continue;
			}
			$atts[ $to ] = $attributes[ $from ];
		}

		// Negative means inherit for both of these: 0 is already meaningful
		// (unlimited depth, and an uncapped list), so it cannot double as the
		// "not set" value the way an empty string can.
		foreach ( array( 'depth' => 'depth', 'number' => 'number', 'perCategory' => 'per_category' ) as $from => $to ) {
			if ( isset( $attributes[ $from ] ) && (int) $attributes[ $from ] >= 0 ) {
				$atts[ $to ] = (int) $attributes[ $from ];
			}
		}

		return $atts;
	}
}
