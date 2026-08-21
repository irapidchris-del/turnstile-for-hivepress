<?php
/**
 * GitHub-powered updates for Turnstile for HivePress.
 *
 * The plugin is distributed via GitHub releases rather than wordpress.org, so
 * update checks go through the native `update_plugins_{$hostname}` filter
 * introduced in WordPress 5.8, keyed off the `Update URI` header in the main
 * plugin file. The update package is the release asset named
 * `turnstile-for-hivepress.zip`, which must contain a single
 * `turnstile-for-hivepress/` directory.
 *
 * This mirrors the reference implementation in Persistent Account Menu for
 * HivePress, adapted for this plugin's split-file, non-namespaced structure:
 * every plugin-identity lookup keys off TFHP_FILE (the main plugin file) and
 * TFHP_VERSION rather than __FILE__, because this file is an include, not the
 * plugin's entry point.
 *
 * @package TurnstileForHivePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TFHP_UPDATE_REPO', 'irapidchris-del/turnstile-for-hivepress' );
define( 'TFHP_UPDATE_SLUG', 'turnstile-for-hivepress' );
define( 'TFHP_UPDATE_CACHE_KEY', 'tfhp_github_release' );

/**
 * Why the last release check came back empty, so the notice can say which.
 */
define( 'TFHP_UPDATE_REASON_KEY', 'tfhp_github_release_reason' );

/**
 * When GitHub's hourly allowance for this server is expected back. While this is set the
 * API is not called at all, so a site that has run out does not spend the rest of the
 * window making requests that can only fail.
 */
define( 'TFHP_UPDATE_RATE_LIMIT_KEY', 'tfhp_github_release_rate_limit' );

/**
 * Gets the latest GitHub release details, cached for 6 hours.
 *
 * The result always carries a 'status' key:
 *   'ok'          a usable release exists (version, package, url, notes, published)
 *   'unusable'    GitHub answered but the latest release has no zip asset or no tag
 *   'unreachable' GitHub could not be reached or answered with an error
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null Null while a background refresh is queued and nothing is cached yet.
 */
