<?php
/**
 * Unified change ledger + rollback dispatcher (AI-safe transactions).
 *
 * A single recorder every write site calls, storing lightweight rollback-capable
 * entries in one capped option, plus a rollback dispatcher that undoes an entry
 * via the right mechanism (re-save prior Elementor data, restore/delete a file
 * backup, or inverse a $wpdb write from a before-image).
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change ledger.
 *
 * @since 3.3.0
 */
class KarMCP_Change_Log {

	const OPTION    = 'karmcp_changelog';
	const MAX_COUNT = 500;     // Rows are light now (before-images live out-of-band).
	const MAX_BYTES = 2097152; // ~2 MB safety ceiling for the light rows.

	/**
	 * When true, record() is a no-op. Set during rollback so the rollback's own
	 * write (e.g. re-saving Elementor data) does not create a spurious entry.
	 *
	 * @var bool
	 */
	public static $suppress = false;

	/**
	 * Run a callback with recording switched off, restoring the caller's state
	 * afterwards — including when the callback throws.
	 *
	 * For writes that are part of a larger operation recorded as one entry, such
	 * as the initial content of a page that is being created.
	 *
	 * @since 1.42.0
	 *
	 * @param callable $fn Callback.
	 * @return mixed What the callback returns.
	 */
	public static function without_recording( callable $fn ) {
		$outer          = self::$suppress;
		self::$suppress = true;
		try {
			return $fn();
		} finally {
			self::$suppress = $outer;
		}
	}

	/**
	 * Append an entry. Returns its id, or '' when suppressed.
	 *
	 * @param array $entry { domain, action, target?, summary?, rollback? }.
	 * @return string
	 */
	public static function record( array $entry ): string {
		if ( self::$suppress ) {
			return '';
		}
		$id    = self::uid();
		$entry = array_merge(
			array(
				'target'   => '',
				'summary'  => '',
				'rollback' => null,
			),
			$entry,
			array(
				'id'          => $id,
				'ts'          => time(),
				'user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'user_login'  => self::current_login(),
				'rolled_back' => false,
			)
		);
		$log   = self::all();
		$log[] = $entry;
		update_option( self::OPTION, self::cap( $log ), false );
		return $id;
	}

