<?php
/**
 * Redirect store — the {prefix}karmcp_redirects table plus CRUD, path
 * normalization, loop guarding, target resolution, and the ledger-rollback
 * applier for the `redirect-row` change type.
 *
 * Follows the KarMCP_Search_Index storage pattern (version-gated dbDelta, a
 * DB_VERSION const checked against KarMCP_Schema_State, maybe_install() on
 * init). The pure methods
 * (normalize_path/would_loop/resolve_target and create()'s validation) are
 * DB-free so they unit-test without a database; persistence is exercised by the
 * live smoke test.
 *
 * @package KarMCP
 * @since   3.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirect store.
 *
 * @since 3.11.0
 */
class KarMCP_Redirect_Store {

	const DB_VERSION     = 1;
	const MAX_SOURCE_LEN = 191;

	/**
	 * The redirects table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_redirects';
	}

	/**
	 * Register the install hook.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 20 );
	}

	/**
	 * Create/upgrade the table when the stored version is behind.
	 */
	public static function maybe_install(): void {
		// Autoloaded schema map, not an own option with autoload off: this runs
		// on init:20 of every request while the module is on. A per-store key
		// (rather than one plugin-version stamp) is what lets the table still
		// install when the module is switched on later. See KarMCP_Schema_State.
		if ( KarMCP_Schema_State::installed( KarMCP_Schema_State::KEY_REDIRECTS ) >= self::DB_VERSION ) {
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
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_path VARCHAR(191) NOT NULL,
				target TEXT NOT NULL,
				target_post_id BIGINT UNSIGNED NULL,
				status_code SMALLINT NOT NULL DEFAULT 301,
				match_type VARCHAR(20) NOT NULL DEFAULT 'exact',
				ignore_query TINYINT(1) NOT NULL DEFAULT 1,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_hit DATETIME NULL,
				notes TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_unique (source_path),
				KEY enabled_idx (enabled)
			) {$charset};";
			dbDelta( $sql );
		}
		KarMCP_Schema_State::mark( KarMCP_Schema_State::KEY_REDIRECTS, self::DB_VERSION );
	}

	// ---------------------------------------------------------------------
	// Pure helpers (DB-free)
	// ---------------------------------------------------------------------

	/**
	 * Normalize a URL or path to a comparable, home-relative source path:
	 * scheme+host stripped, home-path prefix removed (subdirectory installs),
	 * decoded, lowercased, duplicate slashes collapsed, single leading slash,
	 * trailing slash + query dropped. Root stays '/'.
	 *
	 * @param string $url_or_path A full URL or a path.
	 * @return string
	 */
	public static function normalize_path( string $url_or_path ): string {
		$s = trim( $url_or_path );
		if ( '' === $s ) {
			return '/';
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $s ) : parse_url( $s ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path  = ( is_array( $parts ) && isset( $parts['path'] ) ) ? (string) $parts['path'] : $s;
		if ( function_exists( 'home_url' ) ) {
			$home = parse_url( home_url( '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
				$path = substr( $path, strlen( rtrim( $home, '/' ) ) );
			}
		}
		$path = rawurldecode( $path );
		$path = strtolower( $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = '/' . ltrim( (string) $path, '/' );
		$path = rtrim( $path, '/' );
		return '' === $path ? '/' : $path;
	}

	/**
	 * True when the source and target normalize to the same path (a self-loop).
	 *
	 * @param string $source Source path.
	 * @param string $target Target URL/path.
	 * @return bool
	 */
	public static function would_loop( string $source, string $target ): bool {
		return self::normalize_path( $source ) === self::normalize_path( $target );
	}

	/**
	 * Resolve a row's effective target URL. A target_post_id resolves to the
	 * current permalink (so it survives the target's own slug changes); an empty
	 * result (post gone) means the redirect is inactive.
	 *
	 * @param array $row Redirect row.
	 * @return string
	 */
	public static function resolve_target( array $row ): string {
		$post_id = (int) ( $row['target_post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$link = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
			return is_string( $link ) ? $link : '';
		}
		return (string) ( $row['target'] ?? '' );
	}

	// ---------------------------------------------------------------------
	// CRUD
	// ---------------------------------------------------------------------

	/**
	 * Create a redirect. Returns the created row or a WP_Error.
	 *
	 * @param array $data { source, target|target_post_id, status_code, ignore_query, enabled, notes }.
	 * @return array|WP_Error
	 */
	public static function create( array $data ) {
		$source = self::normalize_path( (string) ( $data['source'] ?? '' ) );
		if ( '/' === $source || '' === $source ) {
			return new \WP_Error( 'invalid_source', __( 'A non-empty source path is required.', 'karmcp' ) );
		}
		if ( strlen( $source ) > self::MAX_SOURCE_LEN ) {
			return new \WP_Error( 'source_too_long', __( 'Source path exceeds 191 characters.', 'karmcp' ) );
		}
		$post_id = isset( $data['target_post_id'] ) ? absint( $data['target_post_id'] ) : 0;
		$target  = isset( $data['target'] ) ? trim( (string) $data['target'] ) : '';
		if ( $post_id && '' !== $target ) {
			return new \WP_Error( 'ambiguous_target', __( 'Provide either target or target_post_id, not both.', 'karmcp' ) );
		}
		if ( ! $post_id && '' === $target ) {
			return new \WP_Error( 'missing_target', __( 'A target URL or target_post_id is required.', 'karmcp' ) );
		}
		if ( '' !== $target && self::would_loop( $source, $target ) ) {
			return new \WP_Error( 'redirect_loop', __( 'A redirect cannot point to itself.', 'karmcp' ) );
		}
		if ( self::find_by_source( $source ) ) {
			return new \WP_Error( 'duplicate_source', __( 'A redirect for this source already exists.', 'karmcp' ) );
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'source_path'    => $source,
				'target'         => $post_id ? '' : $target,
				'target_post_id' => $post_id ? $post_id : null,
				'status_code'    => self::clamp_code( $data['status_code'] ?? 301 ),
				'match_type'     => 'exact',
				'ignore_query'   => empty( $data['ignore_query'] ) ? 0 : 1,
				'enabled'        => ( isset( $data['enabled'] ) && ! $data['enabled'] ) ? 0 : 1,
				'hits'           => 0,
				'notes'          => isset( $data['notes'] ) ? (string) $data['notes'] : null,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Update a redirect by id. Only supplied keys change. Returns the fresh row
	 * or a WP_Error.
	 *
	 * @param int   $id   Redirect id.
	 * @param array $data Partial fields.
	 * @return array|WP_Error
	 */
	public static function update( int $id, array $data ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', __( 'Redirect not found.', 'karmcp' ) );
		}
		$set     = array();
		$formats = array();
		if ( array_key_exists( 'source', $data ) ) {
			$source = self::normalize_path( (string) $data['source'] );
			if ( '/' === $source || strlen( $source ) > self::MAX_SOURCE_LEN ) {
				return new \WP_Error( 'invalid_source', __( 'Invalid source path.', 'karmcp' ) );
			}
			$dupe = self::find_by_source( $source );
			if ( $dupe && (int) $dupe['id'] !== $id ) {
				return new \WP_Error( 'duplicate_source', __( 'Another redirect already uses this source.', 'karmcp' ) );
			}
			$set['source_path'] = $source;
			$formats[]          = '%s';
		}
		if ( array_key_exists( 'target', $data ) ) {
			$set['target']         = trim( (string) $data['target'] );
			$set['target_post_id'] = null;
			$formats[]             = '%s';
			$formats[]             = '%d';
		} elseif ( array_key_exists( 'target_post_id', $data ) ) {
			$set['target_post_id'] = absint( $data['target_post_id'] );
			$set['target']         = '';
			$formats[]             = '%d';
			$formats[]             = '%s';
		}
		if ( array_key_exists( 'status_code', $data ) ) {
			$set['status_code'] = self::clamp_code( $data['status_code'] );
			$formats[]          = '%d';
		}
		if ( array_key_exists( 'ignore_query', $data ) ) {
			$set['ignore_query'] = empty( $data['ignore_query'] ) ? 0 : 1;
			$formats[]           = '%d';
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$set['enabled'] = empty( $data['enabled'] ) ? 0 : 1;
			$formats[]      = '%d';
		}
		if ( array_key_exists( 'notes', $data ) ) {
			$set['notes'] = (string) $data['notes'];
			$formats[]    = '%s';
		}
		$effective_source = $set['source_path'] ?? $row['source_path'];
		$effective_target = array_key_exists( 'target', $set ) ? $set['target'] : $row['target'];
		if ( '' !== (string) $effective_target && self::would_loop( (string) $effective_source, (string) $effective_target ) ) {
			return new \WP_Error( 'redirect_loop', __( 'A redirect cannot point to itself.', 'karmcp' ) );
		}
		if ( empty( $set ) ) {
			return $row;
		}
		$set['updated_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$formats[]         = '%s';
		global $wpdb;
		$wpdb->update( self::table(), $set, array( 'id' => $id ), $formats, array( '%d' ) );
		return self::get( $id );
	}

	/**
	 * Delete a redirect by id.
	 *
	 * @param int $id Redirect id.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Get one row by id.
	 *
	 * @param int $id Redirect id.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Get one row by normalized source path (the hot-path lookup).
	 *
	 * @param string $path Already-normalized source path.
	 * @return array|null
	 */
	public static function find_by_source( string $path ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_path = %s LIMIT 1', $path ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * List rows with optional filters.
	 *
	 * @param array $filters { enabled:bool, search:string, limit:int, offset:int }.
	 * @return array
	 */
	public static function all( array $filters = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( array_key_exists( 'enabled', $filters ) && null !== $filters['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = $filters['enabled'] ? 1 : 0;
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(source_path LIKE %s OR target LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );
		$sql    = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? array_map( array( __CLASS__, 'cast' ), $rows ) : array();
	}

	/**
	 * Count rows matching the same filters as all() (minus pagination).
	 *
	 * @param array $filters { enabled:bool, search:string }.
	 * @return int
	 */
	public static function count( array $filters = array() ): int {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( array_key_exists( 'enabled', $filters ) && null !== $filters['enabled'] ) {
			$where[]  = 'enabled = %d';
			$params[] = $filters['enabled'] ? 1 : 0;
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
			$where[]  = '(source_path LIKE %s OR target LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Bump the hit counter + last-hit timestamp for a matched redirect.
	 *
	 * @param int $id Redirect id.
	 */
	public static function record_hit( int $id ): void {
		global $wpdb;
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// ---------------------------------------------------------------------
	// Ledger rollback applier (redirect-row type)
	// ---------------------------------------------------------------------

	/**
	 * Apply a `redirect-row` rollback. Shapes:
	 *  - create → before:{ id }        → delete the row.
	 *  - update → before:{ row:{...} } → restore the prior row values.
	 *  - delete → before:{ row:{...} } → re-insert the prior row (id preserved).
	 *
	 * @param array $rb Rollback ref.
	 * @return bool
	 */
	public static function rollback( array $rb ): bool {
		$action = (string) ( $rb['action'] ?? '' );
		$before = isset( $rb['before'] ) && is_array( $rb['before'] ) ? $rb['before'] : array();
		if ( 'create' === $action ) {
			$id = (int) ( $before['id'] ?? 0 );
			return $id > 0 ? self::delete( $id ) : false;
		}
		$row = isset( $before['row'] ) && is_array( $before['row'] ) ? $before['row'] : array();
		if ( empty( $row['id'] ) ) {
			return false;
		}
		global $wpdb;
		if ( 'delete' === $action ) {
			return (bool) $wpdb->insert( self::table(), self::row_for_write( $row ), self::write_formats() );
		}
		if ( 'update' === $action ) {
			$write = self::row_for_write( $row );
			unset( $write['id'] );
			return (bool) $wpdb->update( self::table(), $write, array( 'id' => (int) $row['id'] ), self::write_formats_no_id(), array( '%d' ) );
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// Internals
	// ---------------------------------------------------------------------

	/**
	 * Clamp a status code to the supported 301/302 set (default 301).
	 *
	 * @param mixed $code Raw code.
	 * @return int
	 */
	private static function clamp_code( $code ): int {
		return in_array( (int) $code, array( 301, 302 ), true ) ? (int) $code : 301;
	}

	/**
	 * Cast a raw DB row to typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function cast( array $row ): array {
		$row['id']             = (int) ( $row['id'] ?? 0 );
		$row['target_post_id'] = isset( $row['target_post_id'] ) ? (int) $row['target_post_id'] : 0;
		$row['status_code']    = (int) ( $row['status_code'] ?? 301 );
		$row['ignore_query']   = (int) ( $row['ignore_query'] ?? 1 );
		$row['enabled']        = (int) ( $row['enabled'] ?? 1 );
		$row['hits']           = (int) ( $row['hits'] ?? 0 );
		return $row;
	}

	/**
	 * The writable column subset of a stored row (for restore/re-insert).
	 *
	 * @param array $row Prior row.
	 * @return array
	 */
	private static function row_for_write( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'source_path'    => (string) ( $row['source_path'] ?? '' ),
			'target'         => (string) ( $row['target'] ?? '' ),
			'target_post_id' => ! empty( $row['target_post_id'] ) ? (int) $row['target_post_id'] : null,
			'status_code'    => self::clamp_code( $row['status_code'] ?? 301 ),
			'match_type'     => (string) ( $row['match_type'] ?? 'exact' ),
			'ignore_query'   => (int) ( $row['ignore_query'] ?? 1 ),
			'enabled'        => (int) ( $row['enabled'] ?? 1 ),
			'hits'           => (int) ( $row['hits'] ?? 0 ),
			'last_hit'       => isset( $row['last_hit'] ) ? $row['last_hit'] : null,
			'notes'          => isset( $row['notes'] ) ? (string) $row['notes'] : null,
			'created_at'     => (string) ( $row['created_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
			'updated_at'     => (string) ( $row['updated_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
		);
	}

	/**
	 * Column formats for a full row write (id first), matching row_for_write().
	 *
	 * @return string[]
	 */
	private static function write_formats(): array {
		return array( '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );
	}

	/**
	 * Column formats for an update (id excluded from the SET list).
	 *
	 * @return string[]
	 */
	private static function write_formats_no_id(): array {
		return array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );
	}
}
