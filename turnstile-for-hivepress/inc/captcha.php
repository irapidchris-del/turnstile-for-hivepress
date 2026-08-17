<?php
/**
 * Turnstile captcha integration for HivePress forms.
 *
 * Mirrors HivePress's native reCAPTCHA implementation
 * (includes/components/class-form.php) but renders a Cloudflare Turnstile
 * widget and verifies it via the Simple Cloudflare Turnstile plugin.
 *
 * Three HivePress hooks per form, exactly as core does for reCAPTCHA:
 *
 *   hivepress/v1/forms/{form}/meta    - flip 'captcha' meta to true
 *   hivepress/v1/forms/{form}         - inject the Turnstile field
 *   hivepress/v1/forms/{form}/errors  - verify token server-side
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether the Turnstile keys are configured in SCT.
 *
 * @return bool
 */
function tfhp_keys_ready() {
	return get_option( 'cfturnstile_key' ) && get_option( 'cfturnstile_secret' );
}

/**
 * Checks whether SCT's Cloudflare-down failsafe is currently active.
 *
 * When the admin enables the failsafe (cfturnstile_failover) and Cloudflare
 * appears down, SCT skips rendering its own widgets and its server-side check
 * lets submissions through (inc/turnstile.php cfturnstile_field_show(),
 * inc/failsafe.php cfturnstile_handle_failover_backend()). Without this
 * mirror, HivePress forms would keep an unrenderable widget and the submit
 * gate would block them for the whole outage. cfturnstile_is_cloudflare_down()
 * caches its probe in a 2-minute transient, so calling it per form is cheap.
 *
 * @return bool
 */
function tfhp_failsafe_active() {
	return get_option( 'cfturnstile_failover' )
		&& function_exists( 'cfturnstile_is_cloudflare_down' )
		&& cfturnstile_is_cloudflare_down();
}

add_action( 'hivepress/v1/setup', 'tfhp_register_form_hooks', 10 );

/**
 * Registers the per-form hooks for every protected form.
 *
 * Timing matters: HivePress calls each form's static init(), which fires the
 * hivepress/v1/forms/{form}/meta filter and CACHES the result, the first time
 * the class is autoloaded. Our /meta filter must be attached before that, or
 * the captcha flag won't flip.
 *
 * HivePress's own Form component registers its captcha meta filter during
 * get_components() on the hivepress/v1/setup action (fired at plugins_loaded
 * priority -10, before forms are rendered). We match that timing exactly.
 */
function tfhp_register_form_hooks() {

	if ( ! tfhp_keys_ready() ) {
		return;
	}

	foreach ( tfhp_protected_forms() as $form_name ) {

		$form_name = sanitize_key( $form_name );
		if ( ! $form_name ) {
			continue;
		}

		add_filter( "hivepress/v1/forms/{$form_name}/meta", 'tfhp_set_captcha_meta' );
		add_filter( "hivepress/v1/forms/{$form_name}", 'tfhp_add_captcha_field', 10, 2 );
		add_filter( "hivepress/v1/forms/{$form_name}/errors", 'tfhp_validate_captcha', 10, 2 );
	}
}

/**
 * Flips the captcha meta flag to true.
 *
 * HivePress forms that support captcha declare 'captcha' => false in meta.
 * We only set it true when the key exists, mirroring core's set_captcha().
 *
 * @param array $meta Form class meta.
 * @return array
 */
function tfhp_set_captcha_meta( $meta ) {
	// Only flip if this form actually supports captcha (has the key in meta).
	if ( isset( $meta['captcha'] ) ) {
		$meta['captcha'] = true;
	}
	return $meta;
}

/**
 * Injects the Turnstile field into the form.
 *
 * We add a real HivePress field of our custom type 'turnstile'. Like core's
 * '_captcha' field, it renders a div that the captcha JS turns into a widget.
 * Placing it as a field (not footer HTML) is what makes modals work.
 *
 * @param array  $args Form properties.
 * @param object $form Form object.
 * @return array
 */
function tfhp_add_captcha_field( $args, $form ) {

	// Respect the meta flag (set by tfhp_set_captcha_meta()).
	if ( ! $form::get_meta( 'captcha' ) ) {
		return $args;
	}

	// Don't add for whitelisted users (mirrors SCT's whitelist behaviour).
	if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
		return $args;
	}

	// Cloudflare down + SCT failsafe enabled: skip the widget like SCT does.
	if ( tfhp_failsafe_active() ) {
		return $args;
	}

	$args['fields']['_turnstile'] = array(
		'type'      => 'hptu_turnstile',
		'_separate' => true,
		'_order'    => 10000,
	);

	return $args;
}

