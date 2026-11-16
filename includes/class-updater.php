<?php
/**
 * Plugin updates delivered from GitHub Releases.
 *
 * WordPress routes update checks for this plugin to the
 * `update_plugins_github.com` filter because of the `Update URI` plugin header.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCSM_Updater {
	/**
	 * GitHub repository that publishes releases, as owner/name.
	 */
	const REPOSITORY = 'MediaComp/MCSM';

	/**
	 * Release asset that contains the installable plugin package.
	 */
	const ASSET_NAME = 'mcsm.zip';

	/**
	 * Site transient holding the latest release details.
	 */
	const CACHE_KEY = 'mcsm_github_release';

	/**
	 * Seconds to keep a successful release lookup.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Seconds to wait before retrying a failed release lookup.
	 */
	const ERROR_TTL = 600;

	/**
	 * Register update hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_for_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_package' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_directory' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Report the latest GitHub release to the WordPress update system.
	 *
	 * @param array|false $update Update data from earlier filters.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file relative to the plugins directory.
	 * @return array|false
	 */
	public static function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( self::plugin_basename() !== $plugin_file ) {
			return $update;
		}

		// "Check again" on the Updates screen should not be answered from cache.
		if ( is_admin() && isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::clear_cache();
		}

		$release = self::get_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'slug'         => self::slug(),
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => self::package_url( $release ),
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Supply the "View details" dialog with release information.
	 *
	 * @param false|object|array $result Result from earlier filters.
	 * @param string             $action Requested API action.
	 * @param object             $args Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = self::get_release();
		if ( ! $release ) {
			return $result;
		}

		$notes = '' !== trim( $release['notes'] )
			? wpautop( esc_html( $release['notes'] ) )
			: '<p>' . esc_html__( 'No release notes were published for this version.', 'mcsm' ) . '</p>';

		return (object) array(
			'name'          => 'MCSM',
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'author'        => 'Media Components LLC',
			'homepage'      => $release['url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published_at'],
			'download_link' => self::package_url( $release ),
			'sections'      => array(
				'changelog' => $notes,
			),
		);
	}

	/**
	 * Download the release asset with the access token when one is configured.
	 *
	 * GitHub answers an authenticated asset request with a redirect to a
	 * short-lived signed URL. The token is sent only to api.github.com; the
	 * signed URL is then fetched without it.
	 *
	 * @param bool|WP_Error|string $reply Result from earlier filters.
	 * @param string               $package Package URL.
	 * @return bool|WP_Error|string Path to the downloaded file, an error, or the untouched reply.
	 */
	public static function download_package( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::asset_api_base() ) ) {
			return $reply;
		}

		if ( '' === self::token() ) {
			return new WP_Error( 'mcsm_updater_token', __( 'MCSM_GITHUB_TOKEN must be defined in wp-config.php to download this update.', 'mcsm' ) );
		}

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => 'MCSM-Updater',
				'headers'     => self::api_headers( 'application/octet-stream' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( $code >= 300 && $code < 400 && is_string( $location ) && 0 === strpos( $location, 'https://' ) ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			return download_url( $location, 300 );
		}

		return new WP_Error(
			'mcsm_updater_download',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'GitHub refused the update package download (HTTP %d). Check that MCSM_GITHUB_TOKEN is valid and can read the repository.', 'mcsm' ),
				$code
			)
		);
	}

	/**
	 * Keep the installed directory name when the package unpacks under another one.
	 *
	 * @param string|WP_Error $source Unpacked package directory.
	 * @param string          $remote_source Parent of the unpacked directory.
	 * @param WP_Upgrader     $upgrader Upgrader instance.
	 * @param array           $hook_extra Extra arguments for the upgrade.
	 * @return string|WP_Error
	 */
	public static function fix_source_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== self::plugin_basename() ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . self::slug();
		if ( untrailingslashit( $source ) === $expected ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $expected, true ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error( 'mcsm_updater_rename', __( 'The MCSM update package could not be prepared for installation.', 'mcsm' ) );
	}

	/**
	 * Forget the cached release so the next check asks GitHub again.
	 */
	public static function clear_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Get the latest release, from cache when available.
	 *
	 * @return array|null
	 */
	private static function get_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$release = self::request_release();
		set_site_transient( self::CACHE_KEY, $release ? $release : array(), $release ? self::CACHE_TTL : self::ERROR_TTL );

		return $release;
	}

	/**
	 * Ask GitHub for the latest published release.
	 *
	 * Drafts and pre-releases are never returned by this endpoint. A release
	 * without the packaged asset is ignored so sites never install GitHub's
	 * automatic source archive.
	 *
	 * @return array|null
	 */
	private static function request_release() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest',
			array(
				'timeout'    => 10,
				'user-agent' => 'MCSM-Updater',
				'headers'    => self::api_headers( 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['tag_name'] ) || ! is_string( $data['tag_name'] ) ) {
			return null;
		}

		$version = ltrim( $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}(-[0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}

		$download_prefix = 'https://github.com/' . self::REPOSITORY . '/releases/download/';
		$assets          = isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || self::ASSET_NAME !== ( $asset['name'] ?? '' ) || 'uploaded' !== ( $asset['state'] ?? '' ) ) {
				continue;
			}

			$asset_id     = absint( $asset['id'] ?? 0 );
			$download_url = is_string( $asset['browser_download_url'] ?? null ) ? $asset['browser_download_url'] : '';
			if ( ! $asset_id || 0 !== strpos( $download_url, $download_prefix ) ) {
				continue;
			}

			return array(
				'version'      => $version,
				'asset_id'     => $asset_id,
				'download_url' => esc_url_raw( $download_url ),
				'url'          => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . rawurlencode( $data['tag_name'] ),
				'notes'        => is_string( $data['body'] ?? null ) ? $data['body'] : '',
				'published_at' => is_string( $data['published_at'] ?? null ) ? $data['published_at'] : '',
			);
		}

		return null;
	}

	/**
	 * Package URL handed to WordPress.
	 *
	 * With a token the API asset URL is used and fetched by download_package();
	 * the token itself is never stored in the update data.
	 *
	 * @param array $release Release details.
	 * @return string
	 */
	private static function package_url( $release ) {
		return '' !== self::token()
			? self::asset_api_base() . absint( $release['asset_id'] )
			: $release['download_url'];
	}

	/**
	 * Base URL of the release asset API for this repository.
	 *
	 * @return string
	 */
	private static function asset_api_base() {
		return 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/assets/';
	}

	/**
	 * Request headers for the GitHub API.
	 *
	 * @param string $accept Accept header value.
	 * @return array
	 */
	private static function api_headers( $accept ) {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}

		return $headers;
	}

	/**
	 * Access token from wp-config.php, required only for a private repository.
	 *
	 * @return string
	 */
	private static function token() {
		return defined( 'MCSM_GITHUB_TOKEN' ) && is_string( MCSM_GITHUB_TOKEN ) ? trim( MCSM_GITHUB_TOKEN ) : '';
	}

	/**
	 * Plugin file relative to the plugins directory.
	 *
	 * @return string
	 */
	private static function plugin_basename() {
		return plugin_basename( MCSM_PLUGIN_FILE );
	}

	/**
	 * Installed plugin directory name.
	 *
	 * @return string
	 */
	private static function slug() {
		return dirname( self::plugin_basename() );
	}
}
