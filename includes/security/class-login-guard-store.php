<?php
/**
 * Login Guard: persistence.
 *
 * One table for both kinds of event. Failures are what the policy counts;
 * locks are recorded too, because the escalation needs a memory the failures
 * cannot provide — they age out of the counting window long before a long lock
 * expires.
 *
 * Addresses are stored in the clear here, which diverges from the OAuth
 * registration limiter, where the address is hashed. The divergence is
 * deliberate: that one is a blind counter and never needs to name an address,
 * while an administrator looking at a lockout has to see *which* address is
 * blocked in order to decide whether to clear it. The rows are short-lived —
 * the daily GC deletes anything past its usefulness.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login Guard storage.
 *
 * @since 1.4.0
 */
class KarMCP_Login_Guard_Store {

	const GC_HOOK = 'karmcp_login_guard_gc';

	/** Failures older than this are useless to every caller. */
	const RETENTION = 7 * 86400;

	/**
	 * @since 1.4.0
	 * @return string Fully-qualified table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_login_events';
	}

	/**
	 * Creates the table and schedules the daily cleanup. Idempotent — dbDelta
	 * and wp_next_scheduled both no-op when there is nothing to do.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function init(): void {
		self::install_table();

		if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK );
		}
		add_action( self::GC_HOOK, array( __CLASS__, 'gc' ) );
	}

	/**
	 * @since 1.4.0
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
				kind VARCHAR(8) NOT NULL,
				subject_type VARCHAR(8) NOT NULL,
				subject VARCHAR(191) NOT NULL,
				username VARCHAR(191) NOT NULL DEFAULT '',
				created_at BIGINT UNSIGNED NOT NULL,
				expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY subject_lookup (subject_type, subject(64), kind, created_at),
				KEY lock_expiry (kind, expires_at),
				KEY created (created_at)
			) {$charset};"
		);
	}

	/**
	 * Records one failed attempt, against both the address and the username.
	 *
	 * Both, separately and on purpose: counting only by address lets a
	 * distributed attack on one account through, and counting only by username
	 * lets one address sweep the whole user list.
	 *
	 * @since 1.4.0
	 *
	 * @param string $ip       Client address; '' to skip the address row.
	 * @param string $username Attempted username; '' to skip the username row.
	 * @param int    $now      Current timestamp.
	 * @return void
	 */
	public static function record_failure( string $ip, string $username, int $now ): void {
		global $wpdb;
		$table = self::table();

		if ( '' !== $ip ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned table, no core API for it.
				$table,
				array(
					'kind'         => 'fail',
					'subject_type' => 'ip',
					'subject'      => $ip,
					'username'     => mb_substr( $username, 0, 191 ),
					'created_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}
		if ( '' !== $username ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'kind'         => 'fail',
					'subject_type' => 'user',
					'subject'      => mb_substr( $username, 0, 191 ),
					'username'     => mb_substr( $username, 0, 191 ),
					'created_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * Records a lock.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type     'ip' | 'user'.
	 * @param string $subject  Address or username.
	 * @param string $username Attempted username, for display.
	 * @param int    $now      Current timestamp.
	 * @param int    $until    When the lock expires.
	 * @return void
	 */
	public static function record_lock( string $type, string $subject, string $username, int $now, int $until ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'kind'         => 'lock',
				'subject_type' => $type,
				'subject'      => mb_substr( $subject, 0, 191 ),
				'username'     => mb_substr( $username, 0, 191 ),
				'created_at'   => $now,
				'expires_at'   => $until,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Failure timestamps for a subject since a cutoff.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type  'ip' | 'user'.
	 * @param string $subject Address or username.
	 * @param int    $since Cutoff timestamp.
	 * @return int[]
	 */
	public static function failures_since( string $type, string $subject, int $since ): array {
		global $wpdb;
		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT created_at FROM %i WHERE kind = %s AND subject_type = %s AND subject = %s AND created_at > %d',
				self::table(),
				'fail',
				$type,
				$subject,
				$since
			)
		);
		return array_map( 'intval', (array) $rows );
	}

	/**
	 * How many locks this subject has already served.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type    'ip' | 'user'.
	 * @param string $subject Address or username.
	 * @param int    $since   Cutoff timestamp; older locks are forgiven.
	 * @return int
	 */
	public static function prior_lockouts( string $type, string $subject, int $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE kind = %s AND subject_type = %s AND subject = %s AND created_at > %d',
				self::table(),
				'lock',
				$type,
				$subject,
				$since
			)
		);
	}

	/**
	 * The expiry of an active lock, or 0.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type    'ip' | 'user'.
	 * @param string $subject Address or username.
	 * @param int    $now     Current timestamp.
	 * @return int
	 */
	public static function active_lock_until( string $type, string $subject, int $now ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT MAX(expires_at) FROM %i WHERE kind = %s AND subject_type = %s AND subject = %s AND expires_at > %d',
				self::table(),
				'lock',
				$type,
				$subject,
				$now
			)
		);
	}

	/**
	 * Currently-active locks, newest first.
	 *
	 * @since 1.4.0
	 *
	 * @param int $now   Current timestamp.
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function active_locks( int $now, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT subject_type, subject, username, created_at, expires_at FROM %i'
				. ' WHERE kind = %s AND expires_at > %d ORDER BY expires_at DESC LIMIT %d',
				self::table(),
				'lock',
				$now,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Clears every failure and lock for one subject. This is the escape hatch,
	 * so it removes the escalation history too: after a manual unblock the
	 * subject starts from zero rather than from a doubled lock.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type    'ip' | 'user'.
	 * @param string $subject Address or username.
	 * @return int Rows removed.
	 */
	public static function clear( string $type, string $subject ): int {
		global $wpdb;
		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'subject_type' => $type,
				'subject'      => $subject,
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Clears the failure rows for a subject without touching the lock history.
	 * Used on a successful sign-in: the slate is wiped, the escalation memory
	 * is not, so alternating one success with four failures cannot farm free
	 * attempts forever.
	 *
	 * @since 1.4.0
	 *
	 * @param string $type    'ip' | 'user'.
	 * @param string $subject Address or username.
	 * @return void
	 */
	public static function clear_failures( string $type, string $subject ): void {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'kind'         => 'fail',
				'subject_type' => $type,
				'subject'      => $subject,
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Daily cleanup. Drops anything past the retention window; an expired lock
	 * is kept until then because it is the escalation history.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public static function gc(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %d',
				self::table(),
				time() - self::RETENTION
			)
		);
	}
}
