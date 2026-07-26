<?php
/**
 * Plugin Name: Turnstile for HivePress
 * Plugin URI:  https://community.hivepress.io/
 * Description: Protects HivePress forms with Cloudflare Turnstile, using HivePress's own native captcha field system for full modal and AJAX support.
 * Version:     2.1.0
 * Author:      Chris B @ HivePress Community
 * License:     GPLv2 or later
 * Text Domain: turnstile-for-hivepress
 * Domain Path: /languages
 *
 * Requires at least: 6.5
 * Requires PHP:      7.2
 * Requires Plugins:  simple-cloudflare-turnstile, hivepress
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
 *      (not footer HTML) — a real HivePress field of type 'captcha' that renders
 *      <div class="g-recaptcha" data-sitekey="...">.
 *   4. On hivepress/v1/forms/{form}/errors, verifying the token server-side.
 *
 * Because the captcha is a genuine form FIELD rendered inside hp-form__fields,
 * it appears correctly in every context HivePress supports — including the
 * login / register / password modals — with no special handling.
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
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TFHP_VERSION', '2.1.0' );
define( 'TFHP_FILE',    __FILE__ );
define( 'TFHP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'TFHP_URL',     plugin_dir_url( __FILE__ ) );

/**
 * The complete set of HivePress forms that natively support captcha, mapped to
 * the labels HivePress uses in the Protected Forms selector.
 *
 * These are exactly the forms whose class meta includes 'captcha' => false:
 *   Core:        user_login, user_register, user_password_request,
 *                listing_submit, listing_report
 *   Bookings:    booking_confirm
 *   Marketplace: order_dispute
 *   Reviews:     review_submit, review_reply
 *   Messages:    message_send
 *
 * We do not hard-restrict to this list at runtime — instead we honour whatever
 * HivePress itself reports as captcha-capable (see tfhp_get_captcha_forms()),
 * so any future or third-party form that opts into the captcha meta is covered
 * automatically.
 *
 * @return array<string,string>
 */
function tfhp_known_forms() {
	return array(
		'user_login'            => 'Login User',
		'user_register'         => 'Register User',
		'user_password_request' => 'Reset Password',
		'listing_submit'        => 'Submit Listing',
		'listing_report'        => 'Report Listing',
		'booking_confirm'       => 'Confirm Booking',
		'order_dispute'         => 'Dispute Order',
		'review_submit'         => 'Write a Review',
		'review_reply'          => 'Reply to Review',
		'message_send'          => 'Send Message',
	);
}

/**
 * Option key storing the array of enabled form names.
 */
define( 'TFHP_OPTION', 'tfhp_protected_forms' );

/**
 * Get the list of forms the admin has chosen to protect.
 *
 * @return string[]
 */
function tfhp_protected_forms() {
	$forms = get_option( TFHP_OPTION, array() );
	return is_array( $forms ) ? $forms : array();
}

/**
 * Whether a specific form is protected.
 *
 * @param string $form_name
 * @return bool
 */
function tfhp_is_protected( $form_name ) {
	return in_array( $form_name, tfhp_protected_forms(), true );
}

/* --------------------------------------------------------------------------
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
 * ----------------------------------------------------------------------- */

require_once TFHP_DIR . 'inc/captcha.php';

if ( is_admin() ) {
	require_once TFHP_DIR . 'inc/admin.php';
}

/* --------------------------------------------------------------------------
 * GitHub-based auto-updates.
 *
 * Uses the bundled Plugin Update Checker library (lib/plugin-update-checker/)
 * to surface new versions on the WordPress Plugins screen — update notices,
 * "View details", and one-click update — served straight from this plugin's
 * GitHub Releases.
 *
 * Release contract (see RELEASING.md):
 *   - Tag each release with the version number (e.g. 2.1.0 or v2.1.0); the
 *     checker reads the version from the tag.
 *   - Attach a "turnstile-for-hivepress.zip" asset built so its top-level
 *     folder is "turnstile-for-hivepress" (no version in the name). We install
 *     ONLY that asset — never GitHub's auto-generated source zip, whose folder
 *     name carries the version and would land in the wrong directory.
 * ----------------------------------------------------------------------- */

if ( file_exists( TFHP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {

	require_once TFHP_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {

		$tfhp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/irapidchris-del/turnstile-for-hivepress/',
			TFHP_FILE,
			'turnstile-for-hivepress'
		);

		// Install the clean, fixed-name release asset rather than the source zip.
		$tfhp_vcs_api = $tfhp_update_checker->getVcsApi();
		if ( is_object( $tfhp_vcs_api ) && method_exists( $tfhp_vcs_api, 'enableReleaseAssets' ) ) {
			$tfhp_vcs_api->enableReleaseAssets( '/turnstile-for-hivepress\.zip$/i' );
		}
	}
}

/* --------------------------------------------------------------------------
 * Translations.
 * ----------------------------------------------------------------------- */

add_action( 'init', 'tfhp_load_textdomain' );
function tfhp_load_textdomain() {
	load_plugin_textdomain( 'turnstile-for-hivepress', false, dirname( plugin_basename( TFHP_FILE ) ) . '/languages' );
}

/* --------------------------------------------------------------------------
 * Dependency notices (checked once everything is loaded).
 * ----------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'tfhp_check_dependencies', 20 );
function tfhp_check_dependencies() {
	if ( ! function_exists( 'cfturnstile_check' ) ) {
		add_action( 'admin_notices', 'tfhp_notice_turnstile' );
	}
	if ( ! function_exists( 'hivepress' ) ) {
		add_action( 'admin_notices', 'tfhp_notice_hivepress' );
	}
}

/* --------------------------------------------------------------------------
 * Admin notices
 * ----------------------------------------------------------------------- */

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

function tfhp_notice_turnstile() {
	tfhp_dependency_notice( '<a href="https://wordpress.org/plugins/simple-cloudflare-turnstile/" target="_blank">Simple Cloudflare Turnstile</a>' );
}

function tfhp_notice_hivepress() {
	tfhp_dependency_notice( '<a href="https://wordpress.org/plugins/hivepress/" target="_blank">HivePress</a>' );
}
