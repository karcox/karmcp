<?php
/**
 * Recorder façade for the change ledger.
 *
 * Every write site records through here rather than calling
 * KarMCP_Change_Log::record() directly. The recorder (1) offloads large
 * before-images to the durable KarMCP_Change_Blobs store so the ledger row
 * stays light, and (2) stamps an `after_hash` of the just-written state so
 * rollback can detect that the target changed underneath it (conflict guard).
 *
 * @package KarMCP
 * @since   3.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change recorder.
 *
 * @since 3.10.0
 */
class KarMCP_Change_Recorder {

	/** Before-images larger than this (bytes, JSON-encoded) go to the blob store. */
	const BLOB_THRESHOLD = 4096;

	/**
	 * Marks a value that did not exist before the write, so undo deletes it.
	 *
	 * An empty string is a value a meta key can really hold, so it cannot double
	 * as "absent" — that is how an undo used to delete a meta row whose prior
	 * value happened to be empty.
	 */
	const ABSENT = '__ABSENT__';

	/**
	 * Record an Elementor page edit.
	 *
	 * @param int    $post_id     Page id.
	 * @param array  $before_tree Prior _elementor_data tree.
	 * @param string $summary     Human summary.
	 * @param string $target      Human target label.
	 * @return string Ledger entry id ('' when suppressed).
	 */
	public static function record_elementor( int $post_id, array $before_tree, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		$rb               = self::attach_before( array( 'type' => 'elementor-data', 'post_id' => $post_id ), array( 'before' => $before_tree ) );
		$rb['after_hash'] = self::hash_elementor( $post_id );
		return KarMCP_Change_Log::record( array(
			'domain'   => 'elementor',
			'action'   => 'page-edit',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record a database write. The caller supplies the full ledger entry (with a
	 * `db-before-image` rollback ref); the recorder offloads a large `before_rows`
	 * array to the blob store.
	 *
	 * @param array $entry Ledger entry.
	 * @return string Ledger entry id.
	 */
	public static function record_db( array $entry ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		$rb = ( isset( $entry['rollback'] ) && is_array( $entry['rollback'] ) ) ? $entry['rollback'] : array();
		if ( isset( $rb['before_rows'] ) && is_array( $rb['before_rows'] ) ) {
			$heavy = array( 'before_rows' => $rb['before_rows'] );
			unset( $rb['before_rows'] );
			$rb = self::attach_before( $rb, $heavy );
		}
		$entry['rollback'] = $rb;
		return KarMCP_Change_Log::record( $entry );
	}

	/**
	 * Record a filesystem write. Stamps an after-hash of the written file so a
	 * later external change is detected on rollback.
	 *
	 * @param array  $entry       Ledger entry (with a file-* rollback ref).
	 * @param string $written_abs Absolute path of the file just written.
	 * @return string Ledger entry id.
	 */
	public static function record_file( array $entry, string $written_abs ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		if ( isset( $entry['rollback'] ) && is_array( $entry['rollback'] ) ) {
			$entry['rollback']['after_hash'] = self::hash_file( $written_abs );
		}
		return KarMCP_Change_Log::record( $entry );
	}

	/**
	 * Record a partial post edit (content/media/Gutenberg). `$before` is
	 * { fields:{col:val}, meta:{key:val|__DELETE__}, terms:{tax:[ids]} } — only
	 * the parts the write can change.
	 *
	 * @param int    $post_id Post id.
	 * @param array  $before  Before-image (fields/meta/terms).
	 * @param string $summary Human summary.
	 * @param string $target  Human target label.
	 * @param string $domain  Ledger domain (default 'content').
	 * @param string $action  Ledger action (default 'update-post').
	 * @return string
	 */
	public static function record_post_fields( int $post_id, array $before, string $summary, string $target = '', string $domain = 'content', string $action = 'update-post' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		$rb               = self::attach_before( array( 'type' => 'post-fields', 'post_id' => $post_id ), array( 'before' => $before ) );
		$rb['after_hash'] = self::hash_post( $post_id );
		return KarMCP_Change_Log::record( array(
			'domain'   => $domain,
			'action'   => $action,
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record a post creation (undo = delete the created post).
	 *
	 * Call it once the creation is complete — after the initial content, meta
	 * and attachment processing — because the entry is stamped with a hash of
	 * the post as it stands now. Undo compares against that stamp and refuses
	 * when the post has been edited since: deleting a page someone went on to
	 * build would take their work with it. For an attachment the stamp covers
	 * the file's bytes and metadata, and the files WordPress manages for it are
	 * listed so undo can check they are really gone.
	 *
	 * @param int    $post_id Post id.
	 * @param string $summary Human summary.
	 * @param string $target  Human target label.
	 * @param string $action  Ledger action (default 'create-post').
	 * @param string $domain  Ledger domain (default 'content').
	 * @return string
	 */
	public static function record_post_create( int $post_id, string $summary, string $target = '', string $action = 'create-post', string $domain = 'content' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		$rb = array(
			'type'       => 'post-create',
			'post_id'    => $post_id,
			'after_hash' => self::hash_created( $post_id ),
		);
		if ( 'attachment' === self::post_type_of( $post_id ) ) {
			$rb['files'] = self::attachment_paths( $post_id );
		}
		return KarMCP_Change_Log::record( array(
			'domain'   => $domain,
			'action'   => $action,
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record a post deletion. A trash is undone by untrashing; a force-delete is
	 * undone by re-inserting from the snapshot (post + meta + terms).
	 *
	 * @param int    $post_id  Post id.
	 * @param array  $snapshot Full snapshot (from snapshot_post()); only used when $forced.
	 * @param bool   $forced   True = permanent delete; false = trashed.
	 * @param string $summary  Human summary.
	 * @param string $target   Human target label.
	 * @return string
	 */
	public static function record_post_delete( int $post_id, array $snapshot, bool $forced, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		if ( $forced ) {
			$rb = self::attach_before( array( 'type' => 'post-restore', 'mode' => 'reinsert' ), array( 'snapshot' => $snapshot ) );
		} else {
			$rb = array( 'type' => 'post-restore', 'mode' => 'untrash', 'post_id' => $post_id );
		}
		return KarMCP_Change_Log::record( array(
			'domain'   => 'content',
			'action'   => 'delete-post',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record a settings write (one or more options). `$before_map` maps each
	 * changed option name to its prior value, or the marker '__ABSENT__' when the
	 * option did not exist.
	 *
	 * @param array  $before_map { option => prior value | '__ABSENT__' }.
	 * @param string $summary    Human summary.
	 * @param string $target     Human target label.
	 * @param string $domain     Ledger domain (default 'settings').
	 * @param string $action     Ledger action (default 'update-settings').
	 * @return string
	 */
	public static function record_options( array $before_map, string $summary, string $target = '', string $domain = 'settings', string $action = 'update-settings' ): string {
		if ( KarMCP_Change_Log::$suppress || empty( $before_map ) ) {
			return '';
		}
		$rb = self::attach_before( array( 'type' => 'option' ), array( 'values' => $before_map ) );
		$rb['option_keys'] = array_keys( $before_map );
		$rb['after_hash']  = self::hash_options( array_keys( $before_map ) );
		return KarMCP_Change_Log::record( array(
			'domain'   => $domain,
			'action'   => $action,
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record a redirect write. `$action` is 'create'|'update'|'delete'; `$before`
	 * is the rollback before-image: { id } for create (undo = delete the row) or
	 * { row: <full prior row> } for update/delete (undo = restore / re-insert).
	 *
	 * @param string $action  create|update|delete.
	 * @param array  $before  Before-image.
	 * @param string $summary Human summary.
	 * @param string $target  Human target label.
	 * @return string
	 */
	public static function record_redirect( string $action, array $before, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		return KarMCP_Change_Log::record( array(
			'domain'   => 'redirect',
			'action'   => $action,
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => array( 'type' => 'redirect-row', 'action' => $action, 'before' => $before ),
		) );
	}

	/**
	 * Record a post/term meta write (globals, page settings). `$before_map` maps
	 * each meta key to its prior value, or to self::ABSENT when the key did not
	 * exist — read it with meta_before(), which tells the two apart. The entry is
	 * marked `exact`, so undo writes each value back as it was, empty ones
	 * included, and deletes only the keys marked absent.
	 *
	 * @param string $object     'post' | 'term'.
	 * @param int    $id         Object id.
	 * @param array  $before_map { meta_key => prior value }.
	 * @param string $summary    Human summary.
	 * @param string $target     Human target label.
	 * @param string $domain     Ledger domain.
	 * @param string $action     Ledger action.
	 * @return string
	 */
	public static function record_meta( string $object, int $id, array $before_map, string $summary, string $target = '', string $domain = 'content', string $action = 'update' ): string {
		if ( KarMCP_Change_Log::$suppress || empty( $before_map ) ) {
			return '';
		}
		$keys              = array_keys( $before_map );
		$rb                = self::attach_before( array( 'type' => 'meta-before-image', 'object' => $object, 'id' => $id, 'meta_keys' => $keys, 'exact' => true ), array( 'before' => $before_map ) );
		$rb['after_hash']  = self::hash_meta( $object, $id, $keys );
		return KarMCP_Change_Log::record( array(
			'domain'   => $domain,
			'action'   => $action,
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * A meta key's current value, or self::ABSENT when the key does not exist.
	 *
	 * @param string $object 'post' | 'term'.
	 * @param int    $id     Object id.
	 * @param string $key    Meta key.
	 * @return mixed
	 */
	public static function meta_before( string $object, int $id, string $key ) {
		if ( function_exists( 'metadata_exists' ) && ! metadata_exists( $object, $id, $key ) ) {
			return self::ABSENT;
		}
		return 'term' === $object ? get_term_meta( $id, $key, true ) : get_post_meta( $id, $key, true );
	}

	/**
	 * Hash of a set of options' current values.
	 *
	 * @param string[] $names Option names.
	 * @return string
	 */
	public static function hash_options( array $names ): string {
		$snap = array();
		sort( $names );
		foreach ( $names as $n ) {
			$snap[ (string) $n ] = maybe_serialize( get_option( (string) $n, null ) );
		}
		return sha1( (string) wp_json_encode( $snap ) );
	}

	/**
	 * Record a user creation (undo = delete the created user).
	 *
	 * @param int    $user_id User id.
	 * @param string $summary Human summary.
	 * @param string $target  Human target label.
	 * @return string
	 */
	public static function record_user_create( int $user_id, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress ) {
			return '';
		}
		return KarMCP_Change_Log::record( array(
			'domain'   => 'users',
			'action'   => 'create-user',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => array( 'type' => 'user-create', 'user_id' => $user_id ),
		) );
	}

	/**
	 * Record a user profile update. `$before` maps wp_update_user field keys
	 * (user_email, first_name, …) to their prior values.
	 *
	 * @param int    $user_id User id.
	 * @param array  $before  Prior field values.
	 * @param string $summary Human summary.
	 * @param string $target  Human target label.
	 * @return string
	 */
	public static function record_user_fields( int $user_id, array $before, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress || empty( $before ) ) {
			return '';
		}
		$rb = self::attach_before( array( 'type' => 'user-fields', 'user_id' => $user_id ), array( 'before' => $before ) );
		return KarMCP_Change_Log::record( array(
			'domain'   => 'users',
			'action'   => 'update-user',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Record an ACF field write. `$before` maps field KEY to its prior raw value;
	 * `$acf_target` is the post id or options-page selector the fields live on.
	 *
	 * @param int|string $acf_target Post id or options-page selector.
	 * @param array      $before     { field_key => prior raw value }.
	 * @param string     $summary    Human summary.
	 * @param string     $target     Human target label.
	 * @return string
	 */
	public static function record_acf_fields( $acf_target, array $before, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress || empty( $before ) ) {
			return '';
		}
		$rb = self::attach_before( array( 'type' => 'acf-fields', 'acf_target' => $acf_target ), array( 'before' => $before ) );
		return KarMCP_Change_Log::record( array(
			'domain'   => 'acf',
			'action'   => 'update-fields',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Snapshot an attachment for a reversible delete: post row + all meta + a
	 * trashed copy of every file (main + sub-sizes), so it can be re-created.
	 * MUST be called BEFORE wp_delete_attachment (which removes the files).
	 *
	 * @param int $att_id Attachment id.
	 * @return array { post, meta, files:[{orig,trashed}] } ('' post => empty).
	 */
	public static function snapshot_attachment( int $att_id ): array {
		$obj = get_post( $att_id );
		if ( ! $obj ) {
			return array();
		}
		return array(
			'post'  => (array) $obj,
			'meta'  => (array) get_post_meta( $att_id ),
			'files' => self::trash_attachment_files( $att_id ),
		);
	}

	/**
	 * Record a media deletion from a snapshot captured by snapshot_attachment().
	 *
	 * @param array  $snapshot Snapshot (post + meta + trashed files).
	 * @param int    $att_id   Attachment id.
	 * @param string $summary  Human summary.
	 * @param string $target   Human target label.
	 * @return string
	 */
	public static function record_attachment_delete( array $snapshot, int $att_id, string $summary, string $target = '' ): string {
		if ( KarMCP_Change_Log::$suppress || empty( $snapshot ) ) {
			return '';
		}
		$rb = self::attach_before( array( 'type' => 'attachment-delete', 'att_id' => $att_id ), array( 'snapshot' => $snapshot ) );
		return KarMCP_Change_Log::record( array(
			'domain'   => 'media',
			'action'   => 'delete-media',
			'target'   => $target,
			'summary'  => $summary,
			'rollback' => $rb,
		) );
	}

	/**
	 * Copy an attachment's files (main + sub-sizes) into a managed trash dir under
	 * uploads, so a delete is reversible. Returns [{ orig, trashed }].
	 *
	 * @param int $att_id Attachment id.
	 * @return array
	 */
	private static function trash_attachment_files( int $att_id ): array {
		$out   = array();
		$paths = self::attachment_paths( $att_id );
		if ( empty( $paths ) ) {
			return $out;
		}
		$up    = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array( 'basedir' => sys_get_temp_dir() );
		$trash = rtrim( (string) ( $up['basedir'] ?? sys_get_temp_dir() ), '/\\' ) . '/karmcp-originals/trash/' . $att_id;
		if ( ! is_dir( $trash ) && function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $trash );
		}
		foreach ( $paths as $orig ) {
			if ( is_file( $orig ) && is_dir( $trash ) ) {
				$dest = $trash . '/' . basename( $orig );
				if ( @copy( $orig, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$out[] = array( 'orig' => $orig, 'trashed' => $dest );
				}
			}
		}
		return $out;
	}

	/**
	 * The files WordPress manages for an attachment — the main file, every
	 * generated size, and the pre-scaling original — as absolute paths.
	 *
	 * @param int $att_id Attachment id.
	 * @return string[]
	 */
	public static function attachment_paths( int $att_id ): array {
		if ( ! function_exists( 'get_attached_file' ) ) {
			return array();
		}
		$main = (string) get_attached_file( $att_id );
		if ( '' === $main ) {
			return array();
		}
		$dir   = dirname( $main );
		$paths = array( $main );
		$amd   = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $att_id ) : array();
		if ( is_array( $amd ) && ! empty( $amd['sizes'] ) && is_array( $amd['sizes'] ) ) {
			foreach ( $amd['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$paths[] = $dir . '/' . $size['file'];
				}
			}
		}
		if ( is_array( $amd ) && ! empty( $amd['original_image'] ) ) {
			$paths[] = $dir . '/' . $amd['original_image'];
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Full snapshot of a post for reversible force-delete: post row + all meta +
	 * its terms across every taxonomy for the type.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function snapshot_post( int $post_id ): array {
		$obj = get_post( $post_id );
		if ( ! $obj ) {
			return array();
		}
		$post  = (array) $obj;
		$terms = array();
		foreach ( get_object_taxonomies( (string) ( $post['post_type'] ?? 'post' ) ) as $tax ) {
			$ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
				$terms[ $tax ] = array_map( 'intval', $ids );
			}
		}
		return array(
			'post'  => $post,
			'meta'  => (array) get_post_meta( $post_id ),
			'terms' => $terms,
		);
	}

	// ---------------------------------------------------------------------
	// Before-image offload
	// ---------------------------------------------------------------------

	/**
	 * Attach a heavy before-image to a rollback ref — inline when small, or in
	 * the blob store (as `blob_id`) when it exceeds BLOB_THRESHOLD.
	 *
	 * @param array $rb    Rollback ref (routing fields).
	 * @param array $heavy The bulk before-image, e.g. { before: [...] }.
	 * @return array
	 */
	public static function attach_before( array $rb, array $heavy ): array {
		$json = (string) wp_json_encode( $heavy );
		if ( strlen( $json ) > self::BLOB_THRESHOLD && class_exists( 'KarMCP_Change_Blobs' ) ) {
			$blob = KarMCP_Change_Blobs::put( $heavy );
			if ( '' !== $blob ) {
				$rb['blob_id'] = $blob;
				return $rb;
			}
		}
		return array_merge( $rb, $heavy );
	}

	// ---------------------------------------------------------------------
	// State hashes (conflict guard)
	// ---------------------------------------------------------------------

	/**
	 * Hash of a page's current Elementor data.
	 *
	 * @param int $post_id Page id.
	 * @return string
	 */
	public static function hash_elementor( int $post_id ): string {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		return sha1( is_string( $raw ) ? $raw : (string) wp_json_encode( $raw ) );
	}

	/**
	 * Hash of a post's current core fields (title/content/status/excerpt/name/
	 * parent/menu_order) — the conflict indicator for post-fields rollbacks.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function hash_post( int $post_id ): string {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return '';
		}
		return sha1( (string) wp_json_encode( array(
			(string) ( $p->post_title ?? '' ),
			(string) ( $p->post_content ?? '' ),
			(string) ( $p->post_status ?? '' ),
			(string) ( $p->post_excerpt ?? '' ),
			(string) ( $p->post_name ?? '' ),
			(int) ( $p->post_parent ?? 0 ),
			(int) ( $p->menu_order ?? 0 ),
		) ) );
	}

	/**
	 * Hash of a created post as it stands: its core fields, its Elementor data
	 * and page settings, and for an attachment its alt text, metadata and the
	 * bytes of its file. The creation guard compares against this.
	 *
	 * Dates are left out on purpose: WordPress keeps moving the date of an
	 * undated draft, and a guard that counted that as an edit would refuse every
	 * undo of a draft.
	 *
	 * @param int $post_id Post id.
	 * @return string '' when the post does not exist.
	 */
	public static function hash_created( int $post_id ): string {
		$core = self::hash_post( $post_id );
		if ( '' === $core ) {
			return '';
		}
		$parts = array(
			$core,
			self::hash_elementor( $post_id ),
			maybe_serialize( get_post_meta( $post_id, '_elementor_page_settings', true ) ),
			(string) get_post_meta( $post_id, '_thumbnail_id', true ),
			self::hash_terms( $post_id ),
		);
		if ( 'attachment' === self::post_type_of( $post_id ) ) {
			$file    = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $post_id ) : '';
			$parts[] = maybe_serialize( get_post_meta( $post_id, '_wp_attachment_image_alt', true ) );
			$parts[] = maybe_serialize( get_post_meta( $post_id, '_wp_attachment_metadata', true ) );
			$parts[] = $file;
			$parts[] = self::hash_file( $file );
		}
		return sha1( implode( '|', $parts ) );
	}

	/**
	 * Hash of the terms a post is assigned, across every taxonomy of its type.
	 *
	 * Free-form post meta is deliberately not part of the creation guard: view
	 * counters and similar plugins write public meta on every visit, and a guard
	 * that counted those as edits would refuse every undo on a live site.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function hash_terms( int $post_id ): string {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'wp_get_object_terms' ) ) {
			return '';
		}
		$terms = array();
		foreach ( (array) get_object_taxonomies( self::post_type_of( $post_id ) ) as $tax ) {
			$ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_array( $ids ) && $ids ) {
				$ids = array_map( 'intval', $ids );
				sort( $ids );
				$terms[ (string) $tax ] = $ids;
			}
		}
		ksort( $terms );
		return sha1( (string) wp_json_encode( $terms ) );
	}

	/**
	 * The post type of a post, '' when it does not exist.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	private static function post_type_of( int $post_id ): string {
		$p = get_post( $post_id );
		return ( $p && isset( $p->post_type ) ) ? (string) $p->post_type : '';
	}

	/**
	 * Hash of a file's current bytes ('' when absent).
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function hash_file( string $abs ): string {
		return ( '' !== $abs && is_file( $abs ) ) ? (string) sha1_file( $abs ) : '';
	}

	/**
	 * Hash of an option's current value.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	public static function hash_option( string $name ): string {
		return sha1( maybe_serialize( get_option( $name, null ) ) );
	}

	/**
	 * Hash of a subset of an object's current meta values.
	 *
	 * @param string   $object 'post' | 'term'.
	 * @param int      $id     Object id.
	 * @param string[] $keys   Meta keys to include.
	 * @return string
	 */
	public static function hash_meta( string $object, int $id, array $keys ): string {
		$snap = array();
		sort( $keys );
		foreach ( $keys as $k ) {
			$k          = (string) $k;
			$snap[ $k ] = 'term' === $object ? get_term_meta( $id, $k, true ) : get_post_meta( $id, $k, true );
		}
		return sha1( (string) wp_json_encode( $snap ) );
	}
}
