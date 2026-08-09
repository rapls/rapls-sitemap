<?php
/**
 * Main plugin container.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap;

use RaplsSitemap\Admin\SettingsPage;
use RaplsSitemap\Admin\SupportPanel;
use RaplsSitemap\Frontend\Block;
use RaplsSitemap\Frontend\ContentMarker;
use RaplsSitemap\Frontend\LegacyShortcode;
use RaplsSitemap\Frontend\Shortcode;
use RaplsSitemap\Frontend\Styles;
use RaplsSitemap\Sitemap\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that wires the plugin's subsystems on `plugins_loaded`.
 */
final class Plugin {

	/**
	 * Sole instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Render cache.
	 *
	 * @var Cache|null
	 */
	private $cache = null;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private to enforce the singleton.
	 */
	private function __construct() {}

	/**
	 * Wire hooks and subsystems. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// One cache instance is shared by every render entry point, so a single
		// page holding both a shortcode and a block does one build, not two.
		$this->cache = new Cache();
		$this->cache->register();

		( new Styles() )->register();
		( new Shortcode( $this->cache ) )->register();
		( new Block( $this->cache ) )->register();
		( new ContentMarker( $this->cache ) )->register();
		( new LegacyShortcode( $this->cache ) )->register();

		if ( is_admin() ) {
			( new SettingsPage() )->register();
			( new SupportPanel() )->register();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * The render cache (available after boot()).
	 *
	 * @return Cache|null
	 */
	public function cache(): ?Cache {
		return $this->cache;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'rapls-sitemap', false, dirname( RAPLS_SITEMAP_BASENAME ) . '/languages' );
	}
}
