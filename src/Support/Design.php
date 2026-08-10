<?php
/**
 * Per-site design tokens.
 *
 * The design *presets* decide the shape of a sitemap; these decide its
 * typography, colour, and bullets on top. They are kept out of Settings so the
 * schema and its (fiddly, security-sensitive) sanitization live together.
 *
 * Everything ends up as CSS custom properties in an inline `style` attribute on
 * the sitemap wrapper, which means: no extra stylesheet, no cache to invalidate
 * separately, and a theme can still override any of it with ordinary CSS.
 *
 * Nothing here is ever interpolated into CSS unvalidated. A value that does not
 * match its pattern is dropped, not escaped — a length is a length, and a
 * colour that is not a colour is a mistake, not a string to sanitize into one.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema, sanitization, and CSS emission for the design tokens.
 */
final class Design {

	/** How a link's underline behaves. */
	public const UNDERLINES = array( 'default', 'always', 'hover', 'never' );

	/** Font weights offered, beyond leaving it to the preset. */
	public const WEIGHTS = array( 'default', 'normal', 'bold' );

	/**
	 * Bullet styles.
	 *
	 * `emoji` renders the character through CSS `content`; `icon` renders a real
	 * `<i class="...">` element, which is what lets Font Awesome, Dashicons, or
	 * any other icon font work without this plugin bundling one.
	 */
	public const MARKERS = array( 'default', 'none', 'disc', 'circle', 'square', 'emoji', 'icon' );

	/**
	 * The complete token schema with its defaults.
	 *
	 * An empty string always means "leave it to the preset", never "set it to
	 * nothing" — that is what keeps the tokens additive over the designs.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			/* Typography. */
			'font_size'         => '',
			'line_height'       => '',
			'indent'            => '',
			/* Layout. The top-level list flows into this many columns; 1 is a
			   single column, which is a real answer on a preset that would
			   otherwise flow. Empty leaves the preset alone. */
			'columns'           => '',
			'column_gap'        => '',

			/* Links. */
			'link_color'        => '',
			'link_hover_color'  => '',
			'underline'         => 'default',

			/* Top-level items — categories, years, and the front-page link. */
			'parent_font_size'  => '',
			'parent_color'      => '',
			'parent_weight'     => 'default',
			'parent_spacing'    => '',

			/* Nested items. */
			'child_font_size'   => '',
			'child_color'       => '',
			'child_weight'      => 'default',

