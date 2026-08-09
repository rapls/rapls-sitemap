<?php
/**
 * Cached rendering of the sitemap.
 *
 * Building the tree is a full unpaginated query per post type, so on a large
 * site it must not run on every page view. The rendered HTML is stored in a
 * transient keyed by (salt, settings hash).
 *
 * Invalidation bumps the salt rather than deleting keys. Deleting would mean
 * finding every transient we ever wrote — a `LIKE` scan over wp_options, which
 * is exactly the query a sitemap plugin should not be adding to a busy site.
 * Bumping orphans the old entries and lets them expire on their own.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Sitemap;

use RaplsSitemap\Support\Hooks;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders through a transient, and invalidates on content changes.
 */
final class Cache {

	/** Option holding the current cache salt. */
	public const SALT_OPTION = 'rapls_sitemap_cache_salt';

	/** Transient key prefix. */
	private const PREFIX = 'rapls_sitemap_html_';

	/**
	 * Hook the invalidation triggers.
	 */
	public function register(): void {
		// Post lifecycle. `save_post` covers create/update; the others cover
		// trash, restore, delete, and status transitions that skip save_post.
		add_action( 'save_post', array( $this, 'flush' ) );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );

		// Term lifecycle — headings and the exclusion filter depend on terms.
		add_action( 'created_term', array( $this, 'flush' ) );
		add_action( 'edited_term', array( $this, 'flush' ) );
		add_action( 'delete_term', array( $this, 'flush' ) );

		// User lifecycle. The author listing is built from display names, so a
		// renamed or removed user would otherwise sit in the cache until it
		// expired — twelve hours by default.
		add_action( 'profile_update', array( $this, 'flush' ) );
		add_action( 'user_register', array( $this, 'flush' ) );
		add_action( 'deleted_user', array( $this, 'flush' ) );

		// Settings changes alter the hash anyway, but flushing keeps the option
		// table from accumulating a fresh orphan set on every save.
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush' ) );

		// The site title is the default home-link label.
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
	}

	/**
	 * Rendered sitemap HTML, from cache when available.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return string
	 */
	public function html( array $settings ): string {
		/**
		 * Filters the cache lifetime in seconds. 0 disables caching entirely.
		 *
		 * @param int   $ttl      Lifetime in seconds.
		 * @param array $settings Effective settings.
		 */
		$ttl = (int) apply_filters( Hooks::CACHE_TTL, (int) $settings['cache_ttl'], $settings );

		if ( $ttl <= 0 ) {
			return $this->build( $settings );
		}

		$key    = $this->key( $settings );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$html = $this->build( $settings );
		set_transient( $key, $html, $ttl );

		return $html;
	}

	/**
	 * Invalidate every cached rendering.
	 *
	 * Wired to hooks that pass an ID; PHP discards the extra argument.
	 */
	public function flush(): void {
		update_option( self::SALT_OPTION, (string) wp_generate_uuid4(), false );

		/**
		 * Fires after the cache has been invalidated.
		 */
		do_action( Hooks::CACHE_FLUSHED );
	}

	/**
	 * Build the markup without touching the cache.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return string
	 */
	private function build( array $settings ): string {
		$tree = ( new TreeBuilder( $settings ) )->build();

		return ( new Renderer( $settings ) )->render( $tree );
	}

	/**
	 * Transient key for one settings state.
	 *
	 * Includes the plugin version so an upgrade that changes the markup does not
	 * serve last version's HTML.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return string
	 */
	private function key( array $settings ): string {
		$salt    = (string) get_option( self::SALT_OPTION, '' );
		$version = defined( 'RAPLS_SITEMAP_VERSION' ) ? RAPLS_SITEMAP_VERSION : '0';

		return self::PREFIX . md5( $salt . '|' . $version . '|' . wp_json_encode( $settings ) );
	}
}
