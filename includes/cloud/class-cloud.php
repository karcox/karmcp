<?php
/**
 * KarMCP Cloud config + encrypted connection store.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers for the Cloud base URL, the stable per-site UUID, and the
 * encrypted OAuth token bundle. No network here.
 */
class KarMCP_Cloud {
	const OPTION_CONNECTION = 'karmcp_cloud_connection';
	const OPTION_BASE_URL   = 'karmcp_cloud_base_url';
	const OPTION_SITE_UUID  = 'karmcp_site_uuid';
	/**
	 * Cloud base URL default.
	 *
	 * Upstream pointed this at its own hosted service. KarMCP has no such
	 * service, and leaving a live host here would mean the plugin could make
	 * outbound calls to a third party. Empty = Cloud is off.
	 *
	 * To enable a Cloud backend later, set it per-site without touching this
	 * file: define `KARMCP_CLOUD_URL`, store the `karmcp_cloud_base_url`
	 * option, or hook the `karmcp_cloud_base_url` filter — see base_url().
	 */
	const DEFAULT_BASE_URL  = '';
	const SCOPES            = 'openid cloud offline_access';

	/**
	 * The KarMCP Cloud base URL. Constant overrides option overrides default;
	 * filterable for staging/self-host.
	 *
	 * @return string No trailing slash.
	 */
	public static function base_url(): string {
		if ( defined( 'KARMCP_CLOUD_URL' ) && '' !== (string) KARMCP_CLOUD_URL ) {
			$url = (string) KARMCP_CLOUD_URL;
		} else {
			$stored = (string) get_option( self::OPTION_BASE_URL, '' );
			$url    = '' !== $stored ? $stored : self::DEFAULT_BASE_URL;
		}
		return rtrim( (string) apply_filters( 'karmcp_cloud_base_url', $url ), '/' );
	}

	/**
	 * The stable per-site UUID, minted lazily on first read.
	 *
	 * @return string
	 */
	public static function site_uuid(): string {
		$uuid = (string) get_option( self::OPTION_SITE_UUID, '' );
		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( self::OPTION_SITE_UUID, $uuid, false );
		}
		return $uuid;
	}

	/**
	 * Persist the token bundle, encrypted at rest.
	 *
	 * @param array $bundle { access_token, refresh_token, access_expires_at, client_id, ... }.
	 * @return void
	 */
	public static function save_connection( array $bundle ): void {
		update_option( self::OPTION_CONNECTION, KarMCP_Secret::encrypt( (string) wp_json_encode( $bundle ) ), false );
	}

	/**
	 * Read the decrypted token bundle (empty array when not connected).
	 *
	 * @return array
	 */
	public static function get_connection(): array {
		$raw = (string) get_option( self::OPTION_CONNECTION, '' );
		if ( '' === $raw ) {
			return array();
		}
		$json = json_decode( KarMCP_Secret::decrypt_if_needed( $raw ), true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * @return void
	 */
	public static function clear_connection(): void {
		delete_option( self::OPTION_CONNECTION );
	}

	/**
	 * @return bool
	 */
	public static function is_connected(): bool {
		$c = self::get_connection();
		return ! empty( $c['access_token'] ) || ! empty( $c['refresh_token'] );
	}

	/**
	 * Connection status for the admin card.
	 *
	 * @return array { connected:bool, base_url:string, expires_at:int, healthy:bool }.
	 */
	public static function status(): array {
		$c = self::get_connection();
		return array(
			'connected'  => self::is_connected(),
			'base_url'   => self::base_url(),
			'expires_at' => (int) ( $c['access_expires_at'] ?? 0 ),
			'healthy'    => empty( $c['unhealthy'] ),
		);
	}
}
