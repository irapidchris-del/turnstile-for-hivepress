<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on
 * deactivation, so switching the plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps your settings by default.** Someone who deletes
 * the plugin by accident, or removes it to install a clean copy, gets their
 * list of protected forms back when they reinstall. Destruction is opt-in,
 * through the "Delete All Data" checkbox in the HivePress section of the
 * Cloudflare Turnstile settings page, and is never a surprise.
 *
 * There is no way to ask at delete time. The confirmation form in
 * wp-admin/plugins.php:398-410 is hard-coded with no do_action or
 * apply_filters inside it, so a checkbox cannot be added to that screen; the
 * setting has to live on a settings page. Worse, WordPress prints "(will also
 * delete its data)" on that screen whenever an uninstall.php is present at all
 * (wp-admin/plugins.php:376-380), whatever the file actually does, so the
 * setting's own description has to tell the owner that the core warning does
 * not apply to them unless they ticked the box.
 *
 * The Cloudflare keys, widget theme, whitelist and every other Simple
 * Cloudflare Turnstile setting belong to that plugin and are never touched
 * here, whichever way the box is set.
 *
 * @package TurnstileForHivePress
 */

// Exit if accessed directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Read the owner's choice first, before anything is touched.
$tfhp_delete_all = (bool) get_option( 'tfhp_delete_data' );

/*
 * ---------------------------------------------------------------------------
 * Always cleaned, whichever way the setting is set.
 *
 * Regenerable runtime junk only: nothing here was created or configured by the
 * site owner, and all of it rebuilds itself on the next request.
 * ---------------------------------------------------------------------------
 */

// The updater's cached release lookup. A site transient lives under its own
// prefix, so neither the option sweep below nor a plain delete_option() would
// ever reach it.
delete_site_transient( 'tfhp_github_release' );

/*
 * The updater's other two site transients and its background job, which used to be left behind.
 *
 * All three are regenerable runtime state belonging to the update check, not the owner's
 * configuration, so they go unconditionally alongside the release cache above. Core's daily sweep
 * clears expired site transients within about a day on single-site, which is why this read as
 * harmless; on multisite they live in wp_sitemeta and are only purged when something asks for
 * them, so on a network they simply stay. The scheduled refresh is worse than debris: it is a job
 * whose callback no longer exists.
 *
 * Unscheduled from both places it can be, because the refresh is queued through HivePress's
 * scheduler (Action Scheduler) when HivePress is present and through WP-Cron when it is not.
 */
delete_site_transient( 'tfhp_github_release_reason' );
delete_site_transient( 'tfhp_github_release_rate_limit' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'tfhp_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'tfhp_github_release_refresh' );
}

wp_clear_scheduled_hook( 'tfhp_github_release_refresh' );

// Any ordinary transient the plugin has ever set. Nothing writes one today,
// but a transient is stored as "_transient_{name}" plus a separate
// "_transient_timeout_{name}" row, so the prefix sweep used for options further
// down cannot match them: it anchors on "tfhp_" at the start of the name.
// Leaving a timeout row behind with no value row is the classic orphan.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$tfhp_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'tfhp_' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'tfhp_' ) . '%'
	)
);

foreach ( (array) $tfhp_transients as $tfhp_transient_name ) {
	delete_option( $tfhp_transient_name );
}

/*
 * ---------------------------------------------------------------------------
 * Everything below happens only when the owner asked for it.
 * ---------------------------------------------------------------------------
 */

if ( $tfhp_delete_all ) {

	// Delete the plugin's options by prefix, so anything added in a later
	// version goes too. This runs once, while the plugin is being deleted, so
	// there is nothing worth caching.
	//
	// The "delete all data" option itself is excluded here and removed at the
	// very end. If this run fails part-way through, the flag is still set, so a
	// second attempt finishes the job. Sweeping it away first would silently
	// flip the site back to "retain" with half the data already gone.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$tfhp_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s",
			$wpdb->esc_like( 'tfhp_' ) . '%',
			'tfhp_delete_data'
		)
	);

	foreach ( (array) $tfhp_option_names as $tfhp_option_name ) {
		delete_option( $tfhp_option_name );
	}

	// Named explicitly as well, in case the prefix ever changes.
	delete_option( 'tfhp_protected_forms' );

	// Last, and only once everything above has succeeded.
	delete_option( 'tfhp_delete_data' );
}
