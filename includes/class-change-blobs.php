<?php
/**
 * Durable out-of-band snapshot store for the change ledger.
 *
 * Large rollback before-images (a full Elementor tree, an attachment snapshot,
 * a DB before-image) used to live inline in the single capped `karmcp_changelog`
 * option, so a few big edits evicted older undo points. This class moves those
 * payloads into a dedicated table keyed by a short blob id; the ledger row keeps
 * only the pointer, so the ledger stays light and retention can grow. Blobs are
 * pruned when their ledger entry is evicted and by an age sweep.
 *
 * @package KarMCP
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change-blob store.
 *
 * @since 3.10.0
 */
class KarMCP_Change_Blobs {

	const DB_VERSION = 1;

	/**
	 * The blob table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_change_blobs';
	}

	/**
	 * Wire the install hook (mirrors KarMCP_Search_Index::init()).
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 20 );
	}

	/**
	 * Create/upgrade the blob table when the stored version is behind.
	 */
	public static function maybe_install(): void {
		// Autoloaded schema map, not an own option with autoload off: this runs
		// on init:20 of every request. See KarMCP_Schema_State.
		if ( KarMCP_Schema_State::installed( KarMCP_Schema_State::KEY_CHANGELOG ) >= self::DB_VERSION ) {
			return;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( function_exists( 'dbDelta' ) ) {
			global $wpdb;
			$table   = self::table();
			$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
			$sql     = "CREATE TABLE {$table} (
				blob_id CHAR(20) NOT NULL,
				data LONGTEXT NOT NULL,
				created_at BIGINT NOT NULL,
				PRIMARY KEY (blob_id),
				KEY created_at (created_at)
			) {$charset};";
			dbDelta( $sql );
		}
		KarMCP_Schema_State::mark( KarMCP_Schema_State::KEY_CHANGELOG, self::DB_VERSION );
	}

	/**
	 * Store a snapshot; returns its blob id.
	 *
	 * @param array $data Arbitrary JSON-serializable snapshot.
	 * @return string Blob id ('' on failure).
	 */
	public static function put( array $data ): string {
		global $wpdb;
		$id  = self::uid();
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'blob_id'    => $id,
				'data'       => (string) wp_json_encode( $data ),
				'created_at' => time(),
			),
			array( '%s', '%s', '%d' )
		);
		return $ok ? $id : '';
	}

	/**
	 * Fetch a snapshot by id.
	 *
	 * @param string $id Blob id.
	 * @return array|null Decoded snapshot, or null if missing/invalid.
	 */
	public static function get( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}
		global $wpdb;
		$json = $wpdb->get_var(
			$wpdb->prepare( 'SELECT data FROM %i WHERE blob_id = %s', self::table(), $id )
		);
		if ( null === $json || '' === $json ) {
			return null;
		}
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Delete a blob by id.
	 *
	 * @param string $id Blob id.
	 */
	public static function delete( string $id ): void {
		if ( '' === $id ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( self::table(), array( 'blob_id' => $id ), array( '%s' ) );
	}

	/**
	 * Delete blobs older than a timestamp.
	 *
	 * @param int $ts Unix time; rows with created_at < $ts are removed.
	 * @return int Rows removed.
	 */
	public static function prune_before( int $ts ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', self::table(), $ts )
		);
	}

	/**
	 * Delete every blob NOT referenced by the given id list. An empty list wipes
	 * the table (nothing references any blob).
	 *
	 * @param string[] $referenced_ids Blob ids still referenced by ledger rows.
	 * @return int Rows removed.
	 */
	public static function prune_orphans( array $referenced_ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $referenced_ids ), 'strlen' ) ) );
		if ( empty( $ids ) ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		}
		// The IN list is one %s per id, built from the id COUNT and never from
		// their content; the values themselves go through prepare() below. This
		// is the documented way to prepare a variable-length IN, and the only
		// interpolation left in this file.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		return (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a generated run of %s (see above); the count is 1 + count($ids) on both sides, which the sniff cannot see through the interpolation and the spread.
			$wpdb->prepare( "DELETE FROM %i WHERE blob_id NOT IN ({$placeholders})", self::table(), ...$ids )
		);
	}

	/**
	 * A short unique blob id (20 hex chars).
	 *
	 * @return string
	 */
	private static function uid(): string {
		return substr( md5( uniqid( '', true ) ), 0, 20 );
	}
}
