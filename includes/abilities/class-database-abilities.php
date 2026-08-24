<?php
/**
 * Database MCP tools: list-tables/describe-table/query (read; enabled) +
 * insert-row/update-rows/delete-rows (structured, parameterized writes;
 * disabled-by-default). Reads via validated read-only SQL; writes via
 * $wpdb->insert/update/delete with forced WHERE, protected tables, before-image
 * snapshots, confirm-on-delete, and an audit log.
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
class KarMCP_Database_Abilities {

	/** @var string[] */
	private $ability_names = array();

	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_list_tables();
		$this->register_describe_table();
		$this->register_query();
		$this->register_insert_row();
		$this->register_update_rows();
		$this->register_delete_rows();
	}

	/** All database tools require manage_options. */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function ability( string $name, string $label, string $description, string $exec, array $props, array $required, bool $readonly ): void {
		$this->ability_names[] = $name;
		karmcp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, $exec ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => $props, 'required' => $required ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly ), 'show_in_rest' => true ),
			)
		);
	}

	// ---- reads ---------------------------------------------------------

	private function register_list_tables(): void {
		$this->ability( 'karmcp/list-tables', __( 'List Tables', 'karmcp' ), __( 'List database tables with estimated row counts and sizes.', 'karmcp' ), 'execute_list_tables', array(), array(), true );
	}
	public function execute_list_tables( $input ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS n, table_rows AS r, (data_length + index_length) AS sz FROM information_schema.TABLES WHERE table_schema = %s ORDER BY n ASC',
				DB_NAME
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'table' => (string) $row['n'], 'rows' => (int) $row['r'], 'size_bytes' => (int) $row['sz'] );
		}
		return array( 'tables' => $out );
	}

	private function register_describe_table(): void {
		$this->ability( 'karmcp/describe-table', __( 'Describe Table', 'karmcp' ), __( 'Return the columns, types, and keys of a table.', 'karmcp' ), 'execute_describe_table', array( 'table' => array( 'type' => 'string' ) ), array( 'table' ), true );
	}
	public function execute_describe_table( $input ) {
		$table = KarMCP_Database_Guard::valid_table( (string) ( $input['table'] ?? '' ) );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		global $wpdb;
		// %i, not a hand-rolled backtick strip: the table name is already
		// validated against the real table list, and this hands the escaping of
		// the identifier to WordPress instead of to a str_replace here.
		$cols = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ), ARRAY_A );
		return array( 'table' => $table, 'columns' => is_array( $cols ) ? $cols : array() );
	}

	private function register_query(): void {
		$this->ability( 'karmcp/query', __( 'Query (read-only)', 'karmcp' ), __( 'Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN). Writes/DDL and file-access SQL are rejected. Results are capped.', 'karmcp' ), 'execute_query', array( 'sql' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer' ) ), array( 'sql' ), true );
	}
	public function execute_query( $input ) {
		$sql = (string) ( $input['sql'] ?? '' );
		// One gate, not three: read-only, no server system schema, no protected
		// user table. See KarMCP_Database_Guard::check_read_query().
		$allowed = KarMCP_Database_Guard::check_read_query( $sql );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		global $wpdb;
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : KarMCP_Database_Guard::MAX_ROWS;
		$limit = min( KarMCP_Database_Guard::MAX_ROWS, max( 1, $limit ) );

		// Bound the work in the DATABASE, not just the response. Slicing after the
		// fetch still made the server materialize every row and PHP hold them, so
		// a wide query was an out-of-memory fatal rather than a capped answer.
		$bounded = KarMCP_Database_Guard::bound_sql( $sql, $limit + 1 ); // +1 detects truncation.
		if ( is_wp_error( $bounded ) ) {
			return $bounded;
		}

		// Server-side statement timeout, restored in the finally block.
		$restore_timeout = KarMCP_Database_Guard::apply_statement_timeout( $wpdb );
		try {
			$rows = $wpdb->get_results( $bounded, ARRAY_A ); // phpcs:ignore WordPress.DB -- validated read-only and row-bounded above; admin-authored.
		} finally {
			$restore_timeout();
		}
		if ( null === $rows ) {
			return new \WP_Error( 'query_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Query failed.', 'karmcp' ) );
		}
		$truncated = count( $rows ) > $limit;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		return array( 'rows' => $rows, 'row_count' => count( $rows ), 'truncated' => $truncated );
	}

	// ---- writes (disabled by default) ---------------------------------

	/**
	 * Record a DB write to the change ledger via the recorder (which offloads a
	 * large before-image to the blob store), falling back to the raw ledger.
	 *
	 * @param array $entry Ledger entry.
	 */
	private function record_change( array $entry ): void {
		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			KarMCP_Change_Recorder::record_db( $entry );
		} elseif ( class_exists( 'KarMCP_Change_Log' ) ) {
			KarMCP_Change_Log::record( $entry );
		}
	}

	private function register_insert_row(): void {
		$this->ability( 'karmcp/insert-row', __( 'Insert Row', 'karmcp' ), __( 'Insert a row into a table (parameterized). Refuses protected tables. Disabled by default.', 'karmcp' ), 'execute_insert_row', array( 'table' => array( 'type' => 'string' ), 'data' => array( 'type' => 'object' ) ), array( 'table', 'data' ), false );
	}
	public function execute_insert_row( $input ) {
		$data = (array) ( $input['data'] ?? array() );
		if ( empty( $data ) ) {
			return new \WP_Error( 'no_data', __( 'A non-empty data object is required.', 'karmcp' ) );
		}
		$table = KarMCP_Database_Guard::valid_table( (string) ( $input['table'] ?? '' ) );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		if ( KarMCP_Database_Guard::is_protected( $table ) ) {
			return new \WP_Error( 'protected_table', __( 'Writes to this table are not allowed.', 'karmcp' ) );
		}
		$cols = KarMCP_Database_Guard::validate_columns( $table, array_keys( $data ), 'data' );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}
		global $wpdb;
		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			return new \WP_Error( 'insert_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Insert failed.', 'karmcp' ) );
		}
		$this->record_change( array(
				'domain'   => 'database',
				'action'   => 'insert',
				'target'   => $table,
				'summary'  => 'Inserted a row into ' . $table,
				'rollback' => array( 'type' => 'db-before-image', 'op' => 'insert', 'table' => $table, 'inserted_key' => $data ),
			) );
		return array( 'table' => $table, 'insert_id' => (int) $wpdb->insert_id, 'affected' => (int) $ok );
	}

	private function register_update_rows(): void {
		$this->ability( 'karmcp/update-rows', __( 'Update Rows', 'karmcp' ), __( 'Update rows matching an equality WHERE (required, non-empty). Parameterized; before-image snapshot; refuses protected tables. Disabled by default.', 'karmcp' ), 'execute_update_rows', array( 'table' => array( 'type' => 'string' ), 'data' => array( 'type' => 'object' ), 'where' => array( 'type' => 'object' ) ), array( 'table', 'data', 'where' ), false );
	}
	public function execute_update_rows( $input ) {
		$data  = (array) ( $input['data'] ?? array() );
		$where = (array) ( $input['where'] ?? array() );
		if ( empty( $data ) ) {
			return new \WP_Error( 'no_data', __( 'A non-empty data object is required.', 'karmcp' ) );
		}
		if ( empty( $where ) ) {
			return new \WP_Error( 'where_required', __( 'A non-empty where object is required for update.', 'karmcp' ) );
		}
		$table = KarMCP_Database_Guard::valid_table( (string) ( $input['table'] ?? '' ) );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		if ( KarMCP_Database_Guard::is_protected( $table ) ) {
			return new \WP_Error( 'protected_table', __( 'Writes to this table are not allowed.', 'karmcp' ) );
		}
		foreach ( array( 'data' => $data, 'where' => $where ) as $karmcp_label => $karmcp_set ) {
			$cols = KarMCP_Database_Guard::validate_columns( $table, array_keys( $karmcp_set ), $karmcp_label );
			if ( is_wp_error( $cols ) ) {
				return $cols;
			}
		}
		$before = KarMCP_Database_Guard::before_image( $table, $where );
		global $wpdb;
		$affected = $wpdb->update( $table, $data, $where );
		if ( false === $affected ) {
			return new \WP_Error( 'update_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Update failed.', 'karmcp' ) );
		}
		$this->record_change( array(
				'domain'   => 'database',
				'action'   => 'update',
				'target'   => $table,
				'summary'  => sprintf( 'Updated %d row(s) in %s', (int) $affected, $table ),
				'rollback' => array( 'type' => 'db-before-image', 'op' => 'update', 'table' => $table, 'key_cols' => array_keys( $where ), 'before_rows' => $before, 'partial' => ( count( $before ) >= KarMCP_Database_Guard::BEFORE_IMAGE_CAP ) ),
			) );
		return array( 'table' => $table, 'affected' => (int) $affected, 'before_image_rows' => count( $before ) );
	}

	private function register_delete_rows(): void {
		$this->ability( 'karmcp/delete-rows', __( 'Delete Rows', 'karmcp' ), __( 'Delete rows matching an equality WHERE (required). Needs confirm:true; before-image snapshot; refuses protected tables. Disabled by default.', 'karmcp' ), 'execute_delete_rows', array( 'table' => array( 'type' => 'string' ), 'where' => array( 'type' => 'object' ), 'confirm' => array( 'type' => 'boolean' ) ), array( 'table', 'where' ), false );
	}
	public function execute_delete_rows( $input ) {
		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new \WP_Error( 'confirm_required', __( 'Deleting rows requires confirm:true.', 'karmcp' ) );
		}
		$where = (array) ( $input['where'] ?? array() );
		if ( empty( $where ) ) {
			return new \WP_Error( 'where_required', __( 'A non-empty where object is required for delete.', 'karmcp' ) );
		}
		$table = KarMCP_Database_Guard::valid_table( (string) ( $input['table'] ?? '' ) );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		if ( KarMCP_Database_Guard::is_protected( $table ) ) {
			return new \WP_Error( 'protected_table', __( 'Writes to this table are not allowed.', 'karmcp' ) );
		}
		$cols = KarMCP_Database_Guard::validate_columns( $table, array_keys( $where ), 'where' );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}
		$before = KarMCP_Database_Guard::before_image( $table, $where );
		global $wpdb;
		$affected = $wpdb->delete( $table, $where );
		if ( false === $affected ) {
			return new \WP_Error( 'delete_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Delete failed.', 'karmcp' ) );
		}
		$this->record_change( array(
				'domain'   => 'database',
				'action'   => 'delete',
				'target'   => $table,
				'summary'  => sprintf( 'Deleted %d row(s) from %s', (int) $affected, $table ),
				'rollback' => array( 'type' => 'db-before-image', 'op' => 'delete', 'table' => $table, 'key_cols' => array_keys( $where ), 'before_rows' => $before, 'partial' => ( count( $before ) >= KarMCP_Database_Guard::BEFORE_IMAGE_CAP ) ),
			) );
		return array( 'table' => $table, 'affected' => (int) $affected, 'before_image_rows' => count( $before ) );
	}
}
