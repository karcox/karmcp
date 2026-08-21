<?php
/**
 * Recovery tools: read the fatals, see what got paused, put it back.
 *
 * The drop-in next door brings a dead site back on its own; these are how an
 * agent finds out what happened once it can connect again — which it can,
 * precisely because the site came back.
 *
 * `update-core` lives here too. It is the most consequential write in the
 * plugin (replacing the floor while standing on it, mid-request, on the very
 * core serving that request), and it is only reasonable to offer *because* the
 * handler is there to catch the fall.
 *
 * @package KarMCP
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recovery and core-update abilities.
 *
 * @since 1.6.0
 */
class KarMCP_Recovery_Abilities {

	/**
	 * @since 1.6.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/get-fatal-log',
			'karmcp/list-paused-plugins',
			'karmcp/resume-plugin',
			'karmcp/update-core',
		);
	}

	/**
	 * @since 1.6.0
	 * @return void
	 */
	public function register(): void {
		$this->register_get_fatal_log();
		$this->register_list_paused();
		$this->register_resume();
		$this->register_update_core();
	}

	/**
	 * @since 1.6.0
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 1.6.0
	 * @return bool
	 */
	public function check_plugin_permission(): bool {
		return current_user_can( 'activate_plugins' );
	}

	/**
	 * @since 1.6.0
	 * @return bool
	 */
	public function check_core_permission(): bool {
		return current_user_can( 'update_core' );
	}

	// ---------------------------------------------------------------------
	// get-fatal-log
	// ---------------------------------------------------------------------

