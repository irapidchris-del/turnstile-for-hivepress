<?php
/**
 * Admin settings panel.
 *
 * Renders a HivePress section inside the Simple Cloudflare Turnstile settings
 * page (via the cfturnstile-settings-section action) containing a checklist of
 * protectable HivePress forms, mirroring HivePress's own "Protected Forms"
 * selector under Settings > Integrations > reCAPTCHA.
 *
 * Saving is handled by piggybacking on the SCT form submission: we hook
 * sanitize_option_cfturnstile_key (a text field always present in the POST)
 * and persist our multi-select from $_POST. This avoids any dependence on the
 * WP Settings API allowed-options list, which SCT builds from its own private
 * array and cannot be extended by external plugins.
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the list of protectable forms.
 *
 * Preference order:
 *   1. Ask HivePress directly which forms are captcha-capable (any form whose
 *      meta contains a 'captcha' key). This auto-covers extensions we don't
 *      know about.
 *   2. Fall back to the known static list if HivePress isn't queryable yet.
 *
 * @return array<string,string> Form name => label.
 */
function tfhp_get_captcha_forms() {

	$forms = array();

	if ( function_exists( 'hivepress' ) && method_exists( hivepress(), 'get_classes' ) ) {
		$classes = hivepress()->get_classes( 'forms' );

		if ( is_array( $classes ) ) {
			foreach ( $classes as $form_name => $form_class ) {

				if ( ! is_string( $form_class ) && ! is_object( $form_class ) ) {
					continue;
				}

				// Only forms that declare a captcha meta key are protectable.
				$meta = method_exists( $form_class, 'get_meta' ) ? $form_class::get_meta() : array();

				if ( is_array( $meta ) && array_key_exists( 'captcha', $meta ) ) {
					$label = isset( $meta['label'] ) && $meta['label'] ? $meta['label'] : $form_name;

					$forms[ $form_name ] = $label;
				}
			}
		}
	}

	// Fallback / supplement with the known list.
	if ( empty( $forms ) ) {
		$forms = tfhp_known_forms();
	}

	asort( $forms );

	return $forms;
}

add_action( 'cfturnstile-settings-section', 'tfhp_render_settings_section' );

/**
 * Renders the HivePress accordion inside the SCT settings page.
 */
