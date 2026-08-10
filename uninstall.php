<?php
/**
 * Uninstall cleanup.
 *
 * Removes the plugin's options. There are no custom tables, and the cached
 * markup lives in transients that expire on their own — but the salt goes, so a
 * reinstall never serves a previous install's HTML.
 *
 * Runs only when the user deletes the plugin from the admin.
 *
 * @package RaplsSitemap
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * The options this plugin owns. Everything else it writes is a transient.
 *
 * @var string[]
 */
$rapls_sitemap_options = array(
	'rapls_sitemap_settings',
	'rapls_sitemap_cache_salt',
	// No longer written — nothing ever read it — but an install from before
	// that still holds one, and uninstalling should leave nothing behind.
	'rapls_sitemap_activated_at',
	'rapls_sitemap_version',
);

/**
 * Delete them from the current site.
 */
function rapls_sitemap_delete_options( array $options ) {
	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/*
 * Options are per-site, but uninstall.php runs once for the whole network — so
 * on multisite, deleting only the current site's options leaves a row behind on
 * every other site. Sites are walked in batches because a large network has
 * more of them than it is safe to load at once.
 */
if ( is_multisite() ) {
	$rapls_sitemap_offset = 0;

	do {
		$rapls_sitemap_sites = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => 200,
				'offset'                 => $rapls_sitemap_offset,
				'update_site_meta_cache' => false,
			)
		);

		foreach ( $rapls_sitemap_sites as $rapls_sitemap_site_id ) {
			switch_to_blog( $rapls_sitemap_site_id );
			rapls_sitemap_delete_options( $rapls_sitemap_options );
			restore_current_blog();
		}

		$rapls_sitemap_offset += 200;
	} while ( count( $rapls_sitemap_sites ) === 200 );

	return;
}

rapls_sitemap_delete_options( $rapls_sitemap_options );
