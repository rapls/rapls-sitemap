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

	/** Records which version last ran its upgrade steps. */
	public const VERSION_OPTION = 'rapls_sitemap_version';

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
			add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		}

		// Priority 0: block.json's title and description are translated at
		// registration time, and the block registers on `init` too. Loading the
		// catalogue any later would leave those two strings in English.
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
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
	 * Bring an existing install in line with the current version.
	 *
	 * Runs on `admin_init` rather than on activation, because a plugin updated
	 * from the Plugins screen is never deactivated and reactivated — an
	 * activation hook would not fire, and the site would keep whatever the old
	 * version set up.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === RAPLS_SITEMAP_VERSION ) {
			return;
		}

		// The settings used to autoload. They are read only where a sitemap
		// renders, so autoloading them spent bytes on every other request too —
		// including the Additional CSS, which has no natural size.
		$settings = get_option( Settings::OPTION );
		if ( false !== $settings ) {
			update_option( Settings::OPTION, $settings, false );
		}

		update_option( self::VERSION_OPTION, RAPLS_SITEMAP_VERSION, false );
	}

	/**
	 * Load translations.
	 *
	 * Plugin Check warns about this call, on the grounds that WordPress.org
	 * loads a hosted plugin's translations by itself. That is true of language
	 * packs from translate.wordpress.org; it is not true of the Japanese
	 * catalogue this plugin *ships*, which is the one its own market reads.
	 * The warning is accepted rather than silenced: an unnecessary call costs
	 * one function invocation, and removing it would risk an English admin
	 * screen on a Japanese site, which is the failure this plugin exists to
	 * avoid. Revisit once the plugin is hosted and has language packs.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'rapls-sitemap', false, dirname( RAPLS_SITEMAP_BASENAME ) . '/languages' );
	}
}
