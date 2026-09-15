<?php
/**
 * WordPress Plugin lifecycle MCP abilities.
 *
 * Seven tools to discover, install (wordpress.org only), activate, deactivate,
 * update, and delete plugins. Built on WP core's plugin + upgrader APIs and
 * guarded by KarMCP_Package_Guard (protected list, active checks, direct
 * filesystem). Reads ship enabled; the five mutation tools ship disabled-by-
 * default (admin opts in on the Tools tab).
 *
 * @package KarMCP
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the plugin lifecycle abilities.
 *
 * @since 3.0.0
 */
class KarMCP_Plugin_Abilities {

	/** @since 3.0.0 @var string[] */
	private $ability_names = array();

	/** @since 3.0.0 @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** @since 3.0.0 */
	public function register(): void {
		$this->register_list_plugins();
		$this->register_search_plugins();
		$this->register_install_plugin();
		$this->register_install_uploaded_zip();
		$this->register_activate_plugin();
		$this->register_deactivate_plugin();
		$this->register_update_plugin();
		$this->register_delete_plugin();
	}

	// -------------------------------------------------------------------
	// Permission callbacks (per-op capability)
	// -------------------------------------------------------------------

	public function can_list(): bool { return current_user_can( 'activate_plugins' ); }
	public function can_install(): bool { return current_user_can( 'install_plugins' ); }
	public function can_activate(): bool { return current_user_can( 'activate_plugins' ); }
	public function can_update(): bool { return current_user_can( 'update_plugins' ); }
	public function can_delete(): bool { return current_user_can( 'delete_plugins' ); }

	// -------------------------------------------------------------------
	// list-plugins
	// -------------------------------------------------------------------

