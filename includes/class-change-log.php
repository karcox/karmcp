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
	 */
	public static function mark_rolled_back( string $id ): void {
		$log = self::all();
		foreach ( $log as &$e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				$e['rolled_back'] = true;
			}
		}
		unset( $e );
		update_option( self::OPTION, $log, false );
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
			$conflict = self::detect_conflict( $rb );
			if ( is_wp_error( $conflict ) ) {
				return $conflict;
			}
		}

		self::$suppress = true;
		try {
			$result = self::apply_rollback( $rb );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'rollback_failed', $e->getMessage() );
		} finally {
			self::$suppress = false;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::mark_rolled_back( $id );
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
					$wpdb->update( $table, $row, $where );
				}
				return true;
			case 'delete':
				foreach ( (array) ( $rb['before_rows'] ?? array() ) as $row ) {
					$wpdb->insert( $table, $row );
				}
				return true;
			case 'insert':
				$wpdb->delete( $table, (array) ( $rb['inserted_key'] ?? array() ) );
				return true;
			default:
				return new WP_Error( 'rollback_failed', __( 'Unknown DB operation.', 'karmcp' ) );
		}
	}

	/**
	 * Re-create a deleted attachment from its snapshot — re-insert the post,
	 * restore all meta, and copy the trashed files back to their original paths.
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
		$old_id = (int) ( $post['ID'] ?? 0 );
		unset( $post['ID'] );
		if ( $old_id > 0 && ! get_post( $old_id ) ) {
			$post['import_id'] = $old_id;
		}
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;
		foreach ( (array) ( $snap['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, maybe_unserialize( $value ) );
			}
		}
		foreach ( (array) ( $snap['files'] ?? array() ) as $file ) {
			$orig    = (string) ( $file['orig'] ?? '' );
			$trashed = (string) ( $file['trashed'] ?? '' );
			if ( '' !== $orig && is_file( $trashed ) ) {
				$parent = dirname( $orig );
				if ( ! is_dir( $parent ) && function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $parent );
				}
				@copy( $trashed, $orig ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		return true;
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
		wp_delete_user( $user_id );
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
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			if ( '__ABSENT__' === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}
		return true;
	}

	/**
	 * Restore post/term meta from a before-image.
	 *
	 * `before` maps meta_key => prior value (as returned by get_*_meta(single);
	 * an empty string/array means the key was unset, so it is deleted on undo).
	 *
	 * @param array $rb Rollback ref: { object:'post'|'term', id:int, before:array }.
	 * @return true|WP_Error
	 */
	private static function rollback_meta( array $rb ) {
		$object = 'term' === ( $rb['object'] ?? '' ) ? 'term' : 'post';
		$id     = (int) ( $rb['id'] ?? 0 );
		$before = ( isset( $rb['before'] ) && is_array( $rb['before'] ) ) ? $rb['before'] : array();
		if ( $id <= 0 ) {
			return new WP_Error( 'rollback_failed', __( 'Missing object id.', 'karmcp' ) );
		}
		foreach ( $before as $key => $value ) {
			$key   = (string) $key;
			$empty = '' === $value || array() === $value || null === $value;
			if ( 'term' === $object ) {
				$empty ? delete_term_meta( $id, $key ) : update_term_meta( $id, $key, $value );
			} else {
				$empty ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, $value );
			}
		}
		return true;
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
			wp_update_post( wp_slash( $fields ) );
		}
		foreach ( (array) ( $before['meta'] ?? array() ) as $key => $value ) {
			$key = (string) $key;
			if ( '__DELETE__' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
		foreach ( (array) ( $before['terms'] ?? array() ) as $tax => $ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', (array) $ids ), (string) $tax, false );
		}
		return true;
	}

	/**
	 * Undo a post creation by deleting the created post.
	 *
	 * @param array $rb Rollback ref: { post_id }.
	 * @return true|WP_Error
	 */
	private static function rollback_post_create( array $rb ) {
		$post_id = (int) ( $rb['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new WP_Error( 'rollback_failed', __( 'Missing post id.', 'karmcp' ) );
		}
		if ( ! get_post( $post_id ) ) {
			return true; // Already gone.
		}
		wp_delete_post( $post_id, true );
		return true;
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
			wp_untrash_post( $post_id );
			return true;
		}
		$snap = ( isset( $rb['snapshot'] ) && is_array( $rb['snapshot'] ) ) ? $rb['snapshot'] : array();
		$post = ( isset( $snap['post'] ) && is_array( $snap['post'] ) ) ? $snap['post'] : array();
		if ( empty( $post ) ) {
			return new WP_Error( 'rollback_failed', __( 'No snapshot to restore.', 'karmcp' ) );
		}
		$old_id = (int) ( $post['ID'] ?? 0 );
		unset( $post['ID'] );
		if ( $old_id > 0 && ! get_post( $old_id ) ) {
			$post['import_id'] = $old_id; // Reuse the original id when it is free.
		}
		$new_id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		$new_id = (int) $new_id;
		foreach ( (array) ( $snap['meta'] ?? array() ) as $key => $values ) {
			foreach ( (array) $values as $value ) {
				add_post_meta( $new_id, (string) $key, maybe_unserialize( $value ) );
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