function tfhp_get_latest_release( $force = false ) {
	$cached  = get_site_transient( TFHP_UPDATE_CACHE_KEY );
	$release = $force ? false : $cached;

	/*
	 * A cold cache must not be filled from somebody's page load. WordPress asks every plugin for its
	 * update details while rendering an admin request, so with several of these installed one such
	 * request made one blocking call to GitHub after another, in series: a site with nine of them
	 * measured 18.6 seconds on a settings screen, once, and then behaved perfectly for six hours
	 * because the answers were cached again. That is the same shape as the listing-save incident, on
	 * the admin side rather than the public one.
	 *
	 * So the fetch moves to a background job and this answers with what is already known. The manual
	 * Check for updates link still fetches immediately, because there a person is waiting for it.
	 */
	if ( ! $force && ( ! is_array( $cached ) || ! isset( $cached['status'] ) ) ) {
		tfhp_schedule_release_refresh();

		return null;
	}

	// The status key also invalidates caches written by older plugin versions,
	// which stored a different shape under the same transient name.
	if ( ! is_array( $release ) || ! isset( $release['status'] ) ) {
		$release = tfhp_fetch_latest_release();

		// A failed check must not erase what the last good one found. Overwriting a usable answer
		// with a failure state took a genuinely pending update off the Plugins screen for an hour
		// with nothing to say why.
		if ( 'ok' !== $release['status'] && tfhp_release_usable( $cached ) ) {
			set_site_transient( TFHP_UPDATE_CACHE_KEY, $cached, HOUR_IN_SECONDS );

			return $cached;
		}

		// Failures are cached briefly so the lookup is not repeated on every admin page load.
		set_site_transient( TFHP_UPDATE_CACHE_KEY, $release, 'ok' === $release['status'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	return $release;
}

/**
 * Whether a release lookup result offers an installable package.
 *
 * @param array<string, string> $release Release details.
 * @return bool
 */
function tfhp_release_usable( $release ) {
	return is_array( $release ) && isset( $release['status'] ) && 'ok' === $release['status'];
}

/**
 * Fetches the latest release details from the GitHub API.
 *
 * Draft and pre-release entries are excluded by the endpoint itself, so
 * publishing a pre-release never triggers an update notice.
 *
 * @return array<string, string>
 */
function tfhp_fetch_latest_release() {
	$data = tfhp_fetch_release_data();

	if ( ! is_array( $data ) ) {

		// Translate the lookup's reason into this plugin's own status vocabulary.
		$reason = get_site_transient( TFHP_UPDATE_REASON_KEY );

		return array( 'status' => in_array( $reason, array( 'no_release', 'rate_limited' ), true ) ? $reason : 'unreachable' );
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $version || ! $package ) {
		return array( 'status' => 'unusable' );
	}

	return array(
		'status'    => 'ok',
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . TFHP_UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	);
}

/**
 * Queues a background refresh of the release cache.
 *
 * Prefers HivePress's scheduler, which is Action Scheduler and already refuses a duplicate of a job
 * with the same hook and arguments, so repeated admin requests coalesce into one fetch. WP-Cron is
 * the fallback for the same reason it exists: it also runs the work outside this request.
 *
 * Neither is blocking, so where cron itself is starved the cache simply stays cold and no update is
 * offered until somebody presses Check for updates, which always fetches at once.
 *
 * @return void
 */
function tfhp_schedule_release_refresh() {
	$hook = TFHP_UPDATE_CACHE_KEY . '_refresh';

	// Assigned and then tested: Core defines no __isset(), so isset( hivepress()->x ) is always
	// false even for a component that is present and working.
	$scheduler = function_exists( 'hivepress' ) ? hivepress()->scheduler : null;

	if ( $scheduler ) {
		$scheduler->add_action( $hook );

		return;
	}

	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_single_event( time(), $hook );
	}
}

/**
 * Fills the release cache. Runs from the scheduler, never from a page render.
 *
 * @return void
 */
function tfhp_refresh_release() {
	tfhp_get_latest_release( true );
}

add_action( TFHP_UPDATE_CACHE_KEY . '_refresh', 'tfhp_refresh_release' );

/**
 * Gets the latest release, from github.com in preference to the GitHub API.
 *
 * WHY THIS DOES NOT SIMPLY CALL THE API
 *
 * Without a token `api.github.com` allows **60 requests an hour per IP address**, and that
 * allowance is shared by every plugin on the site, by every other site on the same server, and by
 * anything else calling the API from that address. A site running several of these extensions,
 * plus a few clicks of "Check for updates" - which deliberately bypasses the cache - spends it
 * easily; on shared hosting a neighbouring site can spend it alone. GitHub then answers 403, and
 * reporting that as "could not reach GitHub" sends the owner hunting a network fault that does not
 * exist. That is the same family of bug as reporting a 404 as unreachable: a refusal is an answer,
 * not a failure to get one.
 *
 * Everything this lookup needs is also published on github.com itself, which carries no such
 * allowance:
 *
 *   - `/releases/latest` answers 302, and the Location header names the release GitHub considers
 *     latest, with drafts and pre-releases excluded exactly as the API excludes them;
 *   - `/releases/expanded_assets/{tag}` is the fragment the release page uses to list its own
 *     downloads, so it names the asset;
 *   - `/releases.atom` carries the release notes.
 *
 * Measured against GitHub's own rate-limit counter on 2026-08-19, thirteen full update checks
 * through this route moved it by zero. The API is kept as a fallback so that a change at github.com
 * cannot leave the plugin with no way to check at all.
 *
 * @return array<string, mixed>|null Release data in the API's own shape, or null.
 */
function tfhp_fetch_release_data() {
	$site = tfhp_fetch_release_from_site();

	if ( isset( $site['release'] ) ) {
		delete_site_transient( TFHP_UPDATE_REASON_KEY );

		return $site['release'];
	}

	// github.com has given a definite answer that nothing is published. Asking the API would only
	// repeat it, at the cost of one of the sixty.
	if ( isset( $site['reason'] ) && 'no_release' === $site['reason'] ) {
		set_site_transient( TFHP_UPDATE_REASON_KEY, 'no_release', HOUR_IN_SECONDS );

		return null;
	}

	return tfhp_fetch_release_from_api();
}

/**
 * Reads the latest release from github.com, without touching the API allowance.
 *
 * @return array<string, mixed> Either a `release` in the API's shape, a `reason`, or empty to fall
 *                              back to the API.
 */
function tfhp_fetch_release_from_site() {
	$base = 'https://github.com/' . TFHP_UPDATE_REPO;

	$response = tfhp_request(
		$base . '/releases/latest',
		[
			// Do not follow it. The redirect target is the answer.
			'redirection' => 0,
		]
	);

	if ( ! $response ) {
		return [];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	// A repository with nothing published answers 404 here, which is the normal state of a new
	// repository rather than a fault.
	if ( 404 === $code ) {
		return [ 'reason' => 'no_release' ];
	}

	if ( 301 !== $code && 302 !== $code ) {
		return [];
	}

	$location = wp_remote_retrieve_header( $response, 'location' );

	// WordPress hands back an array when a header repeats.
	if ( is_array( $location ) ) {
		$location = end( $location );
	}

	if ( ! preg_match( '#/releases/tag/(.+)$#', (string) $location, $matches ) ) {
		return [];
	}

	$tag = rawurldecode( trim( $matches[1] ) );

	$asset = tfhp_fetch_release_asset( $base, $tag );

	// No downloadable asset means there is nothing the updater could install, so let the API have
	// its say rather than reporting a release that cannot be applied.
	if ( ! $asset ) {
		return [];
	}

	$notes = tfhp_fetch_release_notes( $base, $tag );

	// Shaped exactly like the API's own answer, so everything downstream is identical either way.
	return [
		'release' => [
			'tag_name'     => $tag,
			'html_url'     => $base . '/releases/tag/' . rawurlencode( $tag ),
			'body'         => $notes['body'],
			'published_at' => $notes['published'],
			'assets'       => [
				[
					'name'                 => $asset['name'],
					'browser_download_url' => $asset['url'],
				],
			],
		],
	];
}

/**
 * Reads a release's asset from the fragment the release page uses to list its own downloads.
 *
 * @param string $base Repository URL.
 * @param string $tag Release tag.
 * @return array<string, string>|null
 */
function tfhp_fetch_release_asset( $base, $tag ) {
	$response = tfhp_request( $base . '/releases/expanded_assets/' . rawurlencode( $tag ) );

	if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	if ( ! preg_match_all( '#href="(/[^"]*/releases/download/[^"]+\.zip)"#i', wp_remote_retrieve_body( $response ), $matches ) ) {
		return null;
	}

	// Take the first zip, matching what the API branch does with the assets list.
	$path = html_entity_decode( $matches[1][0], ENT_QUOTES, 'UTF-8' );

	return [
		'name' => rawurldecode( basename( $path ) ),
		'url'  => 'https://github.com' . $path,
	];
}

/**
 * Reads a release's notes and publication date from the releases feed.
 *
 * Only the changelog in the plugin details popup depends on this, so a failure here is not fatal.
 *
 * @param string $base Repository URL.
 * @param string $tag Release tag.
 * @return array<string, string>
 */
function tfhp_fetch_release_notes( $base, $tag ) {
	$empty = [
		'body'      => '',
		'published' => '',
	];

	$response = tfhp_request( $base . '/releases.atom' );

	if ( ! $response || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return $empty;
	}

	if ( ! preg_match_all( '#<entry>(.*?)</entry>#s', wp_remote_retrieve_body( $response ), $entries ) ) {
		return $empty;
	}

	foreach ( $entries[1] as $entry ) {

		// Match the tag rather than taking the newest entry: the feed also carries pre-releases,
		// which the latest-release redirect deliberately skips.
		if ( false === strpos( $entry, '/releases/tag/' . $tag ) ) {
			continue;
		}

		$notes = '';

		if ( preg_match( '#<content[^>]*>(.*?)</content>#s', $entry, $content ) ) {
			$notes = tfhp_release_notes_to_text( $content[1] );
		}

		$published = '';

		if ( preg_match( '#<updated>(.*?)</updated>#s', $entry, $updated ) ) {
			$published = trim( $updated[1] );
		}

		return [
			'body'      => $notes,
			'published' => $published,
		];
	}

	return $empty;
}

/**
 * Turns the rendered notes in the feed back into the plain text the API would have returned.
 *
 * The API hands back the release body as it was written, in Markdown, and the details popup prints
 * that as text. The feed carries the rendered HTML instead, so headings, bold runs and list items
 * are put back into their Markdown spelling to keep the popup reading the same either way.
 *
 * @param string $html Rendered notes.
 * @return string
 */
function tfhp_release_notes_to_text( $html ) {
	$text = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

	$text = preg_replace( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', "\n**$1**\n", $text );
	$text = preg_replace( '#<(strong|b)[^>]*>(.*?)</\1>#is', '**$2**', $text );
	$text = preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '*$2*', $text );
	$text = preg_replace( '#<li[^>]*>#i', "\n- ", $text );
	$text = preg_replace( '#</(p|div|ul|ol|li|pre|blockquote)>#i', "\n", $text );
	$text = preg_replace( '#<br\s*/?>#i', "\n", $text );

	$text = wp_strip_all_tags( (string) $text );

	// Collapse the blank lines the substitutions leave behind.
	$text = preg_replace( '#\n{3,}#', "\n\n", (string) $text );

	return trim( (string) $text );
}

/**
 * Reads the latest release from the GitHub API.
 *
 * Kept as a fallback only. See `tfhp_fetch_release_data()` for why it is not the first choice.
 *
 * @return array<string, mixed>|null
 */
function tfhp_fetch_release_from_api() {

	// GitHub has already said the allowance is spent, so sit the window out rather than spending it
	// on requests that can only be refused.
	if ( get_site_transient( TFHP_UPDATE_RATE_LIMIT_KEY ) ) {
		set_site_transient( TFHP_UPDATE_REASON_KEY, 'rate_limited', HOUR_IN_SECONDS );

		return null;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/' . TFHP_UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,
			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],

			// Our own User-Agent, because WordPress's default is "WordPress/{version}; {site url}"
			// (wp-includes/class-wp-http.php:211) and that puts the site's address and its exact
			// WordPress version into every release check. GitHub only requires that the header
			// identifies something, so this satisfies it while telling them nothing about the site.
			'user-agent' => 'turnstile-for-hivepress/' . TFHP_VERSION,
		]
	);

	if ( is_wp_error( $response ) ) {
		set_site_transient( TFHP_UPDATE_REASON_KEY, 'unreachable', HOUR_IN_SECONDS );

		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		$reason = 404 === $code ? 'no_release' : 'unreachable';

		// A 403 or 429 with nothing left on the counter means this server's hourly allowance is
		// spent. Nothing is wrong with the site, the plugin or the repository, so it must not be
		// reported as though something were.
		if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			$reason = 'rate_limited';
			$reset  = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			$wait   = $reset > time() ? min( $reset - time(), HOUR_IN_SECONDS ) : 5 * MINUTE_IN_SECONDS;

			set_site_transient( TFHP_UPDATE_RATE_LIMIT_KEY, $reset ? $reset : time() + $wait, $wait );
		}

		set_site_transient( TFHP_UPDATE_REASON_KEY, $reason, HOUR_IN_SECONDS );

		return null;
	}

	delete_site_transient( TFHP_UPDATE_RATE_LIMIT_KEY );
	delete_site_transient( TFHP_UPDATE_REASON_KEY );

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Makes a request to github.com.
 *
 * The User-Agent is set for the same reason as in the API branch: WordPress's default would put the
 * site's address and its exact WordPress version into every check.
 *
 * @param string               $url Request URL.
 * @param array<string, mixed> $args Extra request arguments.
 * @return array<string, mixed>|null
 */
function tfhp_request( $url, $args = [] ) {
	$response = wp_remote_get(
		$url,
		array_merge(
			[
				'timeout'    => 10,
				'headers'    => [ 'Accept' => 'text/html, application/xml;q=0.9, */*;q=0.8' ],
				'user-agent' => 'turnstile-for-hivepress/' . TFHP_VERSION,
			],
			$args
		)
	);

	return is_wp_error( $response ) ? null : $response;
}

/**
 * Provides the update details to the WordPress update system.
 *
 * WordPress matches the plugin to this filter via the Update URI header
 * hostname and compares the versions itself, filing the result under either
 * the available updates or the up-to-date list.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function tfhp_check_for_update( $update, $plugin_data, $plugin_file ) {
	if ( plugin_basename( TFHP_FILE ) !== $plugin_file ) {
		return $update;
	}

	$release = tfhp_get_latest_release();

	$details = array(
		'id'     => 'https://github.com/' . TFHP_UPDATE_REPO,
		'slug'   => TFHP_UPDATE_SLUG,
		'plugin' => $plugin_file,
	);

	/*
	 * Answer even when there is nothing to update to. WordPress skips this plugin outright on a falsy
	 * return (wp-includes/update.php:557), and only files an answer under `no_update` when it gets one
	 * (:589-595) -- and that entry is what carries the `slug` the plugins list needs before it will
	 * print "View details" (wp-admin/includes/class-wp-plugins-list-table.php:1204, verified).
	 * Returning false left the row with no slug, so View details, the details popup and the donate link
	 * inside it were all unreachable from the Plugins screen whenever this plugin was up to date, which
	 * is almost always, or whenever the release check failed.
	 */
	if ( ! tfhp_release_usable( $release ) ) {
		$details['version'] = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0';

		return $details;
	}

	return array_merge(
		$details,
		array(
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		)
	);
}
add_filter( 'update_plugins_github.com', 'tfhp_check_for_update', 10, 3 );

