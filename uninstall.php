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

delete_option( 'rapls_sitemap_settings' );
delete_option( 'rapls_sitemap_cache_salt' );
delete_option( 'rapls_sitemap_activated_at' );
