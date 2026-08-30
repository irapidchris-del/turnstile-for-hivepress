<?php
/**
 * Fits the Turnstile widget to the WordPress login form.
 *
 * Simple Cloudflare Turnstile can add its widget to the wp-login.php login,
 * register and reset-password forms. Cloudflare draws the standard widget at a
 * fixed 300px and it does not shrink with its container, while WordPress gives
 * the login form a 270px content column: wp-admin/css/login.css sets
 * #login { width: 320px } (line 307) and .login form { padding: 26px 24px;
 * border: 1px } (line 152), leaving 320 - 48 - 2 = 270px for the fields.
 *
 * So the widget was drawn 30px wider than the username and password fields
 * above it. SCT half-hides that by pulling the widget 15px to the left on this
 * page (inc/turnstile.php, cfturnstile_admin_styles(), line 127), which spreads
 * the extra 30px evenly and leaves the widget hanging over both edges of the
 * field column instead of one. That margin is undone here before scaling, so
 * the widget starts exactly where the fields start; without that, scaling alone
 * would leave the widget 15px short of their right-hand edge.
 *
 * The override needs !important because SCT writes that margin against the
 * widget's generated id (#cf-turnstile-1234567890), and an id beats any
 * selector built from classes. Removing SCT's action instead would be tidier
 * to read, but it would break silently and invisibly if that function is ever
 * renamed, which is the sort of thing nobody notices for a year.
 *
 * Scaling with a CSS transform is the same technique fitWidget() in
 * js/turnstile-render.js uses for narrow HivePress modals, and that code
 * proved it leaves the Cloudflare iframe fully clickable. The newer zoom
 * property would avoid the few pixels of slack the transform leaves below the
 * widget, but its effect on hit testing inside a cross-origin iframe is not
 * equally proven in every browser, and a widget the owner cannot click is a
 * locked-out site rather than a cosmetic fault.
 *
 * No JavaScript is added to wp-login.php on purpose. That page is the one
 * place where a script error costs the site owner access to their own site,
 * and every measurement needed here is a WordPress core constant rather than
 * something that has to be read from the rendered page.
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Width in pixels of the content column inside the WordPress login form.
 *
 * This is what the username and password fields are drawn at, so it is the
 * width the widget has to match. Overridable through the
 * 'tfhp_login_form_width' filter for sites whose login page has been restyled
 * to a different width by a login customiser plugin or a custom stylesheet.
 */
define( 'TFHP_LOGIN_FORM_WIDTH', 270 );

/**
 * Widths in pixels Cloudflare draws each Turnstile widget size at.
 *
 * 'flexible' fills its container but never renders below 300px, so inside the
 * login form it comes out the same as 'normal'. Any unrecognised value falls
 * back to the normal width, which is also what Cloudflare itself does with an
 * empty data-size attribute.
 *
 * @return array<string,int>
 */
function tfhp_widget_widths() {
	return array(
		'normal'   => 300,
		'flexible' => 300,
		'compact'  => 150,
	);
}

add_action( 'login_enqueue_scripts', 'tfhp_fit_login_widget', 20 );

/**
 * Adds the CSS that lines the login-page widget up with the form fields.
 *
 * Attached to the 'login' stylesheet handle rather than printed directly, so
 * it always lands after wp-admin/css/login.css no matter what else the page
 * enqueues. wp-login.php enqueues that handle before firing this action
 * (wp-login.php:100), so the inline style is never dropped.
 */
function tfhp_fit_login_widget() {

	// Simple Cloudflare Turnstile has to be present and configured; without
	// keys it renders nothing on the login page for us to fit.
	if ( ! function_exists( 'cfturnstile_check' ) || ! tfhp_keys_ready() ) {
		return;
	}

	// It only places a widget on wp-login.php when one of its three WordPress
	// form options is switched on (inc/wordpress.php lines 38, 178 and 217).
	if (
		! get_option( 'cfturnstile_login' ) &&
		! get_option( 'cfturnstile_register' ) &&
		! get_option( 'cfturnstile_reset' )
	) {
		return;
	}

	// Whitelisted visitors never see a widget at all.
	if ( function_exists( 'cfturnstile_whitelisted' ) && cfturnstile_whitelisted() ) {
		return;
	}

	$widths       = tfhp_widget_widths();
	$size         = (string) get_option( 'cfturnstile_size' );
	$widget_width = isset( $widths[ $size ] ) ? $widths[ $size ] : $widths['normal'];

	/**
	 * Filters the width the login-page widget is fitted to.
	 *
	 * @param int $width Width in pixels of the login form's content column.
	 */
	$form_width = (int) apply_filters( 'tfhp_login_form_width', TFHP_LOGIN_FORM_WIDTH );

	// Undo SCT's 15px pull so the widget starts on the same line as the fields.
	// This alone is the whole fix for the compact size, which is 150px and was
	// only ever misplaced, never too wide.
	$css = '.login form .cf-turnstile{margin-left:0 !important;}';

	// Then shrink it, if Cloudflare draws it wider than the fields.
	if ( $form_width > 0 && $form_width < $widget_width ) {
		$scale = round( $form_width / $widget_width, 4 );

		// The origin keeps the widget's left edge on the left edge of the
		// fields as it shrinks. Height is deliberately left alone: setting one
		// would reserve empty space on sites using the interaction-only
		// appearance, where the widget has no height until it is needed.
		$css .= '.login form .cf-turnstile{transform:scale(' . $scale . ');transform-origin:0 0;}';
	}

	wp_add_inline_style( 'login', $css );
}
