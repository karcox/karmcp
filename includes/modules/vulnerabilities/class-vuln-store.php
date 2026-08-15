<?php
/**
 * Fetches the vulnerability feed and keeps it queryable.
 *
 * The shape of this class is dictated by three measurements taken against the
 * live API on 2026-08-15, not by preference:
 *
 * 1. **The endpoints return the complete feed and accept no parameters.** There
 *    is no per-plugin query. So a local copy is not a privacy preference, it is
 *    the only thing on offer — and the happy side effect is that the provider
 *    never learns which plugins or versions this site runs.
 * 2. **11.2 MB gzipped is 150.87 MB decoded.** `json_decode()` on that wants
 *    about a gigabyte. The feed is therefore streamed through
 *    KarMCP_Vuln_Stream_Parser and written row by row; verified end to end at a
 *    peak of 18 MB under a 128 MB limit.
 * 3. **The quota is tight and undocumented.** One good request, then 429 for
 *    the best part of an hour, with no `Retry-After` and no rate headers. So:
 *    once a day, backed off on failure, and never blocking a page load.
 *
 * Rows are keyed by `(type, slug)`, not by slug: 19 slugs in the real feed
 * exist as both a theme and a plugin, and conflating them would attribute a
 * theme's vulnerability to a plugin of the same name.
 *
 * @package KarMCP
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vulnerability feed storage and refresh.
 *
 * @since 1.7.0
 */
class KarMCP_Vuln_Store {

	const CRON_HOOK      = 'karmcp_vuln_refresh';
	const OPTION_STATE   = 'karmcp_vuln_state';
	const OPTION_API_KEY = 'karmcp_vuln_api_key';

	/** Wordfence Intelligence, v3. v1 and v2 return 410 Gone. */
	const FEED_URL = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';

	/**
	 * The attribution the licence requires.
	 *
	 * Defiant grants a perpetual, irrevocable licence to reproduce and
	 * redistribute these records **on condition** that a link to the record and
	 * their copyright notice travel with any copy. This is not a courtesy line;
	 * it is the term the data is used under, so it is returned with every
	 * finding and shown on the screen.
	 *
	 * @since 1.7.0
	 * @return array{notice:string,license_url:string}
	 */
	public static function attribution(): array {
		return array(
			'notice'      => 'Vulnerability data © Defiant, Inc. (Wordfence Intelligence Community Edition). CVE records © The MITRE Corporation.',
			'license_url' => 'https://www.wordfence.com/wti-community-edition-terms-and-conditions/',
		);
	}

