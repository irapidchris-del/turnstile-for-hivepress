<?php
/**
 * Plugin Name: Turnstile for HivePress
 * Plugin URI:  https://github.com/irapidchris-del/turnstile-for-hivepress
 * Description: Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.
 * Version:     2.2.2
 * Author:      ChrisB @ HivePress Community
 * Author URI:  https://community.hivepress.io/u/chrisb/summary
 * License:     GPLv2 or later
 * Text Domain: turnstile-for-hivepress
 * Domain Path: /languages/
 *
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  simple-cloudflare-turnstile, hivepress
 * Update URI:        https://github.com/irapidchris-del/turnstile-for-hivepress
 *
 * ---------------------------------------------------------------------------
 * ARCHITECTURE
 * ---------------------------------------------------------------------------
 * HivePress has a native reCAPTCHA system (includes/components/class-form.php).
 * It works by:
 *   1. Marking certain forms with a 'captcha' => false meta flag (these are the
 *      forms that appear in Settings > Integrations > Protected Forms).
 *   2. On hivepress/v1/forms/{form}/meta, flipping that flag to true for any
 *      form the admin selected.
 *   3. On hivepress/v1/forms/{form}, injecting a '_captcha' FIELD into the form
 *      (not footer HTML), a real HivePress field of type 'captcha' that renders
 *      <div class="g-recaptcha" data-sitekey="...">.
 *   4. On hivepress/v1/forms/{form}/errors, verifying the token server-side.
 *
 * Because the captcha is a genuine form FIELD rendered inside hp-form__fields,
 * it appears correctly in every context HivePress supports, including the
 * login / register / password modals, with no special handling.
 *
 * This plugin mirrors that exact pattern, but renders a Cloudflare Turnstile
 * widget instead of Google reCAPTCHA, reusing the Simple Cloudflare Turnstile
 * plugin's keys, theme, language and server-side verification.
 *
 * The only extra piece is js/turnstile-render.js, which explicitly renders
 * each Turnstile widget when it becomes visible (Cloudflare's auto-render
 * skips elements that are hidden at page load, which is the case for modal
 * forms sitting in the footer).
 * ---------------------------------------------------------------------------
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TFHP_VERSION', '2.2.2' );
define( 'TFHP_FILE', __FILE__ );
define( 'TFHP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TFHP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Option key storing the array of enabled form names.
 */
define( 'TFHP_OPTION', 'tfhp_protected_forms' );

/**
 * Option key storing whether deleting the plugin should erase its data.
 */
define( 'TFHP_DELETE_OPTION', 'tfhp_delete_data' );

/**
 * Where the support link points.
 */
define( 'TFHP_DONATE_URL', 'https://ko-fi.com/chrisbathivepresscommunity' );

/**
 * Gets the known set of HivePress forms that natively support captcha, mapped
 * to the labels HivePress uses in the Protected Forms selector.
 *
 * These are exactly the forms whose class meta includes 'captcha' => false:
 *   Core:           user_login, user_register, user_password_request,
 *                   listing_submit, listing_report
 *   Bookings:       booking_confirm
 *   Claim Listings: listing_claim_submit
 *   Marketplace:    order_dispute
 *   Messages:       message_send
 *   Requests:       request_submit, offer_make
 *   Reviews:        review_submit, review_reply
 *
 * We do not hard-restrict to this list at runtime. Instead we honour whatever
 * HivePress itself reports as captcha-capable (see tfhp_get_captcha_forms()),
 * so any future or third-party form that opts into the captcha meta is covered
 * automatically; this list is only the fallback when HivePress cannot be
 * queried yet.
 *
 * @return array<string,string>
 */
function tfhp_known_forms() {
	return array(
		'user_login'            => __( 'Login User', 'turnstile-for-hivepress' ),
		'user_register'         => __( 'Register User', 'turnstile-for-hivepress' ),
		'user_password_request' => __( 'Reset Password', 'turnstile-for-hivepress' ),
		'listing_submit'        => __( 'Submit Listing', 'turnstile-for-hivepress' ),
		'listing_report'        => __( 'Report Listing', 'turnstile-for-hivepress' ),
		'listing_claim_submit'  => __( 'Claim Listing', 'turnstile-for-hivepress' ),
		'booking_confirm'       => __( 'Confirm Booking', 'turnstile-for-hivepress' ),
		'order_dispute'         => __( 'Dispute Order', 'turnstile-for-hivepress' ),
		'request_submit'        => __( 'Submit Request', 'turnstile-for-hivepress' ),
		'offer_make'            => __( 'Submit Offer', 'turnstile-for-hivepress' ),
		'review_submit'         => __( 'Write a Review', 'turnstile-for-hivepress' ),
		'review_reply'          => __( 'Reply to Review', 'turnstile-for-hivepress' ),
		'message_send'          => __( 'Send Message', 'turnstile-for-hivepress' ),
	);
}

