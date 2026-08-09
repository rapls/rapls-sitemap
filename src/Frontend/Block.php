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

	/** Block name. */
	public const NAME = 'rapls/sitemap';

	/** Editor script handle. */
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

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 2,
				'editor_script'   => self::SCRIPT,
				'render_callback' => array( $this, 'render' ),
				'attributes'      => array(
					'postTypes'   => array(
						'type'    => 'string',
						'default' => '',
					),
					// -1 means "inherit the stored depth"; 0 means unlimited.
					'depth'       => array(
						'type'    => 'number',
						'default' => -1,
					),
					'design'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'showHome'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'groupByTerm' => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'nestTerms'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'termMode'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
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
		$map  = array(
			'postTypes'   => 'post_types',
			'design'      => 'design',
			'termMode'    => 'term_mode',
			'showHome'    => 'show_home',
			'groupByTerm' => 'group_by_term',
			'nestTerms'   => 'nest_terms',
		);
		$atts = array();

		foreach ( $map as $from => $to ) {
			if ( ! isset( $attributes[ $from ] ) || '' === $attributes[ $from ] ) {
				continue;
			}
			$atts[ $to ] = $attributes[ $from ];
		}

		if ( isset( $attributes['depth'] ) && (int) $attributes['depth'] >= 0 ) {
			$atts['depth'] = (int) $attributes['depth'];
		}

		return $atts;
	}
}
