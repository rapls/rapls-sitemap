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
		add_action( 'save_post', array( $this, 'flush_for_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'trashed_post', array( $this, 'flush' ) );
		add_action( 'untrashed_post', array( $this, 'flush' ) );

		// Term lifecycle — headings and the exclusion filter depend on terms.
		add_action( 'created_term', array( $this, 'flush' ) );
		add_action( 'edited_term', array( $this, 'flush' ) );
		add_action( 'delete_term', array( $this, 'flush' ) );

		// Grouping reads which posts are in which term. Editing a post writes
		// that through `save_post`, but code calling `wp_set_object_terms()`
		// directly changes the relationship and nothing else — and the grouped
		// listing would go on showing the old one.
		add_action( 'set_object_terms', array( $this, 'flush' ) );

		// User lifecycle. The author listing is built from display names, so a
		// renamed or removed user would otherwise sit in the cache until it
		// expired — twelve hours by default.
		add_action( 'profile_update', array( $this, 'flush' ) );
		add_action( 'user_register', array( $this, 'flush' ) );
		add_action( 'deleted_user', array( $this, 'flush' ) );

		// Menu lifecycle. Rearranging a menu writes nav_menu_item posts, so
		// `save_post` catches most of it — but not a menu renamed or emptied
		// from the Menus screen, which is exactly the edit somebody makes right
		// before reloading the sitemap to check.
		add_action( 'wp_update_nav_menu', array( $this, 'flush' ) );
		add_action( 'wp_delete_nav_menu', array( $this, 'flush' ) );

		// Settings changes alter the hash anyway, but flushing keeps the option
		// table from accumulating a fresh orphan set on every save.
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'flush' ) );

		// The site title is the default home-link label.
		add_action( 'update_option_blogname', array( $this, 'flush' ) );

		// Every entry in a sitemap is a URL, and these decide what URLs look
		// like. Without them a permalink change leaves the old ones on the page
		// for as long as the cache lives — twelve hours by default.
		foreach ( array( 'home', 'siteurl', 'permalink_structure', 'category_base', 'tag_base', 'date_format' ) as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'flush' ) );
		}
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
	 * Flush for a saved post, unless it was not really a save.
	 *
	 * Revisions and autosaves are neither listed nor capable of changing what
	 * is: rotating the salt for them would throw the cache away every thirty
	 * seconds while somebody writes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object, when WordPress passes one.
	 */
	public function flush_for_post( $post_id, $post = null ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$type = is_object( $post ) ? (string) $post->post_type : (string) get_post_type( $post_id );

		if ( 'revision' === $type || 'nav_menu_item' !== $type && ! is_post_type_viewable( $type ) ) {
			return;
		}

		$this->flush();
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
	 * It also includes the locale, which is the entirety of the multilingual
	 * story on this side. WPML and Polylang narrow the queries themselves — see
	 * `suppress_filters` in TreeBuilder — but they do that *inside* the render,
	 * and the settings are identical in every language. Without the locale in
	 * the key, whichever language was asked for first would be served to all of
	 * them. The page ID from `exclude_current` usually differs between
	 * translations and hid this; a placement with that switched off did not.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @return string
	 */
	private function key( array $settings ): string {
		$salt    = (string) get_option( self::SALT_OPTION, '' );
		$version = defined( 'RAPLS_SITEMAP_VERSION' ) ? RAPLS_SITEMAP_VERSION : '0';
		$locale  = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		/**
		 * Filters extra strings to hash into the cache key.
		 *
		 * The four filters this plugin exposes can all make the output depend
		 * on something it cannot see — who is logged in, most obviously. One
		 * cache entry would then be built for whoever arrived first and served
		 * to everybody. A site that varies its output has to say what it varied
		 * on here, or set the lifetime to 0.
		 *
		 * @param array $variant  Strings to include in the key.
		 * @param array $settings Effective settings.
		 */
		$variant = apply_filters( Hooks::CACHE_VARIANT, array(), $settings );
		// Encoded rather than joined with a separator: `['a|b', 'c']` and
		// `['a', 'b|c']` join to the same string, and putting two different
		// contexts under one key is the one thing this must never do.
		$variant = wp_json_encode( is_array( $variant ) ? $variant : array() );

		return self::PREFIX . md5( $salt . '|' . $version . '|' . $locale . '|' . $variant . '|' . wp_json_encode( $settings ) );
	}
}
