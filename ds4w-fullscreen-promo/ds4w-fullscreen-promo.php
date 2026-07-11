<?php
/**
 * Plugin Name: Fullscreen Promo Popup (Popup Maker add-on)
 * Description: Auto-opens a locked promotional Popup Maker popup on selected pages and puts the visitor's browser into fullscreen on their first interaction. Built for de-stress4wellness.com.
 * Version:     1.2.0
 * Author:      Anirudha Talmale
 * License:     GPL-2.0-or-later
 * Text Domain: ds4w-fsp
 *
 * Requires Popup Maker (free or Pro).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DS4W_FSP_VERSION', '1.2.0' );
define( 'DS4W_FSP_FILE', __FILE__ );
define( 'DS4W_FSP_URL', plugin_dir_url( __FILE__ ) );
define( 'DS4W_FSP_PATH', plugin_dir_path( __FILE__ ) );

/** Option keys. */
const DS4W_FSP_OPT_TARGETS  = 'ds4w_fsp_targets';   // Raw textarea: page IDs / slugs / URLs, one per line.
const DS4W_FSP_OPT_POPUP_ID = 'ds4w_fsp_popup_id';  // ID of the Popup Maker popup we created.
const DS4W_FSP_OPT_LOCK     = 'ds4w_fsp_lock';      // 1 = popup cannot be dismissed except via the CTA.
const DS4W_FSP_OPT_CTA_URL  = 'ds4w_fsp_cta_url';   // Optional redirect after going fullscreen.
const DS4W_FSP_OPT_TARGET   = 'ds4w_fsp_fs_target'; // 'element' = fullscreen the flipbook; 'page' = whole document.
const DS4W_FSP_OPT_SELECTOR = 'ds4w_fsp_selector';  // CSS selector for the element to fullscreen.

/** Default selector: the Paperturn flipbook iframe, however Elementor wraps it. */
const DS4W_FSP_DEFAULT_SELECTOR = '[data-paperturn] iframe, iframe[src*="paperturn"]';

/* -------------------------------------------------------------------------
 * Target page resolution
 * ---------------------------------------------------------------------- */

/**
 * Parse the saved targets textarea into a list of page IDs.
 *
 * Accepts, one per line: a numeric page ID, a page slug, or a full URL.
 *
 * @return int[]
 */
function ds4w_fsp_target_ids() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$raw   = (string) get_option( DS4W_FSP_OPT_TARGETS, '' );
	$lines = preg_split( '/[\r\n,]+/', $raw );
	$ids   = [];

	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}

		// Plain numeric ID.
		if ( ctype_digit( $line ) ) {
			$ids[] = (int) $line;
			continue;
		}

		// Full URL -> resolve to a post ID.
		if ( preg_match( '#^https?://#i', $line ) ) {
			$id = url_to_postid( $line );
			if ( $id ) {
				$ids[] = (int) $id;
				continue;
			}
			// url_to_postid() fails on some permalink setups; fall back to the last path segment as a slug.
			$path = trim( (string) wp_parse_url( $line, PHP_URL_PATH ), '/' );
			$line = $path ? substr( strrchr( '/' . $path, '/' ), 1 ) : '';
			if ( '' === $line ) {
				continue;
			}
		}

		// Slug -> page, then any public post type.
		$page = get_page_by_path( sanitize_title( $line ), OBJECT, 'page' );
		if ( ! $page ) {
			$page = get_page_by_path( sanitize_title( $line ), OBJECT, get_post_types( [ 'public' => true ] ) );
		}
		if ( $page ) {
			$ids[] = (int) $page->ID;
		}
	}

	$cache = array_values( array_unique( array_filter( $ids ) ) );

	return $cache;
}

/**
 * Is the current request one of the target pages?
 *
 * @return bool
 */