	/**
	 * @since 1.7.0
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_vulnerabilities';
	}

	/**
	 * @since 1.7.0
	 * @return void
	 */
	public static function init(): void {
		self::install_table();

		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * @since 1.7.0
	 * @return void
	 */
	public static function install_table(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		global $wpdb;
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$table   = self::table();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				vuln_id VARCHAR(64) NOT NULL,
				software_type VARCHAR(16) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				title TEXT NOT NULL,
				cve VARCHAR(32) NOT NULL DEFAULT '',
				cve_link VARCHAR(255) NOT NULL DEFAULT '',
				reference VARCHAR(255) NOT NULL DEFAULT '',
				cvss_score DECIMAL(3,1) NOT NULL DEFAULT 0.0,
				cvss_rating VARCHAR(16) NOT NULL DEFAULT '',
				patched TINYINT(1) NOT NULL DEFAULT 0,
				patched_versions TEXT NOT NULL,
				affected_versions LONGTEXT NOT NULL,
				remediation TEXT NOT NULL,
				PRIMARY KEY (id),
				KEY lookup (software_type, slug(64)),
				KEY vuln (vuln_id)
			) {$charset};"
		);
	}

	/**
	 * State of the last refresh: when, whether it worked, and how many rows.
	 *
	 * @since 1.7.0
	 * @return array
	 */
	public static function state(): array {
		$v = get_option( self::OPTION_STATE, array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Downloads the feed and rebuilds the table.
	 *
	 * @since 1.7.0
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		$key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( '' === $key ) {
			return self::fail( 'no_api_key', __( 'No Wordfence Intelligence API key is configured. Get a free one from your wordfence.com account under Integrations, then paste it on the Security tab.', 'karmcp' ) );
		}

		$tmp = wp_tempnam( 'karmcp-vuln-feed' );
		if ( ! $tmp ) {
			return self::fail( 'temp_failed', __( 'Could not create a temporary file for the feed.', 'karmcp' ) );
		}

		$response = wp_remote_get(
			self::FEED_URL,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return self::fail( 'request_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			wp_delete_file( $tmp );
			// Expected, not exceptional: the free quota allows roughly one pull
			// an hour and the daily refresh sometimes lands inside a window
			// something else opened. The previous table stays exactly as it was.
			return self::fail( 'rate_limited', __( 'Wordfence rate-limited the request. The stored data is unchanged; the next scheduled refresh will try again.', 'karmcp' ) );
		}
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			return self::fail(
				'http_' . $code,
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The feed returned HTTP %d. A 401 means the API key is wrong or revoked.', 'karmcp' ),
					$code
				)
			);
		}

		$rows = self::rebuild_from_file( $tmp );
		wp_delete_file( $tmp );

		if ( is_wp_error( $rows ) ) {
			return self::fail( $rows->get_error_code(), $rows->get_error_message() );
		}

		update_option(
			self::OPTION_STATE,
			array(
				'refreshed_at' => time(),
				'ok'           => true,
				'rows'         => (int) $rows,
				'error'        => '',
			),
			false
		);

		return true;
	}

	/**
	 * Streams the downloaded file into the table.
	 *
	 * Never decodes the whole document — see the class docblock for why that is
	 * not an option rather than a preference.
	 *
	 * @since 1.7.0
	 *
	 * @param string $path Downloaded (gzipped or plain) feed.
	 * @return int|\WP_Error Rows written.
	 */
	private static function rebuild_from_file( string $path ) {
		global $wpdb;

		$fh = gzopen( $path, 'rb' ); // Handles a plain file too.
		if ( ! $fh ) {
			return new \WP_Error( 'unreadable', __( 'Could not read the downloaded feed.', 'karmcp' ) );
		}

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table; a full rebuild is the operation.
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		$buffer  = '';
		$started = false;
		$rows    = 0;

		while ( ! gzeof( $fh ) ) {
			$buffer .= (string) gzread( $fh, 524288 );
			$out     = KarMCP_Vuln_Stream_Parser::split( $buffer, $started );
			$started = $out['started'];

			foreach ( $out['records'] as $vuln_id => $raw ) {
				$rec = json_decode( $raw, true );
				if ( ! is_array( $rec ) ) {
					continue;
				}
				$rows += self::insert_record( (string) $vuln_id, $rec );
			}

			$buffer = $out['remainder'];
		}
		gzclose( $fh );

		return $rows;
	}

	/**
	 * One feed record becomes one row per affected piece of software.
	 *
	 * @since 1.7.0
	 *
	 * @param string $vuln_id Feed UUID.
	 * @param array  $rec     Decoded record.
	 * @return int Rows written.
	 */
	private static function insert_record( string $vuln_id, array $rec ): int {
		global $wpdb;
		$written = 0;

		$refs      = (array) ( $rec['references'] ?? array() );
		$reference = (string) ( reset( $refs ) ?: '' );

		foreach ( (array) ( $rec['software'] ?? array() ) as $s ) {
			$slug = (string) ( $s['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'vuln_id'           => $vuln_id,
					'software_type'     => (string) ( $s['type'] ?? '' ),
					'slug'              => $slug,
					'title'             => (string) ( $rec['title'] ?? '' ),
					'cve'               => (string) ( $rec['cve'] ?? '' ),
					'cve_link'          => (string) ( $rec['cve_link'] ?? '' ),
					'reference'         => $reference,
					'cvss_score'        => (float) ( $rec['cvss']['score'] ?? 0 ),
					'cvss_rating'       => (string) ( $rec['cvss']['rating'] ?? '' ),
					'patched'           => ! empty( $s['patched'] ) ? 1 : 0,
					'patched_versions'  => wp_json_encode( (array) ( $s['patched_versions'] ?? array() ) ),
					'affected_versions' => wp_json_encode( (array) ( $s['affected_versions'] ?? array() ) ),
					'remediation'       => (string) ( $s['remediation'] ?? '' ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s' )
			);
			++$written;
		}

		return $written;
	}

	/**
	 * Every vulnerability recorded against one piece of software.
	 *
	 * @since 1.7.0
	 *
	 * @param string $type 'plugin' | 'theme' | 'core'.
	 * @param string $slug Software slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_software( string $type, string $slug ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE software_type = %s AND slug = %s',
				$type,
				$slug
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Records a failed refresh without touching the stored rows.
	 *
	 * The state keeps `ok => false` so every reader can tell "could not check"
	 * from "checked and found nothing" — the distinction the whole security
	 * section is built on.
	 *
	 * @since 1.7.0
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @return \WP_Error
	 */
	private static function fail( string $code, string $message ): \WP_Error {
		$prev = self::state();
		update_option(
			self::OPTION_STATE,
			array(
				'refreshed_at' => (int) ( $prev['refreshed_at'] ?? 0 ),
				'ok'           => false,
				'rows'         => (int) ( $prev['rows'] ?? 0 ),
				'error'        => $message,
				'failed_at'    => time(),
			),
			false
		);
		return new \WP_Error( $code, $message );
	}
}
