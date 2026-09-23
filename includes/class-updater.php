<?php
/**
 * Update checks against the plugin's own GitHub releases.
 *
 * WordPress only knows how to ask wordpress.org. The `Update URI` header names
 * a host instead, and WordPress then asks that host's filter what it has —
 * `update_plugins_github.com` here. No transient is hijacked and no other
 * plugin's update is touched: the filter fires per plugin, and this one answers
 * only for its own file.
 *
 * What it answers with is deliberately narrow. The release's tag has to read as
 * a version, the download has to be a `.zip` asset served by github.com, and the
 * result is only returned when it is newer than what is installed. Anything else
 * — a draft, a pre-release, a release with no asset, a body that is not the JSON
 * this expects — is no update rather than a guess, because the value returned
 * here is the URL WordPress will download and unpack over the live plugin.
 *
 * @package KarMCP
 * @since   1.43.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub release updater.
 *
 * @since 1.43.0
 */
class KarMCP_Updater {

	/** The repository the releases come from. */
	const REPO = 'karcox/karmcp';

	/** Where the latest published release is described. */
	const API = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

	/** Cache key for the last answer, good or bad. */
	const TRANSIENT = 'karmcp_latest_release';

	/** How long a successful lookup is reused. */
	const TTL = 21600; // 6 hours.

	/** How long a failed lookup is remembered, so a broken network is not retried on every page load. */
	const TTL_FAILURE = 1800; // 30 minutes.

	/** Hosts a download may come from. */
	const DOWNLOAD_HOSTS = array( 'github.com', 'objects.githubusercontent.com' );

	/**
	 * Answer WordPress's update question for this plugin.
	 *
	 * Hooked on `update_plugins_github.com`, which fires for every plugin whose
	 * Update URI points at GitHub — hence the file check before anything else.
	 *
	 * @param array|false $update      What another handler already decided.
	 * @param array       $plugin_data The plugin's headers.
	 * @param string      $plugin_file The plugin's basename.
	 * @return array|false
	 */
	public static function check( $update, array $plugin_data, string $plugin_file ) {
		if ( KARMCP_BASENAME !== $plugin_file || ! self::enabled() ) {
			return $update;
		}
		$release = self::latest_release();
		if ( null === $release ) {
			return $update;
		}
		$response = self::response_from_release( $release, (string) ( $plugin_data['Version'] ?? KARMCP_VERSION ), $plugin_file, $plugin_data );
		return null === $response ? $update : $response;
	}

