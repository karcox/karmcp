<?php
/**
 * Filesystem MCP tools: read/list/search (enabled) + write/edit/delete
 * (disabled-by-default). Every path is confined to ABSPATH by
 * KarMCP_Filesystem_Guard. Writes require edit_files + respect
 * DISALLOW_FILE_EDIT; delete requires confirm:true.
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
class KarMCP_Filesystem_Abilities {

	/** @var string[] */
	private $ability_names = array();

	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_read_file();
		$this->register_list_directory();
		$this->register_search_files();
		$this->register_write_file();
		$this->register_edit_file();
		$this->register_delete_file();
	}

	/** All filesystem tools require manage_options (reads can expose secrets). */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---- read-file -----------------------------------------------------

	private function register_read_file(): void {
		$this->ability_names[] = 'karmcp/read-file';
		karmcp_register_ability(
			'karmcp/read-file',
			array(
				'label'               => __( 'Read File', 'karmcp' ),
				'description'         => __( 'Read a file inside the WordPress installation (core, plugins, themes, uploads). Optional line offset/limit for large files. Path is confined to the WP install.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_read_file' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'   => array( 'type' => 'string', 'description' => __( 'Path relative to the WordPress root (e.g. wp-content/themes/x/style.css).', 'karmcp' ) ),
						'offset' => array( 'type' => 'integer', 'description' => __( '1-based start line.', 'karmcp' ) ),
						'limit'  => array( 'type' => 'integer', 'description' => __( 'Number of lines to return from offset.', 'karmcp' ) ),
					),
					'required'   => array( 'path' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_read_file( $input ) {
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_file( $abs ) ) {
			return new \WP_Error( 'not_found', __( 'File not found.', 'karmcp' ) );
		}
		if ( KarMCP_Filesystem_Guard::is_read_protected( $abs ) ) {
			return new \WP_Error( 'protected_read', __( 'This file holds site secrets (database credentials and auth salts) and cannot be read.', 'karmcp' ) );
		}
		$size = (int) filesize( $abs );
		if ( $size > KarMCP_Filesystem_Guard::MAX_READ_BYTES ) {
			return new \WP_Error( 'too_large', __( 'File exceeds the maximum readable size.', 'karmcp' ) );
		}
		$content = (string) file_get_contents( $abs );
		$rel     = KarMCP_Filesystem_Guard::to_relative( $abs );
		if ( ! KarMCP_Filesystem_Guard::is_utf8( $content ) ) {
			return array( 'path' => $rel, 'size' => $size, 'binary' => true, 'message' => __( 'Binary file, not returned as text.', 'karmcp' ) );
		}
		$offset = isset( $input['offset'] ) ? max( 1, (int) $input['offset'] ) : 0;
		$limit  = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 0;
		if ( $offset || $limit ) {
			$lines   = explode( "\n", $content );
			$slice   = array_slice( $lines, $offset ? $offset - 1 : 0, $limit ? $limit : null );
			$content = implode( "\n", $slice );
		}
		return array(
			'path'    => $rel,
			'size'    => $size,
			'lines'   => substr_count( $content, "\n" ) + 1,
			'content' => $content,
		);
	}

	// ---- list-directory ------------------------------------------------

	private function register_list_directory(): void {
		$this->ability_names[] = 'karmcp/list-directory';
		karmcp_register_ability(
			'karmcp/list-directory',
			array(
				'label'               => __( 'List Directory', 'karmcp' ),
				'description'         => __( 'List entries (files/dirs with size + mtime) of a directory inside the WordPress install. Optional recursive (bounded) listing.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_directory' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'      => array( 'type' => 'string', 'description' => __( 'Directory path relative to the WP root. Defaults to the root.', 'karmcp' ) ),
						'recursive' => array( 'type' => 'boolean', 'description' => __( 'Recurse up to 5 levels.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_list_directory( $input ) {
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '.' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( ! is_dir( $abs ) ) {
			return new \WP_Error( 'not_a_dir', __( 'Not a directory.', 'karmcp' ) );
		}
		$recursive = ! empty( $input['recursive'] );
		$entries   = array();
		if ( $recursive ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$it->setMaxDepth( 5 );
			foreach ( $it as $f ) {
				$entries[] = $this->entry( $f->getPathname() );
				if ( count( $entries ) >= 2000 ) {
					break;
				}
			}
		} else {
			foreach ( scandir( $abs ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$entries[] = $this->entry( $abs . DIRECTORY_SEPARATOR . $name );
			}
		}
		return array( 'path' => KarMCP_Filesystem_Guard::to_relative( $abs ), 'entries' => $entries );
	}

	private function entry( string $abs ): array {
		return array(
			'name'  => basename( $abs ),
			'path'  => KarMCP_Filesystem_Guard::to_relative( $abs ),
			'type'  => is_dir( $abs ) ? 'dir' : 'file',
			'size'  => is_file( $abs ) ? (int) filesize( $abs ) : 0,
			'mtime' => (int) filemtime( $abs ),
		);
	}

	// ---- search-files --------------------------------------------------

	private function register_search_files(): void {
		$this->ability_names[] = 'karmcp/search-files';
		karmcp_register_ability(
			'karmcp/search-files',
			array(
				'label'               => __( 'Search Files', 'karmcp' ),
				'description'         => __( 'Search file contents for a string inside the WordPress install, across a directory tree or within a single file. Returns file:line matches. This is how to check what a plugin really does rather than what its documentation says — the control a widget registers, where a hook is defined, which file paints a style. Filter by extensions; results are bounded. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_search_files' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'query'       => array( 'type' => 'string', 'description' => __( 'Substring to search for (case-sensitive).', 'karmcp' ) ),
						'path'        => array( 'type' => 'string', 'description' => __( 'Where to search, relative to the WP root: a directory to search its whole tree, or one file to search only that file. Defaults to the root. To read a file rather than search it, use read-file.', 'karmcp' ) ),
						'extensions'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Limit to these extensions, e.g. ["php","js"]. Ignored when path names a single file, which is already the choice.', 'karmcp' ) ),
						'max_results' => array( 'type' => 'integer', 'description' => __( 'Cap on matches (default 200, max 500).', 'karmcp' ) ),
					),
					'required'   => array( 'query' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_search_files( $input ) {
		$query = (string) ( $input['query'] ?? '' );
		if ( '' === $query ) {
			return new \WP_Error( 'missing_query', __( 'A search query is required.', 'karmcp' ) );
		}
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '.' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}

		/*
		 * A single file is a legitimate scope, and used to be an error.
		 *
		 * Narrowing a search to one file is the obvious move once you know where
		 * to look — checking a generated stylesheet for a rule, confirming which
		 * of two files declares a control — and the answer was "Not a directory."
		 * with no hint that a directory was wanted or that read-file exists. The
		 * cost was a failed call plus a wider search to filter by eye.
		 */
		$single_file = is_file( $abs );

		if ( ! $single_file && ! is_dir( $abs ) ) {
			return new \WP_Error(
				'path_not_found',
				__( 'No file or directory at that path. Pass a directory to search a tree, or a single file to search just that one.', 'karmcp' )
			);
		}

		$exts = array();
		if ( ! empty( $input['extensions'] ) && is_array( $input['extensions'] ) ) {
			$exts = array_map( 'strtolower', array_map( 'strval', $input['extensions'] ) );
		}
		$cap     = min( 500, max( 1, isset( $input['max_results'] ) ? (int) $input['max_results'] : 200 ) );
		$matches = array();

		if ( $single_file ) {
			$files = array( new \SplFileInfo( $abs ) );
		} else {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $abs, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
		}

		foreach ( $files as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			// `extensions` narrows a sweep; it has no business overruling a file
			// the caller named outright.
			if ( ! $single_file && $exts && ! in_array( strtolower( $f->getExtension() ), $exts, true ) ) {
				continue;
			}
			if ( $f->getSize() > KarMCP_Filesystem_Guard::MAX_READ_BYTES ) {
				continue;
			}
			// Never surface secrets (wp-config.php) via a content search.
			if ( KarMCP_Filesystem_Guard::is_read_protected( $f->getPathname() ) ) {
				continue;
			}
			$content = (string) file_get_contents( $f->getPathname() );
			if ( ! KarMCP_Filesystem_Guard::is_utf8( $content ) ) {
				continue;
			}
			$rel = KarMCP_Filesystem_Guard::to_relative( $f->getPathname() );
			$ln  = 0;
			foreach ( explode( "\n", $content ) as $line ) {
				$ln++;
				if ( false !== strpos( $line, $query ) ) {
					$matches[] = array( 'file' => $rel, 'line' => $ln, 'text' => substr( trim( $line ), 0, 300 ) );
					if ( count( $matches ) >= $cap ) {
						return array( 'matches' => $matches, 'truncated' => true );
					}
				}
			}
		}
		return array( 'matches' => $matches, 'truncated' => false );
	}

	// ---- write-file ----------------------------------------------------

	private function register_write_file(): void {
		$this->ability_names[] = 'karmcp/write-file';
		karmcp_register_ability(
			'karmcp/write-file',
			array(
				'label'               => __( 'Write File', 'karmcp' ),
				'description'         => __( 'Create or overwrite a file inside the WordPress install. Backs up an existing file first. Refuses wp-config.php/.htaccess. Disabled by default; requires file-editing to be allowed.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_write_file' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
					),
					'required'   => array( 'path', 'content' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_write_file( $input ) {
		$gate = KarMCP_Filesystem_Guard::writes_allowed();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( KarMCP_Filesystem_Guard::is_protected( $abs ) ) {
			return new \WP_Error( 'protected', __( 'This file is protected from writes.', 'karmcp' ) );
		}
		$content = (string) ( $input['content'] ?? '' );
		if ( strlen( $content ) > KarMCP_Filesystem_Guard::MAX_WRITE_BYTES ) {
			return new \WP_Error( 'too_large', __( 'Content exceeds the maximum writable size.', 'karmcp' ) );
		}
		$existed = is_file( $abs );
		$backup  = KarMCP_Filesystem_Guard::backup( $abs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		if ( ! wp_mkdir_p( dirname( $abs ) ) ) {
			return new \WP_Error( 'mkdir_failed', __( 'Could not create the parent directory.', 'karmcp' ) );
		}
		$bytes = file_put_contents( $abs, $content );
		if ( false === $bytes ) {
			return new \WP_Error( 'write_failed', __( 'Could not write the file (check permissions).', 'karmcp' ) );
		}
		self::invalidate_php_opcache( $abs );
		self::record_fs_change( 'write-file', $abs, $backup );
		return array(
			'path'   => KarMCP_Filesystem_Guard::to_relative( $abs ),
			'bytes'  => (int) $bytes,
			'action' => $existed ? 'overwritten' : 'created',
			'backup' => $backup ? KarMCP_Filesystem_Guard::to_relative( $backup ) : null,
		);
	}

	// ---- edit-file -----------------------------------------------------

	private function register_edit_file(): void {
		$this->ability_names[] = 'karmcp/edit-file';
		karmcp_register_ability(
			'karmcp/edit-file',
			array(
				'label'               => __( 'Edit File', 'karmcp' ),
				'description'         => __( 'Replace an exact string in a file (must match once unless replace_all). Backs up first. Refuses wp-config.php/.htaccess. Disabled by default.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_edit_file' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'        => array( 'type' => 'string' ),
						'old_string'  => array( 'type' => 'string' ),
						'new_string'  => array( 'type' => 'string' ),
						'replace_all' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'path', 'old_string', 'new_string' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_edit_file( $input ) {
		$gate = KarMCP_Filesystem_Guard::writes_allowed();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( KarMCP_Filesystem_Guard::is_protected( $abs ) ) {
			return new \WP_Error( 'protected', __( 'This file is protected from writes.', 'karmcp' ) );
		}
		if ( ! is_file( $abs ) ) {
			return new \WP_Error( 'not_found', __( 'File not found.', 'karmcp' ) );
		}
		$content = (string) file_get_contents( $abs );
		if ( ! KarMCP_Filesystem_Guard::is_utf8( $content ) ) {
			return new \WP_Error( 'binary', __( 'Cannot edit a binary file.', 'karmcp' ) );
		}
		$old = (string) ( $input['old_string'] ?? '' );
		$new = (string) ( $input['new_string'] ?? '' );
		if ( '' === $old ) {
			return new \WP_Error( 'empty_old', __( 'old_string must not be empty.', 'karmcp' ) );
		}
		$count = substr_count( $content, $old );
		if ( 0 === $count ) {
			return new \WP_Error( 'no_match', __( 'old_string was not found in the file.', 'karmcp' ) );
		}
		$all = ! empty( $input['replace_all'] );
		if ( $count > 1 && ! $all ) {
			return new \WP_Error( 'multiple_matches', __( 'old_string matched multiple times; pass replace_all or make it unique.', 'karmcp' ) );
		}
		$backup = KarMCP_Filesystem_Guard::backup( $abs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		if ( $all ) {
			$updated = str_replace( $old, $new, $content );
		} else {
			$pos     = strpos( $content, $old );
			$updated = substr( $content, 0, $pos ) . $new . substr( $content, $pos + strlen( $old ) );
		}
		if ( false === file_put_contents( $abs, $updated ) ) {
			return new \WP_Error( 'write_failed', __( 'Could not write the file (check permissions).', 'karmcp' ) );
		}
		self::invalidate_php_opcache( $abs );
		self::record_fs_change( 'edit-file', $abs, $backup );
		return array(
			'path'         => KarMCP_Filesystem_Guard::to_relative( $abs ),
			'replacements' => $all ? $count : 1,
			'backup'       => $backup ? KarMCP_Filesystem_Guard::to_relative( $backup ) : null,
		);
	}

	// ---- delete-file ---------------------------------------------------

	private function register_delete_file(): void {
		$this->ability_names[] = 'karmcp/delete-file';
		karmcp_register_ability(
			'karmcp/delete-file',
			array(
				'label'               => __( 'Delete File', 'karmcp' ),
				'description'         => __( 'Delete a file inside the WordPress install. Backs up first. Requires confirm:true. Refuses wp-config.php/.htaccess. Disabled by default.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete_file' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'path'    => array( 'type' => 'string' ),
						'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true to delete.', 'karmcp' ) ),
					),
					'required'   => array( 'path' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_delete_file( $input ) {
		if ( empty( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new \WP_Error( 'confirm_required', __( 'Deleting a file requires confirm:true.', 'karmcp' ) );
		}
		$gate = KarMCP_Filesystem_Guard::writes_allowed();
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$abs = KarMCP_Filesystem_Guard::resolve_path( (string) ( $input['path'] ?? '' ) );
		if ( is_wp_error( $abs ) ) {
			return $abs;
		}
		if ( KarMCP_Filesystem_Guard::is_protected( $abs ) ) {
			return new \WP_Error( 'protected', __( 'This file is protected from deletion.', 'karmcp' ) );
		}
		if ( ! is_file( $abs ) ) {
			return new \WP_Error( 'not_found', __( 'File not found.', 'karmcp' ) );
		}
		$backup = KarMCP_Filesystem_Guard::backup( $abs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		if ( ! unlink( $abs ) ) {
			return new \WP_Error( 'delete_failed', __( 'Could not delete the file (check permissions).', 'karmcp' ) );
		}
		self::invalidate_php_opcache( $abs );
		self::record_fs_change( 'delete-file', $abs, $backup );
		return array(
			'path'    => KarMCP_Filesystem_Guard::to_relative( $abs ),
			'deleted' => true,
			'backup'  => $backup ? KarMCP_Filesystem_Guard::to_relative( $backup ) : null,
		);
	}

	/**
	 * Record a filesystem mutation to the unified change ledger.
	 *
	 * @param string        $action write-file | edit-file | delete-file.
	 * @param string        $abs    Absolute target path.
	 * @param string|mixed  $backup Backup path ('' when the write created a new file).
	 */
	private static function record_fs_change( string $action, string $abs, $backup ): void {
		if ( ! class_exists( 'KarMCP_Change_Log' ) ) {
			return;
		}
		$rel     = KarMCP_Filesystem_Guard::to_relative( $abs );
		$rollback = ( '' === $backup )
			? array( 'type' => 'file-create', 'target_path' => $abs )
			: array( 'type' => 'file-backup', 'target_path' => $abs, 'backup_path' => (string) $backup );
		$verbs   = array( 'write-file' => 'Wrote', 'edit-file' => 'Edited', 'delete-file' => 'Deleted' );
		$entry   = array(
			'domain'   => 'filesystem',
			'action'   => $action,
			'target'   => $rel,
			'summary'  => ( $verbs[ $action ] ?? 'Changed' ) . ' ' . $rel,
			'rollback' => $rollback,
		);
		// record_file stamps an after-hash of the written file (empty for a delete)
		// so a later external change is caught by the conflict guard on rollback.
		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			KarMCP_Change_Recorder::record_file( $entry, $abs );
		} else {
			KarMCP_Change_Log::record( $entry );
		}
	}

	/**
	 * Invalidate the OPcache entry for a freshly written or removed PHP file so the
	 * change takes effect on the next request instead of executing stale bytecode.
	 * Mirrors the guard already used in class-php-snippet-store / class-widget-store /
	 * themer/php/class-themer-php-store.
	 */
	private static function invalidate_php_opcache( string $abs ): void {
		if ( function_exists( 'opcache_invalidate' ) && '.php' === strtolower( substr( $abs, -4 ) ) ) {
			opcache_invalidate( $abs, true );
		}
	}
}
