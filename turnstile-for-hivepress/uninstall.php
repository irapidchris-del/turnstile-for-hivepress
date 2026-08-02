<?php
/**
 * Uninstall cleanup for Turnstile for HivePress.
 *
 * Deletes the plugin's own option. The Cloudflare keys and widget settings
 * belong to the Simple Cloudflare Turnstile plugin and are left untouched.
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tfhp_protected_forms' );
delete_site_transient( 'tfhp_github_release' );
