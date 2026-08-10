<?php
/**
 * Plugin Name:       Rapls Sitemap – HTML Sitemap Page for Pages, Posts, Categories, Authors & Menus
 * Description:       An HTML sitemap page for readers: pages, posts, categories, authors, archives and navigation menus, from one shortcode or block.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Rapls
 * Author URI:        https://raplsworks.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rapls-sitemap
 * Domain Path:       /languages
 *
 * @package RaplsSitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RAPLS_SITEMAP_VERSION', '0.1.0' );
define( 'RAPLS_SITEMAP_FILE', __FILE__ );
define( 'RAPLS_SITEMAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'RAPLS_SITEMAP_URL', plugin_dir_url( __FILE__ ) );
define( 'RAPLS_SITEMAP_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Composer autoloader — present once `composer install` has run. This plugin has
 * no runtime dependencies, so it is genuinely optional; the lightweight
 * autoloader below covers the plugin's own classes either way.
 */
if ( file_exists( RAPLS_SITEMAP_DIR . 'vendor/autoload.php' ) ) {
	require RAPLS_SITEMAP_DIR . 'vendor/autoload.php';
}

/*
 * Lightweight PSR-4 autoloader for the plugin's own classes. Lets the plugin
 * run before `composer install` has generated the optimized autoloader.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'RaplsSitemap\\';
		$len    = strlen( $prefix );
		if ( strncmp( $class, $prefix, $len ) !== 0 ) {
			return;
		}
		$relative = substr( $class, $len );
		$path     = RAPLS_SITEMAP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( \RaplsSitemap\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \RaplsSitemap\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\RaplsSitemap\Plugin::instance()->boot();
	}
);