/**
 * Verifies the Turnstile token server-side.
 *
 * cfturnstile_check() reads $_POST['cf-turnstile-response'] automatically,
 * which the Turnstile widget populates inside the submitted form.
 *
 * @param array  $errors Form errors.
 * @param object $form Form object.
 * @return array
 */
function tfhp_validate_captcha( $errors, $form ) {

	if ( ! $form::get_meta( 'captcha' ) ) {
		return $errors;
	}

	if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
		return $errors;
	}

	// Cloudflare down + SCT failsafe enabled: let the submission through, as
	// SCT does for its own forms. Covers widgets rendered before the outage
	// began as well as pages served without a widget during it.
	if ( tfhp_failsafe_active() ) {
		return $errors;
	}

	if ( ! function_exists( 'cfturnstile_check' ) ) {
		return $errors;
	}

	$check   = cfturnstile_check();
	$success = ! empty( $check['success'] ) && true === $check['success'];

	if ( ! $success ) {
		if ( function_exists( 'cfturnstile_failed_message' ) ) {
			$errors[] = cfturnstile_failed_message();
		} else {
			$errors[] = esc_html__( 'Captcha verification failed. Please try again.', 'turnstile-for-hivepress' );
		}
	}

	return $errors;
}

add_action( 'hivepress/v1/setup', 'tfhp_define_field_class', 5 );

/**
 * Defines the custom field class in HivePress's own namespace.
 *
 * HivePress resolves a field's 'type' directly to the class
 * \HivePress\Fields\{Type} (see Form::set_fields() ->
 * create_class_instance('\HivePress\Fields\\' . $type)). There is no filter
 * to map a type to an arbitrary class, so the class must literally exist as
 * \HivePress\Fields\Hptu_Turnstile.
 *
 * HivePress's autoloader only require()s a core file if it exists and
 * otherwise does nothing, so defining the class ourselves is safe: once
 * declared, class_exists() returns true and HivePress instantiates it
 * normally.
 *
 * We load it on 'hivepress/v1/setup' (fired after core is loaded) so the
 * abstract parent \HivePress\Fields\Field is available to extend.
 */
function tfhp_define_field_class() {

	if ( class_exists( '\HivePress\Fields\Hptu_Turnstile' ) ) {
		return;
	}

	// Referencing the parent triggers HivePress's autoloader to load it.
	if ( ! class_exists( '\HivePress\Fields\Field' ) ) {
		return;
	}

	require_once TFHP_DIR . 'inc/class-hptu-turnstile.php';
}

add_action( 'wp_enqueue_scripts', 'tfhp_enqueue_scripts' );

/**
 * Enqueues the Cloudflare Turnstile API and our explicit-render script on the
 * frontend whenever at least one form is protected.
 */
function tfhp_enqueue_scripts() {

	if ( ! function_exists( 'hivepress' ) || ! tfhp_keys_ready() || ! tfhp_protected_forms() ) {
		return;
	}

	if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
		return;
	}

	// Load the Cloudflare Turnstile API in explicit mode under our own handle.
	// It is loaded in the footer (which reliably renders the widget) and NOT
	// declared as a formal dependency of our render script; our script polls
	// for window.turnstile itself, which avoids WordPress async/dependency edge
	// cases that can stop the widget rendering.
	if (
		! wp_script_is( 'tfhp-cfturnstile', 'enqueued' ) &&
		! wp_script_is( 'cfturnstile', 'enqueued' )
	) {
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external API script; a version query string would bust Cloudflare's own cache control.
		wp_enqueue_script(
			'tfhp-cfturnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
			array(),
			null,
			true
		);
	}

	// Our explicit-render + lifecycle script. It is intentionally NOT given a
	// formal jQuery dependency: the core widget-render path is vanilla JS, so it
	// still works even if a JS-optimisation plugin delays jQuery. The optional
	// features that do use jQuery (token reset on ajaxComplete, FancyBox modal
	// events) poll for window.jQuery internally and attach when it appears.
	// The version carries filemtime() so changed JS can never be served stale
	// from a browser cache that remembers an earlier build.
	$render_file = TFHP_DIR . 'js/turnstile-render.js';
	$render_ver  = TFHP_VERSION;

	if ( file_exists( $render_file ) ) {
		$render_ver .= '.' . filemtime( $render_file );
	}

	wp_enqueue_script(
		'tfhp-turnstile-render',
		TFHP_URL . 'js/turnstile-render.js',
		array(),
		$render_ver,
		true
	);

	// The message shown when a submission is blocked because the widget has
	// not produced a token yet. Reuses the admin's SCT failure message so the
	// wording is consistent with SCT-protected forms.
	wp_localize_script(
		'tfhp-turnstile-render',
		'tfhpTurnstileData',
		array(
			'blockedMessage' => function_exists( 'cfturnstile_failed_message' )
				? cfturnstile_failed_message()
				: __( 'Please verify that you are human.', 'turnstile-for-hivepress' ),
		)
	);
}

