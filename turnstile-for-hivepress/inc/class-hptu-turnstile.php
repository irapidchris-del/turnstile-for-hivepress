<?php
/**
 * Turnstile field.
 *
 * Declared in HivePress's own \HivePress\Fields namespace so that HivePress
 * resolves the field type 'hptu_turnstile' to this class (Form::set_fields()
 * instantiates '\HivePress\Fields\' . $type directly, and PHP class names are
 * case-insensitive).
 *
 * The Hptu prefix is not decoration. Class names are global, and HivePress
 * resolves a field type straight to a class name, so an unprefixed
 * \HivePress\Fields\Turnstile would be claimed by the first party to declare
 * it. HivePress has publicly said it is considering its own Turnstile
 * integration; the day core ships one, an unprefixed class here would silently
 * lose (our loader skips a class that already exists) and every protected form
 * would render core's widget with core's key instead of the site's Simple
 * Cloudflare Turnstile configuration. Prefixing makes that collision
 * impossible.
 *
 * Mirrors \HivePress\Fields\Captcha but renders a Cloudflare Turnstile widget
 * using the Simple Cloudflare Turnstile plugin's configuration.
 *
 * @package TurnstileForHivePress
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Turnstile embed, rendered as a HivePress form field.
 */
class Hptu_Turnstile extends Field {

	/**
	 * Bootstraps field properties.
	 */
	protected function boot() {

		// Intentionally NOT using the 'cf-turnstile' class: Cloudflare's api.js
		// auto-renders every '.cf-turnstile' element when any plugin loads it
		// in auto mode. Current SCT loads it in explicit mode
		// (simple-cloudflare-turnstile.php cfturnstile_api_url()), but older
		// SCT versions and other plugins may still load an auto-mode copy on
		// the same page, and that auto-render would hit our widgets too,
		// including ones hidden inside modals, breaking them. Our own JS
		// renders '.tfhp-turnstile' elements explicitly instead.
		$attributes = array(
			'class'                => array( 'tfhp-turnstile' ),
			'data-sitekey'         => get_option( 'cfturnstile_key' ),
			'data-theme'           => get_option( 'cfturnstile_theme', 'auto' ),
			'data-language'        => get_option( 'cfturnstile_language', 'auto' ),
			'data-size'            => get_option( 'cfturnstile_size', 'normal' ),
			'data-appearance'      => get_option( 'cfturnstile_appearance', 'always' ),
			'data-retry'           => 'auto',
			'data-retry-interval'  => '8000',
			'data-refresh-expired' => 'auto',
			'data-refresh-timeout' => get_option( 'cfturnstile_refresh_timeout', 'auto' ),
		);

		// SCT's "failure message" feature. SCT implements it with an inline
		// cfturnstileErrorCallback() printed next to its OWN widgets only
		// (inc/turnstile.php cfturnstile_failed_text()), so that callback is
		// unusable here. Instead the message text is passed on the widget and
		// js/turnstile-render.js shows/clears it via Turnstile's error and
		// success callbacks.
		if ( get_option( 'cfturnstile_failure_message_enable' ) ) {
			$message = get_option( 'cfturnstile_failure_message' );

			if ( ! is_string( $message ) || '' === trim( $message ) ) {
				$message = __( 'Failed to verify you are human. Please contact us if you are having issues.', 'turnstile-for-hivepress' );
			}

			$attributes['data-failure-message'] = wp_strip_all_tags( $message );
		}

		$this->attributes = hp\merge_arrays( $this->attributes, $attributes );

		parent::boot();
	}

	/**
	 * Sanitizes field value.
	 *
	 * Nothing to sanitize: the Turnstile token is read directly from
	 * $_POST['cf-turnstile-response'] by cfturnstile_check() during
	 * server-side validation.
	 */
	protected function sanitize() {}

	/**
	 * Validates field value.
	 *
	 * Field-level validation always passes; the real verification happens in
	 * the form's /errors filter (tfhp_validate_captcha) via cfturnstile_check().
	 *
	 * @return bool
	 */
	public function validate() {
		$this->errors = array();
		return true;
	}

	/**
	 * Renders field HTML.
	 *
	 * @return string
	 */
	public function render() {
		return '<div ' . hp\html_attributes( $this->attributes ) . '></div>';
	}
}