/**
 * Gets the list of forms the admin has chosen to protect.
 *
 * @return string[]
 */
function tfhp_protected_forms() {
	$forms = get_option( TFHP_OPTION, array() );
	return is_array( $forms ) ? $forms : array();
}

/**
 * Checks whether a specific form is protected.
 *
 * @param string $form_name Form name, e.g. 'user_login'.
 * @return bool
 */
function tfhp_is_protected( $form_name ) {
	return in_array( $form_name, tfhp_protected_forms(), true );
}

add_filter( 'plugin_row_meta', 'tfhp_add_donate_link', 10, 2 );

/**
 * Adds the Donate link to the plugin's row on the Plugins screen.
 *
 * House placement: this row and the "View details" popup are the only two
 * places the plugin ever asks, so nothing appears inside its own settings UI.
 * WordPress joins row-meta items with " | " itself, so a bare anchor is
 * returned with no separator of its own.
 *
 * @param array<string> $links Row meta links.
 * @param string        $file Plugin file the row belongs to.
 * @return array<string>
 */
function tfhp_add_donate_link( $links, $file ) {
	if ( plugin_basename( TFHP_FILE ) !== $file ) {
		return $links;
	}

	$links[] = '<a href="' . esc_url( TFHP_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">'
		. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
		. esc_html__( 'Donate', 'turnstile-for-hivepress' )
		. '</a>';

	return $links;
}

/*
 * Load modules.
 *
 * These are required at main-file scope (not inside a plugins_loaded hook)
 * because HivePress fires its hivepress/v1/setup action at plugins_loaded
 * priority -10. WordPress includes all active plugin files BEFORE firing
 * plugins_loaded at all, so requiring here guarantees our
 * add_action('hivepress/v1/setup', ...) calls are registered in time.
 *
 * The modules only DEFINE functions and REGISTER hooks at load time; every
 * hook callback guards its own dependencies (tfhp_keys_ready(), class_exists,
 * function_exists) so nothing runs before HivePress / SCT are actually ready.
 */

require_once TFHP_DIR . 'inc/captcha.php';

if ( is_admin() ) {
	require_once TFHP_DIR . 'inc/admin.php';
}

/*
 * GitHub-powered updates.
 *
 * Library-free: uses WordPress's native update_plugins_{$hostname} filter
 * (WP 5.8+), keyed off the "Update URI" header above, to surface new versions
 * from this plugin's GitHub Releases on the Plugins screen. Loaded on every
 * request (not just admin) because update checks also run during wp-cron.
 */

require_once TFHP_DIR . 'inc/updater.php';

/*
 * Translations load through WordPress's just-in-time loader from the Text
 * Domain and Domain Path headers alone: users translate into
 * wp-content/languages/plugins/ (Loco Translate's "System" location). This is
 * the exact pattern of HivePress core and every official extension; none of
 * them calls load_plugin_textdomain(), and a bundled .mo inside the plugin
 * folder would not be auto-loaded anyway.
 */

add_action( 'plugins_loaded', 'tfhp_check_dependencies', 20 );

/**
 * Queues admin notices for any missing dependency once all plugins are loaded.
 */
function tfhp_check_dependencies() {
	if ( ! function_exists( 'cfturnstile_check' ) ) {
		add_action( 'admin_notices', 'tfhp_notice_turnstile' );
	}
	if ( ! function_exists( 'hivepress' ) ) {
		add_action( 'admin_notices', 'tfhp_notice_hivepress' );
	}
}

/**
 * Prints a dependency admin notice.
 *
 * @param string $plugin_link Anchor tag linking to the required plugin.
 */
function tfhp_dependency_notice( $plugin_link ) {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		wp_kses(
			sprintf(
				/* translators: %s: link to the required plugin */
				__( '<strong>Turnstile for HivePress</strong> requires the %s plugin to be installed and active.', 'turnstile-for-hivepress' ),
				$plugin_link
			),
			array(
				'strong' => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
				),
			)
		)
	);
}

/**
 * Prints the missing Simple Cloudflare Turnstile notice.
 */
function tfhp_notice_turnstile() {
	tfhp_dependency_notice( '<a href="https://wordpress.org/plugins/simple-cloudflare-turnstile/" target="_blank">Simple Cloudflare Turnstile</a>' );
}

/**
 * Prints the missing HivePress notice.
 */
function tfhp_notice_hivepress() {
	tfhp_dependency_notice( '<a href="https://wordpress.org/plugins/hivepress/" target="_blank">HivePress</a>' );
}
