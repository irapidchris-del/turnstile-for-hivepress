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
 * Gets the latest GitHub release details, cached for 6 hours.
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null
 */
function tfhp_get_latest_release( $force = false ) {
	$release = $force ? false : get_site_transient( TFHP_UPDATE_CACHE_KEY );

	if ( ! is_array( $release ) ) {
		$release = tfhp_fetch_latest_release();

		// Failures are cached briefly so the API is not queried repeatedly.
		set_site_transient( TFHP_UPDATE_CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	return $release ? $release : null;
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
	$response = wp_remote_get(
		'https://api.github.com/repos/' . TFHP_UPDATE_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return array();
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return array();
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return array();
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return array();
	}

	return array(
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . TFHP_UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	);
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

	if ( ! $release ) {
		return $update;
	}

	return array(
		'id'      => 'https://github.com/' . TFHP_UPDATE_REPO,
		'slug'    => TFHP_UPDATE_SLUG,
		'plugin'  => $plugin_file,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
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

	if ( ! $release ) {
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

	if ( ! $release ) {
		$status = 'error';
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
	if ( ! isset( $_GET['tfhp_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$status = sanitize_key( wp_unslash( $_GET['tfhp_checked'] ) );

	if ( 'available' === $status ) {
		$release = tfhp_get_latest_release();

		/* translators: %s: new version number. */
		$message = sprintf( __( 'A new version of Turnstile for HivePress (%s) is available.', 'turnstile-for-hivepress' ), $release ? $release['version'] : '' );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = __( 'Turnstile for HivePress is up to date.', 'turnstile-for-hivepress' );
		$class   = 'notice-success';
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