			/* Bullets, set separately for each level. */
			'marker'            => 'default',
			'marker_text'       => '',
			'marker_icon'       => '',
			'marker_color'      => '',
			'child_marker'      => 'default',
			'child_marker_text' => '',
			'child_marker_icon' => '',
		);
	}

	/**
	 * Which sanitizer each token uses.
	 *
	 * @return array<string,string>
	 */
	private static function kinds(): array {
		return array(
			'font_size'         => 'length',
			'line_height'       => 'number_or_length',
			'indent'            => 'length',
			'columns'           => 'columns',
			'column_gap'        => 'length',
			'link_color'        => 'color',
			'link_hover_color'  => 'color',
			'underline'         => 'underline',
			'parent_font_size'  => 'length',
			'parent_color'      => 'color',
			'parent_weight'     => 'weight',
			'parent_spacing'    => 'length',
			'child_font_size'   => 'length',
			'child_color'       => 'color',
			'child_weight'      => 'weight',
			'marker'            => 'marker',
			'marker_text'       => 'glyph',
			'marker_icon'       => 'css_class',
			'marker_color'      => 'color',
			'child_marker'      => 'marker',
			'child_marker_text' => 'glyph',
			'child_marker_icon' => 'css_class',
		);
	}

	/**
	 * Merge stored tokens over the defaults, dropping unknown keys.
	 *
	 * @param mixed $stored Stored token array.
	 * @return array<string,string>
	 */
	public static function merge( $stored ): array {
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Validate raw input into the stored shape.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string,string>
	 */
	public static function sanitize( $input ): array {
		$clean = self::defaults();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		foreach ( self::kinds() as $key => $kind ) {
			$raw = self::raw_value( $input, $key );
			if ( null === $raw ) {
				continue;
			}

			$value = self::clean( $raw, $kind );
			if ( '' !== $value ) {
				$clean[ $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Read one token from raw input, in either shape it can arrive in.
	 *
	 * The settings screen splits every length into a number spinner and a unit
	 * select, because dragging a number is nicer than typing "1.25em". Those
	 * post as `<key>_value` and `<key>_unit` and are recombined here. The plain
	 * `<key>` remains accepted so filters, imports, and programmatic
	 * `update_option()` calls keep working with a single string.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @param string              $key   Token key.
	 * @return string|null Null when the token was not submitted at all.
	 */
	private static function raw_value( array $input, string $key ) {
		if ( isset( $input[ $key . '_value' ] ) ) {
			$number = trim( (string) $input[ $key . '_value' ] );
			if ( '' === $number ) {
				return '';
			}

			$unit = isset( $input[ $key . '_unit' ] ) ? trim( (string) $input[ $key . '_unit' ] ) : '';

			return $number . $unit;
		}

		return isset( $input[ $key ] ) ? (string) $input[ $key ] : null;
	}

	/**
	 * Split a stored length back into its number and unit, for the UI.
	 *
	 * @param string $value Stored value, e.g. "1.25em".
	 * @return array{0:string,1:string} Number and unit, either possibly ''.
	 */
	public static function split( string $value ): array {
		if ( preg_match( '/^(\d+(?:\.\d+)?)([a-z%]*)$/i', trim( $value ), $m ) ) {
			return array( $m[1], $m[2] );
		}

		return array( '', '' );
	}

	/**
	 * Validate one value against its kind. Returns '' when it does not qualify.
	 *
	 * @param string $value Raw value.
	 * @param string $kind  Sanitizer name.
	 * @return string
	 */
	private static function clean( string $value, string $kind ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		switch ( $kind ) {
			case 'length':
				// A bare number is treated as px, which is what someone typing
				// "18" into a font size box means.
				if ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
					return $value . 'px';
				}
				return preg_match( '/^\d+(\.\d+)?(px|rem|em|%|pt|vw|ch)$/', $value ) ? $value : '';

			case 'number_or_length':
				// Line height is legitimately unitless.
				if ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
					return $value;
				}
				return preg_match( '/^\d+(\.\d+)?(px|rem|em|%)$/', $value ) ? $value : '';

			case 'columns':
				// Two to six is the useful range and the honest one: seven
				// columns of links is not a table of contents anyone reads, and
				// a cap keeps a typed 200 from producing hairlines.
				$columns = (int) $value;
				return ( $columns >= 1 && $columns <= 6 ) ? (string) $columns : '';

			case 'color':
				return self::clean_color( $value );

			case 'underline':
				return in_array( $value, self::UNDERLINES, true ) ? $value : '';

			case 'weight':
				return in_array( $value, self::WEIGHTS, true ) ? $value : '';

			case 'marker':
				return in_array( $value, self::MARKERS, true ) ? $value : '';

			case 'glyph':
				return self::clean_glyph( $value );

			case 'css_class':
				// Icon libraries only ever need these characters, and none of
				// them can terminate the class attribute.
				return preg_match( '/^[A-Za-z0-9 _-]{1,120}$/', $value ) ? $value : '';
		}

		return '';
	}

	/**
	 * Accept the colour notations a CSS value can safely take.
	 *
	 * Deliberately narrow: no `url()`, no semicolons, no braces, nothing that
	 * could close a declaration and start another.
	 *
	 * @param string $value Raw colour.
	 * @return string
	 */
	private static function clean_color( string $value ): string {
		if ( preg_match( '/^#[0-9A-Fa-f]{3,8}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg-]+\)$/i', $value ) ) {
			return $value;
		}

		// A CSS variable, so a block theme's palette can be pointed at directly.
		if ( preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/', $value ) ) {
			return $value;
		}

		// Named colours, plus `transparent` and `currentColor`.
		return preg_match( '/^[A-Za-z]{3,24}$/', $value ) ? $value : '';
	}

	/**
	 * Accept a short glyph for use inside a CSS `content` string.
	 *
	 * Quotes and backslashes are removed rather than escaped: an emoji bullet
	 * needs neither, and allowing them is how a `content` value turns into an
	 * escape sequence somebody has to reason about.
	 *
	 * @param string $value Raw glyph.
	 * @return string
	 */
	private static function clean_glyph( string $value ): string {
		$value = str_replace( array( '"', "'", '\\', ';', '{', '}', '<', '>' ), '', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		// Long enough for an emoji with a modifier or a ZWJ sequence, short
		// enough that nobody pastes a stylesheet in here.
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 8 ) : substr( $value, 0, 24 );
	}

	/**
	 * Is any token set at all?
	 *
	 * @param array<string,string> $style Sanitized tokens.
	 * @return bool
	 */
	public static function is_configured( array $style ): bool {
		return self::defaults() !== self::merge( $style );
	}

	/**
	 * A class identifying this exact token set.
	 *
	 * Emitting declarations under a hash of the tokens — rather than under the
	 * plain block class — is what lets two placements on one page carry
	 * different settings without one bleeding into the other. It is
	 * deterministic, so it does not perturb the render cache.
	 *
	 * @param array<string,string> $style Sanitized tokens.
	 * @return string Empty when nothing is configured.
	 */
	public static function scope_class( array $style ): string {
		if ( ! self::is_configured( $style ) ) {
			return '';
		}

		return 'rapls-sitemap--t' . substr( md5( (string) wp_json_encode( self::merge( $style ) ) ), 0, 8 );
	}

	/**
	 * The tokens as a scoped stylesheet.
	 *
	 * Only tokens that were actually set produce a declaration. That matters
	 * more than it looks: CSS has no value meaning "leave the cascade alone",
	 * so emitting `color: var(--x, inherit)` for an unset colour would quietly
	 * override the theme's link colour with the surrounding text colour.
	 * Declarations that do not exist cannot do that.
	 *
	 * @param array<string,string> $style Sanitized tokens.
	 * @return string CSS, or '' when nothing is configured.
	 */
	public static function style_block( array $style ): string {
		$scope = self::scope_class( $style );
		if ( '' === $scope ) {
			return '';
		}

		$style  = self::merge( $style );
		$s      = '.' . $scope;
		$parent = $s . ' .rapls-sitemap__list--depth-0 > .rapls-sitemap__item';
		$child  = $s . ' .rapls-sitemap__list:not(.rapls-sitemap__list--depth-0) > .rapls-sitemap__item';
		$rules  = array();

		/* Typography and rhythm. */
		$rules[] = self::rule(
			$s,
			array(
				'font-size'   => $style['font_size'],
				'line-height' => $style['line_height'],
			)
		);

		$rules[] = self::rule( $s . ' .rapls-sitemap__list:not(.rapls-sitemap__list--depth-0)', array( 'margin-left' => $style['indent'] ) );
		$rules[] = self::column_rules( $s, $style['columns'], $style['column_gap'] );
		$rules[] = self::rule( $parent, array( 'margin-top' => $style['parent_spacing'] ) );

		/* Links. */
		$rules[] = self::rule( $s . ' .rapls-sitemap__link', array( 'color' => $style['link_color'] ) );
		$rules[] = self::rule( $s . ' .rapls-sitemap__link:hover, ' . $s . ' .rapls-sitemap__link:focus', array( 'color' => $style['link_hover_color'] ) );

		$rules[] = self::underline_rules( $s, $style['underline'] );

		/* Levels. */
		$rules[] = self::rule(
			$parent . ' > .rapls-sitemap__link, ' . $parent . ' > .rapls-sitemap__label',
			array(
				'font-size'   => $style['parent_font_size'],
				'color'       => $style['parent_color'],
				'font-weight' => 'default' === $style['parent_weight'] ? '' : $style['parent_weight'],
			)
		);

		$rules[] = self::rule(
			$child . ' > .rapls-sitemap__link, ' . $child . ' > .rapls-sitemap__label',
			array(
				'font-size'   => $style['child_font_size'],
				'color'       => $style['child_color'],
				'font-weight' => 'default' === $style['child_weight'] ? '' : $style['child_weight'],
			)
		);

		/* Bullets. */
		$rules[] = self::marker_rules( $s, $parent, $style['marker'], $style['marker_text'], $style['marker_color'] );
		$rules[] = self::marker_rules( $s, $child, $style['child_marker'], $style['child_marker_text'], $style['marker_color'] );

		return implode( '', array_filter( $rules ) );
	}

	/**
	 * The top-level list in columns, or nothing at all.
	 *
	 * Two declarations come as a pair with the count. A gap, because two
	 * columns of links touching each other read as one column of nonsense, and
	 * `break-inside` on the items, because a browser will otherwise split a
	 * category and its children down the middle of the page. Both are emitted
	 * only where the count is, so the rule that a token which was not set emits
	 * nothing still holds.
	 *
	 * @param string $scope   Scope selector.
	 * @param string $columns Column count, or ''.
	 * @param string $gap     Gap length, or ''.
	 * @return string
	 */
	private static function column_rules( string $scope, string $columns, string $gap ): string {
		if ( '' === $columns ) {
			return '';
		}

		$list = $scope . ' .rapls-sitemap__list--depth-0';

		return self::rule(
			$list,
			array(
				'column-count' => $columns,
				'column-gap'   => '' !== $gap ? $gap : '2.5rem',
			)
		) . self::rule(
			$list . ' > .rapls-sitemap__item',
			array(
				'break-inside'                => 'avoid',
				'-webkit-column-break-inside' => 'avoid',
			)
		);
	}

	/**
	 * One rule, or '' when every declaration in it is unset.
	 *
	 * @param string                $selector     CSS selector.
	 * @param array<string,string>  $declarations Property => value.
	 * @return string
	 */
	private static function rule( string $selector, array $declarations ): string {
		$body = '';

		foreach ( $declarations as $property => $value ) {
			if ( '' !== $value ) {
				$body .= $property . ':' . $value . ';';
			}
		}

		return '' === $body ? '' : $selector . '{' . $body . '}';
	}

	/**
	 * Underline behaviour, which needs a hover rule as well as a base one.
	 *
	 * @param string $scope Scope selector.
	 * @param string $mode  One of UNDERLINES.
	 * @return string
	 */
	private static function underline_rules( string $scope, string $mode ): string {
		$link  = $scope . ' .rapls-sitemap__link';
		$hover = $link . ':hover,' . $link . ':focus';

		switch ( $mode ) {
			case 'always':
				return self::rule( $link, array( 'text-decoration' => 'underline' ) );

			case 'never':
				return self::rule( $link, array( 'text-decoration' => 'none' ) )
					. self::rule( $hover, array( 'text-decoration' => 'none' ) );

			case 'hover':
				return self::rule( $link, array( 'text-decoration' => 'none' ) )
					. self::rule( $hover, array( 'text-decoration' => 'underline' ) );
		}

		return '';
	}

	/**
	 * Bullet rules for one level.
	 *
	 * The presets draw their ornaments with a positioned `::before`, so any
	 * custom bullet has to flatten that geometry before setting its own —
	 * otherwise a preset's absolutely-positioned tick would still be sitting
	 * where the new bullet wants to be.
	 *
	 * @param string $scope    Scope selector.
	 * @param string $items    Selector for the items at this level.
	 * @param string $mode     One of MARKERS.
	 * @param string $glyph    Emoji/text bullet.
	 * @param string $color    Bullet colour, if any.
	 * @return string
	 */
	private static function marker_rules( string $scope, string $items, string $mode, string $glyph, string $color ): string {
		if ( 'default' === $mode ) {
			return '';
		}

		$before = $items . '::before';

		// Every custom bullet starts by clearing whatever the preset drew.
		$reset = self::rule(
			$before,
			array(
				'background' => 'none',
				'border'     => '0',
				'content'    => 'none',
				'height'     => 'auto',
				'padding'    => '0',
				'position'   => 'static',
				'transform'  => 'none',
				'width'      => 'auto',
			)
		) . self::rule( $items, array( 'list-style' => 'none', 'padding-left' => '0' ) );

		if ( 'none' === $mode ) {
			return $reset;
		}

		if ( in_array( $mode, array( 'disc', 'circle', 'square' ), true ) ) {
			return $reset . self::rule(
				$items,
				array(
					'display'      => 'list-item',
					'list-style'   => $mode,
					'margin-left'  => '1.15em',
					'padding-left' => '0.15em',
				)
			) . self::rule( $items . '::marker', array( 'color' => $color ) );
		}

		if ( 'emoji' === $mode && '' !== $glyph ) {
			return $reset . self::rule(
				$before,
				array(
					'content'      => '"' . $glyph . '"',
					'display'      => 'inline-block',
					'margin-right' => '0.4em',
					'color'        => $color,
				)
			);
		}

		// 'icon' renders a real element in the markup; only the colour of that
		// element belongs in CSS.
		if ( 'icon' === $mode ) {
			return $reset . self::rule( $scope . ' .rapls-sitemap__icon', array( 'color' => $color ) );
		}

		return $reset;
	}

	/**
	 * The icon class configured for a depth, or '' when icons are not in use.
	 *
	 * @param array<string,string> $style Sanitized tokens.
	 * @param int                  $depth Zero-based nesting depth.
	 * @return string
	 */
	public static function icon_class( array $style, int $depth ): string {
		$mode  = 0 === $depth ? ( $style['marker'] ?? 'default' ) : ( $style['child_marker'] ?? 'default' );
		$class = 0 === $depth ? ( $style['marker_icon'] ?? '' ) : ( $style['child_marker_icon'] ?? '' );

		return ( 'icon' === $mode && '' !== $class ) ? $class : '';
	}
}
