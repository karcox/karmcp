<?php
/**
 * Database safety guard: validates read-only SQL for the `query` tool,
 * validates/protects table names for structured writes, captures before-image
 * snapshots, and audits. is_read_only_sql() is the safety boundary for the
 * flexible read path.
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class KarMCP_Database_Guard {

	const MAX_ROWS         = 1000;
	const BEFORE_IMAGE_CAP = 500;

	/**
	 * Pure: normalize SQL for safe keyword scanning — replace every comment with
	 * a space and every string / backtick-identifier literal with an empty
	 * placeholder, so keywords cannot hide inside comments, strings, or quoted
	 * identifiers. Does NOT special-case /*! (the caller rejects those first).
	 *
	 * @param string $sql
	 * @return string
	 */
	public static function normalize_sql( string $sql ): string {
		$out = '';
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$c   = $sql[ $i ];
			$two = substr( $sql, $i, 2 );
			if ( '--' === $two || '#' === $c ) {
				$nl  = strpos( $sql, "\n", $i );
				$i   = ( false === $nl ) ? $len : $nl + 1;
				$out .= ' ';
				continue;
			}
			if ( '/*' === $two ) {
				$end = strpos( $sql, '*/', $i + 2 );
				$i   = ( false === $end ) ? $len : $end + 2;
				$out .= ' ';
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$q = $c;
				$i++;
				while ( $i < $len ) {
					if ( '\\' === $sql[ $i ] ) { $i += 2; continue; }
					if ( $sql[ $i ] === $q ) {
						if ( $i + 1 < $len && $sql[ $i + 1 ] === $q ) { $i += 2; continue; }
						$i++;
						break;
					}
					$i++;
				}
				$out .= "''";
				continue;
			}
			if ( '`' === $c ) {
				$i++;
				while ( $i < $len && '`' !== $sql[ $i ] ) { $i++; }
				$i++;
				$out .= '``';
				continue;
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}

	/**
	 * Validate that $sql is a single read-only statement. Pure (no DB).
	 *
	 * @param string $sql
	 * @return true|\WP_Error
	 */
	public static function is_read_only_sql( string $sql ) {
		// MySQL executes the body of /*! ... */ executable comments, so we cannot
		// safely strip-and-trust. Refuse any SQL containing the marker.
		if ( false !== strpos( $sql, '/*!' ) ) {
			return new \WP_Error( 'executable_comment', __( 'MySQL executable comments (/*! ... */) are not allowed.', 'karmcp' ) );
		}
		$norm = trim( self::normalize_sql( $sql ) );
		if ( '' === $norm ) {
			return new \WP_Error( 'empty_sql', __( 'Empty query.', 'karmcp' ) );
		}
		// Multi-statement: any ';' that isn't the sole trailing character.
		$no_trailing = rtrim( $norm, "; \t\r\n" );
		if ( false !== strpos( $no_trailing, ';' ) ) {
			return new \WP_Error( 'multi_statement', __( 'Multiple SQL statements are not allowed.', 'karmcp' ) );
		}
		// File-access vectors (comments already normalized to spaces). Note: no
		// trailing \b — the load_file(...) branch ends in '(', and '(' followed
		// by another non-word char has no word boundary, so a trailing \b would
		// (incorrectly) let LOAD_FILE through.
		if ( preg_match( '/\b(into\s+outfile|into\s+dumpfile|load_file\s*\(|load\s+data\b)/i', $norm ) ) {
			return new \WP_Error( 'file_access_blocked', __( 'File-access SQL (OUTFILE/DUMPFILE/LOAD_FILE/LOAD DATA) is not allowed.', 'karmcp' ) );
		}
		// First keyword must be read-only.
		if ( ! preg_match( '/^([a-z]+)/i', $norm, $m ) ) {
			return new \WP_Error( 'not_read_only', __( 'Only read-only queries are allowed.', 'karmcp' ) );
		}
		$kw      = strtoupper( $m[1] );
		$allowed = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH' );
		if ( ! in_array( $kw, $allowed, true ) ) {
			return new \WP_Error(
				'not_read_only',
				/* translators: %s: SQL keyword */
				sprintf( __( 'Only read-only queries are allowed (got %s).', 'karmcp' ), $kw )
			);
		}
		// Whole-statement write/DDL denylist (literals/comments already stripped,
		// so these match only real keyword tokens, not strings or identifiers).
		if ( preg_match( '/\b(INSERT|UPDATE|DELETE|MERGE|DROP|TRUNCATE|ALTER|CREATE|RENAME|GRANT|REVOKE|HANDLER|CALL|LOCK|UNLOCK|PREPARE|EXECUTE|INTO)\b/i', $norm ) ) {
			return new \WP_Error( 'not_read_only', __( 'The query contains a write or unsafe keyword.', 'karmcp' ) );
		}
		// REPLACE is two different things: the write statement `REPLACE [INTO]
		// tbl ...` and the read-only string function `REPLACE(col,'a','b')`.
		// Denylisting the bare word rejected legitimate analysis queries. Only
		// the statement form is a write, and it is never followed by '('.
		if ( preg_match( '/\bREPLACE\b\s*(?!\()/i', $norm ) ) {
			return new \WP_Error( 'not_read_only', __( 'The query contains a write or unsafe keyword.', 'karmcp' ) );
		}
		return true;
	}

	/**
	 * Resolve a table name against the live table list (table names cannot be
	 * parameterized). Returns the exact real name, or WP_Error.
	 *
	 * @param string $table
	 * @return string|\WP_Error
	 */
	public static function valid_table( string $table ) {
		global $wpdb;
		$table = trim( $table );
		if ( '' === $table ) {
			return new \WP_Error( 'unknown_table', __( 'A table name is required.', 'karmcp' ) );
		}
		$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		foreach ( $tables as $t ) {
			if ( strtolower( (string) $t ) === strtolower( $table ) ) {
				return (string) $t;
			}
		}
		return new \WP_Error( 'unknown_table', __( 'Unknown table.', 'karmcp' ) );
	}

	/**
	 * Pure: is $table in the protected list (case-insensitive)?
	 *
	 * @param string   $table
	 * @param string[] $protected
	 * @return bool
	 */
	public static function table_is_protected( string $table, array $protected ): bool {
		$t = strtolower( $table );
		foreach ( $protected as $p ) {
			if ( strtolower( (string) $p ) === $t ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether writes to $table are refused (users/usermeta by default).
	 *
	 * @param string $table
	 * @return bool
	 */
	public static function is_protected( string $table ): bool {
		global $wpdb;
		$protected = apply_filters( 'karmcp_db_protected_tables', array( $wpdb->users, $wpdb->usermeta ) );
		return self::table_is_protected( $table, (array) $protected );
	}

	/**
	 * Pure: does a read-only $sql reference any of $tables as a real identifier?
	 *
	 * Comments and string literals are stripped (so `'wp_users'` in a literal is
	 * not a reference), backticks are removed (so `` `wp_users` `` still matches),
	 * and each table is matched on word boundaries (so `wp_users_backup` does not
	 * match `wp_users`). This is the read-path counterpart to is_protected() —
	 * the `query` tool refuses any read that touches the protected user tables.
	 *
	 * @param string   $sql
	 * @param string[] $tables Real table names (e.g. {$wpdb->users}).
	 * @return bool
	 */
	public static function query_touches_tables( string $sql, array $tables ): bool {
		// Remove backticks first so quoted identifiers survive normalization
		// (normalize_sql empties backtick-quoted spans), then strip comments +
		// string literals via the shared normalizer.
		$scan = strtolower( self::normalize_sql( str_replace( '`', ' ', $sql ) ) );
		foreach ( $tables as $t ) {
			$t = strtolower( trim( (string) $t ) );
			if ( '' === $t ) {
				continue;
			}
			if ( preg_match( '/(?<![a-z0-9_])' . preg_quote( $t, '/' ) . '(?![a-z0-9_])/', $scan ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a read-only $sql touches a protected table (users/usermeta by
	 * default; filter via karmcp_db_protected_tables).
	 *
	 * @param string $sql
	 * @return bool
	 */
	public static function query_touches_protected( string $sql ): bool {
		global $wpdb;
		$protected = apply_filters( 'karmcp_db_protected_tables', array( $wpdb->users, $wpdb->usermeta ) );
		return self::query_touches_tables( $sql, (array) $protected );
	}

	/**
	 * Pure: which of $keys is not a real column name in $known?
	 *
	 * Comparison is case-insensitive because MySQL column names are, and a key
	 * that is not a string at all (a JSON object arrives with integer keys when
	 * the caller sends an array) can never be a column, so it is reported too.
	 *
	 * @param array    $keys  Caller-supplied column names.
	 * @param string[] $known Real column names of the target table.
	 * @return string[] The offending keys, in the order given.
	 */
	public static function unknown_columns( array $keys, array $known ): array {
		$map = array();
		foreach ( $known as $k ) {
			$map[ strtolower( (string) $k ) ] = true;
		}
		$bad = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! isset( $map[ strtolower( $key ) ] ) ) {
				$bad[] = (string) $key;
			}
		}
		return $bad;
	}

	/**
	 * The real column names of a validated table.
	 *
	 * @param string $table A table name already resolved by valid_table().
	 * @return string[]
	 */
	public static function columns( string $table ): array {
		global $wpdb;
		$safe = str_replace( '`', '', $table );
		// phpcs:ignore WordPress.DB -- identifier already resolved against SHOW TABLES; backticks stripped.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$safe}`" );
		return is_array( $cols ) ? array_map( 'strval', $cols ) : array();
	}

	/**
	 * Refuse a structured write whose column names are not real columns.
	 *
	 * This is a SECURITY boundary, not a convenience check. `$wpdb->insert()`,
	 * `->update()` and `->delete()` parameterize the VALUES but interpolate the
	 * column names straight into the SQL between backticks, without escaping a
	 * backtick in the name. So a key like ``id` = 1 OR 1=1 #`` closes the
	 * identifier and rewrites the statement — which would let a caller sidestep
	 * the protected-table list entirely (e.g. by writing a subquery that copies
	 * password hashes into a readable table). Validating against the table's
	 * real columns closes that off, and gives a clear error for a typo.
	 *
	 * @param string $table A table name already resolved by valid_table().
	 * @param array  $keys  Caller-supplied column names.
	 * @param string $label Which input the keys came from, for the message.
	 * @return true|\WP_Error
	 */
	public static function validate_columns( string $table, array $keys, string $label ) {
		$known = self::columns( $table );
		if ( empty( $known ) ) {
			return new \WP_Error( 'no_columns', __( 'Could not read the table columns.', 'karmcp' ) );
		}
		$bad = self::unknown_columns( $keys, $known );
		if ( ! empty( $bad ) ) {
			return new \WP_Error(
				'unknown_column',
				sprintf(
					/* translators: 1: input name (data or where), 2: comma-separated column names, 3: table name */
					__( 'Unknown column(s) in %1$s: %2$s. Not columns of %3$s — call describe-table to see the real ones.', 'karmcp' ),
					$label,
					implode( ', ', $bad ),
					$table
				)
			);
		}
		return true;
	}

	/**
	 * Capture the rows an equality-AND WHERE will affect, before update/delete.
	 *
	 * @param string $table A validated real table name.
	 * @param array  $where col => value (equality AND).
	 * @return array
	 */
	public static function before_image( string $table, array $where ): array {
		global $wpdb;
		if ( empty( $where ) ) {
			return array();
		}
		$cond = array();
		$vals = array();
		foreach ( $where as $col => $val ) {
			$cond[] = '`' . str_replace( '`', '', (string) $col ) . '` = %s';
			$vals[] = $val;
		}
		$sql  = "SELECT * FROM `" . str_replace( '`', '', $table ) . "` WHERE " . implode( ' AND ', $cond ) . ' LIMIT ' . (int) self::BEFORE_IMAGE_CAP;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deprecated: DB writes are now recorded in the unified change ledger
	 * (KarMCP_Change_Log) via KarMCP_Change_Recorder, which is the single
	 * audit + rollback source. Kept as a no-op for backward compatibility.
	 *
	 * @deprecated 3.10.0
	 */
	public static function log(): void {}
}