function tfhp_render_settings_section() {

	$forms     = tfhp_get_captcha_forms();
	$protected = tfhp_protected_forms();
	$keys_set  = ( get_option( 'cfturnstile_key' ) && get_option( 'cfturnstile_secret' ) );
	?>
	<button type="button" class="sct-accordion">
		<?php esc_html_e( 'HivePress', 'turnstile-for-hivepress' ); ?>
	</button>
	<div class="sct-panel">

		<?php if ( ! $keys_set ) : ?>
			<p style="margin:10px 0;color:#b32d2e;">
				<?php esc_html_e( 'Enter your Cloudflare Turnstile Site Key and Secret Key above, then save, before selecting forms.', 'turnstile-for-hivepress' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( get_option( 'hp_recaptcha_site_key' ) && get_option( 'hp_recaptcha_secret_key' ) ) : ?>
			<p style="margin:10px 0;color:#b32d2e;">
				<?php esc_html_e( 'Heads up: HivePress\'s built-in reCAPTCHA is also configured (Settings > Integrations). Every form selected here will show BOTH captchas and require both to pass, even if that form is not ticked in the HivePress settings. Remove the reCAPTCHA keys there if you want Turnstile only.', 'turnstile-for-hivepress' ); ?>
			</p>
		<?php endif; ?>

		<p style="margin:10px 0 6px;font-weight:600;font-size:13px;">
			<?php esc_html_e( 'Protected Forms', 'turnstile-for-hivepress' ); ?>
		</p>
		<p style="margin:0 0 12px;font-size:12px;color:#777;">
			<?php esc_html_e( 'Select which HivePress forms should display the Turnstile widget. Works on both page forms and modal popups (login, register, reset password).', 'turnstile-for-hivepress' ); ?>
		</p>

		<table class="form-table" style="margin-top:-5px;">
			<?php foreach ( $forms as $form_name => $label ) : ?>
				<tr valign="top">
					<th scope="row" style="font-weight:400;">
						<?php echo esc_html( $label ); ?>
						<code style="font-size:11px;color:#999;margin-left:6px;"><?php echo esc_html( $form_name ); ?></code>
					</th>
					<td>
						<input
							type="checkbox"
							name="tfhp_protected_forms[]"
							value="<?php echo esc_attr( $form_name ); ?>"
							<?php checked( in_array( $form_name, $protected, true ) ); ?>
						>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<p style="margin:12px 0 0;font-size:12px;color:#777;border-top:1px solid #eee;padding-top:10px;">
			<strong><?php esc_html_e( 'Using a caching / JS-optimisation plugin?', 'turnstile-for-hivepress' ); ?></strong><br>
			<?php esc_html_e( 'If the widget does not appear, add these keywords to your plugin\'s "Exclude from Delay/Defer JavaScript" list:', 'turnstile-for-hivepress' ); ?>
			<code>challenges.cloudflare.com</code>, <code>turnstile-render.js</code>
		</p>

		<p style="padding:6px 0 4px;font-size:12px;color:#777;">
			<?php
			printf(
				/* translators: 1: version number, 2: author profile link. */
				esc_html__( 'Turnstile for HivePress v%1$s by %2$s', 'turnstile-for-hivepress' ),
				esc_html( TFHP_VERSION ),
				'<a href="https://community.hivepress.io/u/chrisb/summary" target="_blank">ChrisB</a>'
			);
			?>
		</p>
	</div>
	<?php
}

add_action( 'admin_init', 'tfhp_register_save_hook' );

/**
 * Attaches the save handler to SCT's always-present key option.
 */
function tfhp_register_save_hook() {
	add_filter( 'sanitize_option_cfturnstile_key', 'tfhp_save_protected_forms' );
}

/**
 * Persists the selected forms when the SCT settings form is saved.
 *
 * @param mixed $value The cfturnstile_key value (passed through unchanged).
 * @return mixed
 */
function tfhp_save_protected_forms( $value ) {

	if (
		! isset( $_POST['option_page'] ) ||
		'cfturnstile-settings-group' !== $_POST['option_page'] ||
		! isset( $_POST['action'] ) ||
		'update' !== $_POST['action']
	) {
		return $value;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return $value;
	}

	if ( ! check_admin_referer( 'cfturnstile-settings-group-options' ) ) {
		return $value;
	}

	$submitted = array();

	if ( isset( $_POST['tfhp_protected_forms'] ) ) {
		$submitted = array_map( 'sanitize_key', (array) wp_unslash( $_POST['tfhp_protected_forms'] ) );
	}

	update_option( TFHP_OPTION, array_values( array_unique( array_filter( $submitted ) ) ) );

	return $value;
}

/*
 * Settings quick link on the Plugins screen row.
 *
 * The plugin's settings live inside the SCT settings page (the HivePress
 * accordion), so the link points there.
 */

add_filter( 'plugin_action_links_' . plugin_basename( TFHP_FILE ), 'tfhp_add_settings_link' );

/**
 * Adds the Settings quick link to the plugin row.
 *
 * @param array $links Plugin action links.
 * @return array
 */
function tfhp_add_settings_link( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'options-general.php?page=cfturnstile' ) ) . '">' . esc_html__( 'Settings', 'turnstile-for-hivepress' ) . '</a>'
	);

	return $links;
}

add_filter( 'cfturnstile-settings-not-installed', 'tfhp_remove_from_not_installed' );

/**
 * Removes HivePress from SCT's "Other Integrations" not-installed list.
 *
 * @param array $items Not-installed integration links.
 * @return array
 */
function tfhp_remove_from_not_installed( $items ) {
	return array_filter(
		(array) $items,
		function ( $item ) {
			return stripos( $item, 'hivepress' ) === false;
		}
	);
}
