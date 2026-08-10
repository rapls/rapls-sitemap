<?php
/**
 * Centralized hook / action-name constants.
 *
 * Keeping every action and filter name in one place avoids typos across the
 * codebase and gives any add-on a single, stable contract to target.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * String constants for the plugin's WordPress hooks.
 */
final class Hooks {

	/* Extension points (filters). */

	/** Filters the settings array after defaults are merged in. */
	public const SETTINGS = 'rapls_sitemap/settings';

	/** Filters the post types offered on the settings screen. */
	public const POST_TYPES = 'rapls_sitemap/post_types';

	/** Filters the taxonomies offered on the settings screen. */
	public const TAXONOMIES = 'rapls_sitemap/taxonomies';

	/** Filters the WP_Query args used to fetch entries for one post type. */
	public const QUERY_ARGS = 'rapls_sitemap/query_args';

	/** Filters the assembled node tree before rendering. */
	public const TREE = 'rapls_sitemap/tree';

	/** Filters the rendered HTML before it is returned to the shortcode/block. */
	public const OUTPUT = 'rapls_sitemap/output';

	/** Filters the registered design presets (slug => label). */
	public const DESIGNS = 'rapls_sitemap/designs';

	/** Filters the cache lifetime in seconds (0 disables caching). */
	public const CACHE_TTL = 'rapls_sitemap/cache_ttl';

	/** Vetoes claiming the `[wp_sitemap_page]` tag, on top of the setting. */
	public const LEGACY_SHORTCODE = 'rapls_sitemap/legacy_shortcode';

	/**
	 * Filters whether one post counts as noindex.
	 *
	 * Yoast and Rank Math are detected directly; every other SEO plugin hooks
	 * here. WordPress itself records nothing per post, so there is no core
	 * source of truth to read instead.
	 */
	public const IS_NOINDEX = 'rapls_sitemap/is_noindex';

	/**
	 * The same question about a term, which is stored somewhere else entirely
	 * by every plugin that answers it.
	 */
	public const IS_TERM_NOINDEX = 'rapls_sitemap/is_term_noindex';

	/** ...and about an author, which is a third storage location again. */
	public const IS_USER_NOINDEX = 'rapls_sitemap/is_user_noindex';

	/* Extension points (actions). */

	/** Fires after the cached markup has been flushed. */
	public const CACHE_FLUSHED = 'rapls_sitemap/cache_flushed';

	/**
	 * Not instantiable.
	 */
	private function __construct() {}
}