	private function register_list_plugins(): void {
		$this->ability_names[] = 'karmcp/list-plugins';
		karmcp_register_ability(
			'karmcp/list-plugins',
			array(
				'label'               => __( 'List Plugins', 'karmcp' ),
				'description'         => __( 'Lists installed WordPress plugins with status (active/inactive/network), version, whether an update is available, and whether the plugin is protected (KarMCP / Elementor can never be disabled via MCP). Optional "status" filter.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_plugins' ),
				'permission_callback' => array( $this, 'can_list' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'all', 'active', 'inactive' ), 'description' => __( 'Filter by status. Default: all.', 'karmcp' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_plugins( $input ): array {
		KarMCP_Package_Guard::load_upgrader_deps();
		$filter  = in_array( $input['status'] ?? 'all', array( 'all', 'active', 'inactive' ), true ) ? ( $input['status'] ?? 'all' ) : 'all';
		$all     = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$updates = get_site_transient( 'update_plugins' );
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		$rows = array();
		foreach ( $all as $file => $data ) {
			$active = KarMCP_Package_Guard::is_active_plugin( (string) $file );
			if ( 'active' === $filter && ! $active ) { continue; }
			if ( 'inactive' === $filter && $active ) { continue; }
			$rows[] = array(
				'file'             => (string) $file,
				'slug'             => dirname( (string) $file ),
				'name'             => (string) ( $data['Name'] ?? $file ),
				'version'          => (string) ( $data['Version'] ?? '' ),
				'author'           => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
				'active'           => $active,
				'is_protected'     => KarMCP_Package_Guard::is_protected_plugin( (string) $file ),
				'update_available' => isset( $resp[ $file ] ),
				'new_version'      => isset( $resp[ $file ]->new_version ) ? (string) $resp[ $file ]->new_version : '',
			);
		}
		return array( 'plugins' => $rows );
	}

	// -------------------------------------------------------------------
	// search-plugins
	// -------------------------------------------------------------------

	private function register_search_plugins(): void {
		$this->ability_names[] = 'karmcp/search-plugins';
		karmcp_register_ability(
			'karmcp/search-plugins',
			array(
				'label'               => __( 'Search Plugins', 'karmcp' ),
				'description'         => __( 'Searches the wordpress.org plugin directory by keyword so you can find a slug to install. Returns slug, name, version, rating, and requirements. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_search_plugins' ),
				'permission_callback' => array( $this, 'can_install' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => __( 'Keyword(s) to search the .org directory.', 'karmcp' ) ),
						'per_page' => array( 'type' => 'integer', 'description' => __( '1-50. Default: 10.', 'karmcp' ) ),
					),
					'required'   => array( 'search' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_search_plugins( $input ) {
		$search = sanitize_text_field( $input['search'] ?? '' );
		if ( '' === $search ) {
			return new \WP_Error( 'missing_params', __( 'A "search" keyword is required.', 'karmcp' ) );
		}
		KarMCP_Package_Guard::load_upgrader_deps();
		$per_page = max( 1, min( 50, absint( $input['per_page'] ?? 10 ) ) );
		$api      = plugins_api( 'query_plugins', array( 'search' => $search, 'per_page' => $per_page, 'fields' => array( 'short_description' => true, 'icons' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$rows = array();
		foreach ( (array) ( $api->plugins ?? array() ) as $p ) {
			$p     = (object) $p;
			$rows[] = array(
				'slug'              => (string) ( $p->slug ?? '' ),
				'name'              => wp_strip_all_tags( (string) ( $p->name ?? '' ) ),
				'version'           => (string) ( $p->version ?? '' ),
				'rating'            => (int) ( $p->rating ?? 0 ),
				'num_ratings'       => (int) ( $p->num_ratings ?? 0 ),
				'requires'          => (string) ( $p->requires ?? '' ),
				'tested'            => (string) ( $p->tested ?? '' ),
				'short_description' => wp_strip_all_tags( (string) ( $p->short_description ?? '' ) ),
			);
		}
		return array( 'results' => $rows );
	}

	// -------------------------------------------------------------------
	// install-plugin
	// -------------------------------------------------------------------

	private function register_install_plugin(): void {
		$this->ability_names[] = 'karmcp/install-plugin';
		karmcp_register_ability(
			'karmcp/install-plugin',
			array(
				'label'               => __( 'Install Plugin', 'karmcp' ),
				'description'         => __( 'Installs a plugin from the wordpress.org directory by slug (e.g. "contact-form-7"). Optionally activates it. Source is always wordpress.org, arbitrary URLs are not accepted.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_install_plugin' ),
				'permission_callback' => array( $this, 'can_install' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array( 'type' => 'string', 'description' => __( 'wordpress.org plugin slug.', 'karmcp' ) ),
						'activate' => array( 'type' => 'boolean', 'description' => __( 'Activate after install. Default: false.', 'karmcp' ) ),
					),
					'required'   => array( 'slug' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'installed' => array( 'type' => 'boolean' ), 'activated' => array( 'type' => 'boolean' ),
					'file' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ),
					'messages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_install_plugin( $input ) {
		$slug = sanitize_key( $input['slug'] ?? '' );
		if ( '' === $slug ) {
			return new \WP_Error( 'missing_params', __( 'A plugin "slug" is required.', 'karmcp' ) );
		}
		$activate = ! empty( $input['activate'] );
		if ( $activate && ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'cannot_activate', __( 'You cannot activate plugins.', 'karmcp' ) );
		}
		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$skin     = KarMCP_Package_Guard::make_skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result || null === $result ) {
			return new \WP_Error( 'install_failed', __( 'Plugin installation failed.', 'karmcp' ) );
		}
		$file      = (string) $upgrader->plugin_info();
		$activated = false;
		if ( $activate && '' !== $file ) {
			$act = activate_plugin( $file );
			$activated = ! is_wp_error( $act );
		}
		return array(
			'installed' => true,
			'activated' => $activated,
			'file'      => $file,
			'slug'      => $slug,
			'messages'  => KarMCP_Package_Guard::skin_messages( $skin ),
		);
	}

	// -------------------------------------------------------------------
	// install-uploaded-zip
	// -------------------------------------------------------------------

	/**
	 * Registration gate: either install capability gets the caller in; the
	 * execute callback then demands the one the requested type needs, plus the
	 * ones an overwrite or an activation add.
	 *
	 * @since 1.41.0
	 * @return bool
	 */
	public function can_install_uploaded_zip(): bool {
		return current_user_can( 'install_plugins' ) || current_user_can( 'install_themes' );
	}

	private function register_install_uploaded_zip(): void {
		$this->ability_names[] = 'karmcp/install-uploaded-zip';
		karmcp_register_ability(
			'karmcp/install-uploaded-zip',
			array(
				'label'               => __( 'Install Uploaded ZIP', 'karmcp' ),
				'description'         => __( 'Installs a plugin or theme from a ZIP already in the Media Library — for a package that is not on wordpress.org, or an update to one. Flow: upload the ZIP with upload-media, compute the SHA-256 of the exact bytes you uploaded, then call this with the attachment id, that sha256, and confirm:true. The archive is inspected before anything is extracted and refused if an entry escapes the package folder, is a symbolic link, sits outside a single top-level folder, or expands past the size limits; if the package has no Plugin Name / Theme Name header; or if it needs a newer PHP or WordPress than this site runs. An existing plugin or theme is replaced only with overwrite:true, and only by a package declaring the same name — a guard against replacing the wrong package, not against a package lying about its name; the boundary for that is who may run this tool at all. Protected plugins — KarMCP, Elementor and Elementor Pro — cannot be replaced this way, the same rule update-plugin follows. The uploaded ZIP is deleted after a successful install unless keep_upload:true, so plugin code is not left downloadable from the uploads folder.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_install_uploaded_zip' ),
				'permission_callback' => array( $this, 'can_install_uploaded_zip' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'type'          => array(
							'type'        => 'string',
							'enum'        => array( 'plugin', 'theme' ),
							'description' => __( 'What the ZIP contains.', 'karmcp' ),
						),
						'attachment_id' => array(
							'type'        => 'integer',
							'description' => __( 'Media Library id of the uploaded ZIP, as upload-media returned it.', 'karmcp' ),
						),
						'sha256'        => array(
							'type'        => 'string',
							'description' => __( 'SHA-256 of the ZIP you uploaded, 64 hex characters, computed on your side before uploading. The install is refused if the file on the server does not match it.', 'karmcp' ),
						),
						'overwrite'     => array(
							'type'        => 'boolean',
							'description' => __( 'Replace an installed plugin or theme with the same folder name. Default false. Only allowed when the package declares the same name as the one installed.', 'karmcp' ),
						),
						'activate'      => array(
							'type'        => 'boolean',
							'description' => __( 'Activate the plugin after installing. Plugins only. Default false.', 'karmcp' ),
						),
						'keep_upload'   => array(
							'type'        => 'boolean',
							'description' => __( 'Keep the ZIP in the Media Library after a successful install. Default false, which deletes it.', 'karmcp' ),
						),
						'confirm'       => array(
							'type'        => 'boolean',
							'description' => __( 'Must be true. This installs code on the site.', 'karmcp' ),
						),
					),
					'required'   => array( 'type', 'attachment_id', 'sha256', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'installed'        => array( 'type' => 'boolean' ),
						'type'             => array( 'type' => 'string' ),
						'slug'             => array( 'type' => 'string' ),
						'name'             => array( 'type' => 'string' ),
						'version'          => array( 'type' => 'string' ),
						'previous_version' => array(
							'type'        => array( 'string', 'null' ),
							'description' => __( 'The version that was replaced, or null for a fresh install.', 'karmcp' ),
						),
						'overwritten'      => array( 'type' => 'boolean' ),
						'activated'        => array( 'type' => 'boolean' ),
						'file'             => array(
							'type'        => 'string',
							'description' => __( 'Plugin basename (folder/file.php), or the theme folder.', 'karmcp' ),
						),
						'sha256'           => array( 'type' => 'string' ),
						'upload_deleted'   => array( 'type' => 'boolean' ),
						'messages'         => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// It can replace an installed plugin or theme.
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Installs a plugin or theme from an uploaded ZIP, after proving the file is
	 * the one the caller meant and the archive is safe to extract.
	 *
	 * @since 1.41.0
	 *
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute_install_uploaded_zip( $input ) {
		$type          = sanitize_key( (string) ( $input['type'] ?? '' ) );
		$attachment_id = absint( $input['attachment_id'] ?? 0 );
		$sha256        = strtolower( trim( (string) ( $input['sha256'] ?? '' ) ) );
		$overwrite     = ! empty( $input['overwrite'] );
		$activate      = ! empty( $input['activate'] );
		$keep_upload   = ! empty( $input['keep_upload'] );

		if ( empty( $input['confirm'] ) ) {
			return new \WP_Error( 'confirm_required', __( 'This installs code on the site. Pass confirm:true.', 'karmcp' ) );
		}

		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return new \WP_Error( 'invalid_type', __( 'type must be "plugin" or "theme".', 'karmcp' ) );
		}

		if ( ! $attachment_id ) {
			return new \WP_Error( 'missing_params', __( 'attachment_id is required: the Media Library id upload-media returned.', 'karmcp' ) );
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new \WP_Error( 'invalid_sha256', __( 'sha256 must be the 64-character hexadecimal SHA-256 of the ZIP you uploaded.', 'karmcp' ) );
		}

		if ( 'theme' === $type && $activate ) {
			return new \WP_Error( 'activate_not_supported', __( 'activate applies to plugins only. Switch themes with switch-theme.', 'karmcp' ) );
		}

		// Each consequence needs its own capability: installing, replacing what
		// is there, and switching the plugin on are three separate permissions in
		// WordPress, and a caller holding one must not get the others for free.
		$caps = ( 'plugin' === $type ) ? array( 'install_plugins' ) : array( 'install_themes' );
		if ( $overwrite ) {
			$caps[] = ( 'plugin' === $type ) ? 'update_plugins' : 'update_themes';
		}
		if ( $activate ) {
			$caps[] = 'activate_plugins';
		}
		foreach ( $caps as $cap ) {
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error(
					'missing_capability',
					sprintf(
						/* translators: %s: capability name. */
						__( 'The current user lacks the "%s" capability this install needs.', 'karmcp' ),
						$cap
					),
					array( 'required_capability' => $cap )
				);
			}
		}

		// DISALLOW_FILE_MODS and its filter are the site owner's own switch for
		// "nothing installs code here". It outranks every tool.
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'karmcp_install_uploaded_zip' ) ) {
			return new \WP_Error( 'file_mods_disallowed', __( 'This site does not allow installing or modifying plugins and themes (DISALLOW_FILE_MODS).', 'karmcp' ) );
		}

		$zip = self::verified_upload( $attachment_id, $sha256 );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		// Everything from here reads the private copy verified_upload() made, and
		// the copy goes whatever happens next: an early refusal, a failed install
		// or a thrown error must not leave a second copy of plugin code in temp.
		try {
			return $this->install_verified_copy( $zip, $type, $attachment_id, $overwrite, $activate, $keep_upload );
		} finally {
			wp_delete_file( $zip['path'] );
		}
	}

	/**
	 * The install proper, run against a copy whose hash has already been proven.
	 *
	 * @since 1.41.0
	 *
	 * @param array  $zip           {path, sha256} from verified_upload().
	 * @param string $type          'plugin' or 'theme'.
	 * @param int    $attachment_id The upload, deleted after success unless kept.
	 * @param bool   $overwrite     Whether an installed package may be replaced.
	 * @param bool   $activate      Whether to activate a plugin afterwards.
	 * @param bool   $keep_upload   Whether to keep the upload after success.
	 * @return array|\WP_Error
	 */
	private function install_verified_copy( array $zip, string $type, int $attachment_id, bool $overwrite, bool $activate, bool $keep_upload ) {
		$package = KarMCP_Zip_Package_Inspector::inspect( $zip['path'], $type );
		if ( is_wp_error( $package ) ) {
			return $package;
		}

		if ( '' !== $package['requires_php'] && function_exists( 'is_php_version_compatible' ) && ! is_php_version_compatible( $package['requires_php'] ) ) {
			return new \WP_Error(
				'incompatible_php',
				sprintf(
					/* translators: 1: package name, 2: required PHP version, 3: PHP version running. */
					__( '%1$s requires PHP %2$s; this site runs %3$s.', 'karmcp' ),
					$package['name'],
					$package['requires_php'],
					PHP_VERSION
				)
			);
		}

		if ( '' !== $package['requires_wp'] && function_exists( 'is_wp_version_compatible' ) && ! is_wp_version_compatible( $package['requires_wp'] ) ) {
			return new \WP_Error(
				'incompatible_wp',
				sprintf(
					/* translators: 1: package name, 2: required WordPress version, 3: WordPress version running. */
					__( '%1$s requires WordPress %2$s; this site runs %3$s.', 'karmcp' ),
					$package['name'],
					$package['requires_wp'],
					get_bloginfo( 'version' )
				)
			);
		}

		KarMCP_Package_Guard::load_upgrader_deps();

		$destination = ( 'plugin' === $type )
			? trailingslashit( WP_PLUGIN_DIR ) . $package['slug']
			: trailingslashit( get_theme_root() ) . $package['slug'];
		$existing    = self::installed_package( $type, $package['slug'] );

		$refusal = self::overwrite_refusal( $type, is_dir( $destination ), $existing, $package, $overwrite );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$skin     = KarMCP_Package_Guard::make_skin();
		$upgrader = ( 'plugin' === $type ) ? new \Plugin_Upgrader( $skin ) : new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $zip['path'], array( 'overwrite_package' => $overwrite ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result ) {
			return new \WP_Error(
				'install_failed',
				__( 'WordPress did not complete the install. The messages carry what it reported.', 'karmcp' ),
				array( 'messages' => KarMCP_Package_Guard::skin_messages( $skin ) )
			);
		}

		$file      = ( 'plugin' === $type ) ? (string) $upgrader->plugin_info() : $package['slug'];
		$activated = false;
		$messages  = KarMCP_Package_Guard::skin_messages( $skin );

		if ( $activate ) {
			if ( '' === $file ) {
				// Said out loud: a caller that asked for activation and reads only
				// `installed` would otherwise take a quiet false for a decision.
				$messages[] = __( 'The plugin was installed, but WordPress did not report its main file, so it was not activated. Activate it with activate-plugin.', 'karmcp' );
			} else {
				$act       = activate_plugin( $file );
				$activated = ! is_wp_error( $act );
				if ( is_wp_error( $act ) ) {
					$messages[] = $act->get_error_message();
				}
			}
		}

		$deleted = false;
		if ( ! $keep_upload ) {
			$deleted = (bool) wp_delete_attachment( $attachment_id, true );
		}

		return array(
			'installed'        => true,
			'type'             => $type,
			'slug'             => $package['slug'],
			'name'             => $package['name'],
			'version'          => $package['version'],
			'previous_version' => null !== $existing ? $existing['version'] : null,
			'overwritten'      => null !== $existing,
			'activated'        => $activated,
			'file'             => $file,
			'sha256'           => $zip['sha256'],
			'upload_deleted'   => $deleted,
			'messages'         => $messages,
		);
	}

	/**
	 * Resolves an attachment to a ZIP on disk that is provably the one the
	 * caller uploaded.
	 *
	 * @since 1.41.0
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $sha256        Expected SHA-256, lowercase hex.
	 * @return array{path:string}|\WP_Error
	 */
	private static function verified_upload( int $attachment_id, string $sha256 ) {
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				sprintf(
					/* translators: %d: attachment id. */
					__( 'No Media Library item with id %d exists.', 'karmcp' ),
					$attachment_id
				)
			);
		}

		$file = (string) get_attached_file( $attachment_id );

		// A symlink in uploads could point the install at any file the web user
		// can read, and realpath() below would follow it without a word.
		if ( '' === $file || is_link( $file ) ) {
			return new \WP_Error( 'attachment_unusable', __( 'The uploaded file is missing or is a symbolic link.', 'karmcp' ) );
		}

		$real    = realpath( $file );
		$uploads = realpath( (string) wp_get_upload_dir()['basedir'] );

		// Compared normalised: realpath() answers with the platform's separator,
		// so on a Windows host a raw prefix check against "uploads/" failed for
		// every file and the tool refused all of them — including the ones
		// sitting exactly where they should.
		$inside = false !== $real && false !== $uploads
			&& 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $uploads ) ) );

		if ( ! $inside || ! is_file( (string) $real ) ) {
			return new \WP_Error( 'attachment_outside_uploads', __( 'The attachment does not resolve to a file inside the uploads folder.', 'karmcp' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		$head = (string) file_get_contents( $real, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- four bytes of a local file already confined to uploads above.

		// Both, because each is cheap to fake alone: the stored type is whatever
		// the upload was labelled, and the magic bytes say nothing about the rest.
		if ( ! in_array( $mime, array( 'application/zip', 'application/x-zip-compressed', 'application/x-zip' ), true ) || "PK\x03\x04" !== $head ) {
			return new \WP_Error( 'not_a_zip', __( 'The attachment is not a ZIP archive.', 'karmcp' ) );
		}

		// Work from a private copy, and hash the copy. The inspector and the
		// upgrader each reopen the file by path after this, and the attachment's
		// path is one anyone who can write to uploads can replace: checking the
		// hash of that path and then installing from it would verify one file and
		// install whatever sat there a moment later. A copy made by this request,
		// under a name it chose, is the one thing both later reads can be sure of.
		$copy = tempnam( get_temp_dir(), 'karmcp-zip-' );
		if ( false === $copy || ! copy( $real, $copy ) ) {
			if ( false !== $copy ) {
				wp_delete_file( $copy );
			}
			return new \WP_Error( 'copy_failed', __( 'Could not make a working copy of the upload to install from.', 'karmcp' ) );
		}

		$actual = (string) hash_file( 'sha256', $copy );

		// The hash binds the file about to be installed to the bytes the caller
		// meant to send. Without it, an attachment id — a small integer anyone
		// with upload rights can mint — is all that stands between the tool and
		// whichever ZIP happens to carry it.
		if ( ! hash_equals( $sha256, $actual ) ) {
			wp_delete_file( $copy );
			return new \WP_Error(
				'sha256_mismatch',
				sprintf(
					/* translators: 1: expected hash, 2: actual hash. */
					__( 'The file on the server does not match the SHA-256 you gave (expected %1$s, found %2$s). Nothing was installed.', 'karmcp' ),
					$sha256,
					$actual
				)
			);
		}

		return array(
			'path'   => $copy,
			'sha256' => $actual,
		);
	}

	/**
	 * Why an install may not proceed into its destination, or null when it may.
	 *
	 * Pure, so every branch is testable without a filesystem.
	 *
	 * Protected plugins are refused before identity is even considered, and the
	 * order matters: the case worth refusing is precisely the one where the
	 * names match. KarMCP, Elementor and Elementor Pro are never replaced over
	 * MCP by update-plugin either, and an upload is a weaker source than
	 * wordpress.org, not a stronger one. For KarMCP there is a second reason
	 * the update tool already gives: replacing the plugin's own code while this
	 * request is still running on it is not the same as updating a dependency —
	 * the MCP adapter that has to serialise this response lives in its folder.
	 *
	 * @since 1.41.0
	 *
	 * @param string     $type       'plugin' or 'theme'.
	 * @param bool       $dir_exists Whether the destination folder exists.
	 * @param array|null $existing   installed_package() for that folder.
	 * @param array      $package    What the inspector read from the ZIP.
	 * @param bool       $overwrite  Whether the caller asked to replace.
	 * @return \WP_Error|null
	 */
	public static function overwrite_refusal( string $type, bool $dir_exists, ?array $existing, array $package, bool $overwrite ): ?\WP_Error {
		if ( ! $dir_exists ) {
			return null;
		}

		if ( ! $overwrite ) {
			return new \WP_Error(
				'destination_exists',
				sprintf(
					/* translators: 1: package folder, 2: installed version, 3: incoming version. */
					__( '"%1$s" is already installed (version %2$s). The ZIP holds version %3$s. Pass overwrite:true to replace it.', 'karmcp' ),
					$package['slug'],
					( null !== $existing && '' !== $existing['version'] ) ? $existing['version'] : '?',
					'' !== $package['version'] ? $package['version'] : '?'
				)
			);
		}

		// A folder whose owner cannot be read has no identity to check an
		// overwrite against, so it is not overwritten: that is exactly the shape
		// of a leftover directory a hostile archive would target.
		if ( null === $existing ) {
			return new \WP_Error(
				'destination_unidentified',
				sprintf(
					/* translators: %s: package folder. */
					__( 'The folder "%s" exists but holds no plugin or theme WordPress recognises, so there is nothing to confirm the overwrite against. Remove it first.', 'karmcp' ),
					$package['slug']
				)
			);
		}

		if ( 'plugin' === $type && '' !== ( $existing['file'] ?? '' ) && KarMCP_Package_Guard::is_protected_plugin( $existing['file'] ) ) {
			return new \WP_Error(
				'protected_plugin',
				sprintf(
					/* translators: %s: plugin file. */
					__( '"%s" is protected and cannot be replaced over MCP, the same rule update-plugin follows. Update it from the WordPress admin.', 'karmcp' ),
					$existing['file']
				)
			);
		}

		if ( ! self::same_package_name( $existing['name'], $package['name'] ) ) {
			return new \WP_Error(
				'identity_mismatch',
				sprintf(
					/* translators: 1: package folder, 2: installed package name, 3: name declared by the ZIP. */
					__( 'The folder "%1$s" holds "%2$s", but the ZIP declares "%3$s". An upload only replaces a package with the same name, so the wrong plugin or theme is not replaced by mistake.', 'karmcp' ),
					$package['slug'],
					$existing['name'],
					$package['name']
				)
			);
		}

		return null;
	}

	/**
	 * The plugin or theme installed in a folder, or null.
	 *
	 * @since 1.41.0
	 *
	 * @param string $type 'plugin' or 'theme'.
	 * @param string $slug Folder name.
	 * @return array{name:string,version:string,file:string}|null `file` is the plugin basename, '' for a theme.
	 */
	private static function installed_package( string $type, string $slug ): ?array {
		if ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				return null;
			}
			foreach ( get_plugins( '/' . $slug ) as $relative => $data ) {
				if ( ! empty( $data['Name'] ) ) {
					return array(
						'name'    => (string) $data['Name'],
						'version' => (string) ( $data['Version'] ?? '' ),
						'file'    => $slug . '/' . $relative,
					);
				}
			}
			return null;
		}

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return null;
		}

		return array(
			'name'    => (string) $theme->get( 'Name' ),
			'version' => (string) $theme->get( 'Version' ),
			'file'    => '',
		);
	}

	/**
	 * Whether an incoming package is the same package as the installed one.
	 *
	 * Compared on the declared name, not the folder: the folder is what the
	 * archive chooses, so it proves nothing. Case and surrounding space are not
	 * identity.
	 *
	 * This is a guard against mistakes, and it is described as one on purpose.
	 * A hostile archive can declare any name it likes, "Elementor" included, and
	 * pass. What stops that caller is everything before it: the tool ships
	 * disabled, needs install and update capabilities and confirm:true. Calling
	 * this a hijack defence would be the kind of claim that gets relied on.
	 *
	 * @since 1.41.0
	 *
	 * @param string $installed Name of the installed plugin or theme.
	 * @param string $incoming  Name declared by the ZIP.
	 * @return bool
	 */
	public static function same_package_name( string $installed, string $incoming ): bool {
		$installed = strtolower( trim( $installed ) );

		return '' !== $installed && strtolower( trim( $incoming ) ) === $installed;
	}

	// -------------------------------------------------------------------
	// activate-plugin
	// -------------------------------------------------------------------

	private function register_activate_plugin(): void {
		$this->ability_names[] = 'karmcp/activate-plugin';
		karmcp_register_ability(
			'karmcp/activate-plugin',
			array(
				'label'               => __( 'Activate Plugin', 'karmcp' ),
				'description'         => __( 'Activates an installed plugin by its file path (e.g. "akismet/akismet.php") or folder slug.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_activate_plugin' ),
				'permission_callback' => array( $this, 'can_activate' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => __( 'Plugin file (folder/file.php) or folder slug.', 'karmcp' ) ) ),
					'required'   => array( 'plugin' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'plugin' => array( 'type' => 'string' ), 'active' => array( 'type' => 'boolean' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_activate_plugin( $input ) {
		$file = $this->resolve_plugin_file( $input['plugin'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'success' => true, 'plugin' => $file, 'active' => KarMCP_Package_Guard::is_active_plugin( $file ) );
	}

	// -------------------------------------------------------------------
	// deactivate-plugin
	// -------------------------------------------------------------------

	private function register_deactivate_plugin(): void {
		$this->ability_names[] = 'karmcp/deactivate-plugin';
		karmcp_register_ability(
			'karmcp/deactivate-plugin',
			array(
				'label'               => __( 'Deactivate Plugin', 'karmcp' ),
				'description'         => __( 'Deactivates an active plugin. Refuses to deactivate KarMCP itself or Elementor (its hard dependency).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_deactivate_plugin' ),
				'permission_callback' => array( $this, 'can_activate' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => __( 'Plugin file or folder slug.', 'karmcp' ) ) ),
					'required'   => array( 'plugin' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'success' => array( 'type' => 'boolean' ), 'plugin' => array( 'type' => 'string' ), 'active' => array( 'type' => 'boolean' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_deactivate_plugin( $input ) {
		$file = $this->resolve_plugin_file( $input['plugin'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( KarMCP_Package_Guard::is_protected_plugin( $file ) ) {
			return new \WP_Error( 'protected_plugin', sprintf( /* translators: %s: plugin file */ __( '"%s" is protected and cannot be deactivated via MCP (it would break KarMCP or Elementor).', 'karmcp' ), $file ) );
		}
		deactivate_plugins( array( $file ) );
		return array( 'success' => true, 'plugin' => $file, 'active' => KarMCP_Package_Guard::is_active_plugin( $file ) );
	}

	// -------------------------------------------------------------------
	// update-plugin
	// -------------------------------------------------------------------

	private function register_update_plugin(): void {
		$this->ability_names[] = 'karmcp/update-plugin';
		karmcp_register_ability(
			'karmcp/update-plugin',
			array(
				'label'               => __( 'Update Plugin', 'karmcp' ),
				'description'         => __( 'Updates an installed plugin to the latest wordpress.org version. Reports up_to_date when no update is pending.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_plugin' ),
				'permission_callback' => array( $this, 'can_update' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => __( 'Plugin file or folder slug.', 'karmcp' ) ) ),
					'required'   => array( 'plugin' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'success' => array( 'type' => 'boolean' ), 'up_to_date' => array( 'type' => 'boolean' ),
					'plugin' => array( 'type' => 'string' ), 'old_version' => array( 'type' => 'string' ),
					'new_version' => array( 'type' => 'string' ), 'messages' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_plugin( $input ) {
		$file = $this->resolve_plugin_file( $input['plugin'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		// Protected packages (KarMCP, Elementor, Elementor Pro) are never
		// mutated via MCP — including updates — matching deactivate/delete.
		//
		// One exception, and it has to be earned rather than asserted: when a
		// known vulnerability affects the installed version AND the update on
		// offer genuinely leaves the affected range, Elementor and Elementor Pro
		// may be updated. The Vulnerabilities module answers this filter; with
		// the module off — which is the default — nothing changes.
		//
		// It is a filter and not a direct call on purpose: the guard is core
		// plumbing and must not depend on an optional module. KarMCP itself is
		// never eligible, because replacing your own code mid-request is not the
		// same problem as updating a dependency.
		if ( KarMCP_Package_Guard::is_protected_plugin( $file )
			&& ! apply_filters( 'karmcp_allow_protected_update', false, $file ) ) {
			return new \WP_Error(
				'protected_plugin',
				sprintf(
					/* translators: %s: plugin file */
					__( '"%s" is protected and cannot be updated via MCP. The one exception is a security update: with the Known Vulnerabilities module enabled, an update that clears a vulnerability affecting the installed version is permitted.', 'karmcp' ),
					$file
				)
			);
		}
		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_site_transient( 'update_plugins' );
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();
		$all     = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$old     = (string) ( $all[ $file ]['Version'] ?? '' );
		if ( ! isset( $resp[ $file ] ) ) {
			return array( 'success' => true, 'up_to_date' => true, 'plugin' => $file, 'old_version' => $old, 'new_version' => $old, 'messages' => array() );
		}
		$skin     = KarMCP_Package_Guard::make_skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'update_failed', __( 'Plugin update failed.', 'karmcp' ) );
		}
		return array(
			'success'     => true,
			'up_to_date'  => false,
			'plugin'      => $file,
			'old_version' => $old,
			'new_version' => (string) ( $resp[ $file ]->new_version ?? '' ),
			'messages'    => KarMCP_Package_Guard::skin_messages( $skin ),
		);
	}

	// -------------------------------------------------------------------
	// delete-plugin
	// -------------------------------------------------------------------

	private function register_delete_plugin(): void {
		$this->ability_names[] = 'karmcp/delete-plugin';
		karmcp_register_ability(
			'karmcp/delete-plugin',
			array(
				'label'               => __( 'Delete Plugin', 'karmcp' ),
				'description'         => __( 'Permanently deletes an installed plugin. Destructive. Refuses protected plugins (KarMCP / Elementor) and any active plugin (deactivate it first).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete_plugin' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'plugin' => array( 'type' => 'string', 'description' => __( 'Plugin file or folder slug.', 'karmcp' ) ) ),
					'required'   => array( 'plugin' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'deleted' => array( 'type' => 'boolean' ), 'plugin' => array( 'type' => 'string' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_delete_plugin( $input ) {
		$file = $this->resolve_plugin_file( $input['plugin'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( KarMCP_Package_Guard::is_protected_plugin( $file ) ) {
			return new \WP_Error( 'protected_plugin', sprintf( /* translators: %s: plugin file */ __( '"%s" is protected and cannot be deleted via MCP.', 'karmcp' ), $file ) );
		}
		if ( KarMCP_Package_Guard::is_active_plugin( $file ) ) {
			return new \WP_Error( 'plugin_active', __( 'Deactivate the plugin before deleting it.', 'karmcp' ) );
		}
		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new \WP_Error( 'delete_failed', __( 'Plugin deletion failed.', 'karmcp' ) );
		}
		return array( 'deleted' => true, 'plugin' => $file );
	}

	// -------------------------------------------------------------------
	// helper
	// -------------------------------------------------------------------

	/**
	 * Resolve a user-supplied plugin reference (file path or folder slug) to an
	 * installed plugin file. Returns WP_Error('plugin_not_found') if unknown.
	 *
	 * @param string $ref
	 * @return string|\WP_Error
	 */
	private function resolve_plugin_file( $ref ) {
		$ref = (string) $ref;
		if ( '' === $ref ) {
			return new \WP_Error( 'missing_params', __( 'A "plugin" reference is required.', 'karmcp' ) );
		}
		KarMCP_Package_Guard::load_upgrader_deps();
		$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
		if ( isset( $all[ $ref ] ) ) {
			return $ref;
		}
		// Treat $ref as a folder slug and match the first file in that folder.
		foreach ( array_keys( $all ) as $file ) {
			if ( dirname( (string) $file ) === $ref ) {
				return (string) $file;
			}
		}
		return new \WP_Error( 'plugin_not_found', sprintf( /* translators: %s: plugin reference */ __( 'No installed plugin matches "%s".', 'karmcp' ), $ref ) );
	}
}
