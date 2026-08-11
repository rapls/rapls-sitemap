<?php
/**
 * Settings screen (Settings → Rapls Sitemap).
 *
 * Uses the core Settings API, so the option is written by `options.php` and
 * validated by Settings::sanitize() — this class never calls update_option()
 * itself and holds no sanitization logic of its own.
 *
 * @package RaplsSitemap
 */

namespace RaplsSitemap\Admin;

use RaplsSitemap\Frontend\Shortcode;
use RaplsSitemap\Sitemap\TreeBuilder;
use RaplsSitemap\Support\Design;
use RaplsSitemap\Support\Hooks;
use RaplsSitemap\Support\PsMigration;
use RaplsSitemap\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the options screen.
 */
final class SettingsPage {

	/** Settings API group. */
	private const GROUP = 'rapls_sitemap';

	/** Menu/page slug. */
	public const SLUG = 'rapls-sitemap';

	/** Capability required to view and save. */
	private const CAPABILITY = 'manage_options';

	/** `admin-post.php` action backing the reset button. */
	public const RESET_ACTION = 'rapls_sitemap_reset';

	/** ...and the one backing the PS Auto Sitemap import. */
	public const IMPORT_ACTION = 'rapls_sitemap_import_ps';

	/**
	 * What a colour swatch shows when the field holds no hex.
	 *
	 * A colour input has no "unset" state — it always displays something — so
	 * clearing the field has to put the swatch back to a known neutral, or the
	 * old colour stays on screen and the field looks unchanged.
	 */
	private const SWATCH_DEFAULT = '#0073aa';