/**
 * Provides the plugin details for the update information popup.
 *
 * Without this the "View version x.x.x details" link on the Plugins screen
 * would open an empty modal, since the plugin is not on wordpress.org.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args API arguments.
 * @return object|array|false
 */
function tfhp_get_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || TFHP_UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = tfhp_get_latest_release();

	if ( ! tfhp_release_usable( $release ) ) {
		return $result;
	}

	$plugin_data = get_file_data(
		TFHP_FILE,
		array(
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		)
	);

	return (object) array(
		'name'          => $plugin_data['Name'],
		'slug'          => TFHP_UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => $plugin_data['AuthorURI'] ? '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>' : esc_html( $plugin_data['Author'] ),
		'homepage'      => 'https://github.com/' . TFHP_UPDATE_REPO,
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],

		// WordPress renders this as "Donate to this plugin" in the popup. With
		// no contributors array returned it appears in the sidebar link list
		// (wp-admin/includes/plugin-install.php:705-706).
		'donate_link'   => TFHP_DONATE_URL,
		'sections'      => array(
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'turnstile-for-hivepress' ) . '</p>',
		),
	);
}
add_filter( 'plugins_api', 'tfhp_get_plugin_information', 10, 3 );

/**
 * Adds the manual update check link to the plugin row.
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function tfhp_add_update_check_link( $links ) {
	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?tfhp_check_updates=1' ), 'tfhp_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'turnstile-for-hivepress' ) . '</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( TFHP_FILE ), 'tfhp_add_update_check_link' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( TFHP_FILE ), 'tfhp_add_update_check_link' );

/**
 * Handles the manual update check.
 *
 * Refreshes the cached release, re-runs the update check and redirects back to
 * the Plugins screen with the result.
 *
 * @return void
 */