add_action( 'wp_footer', 'tfhp_dedupe_turnstile_api', 1 );

/**
 * Avoids loading the Cloudflare API twice.
 *
 * SCT enqueues its own copy of api.js (handle 'cfturnstile') only while its
 * widget renders inside page content, AFTER our wp_enqueue_scripts check ran.
 * If that happened on this page, drop our copy just before footer scripts
 * print: Cloudflare advises loading api.js only once per page, and any copy
 * of the API exposes turnstile.render() for our explicit rendering.
 */
function tfhp_dedupe_turnstile_api() {

	if (
		wp_script_is( 'tfhp-cfturnstile', 'enqueued' ) &&
		( wp_script_is( 'cfturnstile', 'enqueued' ) || wp_script_is( 'cfturnstile', 'done' ) )
	) {
		wp_dequeue_script( 'tfhp-cfturnstile' );
	}
}

add_action( 'wp_head', 'tfhp_resource_hints', 1 );

/**
 * Preconnects to Cloudflare's challenge host so the API and widget assets
 * start their TCP/TLS handshake early, reducing the time-to-appear.
 */
function tfhp_resource_hints() {

	if ( ! function_exists( 'hivepress' ) || ! tfhp_keys_ready() || ! tfhp_protected_forms() ) {
		return;
	}
	if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
		return;
	}

	echo "<link rel='preconnect' href='https://challenges.cloudflare.com' crossorigin>\n";
}

/*
 * Keep the Cloudflare API script out of aggressive JS optimisation.
 *
 * Plugins like FlyingPress / Perfmatters delay or defer scripts, which can
 * break Turnstile (the "Remove async/defer before using turnstile.ready()"
 * error and preload warnings). We don't use turnstile.ready(), but we still
 * exclude both the API and our render script from delay/defer to be safe.
 *
 * FlyingPress matches partial keywords against the full <script> tag, so the
 * keywords below ("challenges.cloudflare.com", "turnstile-render.js") will
 * match our tags. If a JS-optimisation plugin still delays them, add those
 * same keywords to the plugin's own "exclude from delay" list in its settings.
 */

// FlyingPress (current + legacy filter names).
add_filter( 'flying_press_exclude_from_delay_js', 'tfhp_exclude_from_optimisation' );
add_filter( 'flying_press_js_delay_exclusions', 'tfhp_exclude_from_optimisation' );
add_filter( 'flying_press_exclude_from_defer_js', 'tfhp_exclude_from_optimisation' );
// Perfmatters.
add_filter( 'perfmatters_delay_js_exclusions', 'tfhp_exclude_from_optimisation' );
add_filter( 'perfmatters_js_defer_exclusions', 'tfhp_exclude_from_optimisation' );
// WP Rocket.
add_filter( 'rocket_delay_js_exclusions', 'tfhp_exclude_from_optimisation' );
add_filter( 'rocket_exclude_defer_js', 'tfhp_exclude_from_optimisation' );

/**
 * Adds our script keywords to a JS-optimisation plugin's exclusion list.
 *
 * @param array|string $exclusions Existing exclusions.
 * @return array
 */
function tfhp_exclude_from_optimisation( $exclusions ) {
	$exclusions   = (array) $exclusions;
	$exclusions[] = 'challenges.cloudflare.com';
	$exclusions[] = 'turnstile-render.js';
	$exclusions[] = 'turnstile/v0/api.js';
	return $exclusions;
}