	private function register_get_fatal_log(): void {
		karmcp_register_ability(
			'karmcp/get-fatal-log',
			array(
				'label'               => __( 'Get Fatal Error Log', 'karmcp' ),
				'description'         => __( 'Returns the fatal errors KarMCP\'s error handler recorded — message, file, line, and which plugin the file belongs to — newest last. This is what the site can tell you about why it broke, written at the moment it broke. Requires the fatal-error handler drop-in to be installed (Security tab). Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_fatal_log' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array( 'type' => 'integer', 'description' => __( 'Most recent N entries. Default: all (25 are kept).', 'karmcp' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'handler_status' => array( 'type' => 'string' ),
						'count'          => array( 'type' => 'integer' ),
						'entries'        => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.6.0
	 * @param array $input Tool input.
	 * @return array
	 */
	public function execute_get_fatal_log( $input ) {
		$log = get_option( KarMCP_Fatal_Handler_Template::OPTION_LOG, array() );
		$log = is_array( $log ) ? $log : array();

		$limit = absint( $input['limit'] ?? 0 );
		if ( $limit > 0 && count( $log ) > $limit ) {
			$log = array_slice( $log, -$limit );
		}

		return array(
			'handler_status' => KarMCP_Fatal_Handler_Template::status(),
			'count'          => count( $log ),
			'entries'        => array_values( $log ),
		);
	}

	// ---------------------------------------------------------------------
	// list-paused-plugins
	// ---------------------------------------------------------------------

	private function register_list_paused(): void {
		karmcp_register_ability(
			'karmcp/list-paused-plugins',
			array(
				'label'               => __( 'List Paused Plugins', 'karmcp' ),
				'description'         => __( 'Lists plugins the fatal-error handler deactivated because they crashed the site repeatedly, with when it happened and how many fatals it took. These are deactivated, not removed: resume-plugin puts one back. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_paused' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'  => array( 'type' => 'integer' ),
						'paused' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.6.0
	 * @param array $input Tool input.
	 * @return array
	 */
	public function execute_list_paused( $input ) {
		unset( $input );
		// Reconciled, not raw: a plugin someone reactivated by hand is not paused
		// any more, whatever the option still remembers.
		$paused = KarMCP_Fatal_Handler_Template::paused();

		$out = array();
		foreach ( $paused as $file => $meta ) {
			$out[] = array(
				'plugin'    => (string) $file,
				'paused_at' => (int) ( $meta['at'] ?? 0 ),
				'fatals'    => (int) ( $meta['hits'] ?? 0 ),
				'window'    => (int) ( $meta['window'] ?? 0 ),
			);
		}

		return array(
			'count'  => count( $out ),
			'paused' => $out,
		);
	}

	// ---------------------------------------------------------------------
	// resume-plugin
	// ---------------------------------------------------------------------

	private function register_resume(): void {
		karmcp_register_ability(
			'karmcp/resume-plugin',
			array(
				'label'               => __( 'Resume Paused Plugin', 'karmcp' ),
				'description'         => __( 'Reactivates a plugin the fatal-error handler deactivated, and clears its pause record. Only do this after the cause is fixed: reactivating an unfixed plugin will crash the site again, and the handler will deactivate it again. Requires confirm:true.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_resume' ),
				'permission_callback' => array( $this, 'check_plugin_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'  => array( 'type' => 'string', 'description' => __( 'Plugin file, e.g. "my-plugin/my-plugin.php", exactly as list-paused-plugins reports it.', 'karmcp' ) ),
						'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true. Acknowledges that an unfixed plugin will crash the site again.', 'karmcp' ) ),
					),
					'required'   => array( 'plugin', 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'resumed' => array( 'type' => 'boolean' ),
						'plugin'  => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.6.0
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute_resume( $input ) {
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Reactivating a plugin that still crashes will take the site down again. Pass confirm:true once the cause is fixed.', 'karmcp' ) );
		}

		$plugin = (string) ( $input['plugin'] ?? '' );
		$paused = KarMCP_Fatal_Handler_Template::paused();

		if ( '' === $plugin || ! isset( $paused[ $plugin ] ) ) {
			return new \WP_Error(
				'not_paused',
				sprintf(
					/* translators: %s: plugin file. */
					__( '"%s" is not in the paused list. Use list-paused-plugins to see what is.', 'karmcp' ),
					$plugin
				)
			);
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		unset( $paused[ $plugin ] );
		update_option( KarMCP_Fatal_Handler_Template::OPTION_PAUSED, $paused, false );

		return array(
			'resumed' => true,
			'plugin'  => $plugin,
		);
	}

	// ---------------------------------------------------------------------
	// update-core
	// ---------------------------------------------------------------------

	private function register_update_core(): void {
		karmcp_register_ability(
			'karmcp/update-core',
			array(
				'label'               => __( 'Update WordPress Core', 'karmcp' ),
				'description'         => __( 'Updates WordPress itself to the latest available release. THE MOST CONSEQUENTIAL WRITE IN THIS PLUGIN: it replaces the code currently serving the request, so a failure can take the whole site down rather than return an error. Reports up_to_date when nothing is pending. Requires confirm:true, and strongly prefers the fatal-error handler to be installed first so a bad upgrade self-recovers.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_core' ),
				'permission_callback' => array( $this, 'check_core_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true. Acknowledges that a failed core update can take the site offline.', 'karmcp' ) ),
					),
					'required'   => array( 'confirm' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'        => array( 'type' => 'boolean' ),
						'up_to_date'     => array( 'type' => 'boolean' ),
						'old_version'    => array( 'type' => 'string' ),
						'new_version'    => array( 'type' => 'string' ),
						'handler_status' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.6.0
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute_update_core( $input ) {
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirmation_required',
				__( 'A core update replaces the code serving this very request. If it fails the site goes down rather than returning an error. Take a backup, install the fatal-error handler from the Security tab, then call again with confirm:true.', 'karmcp' )
			);
		}

		foreach ( array( 'update.php', 'file.php', 'misc.php', 'class-wp-upgrader.php' ) as $inc ) {
			$path = ABSPATH . 'wp-admin/includes/' . $inc;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
		if ( ! function_exists( 'find_core_update' ) || ! class_exists( '\Core_Upgrader' ) ) {
			return new \WP_Error( 'upgrader_unavailable', __( 'The WordPress core upgrader is not available on this install.', 'karmcp' ) );
		}

		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		global $wp_version;
		$old = (string) $wp_version;

		wp_version_check( array(), true );
		$updates = get_site_transient( 'update_core' );
		$offer   = null;
		if ( is_object( $updates ) && ! empty( $updates->updates ) && is_array( $updates->updates ) ) {
			foreach ( $updates->updates as $candidate ) {
				if ( isset( $candidate->response ) && 'upgrade' === $candidate->response ) {
					$offer = $candidate;
					break;
				}
			}
		}

		if ( null === $offer ) {
			return array(
				'success'        => true,
				'up_to_date'     => true,
				'old_version'    => $old,
				'new_version'    => $old,
				'handler_status' => KarMCP_Fatal_Handler_Template::status(),
			);
		}

		$upgrader = new \Core_Upgrader( KarMCP_Package_Guard::make_skin() );
		$result   = $upgrader->upgrade( $offer );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'        => true,
			'up_to_date'     => false,
			'old_version'    => $old,
			'new_version'    => (string) ( $offer->version ?? '' ),
			'handler_status' => KarMCP_Fatal_Handler_Template::status(),
		);
	}
}