	/**
	 * Answer the "View details" screen for this plugin.
	 *
	 * Without this, the details link on the Plugins screen goes to
	 * wordpress.org and errors: the slug in the update response is what makes
	 * WordPress build that link, and wordpress.org has never heard of a plugin
	 * that was never published there. The release notes become the changelog.
	 *
	 * @param false|object|array $result The result another handler decided.
	 * @param string             $action The plugins_api action.
	 * @param object             $args   Its arguments.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, string $action, $args ) {
		$slug = dirname( KARMCP_BASENAME );
		if ( 'plugin_information' !== $action || $slug !== (string) ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::enabled() ? self::latest_release() : null;
		$version = '';
		if ( is_array( $release ) ) {
			$version = ltrim( trim( (string) ( $release['tag_name'] ?? '' ) ), 'vV' );
		}

		return (object) array(
			'name'          => 'KarMCP',
			'slug'          => $slug,
			'version'       => '' !== $version ? $version : KARMCP_VERSION,
			'author'        => '<a href="https://github.com/karcox">karcox</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.9',
			'requires_php'  => '8.1',
			'last_updated'  => (string) ( $release['published_at'] ?? '' ),
			'download_link' => is_array( $release ) ? self::package_url( (array) ( $release['assets'] ?? array() ) ) : '',
			'sections'      => array(
				'description' => esc_html__( 'Exposes this WordPress site as MCP tools, so an AI agent can build Elementor pages, manage content and audit the site.', 'karmcp' ),
				'changelog'   => is_array( $release ) ? wp_kses_post( wpautop( (string) ( $release['body'] ?? '' ) ) ) : '',
			),
		);
	}

	/**
	 * Whether update checking is on.
	 *
	 * The constant is for a site that must not reach out at all; the filter is
	 * for everything else.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$enabled = ! ( defined( 'KARMCP_NO_UPDATE_CHECK' ) && KARMCP_NO_UPDATE_CHECK );
		/**
		 * Filters whether KarMCP checks GitHub for a newer release.
		 *
		 * @since 1.43.0
		 *
		 * @param bool $enabled Whether to check.
		 */
		return (bool) apply_filters( 'karmcp_update_check_enabled', $enabled );
	}

	/**
	 * The latest published release, from cache when possible.
	 *
	 * A failed lookup is cached too: without that, a site that cannot reach
	 * GitHub would make the request again on every admin page load.
	 *
	 * @return array|null
	 */
	public static function latest_release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$response = wp_remote_get(
			self::API,
			array(
				'timeout'    => 10,
				'user-agent' => 'KarMCP/' . KARMCP_VERSION . '; ' . home_url( '/' ),
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
			)
		);
		$body    = ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) )
			? json_decode( (string) wp_remote_retrieve_body( $response ), true )
			: null;

		if ( ! is_array( $body ) ) {
			set_site_transient( self::TRANSIENT, 'none', self::TTL_FAILURE );
			return null;
		}
		set_site_transient( self::TRANSIENT, $body, self::TTL );
		return $body;
	}

	/**
	 * Whether the last lookup failed rather than finding nothing.
	 *
	 * The two look identical from outside — no update either way — so the
	 * dashboard can say which it was instead of implying the site is current.
	 *
	 * @return bool
	 */
	public static function last_check_failed(): bool {
		return 'none' === get_site_transient( self::TRANSIENT );
	}

	/**
	 * Forget the cached release, so the next check asks GitHub again.
	 */
	public static function flush(): void {
		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Turn a release into the update WordPress expects, or null when the release
	 * is not one this site should install.
	 *
	 * Pure: everything it judges comes from its arguments, which is what lets
	 * each refusal be tested without a network or a WordPress.
	 *
	 * @param array  $release     The release, as the GitHub API describes it.
	 * @param string $current     The installed version.
	 * @param string $plugin_file The plugin's basename.
	 * @param array  $plugin_data The plugin's headers, for the requirements to
	 *                            quote. Retyping them here would give WordPress
	 *                            a second copy to disagree with the first.
	 * @return array|null
	 */
	public static function response_from_release( array $release, string $current, string $plugin_file, array $plugin_data = array() ): ?array {
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( trim( (string) ( $release['tag_name'] ?? '' ) ), 'vV' );
		// Digits and dots only. A tag that is not a version at all would compare
		// as 0 and look like an update to every site, and a suffix like
		// `-beta` compares as newer than the release it follows — so a tag that
		// means "not for everyone" would ship to everyone if the pre-release
		// box was left unticked. Two ways to say the same thing, and only one
		// of them is a checkbox someone has to remember.
		if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return null;
		}
		if ( '' === $current || version_compare( $version, $current, '<=' ) ) {
			return null;
		}
		$package = self::package_url( (array) ( $release['assets'] ?? array() ) );
		if ( '' === $package ) {
			return null;
		}
		$out = array(
			'id'      => 'github.com/' . self::REPO,
			'slug'    => dirname( $plugin_file ),
			'plugin'  => $plugin_file,
			'version' => $version,
			'url'     => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPO . '/releases' ),
			'package' => $package,
		);
		foreach ( array( 'requires' => 'RequiresWP', 'requires_php' => 'RequiresPHP' ) as $key => $header ) {
			if ( '' !== (string) ( $plugin_data[ $header ] ?? '' ) ) {
				$out[ $key ] = (string) $plugin_data[ $header ];
			}
		}
		return $out;
	}

	/**
	 * The download URL of the release's plugin ZIP, '' when there is none worth
	 * handing to WordPress.
	 *
	 * WordPress downloads and unpacks whatever comes back over the installed
	 * plugin, so the host is checked rather than assumed: a release body is
	 * fetched over the network, and the only reason to trust it is that it
	 * came from GitHub and points back at GitHub.
	 *
	 * @param array $assets The release's assets.
	 * @return string
	 */
	public static function package_url( array $assets ): string {
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$url = (string) ( $asset['browser_download_url'] ?? '' );
			if ( '.zip' !== strtolower( substr( (string) ( $asset['name'] ?? '' ), -4 ) ) ) {
				continue;
			}
			$parts = wp_parse_url( $url );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && in_array( $host, self::DOWNLOAD_HOSTS, true ) ) {
				return $url;
			}
		}
		return '';
	}
}
