<?php
/**
 * Database safety guard: validates read-only SQL for the `query` tool,
 * validates/protects table names for structured writes, captures before-image
 * snapshots, and audits.
 *
 * Since 1.34.0 the SQL half is a thin layer over KarMCP_SQL_Policy, which
 * inspects a typed token stream instead of pattern-matching normalized text.
 * check_read_query() is the safety boundary for the flexible read path: it is
 * the one call that answers all three questions a raw read has to pass.
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

	/** Server-side statement timeout for read-only queries, in seconds. */
	const MAX_QUERY_SECONDS = 10;

	/** Schemas that are never a legitimate target of the `query` tool. */
	const SYSTEM_SCHEMAS = KarMCP_SQL_Policy::SYSTEM_SCHEMAS;

	/**
	 * Legacy text normalizer.
	 *
	 * Superseded in 1.34.0 by KarMCP_SQL_Lexer. Nothing in the security path
	 * calls this any more: a normalizer that quietly mis-reads a byte still
	 * produces a plausible-looking string, and that is precisely how the four
	 * bypasses an audit found were possible. Kept so a third-party caller does
	 * not fatal, and deliberately not used for policy.
	 *
	 * @deprecated 1.34.0 Use KarMCP_SQL_Lexer::tokenize().
	 * @param string $sql               SQL.
	 * @param bool   $backslash_escapes Mode flag.
	 * @param bool   $keep_identifiers  Emit identifier names rather than blanking them.
	 * @param bool   $ansi_quotes       Mode flag.
	 * @return string Empty string when the statement cannot be tokenized.
	 */
	public static function normalize_sql( string $sql, bool $backslash_escapes = true, bool $keep_identifiers = false, bool $ansi_quotes = false ): string {
		$tokens = KarMCP_SQL_Lexer::tokenize( $sql, $backslash_escapes, $ansi_quotes );
		if ( is_wp_error( $tokens ) ) {
			return '';
		}
		$out = '';
		foreach ( $tokens as $t ) {
			switch ( $t['t'] ) {
				case KarMCP_SQL_Lexer::T_COMMENT:
					$out .= ' ';
					break;
				case KarMCP_SQL_Lexer::T_STRING:
					$out .= "''";
					break;
				case KarMCP_SQL_Lexer::T_IDENT:
					if ( ! $t['quoted'] ) {
						$out .= $t['v'];
					} elseif ( $keep_identifiers ) {
						$out .= ' ' . $t['name'] . ' ';
					} else {
						$out .= '``';
					}
					break;
				default:
					$out .= $t['v'];
			}
		}
		return $out;
	}

	/**
	 * Validate that $sql is a single read-only statement. Pure (no DB).
	 *
	 * This answers "is this a read?", not "may this caller read that?" — the two
	 * data-protection checks live in check_read_query(), which is what a raw read
	 * path should call.
	 *
	 * @param string $sql
	 * @return true|\WP_Error
	 */
	public static function is_read_only_sql( string $sql ) {
		$analysis = KarMCP_SQL_Policy::analyze( $sql );
		return is_wp_error( $analysis ) ? $analysis : true;
	}

	/**
	 * The full gate for a raw read: read-only, no system schema, no protected
	 * table.
	 *
	 * One call rather than three, because the three are only correct together and
	 * a second raw-SQL path that remembered two of them would leak. Everything it
	 * refuses, it refuses with the message the agent needs to act on.
	 *
	 * @since 1.34.0
	 * @param string $sql Raw SQL.
	 * @return true|\WP_Error
	 */
	public static function check_read_query( string $sql ) {
		$read_only = self::is_read_only_sql( $sql );
		if ( is_wp_error( $read_only ) ) {
			return $read_only;
		}
		// Cross-schema secrets. The protected-table list covers the WordPress user
		// tables only, so mysql.user (the server's own account hashes) and the
		// metadata schemas would otherwise be readable straight through.
		if ( self::references_system_schema( $sql ) ) {
			return new \WP_Error( 'protected_read', KarMCP_SQL_Policy::system_schema_message() );
		}
		// The user tables hold password hashes, session tokens and activation
		// keys. Refuse raw reads that touch them and point the agent at the
		// dedicated, redacting user tools.
		if ( self::query_touches_protected( $sql ) ) {
			return new \WP_Error( 'protected_read', KarMCP_SQL_Policy::protected_table_message() );
		}
		return true;
	}

	/**
	 * Does $sql reference a server system schema?
	 *
	 * @since 1.34.0
	 * @param string $sql Raw SQL.
	 * @return bool True also when the statement cannot be parsed (fail closed).
	 */
	public static function references_system_schema( string $sql ): bool {
		return KarMCP_SQL_Policy::references_system_schema( $sql );
	}

	/**
	 * Apply a server-side statement timeout for the next query, and return a
	 * callable that restores the previous session value.
	 *
	 * MySQL 5.7.8+ uses `max_execution_time` (milliseconds, SELECT only);
	 * MariaDB 10.1.1+ uses `max_statement_time` (seconds, as a double). We set
	 * whichever exists and do nothing on a server that has neither, so this can
	 * never break a query on an unsupported database.
	 *
	 * @since 1.34.0
	 * @param object $wpdb WordPress database handle.
	 * @return callable Restores the prior session setting.
	 */
	public static function apply_statement_timeout( $wpdb ): callable {
		$noop = static function () {};
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $noop;
		}
		$seconds  = (int) apply_filters( 'karmcp_db_query_max_seconds', self::MAX_QUERY_SECONDS );
		$seconds  = max( 1, $seconds );
		$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$restore  = $noop;

		// MariaDB first: it also exposes max_statement_time, and asking for the
		// MySQL-only variable there simply returns null.
		$maria = $wpdb->get_var( 'SELECT @@SESSION.max_statement_time' );
		if ( null !== $maria ) {
			$prev    = (float) $maria;
			$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', (float) $seconds ) );
			$restore = static function () use ( $wpdb, $prev ) {
				$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', $prev ) );
			};
		} else {
			$mysql = $wpdb->get_var( 'SELECT @@SESSION.max_execution_time' );
			if ( null !== $mysql ) {
				$prev    = (int) $mysql;
				$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $seconds * 1000 ) );
				$restore = static function () use ( $wpdb, $prev ) {
					$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $prev ) );
				};
			}
		}
		if ( null !== $suppress && method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( $suppress );
		}
		return $restore;
	}

	/**
	 * Row count of a TOP-LEVEL trailing LIMIT, or null when there is none.
	 *
	 * Depth-aware via the token stream, so a LIMIT inside a subquery does not
	 * count as bounding the outer result.
	 *
	 * @since 1.34.0
	 * @param string $sql Raw SQL.
	 * @return int|null
	 */
	public static function trailing_limit( string $sql ): ?int {
		$analysis = KarMCP_SQL_Policy::analyze( $sql );
		return is_wp_error( $analysis ) ? null : $analysis['limit'];
	}

	/**
	 * Return $sql guaranteed to fetch at most $max rows, or a WP_Error.
	 *
	 * An oversized caller LIMIT is REFUSED rather than rewritten. Editing raw SQL
	 * around literals is the very class of trick this guard exists to stop.
	 *
	 * @since 1.34.0
	 * @param string $sql Raw SQL.
	 * @param int    $max Maximum rows.
	 * @return string|\WP_Error
	 */
	public static function bound_sql( string $sql, int $max ) {
		$analysis = KarMCP_SQL_Policy::analyze( $sql );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}
		$trimmed = rtrim( trim( $sql ), "; \t\r\n" );
		if ( null !== $analysis['limit'] ) {
			if ( $analysis['limit'] > $max ) {
				return new \WP_Error(
					'limit_too_large',
					sprintf(
						/* translators: 1: requested LIMIT, 2: maximum allowed. */
						__( 'The query asks for %1$d rows, above the %2$d row cap. Lower the LIMIT.', 'karmcp' ),
						$analysis['limit'],
						$max
					)
				);
			}
			return $trimmed;
		}
		// SHOW / DESCRIBE / EXPLAIN return their own small result and take no
		// LIMIT clause, so appending one would be a syntax error.
		if ( ! in_array( $analysis['first'], array( 'select', 'with', 'table', 'values' ), true ) ) {
			return $trimmed;
		}
		// Newline first: a trailing line comment would otherwise swallow the clause.
		return $trimmed . "\n LIMIT " . (int) $max;
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
	 * Compares whole identifiers from the token stream, so `'wp_users'` inside a
	 * literal is not a reference, `` `wp_users` `` still is, and `wp_users_backup`
	 * is a different name rather than a substring match. This is the read-path
	 * counterpart to is_protected() — the `query` tool refuses any read that
	 * touches the protected user tables.
	 *
	 * Fails closed: a statement this cannot parse is treated as touching them.
	 *
	 * @param string   $sql
	 * @param string[] $tables Real table names (e.g. {$wpdb->users}).
	 * @return bool
	 */
	public static function query_touches_tables( string $sql, array $tables ): bool {
		return KarMCP_SQL_Policy::touches_tables( $sql, $tables );
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
		// Both the table and the column names are identifiers, and %i is what
		// escapes an identifier — WordPress does it, rather than the backtick
		// strip this used to do by hand. Args interleave to match the clause
		// order: table, then (column, value) per condition.
		$cond = array();
		$args = array( $table );
		foreach ( $where as $col => $val ) {
			$cond[] = '%i = %s';
			$args[] = (string) $col;
			$args[] = $val;
		}
		$sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $cond ) . ' LIMIT ' . (int) self::BEFORE_IMAGE_CAP;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from %i/%s placeholders and an int cap; every value goes through prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
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