function tfhp_handle_update_check() {
	if ( ! isset( $_GET['tfhp_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	check_admin_referer( 'tfhp_check_updates' );

	$release = tfhp_get_latest_release( true );

	wp_clean_plugins_cache();
	wp_update_plugins();

	$status = 'none';

	if ( 'rate_limited' === $release['status'] ) {
		$status = 'limited';
	} elseif ( 'unreachable' === $release['status'] ) {
		$status = 'error';
	} elseif ( in_array( $release['status'], array( 'unusable', 'no_release' ), true ) ) {
		$status = 'norelease';
	} elseif ( version_compare( $release['version'], TFHP_VERSION, '>' ) ) {
		$status = 'available';
	}

	wp_safe_redirect( add_query_arg( 'tfhp_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}
add_action( 'admin_init', 'tfhp_handle_update_check' );

/**
 * Shows the manual update check result.
 *
 * @return void
 */
function tfhp_show_update_check_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only status flag set by our own nonce-guarded redirect; nothing is changed here.
	if ( ! isset( $_GET['tfhp_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$status = sanitize_key( wp_unslash( $_GET['tfhp_checked'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'available' === $status ) {
		$release = tfhp_get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Turnstile for HivePress (%s) is available.', 'turnstile-for-hivepress' ), tfhp_release_usable( $release ) ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Turnstile for HivePress is up to date.', 'turnstile-for-hivepress' );
		$class   = 'notice-success';
	} elseif ( 'limited' === $status ) {
		$message = __( 'GitHub limits how many update checks one server may make each hour, and this server has reached that limit. Nothing is wrong with the plugin or your site, and checking will work again within the hour.', 'turnstile-for-hivepress' );
		$class   = 'notice-warning';
	} elseif ( 'norelease' === $status ) {
		$message = __( 'GitHub was reached, but no installable release was found yet, so no update is available.', 'turnstile-for-hivepress' );
		$class   = 'notice-warning';
	} elseif ( 'error' === $status ) {
		$message = __( 'Could not reach GitHub to check for updates. Please try again later.', 'turnstile-for-hivepress' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'tfhp_show_update_check_notice' );
add_action( 'network_admin_notices', 'tfhp_show_update_check_notice' );

/**
 * Keeps updates installing into the current plugin directory.
 *
 * The extracted release folder is renamed to match the directory the plugin is
 * installed in, so an update can never end up in a differently named folder
 * even if the release zip is packaged unexpectedly.
 *
 * @param string               $source Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader Upgrader instance.
 * @param array<string, mixed> $hook_extra Extra hook arguments.
 * @return string|WP_Error
 */
function tfhp_fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;

	if ( plugin_basename( TFHP_FILE ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( TFHP_FILE ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new WP_Error( 'tfhp_rename_failed', __( 'Could not rename the update directory.', 'turnstile-for-hivepress' ) );
	}

	return $target;
}
add_filter( 'upgrader_source_selection', 'tfhp_fix_update_directory', 10, 4 );
