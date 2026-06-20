<?php
/**
 * Turnstile field.
 *
 * Declared in HivePress's own \HivePress\Fields namespace so that HivePress
 * resolves the field type 'turnstile' to this class (Form::set_fields()
 * instantiates '\HivePress\Fields\' . $type directly).
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
class Turnstile extends Field {

	/**
	 * Bootstraps field properties.
	 */
	protected function boot() {

		$attributes = array(
			'class'                => array( 'cf-turnstile', 'tfhp-turnstile' ),
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

		if ( get_option( 'cfturnstile_failure_message_enable' ) ) {
			$attributes['data-error-callback'] = 'cfturnstileErrorCallback';
		}

		$this->attributes = hp\merge_arrays( $this->attributes, $attributes );

		parent::boot();
	}

	/**
	 * Sanitizes field value.
	 *
	 * Nothing to sanitize — the Turnstile token is read directly from
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