	/**
	 * All entries, oldest-first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Fetch an entry by id.
	 *
	 * @param string $id Entry id.
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * Flag an entry as rolled back.
	 *
	 * @param string $id Entry id.
	 * @return bool False when the ledger could not be written.
	 */
	public static function mark_rolled_back( string $id ): bool {
		$log   = self::all();
		$found = false;
		foreach ( $log as &$e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				$e['rolled_back'] = true;
				$found            = true;
			}
		}
		unset( $e );
		if ( ! $found ) {
			return false;
		}
		update_option( self::OPTION, $log, false );
		$check = self::get( $id );
		return null !== $check && ! empty( $check['rolled_back'] );
	}

	/**
	 * Delete a single entry from the ledger.
	 *
	 * Note: an entry carries its own before-image, so deleting a reversible
	 * entry permanently forfeits the ability to roll it back. The admin UI
	 * warns about this before calling.
	 *
	 * @since 3.4.2
	 * @param string $id Entry id.
	 * @return bool True when an entry was removed.
	 */
	public static function delete( string $id ): bool {
		if ( '' === $id ) {
			return false;
		}
		$log     = self::all();
		$kept    = array();
		$removed = null;
		foreach ( $log as $e ) {
			if ( null === $removed && isset( $e['id'] ) && $e['id'] === $id ) {
				$removed = $e;
				continue;
			}
			$kept[] = $e;
		}
		if ( null === $removed ) {
			return false;
		}
		self::forget_blobs( array( $removed ) );
		update_option( self::OPTION, array_values( $kept ), false );
		return true;
	}

	/**
	 * Wipe the whole ledger.
	 *
	 * @since 3.4.2
	 * @return int Number of entries removed.
	 */
	public static function clear(): int {
		$log   = self::all();
		$count = count( $log );
		self::forget_blobs( $log );
		update_option( self::OPTION, array(), false );
		return $count;
	}

	/**
	 * Enforce count + size caps by dropping the oldest entries.
	 *
	 * @param array $log Entries.
	 * @return array
	 */
	private static function cap( array $log ): array {
		$dropped = array();
		if ( count( $log ) > self::MAX_COUNT ) {
			$dropped = array_slice( $log, 0, count( $log ) - self::MAX_COUNT );
			$log     = array_slice( $log, -self::MAX_COUNT );
		}
		while ( count( $log ) > 1 && strlen( (string) wp_json_encode( $log ) ) > self::MAX_BYTES ) {
			$dropped[] = array_shift( $log );
		}
		self::forget_blobs( $dropped );
		return array_values( $log );
	}

	/**
	 * Delete the out-of-band before-image blobs for a set of dropped/removed
	 * ledger rows, so evicted entries don't orphan their snapshots.
	 *
	 * @param array $rows Ledger rows being removed.
	 */
	private static function forget_blobs( array $rows ): void {
		if ( ! class_exists( 'KarMCP_Change_Blobs' ) ) {
			return;
		}
		foreach ( $rows as $r ) {
			$bid = ( isset( $r['rollback']['blob_id'] ) ) ? (string) $r['rollback']['blob_id'] : '';
			if ( '' !== $bid ) {
				KarMCP_Change_Blobs::delete( $bid );
			}
		}
	}

	/**
	 * Undo an entry by id, dispatching on its rollback type. Marks the entry
	 * rolled_back and records a compensating entry.
	 *
	 * @param string $id    Entry id.
	 * @param bool   $force  Roll back even if the target changed since (skips the conflict guard).
	 * @return array|WP_Error
	 */
	public static function rollback( string $id, bool $force = false ) {
		$entry = self::get( $id );
		if ( null === $entry ) {
			return new WP_Error( 'not_found', __( 'Change not found.', 'karmcp' ) );
		}
		if ( ! empty( $entry['rolled_back'] ) ) {
			return new WP_Error( 'already_rolled_back', __( 'This change has already been rolled back.', 'karmcp' ) );
		}
		$rb = ( isset( $entry['rollback'] ) && is_array( $entry['rollback'] ) ) ? $entry['rollback'] : null;
		if ( null === $rb ) {
			return new WP_Error( 'not_reversible', __( 'This change is not reversible.', 'karmcp' ) );
		}

		// Conflict guard: refuse if the target changed after we recorded it,
		// unless the caller forces it. Undoing then would clobber newer edits.
		if ( ! $force ) {
			$unguarded = self::unguarded_creation( $rb );
			if ( is_wp_error( $unguarded ) ) {
				return $unguarded;
			}
			$conflict = self::detect_conflict( $rb );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}

		// Restore the caller's suppression state rather than switching it off:
		// a rollback run from inside another suppressed operation must not
		// start recording that operation's writes halfway through.
		$outer          = self::$suppress;
		self::$suppress = true;
		try {
			$result = self::apply_rollback( $rb );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'rollback_failed', $e->getMessage() );
		} finally {
			self::$suppress = $outer;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! self::mark_rolled_back( $id ) ) {
			return new WP_Error(
				'history_not_updated',
				__( 'The change was undone, but the history could not be updated to say so. Retrying with force is safe: the target no longer matches the recorded state because it was already restored, and every rollback leaves an already-restored target as it is, so the retry only brings the history up to date.', 'karmcp' )
			);
		}
		$comp = self::record( array(
			'domain'   => $entry['domain'] ?? '',
			'action'   => 'rollback',
			'target'   => $entry['target'] ?? '',
			'summary'  => 'Rolled back: ' . ( $entry['summary'] ?? $id ),
			'rollback' => null,
		) );
		$out = array(
			'rolled_back'  => $id,
			'compensating' => $comp,
		);
		// The DB before-image is capped, so a very large write is only partially
		// reversible — flag it so the caller knows the restore is incomplete.
		if ( ! empty( $rb['partial'] ) ) {
			$out['partial']  = true;
			$out['warning']  = __( 'Only part of this change was reversible: the before-image was capped, so some rows were not restored.', 'karmcp' );
		}
		return $out;
	}

	/**
	 * Refuse to undo a creation recorded before creations carried a guard.
	 *
	 * Undoing a creation deletes the post, and an entry without an `after_hash`
	 * cannot say whether anyone has built on it since. That is the one rollback
	 * where "cannot tell" has to mean "ask first" instead of "go ahead", so it
	 * takes `force`.
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function unguarded_creation( array $rb ) {
		if ( 'post-create' !== ( $rb['type'] ?? '' ) || '' !== (string) ( $rb['after_hash'] ?? '' ) ) {
			return true;
		}
		return new WP_Error(
			'unguarded_creation',
			__( 'This creation was recorded before KarMCP could tell whether a post was edited after it was created, so undoing it could delete later work. Check the post, then roll back with force if it should go.', 'karmcp' )
		);
	}

	/**
	 * Detect whether the target changed since the recorded write, by comparing
	 * the stored `after_hash` against the target's current state hash. Returns a
	 * `conflict` WP_Error on mismatch, or true when there is no conflict (or no
	 * hash was recorded / the hash cannot be recomputed, e.g. DB writes).
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function detect_conflict( array $rb ) {
		$expected = isset( $rb['after_hash'] ) ? (string) $rb['after_hash'] : '';
		if ( '' === $expected || ! class_exists( 'KarMCP_Change_Recorder' ) ) {
			return true;
		}
		$current = self::current_hash( $rb );
		if ( '' === $current ) {
			return true; // Cannot recompute — do not block.
		}
		if ( ! hash_equals( $expected, $current ) ) {
			return new WP_Error( 'conflict', __( 'This target has changed since the recorded change. Roll back anyway with force to overwrite the newer state.', 'karmcp' ) );
		}
		return true;
	}

	/**
	 * The target's current state hash, matching how the recorder stamped it.
	 *
	 * @param array $rb Rollback ref.
	 * @return string '' when not hashable for this type.
	 */
	private static function current_hash( array $rb ): string {
		switch ( $rb['type'] ?? '' ) {
			case 'elementor-data':
				return KarMCP_Change_Recorder::hash_elementor( (int) ( $rb['post_id'] ?? 0 ) );
			case 'file-backup':
			case 'file-create':
				return KarMCP_Change_Recorder::hash_file( (string) ( $rb['target_path'] ?? '' ) );
			case 'option':
				if ( isset( $rb['option_keys'] ) && is_array( $rb['option_keys'] ) ) {
					return KarMCP_Change_Recorder::hash_options( $rb['option_keys'] );
				}
				return KarMCP_Change_Recorder::hash_option( (string) ( $rb['option'] ?? '' ) );
			case 'post-fields':
				return KarMCP_Change_Recorder::hash_post( (int) ( $rb['post_id'] ?? 0 ) );
			case 'post-create':
				return KarMCP_Change_Recorder::hash_created( (int) ( $rb['post_id'] ?? 0 ) );
			case 'meta-before-image':
				return KarMCP_Change_Recorder::hash_meta( (string) ( $rb['object'] ?? 'post' ), (int) ( $rb['id'] ?? 0 ), (array) ( $rb['meta_keys'] ?? array() ) );
			default:
				return '';
		}
	}

	/**
	 * Dispatch a rollback by type.
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function apply_rollback( array $rb ) {
		// Resolve an out-of-band before-image (large snapshots live in the blob
		// store; the row carries only a blob_id pointer).
		if ( ! empty( $rb['blob_id'] ) && class_exists( 'KarMCP_Change_Blobs' ) ) {
			$heavy = KarMCP_Change_Blobs::get( (string) $rb['blob_id'] );
			if ( ! is_array( $heavy ) ) {
				return new WP_Error( 'blob_missing', __( 'The saved snapshot for this change is no longer available.', 'karmcp' ) );
			}
			$rb = array_merge( $rb, $heavy );
		}
		switch ( $rb['type'] ?? '' ) {
			case 'elementor-data':
				return self::rollback_elementor( $rb );
			case 'file-backup':
				return self::rollback_file_restore( $rb );
			case 'file-create':
				return self::rollback_file_delete( $rb );
			case 'db-before-image':
				return self::rollback_db( $rb );
			case 'meta-before-image':
				return self::rollback_meta( $rb );
			case 'post-fields':
				return self::rollback_post_fields( $rb );
			case 'post-create':
				return self::rollback_post_create( $rb );
			case 'post-restore':
				return self::rollback_post_restore( $rb );
			case 'option':
				return self::rollback_option( $rb );
			case 'attachment-delete':
				return self::rollback_attachment_delete( $rb );
			case 'user-create':
				return self::rollback_user_create( $rb );
			case 'user-fields':
				return self::rollback_user_fields( $rb );
			case 'acf-fields':
				return self::rollback_acf_fields( $rb );
			case 'redirect-row':
				if ( class_exists( 'KarMCP_Redirect_Store' ) && KarMCP_Redirect_Store::rollback( $rb ) ) {
					return true;
				}
				return new WP_Error( 'rollback_failed', __( 'Could not reverse the redirect change.', 'karmcp' ) );
			default:
				return new WP_Error( 'unknown_rollback', __( 'Unknown rollback type.', 'karmcp' ) );
		}
	}

	/**
	 * Restore a page's prior Elementor data.
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function rollback_elementor( array $rb ) {
		$post_id = (int) ( $rb['post_id'] ?? 0 );
		$before  = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		if ( $post_id <= 0 || ! class_exists( 'KarMCP_Data' ) ) {
			return new WP_Error( 'rollback_failed', __( 'Cannot restore this page.', 'karmcp' ) );
		}
		$data = new KarMCP_Data();
		$res  = $data->save_page_data( $post_id, $before );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Restore a file from its backup (ABSPATH-confined).
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function rollback_file_restore( array $rb ) {
		$target = (string) ( $rb['target_path'] ?? '' );
		$backup = (string) ( $rb['backup_path'] ?? '' );
		if ( '' === $target || '' === $backup || ! is_file( $backup ) ) {
			return new WP_Error( 'rollback_failed', __( 'Backup is unavailable.', 'karmcp' ) );
		}
		$safe = self::guard_target( $target );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		return copy( $backup, $safe ) ? true : new WP_Error( 'rollback_failed', __( 'Could not restore the file.', 'karmcp' ) );
	}

	/**
	 * Delete a file that a recorded write created (ABSPATH-confined).
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function rollback_file_delete( array $rb ) {
		$target = (string) ( $rb['target_path'] ?? '' );
		if ( '' === $target || ! is_file( $target ) ) {
			return true; // Already gone, nothing to undo.
		}
		$safe = self::guard_target( $target );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		return @unlink( $safe ) ? true : new WP_Error( 'rollback_failed', __( 'Could not delete the created file.', 'karmcp' ) );
	}

	/**
	 * Inverse a database write from its before-image.
	 *
	 * @param array $rb Rollback ref.
	 * @return true|WP_Error
	 */
	private static function rollback_db( array $rb ) {
		global $wpdb;
		$table = (string) ( $rb['table'] ?? '' );
		$op    = (string) ( $rb['op'] ?? '' );
		if ( '' === $table ) {
			return new WP_Error( 'rollback_failed', __( 'Missing table.', 'karmcp' ) );
		}
		$keys = (array) ( $rb['key_cols'] ?? array() );
		switch ( $op ) {
			case 'update':
				// A row update MUST be scoped by key columns. Without them we would
				// run an unscoped $wpdb->update touching every row — refuse instead.
				if ( empty( $keys ) ) {
					return new WP_Error( 'rollback_failed', __( 'Cannot roll back this update: no key columns were recorded to scope it safely.', 'karmcp' ) );
				}
				foreach ( (array) ( $rb['before_rows'] ?? array() ) as $row ) {
					$where = array();
					foreach ( $keys as $c ) {
						if ( is_array( $row ) && array_key_exists( $c, $row ) ) {
							$where[ $c ] = $row[ $c ];
						}
					}
					if ( empty( $where ) ) {
						continue; // Never update with an empty WHERE.
					}
					$affected = $wpdb->update( $table, $row, $where );
					if ( false === $affected ) {
						return self::db_failure();
					}
					// Zero rows is either "already as it was" or "the row is
					// gone"; only the first is a restore.
					if ( 0 === $affected && ! self::row_exists( $table, $where ) ) {
						return new WP_Error( 'rollback_failed', __( 'A row this change updated no longer exists, so its prior values could not be restored. Rows restored before it stay restored.', 'karmcp' ) );
					}
				}
				return true;
			case 'delete':
				// Re-insert only what is missing. A retry — after a rollback
				// whose restore landed but whose history write did not — would
				// otherwise insert every row a second time. Identical rows are
				// grouped so a table without a primary key that lost two equal
				// rows gets two back, not one.
				$groups = array();
				foreach ( (array) ( $rb['before_rows'] ?? array() ) as $row ) {
					if ( ! is_array( $row ) || empty( $row ) ) {
						continue;
					}
					// serialize(), not JSON: a binary column is not valid UTF-8,
					// and JSON would fold two different rows into one group.
					$sig = md5( serialize( $row ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a grouping key over values that are already plain strings, never unserialized.
					if ( ! isset( $groups[ $sig ] ) ) {
						$groups[ $sig ] = array( 'row' => $row, 'n' => 0 );
					}
					++$groups[ $sig ]['n'];
				}
				foreach ( $groups as $group ) {
					$missing = $group['n'] - self::count_rows( $table, $group['row'] );
					for ( $i = 0; $i < $missing; $i++ ) {
						if ( false === $wpdb->insert( $table, $group['row'] ) ) {
							return self::db_failure();
						}
					}
				}
				return true;
			case 'insert':
				$key = (array) ( $rb['inserted_key'] ?? array() );
				if ( empty( $key ) ) {
					return new WP_Error( 'rollback_failed', __( 'Cannot roll back this insert: the key of the inserted row was not recorded.', 'karmcp' ) );
				}
				if ( false === $wpdb->delete( $table, $key ) ) {
					return self::db_failure();
				}
				return true;
			default:
				return new WP_Error( 'rollback_failed', __( 'Unknown DB operation.', 'karmcp' ) );
		}
	}

	/**
	 * Whether a row matching every column of `$where` exists.
	 *
	 * @param string $table Table name.
	 * @param array  $where Column => value.
	 * @return bool
	 */
	private static function row_exists( string $table, array $where ): bool {
		return self::count_rows( $table, $where ) > 0;
	}

	/**
	 * How many rows match every column of `$where`.
	 *
	 * @param string $table Table name.
	 * @param array  $where Column => value.
	 * @return int
	 */
	private static function count_rows( string $table, array $where ): int {
		global $wpdb;
		$clauses = array();
		$args    = array( $table );
		foreach ( $where as $col => $value ) {
			if ( null === $value ) {
				$clauses[] = '%i IS NULL';
				$args[]    = (string) $col;
			} else {
				$clauses[] = '%i = %s';
				$args[]    = (string) $col;
				$args[]    = (string) $value;
			}
		}
		if ( empty( $clauses ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- the clause list is built from %i/%s placeholders only; every value goes through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . implode( ' AND ', $clauses ), $args ) );
	}

	/**
	 * The error for a database write that failed during a rollback.
	 *
	 * @return WP_Error
	 */
	private static function db_failure(): WP_Error {
		global $wpdb;
		$detail = ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ? (string) $wpdb->last_error : __( 'no error reported', 'karmcp' );
		return new WP_Error(
			'rollback_failed',
			/* translators: %s: the database error. */
			sprintf( __( 'The database refused part of the rollback (%s). Rows restored before it stay restored.', 'karmcp' ), $detail )
		);
	}

	/**
	 * Re-create a deleted attachment from its snapshot — copy the trashed files
	 * back to their original paths, re-insert the post under its old id, and
	 * restore all meta.
	 *
	 * Refused, before anything is written, when the old id is taken (content
	 * points at an attachment by id, so a restore under a new id would leave
	 * every reference aimed at the wrong thing) and when the snapshot cannot
	 * bring the file back — a restored attachment with no file behind it is a
	 * broken image reported as a successful undo.
	 *
	 * @param array $rb Rollback ref: { snapshot:{ post, meta, files } }.
	 * @return true|WP_Error
	 */
	private static function rollback_attachment_delete( array $rb ) {
		$snap = ( isset( $rb['snapshot'] ) && is_array( $rb['snapshot'] ) ) ? $rb['snapshot'] : array();
		$post = ( isset( $snap['post'] ) && is_array( $snap['post'] ) ) ? $snap['post'] : array();
		if ( empty( $post ) ) {
			return new WP_Error( 'rollback_failed', __( 'No attachment snapshot to restore.', 'karmcp' ) );
		}
		$files = array_values( array_filter( (array) ( $snap['files'] ?? array() ), 'is_array' ) );
		$meta  = (array) ( $snap['meta'] ?? array() );
		if ( empty( $files ) && ! empty( $meta['_wp_attached_file'] ) ) {
			return new WP_Error( 'rollback_refused', __( 'This deletion was recorded without a copy of the file, so undoing it would restore an attachment with nothing behind it.', 'karmcp' ) );
		}
		foreach ( $files as $file ) {
			if ( ! is_file( (string) ( $file['trashed'] ?? '' ) ) ) {
				return new WP_Error( 'rollback_refused', __( 'The saved copy of this attachment\'s file is gone, so it cannot be restored.', 'karmcp' ) );
			}
		}
		$old_id = (int) ( $post['ID'] ?? 0 );
		$taken  = self::id_taken( $old_id, $post );
		if ( is_wp_error( $taken ) ) {
			return $taken;
		}
		if ( 'restored' === $taken ) {
			return true;
		}

		$copied = array();
		foreach ( $files as $file ) {
			$orig   = (string) ( $file['orig'] ?? '' );
			$parent = dirname( $orig );
			if ( ! is_dir( $parent ) && function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $parent );
			}
			if ( '' === $orig || ! @copy( (string) $file['trashed'], $orig ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				self::remove_files( $copied );
				return new WP_Error( 'rollback_failed', __( 'Could not copy the attachment\'s file back into place.', 'karmcp' ) );
			}
			$copied[] = $orig;
		}

		unset( $post['ID'] );
		if ( $old_id > 0 ) {
			$post['import_id'] = $old_id;
		}
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) || ( $old_id > 0 && (int) $new_id !== $old_id ) ) {
			if ( ! is_wp_error( $new_id ) && (int) $new_id > 0 ) {
				wp_delete_post( (int) $new_id, true );
			}
			self::remove_files( $copied );
			return is_wp_error( $new_id ) ? $new_id : new WP_Error( 'rollback_failed', __( 'WordPress did not restore the attachment under its original id.', 'karmcp' ) );
		}
		$new_id = (int) $new_id;
		foreach ( $meta as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return true;
	}

	/**
	 * Refuse a restore when the id it has to reuse belongs to something else.
	 *
	 * Returns 'restored' when the id is held by this same item — an earlier
	 * attempt put it back and only the history write failed — so a retry is a
	 * no-op instead of a refusal. "Same" has to be unambiguous, because a wrong
	 * match skips a real restore and reports success: drafts and attachments
	 * often have no slug, and a batch creates many items in the same second.
	 * So every identifying field the snapshot carries must match — type, title,
	 * slug, date, author, parent, MIME type — and the GUID too when the
	 * snapshot has one, since WordPress makes it unique per item.
	 *
	 * @param int   $old_id The id the restored object must have.
	 * @param array $post   The snapshot's post row.
	 * @return true|string|WP_Error
	 */
	private static function id_taken( int $old_id, array $post ) {
		$current = $old_id > 0 ? get_post( $old_id ) : null;
		if ( $current && self::is_same_item( $current, $post ) ) {
			return 'restored';
		}
		if ( $current ) {
			return new WP_Error(
				'rollback_refused',
				/* translators: %d: post id. */
				sprintf( __( 'Id #%d is already in use by another item, and restoring under a new id would leave everything that points to the old one aimed at the wrong thing.', 'karmcp' ), $old_id )
			);
		}
		return true;
	}

	/**
	 * Whether a post is the item a snapshot describes.
	 *
	 * @param object $current The post now at the id.
	 * @param array  $post    The snapshot's post row.
	 * @return bool
	 */
	private static function is_same_item( $current, array $post ): bool {
		$fields = array( 'post_type', 'post_title', 'post_name', 'post_date', 'post_author', 'post_parent', 'post_mime_type' );
		if ( '' !== (string) ( $post['guid'] ?? '' ) ) {
			$fields[] = 'guid';
		}
		foreach ( $fields as $field ) {
			if ( (string) ( $current->$field ?? '' ) !== (string) ( $post[ $field ] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove files a failed restore had already put back.
	 *
	 * @param string[] $paths Absolute paths.
	 */
	private static function remove_files( array $paths ): void {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Undo a user creation by deleting the user. Refuses if the user has since
	 * gained administrator-level capabilities (safety).
	 *
	 * @param array $rb Rollback ref: { user_id }.
	 * @return true|WP_Error
	 */
	private static function rollback_user_create( array $rb ) {
		$user_id = (int) ( $rb['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return new WP_Error( 'rollback_failed', __( 'Missing user id.', 'karmcp' ) );
		}
		if ( function_exists( 'get_userdata' ) && ! get_userdata( $user_id ) ) {
			return true; // Already gone.
		}
		if ( function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'rollback_refused', __( 'This user now has administrator capabilities and will not be deleted by a rollback.', 'karmcp' ) );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			return new WP_Error( 'rollback_failed', __( 'User deletion is unavailable.', 'karmcp' ) );
		}
		if ( ! wp_delete_user( $user_id ) || ( function_exists( 'get_userdata' ) && get_userdata( $user_id ) ) ) {
			return new WP_Error( 'rollback_failed', __( 'WordPress did not delete the user.', 'karmcp' ) );
		}
		return true;
	}

	/**
	 * Restore a user's prior profile fields.
	 *
	 * @param array $rb Rollback ref: { user_id, before:{ field => value } }.
	 * @return true|WP_Error
	 */
	private static function rollback_user_fields( array $rb ) {
		$user_id = (int) ( $rb['user_id'] ?? 0 );
		$before  = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		if ( $user_id <= 0 || empty( $before ) || ! function_exists( 'wp_update_user' ) ) {
			return new WP_Error( 'rollback_failed', __( 'Cannot restore this user.', 'karmcp' ) );
		}
		$res = wp_update_user( array_merge( array( 'ID' => $user_id ), $before ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Restore ACF field values by re-writing the prior raw values through ACF
	 * (by field key), which correctly reverses simple and complex fields alike.
	 *
	 * @param array $rb Rollback ref: { acf_target, before:{ field_key => value } }.
	 * @return true|WP_Error
	 */
	private static function rollback_acf_fields( array $rb ) {
		if ( ! function_exists( 'update_field' ) ) {
			return new WP_Error( 'rollback_failed', __( 'ACF is not available to restore these fields.', 'karmcp' ) );
		}
		$target = $rb['acf_target'] ?? 0;
		$before = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		if ( empty( $before ) ) {
			return new WP_Error( 'rollback_failed', __( 'No ACF values to restore.', 'karmcp' ) );
		}
		foreach ( $before as $key => $value ) {
			update_field( (string) $key, $value, $target );
		}
		return true;
	}

	/**
	 * Restore option values from a before-image. `values` maps each option to its
	 * prior value, or the marker '__ABSENT__' when it did not exist (deleted on undo).
	 *
	 * @param array $rb Rollback ref: { values:{ option => prior|'__ABSENT__' } } or { option, before }.
	 * @return true|WP_Error
	 */
	private static function rollback_option( array $rb ) {
		$values = ( isset( $rb['values'] ) && is_array( $rb['values'] ) ) ? $rb['values'] : array();
		if ( empty( $values ) ) {
			return new WP_Error( 'rollback_failed', __( 'No option values to restore.', 'karmcp' ) );
		}
		$failed = array();
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( '__ABSENT__' === $value ) {
				delete_option( $name );
				$ok = null === get_option( $name, null );
			} else {
				update_option( $name, $value );
				$ok = self::same_value( get_option( $name, null ), $value );
			}
			if ( ! $ok ) {
				$failed[] = $name;
			}
		}
		return self::unrestored( $failed );
	}

	/**
	 * Restore post/term meta from a before-image.
	 *
	 * Entries marked `exact` (1.42.0 onwards) hold each prior value as it was
	 * and the marker '__ABSENT__' for a key that did not exist, so an empty
	 * prior value is written back instead of deleted. Older entries used an
	 * empty value to mean "unset", and are read that way still.
	 *
	 * Rolling back page settings also drops the page's generated CSS: the
	 * settings are what the stylesheet was built from, and a restore that left
	 * it in place would read back right and render wrong.
	 *
	 * @param array $rb Rollback ref: { object:'post'|'term', id:int, before:array, exact?:bool }.
	 * @return true|WP_Error
	 */
	private static function rollback_meta( array $rb ) {
		$object = 'term' === ( $rb['object'] ?? '' ) ? 'term' : 'post';
		$id     = (int) ( $rb['id'] ?? 0 );
		$before = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		$exact  = ! empty( $rb['exact'] );
		if ( $id <= 0 ) {
			return new WP_Error( 'rollback_failed', __( 'Missing object id.', 'karmcp' ) );
		}
		$failed = array();
		foreach ( $before as $key => $value ) {
			$key    = (string) $key;
			$delete = $exact ? ( '__ABSENT__' === $value ) : ( '' === $value || array() === $value || null === $value );
			if ( 'term' === $object ) {
				$delete ? delete_term_meta( $id, $key ) : update_term_meta( $id, $key, wp_slash( $value ) );
				$now = get_term_meta( $id, $key, true );
			} else {
				$delete ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, wp_slash( $value ) );
				$now = get_post_meta( $id, $key, true );
			}
			$ok = $delete ? ( '' === $now || array() === $now ) : self::same_value( $now, $value );
			if ( ! $ok ) {
				$failed[] = $key;
			}
		}
		if ( 'post' === $object && array_key_exists( '_elementor_page_settings', $before ) ) {
			self::drop_generated_css( $id );
		}
		return self::unrestored( $failed );
	}

	/**
	 * Throw away a post's generated Elementor CSS so it rebuilds from the
	 * restored data.
	 *
	 * @param int $post_id Post id.
	 */
	private static function drop_generated_css( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_css' );
		if ( class_exists( 'KarMCP_Data' ) ) {
			delete_post_meta( $post_id, KarMCP_Data::ELEMENT_CACHE_META );
		}
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$upload = wp_get_upload_dir();
			$css    = rtrim( (string) ( $upload['basedir'] ?? '' ), '/\\' ) . '/elementor/css/post-' . $post_id . '.css';
			if ( is_file( $css ) ) {
				wp_delete_file( $css );
			}
		}
	}

	/**
	 * Whether a value read back matches the one written.
	 *
	 * The database hands scalars back as strings, so 5 and "5" are the same
	 * value here; anything structured has to match exactly.
	 *
	 * @param mixed $actual   Value read back.
	 * @param mixed $expected Value written.
	 * @return bool
	 */
	public static function same_value( $actual, $expected ): bool {
		if ( is_bool( $expected ) ) {
			$expected = $expected ? '1' : '';
		}
		if ( is_bool( $actual ) ) {
			$actual = $actual ? '1' : '';
		}
		if ( ( is_scalar( $actual ) || null === $actual ) && ( is_scalar( $expected ) || null === $expected ) ) {
			return (string) $actual === (string) $expected;
		}
		return maybe_serialize( $actual ) === maybe_serialize( $expected );
	}

	/**
	 * Turn a list of keys that did not take into the rollback's result.
	 *
	 * @param string[] $failed Keys whose restored value did not read back.
	 * @return true|WP_Error
	 */
	private static function unrestored( array $failed ) {
		if ( empty( $failed ) ) {
			return true;
		}
		return new WP_Error(
			'rollback_failed',
			/* translators: %s: comma-separated list of option or meta keys. */
			sprintf( __( 'These values did not read back as restored: %s. The rest were restored.', 'karmcp' ), implode( ', ', $failed ) )
		);
	}

	/**
	 * Restore a post's prior fields, meta, and terms (content/media/Gutenberg).
	 *
	 * @param array $rb Rollback ref: { post_id, before:{ fields, meta, terms } }.
	 * @return true|WP_Error
	 */
	private static function rollback_post_fields( array $rb ) {
		$post_id = (int) ( $rb['post_id'] ?? 0 );
		$before  = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new WP_Error( 'rollback_failed', __( 'The post no longer exists.', 'karmcp' ) );
		}
		$fields = ( isset( $before['fields'] ) && is_array( $before['fields'] ) ) ? $before['fields'] : array();
		if ( ! empty( $fields ) ) {
			$fields['ID'] = $post_id;
			$res          = wp_update_post( wp_slash( $fields ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( ! $res ) {
				return new WP_Error( 'rollback_failed', __( 'WordPress did not restore the post fields.', 'karmcp' ) );
			}
		}
		$failed = array();
		foreach ( (array) ( $before['meta'] ?? array() ) as $key => $value ) {
			$key = (string) $key;
			if ( '__DELETE__' === $value ) {
				delete_post_meta( $post_id, $key );
				$now = get_post_meta( $post_id, $key, true );
				$ok  = '' === $now || array() === $now;
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
				$ok = self::same_value( get_post_meta( $post_id, $key, true ), $value );
			}
			if ( ! $ok ) {
				$failed[] = $key;
			}
		}
		foreach ( (array) ( $before['terms'] ?? array() ) as $tax => $ids ) {
			$res = wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), (string) $tax, false );
			if ( is_wp_error( $res ) ) {
				$failed[] = (string) $tax;
			}
		}
		return self::unrestored( $failed );
	}

	/**
	 * Undo a post creation by deleting the created post.
	 *
	 * An attachment goes through wp_delete_attachment(), which removes its
	 * files as well, and the files recorded at creation are then checked: any
	 * still on disk inside the uploads folder is removed here, and any that
	 * cannot be is reported. WordPress compares paths as strings before
	 * deleting a generated size, and on Windows a mix of separators is enough
	 * for it to leave the sizes behind while reporting success.
	 *
	 * @param array $rb Rollback ref: { post_id, files? }.
	 * @return true|WP_Error
	 */
	private static function rollback_post_create( array $rb ) {
		$post_id = (int) ( $rb['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'rollback_failed', __( 'Missing post id.', 'karmcp' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return true; // Already gone.
		}
		if ( 'attachment' === ( $post->post_type ?? '' ) && function_exists( 'wp_delete_attachment' ) ) {
			wp_delete_attachment( $post_id, true );
		} else {
			wp_delete_post( $post_id, true );
		}
		if ( get_post( $post_id ) ) {
			return new WP_Error(
				'rollback_failed',
				/* translators: %d: post id. */
				sprintf( __( 'WordPress did not delete #%d.', 'karmcp' ), $post_id )
			);
		}

		$left = array();
		foreach ( (array) ( $rb['files'] ?? array() ) as $file ) {
			$file = (string) $file;
			if ( '' === $file || ! is_file( $file ) ) {
				continue;
			}
			if ( self::inside_uploads( $file ) ) {
				wp_delete_file( $file );
			}
			if ( is_file( $file ) ) {
				$left[] = basename( $file );
			}
		}
		if ( $left ) {
			return new WP_Error(
				'rollback_incomplete',
				/* translators: %s: comma-separated file names. */
				sprintf( __( 'The attachment was deleted but these files are still on disk: %s.', 'karmcp' ), implode( ', ', $left ) )
			);
		}
		return true;
	}

	/**
	 * Whether a path lies inside the uploads folder, compared on normalised
	 * real paths so Windows separators and symlinked roots agree.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function inside_uploads( string $path ): bool {
		if ( ! function_exists( 'wp_get_upload_dir' ) || ! function_exists( 'wp_normalize_path' ) ) {
			return false;
		}
		$upload = wp_get_upload_dir();
		$base   = realpath( (string) ( $upload['basedir'] ?? '' ) );
		$real   = realpath( $path );
		if ( false === $base || false === $real ) {
			return false;
		}
		$base = rtrim( wp_normalize_path( $base ), '/' ) . '/';
		return 0 === strpos( wp_normalize_path( $real ), $base );
	}

	/**
	 * Undo a post deletion — untrash a trashed post, or re-insert a force-deleted
	 * one from its snapshot (post + meta + terms), preserving the id when free.
	 *
	 * @param array $rb Rollback ref: { mode:'untrash'|'reinsert', post_id?, snapshot? }.
	 * @return true|WP_Error
	 */
	private static function rollback_post_restore( array $rb ) {
		if ( 'untrash' === ( $rb['mode'] ?? '' ) ) {
			$post_id = (int) ( $rb['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				return new WP_Error( 'rollback_failed', __( 'Missing post id.', 'karmcp' ) );
			}
			$current = get_post( $post_id );
			if ( $current && 'trash' !== ( $current->post_status ?? '' ) ) {
				// WordPress deletes the trash bookkeeping when it untrashes a
				// post. With it gone, the post came out the way an untrash
				// brings it out — ours, on a retry, or anyone's. With it still
				// there, the status was changed some other way, and this is
				// not the restore the entry describes.
				if ( '' === (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ) ) {
					return true;
				}
				return new WP_Error(
					'rollback_failed',
					/* translators: %s: post status. */
					sprintf( __( 'The post is no longer in the trash, but it was not restored from it: its status was changed to "%s" some other way. Check it by hand.', 'karmcp' ), (string) $current->post_status )
				);
			}
			if ( ! wp_untrash_post( $post_id ) ) {
				return new WP_Error( 'rollback_failed', __( 'WordPress did not restore the post from the trash.', 'karmcp' ) );
			}
			return true;
		}
		$snap = ( isset( $rb['snapshot'] ) && is_array( $rb['snapshot'] ) ) ? $rb['snapshot'] : array();
		$post = ( isset( $snap['post'] ) && is_array( $snap['post'] ) ) ? $snap['post'] : array();
		if ( empty( $post ) ) {
			return new WP_Error( 'rollback_failed', __( 'No snapshot to restore.', 'karmcp' ) );
		}
		$old_id = (int) ( $post['ID'] ?? 0 );
		$taken  = self::id_taken( $old_id, $post );
		if ( is_wp_error( $taken ) ) {
			return $taken;
		}
		if ( 'restored' === $taken ) {
			return true;
		}
		unset( $post['ID'] );
		if ( $old_id > 0 ) {
			$post['import_id'] = $old_id;
		}
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		if ( $old_id > 0 && (int) $new_id !== $old_id ) {
			wp_delete_post( (int) $new_id, true );
			return new WP_Error( 'rollback_failed', __( 'WordPress did not restore the post under its original id.', 'karmcp' ) );
		}
		$new_id = (int) $new_id;
		foreach ( (array) ( $snap['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		foreach ( (array) ( $snap['terms'] ?? array() ) as $tax => $ids ) {
			wp_set_object_terms( $new_id, array_map( 'intval', (array) $ids ), (string) $tax, false );
		}
		return true;
	}

	/**
	 * Confine a filesystem target to ABSPATH via the shared guard.
	 *
	 * @param string $target Absolute path.
	 * @return string|WP_Error Canonical path or error.
	 */
	private static function guard_target( string $target ) {
		if ( class_exists( 'KarMCP_Filesystem_Guard' ) ) {
			return KarMCP_Filesystem_Guard::resolve_path( $target );
		}
		return $target;
	}

	/**
	 * A short unique id.
	 *
	 * @return string
	 */
	private static function uid(): string {
		return substr( md5( uniqid( '', true ) ), 0, 12 );
	}

	/**
	 * Current user login (best-effort).
	 *
	 * @return string
	 */
	private static function current_login(): string {
		if ( function_exists( 'wp_get_current_user' ) ) {
			$u = wp_get_current_user();
			if ( $u && isset( $u->user_login ) ) {
				return (string) $u->user_login;
			}
		}
		return '';
	}
}