	/**
	 * The screen id `add_options_page()` handed back, so the stylesheet loads
	 * here and nowhere else.
	 *
	 * @var string|null
	 */
	private $hook_suffix = null;

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the screen's stylesheet — on this screen only.
	 *
	 * @param string $hook_suffix Screen the admin is loading.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix || null === $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'rapls-sitemap-admin',
			RAPLS_SITEMAP_URL . 'assets/css/admin.css',
			array(),
			RAPLS_SITEMAP_VERSION
		);

		wp_enqueue_script(
			'rapls-sitemap-admin',
			RAPLS_SITEMAP_URL . 'assets/js/admin.js',
			array(),
			RAPLS_SITEMAP_VERSION,
			true
		);

		// The emoji palette and the button labels come from PHP so they are
		// translatable; the script is otherwise self-contained.
		wp_add_inline_script(
			'rapls-sitemap-admin',
			'window.raplsSitemapAdmin = ' . wp_json_encode(
				array(
					'emoji'  => self::emoji_palette(),
					'notes'  => self::emoji_notes(),
					'labels' => array(
						'pick'  => __( 'Pick an emoji', 'rapls-sitemap' ),
						'close' => __( 'Close', 'rapls-sitemap' ),
						'clear' => __( 'No emoji', 'rapls-sitemap' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * The emoji offered as bullets.
	 *
	 * Chosen for legibility at bullet size and for reading as a list marker
	 * rather than as decoration — which rules out most faces and anything whose
	 * meaning changes between platforms. The field stays free text, so this is
	 * a shortcut, not a limit.
	 *
	 * Public so `smoke-design.php` can prove every glyph survives the token
	 * sanitizer — a palette entry that gets mangled on save would be a picker
	 * that lies.
	 *
	 * @return array<string,string[]> Group label => emoji.
	 */
	public static function emoji_palette(): array {
		return array(
			__( 'Pointers', 'rapls-sitemap' )    => array(
				'▶️', '▸', '▹', '➤', '➣', '➔', '➡️', '⬅️', '⬆️', '⬇️',
				'↗️', '↘️', '↙️', '↖️', '↔️', '↕️', '⤴️', '⤵️', '↪️', '↩️',
				'🔃', '🔄', '⏩', '⏪', '⏫', '⏬', '»', '›', '«', '‹',
				'·', '‣', '⁃', '–', '—', '⇒', '⇨', '☞', '🔜', '🔛',
			),
			__( 'Shapes', 'rapls-sitemap' )      => array(
				'🔹', '🔸', '🔺', '🔻', '🔶', '🔷', '💠', '🔘', '🔲', '🔳',
				'◾', '◽', '▪️', '▫️', '◼️', '◻️', '⬛', '⬜', '◆', '◇',
				'■', '□', '●', '○', '▲', '△', '▼', '▽', '⬢', '⬣',
				'✦', '✧', '❖', '⧫', '⬟', '⬠', '⌗', '⁂', '※', '⁕',
			),
			__( 'Dots', 'rapls-sitemap' )        => array(
				'🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '🟤', '⚫', '⚪',
				'🟥', '🟧', '🟨', '🟩', '🟦', '🟪', '🟫',
			),
			__( 'Marks', 'rapls-sitemap' )       => array(
				'✅', '☑️', '✔️', '✖️', '❌', '⭕', '✳️', '✴️', '❇️', '❓',
				'❔', '❗', '❕', '‼️', '⁉️', '➕', '➖', '➗', '✂️', '♦️',
				'♠️', '♣️', '♥️', '💯', '🔱', '⚜️', '🔰', '⭐', '🌟', '✨',
				'⚠️', '🚫', '🔞', '♻️', '⚛️', '☢️', '☣️', '🆕', '🆓', '🔤',
			),
			__( 'Hearts', 'rapls-sitemap' )      => array(
				'❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💕',
				'💖', '💗', '💘', '💝', '💞', '💓', '💔', '❣️', '💌', '💟',
			),
			__( 'Faces', 'rapls-sitemap' )       => array(
				'😀', '😃', '😄', '😁', '😆', '😅', '🤣', '😂', '🙂', '🙃',
				'😉', '😊', '😇', '🥰', '😍', '🤩', '😘', '😗', '😚', '😙',
				'😋', '😛', '😜', '🤪', '😝', '🤗', '🤭', '🤫', '🤔', '🤐',
				'🤨', '😐', '😑', '😶', '😏', '😒', '🙄', '😬', '😌', '😔',
				'😪', '😴', '🥱', '😷', '🤒', '🤕', '🤧', '🥳',
			),
			__( 'Hands', 'rapls-sitemap' )       => array(
				'👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉',
				'👆', '👇', '☝️', '✋', '🤚', '🖐️', '🖖', '👋', '🤝', '🙏',
				'👏', '🙌', '👐', '🤲', '💪', '✊', '👊', '🤛', '🤜', '✍️',
				'🫶', '🫰',
			),
			__( 'People', 'rapls-sitemap' )      => array(
				'👤', '👥', '🧑', '👨', '👩', '🧒', '👦', '👧', '👴', '👵',
				'🙋', '🙆', '🙅', '💁', '🤷', '🤦', '🧑‍💻', '👨‍🍳', '👩‍🏫', '🧑‍🎓',
				'👮', '🕵️', '🧑‍🌾', '🧑‍🔧',
			),
			__( 'Documents', 'rapls-sitemap' )   => array(
				'📄', '📃', '📑', '📋', '📁', '📂', '🗂️', '🗃️', '🗄️', '📌',
				'📍', '📎', '🖇️', '📏', '📐', '✏️', '🖊️', '🖋️', '📝', '📖',
				'📕', '📗', '📘', '📙', '📚', '📓', '📔', '📒', '📰', '🗞️',
				'🔖', '🏷️', '📊', '📈', '📉', '🗒️', '🗓️', '📅', '📆', '🧾',
			),
			__( 'Interface', 'rapls-sitemap' )   => array(
				'🏠', '🏡', '🔍', '🔎', '⚙️', '🔧', '🔨', '🛠️', '🗝️', '🔑',
				'🔐', '🔒', '🔓', '🔔', '🔕', '📢', '📣', '💬', '💭', '🗨️',
				'💡', '🖥️', '💻', '📱', '⌨️', '🖱️', '🖨️', '🔗', '📡', '🗺️',
				'🧭', '⏰',
			),
			__( 'Nature', 'rapls-sitemap' )      => array(
				'🌱', '🌿', '🍀', '🍃', '🌾', '🌵', '🌳', '🌲', '🌴', '🎋',
				'🎍', '🌸', '🌺', '🌻', '🌼', '🌷', '🌹', '💐', '🏵️', '🍁',
				'🍂', '🌰', '🌽', '🍄', '🐚', '🌊', '💧', '💦', '🔥', '⚡',
				'🌈', '❄️', '⛄', '🗻', '🏔️', '⛰️', '🌋', '🏕️', '🏖️', '🏜️',
			),
			__( 'Weather', 'rapls-sitemap' )     => array(
				'☀️', '🌤️', '⛅', '🌥️', '☁️', '🌦️', '🌧️', '⛈️', '🌩️', '🌨️',
				'🌪️', '🌫️', '🌬️', '🌙', '🌛', '🌜', '🌚', '🌝', '🌞', '🌑',
				'🌒', '🌓', '🌔', '🌕', '🌖', '🌗', '🌘', '🌠', '🌌', '☄️',
				'🌍', '🌏',
			),
			__( 'Food', 'rapls-sitemap' )        => array(
				'☕', '🍵', '🧋', '🥤', '🍶', '🍺', '🍻', '🍷', '🥂', '🍸',
				'🍎', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍒', '🍑',
				'🥭', '🍍', '🥝', '🍅', '🥑', '🥕', '🍞', '🥐', '🥖', '🧀',
				'🍰', '🎂', '🍩', '🍪', '🍫', '🍬', '🍜', '🍣', '🍙', '🍱',
			),
			__( 'Animals', 'rapls-sitemap' )     => array(
				'🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯',
				'🦁', '🐮', '🐷', '🐸', '🐵', '🙈', '🙉', '🙊', '🐔', '🐧',
				'🐦', '🐤', '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄',
				'🐝', '🦋', '🐌', '🐞', '🐢', '🐍', '🐙', '🐠', '🐬', '🐳',
			),
			__( 'Places', 'rapls-sitemap' )      => array(
				'🏢', '🏬', '🏭', '🏫', '🏥', '🏦', '🏨', '🏪', '🏛️', '⛪',
				'🕌', '🛕', '⛩️', '🏯', '🏰', '🗼', '🗽', '🎡', '🎢', '🎠',
				'🌉', '🌁', '🏙️', '🌃', '🗾', '🚗', '🚕', '🚌', '🚐', '🚲',
				'🛵', '🚂', '🚆', '🚉', '✈️', '🚀', '🚁', '⛵', '🚢', '🛳️',
			),
			__( 'Celebration', 'rapls-sitemap' ) => array(
				'🎉', '🎊', '🎈', '🎁', '🎀', '🏆', '🥇', '🥈', '🥉', '🎯',
				'🎨', '🎵', '🎶', '🎬', '📷', '📸', '🎤', '🎧', '🎮', '🕹️',
				'🧩', '♟️', '🎪', '🎭', '💎', '👑', '🔮', '🧸', '🎃', '🎄',
				'🎆', '🎇',
			),
			__( 'Flags', 'rapls-sitemap' )       => array(
				'🏁', '🚩', '🏳️', '🏴', '🎌', '🏳️‍🌈',
				'🇯🇵', '🇰🇷', '🇨🇳', '🇹🇼', '🇭🇰', '🇸🇬', '🇹🇭', '🇻🇳', '🇵🇭', '🇮🇩',
				'🇲🇾', '🇮🇳', '🇳🇵', '🇱🇰', '🇲🇳', '🇹🇷', '🇦🇪', '🇸🇦', '🇮🇱',
				'🇬🇧', '🇫🇷', '🇩🇪', '🇮🇹', '🇪🇸', '🇵🇹', '🇳🇱', '🇧🇪', '🇨🇭', '🇦🇹',
				'🇸🇪', '🇳🇴', '🇩🇰', '🇫🇮', '🇵🇱', '🇨🇿', '🇭🇺', '🇷🇴', '🇬🇷', '🇮🇪',
				'🇺🇦', '🇷🇺', '🇪🇺',
				'🇺🇸', '🇨🇦', '🇲🇽', '🇧🇷', '🇦🇷', '🇨🇱', '🇨🇴', '🇵🇪',
				'🇦🇺', '🇳🇿', '🇿🇦', '🇪🇬', '🇰🇪', '🇳🇬', '🇲🇦',
			),
		);
	}

	/**
	 * Caveats shown under a tab, keyed by the same group label.
	 *
	 * Only one tab needs one today, and it needs it badly: a flag chosen here
	 * silently becomes two letters for every Windows visitor.
	 *
	 * @return array<string,string>
	 */
	public static function emoji_notes(): array {
		return array(
			__( 'Flags', 'rapls-sitemap' ) => __( 'Windows ships no country flag glyphs, so these appear as two letters — 🇯🇵 becomes JP — for visitors on Windows. The first five are ordinary symbols and are safe everywhere.', 'rapls-sitemap' ),
		);
	}

	/**
	 * Restore every setting to its default.
	 *
	 * A POST to `admin-post.php` rather than a link, so it cannot be triggered
	 * by anything that merely gets a logged-in admin to load a URL.
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'rapls-sitemap' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::RESET_ACTION );

		update_option( Settings::OPTION, Settings::defaults() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::SLUG,
					'rapls-reset' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Fold a stored PS Auto Sitemap configuration into these settings.
	 *
	 * A POST for the same reason the reset is one: it rewrites the settings, so
	 * merely loading a URL must not be able to do it.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'rapls-sitemap' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::IMPORT_ACTION );

		$old = PsMigration::stored();

		if ( array() !== $old ) {
			update_option(
				Settings::OPTION,
				Settings::sanitize( PsMigration::to_settings( $old, Settings::get() ) )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'rapls-import' => array() === $old ? '0' : '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Add the submenu entry.
	 */
	public function add_page(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'Rapls Sitemap', 'rapls-sitemap' ),
			__( 'Rapls Sitemap', 'rapls-sitemap' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the single option with its sanitizer.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = Settings::get();
		$name     = Settings::OPTION;

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Rapls Sitemap', 'rapls-sitemap' ); ?></h1>

			<?php
			// The value, not merely its presence: handle_import() redirects with
			// a 0 when there was nothing to read, and a success notice over a
			// screen that did not change is worse than no notice at all.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$imported = isset( $_GET['rapls-import'] ) ? sanitize_key( wp_unslash( $_GET['rapls-import'] ) ) : '';
			?>
			<?php if ( '1' === $imported ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'The PS Auto Sitemap settings were read in. Check them over before saving anything else — the design is the nearest match, not the same stylesheet.', 'rapls-sitemap' ); ?></p>
				</div>
			<?php elseif ( '' !== $imported ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'There was no PS Auto Sitemap configuration to read. Nothing on this screen was changed.', 'rapls-sitemap' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['rapls-reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings reset to their defaults.', 'rapls-sitemap' ); ?></p>
				</div>
			<?php endif; ?>

			<p>
				<?php echo esc_html__( 'Place the sitemap with the shortcode below, or with the "Sitemap" block.', 'rapls-sitemap' ); ?>
				<code>[<?php echo esc_html( Shortcode::TAG ); ?>]</code>
			</p>

			<?php self::tabs(); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<div class="rapls-pane rapls-pane--basic">
					<?php $this->render_basic( $name, $settings ); ?>
				</div>

				<div class="rapls-pane rapls-pane--advanced">
					<?php $this->render_more( $name, $settings ); ?>
				</div>

				<?php submit_button(); ?>
			</form>

			<?php
			$this->render_import();
			$this->render_reset();
			SupportPanel::render_support();
			?>
		</div>
		<?php
	}

	/**
	 * The two tabs.
	 *
	 * Radio buttons and a sibling selector, for the same reason the sections
	 * collapse that way: no build step, and no state to keep in sync. They sit
	 * outside the form, so nothing about which tab is open is ever posted, and
	 * the hidden pane still submits every field it holds — a browser omits a
	 * disabled control, not a hidden one, which is what makes one Save button
	 * correct for both tabs.
	 *
	 * The wrapper is a `div`, not the `h2` core's own tab bars use. Those are
	 * links to other screens, and a heading is a fair description of one. These
	 * two are a control that swaps what is shown on this screen, and "Basic
	 * Advanced" is not a heading of anything — the radio group is what a screen
	 * reader should meet here, and it is what it does meet. `nav-tab-wrapper`
	 * stays, because core's stylesheet is what makes it look like a tab bar.
	 */
	private static function tabs(): void {
		?>
		<input type="radio" class="rapls-tab-input" name="rapls-sitemap-tab" id="rapls-sitemap-tab-basic" checked />
		<input type="radio" class="rapls-tab-input" name="rapls-sitemap-tab" id="rapls-sitemap-tab-advanced" />
		<div class="nav-tab-wrapper rapls-tabs">
			<label class="nav-tab rapls-tab--basic" for="rapls-sitemap-tab-basic">
				<?php echo esc_html__( 'Basic', 'rapls-sitemap' ); ?>
			</label>
			<label class="nav-tab rapls-tab--advanced" for="rapls-sitemap-tab-advanced">
				<?php echo esc_html__( 'Advanced', 'rapls-sitemap' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * The Basic tab.
	 *
	 * The eight answers a sitemap cannot be built without, in the order
	 * somebody gives them: what to list, how deep, what to leave out, what it
	 * looks like. A site that comes from PS Auto Sitemap finds every setting
	 * that plugin had on this one screen, and nothing else.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function render_basic( string $name, array $settings ): void {
		?>
		<p class="description rapls-pane__lead">
			<?php echo esc_html__( 'Every setting here has a working default, so choosing what to list is enough to finish. Everything else lives under Advanced.', 'rapls-sitemap' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rapls-sitemap-source"><?php echo esc_html__( 'What to list', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<select id="rapls-sitemap-source" name="<?php echo esc_attr( $name . '[source]' ); ?>">
						<?php foreach ( self::sources() as $slug => $text ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['source'], $slug ); ?>>
								<?php echo esc_html( $text ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php echo esc_html__( 'The author and archive listings are built from who published and when, so the other settings reach them only where they make sense: both take the entry cap, the exclusions and the design, and the archive listing takes the publication window as well. Ordering and grouping belong to the entries, which those two do not list. Both are derived from posts, whatever this sitemap lists — a date archive shows posts, so a year taken from pages would link somewhere empty.', 'rapls-sitemap' ); ?>
					</p>
					<p>
						<label>
							<?php echo esc_html__( 'Menu', 'rapls-sitemap' ); ?>
							<select name="<?php echo esc_attr( $name . '[menu]' ); ?>">
								<option value=""><?php echo esc_html__( '— Select —', 'rapls-sitemap' ); ?></option>
								<?php foreach ( self::available_menus() as $menu ) : ?>
									<option value="<?php echo esc_attr( (string) $menu->term_id ); ?>" <?php selected( (string) $settings['menu'], (string) $menu->term_id ); ?>>
										<?php echo esc_html( $menu->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="description">
						<?php echo esc_html__( 'A menu is listed exactly as it was arranged, with its own labels — the order here is a decision somebody made, so it is not re-sorted. Only the exclusions, the depth limit and the entry cap apply to it.', 'rapls-sitemap' ); ?>
					</p>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[menu_headings]' ); ?>"
							<?php checked( ! empty( $settings['menu_headings'] ) ); ?> />
						<?php echo esc_html__( 'Print items with no destination as headings', 'rapls-sitemap' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'A menu item whose link is "#" holds open a dropdown. In a table of contents it is a link that goes nowhere, so its label is printed as plain text instead.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Content to include', 'rapls-sitemap' ); ?></th>
				<td>
					<?php $this->post_type_checkboxes( $name, $settings ); ?>
					<p class="description">
						<?php echo esc_html__( 'The number sets the output order — the lowest is listed first.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-depth"><?php echo esc_html__( 'Maximum depth', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" max="10" step="1"
						id="rapls-sitemap-depth"
						name="<?php echo esc_attr( $name . '[depth]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['depth'] ); ?>" />
					<p class="description"><?php echo esc_html__( '0 shows every level.', 'rapls-sitemap' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Home link', 'rapls-sitemap' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[show_home]' ); ?>"
							<?php checked( ! empty( $settings['show_home'] ) ); ?> />
						<?php echo esc_html__( 'Show a link to the front page first', 'rapls-sitemap' ); ?>
					</label>
					<p>
						<input type="text" class="regular-text"
							name="<?php echo esc_attr( $name . '[home_label]' ); ?>"
							value="<?php echo esc_attr( (string) $settings['home_label'] ); ?>"
							placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-exclude-ids"><?php echo esc_html__( 'Exclude post IDs', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text"
						id="rapls-sitemap-exclude-ids"
						name="<?php echo esc_attr( $name . '[exclude_ids]' ); ?>"
						value="<?php echo esc_attr( implode( ', ', (array) $settings['exclude_ids'] ) ); ?>" />
					<p class="description"><?php echo esc_html__( 'Comma separated. Children of an excluded page are excluded too.', 'rapls-sitemap' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-exclude-terms"><?php echo esc_html__( 'Exclude category IDs', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text"
						id="rapls-sitemap-exclude-terms"
						name="<?php echo esc_attr( $name . '[exclude_terms]' ); ?>"
						value="<?php echo esc_attr( implode( ', ', (array) $settings['exclude_terms'] ) ); ?>" />
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-design"><?php echo esc_html__( 'Design', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<select id="rapls-sitemap-design" name="<?php echo esc_attr( $name . '[design]' ); ?>">
						<?php foreach ( self::designs() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['design'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $name . '[load_styles]' ); ?>"
								<?php checked( ! empty( $settings['load_styles'] ) ); ?> />
							<?php echo esc_html__( 'Load the bundled stylesheet', 'rapls-sitemap' ); ?>
						</label>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-cache"><?php echo esc_html__( 'Cache lifetime', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" step="60"
						id="rapls-sitemap-cache"
						name="<?php echo esc_attr( $name . '[cache_ttl]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>" />
					<p class="description">
						<?php echo esc_html__( 'Seconds. The cache is cleared automatically when content changes; 0 disables it.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * The Advanced tab.
	 *
	 * Everything here is optional, which is why it is a tab rather than more of
	 * the screen above: a long form reads as a list of decisions to make. Each
	 * panel opens itself when it holds something other than a default, so a
	 * setting somebody deliberately made is never behind a closed panel.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function render_more( string $name, array $settings ): void {
		?>
		<p class="description rapls-pane__lead">
			<?php echo esc_html__( 'Everything here is optional. A panel opens itself when it holds something other than a default, so all of them closed means nothing on this tab has been changed.', 'rapls-sitemap' ); ?>
		</p>
		<?php
		self::open_section(
			'composition',
			__( 'Composition', 'rapls-sitemap' ),
			__( 'several lists in one placement, or one branch of the site', 'rapls-sitemap' ),
			self::configured( $settings, array( 'sections', 'child_of' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Sections in one sitemap', 'rapls-sitemap' ); ?></th>
				<td>
					<?php $this->section_checkboxes( $name, $settings ); ?>
					<p class="description">
						<?php echo esc_html__( 'Tick nothing for an ordinary sitemap built from the single source chosen under "What to list". Tick two or more and one placement lists each of them in turn, under its own heading — which is the shape most sitemap pages have.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'The number sets the output order — the lowest is listed first. The other settings apply inside every section that lists content; the author and archive sections take the exclusions, the cap and the design, but not the ordering or grouping that belong to entries they do not list.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-child-of"><?php echo esc_html__( 'Limit to one branch', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" step="1" id="rapls-sitemap-child-of"
						name="<?php echo esc_attr( $name . '[child_of]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['child_of'] ); ?>" />
					<p class="description">
						<?php echo esc_html__( 'A page ID. Only the pages filed under it are listed, and the page itself is not. 0 lists the whole site. Post types with no hierarchy are left out entirely, since a page branch is not a scope they have.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: 1: an example shortcode. 2: another example shortcode. */
							esc_html__( 'In a shortcode this also takes %1$s, for a section landing page listing its own children without naming an ID that differs between staging and live, and %2$s, which lists the page\'s siblings instead — the same template on every page of a section then shows the reader where they are inside it.', 'rapls-sitemap' ),
							'<code>child_of="current"</code>',
							'<code>child_of="parent"</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();

		self::open_section(
			'filters',
			__( 'What to leave out', 'rapls-sitemap' ),
			__( 'a publication window, people, protected and noindexed entries', 'rapls-sitemap' ),
			self::configured( $settings, array( 'date_after', 'date_before', 'exclude_users', 'author_roles', 'exclude_current', 'exclude_protected', 'exclude_noindex', 'exclude_types', 'exclude_tax' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Published between', 'rapls-sitemap' ); ?></th>
				<td>
					<input type="text" class="regular-text" style="width:9em"
						name="<?php echo esc_attr( $name . '[date_after]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['date_after'] ); ?>"
						placeholder="2026-04-01" />
					<span aria-hidden="true">–</span>
					<input type="text" class="regular-text" style="width:9em"
						name="<?php echo esc_attr( $name . '[date_before]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['date_before'] ); ?>"
						placeholder="2027-03-31" />
					<p class="description">
						<?php echo esc_html__( 'Both ends are included, and either can stand on its own. Leave both empty to list everything. A school or a council listing one year at a time is what this is for.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'YYYY-MM-DD, YYYY-MM or YYYY. Anything else is read as no limit rather than as a date nobody meant — a typo widens the listing instead of emptying it.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-exclude-users"><?php echo esc_html__( 'Exclude user IDs', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text"
						id="rapls-sitemap-exclude-users"
						name="<?php echo esc_attr( $name . '[exclude_users]' ); ?>"
						value="<?php echo esc_attr( implode( ', ', (array) $settings['exclude_users'] ) ); ?>" />
					<p class="description">
						<?php echo esc_html__( 'The author listing only. The account WordPress was installed with, or the agency that built the site, is on the user list without being anyone a reader should be sent to.', 'rapls-sitemap' ); ?>
					</p>
					<?php $this->role_checkboxes( $name, $settings ); ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Also leave out', 'rapls-sitemap' ); ?></th>
				<td>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[exclude_current]' ); ?>"
							<?php checked( ! empty( $settings['exclude_current'] ) ); ?> />
						<?php echo esc_html__( 'The page holding the sitemap, from its own list', 'rapls-sitemap' ); ?>
					</label>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[exclude_protected]' ); ?>"
							<?php checked( ! empty( $settings['exclude_protected'] ) ); ?> />
						<?php echo esc_html__( 'Password-protected entries', 'rapls-sitemap' ); ?>
					</label>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[exclude_noindex]' ); ?>"
							<?php checked( ! empty( $settings['exclude_noindex'] ) ); ?> />
						<?php echo esc_html__( 'Entries individually marked noindex', 'rapls-sitemap' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'Yoast SEO, Rank Math, SEO SIMPLE PACK, SEOPress, The SEO Framework, All in One SEO and the Cocoon theme are read directly. WordPress records nothing about noindex itself, so anything else needs the rapls_sitemap/is_noindex filter. Reading the setting costs one extra query per render, and a second one where All in One SEO is active — it keeps this in a table of its own rather than in post meta.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'What is read is the setting on the entry itself. A default that applies to a whole post type, taxonomy or archive is not: those listings appear only because you chose them on this screen, and an SEO plugin\'s default — Yoast noindexes date archives out of the box — would otherwise empty a list you asked for.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'Categories, tags and authors are read too. A category is only dropped where the category itself is what is listed — where entries are grouped under category headings, dropping a heading would take indexable entries with it, so switch off "Link section and category headings" instead. Those two questions have filters of their own: rapls_sitemap/is_term_noindex and rapls_sitemap/is_user_noindex.', 'rapls-sitemap' ); ?>
					</p>

					<?php $this->exclusion_checkboxes( $name, $settings ); ?>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();

		self::open_section(
			'grouping',
			__( 'Grouping by category', 'rapls-sitemap' ),
			__( 'category headings, nesting, or the categories on their own', 'rapls-sitemap' ),
			self::configured( $settings, array( 'group_by_term', 'nest_terms', 'duplicate_in_terms', 'taxonomy', 'term_mode' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Categories', 'rapls-sitemap' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[group_by_term]' ); ?>"
							<?php checked( ! empty( $settings['group_by_term'] ) ); ?> />
						<?php echo esc_html__( 'Group posts under their category headings', 'rapls-sitemap' ); ?>
					</label>
					<p>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $name . '[nest_terms]' ); ?>"
								<?php checked( ! empty( $settings['nest_terms'] ) ); ?> />
							<?php echo esc_html__( 'Nest child categories inside their parents', 'rapls-sitemap' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $name . '[duplicate_in_terms]' ); ?>"
								<?php checked( ! empty( $settings['duplicate_in_terms'] ) ); ?> />
							<?php echo esc_html__( 'List a post under every category it belongs to', 'rapls-sitemap' ); ?>
						</label>
						<span class="description">
							<?php echo esc_html__( 'Off lists it once, under the first category that claims it.', 'rapls-sitemap' ); ?>
						</span>
					</p>
					<p>
						<label>
							<?php echo esc_html__( 'Group by', 'rapls-sitemap' ); ?>
							<select name="<?php echo esc_attr( $name . '[taxonomy]' ); ?>">
								<option value="" <?php selected( $settings['taxonomy'], '' ); ?>>
									<?php echo esc_html__( 'Pick automatically', 'rapls-sitemap' ); ?>
								</option>
								<?php foreach ( self::available_taxonomies() as $taxonomy ) : ?>
									<option value="<?php echo esc_attr( $taxonomy->name ); ?>" <?php selected( $settings['taxonomy'], $taxonomy->name ); ?>>
										<?php echo esc_html( $taxonomy->labels->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span class="description">
							<?php echo esc_html__( 'Automatic picks the first hierarchical taxonomy, which is Categories. A flat one such as Tags has to be chosen here.', 'rapls-sitemap' ); ?>
						</span>
					</p>
					<fieldset style="margin-top:0.5em">
						<label style="display:block">
							<input type="radio" value="posts"
								name="<?php echo esc_attr( $name . '[term_mode]' ); ?>"
								<?php checked( 'posts', $settings['term_mode'] ); ?> />
							<?php echo esc_html__( 'List the posts under each category', 'rapls-sitemap' ); ?>
						</label>
						<label style="display:block">
							<input type="radio" value="terms_only"
								name="<?php echo esc_attr( $name . '[term_mode]' ); ?>"
								<?php checked( 'terms_only', $settings['term_mode'] ); ?> />
							<?php echo esc_html__( 'List the categories only', 'rapls-sitemap' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();

		self::open_section(
			'display',
			__( 'What each entry shows', 'rapls-sitemap' ),
			__( 'dates, excerpts, counts, headings, nofollow', 'rapls-sitemap' ),
			self::configured( $settings, array( 'show_date', 'date_format', 'show_excerpt', 'excerpt_length', 'show_count', 'section_headings', 'list_type', 'link_headings', 'link_parents', 'heading_level', 'nofollow' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Show with each entry', 'rapls-sitemap' ); ?></th>
				<td>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[show_date]' ); ?>"
							<?php checked( ! empty( $settings['show_date'] ) ); ?> />
						<?php echo esc_html__( 'Published date', 'rapls-sitemap' ); ?>
						<input type="text" style="width:9em;margin-left:0.5em"
							name="<?php echo esc_attr( $name . '[date_format]' ); ?>"
							value="<?php echo esc_attr( (string) $settings['date_format'] ); ?>"
							placeholder="<?php echo esc_attr( (string) get_option( 'date_format' ) ); ?>"
							aria-label="<?php echo esc_attr__( 'Date format', 'rapls-sitemap' ); ?>" />
						<span class="description"><?php echo esc_html__( 'Empty uses the site’s own date format.', 'rapls-sitemap' ); ?></span>
					</label>

					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[show_excerpt]' ); ?>"
							<?php checked( ! empty( $settings['show_excerpt'] ) ); ?> />
						<?php echo esc_html__( 'Excerpt', 'rapls-sitemap' ); ?>
						<input type="number" min="1" max="200" style="width:6em;margin-left:0.5em"
							name="<?php echo esc_attr( $name . '[excerpt_length]' ); ?>"
							value="<?php echo esc_attr( (string) $settings['excerpt_length'] ); ?>"
							aria-label="<?php echo esc_attr__( 'Excerpt length in words', 'rapls-sitemap' ); ?>" />
						<span class="description"><?php echo esc_html__( 'words', 'rapls-sitemap' ); ?></span>
					</label>

					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[show_count]' ); ?>"
							<?php checked( ! empty( $settings['show_count'] ) ); ?> />
						<?php echo esc_html__( 'Entry count beside each category', 'rapls-sitemap' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Structure', 'rapls-sitemap' ); ?></th>
				<td>
					<label style="display:block">
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[section_headings]' ); ?>"
							<?php checked( ! empty( $settings['section_headings'] ) ); ?> />
						<?php echo esc_html__( 'Put a heading above each list', 'rapls-sitemap' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'Applies both to the post types listed and to the sections composed. Only when more than one is listed — a single list needs no label to tell it apart.', 'rapls-sitemap' ); ?>
					</p>
					<p>
						<label>
							<?php echo esc_html__( 'List element', 'rapls-sitemap' ); ?>
							<select name="<?php echo esc_attr( $name . '[list_type]' ); ?>">
								<option value="ul" <?php selected( $settings['list_type'], 'ul' ); ?>><?php echo esc_html__( 'Unordered (ul)', 'rapls-sitemap' ); ?></option>
								<option value="ol" <?php selected( $settings['list_type'], 'ol' ); ?>><?php echo esc_html__( 'Ordered (ol)', 'rapls-sitemap' ); ?></option>
							</select>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $name . '[link_headings]' ); ?>"
								<?php checked( ! empty( $settings['link_headings'] ) ); ?> />
							<?php echo esc_html__( 'Link section and category headings to their archives', 'rapls-sitemap' ); ?>
						</label>
						<span class="description">
							<?php echo esc_html__( 'Off leaves the text in place without linking it, for archives that are thin or noindexed.', 'rapls-sitemap' ); ?>
						</span>
					</p>
					<p>
						<label>
							<input type="checkbox" value="1"
								name="<?php echo esc_attr( $name . '[link_parents]' ); ?>"
								<?php checked( ! empty( $settings['link_parents'] ) ); ?> />
							<?php echo esc_html__( 'Link entries that have entries under them', 'rapls-sitemap' ); ?>
						</label>
						<span class="description">
							<?php echo esc_html__( 'Off prints them as headings instead. For a section page that exists only to hold its children, the link goes to a page nobody wants to read.', 'rapls-sitemap' ); ?>
						</span>
					</p>
					<p>
						<label>
							<?php echo esc_html__( 'Headings', 'rapls-sitemap' ); ?>
							<select name="<?php echo esc_attr( $name . '[heading_level]' ); ?>">
								<option value="" <?php selected( $settings['heading_level'], '' ); ?>>
									<?php echo esc_html__( 'Plain text', 'rapls-sitemap' ); ?>
								</option>
								<?php foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6' ) as $level ) : ?>
									<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $settings['heading_level'], $level ); ?>>
										<?php echo esc_html( strtoupper( $level ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="description">
						<?php echo esc_html__( 'Section and category labels can be real headings, which is how a screen reader user moves through the page. Pick the level that fits under whatever heading the page already has above the sitemap — a wrong level is a broken outline, so this stays plain text until you choose.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Links', 'rapls-sitemap' ); ?></th>
				<td>
					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[nofollow]' ); ?>"
							<?php checked( ! empty( $settings['nofollow'] ) ); ?> />
						<?php echo esc_html__( 'Add rel="nofollow" to every link', 'rapls-sitemap' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();

		$this->render_ordering( $name, $settings );
		$this->render_design( $name, $settings );
		$this->render_custom_css( $name, $settings );

		self::open_section(
			'migration',
			__( 'Coming from another plugin', 'rapls-sitemap' ),
			__( 'answer to WP Sitemap Page or PS Auto Sitemap as well', 'rapls-sitemap' ),
			self::configured( $settings, array( 'legacy_shortcode', 'legacy_marker' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Migration', 'rapls-sitemap' ); ?></th>
				<td>
					<p class="description" style="margin-bottom:0.75em">
						<?php echo esc_html__( 'Both are off until you switch them on. Turn on the one matching the plugin you are coming from, and the sitemap page keeps working with nothing to edit.', 'rapls-sitemap' ); ?>
					</p>

					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[legacy_shortcode]' ); ?>"
							<?php checked( ! empty( $settings['legacy_shortcode'] ) ); ?> />
						<?php echo esc_html__( 'Also render where a WP Sitemap Page shortcode appears', 'rapls-sitemap' ); ?>
					</label>
					<p class="description" style="margin-bottom:0.75em">
						<?php
						printf(
							/* translators: %s: the shortcode used by WP Sitemap Page. */
							esc_html__( 'Answers to %s, including its only="..." attribute. Ignored while WP Sitemap Page itself is active.', 'rapls-sitemap' ),
							'<code>[wp_sitemap_page]</code>'
						);
						?>
					</p>

					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr( $name . '[legacy_marker]' ); ?>"
							<?php checked( ! empty( $settings['legacy_marker'] ) ); ?> />
						<?php echo esc_html__( 'Also render where a PS Auto Sitemap comment marker appears', 'rapls-sitemap' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: the HTML comment used by PS Auto Sitemap. */
							esc_html__( 'Answers to %s, which that plugin left in the page content.', 'rapls-sitemap' ),
							'<code>&lt;!-- SITEMAP CONTENT REPLACE POINT --&gt;</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();
	}

	/**
	 * Has anything in this group of settings been changed from its default?
	 *
	 * Decides whether a panel opens itself. The comparison errs towards open:
	 * two arrays holding the same values in a different order count as changed,
	 * which opens a panel that did not need it rather than hiding a setting
	 * somebody deliberately made — and a hidden setting is the one failure a
	 * collapsing settings screen must not have.
	 *
	 * @param array<string,mixed> $settings Effective settings.
	 * @param string[]            $keys     The keys that panel holds.
	 * @return bool
	 */
	private static function configured( array $settings, array $keys ): bool {
		$defaults = Settings::defaults();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $defaults ) && $settings[ $key ] !== $defaults[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The ordering section.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function render_ordering( string $name, array $settings ): void {
		self::open_section(
			'order',
			__( 'Order', 'rapls-sitemap' ),
			__( 'how entries and categories are sorted, the entry cap, custom fields', 'rapls-sitemap' ),
			self::configured( $settings, array( 'orderby', 'order', 'term_orderby', 'term_order', 'max_entries', 'max_per_term', 'offset', 'sort_meta_key' ) )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rapls-sitemap-term-orderby"><?php echo esc_html__( 'Sort category headings by', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<select id="rapls-sitemap-term-orderby" name="<?php echo esc_attr( $name . '[term_orderby]' ); ?>">
						<?php foreach ( self::term_orderings() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['term_orderby'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<select name="<?php echo esc_attr( $name . '[term_order]' ); ?>">
						<option value="ASC" <?php selected( $settings['term_order'], 'ASC' ); ?>><?php echo esc_html__( 'Ascending (A to Z, fewest first)', 'rapls-sitemap' ); ?></option>
						<option value="DESC" <?php selected( $settings['term_order'], 'DESC' ); ?>><?php echo esc_html__( 'Descending (Z to A, most first)', 'rapls-sitemap' ); ?></option>
					</select>

					<p class="description">
						<?php echo esc_html__( 'A category order is often a decision rather than an alphabet — departments, regions, product lines. "The order set by hand" needs a plugin that provides one; without it WordPress falls back to the category ID.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( '"Number of entries" is the count WordPress keeps for each category. Where exclusions or the noindex filter remove entries, it can differ from the number shown beside the category, which counts what was actually listed.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-orderby"><?php echo esc_html__( 'Sort entries by', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<select id="rapls-sitemap-orderby" name="<?php echo esc_attr( $name . '[orderby]' ); ?>">
						<?php foreach ( self::orderings() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['orderby'], $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<select name="<?php echo esc_attr( $name . '[order]' ); ?>">
						<option value="DESC" <?php selected( $settings['order'], 'DESC' ); ?>><?php echo esc_html__( 'Descending (newest, Z to A)', 'rapls-sitemap' ); ?></option>
						<option value="ASC" <?php selected( $settings['order'], 'ASC' ); ?>><?php echo esc_html__( 'Ascending (oldest, A to Z)', 'rapls-sitemap' ); ?></option>
					</select>

					<p class="description">
						<?php echo esc_html__( 'Titles sort by the database collation, which is correct for kana and Latin but not for kanji — nothing records that 大阪 reads おおさか. For a true kana order, store the reading in a custom field and sort by that instead.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-max"><?php echo esc_html__( 'Entries per list', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="number" min="0" step="100" id="rapls-sitemap-max"
						name="<?php echo esc_attr( $name . '[max_entries]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['max_entries'] ); ?>" />
					<label style="margin-left:1.5em">
						<?php echo esc_html__( 'Skip the first', 'rapls-sitemap' ); ?>
						<input type="number" min="0" step="1" style="width:6em"
							name="<?php echo esc_attr( $name . '[offset]' ); ?>"
							value="<?php echo esc_attr( (string) $settings['offset'] ); ?>" />
					</label>
					<label style="margin-left:1.5em">
						<?php echo esc_html__( 'Per category', 'rapls-sitemap' ); ?>
						<input type="number" min="0" step="1" style="width:6em"
							name="<?php echo esc_attr( $name . '[max_per_term]' ); ?>"
							value="<?php echo esc_attr( (string) $settings['max_per_term'] ); ?>" />
					</label>
					<p class="description">
						<?php echo esc_html__( 'A sitemap asks for every entry of every post type at once, which is the query that runs out of memory on a large site. 0 lifts the cap; a list that stops short always says so in the output.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( '"Per category" is a different limit: it caps how far a reader has to scroll past one category before reaching the next, rather than how much is fetched.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'The cap applies to all three queries — entries, categories and authors. "Skip the first" applies to the entries only: an offset into a list of categories or of people answers no question anyone has asked.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rapls-sitemap-sort-meta"><?php echo esc_html__( 'Custom field to sort by', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<input type="text" class="regular-text" id="rapls-sitemap-sort-meta"
						name="<?php echo esc_attr( $name . '[sort_meta_key]' ); ?>"
						value="<?php echo esc_attr( (string) $settings['sort_meta_key'] ); ?>"
						placeholder="yomi" />
					<p class="description"><?php echo esc_html__( 'Used only when the sort above is set to "Custom field".', 'rapls-sitemap' ); ?></p>

					<?php
					self::open_section(
						'meta-help',
						__( 'What is a custom field, and how do I fill one in?', 'rapls-sitemap' ),
						'',
						false,
						'rapls-section--help'
					);
					?>
					<div class="rapls-help">
						<p>
							<?php echo esc_html__( 'A custom field is an extra named value stored alongside a post — WordPress calls the name a "key" and the contents a "value". Nothing shows it to readers; it is there for code like this to read.', 'rapls-sitemap' ); ?>
						</p>
						<p>
							<?php echo esc_html__( 'This is how a true kana ordering is possible. Store the reading of each title in one field, name that field here, and entries sort by the reading instead of by the characters.', 'rapls-sitemap' ); ?>
						</p>

						<h4><?php echo esc_html__( 'Setting one by hand', 'rapls-sitemap' ); ?></h4>
						<ol>
							<li><?php echo esc_html__( 'Open a post or page for editing.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'In the block editor, open the options menu (three dots, top right) → Preferences → Panels, and switch on "Custom fields". The page reloads with a Custom Fields box below the content.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'Choose "Enter new", type the field name, and put the reading in the value box.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'Update the post, then enter the same field name in the box above.', 'rapls-sitemap' ); ?></li>
						</ol>

						<table class="widefat striped rapls-help__table">
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Post title', 'rapls-sitemap' ); ?></th>
									<th><?php echo esc_html__( 'Field name', 'rapls-sitemap' ); ?></th>
									<th><?php echo esc_html__( 'Value', 'rapls-sitemap' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr><td>大阪の話</td><td><code>yomi</code></td><td>おおさかのはなし</td></tr>
								<tr><td>会社概要</td><td><code>yomi</code></td><td>かいしゃがいよう</td></tr>
								<tr><td>アクセス</td><td><code>yomi</code></td><td>あくせす</td></tr>
							</tbody>
						</table>

						<h4><?php echo esc_html__( 'Notes', 'rapls-sitemap' ); ?></h4>
						<ul class="ul-disc">
							<li><?php echo esc_html__( 'The field name is yours to choose. "yomi" is only a suggestion; use whatever an existing plugin already fills in if you have one.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'Values sort as text, so keep them consistent — all hiragana, or all katakana, not a mix.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'Posts with the field empty or missing are grouped together at one end of the list rather than dropped.', 'rapls-sitemap' ); ?></li>
							<li><?php echo esc_html__( 'The same mechanism works for any ordering you want to control by hand — a number field gives you a manual running order, for instance.', 'rapls-sitemap' ); ?></li>
						</ul>
					</div>
					<?php
					self::close_section();
					?>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();
	}

	/**
	 * The design-token section.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function render_design( string $name, array $settings ): void {
		$style = Design::merge( $settings['style'] );
		$field = $name . '[style]';

		// Opens itself when there is something in here to see, so a site that
		// has configured tokens never hides them behind a collapsed panel.
		self::open_section(
			'design',
			__( 'Typography and bullets', 'rapls-sitemap' ),
			__( 'font size, line height, link colour, underline, bullets', 'rapls-sitemap' ),
			Design::is_configured( $style )
		);

		?>
		<p class="description">
			<?php echo esc_html__( 'These sit on top of the design chosen on the Basic tab. Leave a box empty to keep whatever the design already does.', 'rapls-sitemap' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Text', 'rapls-sitemap' ); ?></th>
				<td>
					<?php
					self::length_field( $field, 'font_size', __( 'Font size', 'rapls-sitemap' ), $style );
					self::length_field( $field, 'line_height', __( 'Line height', 'rapls-sitemap' ), $style, true );
					self::length_field( $field, 'indent', __( 'Indent per level', 'rapls-sitemap' ), $style );
					?>
					<p class="description"><?php echo esc_html__( 'Leave a number blank to keep what the design does.', 'rapls-sitemap' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Columns', 'rapls-sitemap' ); ?></th>
				<td>
					<span class="rapls-field">
						<label class="rapls-field__label" for="rapls-sitemap-columns"><?php echo esc_html__( 'Number of columns', 'rapls-sitemap' ); ?></label>
						<input type="number" min="1" max="6" step="1" id="rapls-sitemap-columns"
							name="<?php echo esc_attr( $field . '[columns]' ); ?>"
							value="<?php echo esc_attr( (string) $style['columns'] ); ?>" />
					</span>
					<?php self::length_field( $field, 'column_gap', __( 'Gap between columns', 'rapls-sitemap' ), $style ); ?>
					<p class="description">
						<?php echo esc_html__( 'Only the top level is split; a category keeps its own entries with it. Empty leaves the design alone, and one column is a real answer on a design that would otherwise flow into several.', 'rapls-sitemap' ); ?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'A fixed number of columns does not narrow on a phone. Where that matters, the "Columns" design flows into as many as fit instead.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Links', 'rapls-sitemap' ); ?></th>
				<td>
					<?php
					self::color_field( $field, 'link_color', __( 'Link colour', 'rapls-sitemap' ), $style );
					self::color_field( $field, 'link_hover_color', __( 'Hover colour', 'rapls-sitemap' ), $style );
					?>
					<p>
						<label>
							<?php echo esc_html__( 'Underline', 'rapls-sitemap' ); ?>
							<select name="<?php echo esc_attr( $field . '[underline]' ); ?>">
								<?php foreach ( self::underlines() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $style['underline'], $slug ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</p>
					<p class="description"><?php echo esc_html__( 'Colours accept #hex, rgb(), hsl(), a CSS variable, or a colour name.', 'rapls-sitemap' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Top-level items', 'rapls-sitemap' ); ?></th>
				<td>
					<?php
					self::length_field( $field, 'parent_font_size', __( 'Font size', 'rapls-sitemap' ), $style );
					self::color_field( $field, 'parent_color', __( 'Colour', 'rapls-sitemap' ), $style );
					self::length_field( $field, 'parent_spacing', __( 'Space above', 'rapls-sitemap' ), $style );
					self::weight_field( $field, 'parent_weight', __( 'Weight', 'rapls-sitemap' ), $style );
					?>
					<p class="description"><?php echo esc_html__( 'Categories, years, and the front-page link.', 'rapls-sitemap' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Nested items', 'rapls-sitemap' ); ?></th>
				<td>
					<?php
					self::length_field( $field, 'child_font_size', __( 'Font size', 'rapls-sitemap' ), $style );
					self::color_field( $field, 'child_color', __( 'Colour', 'rapls-sitemap' ), $style );
					self::weight_field( $field, 'child_weight', __( 'Weight', 'rapls-sitemap' ), $style );
					?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php echo esc_html__( 'Bullets', 'rapls-sitemap' ); ?></th>
				<td>
					<?php
					self::marker_fields( $field, 'marker', __( 'Top level', 'rapls-sitemap' ), $style );
					self::marker_fields( $field, 'child_marker', __( 'Nested', 'rapls-sitemap' ), $style );
					self::color_field( $field, 'marker_color', __( 'Bullet colour', 'rapls-sitemap' ), $style );
					?>
					<p class="description">
						<?php echo esc_html__( 'Choose "Emoji" and type one, or choose "Icon" and paste the class from an icon library your theme already loads — for example fa-solid fa-angle-right for Font Awesome. This plugin bundles no icon font; if nothing loads it, no icon shows and the sitemap is otherwise unaffected.', 'rapls-sitemap' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();
	}

	/**
	 * The custom-CSS section.
	 *
	 * Named for what it holds rather than "advanced", which is now the tab it
	 * sits in — every panel in there is advanced, so one of them carrying the
	 * word says nothing.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function render_custom_css( string $name, array $settings ): void {
		self::open_section(
			'custom-css',
			__( 'Custom CSS', 'rapls-sitemap' ),
			__( 'style rules of your own, printed after everything else', 'rapls-sitemap' ),
			'' !== trim( (string) $settings['custom_css'] )
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rapls-sitemap-css"><?php echo esc_html__( 'Additional CSS', 'rapls-sitemap' ); ?></label>
				</th>
				<td>
					<?php if ( ! Settings::can_edit_css() ) : ?>
						<p class="description">
							<?php echo esc_html__( 'Editing CSS needs the unfiltered_html capability, which a network reserves for super administrators. Any CSS already saved keeps working; it is only shown here to those who may change it.', 'rapls-sitemap' ); ?>
						</p>
					<?php else : ?>
					<textarea id="rapls-sitemap-css" class="large-text code" rows="10" spellcheck="false"
						name="<?php echo esc_attr( $name . '[custom_css]' ); ?>"><?php echo esc_textarea( (string) $settings['custom_css'] ); ?></textarea>
					<p class="description">
						<?php echo esc_html__( 'Printed with the sitemap, after everything else, so it wins. Scope your rules to .rapls-sitemap to avoid affecting the rest of the page.', 'rapls-sitemap' ); ?>
					</p>

					<?php
					self::open_section( 'css-help', __( 'Which classes can I target?', 'rapls-sitemap' ), '', false, 'rapls-section--help' );
					self::render_css_reference();
					self::close_section();
					?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
		self::close_section();
	}

	/**
	 * The class reference shown under the Additional CSS box.
	 *
	 * Worth spelling out in the admin rather than only in the readme: someone
	 * writing CSS here has the markup in front of them but no way to see its
	 * class names without opening developer tools.
	 */
	private static function render_css_reference(): void {
		$rows = array(
			array( '.rapls-sitemap', __( 'The wrapper around everything. Also carries the design class, e.g. .rapls-sitemap--card.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__list', __( 'Every list. Also carries its depth: --depth-0 is the top level, --depth-1 the first nesting, and so on.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item', __( 'Every row.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--post', __( 'A post or page entry.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--term', __( 'A category or tag heading.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--home', __( 'The link to the front page.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--author', __( 'An author entry.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--archive', __( 'A year heading in the date archives.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--archive-month', __( 'A month entry under that year.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__item--has-children', __( 'Any row that has a nested list under it.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__link', __( 'The link itself.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__label', __( 'Text for a row with no link — a heading whose archive is switched off.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__icon', __( 'The icon element, when the bullet is set to an icon class.', 'rapls-sitemap' ) ),
			array( '.rapls-sitemap__empty', __( 'The message shown when there is nothing to list.', 'rapls-sitemap' ) ),
		);

		?>
		<div class="rapls-help">
			<p><?php echo esc_html__( 'The markup is the same for every design, so a rule written against these classes keeps working if you change the design later.', 'rapls-sitemap' ); ?></p>

			<pre class="rapls-help__markup"><code>&lt;nav class="rapls-sitemap rapls-sitemap--simple"&gt;
  &lt;ul class="rapls-sitemap__list rapls-sitemap__list--depth-0"&gt;
    &lt;li class="rapls-sitemap__item rapls-sitemap__item--term rapls-sitemap__item--has-children"&gt;
      &lt;a class="rapls-sitemap__link" href="…"&gt;<?php echo esc_html__( 'News', 'rapls-sitemap' ); ?>&lt;/a&gt;
      &lt;ul class="rapls-sitemap__list rapls-sitemap__list--depth-1"&gt;
        &lt;li class="rapls-sitemap__item rapls-sitemap__item--post"&gt;
          &lt;a class="rapls-sitemap__link" href="…"&gt;<?php echo esc_html__( 'A post', 'rapls-sitemap' ); ?>&lt;/a&gt;
        &lt;/li&gt;
      &lt;/ul&gt;
    &lt;/li&gt;
  &lt;/ul&gt;
&lt;/nav&gt;</code></pre>

			<table class="widefat striped rapls-help__table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Class', 'rapls-sitemap' ); ?></th>
						<th><?php echo esc_html__( 'What it is', 'rapls-sitemap' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row[0] ); ?></code></td>
							<td><?php echo esc_html( $row[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h4><?php echo esc_html__( 'Examples', 'rapls-sitemap' ); ?></h4>
			<pre class="rapls-help__markup"><code><?php
			echo esc_html(
				"/* " . __( 'Make category headings bigger', 'rapls-sitemap' ) . " */\n"
				. ".rapls-sitemap__item--term > .rapls-sitemap__link { font-size: 1.3em; }\n\n"
				. "/* " . __( 'Two columns on wide screens only', 'rapls-sitemap' ) . " */\n"
				. "@media (min-width: 48em) {\n"
				. "  .rapls-sitemap__list--depth-0 { columns: 2; }\n"
				. "}\n\n"
				. "/* " . __( 'Hide the second level and deeper', 'rapls-sitemap' ) . " */\n"
				. ".rapls-sitemap__list--depth-2 { display: none; }"
			);
			?></code></pre>

			<p class="description">
				<?php echo esc_html__( 'A rule here beats the design and the settings above. If a rule seems to do nothing, the theme is probably winning on specificity — add .rapls-sitemap in front of your selector to raise it.', 'rapls-sitemap' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Open a collapsible section.
	 *
	 * `<details>` rather than a nameless checkbox and a sibling selector. Both
	 * collapse without JavaScript, but a checkbox in a settings form means "on
	 * or off" to everybody who has ever used one, and eight of them down the
	 * Advanced tab read as eight more things to decide rather than as eight
	 * panels to open. `<summary>` is a disclosure control and says so — to the
	 * eye through the chevron, to a screen reader through its own role, and to
	 * the keyboard through Enter and Space, which the checkbox only got by
	 * accident of being a checkbox.
	 *
	 * A closed `<details>` still submits every field inside it. The elements
	 * are in the document and are not disabled; only rendering is suppressed.
	 * That is the property the whole screen rests on, so `smoke-admin.php`
	 * asserts it and it is checked in a real install before every release.
	 *
	 * The heading stays a real `h2` inside the summary — `<summary>` takes
	 * heading content, and the panel titles are how a screen-reader user moves
	 * through this screen.
	 *
	 * @param string $id       Unique id fragment.
	 * @param string $title    Section heading.
	 * @param string $hint     Short summary shown beside the heading.
	 * @param bool   $expanded Whether to start open.
	 * @param string $modifier Extra class, e.g. for the quieter help panels.
	 */
	private static function open_section( string $id, string $title, string $hint, bool $expanded, string $modifier = '' ): void {
		// The modifier goes through printf as an argument, not spliced into the
		// format string — a stray `%` in a class name would otherwise be read
		// as a conversion specification.
		printf(
			'<details class="%1$s" id="%2$s"%3$s>'
				. '<summary class="rapls-section__summary">'
				. '<h2 class="rapls-section__title">%4$s'
				. '<span class="rapls-section__hint">%5$s</span></h2>'
				. '</summary>'
				. '<div class="rapls-section__body">',
			esc_attr( trim( 'rapls-section ' . $modifier ) ),
			esc_attr( 'rapls-sitemap-section-' . $id ),
			$expanded ? ' open' : '',
			esc_html( $title ),
			esc_html( $hint )
		);
	}

	/**
	 * Close a collapsible section.
	 */
	private static function close_section(): void {
		echo '</div></details>';
	}

	/**
	 * The PS Auto Sitemap import form.
	 *
	 * Shown only when there is something to import. That plugin's option
	 * survives its deletion, so a site that ran it years ago still holds the
	 * answers its owner already gave — and a button that is not there on the
	 * millions of sites that never ran it costs them nothing.
	 */
	private function render_import(): void {
		if ( ! PsMigration::available() ) {
			return;
		}

		?>
		<hr />
		<h2><?php echo esc_html__( 'Import from PS Auto Sitemap', 'rapls-sitemap' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
			<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
			<p class="description">
				<?php echo esc_html__( 'This site still holds a PS Auto Sitemap configuration. Reading it in fills this screen with the answers you already gave there: which lists to show and in which order, the depth, the categories and posts to leave out, and the nearest design.', 'rapls-sitemap' ); ?>
			</p>
			<p class="description">
				<?php echo esc_html__( 'It overwrites the settings on this screen and nothing else. The old configuration is left where it is, so you can do this again.', 'rapls-sitemap' ); ?>
			</p>
			<?php
			submit_button(
				__( 'Read in the old settings', 'rapls-sitemap' ),
				'secondary',
				'submit',
				true,
				array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Overwrite the settings on this screen with the PS Auto Sitemap ones?', 'rapls-sitemap' ) ) . ');' )
			);
			?>
		</form>
		<?php
	}

	/**
	 * The reset form.
	 *
	 * Its own form, because HTML forbids nesting one inside the settings form.
	 */
	private function render_reset(): void {
		?>
		<hr />
		<h2><?php echo esc_html__( 'Reset', 'rapls-sitemap' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
			<?php wp_nonce_field( self::RESET_ACTION ); ?>
			<p class="description"><?php echo esc_html__( 'Puts every setting on this screen back to its default. Your pages and posts are not touched.', 'rapls-sitemap' ); ?></p>
			<?php
			submit_button(
				__( 'Reset all settings', 'rapls-sitemap' ),
				'delete',
				'submit',
				true,
				array( 'onclick' => 'return confirm(' . wp_json_encode( __( 'Reset every setting to its default?', 'rapls-sitemap' ) ) . ');' )
			);
			?>
		</form>
		<?php
	}

	/**
	 * A number spinner paired with a unit select, for one length token.
	 *
	 * Posts as `<key>_value` and `<key>_unit`; Design::sanitize() recombines
	 * them. Splitting the control is what makes the arrows — and the mouse
	 * wheel, and the up/down keys — work, which a free-text "1.25em" cannot do.
	 *
	 * @param string               $field    Field name prefix.
	 * @param string               $key      Token key.
	 * @param string               $label    Visible label.
	 * @param array<string,string> $style    Current tokens.
	 * @param bool                 $unitless Whether a bare number is meaningful
	 *                                       (line height), which adds a "none"
	 *                                       unit and a finer step.
	 */
	private static function length_field( string $field, string $key, string $label, array $style, bool $unitless = false ): void {
		list( $number, $unit ) = Design::split( $style[ $key ] );

		// An unset length has no unit yet; offer the one people reach for first.
		if ( '' === $unit && ! $unitless ) {
			$unit = 'px';
		}

		$units = self::length_units( $unitless, $unit );
		$id    = 'rapls-sitemap-' . str_replace( '_', '-', $key );

		printf(
			'<span class="rapls-field"><label class="rapls-field__label" for="%1$s">%2$s</label>'
				. '<input type="number" id="%1$s" name="%3$s" value="%4$s" min="0" max="%5$s" step="%6$s" />',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $field . '[' . $key . '_value]' ),
			esc_attr( $number ),
			esc_attr( $unitless ? '10' : '400' ),
			esc_attr( $unitless ? '0.05' : '0.25' )
		);

		printf( '<select name="%s" aria-label="%s">', esc_attr( $field . '[' . $key . '_unit]' ), esc_attr__( 'Unit', 'rapls-sitemap' ) );
		foreach ( $units as $value => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $unit, $value, false ),
				esc_html( $text )
			);
		}
		echo '</select></span>';
	}

	/**
	 * The unit options for a length control.
	 *
	 * `Design` accepts units this list does not lead with — `vw` and `ch` — and
	 * a filter, an import, or a hand-edited option can set one. A select with
	 * no matching option falls back to its first entry, so the next save would
	 * silently rewrite `50vw` as `50px`. Whatever is currently set is therefore
	 * always among the options, even if nobody would have picked it from a menu.
	 *
	 * Public so `smoke-design.php` can prove that holds for every unit the
	 * validator accepts.
	 *
	 * @param bool   $unitless Whether a bare number is meaningful.
	 * @param string $current  The unit currently stored.
	 * @return array<string,string> Value => label.
	 */
	public static function length_units( bool $unitless, string $current = '' ): array {
		$units = $unitless
			? array( '' => '—', 'px' => 'px', 'em' => 'em', 'rem' => 'rem', '%' => '%' )
			: array( 'px' => 'px', 'em' => 'em', 'rem' => 'rem', '%' => '%', 'pt' => 'pt' );

		if ( ! isset( $units[ $current ] ) ) {
			$units[ $current ] = $current;
		}

		return $units;
	}

	/**
	 * A text box paired with a native colour picker.
	 *
	 * The text box stays authoritative because this plugin accepts more than a
	 * hex value — `currentColor`, a colour name, and `var(--wp--preset--…)` are
	 * all legal here, and none of them can be expressed in a colour input. The
	 * swatch is a convenience that writes into the text box; it carries no
	 * `name`, so it never posts and can never be the thing that gets saved.
	 *
	 * @param string               $field Field name prefix.
	 * @param string               $key   Token key.
	 * @param string               $label Visible label.
	 * @param array<string,string> $style Current tokens.
	 */
	private static function color_field( string $field, string $key, string $label, array $style ): void {
		$id = 'rapls-sitemap-' . str_replace( '_', '-', $key );

		printf(
			'<span class="rapls-field rapls-field--color">'
				. '<label class="rapls-field__label" for="%1$s">%2$s</label>'
				. '<input type="text" class="rapls-field__color" id="%1$s" name="%3$s" value="%4$s" placeholder="#0073aa" />'
				. '<input type="color" class="rapls-field__swatch" value="%5$s" data-default="%6$s" aria-label="%7$s" />'
				. '<button type="button" class="button-link rapls-field__clear" aria-label="%8$s" title="%8$s">&times;</button>'
				. '</span>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $field . '[' . $key . ']' ),
			esc_attr( $style[ $key ] ),
			esc_attr( self::hex_or_default( $style[ $key ] ) ),
			esc_attr( self::SWATCH_DEFAULT ),
			esc_attr__( 'Pick a colour', 'rapls-sitemap' ),
			esc_attr__( 'Clear the colour', 'rapls-sitemap' )
		);
	}

	/**
	 * A six-digit hex for the swatch to start on.
	 *
	 * A colour input accepts nothing else, so anything expressive — a keyword,
	 * a CSS variable — falls back to a neutral rather than being mangled into
	 * one. The text box still shows the real value.
	 *
	 * @param string $value Stored colour.
	 * @return string
	 */
	private static function hex_or_default( string $value ): string {
		$value = trim( $value );

		if ( preg_match( '/^#([0-9A-Fa-f]{3})$/', $value, $m ) ) {
			// Expand #abc, which a colour input will not take.
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}

		return preg_match( '/^#[0-9A-Fa-f]{6}$/', $value ) ? $value : self::SWATCH_DEFAULT;
	}

	/**
	 * A font-weight select.
	 *
	 * @param string               $field Field name prefix.
	 * @param string               $key   Token key.
	 * @param string               $label Visible label.
	 * @param array<string,string> $style Current tokens.
	 */
	private static function weight_field( string $field, string $key, string $label, array $style ): void {
		$choices = array(
			'default' => __( 'Leave to the design', 'rapls-sitemap' ),
			'normal'  => __( 'Normal', 'rapls-sitemap' ),
			'bold'    => __( 'Bold', 'rapls-sitemap' ),
		);

		echo '<label style="display:inline-block;margin:0 1.5em 0.5em 0">' . esc_html( $label ) . ' <select name="' . esc_attr( $field . '[' . $key . ']' ) . '">';
		foreach ( $choices as $slug => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $style[ $key ], $slug, false ),
				esc_html( $text )
			);
		}
		echo '</select></label>';
	}

	/**
	 * The bullet controls for one level: mode, emoji, and icon class.
	 *
	 * @param string               $field Field name prefix.
	 * @param string               $key   Mode key (`marker` or `child_marker`).
	 * @param string               $label Visible label.
	 * @param array<string,string> $style Current tokens.
	 */
	private static function marker_fields( string $field, string $key, string $label, array $style ): void {
		$modes = array(
			'default' => __( 'Leave to the design', 'rapls-sitemap' ),
			'none'    => __( 'None', 'rapls-sitemap' ),
			'disc'    => __( 'Filled circle', 'rapls-sitemap' ),
			'circle'  => __( 'Hollow circle', 'rapls-sitemap' ),
			'square'  => __( 'Square', 'rapls-sitemap' ),
			'emoji'   => __( 'Emoji', 'rapls-sitemap' ),
			'icon'    => __( 'Icon class', 'rapls-sitemap' ),
		);

		echo '<p><label>' . esc_html( $label ) . ' <select name="' . esc_attr( $field . '[' . $key . ']' ) . '">';
		foreach ( $modes as $slug => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $style[ $key ], $slug, false ),
				esc_html( $text )
			);
		}
		echo '</select></label> ';

		// Widths live in admin.css rather than in a `size` attribute: the emoji
		// placeholder is full-width Japanese, which `size` measures in the
		// wrong units and clips.
		//
		// The picker button is added by admin.js and does nothing without it —
		// the field stays typable either way, including by the operating
		// system's own emoji palette.
		printf(
			'<span class="rapls-field rapls-field--emoji">'
				. '<input type="text" class="rapls-field__emoji" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%4$s" />'
				. '</span> ',
			esc_attr( $field . '[' . $key . '_text]' ),
			esc_attr( $style[ $key . '_text' ] ),
			esc_attr__( 'emoji', 'rapls-sitemap' ),
			esc_attr__( 'Emoji bullet', 'rapls-sitemap' )
		);

		printf(
			'<input type="text" class="rapls-field__icon" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%4$s" /></p>',
			esc_attr( $field . '[' . $key . '_icon]' ),
			esc_attr( $style[ $key . '_icon' ] ),
			esc_attr( 'fa-solid fa-angle-right' ),
			esc_attr__( 'Icon class', 'rapls-sitemap' )
		);
	}

	/**
	 * Orderings as slug => translated label.
	 *
	 * @return array<string,string>
	 */
	private static function orderings(): array {
		return array(
			'default'    => __( 'Whatever suits each list', 'rapls-sitemap' ),
			'date'       => __( 'Published date', 'rapls-sitemap' ),
			'modified'   => __( 'Last modified', 'rapls-sitemap' ),
			'title'      => __( 'Title', 'rapls-sitemap' ),
			'menu_order'    => __( 'Page order', 'rapls-sitemap' ),
			'ID'            => __( 'ID', 'rapls-sitemap' ),
			'comment_count' => __( 'Comment count', 'rapls-sitemap' ),
			'rand'          => __( 'Random', 'rapls-sitemap' ),
			'meta'          => __( 'Custom field', 'rapls-sitemap' ),
		);
	}

	/**
	 * Tree sources as slug => translated label.
	 *
	 * @return array<string,string>
	 */
	private static function sources(): array {
		return array(
			'content'  => __( 'Posts and pages', 'rapls-sitemap' ),
			'authors'  => __( 'Authors', 'rapls-sitemap' ),
			'archives' => __( 'Monthly archives', 'rapls-sitemap' ),
			'menu'     => __( 'A navigation menu', 'rapls-sitemap' ),
		);
	}

	/**
	 * The role filter for the author listing.
	 *
	 * Ticking nothing lists everyone who has published, which is the behaviour
	 * this had before the boxes existed and the right default for a blog. A
	 * company site usually wants one or two roles out of five.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function role_checkboxes( string $name, array $settings ): void {
		$roles = self::available_roles();
		if ( array() === $roles ) {
			return;
		}

		$selected = array_map( 'strval', (array) $settings['author_roles'] );

		printf(
			'<p><strong>%s</strong></p><input type="hidden" name="%s" value="" />',
			esc_html__( 'Limit the author listing to these roles', 'rapls-sitemap' ),
			esc_attr( $name . '[author_roles][]' )
		);

		foreach ( $roles as $slug => $label ) {
			printf(
				'<label style="display:inline-block;margin-right:1.5em">'
					. '<input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name . '[author_roles][]' ),
				esc_attr( $slug ),
				checked( in_array( (string) $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Tick nothing to list everyone who has published something.', 'rapls-sitemap' )
		);
	}

	/**
	 * Roles offered on this screen, as slug => translated name.
	 *
	 * @return array<string,string>
	 */
	private static function available_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}

		$roles = wp_roles();

		return isset( $roles->role_names ) ? (array) $roles->role_names : array();
	}

	/**
	 * Navigation menus offered on this screen.
	 *
	 * @return \WP_Term[]
	 */
	private static function available_menus(): array {
		if ( ! function_exists( 'wp_get_nav_menus' ) ) {
			return array();
		}

		return (array) wp_get_nav_menus();
	}

	/**
	 * Term orderings as slug => translated label.
	 *
	 * @return array<string,string>
	 */
	private static function term_orderings(): array {
		return array(
			'name'       => __( 'Name', 'rapls-sitemap' ),
			'count'      => __( 'Number of entries', 'rapls-sitemap' ),
			'slug'       => __( 'Slug', 'rapls-sitemap' ),
			'term_id'    => __( 'Category ID', 'rapls-sitemap' ),
			'term_order' => __( 'The order set by hand', 'rapls-sitemap' ),
		);
	}

	/**
	 * Underline modes as slug => translated label.
	 *
	 * @return array<string,string>
	 */
	private static function underlines(): array {
		return array(
			'default' => __( 'Leave to the theme', 'rapls-sitemap' ),
			'always'  => __( 'Always', 'rapls-sitemap' ),
			'hover'   => __( 'On hover only', 'rapls-sitemap' ),
			'never'   => __( 'Never', 'rapls-sitemap' ),
		);
	}

	/**
	 * Render the post-type checkbox list.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function post_type_checkboxes( string $name, array $settings ): void {
		$selected = array_values( (array) $settings['post_types'] );

		// Unchecking every box must submit an empty list, not omit the key —
		// an omitted key would let sanitize() fall back to the defaults and
		// silently re-enable pages and posts.
		printf(
			'<input type="hidden" name="%s" value="" />',
			esc_attr( $name . '[post_types][]' )
		);

		foreach ( self::available_post_types() as $post_type ) {
			$position = array_search( $post_type->name, $selected, true );
			$position = false === $position ? count( $selected ) : $position;

			printf(
				'<label style="display:block;margin-bottom:0.25em">'
					. '<input type="number" min="0" max="99" step="1" style="width:4em" name="%1$s" value="%2$s" /> '
					. '<input type="checkbox" name="%3$s" value="%4$s" %5$s /> %6$s</label>',
				esc_attr( $name . '[post_types_order][' . $post_type->name . ']' ),
				esc_attr( (string) $position ),
				esc_attr( $name . '[post_types][]' ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $selected, true ), true, false ),
				esc_html( $post_type->labels->singular_name )
			);
		}
	}

	/**
	 * Render the section checkbox list.
	 *
	 * Same control as the post types — a position spinner beside each box —
	 * because it answers the same question, "which list comes first".
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function section_checkboxes( string $name, array $settings ): void {
		$selected = array_values( (array) $settings['sections'] );

		// Unticking every box must submit an empty list rather than omit the
		// key, or sanitize() would fall back to the defaults.
		printf(
			'<input type="hidden" name="%s" value="" />',
			esc_attr( $name . '[sections][]' )
		);

		foreach ( self::available_sections() as $slug => $label ) {
			$position = array_search( $slug, $selected, true );
			$position = false === $position ? count( $selected ) : $position;

			printf(
				'<label style="display:block;margin-bottom:0.25em">'
					. '<input type="number" min="0" max="99" step="1" style="width:4em" name="%1$s" value="%2$s" /> '
					. '<input type="checkbox" name="%3$s" value="%4$s" %5$s /> %6$s</label>',
				esc_attr( $name . '[sections_order][' . $slug . ']' ),
				esc_attr( (string) $position ),
				esc_attr( $name . '[sections][]' ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Sections offered on this screen, as slug => label.
	 *
	 * The post types first, then the taxonomies, then the two listings that are
	 * built from the content rather than being content — which is the order a
	 * sitemap page usually wants, so the default positions need no thought.
	 *
	 * `category` and `post_tag` are reached as taxonomies here rather than
	 * through their SECTIONS aliases; the aliases exist for `[wp_sitemap_page]`,
	 * whose vocabulary they are, and both routes build the same section.
	 *
	 * A slug is offered once, with the label of whatever `TreeBuilder::section()`
	 * would resolve it to — which is why this follows that method's order rather
	 * than overwriting as it goes. A box labelled with a taxonomy that lists a
	 * post type is worse than one option fewer.
	 *
	 * @return array<string,string>
	 */
	private static function available_sections(): array {
		// `author` and `archive` are resolved before anything is looked up, so a
		// post type or taxonomy that happens to use one of those slugs cannot be
		// reached and must not be offered. `category` and `post_tag` are not in
		// this list: their aliases resolve to those very taxonomies, so the
		// taxonomy label is the right one for them.
		$reserved = array();
		foreach ( Settings::SECTIONS as $slug => $overrides ) {
			if ( ! isset( $overrides['taxonomy'] ) ) {
				$reserved[] = $slug;
			}
		}

		$sections = array();

		foreach ( self::available_post_types() as $post_type ) {
			if ( ! in_array( $post_type->name, $reserved, true ) ) {
				$sections[ $post_type->name ] = $post_type->labels->name;
			}
		}

		foreach ( self::available_taxonomies() as $taxonomy ) {
			// A post type of the same name wins in the builder, so it wins here.
			if ( ! isset( $sections[ $taxonomy->name ] ) && ! in_array( $taxonomy->name, $reserved, true ) ) {
				$sections[ $taxonomy->name ] = $taxonomy->labels->name;
			}
		}

		$sections['author']  = __( 'Authors', 'rapls-sitemap' );
		$sections['archive'] = __( 'Archives', 'rapls-sitemap' );

		// One box per menu rather than a single "Navigation menu": a site with a
		// global nav and a footer nav can list both, and the bare `menu` alias
		// could only ever mean whichever one the select above is on.
		foreach ( self::available_menus() as $menu ) {
			$sections[ 'menu:' . $menu->term_id ] = $menu->name;
		}

		return $sections;
	}

	/**
	 * The post-type and taxonomy exclusion lists.
	 *
	 * These overlap the inclusion checkboxes above on purpose. Unchecking a
	 * post type there stops listing it *today*; naming it here keeps it out
	 * even after a plugin registers something new and somebody re-saves the
	 * screen. The same goes for a taxonomy that should never be used for
	 * grouping, whichever post type it is attached to.
	 *
	 * @param string              $name     Option name.
	 * @param array<string,mixed> $settings Effective settings.
	 */
	private function exclusion_checkboxes( string $name, array $settings ): void {
		$types      = self::available_post_types();
		$taxonomies = self::available_taxonomies();

		if ( array() === $types && array() === $taxonomies ) {
			return;
		}

		self::open_section(
			'exclusions',
			__( 'Never list these, whatever else is set', 'rapls-sitemap' ),
			'',
			array() !== (array) $settings['exclude_types'] || array() !== (array) $settings['exclude_tax'],
			'rapls-section--help'
		);

		$excluded_types = (array) $settings['exclude_types'];
		$excluded_tax   = (array) $settings['exclude_tax'];

		echo '<p class="description">' . esc_html__( 'An exclusion here always wins.', 'rapls-sitemap' ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Post types', 'rapls-sitemap' ) . '</strong></p>';
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name . '[exclude_types][]' ) );
		foreach ( $types as $post_type ) {
			printf(
				'<label style="display:inline-block;margin:0 1.5em 0.4em 0"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( $name . '[exclude_types][]' ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $excluded_types, true ), true, false ),
				esc_html( $post_type->labels->singular_name )
			);
		}

		echo '<p><strong>' . esc_html__( 'Taxonomies', 'rapls-sitemap' ) . '</strong></p>';
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name . '[exclude_tax][]' ) );
		foreach ( $taxonomies as $taxonomy ) {
			printf(
				'<label style="display:inline-block;margin:0 1.5em 0.4em 0"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( $name . '[exclude_tax][]' ),
				esc_attr( $taxonomy->name ),
				checked( in_array( $taxonomy->name, $excluded_tax, true ), true, false ),
				esc_html( $taxonomy->labels->singular_name )
			);
		}

		self::close_section();
	}

	/**
	 * Taxonomies offered on this screen.
	 *
	 * @return \WP_Taxonomy[]
	 */
	private static function available_taxonomies(): array {
		// Viewable, not merely public, and asked through the same function the
		// tree asks — see available_post_types() for why the candidate list is
		// unfiltered.
		$taxonomies = array_filter(
			get_taxonomies( array(), 'objects' ),
			static function ( $taxonomy ) {
				return TreeBuilder::is_listable_taxonomy( $taxonomy->name );
			}
		);

		/**
		 * Filters the selectable taxonomies.
		 *
		 * @param \WP_Taxonomy[] $taxonomies Taxonomy objects, keyed by slug.
		 */
		return (array) apply_filters( Hooks::TAXONOMIES, $taxonomies );
	}

	/**
	 * Post types offered on this screen.
	 *
	 * @return \WP_Post_Type[]
	 */
	private static function available_post_types(): array {
		// The candidate list is unfiltered on purpose. Narrowing it to
		// `public => true` first looks like a harmless pre-filter, but the two
		// questions disagree in both directions: `public => true,
		// publicly_queryable => false` is a type whose pages 404, and
		// `public => false, publicly_queryable => true` is a type that works.
		// Pre-filtering therefore hid a type the tree would happily list. The
		// predicate is TreeBuilder's own, so the screen and the renderer cannot
		// come to answer this differently.
		$types = array_filter(
			get_post_types( array(), 'objects' ),
			static function ( $type ) {
				return TreeBuilder::is_listable_post_type( $type->name );
			}
		);
		unset( $types['attachment'] );

		/**
		 * Filters the selectable post types.
		 *
		 * @param \WP_Post_Type[] $types Post type objects, keyed by slug.
		 */
		return (array) apply_filters( Hooks::POST_TYPES, $types );
	}

	/**
	 * Design presets as slug => translated label.
	 *
	 * @return array<string,string>
	 */
	private static function designs(): array {
		$designs = array(
			'none'      => __( 'No style', 'rapls-sitemap' ),
			'simple'    => __( 'Simple', 'rapls-sitemap' ),
			'list'      => __( 'Bulleted list', 'rapls-sitemap' ),
			'compact'   => __( 'Compact', 'rapls-sitemap' ),
			'tree'      => __( 'Document tree', 'rapls-sitemap' ),
			'index'     => __( 'Index', 'rapls-sitemap' ),
			'table'     => __( 'Ruled rows', 'rapls-sitemap' ),
			'columns'   => __( 'Newspaper columns', 'rapls-sitemap' ),
			'outline'   => __( 'Outlined boxes', 'rapls-sitemap' ),
			'numbered'  => __( 'Numbered', 'rapls-sitemap' ),
			'card'      => __( 'Cards', 'rapls-sitemap' ),
			'business'  => __( 'Business', 'rapls-sitemap' ),
			'panel'     => __( 'Panels', 'rapls-sitemap' ),
			'timeline'  => __( 'Timeline', 'rapls-sitemap' ),
			'accordion' => __( 'Accordion', 'rapls-sitemap' ),
			'grid'      => __( 'Grid', 'rapls-sitemap' ),
			'underline' => __( 'Underlined headings', 'rapls-sitemap' ),
			'marker'    => __( 'Highlighter', 'rapls-sitemap' ),
			'checklist' => __( 'Checklist', 'rapls-sitemap' ),
			'label'     => __( 'Sticky notes', 'rapls-sitemap' ),
			'arrow'     => __( 'Arrows', 'rapls-sitemap' ),
			'dots'      => __( 'Dots', 'rapls-sitemap' ),
			'pill'      => __( 'Pills', 'rapls-sitemap' ),
			'ribbon'    => __( 'Ribbons', 'rapls-sitemap' ),
			'magazine'  => __( 'Magazine', 'rapls-sitemap' ),
			'book'      => __( 'Book contents', 'rapls-sitemap' ),
			'neon'      => __( 'Neon', 'rapls-sitemap' ),
			'terminal'  => __( 'Terminal', 'rapls-sitemap' ),
		);

		/**
		 * Filters the design presets.
		 *
		 * @param array $designs slug => label.
		 */
		return (array) apply_filters( Hooks::DESIGNS, $designs );
	}
}
