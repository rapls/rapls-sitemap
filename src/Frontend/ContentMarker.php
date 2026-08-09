<?php
/**
 * Legacy placement marker.
 *
 * PS Auto Sitemap was placed by pasting an HTML comment into the page body and
 * naming that page's ID in the settings. Sites migrating here have that comment
 * sitting in hundreds of published pages, so this filter honours it: the same
 * comment renders the same sitemap, with nothing to edit and no page ID to
 * configure.
 *
 * The marker string is an interoperability token, like a file signature — the
 * matching and rendering below are this plugin's own.
 *
 * New pages should use `[rapls_sitemap]` or the block instead; this exists so
 * that migrating never means touching content.
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
 * Swaps the legacy comment marker for a rendered sitemap.
 */
final class ContentMarker {

	/**
	 * Matches the marker, plus the paragraph tags the editor may have wrapped
	 * around it. Without swallowing those, the sitemap would end up nested
	 * inside a `<p>`, which is invalid and collapses most of the designs.
	 */
	private const PATTERN = '#(?:<p>\s*)?<!--\s*SITEMAP\s+CONTENT\s+REPLACE\s+POINT\s*-->(?:\s*</p>)?#i';

	/**
	 * Runs after wpautop (10) and do_shortcode (11), so the injected markup is
	 * never reformatted by them.
	 */
	private const PRIORITY = 12;

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
	 * Hook the content filter.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'replace' ), self::PRIORITY );
	}

	/**
	 * Replace the marker, if this content has one.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function replace( $content ): string {
		$content = (string) $content;

		// Cheap reject first: the regex should never run on the overwhelming
		// majority of pages. The needle is the single word rather than the
		// whole marker, because the pattern tolerates arbitrary whitespace
		// between the words and a substring search does not.
		if ( false === stripos( $content, 'sitemap' ) ) {
			return $content;
		}

		if ( is_feed() ) {
			return $content;
		}

		$settings = Settings::get();
		if ( empty( $settings['legacy_marker'] ) ) {
			return $content;
		}

		$settings = Settings::for_request( $settings );
		Styles::request( $settings );

		$html = $this->cache->html( $settings );

		// The callback form keeps `$` and `\` sequences in the rendered HTML
		// from being read as backreferences.
		return (string) preg_replace_callback(
			self::PATTERN,
			static function () use ( $html ) {
				return $html;
			},
			$content
		);
	}
}
