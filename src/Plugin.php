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
use RaplsSitemap\Support\Settings;

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

		// No load_plugin_textdomain(): translations for a WordPress.org-hosted
		// plugin come from translate.wordpress.org and load just in time.
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

		/*
		 * The settings used to autoload. They are read only where a sitemap
		 * renders, so autoloading them spent bytes on every other request too —
		 * on every render.
		 *
		 * `update_option( $option, $same_value, false )` will not do this.
		 * update_option() returns early when the serialized value is unchanged,
		 * and that return happens *before* it looks at the autoload argument —
		 * so writing the array back to itself changes nothing at all.
		 */
		$settings = get_option( Settings::OPTION );

		if ( false !== $settings ) {
			/*
			 * Removing and re-adding is the only way to change that column
			 * without also changing the value, on every version this plugin
			 * supports. `wp_set_option_autoload()` says it in one call and was
			 * used here behind a `function_exists()` guard — but it arrived in
			 * WordPress 6.4, three versions above the floor in the header, and
			 * Plugin Check reads the call rather than the guard. A migration
			 * that in practice has nobody to migrate is not worth an error on
			 * the submission report; put the one-liner back if the floor ever
			 * rises to 6.4.
			 */
			delete_option( Settings::OPTION );
			add_option( Settings::OPTION, $settings, '', false );
		}

		update_option( self::VERSION_OPTION, RAPLS_SITEMAP_VERSION, false );
	}

}
