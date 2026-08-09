<?php
/**
 * Where the plugin asks for support, and — more importantly — where it does not.
 *
 * The WordPress.org guidelines draw two lines this file stays behind:
 *
 *   Guideline 10 — credits and links on the *front end* must default to off and
 *   be opt-in. The simplest way to comply is not to have any, which is what
 *   this plugin does. Nothing here ever runs outside `is_admin()`.
 *
 *   Guideline 11 — advertising in the dashboard is to be avoided, and any
 *   notice must be dismissible and limited in scope. So there is no admin
 *   notice, no dashboard widget, no nag, no "rate us after 7 days" timer. The
 *   link appears in exactly two places a user has to go looking for: the
 *   plugin's own settings screen, and its row on the Plugins screen — the
 *   conventional home for a donate link, alongside "View details".
 *
 * `readme.txt` additionally carries the `Donate link:` header, which is the
 * officially supported way to surface this on WordPress.org itself.
 *
 * If this ever grows into something a user could mistake for an advertisement,
 * it has gone too far.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the support link to the plugin's own admin surfaces.
 */
final class SupportPanel {

	/** Where the link goes. */
	public const URL = 'https://buymeacoffee.com/rapls';

	/** The plugin's review form on WordPress.org. */
	public const REVIEW_URL = 'https://wordpress.org/support/plugin/rapls-sitemap/reviews/#new-post';

	/**
	 * Hook registration. Admin only, by construction.
	 */
	public function register(): void {
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Append the link to this plugin's row on the Plugins screen.
	 *
	 * @param string[] $links Existing row meta links.
	 * @param string   $file  Plugin file the row belongs to.
	 * @return string[]
	 */
	public function row_meta( array $links, string $file ): array {
		if ( ! defined( 'RAPLS_SITEMAP_BASENAME' ) || RAPLS_SITEMAP_BASENAME !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::URL ),
			esc_html__( 'Buy me a coffee', 'rapls-sitemap' )
		);

		return $links;
	}

	/**
	 * The support panel at the foot of the plugin's settings screen.
	 *
	 * Styled to match `rapls-pdf-image-creator`, so the family looks like one
	 * family. It sits below the settings, on the plugin's own screen, and it
	 * appears exactly once — which is what keeps a visible panel on the right
	 * side of guideline 11. It is not a notice, it does not follow the user
	 * around, and there is nothing to dismiss because there is nothing that
	 * reappears.
	 */
	public static function render_support(): void {
		?>
		<div class="rapls-support">
			<h2><?php echo esc_html__( 'Support this plugin', 'rapls-sitemap' ); ?></h2>
			<p><?php echo esc_html__( 'Rapls Sitemap is free and always will be. If it saved you some time, there are two ways to help.', 'rapls-sitemap' ); ?></p>

			<div class="rapls-support__buttons">
				<a class="rapls-support__bmc" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="rapls-support__icon" aria-hidden="true">☕</span>
					<?php echo esc_html__( 'Buy me a coffee', 'rapls-sitemap' ); ?>
				</a>

				<a class="rapls-support__review" href="<?php echo esc_url( self::REVIEW_URL ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="rapls-support__icon" aria-hidden="true">★</span>
					<?php echo esc_html__( 'Leave a review', 'rapls-sitemap' ); ?>
				</a>
			</div>

			<p class="rapls-support__note"><?php echo esc_html__( 'Reviews help other people find the plugin. Thank you.', 'rapls-sitemap' ); ?></p>
		</div>
		<?php
	}
}