function ds4w_fsp_is_target() {
	if ( is_admin() || ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id || ! in_array( (int) $id, ds4w_fsp_target_ids(), true ) ) {
		return false;
	}

	/*
	 * Password-protected pages: hold the promo back until the visitor is actually in.
	 *
	 * WordPress still renders the page (and fires wp_enqueue_scripts) when a password
	 * is required — it just swaps the content for the password form. Without this guard
	 * the locked popup would cover that form, and since the lock blocks all interaction
	 * the visitor could never type the password. It would trap them at the gate.
	 */
	if ( post_password_required( $id ) ) {
		return false;
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Popup Maker wiring
 * ---------------------------------------------------------------------- */

/**
 * Force our popup to load on target pages only — regardless of what the
 * Popup Maker "Conditions" UI says. This keeps the page list in one place
 * (our settings screen) and makes the plugin resilient to someone editing
 * the popup's conditions by hand.
 *
 * Other popups on the site are left completely alone.
 *
 * @param bool $loadable Whether Popup Maker currently intends to load it.
 * @param int  $popup_id Popup post ID.
 * @return bool
 */
function ds4w_fsp_filter_loadable( $loadable, $popup_id ) {
	$ours = (int) get_option( DS4W_FSP_OPT_POPUP_ID, 0 );

	if ( ! $ours || (int) $popup_id !== $ours ) {
		return $loadable; // Not our popup — don't interfere.
	}

	return ds4w_fsp_is_target();
}
add_filter( 'pum_popup_is_loadable', 'ds4w_fsp_filter_loadable', 20, 2 );

/**
 * Front-end assets, only on target pages, and only if our popup exists.
 */
function ds4w_fsp_enqueue() {
	if ( ! ds4w_fsp_is_target() ) {
		return;
	}

	$popup_id = (int) get_option( DS4W_FSP_OPT_POPUP_ID, 0 );
	if ( ! $popup_id || 'publish' !== get_post_status( $popup_id ) ) {
		return;
	}

	wp_enqueue_style(
		'ds4w-fsp',
		DS4W_FSP_URL . 'assets/ds4w-fullscreen-promo.css',
		[],
		DS4W_FSP_VERSION
	);

	// Depends on popup-maker-site so it always loads after PUM's own JS.
	wp_enqueue_script(
		'ds4w-fsp',
		DS4W_FSP_URL . 'assets/ds4w-fullscreen-promo.js',
		[ 'jquery', 'popup-maker-site' ],
		DS4W_FSP_VERSION,
		true
	);

	$selector = trim( (string) get_option( DS4W_FSP_OPT_SELECTOR, '' ) );

	wp_localize_script(
		'ds4w-fsp',
		'DS4W_FSP',
		[
			'popupId'  => $popup_id,
			'lock'     => (bool) get_option( DS4W_FSP_OPT_LOCK, 1 ),
			'ctaUrl'   => (string) get_option( DS4W_FSP_OPT_CTA_URL, '' ),
			// 'element' => fullscreen just the flipbook. 'page' => the whole document.
			'target'   => (string) get_option( DS4W_FSP_OPT_TARGET, 'element' ),
			'selector' => '' !== $selector ? $selector : DS4W_FSP_DEFAULT_SELECTOR,
		]
	);
}
add_action( 'wp_enqueue_scripts', 'ds4w_fsp_enqueue', 20 );

/* -------------------------------------------------------------------------
 * Popup creation (on activation)
 * ---------------------------------------------------------------------- */

/**
 * Default promo markup. Client can freely edit this later in Popup Maker;
 * the only thing our JS needs is an element carrying .ds4w-fs-go.
 *
 * @return string
 */
function ds4w_fsp_default_content() {
	return '<div class="ds4w-promo">' .
		'<h2 class="ds4w-promo__title">Welcome to Your VIP Preview</h2>' .
		'<p class="ds4w-promo__text">For the best reading experience, the book opens in fullscreen &mdash; ' .
		'so every page control is on screen and nothing gets in your way.</p>' .
		'<button type="button" class="ds4w-fs-go">Open the Book in Fullscreen</button>' .
		'<p class="ds4w-promo__note">Press Esc at any time to leave fullscreen.</p>' .
		'</div>';
}

/**
 * Build the popup_settings array using Popup Maker's own schema.
 *
 * @param int $popup_id Popup post ID (used for the cookie name).
 * @return array
 */
function ds4w_fsp_popup_settings( $popup_id ) {
	$lock = (bool) get_option( DS4W_FSP_OPT_LOCK, 1 );

	$theme_id = function_exists( 'pum_get_default_theme_id' ) ? (int) pum_get_default_theme_id() : 0;

	return [
		// Fire the moment the page is ready. No click needed to OPEN the popup.
		'triggers'                       => [
			[
				'type'     => 'auto_open',
				'settings' => [
					'cookie_name' => [],  // Deliberately empty: no cookie, so it opens on every visit.
					'delay'       => 0,
				],
			],
		],
		// No cookies -> the promo is not suppressed after being seen.
		'cookies'                        => [],
		// Conditions are enforced in PHP via pum_popup_is_loadable; left open here.
		'conditions'                     => [],
		'theme_id'                       => $theme_id ? (string) $theme_id : '',
		'size'                           => 'medium',
		'responsive_min_width'           => '0%',
		'responsive_max_width'           => '100%',
		'custom_width'                   => '640px',
		'custom_height'                  => '380px',
		'animation_type'                 => 'fade',
		'animation_speed'                => '350',
		'animation_origin'               => 'center top',
		'open_sound'                     => 'none',
		'custom_sound'                   => '',
		'location'                       => 'center',
		'position_top'                   => '100',
		'position_bottom'                => '0',
		'position_left'                  => '0',
		'position_right'                 => '0',
		'zindex'                         => '1999999999',
		'close_text'                     => '',
		'close_button_delay'             => '0',
		'close_on_form_submission_delay' => '0',
		'disable_on_mobile'              => false,
		'disable_on_tablet'              => false,
		'custom_height_auto'             => true,
		'scrollable_content'             => false,
		'position_from_trigger'          => false,
		'position_fixed'                 => true,
		'overlay_disabled'               => false,
		'stackable'                      => false,
		'disable_reposition'             => false,
		'close_on_form_submission'       => false,
		// The lock: these are what let a visitor dismiss the promo without acting.
		'close_on_overlay_click'         => ! $lock,
		'close_on_esc_press'             => ! $lock,
		'close_on_f4_press'              => ! $lock,
		'disable_form_reopen'            => false,
		'disable_accessibility'          => false,
		'theme_slug'                     => 'default-theme',
	];
}

/**
 * Create the popup on activation (once). Stores its ID in an option.
 */
function ds4w_fsp_activate() {
	// Seed the target page list with the VIP page if nothing is set yet.
	if ( '' === (string) get_option( DS4W_FSP_OPT_TARGETS, '' ) ) {
		add_option( DS4W_FSP_OPT_TARGETS, "/vip-access-page-private/\n" );
	}
	add_option( DS4W_FSP_OPT_LOCK, 1 );
	add_option( DS4W_FSP_OPT_CTA_URL, '' );
	add_option( DS4W_FSP_OPT_TARGET, 'element' );
	add_option( DS4W_FSP_OPT_SELECTOR, '' );

	$existing = (int) get_option( DS4W_FSP_OPT_POPUP_ID, 0 );
	if ( $existing && get_post( $existing ) && 'trash' !== get_post_status( $existing ) ) {
		return; // Already created — don't clobber the client's edits.
	}

	$popup_id = wp_insert_post(
		[
			'post_type'    => 'popup',
			'post_status'  => 'publish',
			'post_title'   => 'VIP Fullscreen Promo',
			'post_content' => ds4w_fsp_default_content(),
		]
	);

	if ( ! $popup_id || is_wp_error( $popup_id ) ) {
		return;
	}

	// Empty: the headline lives in the popup content, so a popup_title would just duplicate it.
	update_post_meta( $popup_id, 'popup_title', '' );
	update_post_meta( $popup_id, 'enabled', 1 ); // Popup Maker 1.12+ requires this.
	update_post_meta( $popup_id, 'popup_settings', ds4w_fsp_popup_settings( $popup_id ) );
	update_post_meta( $popup_id, 'ds4w_fsp_popup', 1 );

	update_option( DS4W_FSP_OPT_POPUP_ID, (int) $popup_id );
}
register_activation_hook( DS4W_FSP_FILE, 'ds4w_fsp_activate' );

/**
 * Keep the popup's lock settings in sync when the option is changed on our
 * settings screen, so the client doesn't have to touch Popup Maker at all.
 */
function ds4w_fsp_sync_lock() {
	$popup_id = (int) get_option( DS4W_FSP_OPT_POPUP_ID, 0 );
	if ( ! $popup_id || ! get_post( $popup_id ) ) {
		return;
	}

	$settings = get_post_meta( $popup_id, 'popup_settings', true );
	if ( ! is_array( $settings ) ) {
		return;
	}

	$lock = (bool) get_option( DS4W_FSP_OPT_LOCK, 1 );

	$settings['close_on_overlay_click'] = ! $lock;
	$settings['close_on_esc_press']     = ! $lock;
	$settings['close_on_f4_press']      = ! $lock;

	update_post_meta( $popup_id, 'popup_settings', $settings );
}
add_action( 'update_option_' . DS4W_FSP_OPT_LOCK, 'ds4w_fsp_sync_lock', 10, 0 );
add_action( 'add_option_' . DS4W_FSP_OPT_LOCK, 'ds4w_fsp_sync_lock', 10, 0 );

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

function ds4w_fsp_admin_menu() {
	add_options_page(
		'Fullscreen Promo',
		'Fullscreen Promo',
		'manage_options',
		'ds4w-fsp',
		'ds4w_fsp_settings_page'
	);
}
add_action( 'admin_menu', 'ds4w_fsp_admin_menu' );

function ds4w_fsp_register_settings() {
	register_setting( 'ds4w_fsp', DS4W_FSP_OPT_TARGETS, [ 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
	register_setting( 'ds4w_fsp', DS4W_FSP_OPT_LOCK, [ 'sanitize_callback' => 'absint', 'default' => 1 ] );
	register_setting( 'ds4w_fsp', DS4W_FSP_OPT_CTA_URL, [ 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );
	register_setting(
		'ds4w_fsp',
		DS4W_FSP_OPT_TARGET,
		[
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, [ 'element', 'page' ], true ) ? $v : 'element';
			},
			'default'           => 'element',
		]
	);
	register_setting( 'ds4w_fsp', DS4W_FSP_OPT_SELECTOR, [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
}
add_action( 'admin_init', 'ds4w_fsp_register_settings' );

function ds4w_fsp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$popup_id = (int) get_option( DS4W_FSP_OPT_POPUP_ID, 0 );
	$resolved = ds4w_fsp_target_ids();
	?>
	<div class="wrap">
		<h1>Fullscreen Promo Popup</h1>

		<?php if ( ! class_exists( 'PUM' ) && ! function_exists( 'pum_get_popup' ) ) : ?>
			<div class="notice notice-error"><p><strong>Popup Maker is not active.</strong> This plugin needs Popup Maker (free or Pro) to run.</p></div>
		<?php endif; ?>

		<?php if ( $popup_id ) : ?>
			<p>
				Popup: <strong>#<?php echo esc_html( (string) $popup_id ); ?></strong> —
				<a href="<?php echo esc_url( get_edit_post_link( $popup_id ) ); ?>">edit the promo content in Popup Maker</a>.
				Keep the <code>ds4w-fs-go</code> class on your call-to-action button; that button is what triggers fullscreen.
			</p>
		<?php else : ?>
			<div class="notice notice-warning"><p>No popup created yet. Deactivate and reactivate this plugin to create it.</p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'ds4w_fsp' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ds4w_targets">Show on these pages</label></th>
					<td>
						<textarea id="ds4w_targets" name="<?php echo esc_attr( DS4W_FSP_OPT_TARGETS ); ?>" rows="7" cols="60" class="large-text code"><?php echo esc_textarea( (string) get_option( DS4W_FSP_OPT_TARGETS, '' ) ); ?></textarea>
						<p class="description">
							One per line. A page ID (<code>1438</code>), a slug (<code>vip-access-page-private</code>), or a full URL.
							The popup appears <em>only</em> on these pages and nowhere else on the site.
						</p>
						<?php if ( $resolved ) : ?>
							<p class="description"><strong>Currently resolves to page IDs:</strong> <?php echo esc_html( implode( ', ', $resolved ) ); ?></p>
						<?php else : ?>
							<p class="description" style="color:#b32d2e;"><strong>Nothing resolved yet</strong> — the popup will not show anywhere until at least one line matches a real page.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Lock the popup</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( DS4W_FSP_OPT_LOCK ); ?>" value="1" <?php checked( 1, (int) get_option( DS4W_FSP_OPT_LOCK, 1 ) ); ?> />
							Block the page until the visitor clicks the button
						</label>
						<p class="description">
							Hides the close (&times;) button, disables the ESC key, and stops the background from being clicked or scrolled.
							The only way out is the call-to-action.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">What goes fullscreen</th>
					<td>
						<?php $target = (string) get_option( DS4W_FSP_OPT_TARGET, 'element' ); ?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="<?php echo esc_attr( DS4W_FSP_OPT_TARGET ); ?>" value="element" <?php checked( 'element', $target ); ?> />
							<strong>The flipbook only</strong> (recommended) &mdash; the reader fills the whole screen, controls and all.
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo esc_attr( DS4W_FSP_OPT_TARGET ); ?>" value="page" <?php checked( 'page', $target ); ?> />
							<strong>The whole page</strong> &mdash; fullscreens the WordPress page, so the header, footer
							and the flipbook's embed box all stay visible inside it.
						</label>
						<p class="description" style="margin-top:8px;">
							"The flipbook only" is what gives the clean reading experience &mdash; it's the same result as
							the flipbook's own fullscreen button, just triggered for the reader instead of hidden behind a hover.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ds4w_selector">Flipbook selector</label></th>
					<td>
						<input type="text" id="ds4w_selector" name="<?php echo esc_attr( DS4W_FSP_OPT_SELECTOR ); ?>" class="large-text code" value="<?php echo esc_attr( (string) get_option( DS4W_FSP_OPT_SELECTOR, '' ) ); ?>" placeholder="<?php echo esc_attr( DS4W_FSP_DEFAULT_SELECTOR ); ?>" />
						<p class="description">
							Leave blank unless the flipbook moves. Defaults to <code><?php echo esc_html( DS4W_FSP_DEFAULT_SELECTOR ); ?></code>,
							which finds the Paperturn embed. If nothing matches, the plugin falls back to the whole page rather than doing nothing.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ds4w_cta">Send visitor to (optional)</label></th>
					<td>
						<input type="url" id="ds4w_cta" name="<?php echo esc_attr( DS4W_FSP_OPT_CTA_URL ); ?>" class="regular-text" value="<?php echo esc_attr( (string) get_option( DS4W_FSP_OPT_CTA_URL, '' ) ); ?>" placeholder="https://de-stress4wellness.com/offer/" />
						<p class="description">Leave blank to simply close the popup and stay on the page (still in fullscreen).</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>How fullscreen behaves</h2>
		<p>
			Browsers only permit fullscreen during a genuine user gesture, so it is triggered by the visitor's
			first click/tap/keypress &mdash; normally the popup's button. The popup itself opens automatically with no click.
			On iPhone, iOS Safari does not allow fullscreen on page elements at all, so the plugin falls back to a
			full-viewport lock that looks the same.
		</p>
	</div>
	<?php
}
