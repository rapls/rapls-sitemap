<?php
/**
 * Reading a PS Auto Sitemap configuration.
 *
 * That plugin left its settings in one option, `ps_sitemap`, and deleting the
 * plugin does not remove it — so a site that ran it years ago still has the
 * answers its owner already gave: which lists to show, how deep, what to leave
 * out. Asking for them again is the largest remaining friction in switching.
 *
 * Only the option's SHAPE is reproduced here. None of that plugin's code is
 * used, and the mapping is a reading of its documented settings screen: eleven
 * keys, each with a counterpart on this one or a reason it has none.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates a stored PS Auto Sitemap configuration into this plugin's.
 */
final class PsMigration {

	/** The option that plugin wrote, and that survives its deletion. */
	public const OPTION = 'ps_sitemap';

	/**
	 * Its preset names against the nearest thing here.
	 *
	 * "Nearest" is the honest word: these are original stylesheets, not
	 * reproductions, so this picks a preset with the same idea rather than the
	 * same appearance. A name with no counterpart at all is left out, and the
	 * design then stays whatever it already was — a wrong design is a visible,
	 * one-click-fixable disappointment, but a silently changed one is confusing.
	 */
	private const DESIGNS = array(
		'simple'      => 'simple',
		'simple2'     => 'list',
		'checker'     => 'checklist',
		'marker'      => 'marker',
		'document'    => 'tree',
		'label'       => 'label',
		'arrows'      => 'arrow',
		'business'    => 'business',
		'index'       => 'index',
		'under_score' => 'underline',
	);

	/**
	 * Is there anything to import?
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return is_array( get_option( self::OPTION ) );
	}

	/**
	 * The stored configuration, or an empty array.
	 *
	 * @return array<string,mixed>
	 */
	public static function stored(): array {
		$option = get_option( self::OPTION );

		return is_array( $option ) ? $option : array();
	}

	/**
	 * Fold a PS Auto Sitemap configuration into this plugin's settings.
	 *
	 * Every key it wrote is accounted for:
	 *
	 *   home_list      -> show_home
	 *   post_tree      -> `post` in post_types
	 *   page_tree      -> `page` in post_types
	 *   disp_first     -> which of those two comes first
	 *   disp_level     -> depth (0 means unlimited on both sides)
	 *   disp_posts     -> term_mode: combine lists the posts, divide stops at
	 *                     the category links
	 *   ex_cat_ids     -> exclude_terms
	 *   ex_post_ids    -> exclude_ids
	 *   prepared_style -> design, where there is a counterpart
	 *   use_cache      -> cache_ttl, off meaning 0
	 *   post_id        -> nothing, and nothing is needed: it named the page the
	 *                     sitemap was placed on so that page could be kept out
	 *                     of its own list, which `exclude_current` does here
	 *                     without being told which page it is
	 *   suppress_link  -> nothing. The form posted a hidden 1 and nothing read
	 *                     it back
	 *
	 * Keys absent from the stored option leave the current setting alone, so
	 * importing a partial configuration cannot blank anything.
	 *
	 * @param array<string,mixed> $old      The `ps_sitemap` option.
	 * @param array<string,mixed> $settings Settings to fold it into.
	 * @return array<string,mixed>
	 */
	public static function to_settings( array $old, array $settings ): array {
		if ( isset( $old['home_list'] ) ) {
			$settings['show_home'] = '' !== (string) $old['home_list'];
		}

		$settings['post_types'] = self::post_types( $old, $settings );

		if ( isset( $old['disp_level'] ) ) {
			$settings['depth'] = max( 0, min( 10, (int) $old['disp_level'] ) );
		}

		if ( isset( $old['disp_posts'] ) ) {
			// Its "divide" put a "show the posts in this category" link where
			// the posts would have been. There is no such link here, so the
			// nearest reading is the category listing on its own.
			$settings['group_by_term'] = true;
			$settings['term_mode']     = 'divide' === (string) $old['disp_posts'] ? 'terms_only' : 'posts';
		}

		if ( isset( $old['ex_cat_ids'] ) ) {
			$settings['exclude_terms'] = Settings::to_id_list( $old['ex_cat_ids'] );
		}

		if ( isset( $old['ex_post_ids'] ) ) {
			$settings['exclude_ids'] = Settings::to_id_list( $old['ex_post_ids'] );
		}

		$style = isset( $old['prepared_style'] ) ? (string) $old['prepared_style'] : '';
		if ( isset( self::DESIGNS[ $style ] ) ) {
			$settings['design'] = self::DESIGNS[ $style ];
		}

		if ( isset( $old['use_cache'] ) && '' === (string) $old['use_cache'] ) {
			$settings['cache_ttl'] = 0;
		}

		return $settings;
	}

	/**
	 * Which lists it was showing, in the order it was showing them.
	 *
	 * Both switches off is a sitemap of nothing, which nobody configured on
	 * purpose and which would look like the import had failed — so that case
	 * keeps whatever is configured here.
	 *
	 * @param array<string,mixed> $old      The `ps_sitemap` option.
	 * @param array<string,mixed> $settings Settings being folded into.
	 * @return string[]
	 */
	private static function post_types( array $old, array $settings ): array {
		if ( ! isset( $old['post_tree'] ) && ! isset( $old['page_tree'] ) ) {
			return array_values( (array) $settings['post_types'] );
		}

		$types = array();

		// `disp_first` named which of the two came first, and the array order
		// is the output order here.
		if ( 'page' === ( isset( $old['disp_first'] ) ? (string) $old['disp_first'] : 'post' ) ) {
			$order = array( 'page' => 'page_tree', 'post' => 'post_tree' );
		} else {
			$order = array( 'post' => 'post_tree', 'page' => 'page_tree' );
		}

		foreach ( $order as $type => $key ) {
			if ( '' !== (string) ( $old[ $key ] ?? '' ) ) {
				$types[] = $type;
			}
		}

		return array() === $types ? array_values( (array) $settings['post_types'] ) : $types;
	}
}
