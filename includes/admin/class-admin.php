<?php
/**
 * Admin settings page for KarMCP.
 *
 * Provides a UI to toggle individual MCP tools on/off and view
 * connection information for various MCP clients.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page orchestrator.
 *
 * @since 1.0.0
 */
class KarMCP_Admin {

	/**
	 * Hook suffixes returned by add_menu_page() / add_submenu_page(),
	 * used to scope asset enqueues to our screens only.
	 *
	 * @var string[]
	 */
	private $hook_suffixes = array();

	/**
	 * Option name for storing disabled tools.
	 *
	 * @var string
	 */
	const OPTION_DISABLED_TOOLS = 'karmcp_disabled_tools';

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'karmcp_settings';

	/**
	 * Dedicated settings group for the "Activate Abilities API for KarMCP" server
	 * gate. Kept separate from SETTINGS_GROUP so the Connection-tab toggle form
	 * submits only that option and can't wipe the Tools-page options on save.
	 *
	 * @since 1.7.4
	 * @var string
	 */
	const SETTINGS_GROUP_SERVER = 'karmcp_server_settings';

	/** Settings group for the Context page. */
	const SETTINGS_GROUP_CONTEXT = 'karmcp_context_settings';

	/** Settings group for the Modules tab (active-modules list + each module's knobs). */
	const SETTINGS_GROUP_MODULES = 'karmcp_modules_settings';

	/**
	 * Settings group for third-party service credentials (stock-image provider
	 * keys). Separate from SETTINGS_GROUP_SERVER so the "3rd Party Services"
	 * sub-tab form saves independently of the server-gate toggles.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP_SERVICES = 'karmcp_services_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'karmcp';

	/**
	 * Outbound links shown in the admin header's "Get Help" menu. Defined once
	 * here so the whole admin surface moves together when the project gets its
	 * own docs site — the views read these, never a literal URL.
	 *
	 * @var string
	 */
	const DOCS_URL = 'https://github.com/karcox/karmcp#readme';

	/** Where an admin reports a problem. */
	const SUPPORT_URL = 'https://github.com/karcox/karmcp/issues';

	/**
	 * Map of sub-screen slug => label. The first entry is the dashboard
	 * (rendered when the parent menu item is clicked).
	 *
	 * @var array<string, string>|null
	 */
	private $submenus = null;

	/**
	 * Returns the map of submenu slugs to translated labels.
	 *
	 * Initialised lazily so the strings are localised at call time.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Whether a module-backed admin tab should show. Visible when the module is
	 * not registered (free build / no overlay → keep the tab, e.g. an upsell) or
	 * it is active and available; hidden when registered but off or unavailable.
	 *
	 * @param string $module_id Module id.
	 * @return bool
	 */
	private function module_tab_visible( string $module_id ): bool {
		if ( ! class_exists( 'KarMCP_Modules_Registry' ) ) {
			return true;
		}
		$module = KarMCP_Modules_Registry::instance()->get( $module_id );
		if ( ! $module ) {
			return true;
		}
		return $module->is_active() && $module->is_available();
	}

	/**
	 * Dashicon class for a tab id, used by the in-header nav. Falls back to a
	 * generic marker for unknown ids.
	 *
	 * @param string $tab_id Tab id as returned by get_active_tab().
	 * @return string Dashicon class.
	 */
	public static function tab_icon( string $tab_id ): string {
		$icons = array(
			'dashboard'  => 'dashicons-dashboard',
			'tools'      => 'dashicons-admin-tools',
			'security'   => 'dashicons-shield',
			'history'    => 'dashicons-undo',
			'redirects'  => 'dashicons-randomize',
			'modules'    => 'dashicons-screenoptions',
			'connection' => 'dashicons-admin-links',
			'context'    => 'dashicons-info-outline',
			'prompts'    => 'dashicons-lightbulb',
			'templates'  => 'dashicons-layout',
			'brand-kits' => 'dashicons-art',
			'widgets'    => 'dashicons-editor-code',
			'mcp-log'    => 'dashicons-list-view',
			'changelog'  => 'dashicons-backup',
		);
		return $icons[ $tab_id ] ?? 'dashicons-marker';
	}

	private function get_submenus(): array {
		if ( null === $this->submenus ) {
			$this->submenus = array(
				self::PAGE_SLUG                 => __( 'Dashboard', 'karmcp' ),
				self::PAGE_SLUG . '-modules'    => __( 'Modules', 'karmcp' ),
				self::PAGE_SLUG . '-tools'      => __( 'Tools', 'karmcp' ),
				self::PAGE_SLUG . '-connection' => __( 'Connection', 'karmcp' ),
				self::PAGE_SLUG . '-context'    => __( 'Context', 'karmcp' ),
				self::PAGE_SLUG . '-redirects'  => __( 'Redirects', 'karmcp' ),
				self::PAGE_SLUG . '-prompts'    => __( 'Prompts', 'karmcp' ),
				self::PAGE_SLUG . '-templates'  => __( 'Templates', 'karmcp' ),
				self::PAGE_SLUG . '-brand-kits' => __( 'Brand Kits', 'karmcp' ),
				self::PAGE_SLUG . '-widgets'    => __( 'Sandbox', 'karmcp' ),
				self::PAGE_SLUG . '-mcp-log'    => __( 'MCP Log', 'karmcp' ),
				self::PAGE_SLUG . '-security'   => __( 'Security', 'karmcp' ),
				self::PAGE_SLUG . '-history'    => __( 'History', 'karmcp' ),
				self::PAGE_SLUG . '-changelog'  => __( 'Changelog', 'karmcp' ),
			);
			// Redirects tab is gated by the Redirect Manager module.
			if ( ! $this->module_tab_visible( 'redirects' ) ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-redirects' ] );
			}
			// Module-backed tabs: drop each when its module is off/unavailable.
			foreach ( array( 'prompts', 'templates', 'brand-kits' ) as $karmcp_mod_id ) {
				if ( ! $this->module_tab_visible( $karmcp_mod_id ) ) {
					unset( $this->submenus[ self::PAGE_SLUG . '-' . $karmcp_mod_id ] );
				}
			}
		}
		return $this->submenus;
	}

	/**
	 * Determine which sub-screen is active from $_GET['page'].
	 *
	 * @return string One of 'tools', 'connection', 'prompts', 'changelog'.
	 */
	private function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		switch ( $page ) {
			case self::PAGE_SLUG . '-tools':
				return 'tools';
			case self::PAGE_SLUG . '-security':
				return 'security';
			case self::PAGE_SLUG . '-history':
				return 'history';
			case self::PAGE_SLUG . '-redirects':
				return 'redirects';
			case self::PAGE_SLUG . '-migrate':
				return 'migrate';
			case self::PAGE_SLUG . '-modules':
				return 'modules';
			case self::PAGE_SLUG . '-connection':
				return 'connection';
			case self::PAGE_SLUG . '-ai-chat':
				return 'ai-chat';
			case self::PAGE_SLUG . '-context':
				return 'context';
			case self::PAGE_SLUG . '-prompts':
				return 'prompts';
			case self::PAGE_SLUG . '-templates':
				return 'templates';
			case self::PAGE_SLUG . '-brand-kits':
				return 'brand-kits';
			case self::PAGE_SLUG . '-skills':
				return 'skills';
			case self::PAGE_SLUG . '-widgets':
				return 'widgets';
			case self::PAGE_SLUG . '-mcp-log':
				return 'mcp-log';
			case self::PAGE_SLUG . '-changelog':
				return 'changelog';
			default:
				return 'dashboard';
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_security_actions' ) );
		add_action( 'admin_init', array( $this, 'maybe_apply_default_disabled_tools' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_karmcp_create_app_password', array( $this, 'ajax_create_app_password' ) );
		add_action( 'wp_ajax_karmcp_toggle_widget', array( $this, 'ajax_toggle_widget' ) );
		add_action( 'wp_ajax_karmcp_delete_widget', array( $this, 'ajax_delete_widget' ) );
		add_action( 'wp_ajax_karmcp_toggle_block', array( $this, 'ajax_toggle_block' ) );
		add_action( 'wp_ajax_karmcp_delete_block', array( $this, 'ajax_delete_block' ) );
		add_action( 'wp_ajax_karmcp_backup_artifact', array( $this, 'ajax_backup_artifact' ) );
		add_action( 'wp_ajax_karmcp_bulk_backup_artifacts', array( $this, 'ajax_bulk_backup_artifacts' ) );
		add_action( 'wp_ajax_karmcp_resync_cloud', array( $this, 'ajax_resync_cloud' ) );
		add_action( 'wp_ajax_karmcp_cloud_library', array( $this, 'ajax_cloud_library' ) );
		add_action( 'wp_ajax_karmcp_cloud_import', array( $this, 'ajax_cloud_import' ) );
		add_action( 'wp_ajax_karmcp_save_php_snippet', array( $this, 'ajax_save_php_snippet' ) );
		add_action( 'wp_ajax_karmcp_toggle_php_snippet', array( $this, 'ajax_toggle_php_snippet' ) );
		add_action( 'wp_ajax_karmcp_delete_php_snippet', array( $this, 'ajax_delete_php_snippet' ) );
		add_action( 'wp_ajax_karmcp_notifications_read', array( $this, 'ajax_notifications_read' ) );
		add_action( 'admin_post_karmcp_download_mcpb', array( $this, 'handle_download_mcpb' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS_PROMPTS_NOTICE, array( $this, 'handle_dismiss_prompts_notice' ) );
		add_action( 'admin_post_' . self::ACTION_ROLLBACK_CHANGE, array( $this, 'handle_rollback_change' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_CHANGE, array( $this, 'handle_delete_change' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_CHANGES, array( $this, 'handle_clear_changes' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE_OAUTH, array( $this, 'handle_revoke_oauth_client' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT_ARTIFACT, array( $this, 'handle_export_artifact' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT_ARTIFACT, array( $this, 'handle_import_artifact' ) );
		add_action( 'admin_post_karmcp_settings_push', array( $this, 'handle_settings_push' ) );
		add_action( 'admin_post_karmcp_settings_pull', array( $this, 'handle_settings_pull' ) );
		add_action( 'admin_post_karmcp_redirect_save', array( $this, 'handle_redirect_save' ) );
		add_action( 'admin_post_karmcp_redirect_delete', array( $this, 'handle_redirect_delete' ) );
		add_action( 'admin_post_karmcp_redirect_toggle', array( $this, 'handle_redirect_toggle' ) );
	}

	/** Nonce action for the .mcpb bundle download. */
	const NONCE_DOWNLOAD_MCPB = 'karmcp_download_mcpb';

	/** admin-post action that dismisses the "prompts rewritten" notice. */
	const ACTION_DISMISS_PROMPTS_NOTICE = 'karmcp_dismiss_prompts_notice';

	/** admin-post action that rolls back a change from the History tab. */
	const ACTION_ROLLBACK_CHANGE = 'karmcp_rollback_change';

	/** admin-post action that deletes one entry from the History ledger. */
	const ACTION_DELETE_CHANGE = 'karmcp_delete_change';

	/** admin-post action that clears the whole History ledger. */
	const ACTION_CLEAR_CHANGES = 'karmcp_clear_changes';

	/** Nonce action shared by the sandbox artifact export/import admin-post handlers. */
	const NONCE_SANDBOX_BUNDLE = 'karmcp_sandbox_bundle';

	/** admin-post action that streams a sandbox artifact as a portable JSON bundle download. */
	const ACTION_EXPORT_ARTIFACT = 'karmcp_export_artifact';

	/** admin-post action that imports an uploaded sandbox artifact bundle. */
	const ACTION_IMPORT_ARTIFACT = 'karmcp_import_artifact';

	/**
	 * admin-post action: revoke all tokens for one OAuth client.
	 *
	 * @var string
	 */
	const ACTION_REVOKE_OAUTH = 'karmcp_revoke_oauth_client';

	/**
	 * Nonce-protected URL that rolls back one change-ledger entry.
	 *
	 * @since 3.3.0
	 * @param string $id Change id.
	 * @return string
	 */
	public static function rollback_change_url( string $id, bool $force = false ): string {
		$url = admin_url( 'admin-post.php?action=' . self::ACTION_ROLLBACK_CHANGE . '&change=' . rawurlencode( $id ) );
		if ( $force ) {
			$url .= '&force=1';
		}
		return wp_nonce_url( $url, self::ACTION_ROLLBACK_CHANGE . '_' . $id );
	}

	/**
	 * Roll back a change from the History tab, then bounce back with a notice.
	 *
	 * @since 3.3.0
	 */
	public function handle_rollback_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below; force is a display flag on an admin-gated action.
		$force = ! empty( $_GET['force'] );
		check_admin_referer( self::ACTION_ROLLBACK_CHANGE . '_' . $id );

		$result = class_exists( 'KarMCP_Change_Log' ) ? KarMCP_Change_Log::rollback( $id, $force ) : new WP_Error( 'unavailable', 'unavailable' );
		if ( is_wp_error( $result ) ) {
			// A conflict is recoverable — bounce back with the id so the History
			// tab can offer a "roll back anyway" (force) action.
			$status = ( 'conflict' === $result->get_error_code() )
				? 'conflict&change=' . rawurlencode( $id )
				: 'error&msg=' . rawurlencode( $result->get_error_message() );
		} else {
			$status = ! empty( $result['partial'] ) ? 'partial' : 'ok';
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&rollback=' . $status ) );
		exit;
	}

	/**
	 * Nonce'd URL that deletes one History entry.
	 *
	 * @since 3.4.2
	 * @param string $id Entry id.
	 * @return string
	 */
	public static function delete_change_url( string $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DELETE_CHANGE . '&change=' . rawurlencode( $id ) ),
			self::ACTION_DELETE_CHANGE . '_' . $id
		);
	}

	/**
	 * Nonce'd URL that clears the whole History ledger.
	 *
	 * @since 3.4.2
	 * @return string
	 */
	public static function clear_changes_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR_CHANGES ),
			self::ACTION_CLEAR_CHANGES
		);
	}

	/**
	 * Delete one entry from the History ledger, then bounce back with a notice.
	 *
	 * @since 3.4.2
	 */
	public function handle_delete_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
		check_admin_referer( self::ACTION_DELETE_CHANGE . '_' . $id );

		$deleted = class_exists( 'KarMCP_Change_Log' ) && KarMCP_Change_Log::delete( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&deleted=' . ( $deleted ? '1' : '0' ) ) );
		exit;
	}

	/**
	 * Clear the whole History ledger, then bounce back with a notice.
	 *
	 * @since 3.4.2
	 */
	public function handle_clear_changes(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_CLEAR_CHANGES );

		$count = class_exists( 'KarMCP_Change_Log' ) ? KarMCP_Change_Log::clear() : 0;

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&cleared=' . (int) $count ) );
		exit;
	}

	/**
	 * Bounce back to the Redirects tab with a status code.
	 *
	 * @param string $status Status slug for a notice.
	 */
	private function redirect_back_to_redirects( string $status ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-redirects&notice=' . rawurlencode( $status ) ) );
		exit;
	}

	/**
	 * Create or update a redirect from the management form. Routes through the
	 * store + ledger so admin edits are reversible in History.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karmcp_redirect_save' );
		if ( ! class_exists( 'KarMCP_Redirect_Store' ) ) {
			$this->redirect_back_to_redirects( 'error' );
		}
		$id             = isset( $_POST['redirect_id'] ) ? absint( wp_unslash( $_POST['redirect_id'] ) ) : 0;
		$source         = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target_raw     = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
		$target_post_id = isset( $_POST['target_post_id'] ) ? absint( wp_unslash( $_POST['target_post_id'] ) ) : 0;
		$status_code    = isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301;
		$ignore_query   = ! empty( $_POST['ignore_query'] );

		$data = array(
			'source'       => $source,
			'status_code'  => $status_code,
			'ignore_query' => $ignore_query,
		);
		if ( $target_post_id ) {
			$data['target_post_id'] = $target_post_id;
		} else {
			$data['target'] = $target_raw;
		}

		if ( $id ) {
			$prior = KarMCP_Redirect_Store::get( $id );
			$res   = KarMCP_Redirect_Store::update( $id, $data );
			if ( ! is_wp_error( $res ) && $prior && class_exists( 'KarMCP_Change_Recorder' ) ) {
				KarMCP_Change_Recorder::record_redirect( 'update', array( 'row' => $prior ), sprintf( 'Updated redirect %s', $res['source_path'] ), (string) $res['source_path'] );
			}
		} else {
			$res = KarMCP_Redirect_Store::create( $data );
			if ( ! is_wp_error( $res ) && class_exists( 'KarMCP_Change_Recorder' ) ) {
				KarMCP_Change_Recorder::record_redirect( 'create', array( 'id' => (int) $res['id'] ), sprintf( 'Created redirect %s', $res['source_path'] ), (string) $res['source_path'] );
			}
		}
		$this->redirect_back_to_redirects( is_wp_error( $res ) ? 'error:' . $res->get_error_code() : ( $id ? 'updated' : 'created' ) );
	}

	/**
	 * Delete a redirect (nonce per-id), recorded for rollback.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'karmcp_redirect_delete_' . $id );
		if ( class_exists( 'KarMCP_Redirect_Store' ) ) {
			$prior = KarMCP_Redirect_Store::get( $id );
			if ( $prior && KarMCP_Redirect_Store::delete( $id ) && class_exists( 'KarMCP_Change_Recorder' ) ) {
				KarMCP_Change_Recorder::record_redirect( 'delete', array( 'row' => $prior ), sprintf( 'Deleted redirect %s', $prior['source_path'] ), (string) $prior['source_path'] );
			}
		}
		$this->redirect_back_to_redirects( 'deleted' );
	}

	/**
	 * Toggle a redirect's enabled state (nonce per-id), recorded for rollback.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'karmcp_redirect_toggle_' . $id );
		if ( class_exists( 'KarMCP_Redirect_Store' ) ) {
			$prior = KarMCP_Redirect_Store::get( $id );
			if ( $prior ) {
				$res = KarMCP_Redirect_Store::update( $id, array( 'enabled' => empty( $prior['enabled'] ) ) );
				if ( ! is_wp_error( $res ) && class_exists( 'KarMCP_Change_Recorder' ) ) {
					KarMCP_Change_Recorder::record_redirect( 'update', array( 'row' => $prior ), sprintf( 'Toggled redirect %s', $prior['source_path'] ), (string) $prior['source_path'] );
				}
			}
		}
		$this->redirect_back_to_redirects( 'updated' );
	}

	/**
	 * Nonce'd URL that deletes one redirect.
	 *
	 * @since 3.11.0
	 * @param int $id Redirect id.
	 * @return string
	 */
	public static function redirect_delete_url( int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=karmcp_redirect_delete&id=' . $id ),
			'karmcp_redirect_delete_' . $id
		);
	}

	/**
	 * Nonce'd URL that toggles one redirect's enabled state.
	 *
	 * @since 3.11.0
	 * @param int $id Redirect id.
	 * @return string
	 */
	public static function redirect_toggle_url( int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=karmcp_redirect_toggle&id=' . $id ),
			'karmcp_redirect_toggle_' . $id
		);
	}

	/**
	 * Push the local KarMCP settings to KarMCP Cloud (paid Cloud feature).
	 */
	public function handle_settings_push(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karmcp_settings_sync' );
		$res = class_exists( 'KarMCP_Settings_Sync' ) ? KarMCP_Settings_Sync::push() : new \WP_Error( 'unavailable', '' );
		$this->redirect_settings_sync( is_wp_error( $res ) ? 'err' : 'push' );
	}

	/**
	 * Pull the KarMCP settings from KarMCP Cloud and apply them (paid Cloud feature).
	 */
	public function handle_settings_pull(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'karmcp_settings_sync' );
		$res = class_exists( 'KarMCP_Settings_Sync' ) ? KarMCP_Settings_Sync::pull_and_apply() : new \WP_Error( 'unavailable', '' );
		$this->redirect_settings_sync( is_wp_error( $res ) ? 'err' : 'pull' );
	}

	/**
	 * Back up a Sandbox artifact (block/widget/snippet) to KarMCP Cloud. AJAX.
	 */
	public function ajax_backup_artifact(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$nonces = array(
			'widget'  => 'karmcp_widgets',
			'block'   => 'karmcp_blocks',
			'snippet' => 'karmcp_php_snippets',
		);
		if ( ! isset( $nonces[ $kind ] ) || ! check_ajax_referer( $nonces[ $kind ], 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id || ! class_exists( 'KarMCP_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'karmcp' ) ) );
		}
		$res = KarMCP_Cloud_Sync::backup( $kind, $id );
		if ( is_wp_error( $res ) ) {
			$msg = ( 'not_connected' === $res->get_error_code() )
				? __( 'Connect this site to KarMCP Cloud first.', 'karmcp' )
				: $res->get_error_message();
			wp_send_json_error( array( 'message' => $msg ) );
		}
		// Record that this artifact now exists in the cloud + the checksum of what
		// was pushed, so a later local edit is detectable.
		update_post_meta( $id, '_karmcp_cloud_pushed', time() );
		self::store_artifact_checksum( $kind, $id );
		$payload            = self::cloud_action_payload( $kind, $id );
		$payload['message'] = __( 'Saved to cloud.', 'karmcp' );
		wp_send_json_success( $payload );
	}

	/**
	 * Back up EVERY Sandbox artifact of a kind to KarMCP Cloud in one call — the
	 * bulk counterpart to ajax_backup_artifact(), driving the "Save all to Cloud"
	 * button. AJAX.
	 */
	public function ajax_bulk_backup_artifacts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$nonces = array(
			'widget'  => 'karmcp_widgets',
			'block'   => 'karmcp_blocks',
			'snippet' => 'karmcp_php_snippets',
		);
		if ( ! isset( $nonces[ $kind ] ) || ! check_ajax_referer( $nonces[ $kind ], 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}
		if ( ! class_exists( 'KarMCP_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cloud sync is unavailable.', 'karmcp' ) ) );
		}
		$res = KarMCP_Cloud_Sync::bulk_backup( array( $kind ) );
		if ( is_wp_error( $res ) ) {
			$msg = ( 'not_connected' === $res->get_error_code() )
				? __( 'Connect this site to KarMCP Cloud first.', 'karmcp' )
				: $res->get_error_message();
			wp_send_json_error( array( 'message' => $msg ) );
		}
		// Mirror the per-artifact post-processing so each pushed row reflects "Saved".
		foreach ( (array) ( $res['items'] ?? array() ) as $karmcp_item ) {
			if ( empty( $karmcp_item['ok'] ) ) {
				continue;
			}
			$karmcp_iid = (int) ( $karmcp_item['id'] ?? 0 );
			if ( $karmcp_iid ) {
				update_post_meta( $karmcp_iid, '_karmcp_cloud_pushed', time() );
				self::store_artifact_checksum( $kind, $karmcp_iid );
			}
		}
		$pushed = (int) ( $res['pushed'] ?? 0 );
		$failed = (int) ( $res['failed'] ?? 0 );
		/* translators: %d: number of artifacts saved to the cloud. */
		$message = sprintf( _n( 'Saved %d item to the cloud.', 'Saved %d items to the cloud.', $pushed, 'karmcp' ), $pushed );
		if ( $failed > 0 ) {
			/* translators: %d: number of artifacts that failed to save. */
			$message .= ' ' . sprintf( _n( '%d failed.', '%d failed.', $failed, 'karmcp' ), $failed );
		}
		wp_send_json_success(
			array(
				'pushed'  => $pushed,
				'failed'  => $failed,
				'message' => $message,
			)
		);
	}

	/** Nonce action for a sandbox artifact kind. */
	private static function cloud_nonce_action( string $kind ): string {
		$map = array( 'widget' => 'karmcp_widgets', 'block' => 'karmcp_blocks', 'snippet' => 'karmcp_php_snippets' );
		return $map[ $kind ] ?? '';
	}

	/** Cache the current content checksum as the last-pushed checksum. */
	private static function store_artifact_checksum( string $kind, int $id ): void {
		$sum = self::artifact_checksum( $kind, $id );
		if ( '' !== $sum ) {
			update_post_meta( $id, '_karmcp_cloud_checksum', $sum );
		}
	}

	/** Current content checksum for an artifact ('' if unresolvable). */
	private static function artifact_checksum( string $kind, int $id ): string {
		if ( ! class_exists( 'KarMCP_Sandbox_Cloud_Abilities' ) ) {
			return '';
		}
		$art = ( new KarMCP_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		return $art ? (string) $art->checksum( $id ) : '';
	}

	/** True when local content differs from what was last pushed to the cloud. */
	private static function artifact_changed( string $kind, int $id ): bool {
		if ( ! get_post_meta( $id, '_karmcp_cloud_pushed', true ) ) {
			return false;
		}
		$pushed = (string) get_post_meta( $id, '_karmcp_cloud_checksum', true );
		if ( '' === $pushed ) {
			// No recorded baseline — e.g. the artifact was pushed/published before
			// checksum tracking existed. We can't prove the content is unchanged,
			// so allow an update rather than hide "Push update" forever. Pushing (or
			// re-saving) records a fresh baseline via store_artifact_checksum(),
			// which self-heals the state back to "Up to date".
			return true;
		}
		return self::artifact_checksum( $kind, $id ) !== $pushed;
	}


	/**
	 * Verify the artifact still exists as a cloud backup. If it was deleted
	 * remotely, clear the local "pushed" flag so the button reverts from
	 * "Saved" to "Save to Cloud".
	 *
	 * Only a definitive 404/410 resets the state — transient errors (network,
	 * 5xx, not-connected) leave it untouched so a blip never drops a real save.
	 */
	private static function verify_cloud_backup( string $kind, int $id ): void {
		if ( ! get_post_meta( $id, '_karmcp_cloud_pushed', true ) ) {
			return; // nothing claims to be pushed.
		}
		if ( ! class_exists( 'KarMCP_Cloud_Client' ) || ! class_exists( 'KarMCP_Sandbox_Cloud_Abilities' ) ) {
			return;
		}
		$art  = ( new KarMCP_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		$uuid = $art ? (string) $art->uuid( $id ) : '';
		if ( '' === $uuid ) {
			return;
		}
		$res = KarMCP_Cloud_Client::get( '/api/cloud/v1/artifacts/' . rawurlencode( $uuid ) );
		if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'cloud_http_404', 'cloud_http_410' ), true ) ) {
			delete_post_meta( $id, '_karmcp_cloud_pushed' );
			delete_post_meta( $id, '_karmcp_cloud_checksum' );
		}
	}

	/** JS payload describing an artifact's cloud backup state (from cached meta). */
	public static function cloud_action_payload( string $kind, int $id ): array {
		return array(
			'kind'    => $kind,
			'id'      => $id,
			'pushed'  => (bool) get_post_meta( $id, '_karmcp_cloud_pushed', true ),
			'changed' => self::artifact_changed( $kind, $id ),
		);
	}

	/**
	 * Renders the Sandbox cloud button. Backup only — this plugin ships no
	 * marketplace, so there is nothing to publish an artifact TO. The correct
	 * state is rendered server-side (works without JS); sandbox-cloud.js refines
	 * it after refreshing state.
	 */
	public static function render_sandbox_cloud_actions( string $kind, int $id ): string {
		if ( ! class_exists( 'KarMCP_Cloud' ) || ! KarMCP_Cloud::is_connected() ) {
			return '';
		}
		$s     = self::cloud_action_payload( $kind, $id );
		$nonce = wp_create_nonce( self::cloud_nonce_action( $kind ) );

		$pushed  = ! empty( $s['pushed'] );
		$changed = ! empty( $s['changed'] );

		$save_dis = false;
		if ( ! $pushed ) {
			$save_txt = __( 'Save to Cloud', 'karmcp' );
		} elseif ( $changed ) {
			$save_txt = __( 'Update cloud', 'karmcp' );
		} else {
			$save_txt = __( 'Saved', 'karmcp' );
			$save_dis = true;
		}

		$icon = static function ( string $d ): string {
			return '<span class="dashicons dashicons-' . esc_attr( $d ) . '" aria-hidden="true"></span>';
		};

		ob_start();
		?>
		<span class="karmcp-sb-cloud" data-kind="<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( (string) $id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-state="<?php echo esc_attr( (string) wp_json_encode( $s ) ); ?>">
			<button type="button" class="button karmcp-sb-save"<?php disabled( $save_dis ); ?>
				data-t-save="<?php echo esc_attr__( 'Save to Cloud', 'karmcp' ); ?>"
				data-t-update="<?php echo esc_attr__( 'Update cloud', 'karmcp' ); ?>"
				data-t-saved="<?php echo esc_attr__( 'Saved', 'karmcp' ); ?>"><?php
				echo $icon( 'backup' ) . '<span class="karmcp-sb-txt">' . esc_html( $save_txt ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?></button>
			<span class="karmcp-sb-msg" aria-live="polite"></span>
		</span>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the "Cloud Library" panel for a sandbox screen — a collapsible list
	 * of the whole workspace's cloud artifacts of this kind (across every connected
	 * site), each importable into THIS site as a new inactive draft. Empty string
	 * when the site isn't cloud-connected. The list is fetched lazily on first open
	 * (see assets/js/cloud-library.js); import runs KarMCP_Cloud_Sync::pull().
	 *
	 * @param string $kind Artifact kind (widget/block/snippet).
	 * @return string
	 */
	public static function render_cloud_library( string $kind ): string {
		if ( ! class_exists( 'KarMCP_Cloud' ) || ! KarMCP_Cloud::is_connected() ) {
			return '';
		}
		$na = self::cloud_nonce_action( $kind );
		if ( '' === $na ) {
			return '';
		}
		$plural = array(
			'widget'  => __( 'widgets', 'karmcp' ),
			'block'   => __( 'blocks', 'karmcp' ),
			'snippet' => __( 'snippets', 'karmcp' ),
		);
		$kl = $plural[ $kind ] ?? $kind;

		ob_start();
		?>
		<details class="karmcp-cloud-lib karmcp-sb-disclosure karmcp-sb-disclosure--cloud" data-kind="<?php echo esc_attr( $kind ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( $na ) ); ?>" data-site="<?php echo esc_attr( KarMCP_Cloud::site_uuid() ); ?>"
			data-t-loading="<?php echo esc_attr__( 'Loading…', 'karmcp' ); ?>"
			data-t-import="<?php echo esc_attr__( 'Import', 'karmcp' ); ?>"
			data-t-importing="<?php echo esc_attr__( 'Importing…', 'karmcp' ); ?>"
			data-t-imported="<?php echo esc_attr__( 'Imported', 'karmcp' ); ?>"
			data-t-thissite="<?php echo esc_attr__( 'This site', 'karmcp' ); ?>"
			data-t-othersite="<?php echo esc_attr__( 'Another site', 'karmcp' ); ?>"
			data-t-empty="<?php echo esc_attr__( 'Nothing in your cloud library yet. Save one from another connected site, then it appears here.', 'karmcp' ); ?>"
			data-t-error="<?php echo esc_attr__( 'Could not reach the cloud. Try again.', 'karmcp' ); ?>"
			data-t-reloadhint="<?php echo esc_attr__( 'Imported as a new inactive draft below.', 'karmcp' ); ?>"
			data-t-reload="<?php echo esc_attr__( 'Reload to view →', 'karmcp' ); ?>">
			<summary>
				<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
				<?php
				/* translators: %s: artifact kind, plural (widgets / blocks / snippets). */
				echo esc_html( sprintf( __( 'Cloud Library — import %s from your other connected sites', 'karmcp' ), $kl ) );
				?>
				<span class="karmcp-sb-disclosure__badge"><?php esc_html_e( 'Cross-site', 'karmcp' ); ?></span>
			</summary>
			<div class="karmcp-cloud-lib__body" style="margin-top:12px;">
				<p class="karmcp-cloud-lib__status description"><?php esc_html_e( 'Open to load your cloud library…', 'karmcp' ); ?></p>
				<table class="widefat striped karmcp-cloud-lib__table" style="display:none;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'karmcp' ); ?></th>
							<th><?php esc_html_e( 'From', 'karmcp' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Version', 'karmcp' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Updated', 'karmcp' ); ?></th>
							<th style="width:120px;"></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * List the workspace's cloud artifacts of a kind (across all connected sites).
	 * Feeds the Cloud Library panel. AJAX.
	 */
	public function ajax_cloud_library(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'karmcp' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}
		if ( ! class_exists( 'KarMCP_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cloud is unavailable.', 'karmcp' ) ) );
		}
		$res = KarMCP_Cloud_Sync::list_remote( $kind );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$arts = ( is_array( $res ) && isset( $res['artifacts'] ) && is_array( $res['artifacts'] ) ) ? $res['artifacts'] : array();
		$out  = array();
		foreach ( $arts as $a ) {
			$out[] = array(
				'uuid'        => (string) ( $a['artifact_uuid'] ?? '' ),
				'title'       => (string) ( $a['title'] ?? '' ),
				'version'     => (int) ( $a['version'] ?? 1 ),
				'origin'      => (string) ( $a['origin_site_uuid'] ?? '' ),
				'origin_url'  => (string) ( $a['origin_site_url'] ?? '' ),
				'origin_name' => (string) ( $a['origin_site_name'] ?? '' ),
				'updated'     => (string) ( $a['updated_at'] ?? '' ),
			);
		}
		wp_send_json_success(
			array(
				'artifacts' => $out,
				'site'      => class_exists( 'KarMCP_Cloud' ) ? KarMCP_Cloud::site_uuid() : '',
			)
		);
	}

	/**
	 * Pull one cloud artifact into this site as a new inactive draft. AJAX.
	 * Delegates to KarMCP_Cloud_Sync::pull() (imports the portable bundle).
	 */
	public function ajax_cloud_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'karmcp' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( '' === $uuid || ! class_exists( 'KarMCP_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to import.', 'karmcp' ) ) );
		}
		$res = KarMCP_Cloud_Sync::pull( $uuid, $kind );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'id'      => (int) ( $res['id'] ?? 0 ),
				'message' => __( 'Imported as a new inactive draft.', 'karmcp' ),
			)
		);
	}



	/**
	 * Resync an artifact's cloud state: verify the backup still exists remotely,
	 * which self-heals a stale "Saved" after a cloud-side delete. Drives the
	 * "Refresh cloud status" button.
	 */
	public function ajax_resync_cloud(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'karmcp' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing id.', 'karmcp' ) ) );
		}
		self::verify_cloud_backup( $kind, $id );
		$payload            = self::cloud_action_payload( $kind, $id );
		$payload['message'] = __( 'Cloud status refreshed.', 'karmcp' );
		wp_send_json_success( $payload );
	}

	/**
	 * Redirect back to the Connection tab after a settings-sync action.
	 *
	 * @param string $status push|pull|err.
	 */
	private function redirect_settings_sync( string $status ): void {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection' );
		}
		wp_safe_redirect( add_query_arg( 'synced', $status, $back ) );
		exit;
	}

	/**
	 * Revoke every token issued to an OAuth client (disconnects it).
	 */
	public function handle_revoke_oauth_client(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-client action.
		$client_id = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : '';
		check_admin_referer( self::ACTION_REVOKE_OAUTH . '_' . $client_id );

		if ( '' !== $client_id && class_exists( 'KarMCP_Gateway_Credential' ) ) {
			// Run before revoke_client() below so the gateway teardown observes the
			// still-live token count. (Identity itself survives revoke_client(), which
			// only deletes token rows, not the client registration.)
			KarMCP_Gateway_Credential::handle_client_revoked( $client_id );
		}

		if ( '' !== $client_id && class_exists( 'KarMCP_OAuth_Store' ) ) {
			KarMCP_OAuth_Store::revoke_client( $client_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection&oauth_revoked=1#karmcp-conn-main' ) );
		exit;
	}

	/**
	 * User meta flag recording that the current user has dismissed the notice
	 * announcing the rewritten (v2) prompt library. Per-user, not per-site, so
	 * one administrator dismissing it does not hide it from the others.
	 *
	 * Suffixed with the library generation: a future rewrite bumps the key and
	 * the notice surfaces again rather than staying permanently dismissed.
	 *
	 * @since 3.2.0
	 */
	const META_PROMPTS_NOTICE_DISMISSED = 'karmcp_prompts_v2_notice_dismissed';

	/**
	 * Whether the current user has dismissed the rewritten-prompts notice.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public static function prompts_notice_dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::META_PROMPTS_NOTICE_DISMISSED, true );
	}

	/**
	 * Nonce-protected URL that dismisses the rewritten-prompts notice.
	 *
	 * @since 3.2.0
	 * @return string
	 */
	public static function prompts_notice_dismiss_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS_PROMPTS_NOTICE ),
			self::ACTION_DISMISS_PROMPTS_NOTICE
		);
	}

	/**
	 * Persist the dismissal, then bounce back to the Prompts screen.
	 *
	 * @since 3.2.0
	 */
	public function handle_dismiss_prompts_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_DISMISS_PROMPTS_NOTICE );

		update_user_meta( get_current_user_id(), self::META_PROMPTS_NOTICE_DISMISSED, '1' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-prompts' ) );
		exit;
	}

	/**
	 * Option that records which version of the default disabled-tools seeding
	 * has been applied. Stored as an integer-ish string: legacy '1' = the
	 * original Pro-widget defaults; '2' adds the SEO/A11y Pro MCP tools.
	 */
	const OPTION_DEFAULTS_APPLIED = 'karmcp_defaults_applied';

	/**
	 * Current defaults-seeding version. Bump when a new batch of slugs should
	 * ship disabled-by-default; add a guarded step in
	 * maybe_apply_default_disabled_tools() for the new version.
	 *
	 * @since 1.8.0
	 */
	const DEFAULTS_VERSION = 38;

	/**
	 * SEO/A11y Pro MCP tool slugs that ship disabled-by-default (v2 defaults).
	 *
	 * @since 1.8.0
	 *
	 * @return string[]
	 */
	public static function seo_a11y_tool_slugs(): array {
		return array(
			'karmcp/audit-page-seo',
			'karmcp/extract-keywords-from-content',
			'karmcp/generate-meta-tags',
			'karmcp/generate-schema-markup',
			'karmcp/set-social-image',
			'karmcp/audit-page-a11y',
			'karmcp/fix-color-contrast',
			'karmcp/add-alt-text-from-context',
		);
	}

	/**
	 * Widget Builder Pro MCP tool slugs that ship disabled-by-default (v3).
	 *
	 * @since 1.9.0
	 *
	 * @return string[]
	 */
	public static function widget_builder_tool_slugs(): array {
		return array(
			'karmcp/list-control-types',
			'karmcp/validate-widget-spec',
			'karmcp/create-custom-widget',
			'karmcp/update-custom-widget',
			'karmcp/get-custom-widget',
			'karmcp/list-custom-widgets',
			'karmcp/set-widget-status',
			'karmcp/delete-custom-widget',
		);
	}

	/**
	 * Block Builder Pro MCP tool slugs that ship disabled-by-default (v24).
	 *
	 * @since 3.7.0
	 *
	 * @return string[]
	 */
	public static function block_tool_slugs(): array {
		return array(
			'karmcp/list-block-control-types',
			'karmcp/validate-block-spec',
			'karmcp/create-custom-block',
			'karmcp/update-custom-block',
			'karmcp/get-custom-block',
			'karmcp/list-custom-blocks',
			'karmcp/set-block-status',
			'karmcp/delete-custom-block',
		);
	}

	/**
	 * Which internal Sandbox pillar to render. The Sandbox parent page
	 * (?page=karmcp-widgets) is a 3-card overview; each pillar's full
	 * management UI lives at ?page=karmcp-widgets&view=<pillar> — a route
	 * deliberately not exposed as its own wp-admin menu entry.
	 *
	 * @since 3.7.0
	 *
	 * @return string One of 'overview' | 'blocks' | 'widgets' | 'snippets'.
	 */
	public static function sandbox_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';
		return in_array( $view, array( 'overview', 'blocks', 'widgets', 'snippets' ), true ) ? $view : 'overview';
	}

	/**
	 * The PHP Snippet (Sandbox) tool slugs. Free, but powerful, so they ship
	 * disabled-by-default and the admin opts in on the Tools tab.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function php_snippet_tool_slugs(): array {
		return array(
			'karmcp/validate-php-snippet',
			'karmcp/create-php-snippet',
			'karmcp/update-php-snippet',
			'karmcp/get-php-snippet',
			'karmcp/list-php-snippets',
			'karmcp/delete-php-snippet',
		);
	}

	/**
	 * Themer PHP-template tool slugs. The whole feature is gated behind a master
	 * switch (off by default), and even once enabled these 5 tools ship
	 * disabled-by-default like the PHP Snippets — the admin opts in on the Tools tab.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	public static function themer_php_tool_slugs(): array {
		return array(
			'karmcp/create-theme-php-template',
			'karmcp/list-theme-php-templates',
			'karmcp/get-theme-php-template',
			'karmcp/update-theme-php-template',
			'karmcp/delete-theme-php-template',
		);
	}

	/**
	 * The 9 Plugins & Themes mutation tool slugs. Powerful (install/delete/
	 * activate), so they ship disabled-by-default; reads stay enabled. The admin
	 * opts in on the Tools tab.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function package_write_tool_slugs(): array {
		return array(
			'karmcp/install-plugin',
			'karmcp/activate-plugin',
			'karmcp/deactivate-plugin',
			'karmcp/update-plugin',
			'karmcp/delete-plugin',
			'karmcp/install-theme',
			'karmcp/switch-theme',
			'karmcp/update-theme',
			'karmcp/delete-theme',
		);
	}

	/**
	 * Media tool slugs that ship disabled-by-default. Only delete-media (the
	 * destructive, effectively-permanent op); get-media / update-media stay on.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function media_write_tool_slugs(): array {
		return array( 'karmcp/delete-media' );
	}

	/**
	 * Users mutation tool slugs that ship disabled-by-default. The reads
	 * (list-users/get-user) stay enabled. The admin opts in on the Tools tab.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function user_write_tool_slugs(): array {
		return array( 'karmcp/create-user', 'karmcp/update-user' );
	}

	/**
	 * Filesystem mutation tool slugs that ship disabled-by-default. The reads
	 * (read-file/list-directory/search-files) stay enabled.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function filesystem_write_tool_slugs(): array {
		return array( 'karmcp/write-file', 'karmcp/edit-file', 'karmcp/delete-file' );
	}

	/**
	 * Database mutation tool slugs that ship disabled-by-default. The reads
	 * (list-tables/describe-table/query) stay enabled.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function database_write_tool_slugs(): array {
		return array( 'karmcp/insert-row', 'karmcp/update-rows', 'karmcp/delete-rows' );
	}

	/**
	 * Redirect Manager write tool slugs that ship disabled-by-default. The reads
	 * (list-redirects/find-broken-links) stay enabled. The admin opts in on the
	 * Tools tab.
	 *
	 * @since 3.11.0
	 * @return string[]
	 */
	public static function redirect_tool_slugs(): array {
		return array( 'karmcp/create-redirect', 'karmcp/update-redirect', 'karmcp/delete-redirect' );
	}

	/**
	 * The Backup/Migrate/Sync MCP tool slugs (drift-guard exclusion — the group
	 * only registers when the Migrate module is active + premium). The two
	 * destructive tools (migrate-site/sync-to-live) ship disabled-by-default.
	 *
	 * @since 3.15.0
	 * @return string[]
	 */
	public static function migrate_tool_slugs(): array {
		return array(
			'karmcp/create-backup',
			'karmcp/list-backups',
			'karmcp/migrate-site',
			'karmcp/sync-to-live',
			'karmcp/list-syncable-changes',
			'karmcp/sync-content-item',
			'karmcp/discard-sync-change',
		);
	}

	/**
	 * The ACF dispatcher tool slugs. The domain registers as two dispatcher
	 * tools (acf-read enabled by default, acf-write disabled by default); the
	 * 15 operations live behind them. Both slugs are excluded from the drift
	 * guard since the domain only registers when ACF (free or Pro) is active.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	public static function acf_tool_slugs(): array {
		return array(
			'karmcp/acf-read',
			'karmcp/acf-write',
		);
	}

	/**
	 * The WooCommerce integration's dispatcher slugs (drift-guard exclusion).
	 *
	 * @since 3.4.2
	 * @return string[]
	 */
	public static function woo_tool_slugs(): array {
		return array(
			'karmcp/woo-read',
			'karmcp/woo-write',
		);
	}

	/**
	 * The Meta Box dispatcher tool slugs. The domain registers as two dispatcher
	 * tools (metabox-read enabled by default, metabox-write disabled by default);
	 * the operations live behind them. Both slugs are excluded from the drift
	 * guard since the domain only registers when Meta Box is active.
	 *
	 * @since 3.4.2
	 * @return string[]
	 */
	public static function metabox_tool_slugs(): array {
		return array(
			'karmcp/metabox-read',
			'karmcp/metabox-write',
		);
	}

	/**
	 * The pre-release per-operation ACF slugs (the earlier 15-tool layout).
	 * Kept only so the defaults step can strip them from the stored option on
	 * sites that seeded them before the 2-dispatcher consolidation.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	public static function legacy_acf_operation_slugs(): array {
		return array(
			'karmcp/list-acf-field-groups',
			'karmcp/get-acf-field-group',
			'karmcp/list-acf-options-pages',
			'karmcp/get-acf-fields',
			'karmcp/update-acf-fields',
			'karmcp/create-acf-field-group',
			'karmcp/update-acf-field-group',
			'karmcp/list-acf-post-types',
			'karmcp/get-acf-post-type',
			'karmcp/create-acf-post-type',
			'karmcp/update-acf-post-type',
			'karmcp/list-acf-taxonomies',
			'karmcp/get-acf-taxonomy',
			'karmcp/create-acf-taxonomy',
			'karmcp/update-acf-taxonomy',
		);
	}

	/**
	 * Seeds default disabled-tools on install/upgrade so new Pro tool batches
	 * ship off-by-default (keeping sites under client tool caps), then records
	 * the applied version. Each version step adds ONLY its newly-introduced
	 * slugs, so prior user enable/disable choices are preserved (union merge).
	 *
	 * @since 1.6.0
	 */
	/**
	 * The Themes-domain dispatcher slugs (Active Theme + framework packs). Both
	 * write dispatchers ship disabled-by-default; the per-framework packs are
	 * env-gated (register only when that framework is active). Excluded from the
	 * F-019 drift guard for that reason.
	 *
	 * @since 3.4.0
	 * @return string[]
	 */
	public static function theme_tool_slugs(): array {
		return array(
			'karmcp/theme-read',
			'karmcp/theme-write',
			'karmcp/astra-read',
			'karmcp/astra-write',
			'karmcp/spectra-read',
			'karmcp/spectra-write',
			'karmcp/kadence-read',
			'karmcp/kadence-write',
			'karmcp/kadence-blocks-read',
			'karmcp/kadence-blocks-write',
			'karmcp/generatepress-read',
			'karmcp/generatepress-write',
			'karmcp/generateblocks-read',
			'karmcp/generateblocks-write',
			'karmcp/blocksy-blocks-read',
			'karmcp/blocksy-blocks-write',
			'karmcp/blocksy-extensions-read',
			'karmcp/blocksy-extensions-write',
		);
	}

	public function maybe_apply_default_disabled_tools(): void {
		$applied = (int) get_option( self::OPTION_DEFAULTS_APPLIED, 0 );
		if ( $applied >= self::DEFAULTS_VERSION ) {
			return;
		}

		$existing = get_option( self::OPTION_DISABLED_TOOLS, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$add = array();

		// v1 — every Pro-badged tool. Only seeded on a truly fresh install
		// (applied < 1); re-running on an upgrade would clobber user re-enables.
		if ( $applied < 1 ) {
			foreach ( $this->get_all_tools() as $category ) {
				foreach ( $category['tools'] as $slug => $tool ) {
					if ( in_array( 'pro', $tool['badges'], true ) || in_array( 'elementor-pro', $tool['badges'], true ) ) {
						$add[] = $slug;
					}
				}
			}
		}

		// v2 — SEO/A11y Pro MCP tools ship disabled-by-default. Adding only the
		// new slugs means an existing user's other choices survive the upgrade.
		if ( $applied < 2 ) {
			$add = array_merge( $add, self::seo_a11y_tool_slugs() );
		}

		// v3 — Widget Builder Pro MCP tools ship disabled-by-default.
		if ( $applied < 3 ) {
			$add = array_merge( $add, self::widget_builder_tool_slugs() );
		}

		// v4 — PHP Snippet (Sandbox) MCP tools ship disabled-by-default.
		if ( $applied < 4 ) {
			$add = array_merge( $add, self::php_snippet_tool_slugs() );
		}

		// v5 — Widget consolidation (3.0.0). The 62 per-widget Pro slugs seeded
		// disabled in v1 no longer exist; strip them so they don't linger in the
		// stored option. add-pro-widget is a single tool, left ENABLED by default
		// (it only registers when Elementor Pro is active anyway).
		if ( $applied < 5 ) {
			$existing = array_values( array_diff( $existing, self::removed_widget_tool_slugs() ) );
		}

		// v6 — Plugins & Themes mutation tools ship disabled-by-default
		// (powerful: install/activate/deactivate/update/delete). Reads stay on.
		if ( $applied < 6 ) {
			$add = array_merge( $add, self::package_write_tool_slugs() );
		}

		// v7 — delete-media ships disabled-by-default (permanent deletion).
		if ( $applied < 7 ) {
			$add = array_merge( $add, self::media_write_tool_slugs() );
		}

		// v8 — Users mutation tools ship disabled-by-default (account changes).
		if ( $applied < 8 ) {
			$add = array_merge( $add, self::user_write_tool_slugs() );
		}

		// v9 — Filesystem mutation tools ship disabled-by-default (write/edit/delete).
		if ( $applied < 9 ) {
			$add = array_merge( $add, self::filesystem_write_tool_slugs() );
		}

		// v10 — Database mutation tools ship disabled-by-default (insert/update/delete).
		if ( $applied < 10 ) {
			$add = array_merge( $add, self::database_write_tool_slugs() );
		}

		// v11 — Themer PHP-template tools ship disabled-by-default (raw PHP; gated
		// behind the master switch too). The admin opts in on the Tools tab.
		if ( $applied < 11 ) {
			$add = array_merge( $add, self::themer_php_tool_slugs() );
		}

		// v14 — ACF is exposed as two dispatcher tools (acf-read / acf-write).
		// The write dispatcher ships disabled-by-default; the read dispatcher
		// stays on. Also strip any pre-release per-operation ACF slugs left in
		// the stored option from the earlier 15-tool layout. (Supersedes the
		// v12/v13 per-tool ACF seeding, which targeted slugs that no longer
		// exist as individual tools.)
		if ( $applied < 14 ) {
			$existing = array_values( array_diff( $existing, self::legacy_acf_operation_slugs() ) );
			$add[]    = 'karmcp/acf-write';
		}

		// v15 — set-social-image (Pro SEO) ships disabled-by-default, consistent
		// with the rest of the SEO/A11y toolkit.
		if ( $applied < 15 ) {
			$add[] = 'karmcp/set-social-image';
		}

		// v16 — Themes-domain write dispatchers ship disabled-by-default (theme_mod
		// writes + child-theme creation; per-framework settings writes). Reads on.
		if ( $applied < 16 ) {
			$add[] = 'karmcp/theme-write';
			$add[] = 'karmcp/astra-write';
		}

		// v17 — Spectra Blocks write dispatcher (add-block) ships disabled-by-default.
		if ( $applied < 17 ) {
			$add[] = 'karmcp/spectra-write';
		}

		// v18 — WP-CLI tools (run + background jobs) ship disabled-by-default
		// (command execution surface). All four are off until the admin opts in.
		if ( $applied < 18 ) {
			$add = array_merge( $add, KarMCP_WPCLI_Abilities::slugs() );
		}

		// v19 — WooCommerce + Meta Box write dispatchers ship disabled-by-default.
		// Woo write is the money/PII surface; Meta Box write edits custom-field
		// values. Both read dispatchers stay enabled.
		if ( $applied < 19 ) {
			$add[] = 'karmcp/woo-write';
			$add[] = 'karmcp/metabox-write';
		}

		// v20 — Forms domain writes ship disabled-by-default (all six plugins).
		// Reads stay enabled; the five Pro reads render locked on free builds via
		// the get_all_tools() Pro-lock post-process.
		if ( $applied < 20 ) {
			$add[] = 'karmcp/cf7-write';
			$add[] = 'karmcp/wpforms-write';
			$add[] = 'karmcp/gravityforms-write';
			$add[] = 'karmcp/fluentforms-write';
			$add[] = 'karmcp/ninjaforms-write';
			$add[] = 'karmcp/formidable-write';
		}

		// v21 — MetForm + SureForms writes disabled-by-default.
		if ( $applied < 21 ) {
			$add[] = 'karmcp/metform-write';
			$add[] = 'karmcp/sureforms-write';
		}

		// v22 — SEO-plugin writes disabled-by-default (all 7 plugins).
		if ( $applied < 22 ) {
			$add[] = 'karmcp/slimseo-write';
			$add[] = 'karmcp/yoast-write';
			$add[] = 'karmcp/rankmath-write';
			$add[] = 'karmcp/aioseo-write';
			$add[] = 'karmcp/seopress-write';
			$add[] = 'karmcp/seoframework-write';
			$add[] = 'karmcp/surerank-write';
		}

		// v23 — Elementor addon domain. Only UAE has a write tool; Essential and
		// Premium Addons are discovery-only (placement stays on add-free-widget),
		// so there is nothing of theirs to disable.
		if ( $applied < 23 ) {
			$add[] = 'karmcp/uae-write';
		}

		// v24 — Block Builder Pro MCP tools ship disabled-by-default (author executable
		// block code; same posture as the Widget Builder + PHP Snippets).
		if ( $applied < 24 ) {
			$add = array_merge( $add, self::block_tool_slugs() );
		}

		// v25 was Project Memory, a feature this build does not have.

		// v26 — Forminator write (delete-entry) disabled-by-default.
		if ( $applied < 26 ) {
			$add[] = 'karmcp/forminator-write';
		}

		// v27 — Kadence theme + Kadence Blocks write dispatchers disabled-by-default.
		if ( $applied < 27 ) {
			$add[] = 'karmcp/kadence-write';
			$add[] = 'karmcp/kadence-blocks-write';
		}

		// v28 — Elementor v4 Global Class write tools disabled-by-default.
		if ( $applied < 28 ) {
			$add[] = 'karmcp/create-global-class';
			$add[] = 'karmcp/update-global-class';
			$add[] = 'karmcp/delete-global-class';
		}

		// v29 — reorder-global-classes write tool disabled-by-default.
		if ( $applied < 29 ) {
			$add[] = 'karmcp/reorder-global-classes';
		}

		// v30 — GeneratePress + GenerateBlocks write dispatchers disabled-by-default.
		if ( $applied < 30 ) {
			$add[] = 'karmcp/generatepress-write';
			$add[] = 'karmcp/generateblocks-write';
		}

		// v31 — Blocksy write dispatchers disabled-by-default.
		if ( $applied < 31 ) {
			$add[] = 'karmcp/blocksy-blocks-write';
			$add[] = 'karmcp/blocksy-extensions-write';
		}

		// v32 — Redirect Manager write tools ship disabled-by-default (create/
		// update/delete a redirect). The reads (list-redirects/find-broken-links)
		// stay enabled. The admin opts in on the Tools tab.
		if ( $applied < 32 ) {
			$add = array_merge( $add, self::redirect_tool_slugs() );
		}

		// v33 — Backup/Migrate/Sync destructive MCP tools ship disabled-by-default
		// (migrate-site/sync-to-live push to and overwrite a live target). The
		// reads (create-backup/list-backups) stay enabled.
		if ( $applied < 33 ) {
			$add[] = 'karmcp/migrate-site';
			$add[] = 'karmcp/sync-to-live';
		}

		// v34 — the content-sync push tool overwrites an item on the live site, so
		// it ships disabled-by-default. The list + discard reads stay enabled.
		if ( $applied < 34 ) {
			$add[] = 'karmcp/sync-content-item';
		}

		// v35 — duplicate-post copies protected meta from the source post, and the
		// multilingual writes assign languages and rewrite translation groups.
		// Both are safe in intent and hard to undo by hand, so the admin opts in.
		// The multilingual reads stay enabled.
		if ( $applied < 35 ) {
			$add[] = 'karmcp/duplicate-post';
			$add[] = 'karmcp/polylang-write';
			$add[] = 'karmcp/wpml-write';
			// build-site changes the reading settings and the menu assignment,
			// which are site-wide. Its own dry-run default is not enough on its
			// own: the admin decides whether the tool exists at all.
			$add[] = 'karmcp/build-site';
		}

		// v36 — clear-login-lockout decides who may sign in, so it opts in like
		// every other write. list-login-lockouts stays enabled: it is read-only,
		// and a lockout nobody can see is a support call. Both only register at
		// all when the Login Guard module is on, which itself ships off.
		if ( $applied < 36 ) {
			$add[] = 'karmcp/clear-login-lockout';
		}

		// v37 — harden-site changes how the site answers every visitor. Its
		// dry-run default is not enough on its own: the admin decides whether an
		// agent can reach for it at all.
		if ( $applied < 37 ) {
			$add[] = 'karmcp/harden-site';
		}

		// v38 — update-core replaces the code serving the request, and
		// resume-plugin re-enables something the handler switched off for a
		// reason. The two reads beside them stay on: a fatal log nobody can
		// reach is a fatal log that helps nobody.
		if ( $applied < 38 ) {
			$add[] = 'karmcp/update-core';
			$add[] = 'karmcp/resume-plugin';
		}

		$merged = array_values( array_unique( array_merge( $existing, $add ) ) );
		update_option( self::OPTION_DISABLED_TOOLS, $merged );
		update_option( self::OPTION_DEFAULTS_APPLIED, (string) self::DEFAULTS_VERSION );
	}

	/**
	 * The per-widget convenience tool slugs removed in 3.0.0 (widget
	 * consolidation). Used by the v5 defaults step to clear orphaned disabled
	 * entries from the stored option.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function removed_widget_tool_slugs(): array {
		return array(
			'karmcp/add-widget',
			'karmcp/add-heading', 'karmcp/add-text-editor', 'karmcp/add-image',
			'karmcp/add-button', 'karmcp/add-video', 'karmcp/add-icon',
			'karmcp/add-spacer', 'karmcp/add-divider', 'karmcp/add-icon-box',
			'karmcp/add-accordion', 'karmcp/add-alert', 'karmcp/add-counter',
			'karmcp/add-google-maps', 'karmcp/add-icon-list', 'karmcp/add-image-box',
			'karmcp/add-image-carousel', 'karmcp/add-progress', 'karmcp/add-social-icons',
			'karmcp/add-star-rating', 'karmcp/add-tabs', 'karmcp/add-testimonial',
			'karmcp/add-toggle', 'karmcp/add-html', 'karmcp/add-menu-anchor',
			'karmcp/add-shortcode', 'karmcp/add-rating', 'karmcp/add-text-path',
			'karmcp/add-form', 'karmcp/add-posts-grid', 'karmcp/add-countdown',
			'karmcp/add-price-table', 'karmcp/add-flip-box', 'karmcp/add-animated-headline',
			'karmcp/add-call-to-action', 'karmcp/add-slides', 'karmcp/add-testimonial-carousel',
			'karmcp/add-price-list', 'karmcp/add-gallery', 'karmcp/add-share-buttons',
			'karmcp/add-table-of-contents', 'karmcp/add-blockquote', 'karmcp/add-lottie',
			'karmcp/add-hotspot', 'karmcp/add-nav-menu', 'karmcp/add-loop-grid',
			'karmcp/add-loop-carousel', 'karmcp/add-media-carousel', 'karmcp/add-nested-tabs',
			'karmcp/add-nested-accordion', 'karmcp/add-portfolio', 'karmcp/add-author-box',
			'karmcp/add-login', 'karmcp/add-code-highlight', 'karmcp/add-reviews',
			'karmcp/add-off-canvas', 'karmcp/add-progress-tracker', 'karmcp/add-search',
			'karmcp/add-wc-products', 'karmcp/add-wc-add-to-cart', 'karmcp/add-wc-cart',
			'karmcp/add-wc-checkout', 'karmcp/add-wc-menu-cart',
		);
	}

	/**
	 * The KarMCP mark as a base64 data URI for the top-level menu.
	 *
	 * The brand mark is a "K" whose arms end in two nodes — the initial and the
	 * connection topology MCP describes, in one shape that still reads at 20px.
	 *
	 * Deliberately monochrome: WordPress recolours a data-URI menu icon to match
	 * the admin colour scheme, so any colour baked in here is overridden, and a
	 * filled background (see assets/img/karmcp-tile.svg) would recolour into an
	 * illegible solid block. Use the tile only where colour is preserved.
	 *
	 * @since 1.0.0
	 *
	 * @return string `data:image/svg+xml;base64,…`
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<g stroke="black" stroke-width="2.6" stroke-linecap="round" fill="none">'
			. '<path d="M5.5 3.5v17"/><path d="M5.7 12.6 14.6 5.4"/><path d="M5.7 11.4 14.6 18.6"/>'
			. '</g>'
			. '<circle cx="17.6" cy="4.2" r="2.7" fill="black"/>'
			. '<circle cx="17.6" cy="19.8" r="2.7" fill="black"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WordPress requires menu icons in this exact form.
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		$this->hook_suffixes[] = add_menu_page(
			__( 'KarMCP', 'karmcp' ),
			__( 'KarMCP', 'karmcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			self::menu_icon(),
			58
		);

		foreach ( $this->get_submenus() as $slug => $label ) {
			$menu_title = $label;
			$this->hook_suffixes[] = add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$menu_title,
				'manage_options',
				$slug,
				array( $this, 'render_page' )
			);
		}

		// Changelog is surfaced as an app-bar button in the header, not the
		// sidebar. We deliberately do NOT remove_submenu_page() it: that drops
		// the page from $submenu, which breaks both user_can_access_admin_page()
		// (parent no longer resolves) and the render hook (admin.php recomputes
		// the page hook to a name with no attached callback → "Cannot load").
		// Instead the sidebar <li> is hidden with CSS in print_menu_icon_style(),
		// so the page stays a normal, fully-renderable submenu reachable by URL.
	}

	/**
	 * Print a tiny inline style on every admin page that constrains our menu
	 * icon to native-dashicon dimensions.
	 *
	 * WordPress renders a PNG menu icon at its natural size, which makes our
	 * 64×64 brand icon overflow the 34px-tall sidebar row. The native dashicon
	 * box is 20×20 with a small vertical inset — replicating that here keeps
	 * the icon visually aligned with Posts/Pages/etc. We inject globally
	 * (not via the KarMCP page enqueue) because the WP sidebar shows on every
	 * admin screen, not just ours.
	 *
	 * @since 1.7.2
	 */
	public function print_menu_icon_style(): void {
		echo '<style>'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-menu-image img{'
			. 'width:20px;height:20px;padding:7px 0 0;object-fit:contain;opacity:.95;'
			. '}'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ':hover .wp-menu-image img,'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . '.current .wp-menu-image img,'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . '.wp-has-current-submenu .wp-menu-image img{'
			. 'opacity:1;'
			. '}'
			// Changelog lives in the header app-bar, not the sidebar. It stays a
			// real submenu (so it renders + is URL-accessible); we only hide its
			// sidebar row. :has() hides the whole <li>; the anchor rule is a
			// fallback for browsers without :has() (collapses the row to 0).
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-changelog"]),'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-changelog"],'
			// History also lives in the app-bar (next to Changelog), not the sidebar.
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-history"]),'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-history"]{'
			. 'display:none !important;'
			. '}'
			. '</style>';
	}

	/**
	 * Register the settings with the WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	/**
	 * Handles the two Security-tab forms: "Scan now" and "Save hardening".
	 *
	 * Both write, so both carry a nonce and a capability check. The hardening
	 * form submits the full set of checkboxes, so the difference against what is
	 * currently applied is what gets applied and reverted — which makes
	 * unchecking a box a real revert rather than a no-op.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public function handle_security_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['karmcp_security_scan'] ) ) {
			check_admin_referer( 'karmcp_security_scan' );
			if ( class_exists( 'KarMCP_Security_Monitor' ) ) {
				KarMCP_Security_Monitor::run_scheduled();
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-security' ) );
			exit;
		}

		if ( isset( $_POST['karmcp_vuln_update'], $_POST['karmcp_vuln_plugin'] ) ) {
			check_admin_referer( 'karmcp_vuln_update' );
			$karmcp_target = sanitize_text_field( wp_unslash( $_POST['karmcp_vuln_plugin'] ) );
			$karmcp_msg    = '';

			if ( class_exists( 'KarMCP_Vuln_Remediation' ) ) {
				$karmcp_done = KarMCP_Vuln_Remediation::update( $karmcp_target );
				$karmcp_msg  = is_wp_error( $karmcp_done ) ? $karmcp_done->get_error_message() : 'ok';
			}

			wp_safe_redirect(
				add_query_arg(
					'karmcp_updated',
					rawurlencode( $karmcp_msg ),
					admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-security' )
				)
			);
			exit;
		}

		if ( isset( $_POST['karmcp_vuln_refresh'] ) ) {
			check_admin_referer( 'karmcp_vuln_refresh' );
			if ( class_exists( 'KarMCP_Vuln_Store' ) ) {
				// The result is read back off the stored state by the view, so a
				// failure here is not swallowed: refresh() records why it could
				// not update and leaves the previous rows exactly as they were.
				KarMCP_Vuln_Store::refresh();
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-security' ) );
			exit;
		}

		if ( isset( $_POST['karmcp_dropin_action'] ) ) {
			check_admin_referer( 'karmcp_security_dropin' );
			if ( class_exists( 'KarMCP_Fatal_Handler_Template' ) ) {
				$karmcp_action = sanitize_key( wp_unslash( $_POST['karmcp_dropin_action'] ) );

				$karmcp_cfg = (array) get_option( KarMCP_Fatal_Handler_Template::OPTION_CONFIG, array() );
				$karmcp_cfg['auto_pause'] = ! empty( $_POST['karmcp_fatal_auto_pause'] );
				$karmcp_cfg['strikes']    = 3;
				$karmcp_cfg['window']     = 600;
				$karmcp_cfg['protected']  = KarMCP_Fatal_Handler_Template::ALWAYS_PROTECTED;
				update_option( KarMCP_Fatal_Handler_Template::OPTION_CONFIG, $karmcp_cfg, false );

				if ( 'uninstall' === $karmcp_action ) {
					KarMCP_Fatal_Handler_Template::uninstall();
				} else {
					KarMCP_Fatal_Handler_Template::install();
				}
			}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-security' ) );
			exit;
		}

		if ( isset( $_POST['karmcp_security_harden'] ) ) {
			check_admin_referer( 'karmcp_security_harden' );
			if ( ! class_exists( 'KarMCP_Security_Hardening_Fixer' ) ) {
				return;
			}

			$wanted = isset( $_POST['karmcp_harden'] )
				? array_map( 'sanitize_key', (array) wp_unslash( $_POST['karmcp_harden'] ) )
				: array();

			$before  = KarMCP_Security_Hardening_Fixer::applied();
			$revert  = array_values( array_diff( $before, $wanted ) );

			KarMCP_Security_Hardening_Fixer::apply( $wanted );
			KarMCP_Security_Hardening_Fixer::revert( $revert );

			if ( $before !== KarMCP_Security_Hardening_Fixer::applied() && class_exists( 'KarMCP_Change_Recorder' ) ) {
				KarMCP_Change_Recorder::record_options(
					array( KarMCP_Security_Hardening_Fixer::OPTION_APPLIED => $before ),
					__( 'Hardening changed from the Security tab', 'karmcp' ),
					__( 'Site hardening', 'karmcp' ),
					'settings',
					'harden-site'
				);
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-security' ) );
			exit;
		}
	}

	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DISABLED_TOOLS,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_disabled_tools' ),
			)
		);

		// Compact tool mode (dispatcher) — Tools tab. OFF by default; surfaces 3
		// meta-tools (list-tools / get-tool-schema / call-tool) instead of every
		// individual tool for clients that cap the tool count. Registered under the
		// Tools form group so its toggle lives alongside the per-tool grid.
		register_setting(
			self::SETTINGS_GROUP,
			KarMCP_Plugin::OPTION_DISPATCHER_MODE,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Themer PHP Templates master switch (Tools tab). Off by default — the
		// feature lets AI author raw PHP region templates, so the admin opts in.
		register_setting(
			self::SETTINGS_GROUP,
			KarMCP_Themer_PHP::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Content mirror auto-export (Tools tab). Off by default — when on, saving an
		// Elementor page/template also writes its JSON to uploads/karmcp-content-mirror/
		// for external version control. The MCP export/restore tools work regardless.
		register_setting(
			self::SETTINGS_GROUP,
			KarMCP_Content_Mirror::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// "Activate Abilities API for KarMCP" server gate (Connection tab). On by
		// default; an absent checkbox on submit sanitizes to '0' (off).
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			KarMCP_Plugin::OPTION_SERVER_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// OAuth sign-in for MCP clients (Connection tab). No stored default — the
		// effective default is "on when HTTPS", enforced by
		// KarMCP_OAuth_Server (is_available). The form posts a hidden 0 +
		// checkbox 1 so an unchecked box saves '0'.
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			KarMCP_OAuth_Server::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// OpenAI-strict tool schemas (Connection tab). OFF by default — it's only
		// for OpenAI-compatible strict function-calling clients (CrewAI, etc.) and
		// would otherwise break Gemini/Antigravity. (GitHub #42)
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			'karmcp_strict_schemas',
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Server URL override (Connection tab). Empty = auto-detect from the REST
		// API. Set it when the site is served on a different URL than WordPress's
		// configured Site Address (e.g. staging with a pinned domain) so the
		// bundle / OAuth / configs use the reachable host. Accepts only http(s).
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			KarMCP_Site_Context::OPTION_BASE_URL,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						return '';
					}
					$value  = esc_url_raw( $value );
					$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
					if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
						return '';
					}
					return rtrim( $value, '/' );
				},
			)
		);

		// WP-CLI base command (Connection → 3rd Party Services) — the `wp` launcher
		// used for the shell / background-job path over HTTP (e.g. "wp" or
		// "php /path/to/wp-cli.phar"). Empty = in-process only (WP-CLI stdio).
		register_setting(
			self::SETTINGS_GROUP_SERVICES,
			'karmcp_wpcli_command',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
			)
		);

		// Stock-image provider API keys (Connection → 3rd Party Services sub-tab)
		// — power the stock-image tools (search-images / add-stock-image). All
		// three are free keys. Registered in their own group so that sub-tab's
		// form saves without touching the server-gate toggles. Keys are stored
		// encrypted at rest (KarMCP_Secret) and never rendered back to the
		// form: the field posts empty when unchanged (we keep the stored value),
		// a per-field "__clear" checkbox removes it, and a new value is encrypted.
		foreach ( array( KarMCP_Unsplash_Client::OPTION, KarMCP_Pexels_Client::OPTION, KarMCP_Pixabay_Client::OPTION ) as $karmcp_stock_option ) {
			register_setting(
				self::SETTINGS_GROUP_SERVICES,
				$karmcp_stock_option,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $value ) use ( $karmcp_stock_option ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings-group nonce before this runs.
						if ( ! empty( $_POST[ $karmcp_stock_option . '__clear' ] ) ) {
							return '';
						}
						$value = sanitize_text_field( (string) $value );
						if ( '' === $value ) {
							// Unchanged (masked) submit — keep the stored value.
							return (string) get_option( $karmcp_stock_option, '' );
						}
						// The Settings API can run this callback twice per save;
						// don't re-encrypt an already-encrypted token (would nest).
						if ( KarMCP_Secret::is_encrypted( $value ) ) {
							return $value;
						}
						return KarMCP_Secret::encrypt( $value );
					},
				)
			);
		}

		// Context page — the site-wide guidance + its on/off toggle.
		register_setting(
			self::SETTINGS_GROUP_CONTEXT,
			KarMCP_Site_Context::OPTION_CONTEXT,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					$value = sanitize_textarea_field( (string) $value );
					return mb_substr( $value, 0, KarMCP_Site_Context::MAX_CHARS );
				},
			)
		);
		register_setting(
			self::SETTINGS_GROUP_CONTEXT,
			KarMCP_Site_Context::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Modules tab — the active-modules list + each registered module's own
		// option keys (declared by the module's settings_fields()).
		register_setting(
			self::SETTINGS_GROUP_MODULES,
			KarMCP_Module::OPTION_ACTIVE,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => static function ( $value ) {
					$value = is_array( $value ) ? $value : array();
					return array_values( array_map( 'sanitize_key', $value ) );
				},
			)
		);
		if ( class_exists( 'KarMCP_Modules_Registry' ) ) {
			foreach ( KarMCP_Modules_Registry::instance()->all() as $karmcp_module ) {
				// Each module's keys live in the module's own group so its overlay
				// settings form saves independently of the active-modules toggles.
				$karmcp_group = $karmcp_module->settings_group();
				foreach ( $karmcp_module->settings_fields() as $karmcp_key => $karmcp_args ) {
					register_setting( $karmcp_group, $karmcp_key, $karmcp_args );
				}
			}
		}
	}

	/**
	 * Sanitize the disabled tools option value.
	 *
	 * The form submits an array of enabled tool slugs. We compute the
	 * disabled list as the difference between all known tools and the
	 * enabled ones submitted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The raw form input.
	 * @return string[] Sanitized array of disabled tool slugs.
	 */
	public function sanitize_disabled_tools( $input ): array {
		$all_tools = $this->get_all_tool_slugs();

		// Only when the Tools settings form is being submitted do we INVERT the
		// posted "enabled" checkboxes into a disabled list. We read the enabled
		// set straight from $_POST (not from $input) so this callback is
		// IDEMPOTENT: WordPress re-runs sanitize_option a second time via
		// add_option() the first time the option is created, and inverting
		// $input twice would zero the result (all -> none). It also keeps
		// programmatic update_option() calls (e.g. the default-disabled seeder)
		// from being inverted at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings nonce before sanitization runs.
		$is_settings_form = isset( $_POST['option_page'] )
			&& self::SETTINGS_GROUP === sanitize_text_field( wp_unslash( $_POST['option_page'] ) );

		if ( $is_settings_form ) {
			$enabled = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ self::OPTION_DISABLED_TOOLS ] ) && is_array( $_POST[ self::OPTION_DISABLED_TOOLS ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$enabled = array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::OPTION_DISABLED_TOOLS ] ) );
			}
			// Disabled = all tools minus the ones that were checked (enabled).
			return array_values( array_diff( $all_tools, $enabled ) );
		}

		// Any other context: $input is already the final disabled list (e.g. the
		// default-disabled seeder). Clean against the known slugs and return —
		// this is idempotent, so a second sanitize pass leaves it unchanged.
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_values( array_intersect( $all_tools, array_map( 'sanitize_text_field', $input ) ) );
	}

	/**
	 * Enqueue admin CSS on our settings page only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->hook_suffixes, true ) ) {
			return;
		}

		$css_path = KARMCP_DIR . 'assets/css/admin.css';
		$js_path  = KARMCP_DIR . 'assets/js/admin.js';

		// Some security software and hosts rename or quarantine .js files on
		// upload (admin.js -> admin.j_), which makes the script 404 and silently
		// breaks JS-driven features like the Connection-tab config generator. If
		// the asset is missing, warn the admin with an actionable fix instead of
		// failing silently. (GitHub #44)
		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_js_asset' ) );
		}

		// Use filemtime in dev (when WP_DEBUG is on) so iterating on CSS/JS doesn't get stuck
		// behind a cached file under the same plugin version. Falls back to KARMCP_VERSION.
		$css_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $css_path ) ) ? filemtime( $css_path ) : KARMCP_VERSION;
		$js_ver  = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $js_path ) ) ? filemtime( $js_path ) : KARMCP_VERSION;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'elementor-mcp-admin',
				KARMCP_URL . 'assets/css/admin.css',
				array(),
				$css_ver
			);
		}

		// No script on disk -> nothing to enqueue or localize (the notice above
		// tells the admin how to fix it).
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'elementor-mcp-admin',
			KARMCP_URL . 'assets/js/admin.js',
			array(),
			$js_ver,
			true
		);

		// Sandbox cloud-backup button state machine (no-op unless the page
		// renders .karmcp-sb-cloud clusters).
		$sb_js = KARMCP_DIR . 'assets/js/sandbox-cloud.js';
		if ( file_exists( $sb_js ) ) {
			wp_enqueue_script( 'karmcp-sandbox-cloud', KARMCP_URL . 'assets/js/sandbox-cloud.js', array(), (string) filemtime( $sb_js ), true );
		}

		// Cloud Library: lazy list + import of the workspace's cloud artifacts
		// (no-op unless the page renders a .karmcp-cloud-lib panel).
		$cl_js = KARMCP_DIR . 'assets/js/cloud-library.js';
		if ( file_exists( $cl_js ) ) {
			wp_enqueue_script( 'karmcp-cloud-library', KARMCP_URL . 'assets/js/cloud-library.js', array(), (string) filemtime( $cl_js ), true );
		}

		wp_localize_script(
			'elementor-mcp-admin',
			'karmcpToolsAdmin',
			array(
				'copied'      => __( 'Copied!', 'karmcp' ),
				'copy'        => __( 'Copy', 'karmcp' ),
				'download'    => __( 'Download', 'karmcp' ),
				'mcpEndpoint' => class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::mcp_endpoint() : rest_url( 'mcp/karmcp-server' ),
				'oauthEnabled' => class_exists( 'KarMCP_OAuth_Server' ) && KarMCP_OAuth_Server::is_enabled(),
				'oauthSignin'  => __( 'The next time your AI client connects, your browser opens so you can authorize it. Approve to finish connecting.', 'karmcp' ),
				/* translators: %s: client label */
				'genFirst'     => __( 'Generate your credentials above, the config for %s then appears here.', 'karmcp' ),
				'siteUrl'     => class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::public_base_url() : site_url(),
				'restMeUrl'   => rest_url( 'wp/v2/users/me' ),
				// Only the filename — never the absolute server path. The proxy runs
				// on the CLIENT machine, so the server path is both useless to the
				// user and a needless path disclosure (F-020). The UI points users at
				// the npx runner or their own local copy of the proxy.
				'proxyPath'   => 'mcp-proxy.mjs',
				// Connection auth self-test (#41).
				'authTesting' => __( 'Testing…', 'karmcp' ),
				'authOk'      => __( '✓ Authentication works, your AI client should connect successfully.', 'karmcp' ),
				'authFail'    => __( '✗ Authentication failed (HTTP %d). If the credentials are correct, your server is stripping the Authorization header, see the fix below.', 'karmcp' ),
				'authError'   => __( 'Could not reach the REST API to test. Check the site URL and that the REST API is enabled.', 'karmcp' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'createPwNonce' => wp_create_nonce( 'karmcp_create_app_password' ),
				'trackPromptNonce' => wp_create_nonce( 'karmcp_track_prompt_copy' ),
				'generating'    => __( 'Generating…', 'karmcp' ),
				'pwCreated'     => __( 'Application password created, save it below, it is shown only once.', 'karmcp' ),
				'syncing'       => __( 'Syncing…', 'karmcp' ),
				// Brand Kits.
				'applying'      => __( 'Applying…', 'karmcp' ),
				'restoring'     => __( 'Restoring…', 'karmcp' ),
				/* translators: %s: brand kit title */
				'applyKitTitle' => __( 'Apply "%s" brand kit?', 'karmcp' ),
				/* translators: %s: brand kit title */
				'kitApplied'    => __( '%s applied.', 'karmcp' ),
				'restoreConfirm'     => __( 'Restore global colors and typography from this backup?', 'karmcp' ),
				'viewSite'           => __( 'View site →', 'karmcp' ),
				// Connection-tab client picker + .mcpb bundle.
				'connectionClients'  => self::connection_clients(),
				'mcpbNonce'          => wp_create_nonce( self::NONCE_DOWNLOAD_MCPB ),
				'adminPostUrl'       => admin_url( 'admin-post.php' ),
				'siteContextBase'      => KarMCP_Site_Context::default_base(),
				'siteContextDelimiter' => KarMCP_Site_Context::DELIMITER,
			)
		);

		// Modules tab: the bulk-optimizer progress UI.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( isset( $_GET['page'] ) && ( self::PAGE_SLUG . '-modules' ) === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			$bulk_path = KARMCP_DIR . 'assets/js/modules-bulk.js';
			if ( file_exists( $bulk_path ) && class_exists( 'KarMCP_Bulk_Optimizer' ) ) {
				$bulk_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? filemtime( $bulk_path ) : KARMCP_VERSION;
				wp_enqueue_script( 'karmcp-modules-bulk', KARMCP_URL . 'assets/js/modules-bulk.js', array(), $bulk_ver, true );
				wp_localize_script(
					'karmcp-modules-bulk',
					'karmcpToolsModules',
					array(
						'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
						'nonce'         => wp_create_nonce( KarMCP_Bulk_Optimizer::NONCE ),
						'batchAction'   => KarMCP_Bulk_Optimizer::ACTION_BATCH,
						'restoreAction' => KarMCP_Bulk_Optimizer::ACTION_RESTORE,
						'batchSize'     => 10,
						'optimizing'    => __( 'Optimizing…', 'karmcp' ),
						'restoring'     => __( 'Restoring…', 'karmcp' ),
						'done'          => __( 'Done', 'karmcp' ),
						'unsaved'       => __( 'Unsaved changes, click Save Modules to apply.', 'karmcp' ),
					)
				);
			}
		}
	}

	/**
	 * Admin notice shown when assets/js/admin.js is missing from the plugin
	 * folder — usually because security software or a host renamed/quarantined
	 * the .js file on upload (e.g. admin.js -> admin.j_). Without it, JS-driven
	 * features (the Connection-tab config generator, tool toggles, etc.) silently
	 * do nothing, so we surface a precise, actionable message. (GitHub #44)
	 *
	 * @since 2.1.0
	 */
	public function notice_missing_js_asset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Detect a mangled copy so we can name the exact file to restore.
		$dir     = KARMCP_DIR . 'assets/js/';
		$mangled = '';
		foreach ( array( 'admin.j_', 'admin.js_', 'admin._s', 'admin.js.quarantine' ) as $candidate ) {
			if ( file_exists( $dir . $candidate ) ) {
				$mangled = $candidate;
				break;
			}
		}

		echo '<div class="notice notice-error"><p><strong>KarMCP:</strong> ';
		echo esc_html__( 'A required script is missing, assets/js/admin.js was not found in the plugin folder, so admin features like the Connection-tab config generator will not work.', 'karmcp' );
		echo ' ';
		if ( '' !== $mangled ) {
			printf(
				/* translators: %s: the mangled filename found, e.g. admin.j_ */
				esc_html__( 'It looks like security software renamed it to assets/js/%s, rename that file back to admin.js.', 'karmcp' ),
				esc_html( $mangled )
			);
		} else {
			echo esc_html__( 'Some security software and hosts rename or quarantine .js files on upload. Re-upload a fresh copy of the plugin from the official release, and restore assets/js/admin.js if your host renamed it.', 'karmcp' );
		}
		echo '</p></div>';
	}

	/**
	 * AJAX: create a fresh Application Password for a chosen administrator.
	 *
	 * Returns the chunked plaintext password once so the Connection tab can drop
	 * it straight into the generated client configs — no profile visit needed.
	 *
	 * @since 1.8.3
	 */
	public function ajax_create_app_password(): void {
		check_ajax_referer( 'karmcp_create_app_password', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'No user selected.', 'karmcp' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'That user no longer exists.', 'karmcp' ) ), 404 );
		}

		// Only administrators, and only those the current user is allowed to edit.
		if ( ! user_can( $user, 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Application passwords can only be generated for administrator accounts here.', 'karmcp' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot manage application passwords for this user.', 'karmcp' ) ), 403 );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( array( 'message' => __( 'Application Passwords are not supported on this WordPress version.', 'karmcp' ) ), 400 );
		}

		// Application passwords only authenticate over HTTPS (or a local environment),
		// so refuse to mint one that could not actually be used to connect.
		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application Passwords require HTTPS. Load this site over https:// (or use the WP-CLI connection method for local development).', 'karmcp' ),
				),
				400
			);
		}

		$app_name = sprintf(
			/* translators: %s: current date and time */
			__( 'KarMCP (MCP), %s', 'karmcp' ),
			gmdate( 'Y-m-d H:i' )
		);

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $app_name ) );

		if ( is_wp_error( $created ) ) {
			wp_send_json_error( array( 'message' => $created->get_error_message() ), 400 );
		}

		$raw_password = isset( $created[0] ) ? $created[0] : '';
		if ( '' === $raw_password ) {
			wp_send_json_error( array( 'message' => __( 'Could not create an application password.', 'karmcp' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'username' => $user->user_login,
				'password' => \WP_Application_Passwords::chunk_password( $raw_password ),
				'name'     => $app_name,
			)
		);
	}

	/**
	 * AJAX: activate/deactivate a generated widget from the Widget Builder tab.
	 *
	 * @since 1.9.0
	 */
	public function ajax_toggle_widget(): void {
		check_ajax_referer( 'karmcp_widgets', 'nonce' );
		if ( ! class_exists( 'KarMCP_Widget_Store' ) || ! KarMCP_Widget_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$widget_id = isset( $_POST['widget_id'] ) ? absint( wp_unslash( $_POST['widget_id'] ) ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $widget_id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_Widget_Store::set_status( $widget_id, $status );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a generated widget from the Widget Builder tab.
	 *
	 * @since 1.9.0
	 */
	public function ajax_delete_widget(): void {
		check_ajax_referer( 'karmcp_widgets', 'nonce' );
		if ( ! class_exists( 'KarMCP_Widget_Store' ) || ! KarMCP_Widget_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$widget_id = isset( $_POST['widget_id'] ) ? absint( wp_unslash( $_POST['widget_id'] ) ) : 0;
		if ( ! $widget_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_Widget_Store::delete( $widget_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: mark app-bar notifications as read for the current user, called
	 * when the notifications dropdown is opened.
	 *
	 * @since 3.10.0
	 */
	public function ajax_notifications_read(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'karmcp' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'karmcp_notifications', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'karmcp' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids = array_map( 'sanitize_text_field', $ids );

		$user_id = get_current_user_id();
		KarMCP_Notifications::mark_read( $user_id, $ids );

		wp_send_json_success( array( 'unread' => KarMCP_Notifications::unread_count( $user_id ) ) );
	}

	/**
	 * AJAX: activate/deactivate a generated Gutenberg block from the Blocks tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_toggle_block(): void {
		check_ajax_referer( 'karmcp_blocks', 'nonce' );
		if ( ! class_exists( 'KarMCP_Block_Store' ) || ! KarMCP_Block_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$block_id = isset( $_POST['block_id'] ) ? absint( wp_unslash( $_POST['block_id'] ) ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $block_id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_Block_Store::instance()->set_status( $block_id, $status );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a generated Gutenberg block from the Blocks tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_delete_block(): void {
		check_ajax_referer( 'karmcp_blocks', 'nonce' );
		if ( ! class_exists( 'KarMCP_Block_Store' ) || ! KarMCP_Block_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$block_id = isset( $_POST['block_id'] ) ? absint( wp_unslash( $_POST['block_id'] ) ) : 0;
		if ( ! $block_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_Block_Store::instance()->delete( $block_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: create or update a PHP snippet draft from the Sandbox tab. Validates
	 * and refuses critical findings (returning them so the form can show why).
	 *
	 * @since 2.1.0
	 */
	public function ajax_save_php_snippet(): void {
		check_ajax_referer( 'karmcp_php_snippets', 'nonce' );
		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) || ! KarMCP_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PHP snippets (requires manage_options and unfiltered_html).', 'karmcp' ) ), 403 );
		}
		$id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		// Code is raw PHP: keep it verbatim (unslash only). It is never executed
		// here — it is validated and stored; execution requires later activation.
		$args = array(
			'title'    => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'code'     => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw PHP source, validated by the snippet validator, never executed here.
			'context'  => isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'shortcode',
			'hook'     => isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '',
			'priority' => isset( $_POST['priority'] ) ? absint( wp_unslash( $_POST['priority'] ) ) : 10,
		);

		$res = $id
			? KarMCP_PHP_Snippet_Store::update( $id, $args )
			: KarMCP_PHP_Snippet_Store::create_draft( $args );

		if ( is_wp_error( $res ) ) {
			$data    = $res->get_error_data();
			$payload = array( 'message' => $res->get_error_message() );
			if ( is_array( $data ) && isset( $data['validation'] ) ) {
				$payload['validation'] = $data['validation'];
			}
			wp_send_json_error( $payload, 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: activate/deactivate a PHP snippet (the human approval gate).
	 * Activation re-validates and writes the executable file.
	 *
	 * @since 2.1.0
	 */
	public function ajax_toggle_php_snippet(): void {
		check_ajax_referer( 'karmcp_php_snippets', 'nonce' );
		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) || ! KarMCP_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$id     = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_PHP_Snippet_Store::set_status( $id, $status );
		if ( is_wp_error( $res ) ) {
			$data    = $res->get_error_data();
			$payload = array( 'message' => $res->get_error_message() );
			if ( is_array( $data ) && isset( $data['validation'] ) ) {
				$payload['validation'] = $data['validation'];
			}
			wp_send_json_error( $payload, 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a PHP snippet from the Sandbox tab.
	 *
	 * @since 2.1.0
	 */
	public function ajax_delete_php_snippet(): void {
		check_ajax_referer( 'karmcp_php_snippets', 'nonce' );
		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) || ! KarMCP_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'karmcp' ) ), 403 );
		}
		$id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'karmcp' ) ), 400 );
		}
		$res = KarMCP_PHP_Snippet_Store::delete( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * admin-post.php callback: build + stream a Claude Desktop .mcpb bundle
	 * with the chosen admin's credentials baked in. POST body: user_id,
	 * app_password, _karmcp_nonce. Halts execution at the end.
	 *
	 * @since 3.0.0
	 */
	public function handle_download_mcpb(): void {
		if (
			! isset( $_POST['_karmcp_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_karmcp_nonce'] ) ), self::NONCE_DOWNLOAD_MCPB )
		) {
			wp_die( esc_html__( 'Invalid request.', 'karmcp' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this.', 'karmcp' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! current_user_can( 'edit_user', $user_id ) || ! user_can( $user_id, 'manage_options' ) ) {
			wp_die( esc_html__( 'Pick a valid administrator account.', 'karmcp' ), '', array( 'response' => 400 ) );
		}

		// The app password was generated on the page (Step 1) and POSTed back —
		// same-origin, nonce-gated, the admin's own credential.
		$app_password = isset( $_POST['app_password'] ) ? sanitize_text_field( wp_unslash( $_POST['app_password'] ) ) : '';
		if ( '' === $app_password ) {
			wp_die( esc_html__( 'Generate an Application Password first, then download the bundle.', 'karmcp' ), '', array( 'response' => 400 ) );
		}

		// Bake the reachable public base (rest_url-derived / admin-overridable),
		// NOT home_url() — on a staging host whose Site Address is pinned to a
		// not-yet-live domain, home_url() would ship a bundle that can't connect.
		$karmcp_base = class_exists( 'KarMCP_Site_Context' ) ? KarMCP_Site_Context::public_base_url() : home_url();
		$manifest  = KarMCP_Mcpb_Builder::build_manifest( $karmcp_base, $user->user_login, $app_password );
		$tmp      = KarMCP_Mcpb_Builder::build_zip( $manifest );
		if ( is_wp_error( $tmp ) ) {
			wp_die( esc_html( $tmp->get_error_message() ), '', array( 'response' => 500 ) );
		}

		// Safety net: the temp file holds a live Application Password. Guarantee
		// it is removed even if streaming aborts (fatal, memory limit, etc.) —
		// the explicit unlink after readfile() handles the normal fast path.
		register_shutdown_function(
			static function () use ( $tmp ) {
				if ( file_exists( $tmp ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		);

		$host     = (string) wp_parse_url( $karmcp_base, PHP_URL_HOST );
		$filename = 'karmcp-' . sanitize_file_name( $host ?: 'site' ) . '.mcpb';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $tmp );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		exit;
	}

	/**
	 * Nonce'd admin-post URL that exports one sandbox artifact (block/widget/
	 * snippet) as a portable JSON bundle download. Mirrors delete_change_url().
	 *
	 * @since 3.7.0
	 *
	 * @param string $kind One of 'block' | 'widget' | 'snippet'.
	 * @param int    $id   The artifact's local post ID.
	 * @return string
	 */
	public static function sandbox_export_url( string $kind, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_EXPORT_ARTIFACT,
					'kind'   => $kind,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_SANDBOX_BUNDLE
		);
	}

	/**
	 * admin-post.php callback: stream one sandbox artifact (custom block,
	 * custom widget, or PHP snippet) as a portable, checksum-verified JSON
	 * bundle download. GET: kind, id, _wpnonce. Halts execution at the end.
	 *
	 * Reuses KarMCP_Sandbox_Cloud_Abilities::resolve_artifact() — the same
	 * resolver the MCP export-sandbox-artifact tool uses — so a block export
	 * cleanly fails here (no fatal) on a site without the Pro overlay.
	 *
	 * @since 3.7.0
	 */
	public function handle_export_artifact(): void {
		check_admin_referer( self::NONCE_SANDBOX_BUNDLE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! in_array( $kind, KarMCP_Sandbox_Bundle::KINDS, true ) ) {
			wp_die( esc_html__( 'Unsupported sandbox artifact kind.', 'karmcp' ), '', array( 'response' => 400 ) );
		}

		$artifact = ( new KarMCP_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		if ( null === $artifact ) {
			wp_die( esc_html__( 'That artifact kind is unavailable on this site (it may require KarMCP Pro).', 'karmcp' ), '', array( 'response' => 400 ) );
		}

		$bundle = $artifact->to_bundle( $id );
		if ( is_wp_error( $bundle ) ) {
			wp_die( esc_html( $bundle->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$filename = sanitize_file_name( 'karmcp-' . $kind . '-' . $id . '.json' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		echo wp_json_encode( $bundle, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streamed JSON download body, not HTML.
		exit;
	}

	/**
	 * admin-post.php callback: import an uploaded sandbox artifact bundle
	 * (custom block, custom widget, or PHP snippet) as a new local draft.
	 * POST (multipart): the `bundle` file upload, `_wpnonce`. Redirects back
	 * to the pillar view for the imported kind with a minimal notice query
	 * arg. Halts execution at the end.
	 *
	 * Validates the upload (present, no error, size-capped, .json extension,
	 * decodes to an array) then defers to KarMCP_Sandbox_Bundle::validate()
	 * (schema version, kind, checksum) before resolving the artifact and
	 * calling apply_bundle() — a block import on a non-Pro site resolves to
	 * null and redirects with a clean notice, never a fatal.
	 *
	 * @since 3.7.0
	 */
	public function handle_import_artifact(): void {
		check_admin_referer( self::NONCE_SANDBOX_BUNDLE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'karmcp' ), '', array( 'response' => 403 ) );
		}

		$back = menu_page_url( 'karmcp-widgets', false );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES superglobal; every field is validated below before use.
		$file = isset( $_FILES['bundle'] ) && is_array( $_FILES['bundle'] ) ? $_FILES['bundle'] : array();

		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'No bundle file was uploaded, or the upload failed.', 'karmcp' ) ), $back ) );
			exit;
		}

		$max_bytes = 2 * MB_IN_BYTES;
		if ( ! isset( $file['size'] ) || $file['size'] <= 0 || $file['size'] > $max_bytes ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle file is empty or larger than 2 MB.', 'karmcp' ) ), $back ) );
			exit;
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( '.json' !== strtolower( substr( $name, -5 ) ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle must be a .json file.', 'karmcp' ) ), $back ) );
			exit;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? wp_unslash( $file['tmp_name'] ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The upload could not be read.', 'karmcp' ) ), $back ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a validated PHP-upload tmp file (is_uploaded_file() checked above), not a remote URL.
		$contents = file_get_contents( $tmp_name );
		$data     = ( false !== $contents ) ? json_decode( $contents, true ) : null;

		if ( ! is_array( $data ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle is not valid JSON.', 'karmcp' ) ), $back ) );
			exit;
		}

		$valid = KarMCP_Sandbox_Bundle::validate( $data );
		if ( is_wp_error( $valid ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $valid->get_error_message() ), $back ) );
			exit;
		}

		$kind     = (string) $data['kind'];
		$artifact = ( new KarMCP_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		if ( null === $artifact ) {
			wp_safe_redirect(
				add_query_arg(
					'import_error',
					rawurlencode(
						sprintf(
							/* translators: %s: artifact kind (e.g. "block") */
							__( 'The "%s" artifact kind requires KarMCP Pro.', 'karmcp' ),
							$kind
						)
					),
					$back
				)
			);
			exit;
		}

		$new_id = $artifact->apply_bundle( $data );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $new_id->get_error_message() ), $back ) );
			exit;
		}

		$view_by_kind = array(
			'block'   => 'blocks',
			'widget'  => 'widgets',
			'snippet' => 'snippets',
		);
		$view         = isset( $view_by_kind[ $kind ] ) ? $view_by_kind[ $kind ] : 'overview';

		wp_safe_redirect(
			add_query_arg(
				array(
					'view'     => $view,
					'imported' => '1',
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Build the headline stat cards shown on the Dashboard.
	 *
	 * Always includes Total Tools, Active, and Pro Tools. Prompts, Brand Kits,
	 * and Templates are appended only when their module is active (and, for the
	 * Pro-gated counts, when a value is available) — mirroring the module-tab
	 * visibility rules. Each entry is `key`/`value`/`label`; the view maps `key`
	 * to an icon.
	 *
	 * @since 3.1.0
	 * @return array<int,array{key:string,value:int,label:string}>
	 */
	public function get_dashboard_stats(): array {
		$stats = array(
			array( 'key' => 'tools', 'value' => (int) $this->get_total_tool_count(), 'label' => __( 'Total Tools', 'karmcp' ) ),
			array( 'key' => 'active', 'value' => (int) $this->get_enabled_tool_count(), 'label' => __( 'Active', 'karmcp' ) ),
		);

		// Count Pro tools.
		$pro_count = 0;
		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $tool ) {
				if ( in_array( 'pro', $tool['badges'], true ) || in_array( 'elementor-pro', $tool['badges'], true ) ) {
					$pro_count++;
				}
			}
		}
		$stats[] = array( 'key' => 'pro', 'value' => $pro_count, 'label' => __( 'Pro Tools', 'karmcp' ) );

		// Count prompts. For Pro sites with a synced bundle, use the actual
		// premium-library count (matches the Prompts tab). Otherwise count the
		// bundled sample files in prompts/.
		if ( $this->module_tab_visible( 'prompts' ) ) {
			$prompt_count = 0;
			if ( class_exists( 'KarMCP_Pro_Prompts' ) && KarMCP_Pro_Prompts::user_has_access() ) {
				$prompt_count = KarMCP_Pro_Prompts::cached_count();
			}
			if ( 0 === $prompt_count ) {
				$prompts_dir  = KARMCP_DIR . 'prompts/';
				$prompt_files = is_dir( $prompts_dir ) ? glob( $prompts_dir . '*.md' ) : array();
				$prompt_count = count( $prompt_files );
			}
			$stats[] = array( 'key' => 'prompts', 'value' => (int) $prompt_count, 'label' => __( 'Prompts', 'karmcp' ) );
		}

		// Brand kits: Pro shows the cached remote library count; everyone else
		// shows the bundled free-kit count (applying is a free feature).
		if ( $this->module_tab_visible( 'brand-kits' ) ) {
			$brand_kit_count = 0;
			$show_brand_kits = false;
			if ( class_exists( 'KarMCP_Pro_Brand_Kits' ) && KarMCP_Pro_Brand_Kits::user_has_access() ) {
				$brand_kit_count = KarMCP_Pro_Brand_Kits::count_cached_kits();
				$show_brand_kits = true;
			} elseif ( class_exists( 'KarMCP_Free_Brand_Kits' ) ) {
				$brand_kit_count = KarMCP_Free_Brand_Kits::count_kits();
				$show_brand_kits = $brand_kit_count > 0;
			}
			if ( $show_brand_kits ) {
				$stats[] = array( 'key' => 'brand-kits', 'value' => (int) $brand_kit_count, 'label' => __( 'Brand Kits', 'karmcp' ) );
			}
		}

		// Templates: Pro shows the templates-library total (sum across
		// categories). Hidden for free users and when the bundle can't be fetched.
		if ( $this->module_tab_visible( 'templates' ) && class_exists( 'KarMCP_Pro_Templates' ) && KarMCP_Pro_Templates::user_has_access() ) {
			$template_count  = 0;
			$karmcp_tpl_bundle = KarMCP_Pro_Templates::get_bundle();
			if ( ! is_wp_error( $karmcp_tpl_bundle ) && is_array( $karmcp_tpl_bundle ) && ! empty( $karmcp_tpl_bundle['categories'] ) ) {
				foreach ( $karmcp_tpl_bundle['categories'] as $karmcp_tpl_cat ) {
					$template_count += is_array( $karmcp_tpl_cat['templates'] ?? null ) ? count( $karmcp_tpl_cat['templates'] ) : 0;
				}
			}
			if ( $template_count > 0 ) {
				$stats[] = array( 'key' => 'templates', 'value' => $template_count, 'label' => __( 'Templates', 'karmcp' ) );
			}
		}

		return $stats;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = $this->get_active_tab();

		?>
		<div class="wrap elementor-mcp-admin">
			<h1><?php esc_html_e( 'KarMCP', 'karmcp' ); ?></h1>

			<?php
			// Success notice after a Settings API save (options.php redirects back
			// with settings-updated=true). Shown for any KarMCP settings tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- options.php verifies the settings nonce before redirecting.
			if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><strong><?php esc_html_e( 'Settings saved.', 'karmcp' ); ?></strong></p>
				</div>
				<?php
			endif;
			?>

			<?php
			// App-bar notifications bell + cloud button state (Cloud-fed, cached,
			// graceful offline — see KarMCP_Notifications).
			$karmcp_notifs  = class_exists( 'KarMCP_Notifications' ) ? KarMCP_Notifications::get() : array();
			$karmcp_uid     = get_current_user_id();
			$karmcp_unread  = class_exists( 'KarMCP_Notifications' ) ? KarMCP_Notifications::unread_count( $karmcp_uid ) : 0;
			$karmcp_seen    = (array) get_user_meta( $karmcp_uid, '_karmcp_read_notifications', true );
			$karmcp_cloud_connected = class_exists( 'KarMCP_Cloud' ) && KarMCP_Cloud::is_connected();
			?>

			<!-- App bar -->
			<div class="karmcp-appbar">
				<div class="karmcp-appbar-brand">
					<span class="karmcp-appbar-mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="24" height="24" focusable="false"><g stroke="currentColor" stroke-width="2.6" stroke-linecap="round" fill="none"><path d="M5.5 3.5v17"/><path d="M5.7 12.6 14.6 5.4"/><path d="M5.7 11.4 14.6 18.6"/></g><circle cx="17.6" cy="4.2" r="2.7" fill="currentColor"/><circle cx="17.6" cy="19.8" r="2.7" fill="currentColor"/></svg>
					</span>
					<span class="karmcp-appbar-title karmcp-appbar-title--full"><?php esc_html_e( 'KarMCP', 'karmcp' ); ?></span>
					<span class="karmcp-appbar-title karmcp-appbar-title--short"><?php esc_html_e( 'KarMCP', 'karmcp' ); ?></span>
					<span class="karmcp-appbar-version">v<?php echo esc_html( KARMCP_VERSION ); ?></span>
				</div>
				<div class="karmcp-appbar-actions">
					<a class="karmcp-appbar-changelog<?php echo 'mcp-log' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-mcp-log' ) ); ?>">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'MCP Log', 'karmcp' ); ?>
					</a>
					<a class="karmcp-appbar-changelog<?php echo 'history' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history' ) ); ?>">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php esc_html_e( 'History', 'karmcp' ); ?>
					</a>
					<a class="karmcp-appbar-changelog<?php echo 'changelog' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-changelog' ) ); ?>">
						<span class="dashicons dashicons-backup" aria-hidden="true"></span>
						<?php esc_html_e( 'Changelog', 'karmcp' ); ?>
					</a>
					<div class="karmcp-help-menu">
						<button type="button" class="karmcp-help-toggle" aria-haspopup="true">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php esc_html_e( 'Get Help', 'karmcp' ); ?>
							<span class="dashicons dashicons-arrow-down-alt2 karmcp-help-caret" aria-hidden="true"></span>
						</button>
						<div class="karmcp-help-dropdown" role="menu">
							<a role="menuitem" href="<?php echo esc_url( self::DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-book" aria-hidden="true"></span><?php esc_html_e( 'Documentation', 'karmcp' ); ?></a>
							<a role="menuitem" href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Support', 'karmcp' ); ?></a>
						</div>
					</div>
					<div class="karmcp-notif">
						<button type="button" class="karmcp-notif-toggle" aria-haspopup="true" aria-expanded="false" data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_notifications' ) ); ?>">
							<span class="dashicons dashicons-bell" aria-hidden="true"></span>
							<span class="karmcp-notif-badge<?php echo 0 === $karmcp_unread ? ' is-empty' : ''; ?>"><?php echo esc_html( (string) $karmcp_unread ); ?></span>
						</button>
						<div class="karmcp-notif-overlay" aria-hidden="true"></div>
						<aside class="karmcp-notif-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Announcements', 'karmcp' ); ?>">
							<div class="karmcp-notif-header">
								<span><?php esc_html_e( 'Announcements', 'karmcp' ); ?></span>
								<button type="button" class="karmcp-notif-close" aria-label="<?php esc_attr_e( 'Close', 'karmcp' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
							</div>
							<div class="karmcp-notif-list">
								<?php if ( empty( $karmcp_notifs ) ) : ?>
									<div class="karmcp-notif-empty"><?php esc_html_e( 'No announcements yet.', 'karmcp' ); ?></div>
								<?php else : ?>
									<?php foreach ( $karmcp_notifs as $karmcp_n ) : ?>
										<?php
										$karmcp_n_id      = isset( $karmcp_n['id'] ) ? (string) $karmcp_n['id'] : '';
										$karmcp_n_unread  = '' !== $karmcp_n_id && ! in_array( $karmcp_n_id, $karmcp_seen, true );
										$karmcp_n_level   = isset( $karmcp_n['level'] ) && '' !== $karmcp_n['level'] ? sanitize_html_class( $karmcp_n['level'] ) : 'info';
										$karmcp_n_icon    = isset( $karmcp_n['icon'] ) && '' !== $karmcp_n['icon'] ? sanitize_html_class( $karmcp_n['icon'] ) : 'megaphone';
										$karmcp_n_created = isset( $karmcp_n['created_at'] ) ? strtotime( (string) $karmcp_n['created_at'] ) : false;
										?>
										<div class="karmcp-notif-item karmcp-notif-item--<?php echo esc_attr( $karmcp_n_level ); ?><?php echo $karmcp_n_unread ? ' is-unread' : ''; ?>" data-id="<?php echo esc_attr( $karmcp_n_id ); ?>">
											<span class="karmcp-notif-item-icon dashicons dashicons-<?php echo esc_attr( $karmcp_n_icon ); ?>" aria-hidden="true"></span>
											<div class="karmcp-notif-item-body">
												<strong><?php echo esc_html( isset( $karmcp_n['title'] ) ? $karmcp_n['title'] : '' ); ?></strong>
												<p><?php echo esc_html( isset( $karmcp_n['body'] ) ? $karmcp_n['body'] : '' ); ?></p>
												<div class="karmcp-notif-item-meta">
													<?php if ( false !== $karmcp_n_created && $karmcp_n_created > 0 ) : ?>
														<span class="karmcp-notif-item-time">
															<?php
															/* translators: %s: human-readable time difference (e.g. "2 hours") */
															echo esc_html( sprintf( __( '%s ago', 'karmcp' ), human_time_diff( $karmcp_n_created ) ) );
															?>
														</span>
													<?php endif; ?>
													<?php if ( ! empty( $karmcp_n['url'] ) ) : ?>
														<a class="karmcp-notif-item-cta" href="<?php echo esc_url( $karmcp_n['url'] ); ?>" target="_blank" rel="noopener">
															<?php echo esc_html( ! empty( $karmcp_n['cta'] ) ? $karmcp_n['cta'] : __( 'Learn more', 'karmcp' ) ); ?>
														</a>
													<?php endif; ?>
												</div>
											</div>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</aside>
					</div>
					<a class="karmcp-cloud-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection' ) ); ?>" title="<?php echo esc_attr( $karmcp_cloud_connected ? __( 'KarMCP Cloud: Connected', 'karmcp' ) : __( 'KarMCP Cloud: Not connected — click to connect', 'karmcp' ) ); ?>">
						<span class="dashicons dashicons-cloud karmcp-cloud-icon" aria-hidden="true"></span>
						<span class="karmcp-cloud-dot<?php echo $karmcp_cloud_connected ? ' is-connected' : ''; ?>"></span>
					</a>
				</div>
			</div>

			<!-- Tab nav -->
						<div class="karmcp-appnav-wrap">
				<button type="button" class="karmcp-appnav-arrow karmcp-appnav-arrow--prev" aria-label="<?php esc_attr_e( 'Scroll tabs left', 'karmcp' ); ?>" hidden><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
<nav class="karmcp-appnav" aria-label="<?php esc_attr_e( 'KarMCP sections', 'karmcp' ); ?>">
				<?php
				foreach ( $this->get_submenus() as $karmcp_slug => $karmcp_label ) :
					$karmcp_tab_id = ( self::PAGE_SLUG === $karmcp_slug ) ? 'dashboard' : substr( $karmcp_slug, strlen( self::PAGE_SLUG . '-' ) );
					// Changelog + History + MCP Log live in the app-bar top-right, not the tab nav.
					if ( 'changelog' === $karmcp_tab_id || 'history' === $karmcp_tab_id || 'mcp-log' === $karmcp_tab_id ) {
						continue;
					}
					$karmcp_is_on = ( $karmcp_tab_id === $active_tab );
					?>
					<a class="karmcp-appnav-item<?php echo $karmcp_is_on ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $karmcp_slug ) ); ?>"
						<?php echo $karmcp_is_on ? 'aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( self::tab_icon( $karmcp_tab_id ) ); ?>" aria-hidden="true"></span>
						<span class="karmcp-appnav-label"><?php echo esc_html( $karmcp_label ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
				<button type="button" class="karmcp-appnav-arrow karmcp-appnav-arrow--next" aria-label="<?php esc_attr_e( 'Scroll tabs right', 'karmcp' ); ?>" hidden><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
			</div>

			<!-- Content -->
			<div class="tab-content<?php echo 'dashboard' === $active_tab ? ' tab-content--flush' : ''; ?>">
				<?php
				if ( 'dashboard' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-dashboard.php';
				} elseif ( 'modules' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-modules.php';
				} elseif ( 'connection' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-connection.php';
				} elseif ( 'context' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-context.php';
				} elseif ( 'prompts' === $active_tab && $this->module_tab_visible( 'prompts' ) ) {
					include KARMCP_DIR . 'includes/admin/views/page-prompts.php';
				} elseif ( 'templates' === $active_tab && $this->module_tab_visible( 'templates' ) ) {
					include KARMCP_DIR . 'includes/admin/views/page-templates.php';
				} elseif ( 'brand-kits' === $active_tab && $this->module_tab_visible( 'brand-kits' ) ) {
					include KARMCP_DIR . 'includes/admin/views/page-brand-kits.php';
				} elseif ( 'security' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-security.php';
				} elseif ( 'history' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-history.php';
				} elseif ( 'redirects' === $active_tab && $this->module_tab_visible( 'redirects' ) ) {
					include KARMCP_DIR . 'includes/admin/views/page-redirects.php';
				} elseif ( 'widgets' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-widgets.php';
				} elseif ( 'mcp-log' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-mcp-log.php';
				} elseif ( 'changelog' === $active_tab ) {
					include KARMCP_DIR . 'includes/admin/views/page-changelog.php';
				} else {
					include KARMCP_DIR . 'includes/admin/views/page-tools.php';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The ordered platform sub-tabs for the Tools page. Keyed by the `platform`
	 * value a category carries; the value is the display label. A future page
	 * builder is added by giving its categories a new platform value and adding
	 * a matching entry here.
	 *
	 * @since 3.0.0
	 * @return array<string,string>
	 */
	public static function platform_tabs(): array {
		return array(
			'elementor' => __( 'Elementor', 'karmcp' ),
			'wordpress' => __( 'WordPress', 'karmcp' ),
			'plugins'   => __( 'Plugins', 'karmcp' ),
			'themes'    => __( 'Themes', 'karmcp' ),
			'gutenberg' => __( 'Gutenberg', 'karmcp' ),
		);
	}

	/**
	 * Plugin-integration groups, in display order. Categories on the Plugins tab
	 * carry a `group` key naming one of these; page-tools.php clusters each
	 * plugin card under its group heading so the tab stays organized as the
	 * number of integrations grows. A category with no (or an unknown) group
	 * renders inline, ungrouped.
	 *
	 * @since 3.4.3
	 * @return array<string,array{label:string,desc:string}>
	 */
	public static function plugin_groups(): array {
		return array(
			'dynamic'   => array(
				'label' => __( 'Dynamic Content', 'karmcp' ),
				'desc'  => __( 'Custom fields & metadata, read and write dynamic content.', 'karmcp' ),
			),
			'ecommerce' => array(
				'label' => __( 'E-Commerce', 'karmcp' ),
				'desc'  => __( 'Stores, products, orders, and customers.', 'karmcp' ),
			),
			'forms'     => array(
				'label' => __( 'Forms', 'karmcp' ),
				'desc'  => __( 'Form definitions and submissions.', 'karmcp' ),
			),
			'seo'       => array(
				'label' => __( 'SEO', 'karmcp' ),
				'desc'  => __( 'Read & write the SEO metadata your SEO plugin stores.', 'karmcp' ),
			),
			'translation' => array(
				'label' => __( 'Translation', 'karmcp' ),
				'desc'  => __( 'Languages, translations, and the links between them.', 'karmcp' ),
			),
			'addons'    => array(
				'label' => __( 'Elementor Addons', 'karmcp' ),
				'desc'  => __( 'Discover addon widget packs, and manage Ultimate Addons for Elementor templates.', 'karmcp' ),
			),
			'other'     => array(
				'label' => __( 'Other Integrations', 'karmcp' ),
				'desc'  => __( 'Additional plugin integrations.', 'karmcp' ),
			),
		);
	}

	/**
	 * Connection-tab client registry: the single source of truth for the
	 * client cards grid + per-client reveal. `methods` declares WHICH options
	 * a client supports; the actual JSON/CLI/prompt strings are assembled
	 * client-side in admin.js from the generated credentials.
	 *
	 * `cli` is a printf-style template with these tokens, substituted in JS:
	 *   %ENDPOINT% (REST MCP url), %B64% (base64 user:app-password).
	 *
	 * @since 3.0.0
	 * @return array<int,array<string,mixed>>
	 */
	public static function connection_clients(): array {
		$claude_cli = 'claude mcp add --transport http %NAME% "%ENDPOINT%" --header "Authorization: Basic %B64%"';
		$codex_cli  = 'codex mcp add %NAME% --transport http --url "%ENDPOINT%" --header "Authorization=Basic %B64%"';

		// OAuth-mode setup per client — the browser sign-in supplies auth, so no
		// password. Shapes: 'cmd' (terminal command), 'connector' (custom-connector
		// UI), 'config' (a config-file snippet). %NAME%/%ENDPOINT% are filled in JS.
		$oauth_claude_code = array(
			'type' => 'cmd',
			'cmd'  => 'claude mcp add %NAME% --transport http %ENDPOINT%',
		);
		$oauth_claude_desktop = array(
			'type' => 'connector',
			'app'  => __( 'Claude Desktop', 'karmcp' ),
		);
		$oauth_claude_ai = array(
			'type'     => 'connector',
			'app'      => 'claude.ai',
			'deeplink' => 'claude-ai',
			'note'     => __( 'Works in the browser and in Claude Desktop.', 'karmcp' ),
		);
		$oauth_cursor = array(
			'type'     => 'config',
			'lang'     => 'json',
			'paths'    => array(
				array( 'path' => '~/.cursor/mcp.json', 'label' => __( 'Global', 'karmcp' ) ),
				array( 'path' => '.cursor/mcp.json', 'label' => __( 'Project', 'karmcp' ) ),
			),
			'template' => "{\n    \"mcpServers\": {\n        \"%NAME%\": {\n            \"url\": \"%ENDPOINT%\"\n        }\n    }\n}",
			'deeplink' => 'cursor',
		);
		// The ChatGPT App signs in through its own MCP UI (Add server → Streamable
		// HTTP → Authenticate), not a config file — config.toml has no OAuth path.
		$oauth_codex = array(
			'type'  => 'steps',
			'steps' => array(
				array(
					'title' => __( 'a. Open the MCP settings', 'karmcp' ),
					'desc'  => __( 'In the ChatGPT app, go to File → Settings → Plugins, switch to the MCP tab, and click “Add server”.', 'karmcp' ),
				),
				array(
					'title' => __( 'b. Choose Streamable HTTP', 'karmcp' ),
					'desc'  => __( 'Set Type to “Streamable HTTP”, then enter a name and this server URL:', 'karmcp' ),
				),
				array( 'title' => __( 'Name', 'karmcp' ), 'copy' => '%NAME%' ),
				array( 'title' => __( 'URL', 'karmcp' ), 'copy' => '%ENDPOINT%' ),
				array(
					'title' => __( 'c. Save, then Authenticate', 'karmcp' ),
					'desc'  => __( 'Click Save. An “Authenticate” button appears on the server row, click it, then “Approve” on the consent screen that opens. Your site is now connected and you can start chatting.', 'karmcp' ),
				),
			),
		);
		$oauth_antigravity = array(
			'type'     => 'config',
			'lang'     => 'json',
			'paths'    => array(
				array( 'path' => '~/.gemini/antigravity/mcp_config.json', 'label' => __( 'macOS / Linux', 'karmcp' ) ),
				array( 'path' => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json', 'label' => __( 'Windows', 'karmcp' ) ),
			),
			'template' => "{\n    \"mcpServers\": {\n        \"%NAME%\": {\n            \"command\": \"npx\",\n            \"args\": [\n                \"-y\",\n                \"mcp-remote\",\n                \"%ENDPOINT%\"\n            ]\n        }\n    }\n}",
		);
		$oauth_mcp_remote = array(
			'type' => 'cmd',
			'cmd'  => 'npx -y mcp-remote %ENDPOINT%',
		);
		// OpenClaw CLI — `openclaw mcp set <name> '<json>'` writes straight to
		// ~/.openclaw/openclaw.json (mcp.servers). Basic-auth via the headers map.
		$openclaw_cli   = 'openclaw mcp set %NAME% \'{"url":"%ENDPOINT%","transport":"streamable-http","headers":{"Authorization":"Basic %B64%"}}\'';
		// OpenClaw OAuth: same shape with auth:oauth; `openclaw mcp login` runs the flow.
		$oauth_openclaw = array(
			'type'      => 'config',
			'lang'      => 'json',
			'paths'     => array( array( 'path' => '~/.openclaw/openclaw.json', 'label' => '' ) ),
			// The "mcp" property (not a whole object) — openclaw.json usually has
			// other keys already; add this, or drop the server under an existing
			// mcp.servers.
			'template'  => "\"mcp\": {\n    \"servers\": {\n        \"%NAME%\": {\n            \"url\": \"%ENDPOINT%\",\n            \"transport\": \"streamable-http\",\n            \"auth\": \"oauth\"\n        }\n    }\n}",
			'merge_msg' => __( 'openclaw.json usually already has other settings. Add this "mcp" block, or if you already have one, add the server inside its "servers".', 'karmcp' ),
			'note'      => __( 'After saving, run  openclaw mcp login %NAME%  to authorize through your browser.', 'karmcp' ),
		);
		// Hermes uses ~/.hermes/config.yaml (mcp_servers). OAuth mode is url-only —
		// the server initiates the browser sign-in on first connect.
		$oauth_hermes = array(
			'type'     => 'config',
			'lang'     => 'yaml',
			'paths'    => array( array( 'path' => '~/.hermes/config.yaml', 'label' => '' ) ),
			'template' => "mcp_servers:\n  %NAME%:\n    url: \"%ENDPOINT%\"",
		);

		// Codex's "Connect to a custom MCP" UI form — a field-by-field mapping so
		// users know which Connection value goes where. %ENDPOINT%/%B64% are filled
		// with the live endpoint + Basic-auth token in JS (escaped). HTML tags are
		// kept outside the translation calls so they are not escaped.
		$codex_guide = '<p class="description">'
			. esc_html__( 'Prefer the ChatGPT App\'s UI? Choose “Connect to a custom MCP” → “Streamable HTTP”, then fill the form like this:', 'karmcp' )
			. '</p>'
			. '<table class="karmcp-conn-guide"><tbody>'
			. '<tr><th>' . esc_html__( 'Name', 'karmcp' ) . '</th><td><code>%NAME%</code></td></tr>'
			. '<tr><th>' . esc_html__( 'Transport', 'karmcp' ) . '</th><td>' . esc_html__( 'Streamable HTTP', 'karmcp' ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'URL', 'karmcp' ) . '</th><td><code>%ENDPOINT%</code></td></tr>'
			. '<tr><th>' . esc_html__( 'Bearer token env var', 'karmcp' ) . '</th><td>' . esc_html__( 'Leave blank, KarMCP uses a WordPress Application Password (HTTP Basic), not a bearer token.', 'karmcp' ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'Headers', 'karmcp' ) . '</th><td>' . esc_html__( 'Key', 'karmcp' ) . ' <code>Authorization</code> &middot; ' . esc_html__( 'Value', 'karmcp' ) . ' <code>Basic %B64%</code></td></tr>'
			. '</tbody></table>'
			. '<p class="description">' . esc_html__( 'Then Save. The config block below does the same thing with the URL + header approach.', 'karmcp' ) . '</p>';

		return array(
			array(
				'id'      => 'claude-desktop',
				'label'   => __( 'Claude Desktop', 'karmcp' ),
				'icon'    => 'desktop',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => true, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_claude_desktop,
			),
			array(
				'id'      => 'claude-ai',
				'label'   => __( 'Claude.ai', 'karmcp' ),
				'icon'    => 'admin-site-alt3',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'remote' ) ),
				'oauth'   => $oauth_claude_ai,
			),
			array(
				'id'      => 'claude-code',
				'label'   => __( 'Claude Code', 'karmcp' ),
				'icon'    => 'editor-code',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => false, 'cli' => $claude_cli, 'ai_prompt' => false, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_claude_code,
			),
			array(
				'id'      => 'cursor',
				'label'   => __( 'Cursor', 'karmcp' ),
				'icon'    => 'editor-code',
				'image'   => 'cursor.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_cursor,
			),
			array(
				'id'          => 'codex',
				'label'       => __( 'ChatGPT App', 'karmcp' ),
				'icon'        => 'editor-code',
				'image'       => 'gpt.png',
				'guide_title' => __( 'Using the ChatGPT App “Custom MCP” form', 'karmcp' ),
				'guide'       => $codex_guide,
				'methods'     => array( 'bundle' => false, 'cli' => $codex_cli, 'ai_prompt' => false, 'json' => array( 'toml' ) ),
				'oauth'       => $oauth_codex,
			),
			array(
				'id'      => 'antigravity',
				'label'   => __( 'Antigravity', 'karmcp' ),
				'icon'    => 'editor-code',
				'image'   => 'antigravity.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_antigravity,
			),
			array(
				'id'      => 'openclaw',
				'label'   => __( 'OpenClaw', 'karmcp' ),
				'icon'    => 'editor-code',
				'methods' => array( 'bundle' => false, 'cli' => $openclaw_cli, 'ai_prompt' => false, 'json' => array( 'openclaw-http' ) ),
				'oauth'   => $oauth_openclaw,
			),
			array(
				'id'      => 'hermes',
				'label'   => __( 'Hermes', 'karmcp' ),
				'icon'    => 'editor-code',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'hermes-http' ) ),
				'oauth'   => $oauth_hermes,
			),
			array(
				'id'      => 'mcp-remote',
				'label'   => __( 'npx mcp-remote', 'karmcp' ),
				'icon'    => 'admin-links',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'remote' ) ),
				'oauth'   => $oauth_mcp_remote,
			),
		);
	}

	/**
	 * Group a tool-category map into one bucket per platform tab, preserving
	 * category order within each bucket. A category with a missing or unknown
	 * `platform` falls into the default ('elementor') bucket.
	 *
	 * @since 3.0.0
	 * @param array $categories Category map (id => category array) from get_all_tools().
	 * @return array<string,array> [ 'elementor' => [...], 'wordpress' => [...] ]
	 */
	public static function partition_by_platform( array $categories ): array {
		$buckets = array();
		foreach ( array_keys( self::platform_tabs() ) as $tab_id ) {
			$buckets[ $tab_id ] = array();
		}
		foreach ( $categories as $id => $cat ) {
			$platform = ( isset( $cat['platform'] ) && isset( $buckets[ $cat['platform'] ] ) ) ? $cat['platform'] : 'elementor';
			$buckets[ $platform ][ $id ] = $cat;
		}
		// Sort danger categories (filesystem/database) to the end of their tab —
		// the most powerful/destructive groups live at the bottom. Relative order
		// is otherwise preserved.
		foreach ( $buckets as $tab_id => $cats ) {
			$normal = array();
			$danger = array();
			foreach ( $cats as $id => $cat ) {
				if ( ! empty( $cat['danger'] ) ) {
					$danger[ $id ] = $cat;
				} else {
					$normal[ $id ] = $cat;
				}
			}
			$buckets[ $tab_id ] = $normal + $danger;
		}
		return $buckets;
	}

	/**
	 * Whether a tool category belongs to the Elementor platform (the default
	 * when no platform key is set), i.e. it is unavailable when Elementor
	 * is inactive.
	 *
	 * @since 3.0.0
	 *
	 * @param array $category A get_all_tools() category entry.
	 * @return bool
	 */
	public static function is_elementor_category( array $category ): bool {
		return 'elementor' === ( $category['platform'] ?? 'elementor' );
	}

	/**
	 * Whether the Astra theme integration's tools are available (Astra is the
	 * active parent theme). When false the admin greys out + disables the Astra
	 * toggles, the same way Elementor tools are gated when Elementor is inactive.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function astra_available(): bool {
		return function_exists( 'get_template' ) && 'astra' === get_template();
	}

	/**
	 * Whether the Kadence theme integration's tools are available (Kadence is the
	 * active theme). When false the admin greys out + disables the Kadence
	 * theme-settings toggles.
	 *
	 * @since 3.9.0
	 * @return bool
	 */
	public static function kadence_available(): bool {
		return function_exists( 'get_template' ) && 'kadence' === get_template();
	}

	/**
	 * Whether the Kadence Blocks integration's tools are available (the Kadence
	 * Blocks plugin is active — independent of the active theme).
	 *
	 * @since 3.9.0
	 * @return bool
	 */
	public static function kadence_blocks_available(): bool {
		return class_exists( 'KarMCP_Kadence_Blocks_Catalog' ) && KarMCP_Kadence_Blocks_Catalog::is_active();
	}

	/**
	 * Whether the GeneratePress theme integration's tools are available
	 * (GeneratePress is the active theme; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function generatepress_available(): bool {
		return function_exists( 'get_template' ) && 'generatepress' === get_template();
	}

	/**
	 * Whether the GenerateBlocks integration's tools are available (the
	 * GenerateBlocks plugin is active; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function generateblocks_available(): bool {
		return class_exists( 'KarMCP_GenerateBlocks_Catalog' ) && KarMCP_GenerateBlocks_Catalog::is_active();
	}

	/**
	 * Whether the Blocksy blocks integration's tools are available (Blocksy
	 * Companion active; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function blocksy_blocks_available(): bool {
		return class_exists( 'KarMCP_Blocksy_Blocks_Catalog' ) && KarMCP_Blocksy_Blocks_Catalog::is_active();
	}

	/**
	 * Whether the Blocksy extensions integration's tools are available (the Blocksy
	 * ExtensionsManager exists; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function blocksy_extensions_available(): bool {
		return class_exists( '\\Blocksy\\ExtensionsManager' );
	}

	/**
	 * Whether the WooCommerce integration's tools are available (WooCommerce
	 * installed and active).
	 *
	 * @since 3.4.2
	 * @return bool
	 */
	public static function woo_available(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Form-plugin availability — mirrors each adapter's is_active() so the admin
	 * card greys out its toggles when the plugin is inactive. Detection is
	 * reconciled with the adapter's own is_active() in the adapter tasks.
	 *
	 * @since 3.5.0
	 */
	public static function cf7_available(): bool {
		return class_exists( 'WPCF7_ContactForm' ) || defined( 'WPCF7_VERSION' );
	}

	/** @since 1.1.0 */
	public static function polylang_available(): bool {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_save_post_translations' );
	}

	/** @since 1.1.0 */
	public static function wpml_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
	}

	/** @since 3.5.0 */
	public static function wpforms_available(): bool {
		return function_exists( 'wpforms' );
	}

	/** @since 3.5.0 */
	public static function gravityforms_available(): bool {
		return class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
	}

	/** @since 3.5.0 */
	public static function fluentforms_available(): bool {
		return defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' );
	}

	/** @since 3.5.0 */
	public static function ninjaforms_available(): bool {
		return function_exists( 'Ninja_Forms' );
	}

	/** @since 3.5.0 */
	public static function formidable_available(): bool {
		return class_exists( 'FrmForm' ) || class_exists( 'FrmAppHelper' );
	}

	/** @since 3.5.0 */
	public static function metform_available(): bool {
		return defined( 'METFORM_VERSION' ) || post_type_exists( 'metform-form' );
	}

	/** @since 3.5.0 */
	public static function sureforms_available(): bool {
		return defined( 'SRFM_VER' ) || post_type_exists( 'sureforms_form' );
	}

	/** @since 3.8.0 */
	public static function forminator_available(): bool {
		return class_exists( 'Forminator_API' ) || defined( 'FORMINATOR_VERSION' );
	}

	/**
	 * SEO-plugin availability — mirrors each adapter's is_active() so the admin
	 * card greys out its toggles when the plugin is inactive. Reconciled with the
	 * adapter's own is_active() in the adapter tasks.
	 *
	 * @since 3.5.0
	 */
	public static function slimseo_available(): bool {
		return defined( 'SLIM_SEO_VER' ) || class_exists( '\\SlimSEO\\Plugin' );
	}

	/** @since 3.5.0 */
	public static function yoast_available(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/** @since 3.5.0 */
	public static function rankmath_available(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/** @since 3.5.0 */
	public static function aioseo_available(): bool {
		return function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' );
	}

	/** @since 3.5.0 */
	public static function seopress_available(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/** @since 3.5.0 */
	public static function seoframework_available(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' );
	}

	/** @since 3.5.0 */
	public static function surerank_available(): bool {
		return defined( 'SURERANK_VERSION' ) || class_exists( '\\SureRank\\Inc\\Meta_Data' );
	}

	/**
	 * The 14 SEO dispatcher slugs — drift-guard exclusion (registered only when
	 * their plugin is active / Pro).
	 *
	 * @since 3.5.0
	 * @return string[]
	 */
	public static function seo_tool_slugs(): array {
		return array(
			'karmcp/slimseo-read',
			'karmcp/slimseo-write',
			'karmcp/yoast-read',
			'karmcp/yoast-write',
			'karmcp/rankmath-read',
			'karmcp/rankmath-write',
			'karmcp/aioseo-read',
			'karmcp/aioseo-write',
			'karmcp/seopress-read',
			'karmcp/seopress-write',
			'karmcp/seoframework-read',
			'karmcp/seoframework-write',
			'karmcp/surerank-read',
			'karmcp/surerank-write',
		);
	}

	/**
	 * The 12 Forms dispatcher slugs — drift-guard exclusion (registered only when
	 * their plugin is active / Pro, so the drift guard must not flag them as
	 * "missing" tools).
	 *
	 * @since 3.5.0
	 * @return string[]
	 */
	/**
	 * Elementor addon-domain tool slugs (Pro).
	 *
	 * The two widget packs contribute a single read tool each: they exist for
	 * discovery and curation, because their widgets are placed with the generic
	 * add-free-widget tool. HFE is a data plugin and keeps the read/write pair.
	 *
	 * @since 3.6.0
	 * @return string[]
	 */
	public static function addon_tool_slugs(): array {
		return array(
			'karmcp/essential-addons-read',
			'karmcp/premium-addons-read',
			'karmcp/uae-read',
			'karmcp/uae-write',
		);
	}

	/**
	 * True when Essential Addons (Lite or Pro) is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function essential_addons_available(): bool {
		return defined( 'EAEL_PLUGIN_VERSION' )
			|| class_exists( '\Essential_Addons_Elementor\Classes\Bootstrap' );
	}

	/**
	 * True when Premium Addons is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function premium_addons_available(): bool {
		return defined( 'PREMIUM_ADDONS_VERSION' )
			|| defined( 'PREMIUM_ADDONS_FILE' )
			|| class_exists( 'PremiumAddons\Includes\Addons_Integration' );
	}

	/**
	 * True when the free Ultimate Addons for Elementor plugin (formerly Header
	 * Footer Elementor) is active. Its own identifiers still say HFE.
	 *
	 * This is what gates TEMPLATES: the `elementor-hf` CPT and its `ehf_*`
	 * display-condition meta belong to the free plugin.
	 *
	 * @since 3.6.2
	 * @return bool
	 */
	public static function uae_templates_available(): bool {
		return class_exists( 'Header_Footer_Elementor' ) || post_type_exists( 'elementor-hf' );
	}

	/**
	 * True when UAE Pro is active.
	 *
	 * UAE Pro ("Ultimate Addons for Elementor Pro", slug `ultimate-elementor`)
	 * is a SEPARATE standalone plugin, not an add-on to the free one, and can be
	 * installed on its own.
	 *
	 * @since 3.6.2
	 * @return bool
	 */
	public static function uae_pro_available(): bool {
		return defined( 'UAEL_VER' ) || class_exists( 'UAEL_Loader' );
	}

	/**
	 * True when either UAE plugin is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function uae_available(): bool {
		return self::uae_templates_available() || self::uae_pro_available();
	}

	public static function form_tool_slugs(): array {
		return array(
			'karmcp/cf7-read',
			'karmcp/cf7-write',
			'karmcp/wpforms-read',
			'karmcp/wpforms-write',
			'karmcp/gravityforms-read',
			'karmcp/gravityforms-write',
			'karmcp/fluentforms-read',
			'karmcp/fluentforms-write',
			'karmcp/ninjaforms-read',
			'karmcp/ninjaforms-write',
			'karmcp/formidable-read',
			'karmcp/formidable-write',
			'karmcp/metform-read',
			'karmcp/metform-write',
			'karmcp/sureforms-read',
			'karmcp/sureforms-write',
			'karmcp/forminator-read',
			'karmcp/forminator-write',
		);
	}

	/**
	 * Whether the Spectra Blocks integration's tools are available (the Spectra
	 * plugin — Ultimate Addons for Gutenberg — is installed and active).
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function spectra_available(): bool {
		return class_exists( 'KarMCP_Spectra_Catalog' ) && KarMCP_Spectra_Catalog::is_active();
	}

	/**
	 * Whether Spectra is set to generate separate CSS/JS files (as opposed to its
	 * default inline CSS). In file mode, pages an AI builds or edits over MCP can
	 * render with stale cached CSS until the assets are regenerated — so the combo
	 * section shows a heads-up to switch to inline while building.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function spectra_file_generation_on(): bool {
		if ( ! self::spectra_available() ) {
			return false;
		}
		// Spectra's default is inline CSS; treat an absent option as inline.
		if ( class_exists( 'UAGB_Admin_Helper' ) && method_exists( 'UAGB_Admin_Helper', 'get_admin_settings_option' ) ) {
			return 'enabled' === UAGB_Admin_Helper::get_admin_settings_option( '_uagb_allow_file_generation', 'disabled' );
		}
		return 'enabled' === get_option( '_uagb_allow_file_generation', 'disabled' );
	}

	/**
	 * The Astra + Spectra section notice, or null. Returns an actionable warning
	 * only when Spectra's separate-file CSS generation is on (the state that
	 * causes stale styling for AI-built pages).
	 *
	 * @since 3.4.0
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function spectra_file_generation_notice(): ?array {
		if ( ! self::spectra_file_generation_on() ) {
			return null;
		}
		return array(
			'type'    => 'warning',
			'message' => __( 'Spectra is set to generate separate CSS files. When an AI builds or edits pages over MCP, those cached files can go stale and a page may look unstyled until they are rebuilt. While building with AI, turn OFF Spectra → Settings → Asset Generation → File Generation (use inline CSS), or click "Regenerate Assets" there after edits.', 'karmcp' ),
		);
	}

	/**
	 * Returns the categories with the Elementor-platform ones removed. Used for
	 * truthful tool counts when Elementor is inactive (those tools never register).
	 *
	 * @since 3.0.0
	 *
	 * @param array $categories get_all_tools() output.
	 * @return array
	 */
	public static function filter_out_elementor( array $categories ): array {
		return array_filter(
			$categories,
			static function ( $cat ) {
				return ! self::is_elementor_category( $cat );
			}
		);
	}

	/**
	 * Get all tools grouped by category for the UI.
	 *
	 * Returns the curated catalog (see get_tool_catalog()) and, under WP_DEBUG,
	 * cross-checks it against the live ability registry so the hand-maintained
	 * catalog can't silently drift from the actually-registered tools (F-019).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, tools: array<string, array{label: string, description: string, badges: string[]}>}> Grouped tools.
	 */
	public function get_all_tools(): array {
		$catalog = $this->get_tool_catalog();

		// F-019 drift guard: the catalog carries admin-UI metadata (labels,
		// descriptions, badges) the bare ability registry doesn't have, so it
		// stays curated rather than derived. To stop it drifting, cross-check
		// each catalog slug against the live registry and log any that isn't a
		// registered ability (a renamed/removed tool, or env-gated).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && class_exists( 'WP_Abilities_Registry' ) ) {
			$karmcp_registry = WP_Abilities_Registry::get_instance();
			// Tools that only register when their module/feature/flag is on are
			// legitimately absent — skip them so the guard flags genuine drift
			// (renamed/removed tools) and not expected environment-gating.
			$karmcp_conditional = array_merge(
				self::themer_php_tool_slugs(),
				self::acf_tool_slugs(),
				self::woo_tool_slugs(),
				self::metabox_tool_slugs(),
				self::form_tool_slugs(),
				self::seo_tool_slugs(),
				self::addon_tool_slugs(),
				self::theme_tool_slugs(),
				self::seo_a11y_tool_slugs(),
				self::widget_builder_tool_slugs(),
				self::block_tool_slugs(),
				self::redirect_tool_slugs(),
				self::migrate_tool_slugs(),
				array( 'karmcp/list-redirects', 'karmcp/find-broken-links', 'karmcp/resize-media' )
			);
			foreach ( $catalog as $karmcp_group ) {
				foreach ( array_keys( $karmcp_group['tools'] ?? array() ) as $karmcp_slug ) {
					// is_registered() is a silent isset() check — unlike wp_get_ability()
					// / get_registered(), it does not _doing_it_wrong() "Ability not
					// found" for env-gated tools, which was flooding debug.log (#71).
					if ( ! $karmcp_registry->is_registered( $karmcp_slug )
						&& ! in_array( $karmcp_slug, $karmcp_conditional, true ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( '[KarMCP] get_all_tools: catalog tool "' . $karmcp_slug . '" is not in the ability registry (drift or environment-gated).' );
					}
				}
			}
		}

		// Drop every `pro`-flagged category. Upstream kept them in the catalog as
		// a locked upsell surface, but each one describes an integration whose
		// implementation lived in the private Pro overlay and is absent here.
		// Listing tools that can never register would be a lie the Tools screen
		// tells the admin, so they are removed rather than greyed out.
		$catalog = array_filter(
			$catalog,
			static function ( $karmcp_cat ) {
				return empty( $karmcp_cat['pro'] );
			}
		);

		return $catalog;
	}

	/**
	 * The curated admin tool catalog: every tool grouped by category with its
	 * label, description, and badges for the Tools admin screen. This is the
	 * source of the admin-UI metadata; get_all_tools() keeps it honest against
	 * the ability registry.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, tools: array<string, array{label: string, description: string, badges: string[]}>}> Grouped tools.
	 */
	private function get_tool_catalog(): array {
		$tools = array(
			'query'            => array(
				'platform' => 'elementor',
				'label' => __( 'Query & Discovery', 'karmcp' ),
				'tools' => array(
					'karmcp/list-widgets'         => array(
						'label'       => __( 'List Widgets', 'karmcp' ),
						'description' => __( 'Lists all available Elementor widget types and their names.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-widget-schema'    => array(
						'label'       => __( 'Get Widget Schema', 'karmcp' ),
						'description' => __( 'Returns the JSON schema for a specific widget type.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-page-structure'   => array(
						'label'       => __( 'Get Page Structure', 'karmcp' ),
						'description' => __( 'Returns the full Elementor element tree for a page.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-page-snapshot'    => array(
						'label'       => __( 'Get Page Snapshot', 'karmcp' ),
						'description' => __( 'One normalized page digest: structure, tokens-in-use, responsive overrides, content outline, SEO-lite (+ opt-in performance/a11y/seo).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-element-settings' => array(
						'label'       => __( 'Get Element Settings', 'karmcp' ),
						'description' => __( 'Returns the settings of a specific element by ID.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-pages'           => array(
						'label'       => __( 'List Pages', 'karmcp' ),
						'description' => __( 'Lists all pages/posts that use Elementor.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-templates'       => array(
						'label'       => __( 'List Templates', 'karmcp' ),
						'description' => __( 'Lists all saved Elementor templates.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-global-settings'  => array(
						'label'       => __( 'Get Global Settings', 'karmcp' ),
						'description' => __( 'Returns global colors, typography, and theme settings.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'render'           => array(
				'platform' => 'wordpress',
				'label' => __( 'Render & Audit', 'karmcp' ),
				'tools' => array(
					'karmcp/render-page' => array(
						'label'       => __( 'Render Page', 'karmcp' ),
						'description' => __( 'Renders a page the way a visitor gets it and returns a digest of the output: heading outline, links, images, forms, visible text, and warnings for empty containers, missing alt text, placeholder links and unresolved shortcodes. Read-only.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'scaffold'         => array(
				'platform' => 'wordpress',
				'label' => __( 'Site Scaffolding', 'karmcp' ),
				'tools' => array(
					'karmcp/build-site' => array(
						'label'       => __( 'Build Site', 'karmcp' ),
						'description' => __( 'Creates a site skeleton in one call: pages, navigation menu, static front page, and the global palette and fonts. Dry-run unless called with apply:true and confirm:true, and idempotent by slug. Requires administrator. Disabled by default.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'structured_data'  => array(
				'platform' => 'wordpress',
				'label' => __( 'Structured Data', 'karmcp' ),
				'tools' => array(
					'karmcp/get-post-schema' => array(
						'label'       => __( 'Get Post Schema', 'karmcp' ),
						'description' => __( 'Returns the Schema.org JSON-LD attached to a post, and whether an SEO plugin is already emitting its own.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/set-post-schema' => array(
						'label'       => __( 'Set Post Schema', 'karmcp' ),
						'description' => __( 'Attaches validated Schema.org JSON-LD (Organization, LocalBusiness, Product, FAQPage, BreadcrumbList, Article, Person, Service, Event) to a post, printed in the page head.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'redirects'        => array(
				'platform' => 'wordpress',
				'label' => __( 'Redirects', 'karmcp' ),
				'tools' => array(
					'karmcp/list-redirects'    => array(
						'label'       => __( 'List Redirects', 'karmcp' ),
						'description' => __( 'Lists the site\'s managed 301/302 redirects (source → target, code, hits).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/find-broken-links' => array(
						'label'       => __( 'Find Broken Links', 'karmcp' ),
						'description' => __( 'Scans published content for internal links to dead or already-redirected URLs. Read-only.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/create-redirect'   => array(
						'label'       => __( 'Create Redirect', 'karmcp' ),
						'description' => __( 'Creates a 301/302 redirect from an old path to a target URL or post. Disabled by default.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-redirect'   => array(
						'label'       => __( 'Update Redirect', 'karmcp' ),
						'description' => __( 'Updates an existing redirect by id. Disabled by default.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-redirect'   => array(
						'label'       => __( 'Delete Redirect', 'karmcp' ),
						'description' => __( 'Deletes a redirect by id. Reversible from History. Disabled by default.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
				),
			),
			// Not built in this tree: KarMCP_Migrate_Abilities does not exist, so
			// none of these tools ever register. Flagged so get_all_tools() drops
			// the whole category — otherwise the Tools screen shows 7 toggles that
			// do nothing and the dashboard's "X of Y" counters are inflated by 7.
			'migrate'          => array(
				'platform' => 'wordpress',
				'pro'   => true,
				'label' => __( 'Backup & Migrate', 'karmcp' ),
				'tools' => array(
					'karmcp/create-backup' => array(
						'label'       => __( 'Create Backup', 'karmcp' ),
						'description' => __( 'Creates a portable .karmcp backup (full/database/files) and returns its id + size. Non-destructive.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/list-backups'  => array(
						'label'       => __( 'List Backups', 'karmcp' ),
						'description' => __( 'Lists this site\'s .karmcp backups. Read-only.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/migrate-site'  => array(
						'label'       => __( 'Migrate Site to Live', 'karmcp' ),
						'description' => __( 'Pushes this whole site to a paired live target and restores it there. Destructive on the destination; requires confirm. Disabled by default.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/sync-to-live'  => array(
						'label'       => __( 'Sync to Live', 'karmcp' ),
						'description' => __( 'Pushes a full or selective scope (chosen tables/files) to a paired live target. Destructive for the pushed scope; requires confirm. Disabled by default.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/list-syncable-changes' => array(
						'label'       => __( 'List Syncable Changes', 'karmcp' ),
						'description' => __( 'Lists pages/posts/CPTs changed locally since they were last synced to a paired live target. Read-only.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/sync-content-item' => array(
						'label'       => __( 'Sync Content Item to Live', 'karmcp' ),
						'description' => __( 'Pushes one page/post/CPT (content + fields + attached media) to a paired live target, upserting it and remapping media. Overwrites only that item; requires confirm. Disabled by default.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/discard-sync-change' => array(
						'label'       => __( 'Discard Sync Change', 'karmcp' ),
						'description' => __( 'Dismisses an item from the changes-to-sync list until it changes again. Local only.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'gutenberg_blocks' => array(
				'platform' => 'gutenberg',
				'label' => __( 'Gutenberg Blocks', 'karmcp' ),
				'tools' => array(
					'karmcp/list-blocks'      => array(
						'label'       => __( 'List Blocks', 'karmcp' ),
						'description' => __( 'Lists registered block types (name, title, category).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-block-schema' => array(
						'label'       => __( 'Get Block Schema', 'karmcp' ),
						'description' => __( 'Returns a block\'s attributes, supports, and a markup example.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-post-blocks'  => array(
						'label'       => __( 'Get Post Blocks', 'karmcp' ),
						'description' => __( 'Returns a post\'s block tree with an index path per block.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-patterns'    => array(
						'label'       => __( 'List Patterns', 'karmcp' ),
						'description' => __( 'Lists registered block patterns.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/add-block'        => array(
						'label'       => __( 'Add Block', 'karmcp' ),
						'description' => __( 'Inserts block markup into a post at a position.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-block'     => array(
						'label'       => __( 'Update Block', 'karmcp' ),
						'description' => __( 'Replaces the block at an index path with new markup.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/remove-block'     => array(
						'label'       => __( 'Remove Block', 'karmcp' ),
						'description' => __( 'Deletes the block at an index path.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/move-block'       => array(
						'label'       => __( 'Move Block', 'karmcp' ),
						'description' => __( 'Moves a block to a new position.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/duplicate-block'  => array(
						'label'       => __( 'Duplicate Block', 'karmcp' ),
						'description' => __( 'Clones the block at a path and inserts the copy after it.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/insert-pattern'   => array(
						'label'       => __( 'Insert Pattern', 'karmcp' ),
						'description' => __( 'Inserts a registered block pattern into a post.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'wp_nav_menus'     => array(
				'platform' => 'wordpress',
				'label' => __( 'Navigation Menus', 'karmcp' ),
				'tools' => array(
					'karmcp/menu-read'  => array(
						'label'       => __( 'Menu Read', 'karmcp' ),
						'description' => __( 'Read nav menus: list menus, get a menu\'s nested item tree, list theme locations, render a menu to HTML. Call with no operation to list read operations.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/menu-write' => array(
						'label'       => __( 'Menu Write', 'karmcp' ),
						'description' => __( 'Manage nav menus: create/rename/delete menus, assign theme locations, and add/update/delete/reorder items. Call with no operation to list write operations.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'wp_content'       => array(
				'platform' => 'wordpress',
				'label' => __( 'WordPress Content', 'karmcp' ),
				'tools' => array(
					'karmcp/list-post-types' => array(
						'label'       => __( 'List Post Types', 'karmcp' ),
						'description' => __( 'Lists registered post types (posts, pages, CPTs).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-taxonomies' => array(
						'label'       => __( 'List Taxonomies', 'karmcp' ),
						'description' => __( 'Lists taxonomies and optionally their terms.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/create-post'     => array(
						'label'       => __( 'Create Post', 'karmcp' ),
						'description' => __( 'Creates a post/page/CPT with content, terms, meta, featured image.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/duplicate-post'  => array(
						'label'       => __( 'Duplicate Post', 'karmcp' ),
						'description' => __( 'Copies a post, page or CPT with its meta and terms, as a draft. The way to create posts of plugin-owned types (popups, listings) whose configuration lives in protected meta that create-post will not write. Requires confirm:true. Disabled by default.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/get-post'        => array(
						'label'       => __( 'Get Post', 'karmcp' ),
						'description' => __( 'Returns a post\'s content, terms, meta, and featured image.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/update-post'     => array(
						'label'       => __( 'Update Post', 'karmcp' ),
						'description' => __( 'Partial update of a post/page/CPT.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/list-posts'      => array(
						'label'       => __( 'List Posts', 'karmcp' ),
						'description' => __( 'Lists/searches posts, pages, or any CPT (compact).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/delete-post'     => array(
						'label'       => __( 'Delete Post', 'karmcp' ),
						'description' => __( 'Trashes (or force-deletes) a post.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/set-post-terms'  => array(
						'label'       => __( 'Set Post Terms', 'karmcp' ),
						'description' => __( 'Assigns category/tag/custom terms to a post.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'wp_settings'      => array(
				'platform' => 'wordpress',
				'label' => __( 'WordPress Settings', 'karmcp' ),
				'tools' => array(
					'karmcp/get-settings'    => array(
						'label'       => __( 'Get Settings', 'karmcp' ),
						'description' => __( 'Reads curated site settings (general, reading, writing, discussion, media, permalinks).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/update-settings' => array(
						'label'       => __( 'Update Settings', 'karmcp' ),
						'description' => __( 'Updates curated site settings; auto-flushes rewrite rules on permalink changes.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'performance'      => array(
				'platform' => 'wordpress',
				'label' => __( 'Performance & Security', 'karmcp' ),
				'tools' => array(
					'karmcp/analyze-performance' => array(
						'label'       => __( 'Analyze Performance', 'karmcp' ),
						'description' => __( 'Audits server config, WordPress internals, and a target page; returns a scored report with recommendations.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/scan-security' => array(
						'label'       => __( 'Scan Security', 'karmcp' ),
						'description' => __( 'Scans for malware heuristics, core file integrity, configuration hardening, and outdated/abandoned software; returns a scored report with recommendations.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-vulnerabilities' => array(
						'label'       => __( 'List Vulnerabilities', 'karmcp' ),
						'description' => __( 'Known vulnerabilities affecting the installed plugins and themes, with CVE, CVSS and the version that fixes each. Registers only when the Known Vulnerabilities module is enabled.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-fatal-log' => array(
						'label'       => __( 'Get Fatal Error Log', 'karmcp' ),
						'description' => __( 'Reads the fatal errors the KarMCP error handler recorded — message, file, line, and which plugin owns the file.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-paused-plugins' => array(
						'label'       => __( 'List Paused Plugins', 'karmcp' ),
						'description' => __( 'Lists plugins the fatal-error handler deactivated after repeated crashes.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/resume-plugin' => array(
						'label'       => __( 'Resume Paused Plugin', 'karmcp' ),
						'description' => __( 'Reactivates a plugin the fatal-error handler deactivated. Requires confirm.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-core' => array(
						'label'       => __( 'Update WordPress Core', 'karmcp' ),
						'description' => __( 'Updates WordPress itself. Replaces the code serving the request, so a failure takes the site down rather than returning an error. Requires confirm.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/harden-site' => array(
						'label'       => __( 'Harden Site', 'karmcp' ),
						'description' => __( 'Applies the configuration hardening that scan-security reports — file editor, XML-RPC, version disclosure, security headers. Dry-run by default; every fix is reversible.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/list-login-lockouts' => array(
						'label'       => __( 'List Login Lockouts', 'karmcp' ),
						'description' => __( 'Lists the sign-in lockouts currently in force. Registers only when the Login Guard module is enabled.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/clear-login-lockout' => array(
						'label'       => __( 'Clear Login Lockout', 'karmcp' ),
						'description' => __( 'Lifts a sign-in lockout for one IP address or username. Registers only when the Login Guard module is enabled.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'filesystem'       => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'Filesystem', 'karmcp' ),
				'tools' => array(
					'karmcp/read-file'      => array( 'label' => __( 'Read File', 'karmcp' ),      'description' => __( 'Read a file in the WordPress install.', 'karmcp' ),          'badges' => array( 'read-only' ) ),
					'karmcp/list-directory' => array( 'label' => __( 'List Directory', 'karmcp' ), 'description' => __( 'List a directory in the WordPress install.', 'karmcp' ),      'badges' => array( 'read-only' ) ),
					'karmcp/search-files'   => array( 'label' => __( 'Search Files', 'karmcp' ),   'description' => __( 'Search file contents across the install.', 'karmcp' ),        'badges' => array( 'read-only' ) ),
					'karmcp/write-file'     => array( 'label' => __( 'Write File', 'karmcp' ),     'description' => __( 'Create/overwrite a file (backs up first). Disabled by default.', 'karmcp' ), 'badges' => array() ),
					'karmcp/edit-file'      => array( 'label' => __( 'Edit File', 'karmcp' ),      'description' => __( 'Replace a string in a file (backs up first). Disabled by default.', 'karmcp' ),  'badges' => array() ),
					'karmcp/delete-file'    => array( 'label' => __( 'Delete File', 'karmcp' ),    'description' => __( 'Delete a file (backs up; needs confirm). Disabled by default.', 'karmcp' ),     'badges' => array() ),
				),
			),
			'database'         => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'Database', 'karmcp' ),
				'tools' => array(
					'karmcp/list-tables'    => array( 'label' => __( 'List Tables', 'karmcp' ),    'description' => __( 'List database tables with sizes.', 'karmcp' ),                'badges' => array( 'read-only' ) ),
					'karmcp/describe-table' => array( 'label' => __( 'Describe Table', 'karmcp' ), 'description' => __( 'Show a table\'s columns and keys.', 'karmcp' ),               'badges' => array( 'read-only' ) ),
					'karmcp/query'          => array( 'label' => __( 'Query (read-only)', 'karmcp' ), 'description' => __( 'Run a read-only SQL query (SELECT/SHOW/etc.).', 'karmcp' ), 'badges' => array( 'read-only' ) ),
					'karmcp/insert-row'     => array( 'label' => __( 'Insert Row', 'karmcp' ),     'description' => __( 'Insert a row (parameterized). Disabled by default.', 'karmcp' ),   'badges' => array() ),
					'karmcp/update-rows'    => array( 'label' => __( 'Update Rows', 'karmcp' ),    'description' => __( 'Update rows matching a WHERE. Disabled by default.', 'karmcp' ),   'badges' => array() ),
					'karmcp/delete-rows'    => array( 'label' => __( 'Delete Rows', 'karmcp' ),    'description' => __( 'Delete rows matching a WHERE (confirm). Disabled by default.', 'karmcp' ), 'badges' => array() ),
				),
			),
			'wpcli'            => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'WP-CLI', 'karmcp' ),
				'tools' => array(
					'karmcp/run-wp-cli'       => array( 'label' => __( 'Run WP-CLI Command', 'karmcp' ), 'description' => __( 'Run a wp-cli command (blocklist-guarded: no eval/shell/raw-SQL/config-writes). Disabled by default.', 'karmcp' ), 'badges' => array() ),
					'karmcp/dispatch-wp-cli'  => array( 'label' => __( 'Dispatch WP-CLI Job', 'karmcp' ), 'description' => __( 'Run a wp-cli command as a detached background job (long migrations / bulk tasks). Disabled by default.', 'karmcp' ), 'badges' => array() ),
					'karmcp/get-wp-cli-job'   => array( 'label' => __( 'Get WP-CLI Job', 'karmcp' ), 'description' => __( 'Poll a background job\'s status, exit code, and output.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
					'karmcp/list-wp-cli-jobs' => array( 'label' => __( 'List WP-CLI Jobs', 'karmcp' ), 'description' => __( 'List recent WP-CLI background jobs.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
				),
			),
			'transactions'     => array(
				'platform' => 'wordpress',
				'label' => __( 'Changes & Rollback', 'karmcp' ),
				'tools' => array(
					'karmcp/list-changes'    => array( 'label' => __( 'List Changes', 'karmcp' ),    'description' => __( 'List recent AI-made changes (Elementor/filesystem/database), newest first.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
					'karmcp/get-change'      => array( 'label' => __( 'Get Change', 'karmcp' ),      'description' => __( 'Full detail of one change-ledger entry, including its rollback reference.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
					'karmcp/rollback-change' => array( 'label' => __( 'Roll Back Change', 'karmcp' ), 'description' => __( 'Undo one recorded change by id (page/file/database). Only reverts changes KarMCP recorded.', 'karmcp' ), 'badges' => array() ),
				),
			),
			'search'           => array(
				'platform' => 'wordpress',
				'label' => __( 'Content Search', 'karmcp' ),
				'tools' => array(
					'karmcp/search-content'  => array( 'label' => __( 'Search Content', 'karmcp' ),  'description' => __( 'Search the site\'s pages, templates, widgets, and global styles to reuse existing content.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
					'karmcp/reindex-search'  => array( 'label' => __( 'Reindex Search', 'karmcp' ),  'description' => __( 'Rebuild the content-search index (also updates on save).', 'karmcp' ), 'badges' => array() ),
				),
			),
			'content_mirror'   => array(
				'platform' => 'wordpress',
				'label' => __( 'Content Mirror (Git)', 'karmcp' ),
				'tools' => array(
					'karmcp/export-content'        => array( 'label' => __( 'Export Content', 'karmcp' ),        'description' => __( 'Export page/template content to git-trackable JSON files.', 'karmcp' ), 'badges' => array() ),
					'karmcp/restore-content'       => array( 'label' => __( 'Restore Content', 'karmcp' ),       'description' => __( 'Restore a page/template from its mirror file (file-based undo).', 'karmcp' ), 'badges' => array() ),
					'karmcp/list-content-exports'  => array( 'label' => __( 'List Content Exports', 'karmcp' ), 'description' => __( 'List the mirror files on disk.', 'karmcp' ), 'badges' => array( 'read-only' ) ),
				),
			),
			'wp_packages'      => array(
				'platform' => 'wordpress',
				'label' => __( 'Plugins & Themes', 'karmcp' ),
				'tools' => array(
					'karmcp/list-plugins'      => array(
						'label'       => __( 'List Plugins', 'karmcp' ),
						'description' => __( 'Lists installed plugins, status, versions, and updates.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/search-plugins'    => array(
						'label'       => __( 'Search Plugins', 'karmcp' ),
						'description' => __( 'Searches the wordpress.org plugin directory.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/install-plugin'    => array(
						'label'       => __( 'Install Plugin', 'karmcp' ),
						'description' => __( 'Installs a plugin from wordpress.org by slug.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/activate-plugin'   => array(
						'label'       => __( 'Activate Plugin', 'karmcp' ),
						'description' => __( 'Activates an installed plugin.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/deactivate-plugin' => array(
						'label'       => __( 'Deactivate Plugin', 'karmcp' ),
						'description' => __( 'Deactivates a plugin (never KarMCP or Elementor).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-plugin'     => array(
						'label'       => __( 'Update Plugin', 'karmcp' ),
						'description' => __( 'Updates a plugin to the latest wordpress.org version.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-plugin'     => array(
						'label'       => __( 'Delete Plugin', 'karmcp' ),
						'description' => __( 'Permanently deletes an inactive plugin.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/list-themes'       => array(
						'label'       => __( 'List Themes', 'karmcp' ),
						'description' => __( 'Lists installed themes, active status, and updates.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/search-themes'     => array(
						'label'       => __( 'Search Themes', 'karmcp' ),
						'description' => __( 'Searches the wordpress.org theme directory.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/install-theme'     => array(
						'label'       => __( 'Install Theme', 'karmcp' ),
						'description' => __( 'Installs a theme from wordpress.org by slug.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/switch-theme'      => array(
						'label'       => __( 'Switch Theme', 'karmcp' ),
						'description' => __( 'Activates an installed theme.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-theme'      => array(
						'label'       => __( 'Update Theme', 'karmcp' ),
						'description' => __( 'Updates a theme to the latest wordpress.org version.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-theme'      => array(
						'label'       => __( 'Delete Theme', 'karmcp' ),
						'description' => __( 'Permanently deletes an inactive theme.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
				),
			),
			'wp_users'         => array(
				'platform' => 'wordpress',
				'label' => __( 'Users', 'karmcp' ),
				'tools' => array(
					'karmcp/list-users'   => array(
						'label'       => __( 'List Users', 'karmcp' ),
						'description' => __( 'Lists users (admin-only); filter by role/search.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-user'     => array(
						'label'       => __( 'Get User', 'karmcp' ),
						'description' => __( 'Returns one user\'s profile detail.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/create-user'  => array(
						'label'       => __( 'Create User', 'karmcp' ),
						'description' => __( 'Creates a non-admin user; auto-password + email.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-user'  => array(
						'label'       => __( 'Update User', 'karmcp' ),
						'description' => __( 'Edits a non-admin user\'s profile (no role/password; admins refused).', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'wp_acf'           => array(
				'platform' => 'plugins',
				'group'    => 'dynamic',
				'label'    => __( 'ACF (Advanced Custom Fields)', 'karmcp' ),
				'note'     => __( 'Plugin integrations are exposed as two tools, one Read, one Write. The AI calls a tool with an operation name; each tool bundles the operations listed on its card. Toggle a tool to allow or block all of its operations at once. Post-type & taxonomy operations need ACF 6.1+.', 'karmcp' ),
				'tools'    => array(
					'karmcp/acf-read'  => array(
						'label'       => __( 'ACF Read', 'karmcp' ),
						'description' => __( 'Read Advanced Custom Fields data, field groups, field values, options pages, and (ACF 6.1+) ACF-managed post types and taxonomies.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array(
							'list-field-groups',
							'get-field-group',
							'list-options-pages',
							'get-fields',
							'list-post-types',
							'get-post-type',
							'list-taxonomies',
							'get-taxonomy',
						),
					),
					'karmcp/acf-write' => array(
						'label'       => __( 'ACF Write', 'karmcp' ),
						'description' => __( 'Write Advanced Custom Fields data, field values, field groups, and (ACF 6.1+) ACF-managed post types and taxonomies. No delete operations; slugs and field keys are immutable.', 'karmcp' ),
						'badges'      => array(),
						'operations'  => array(
							'update-fields',
							'create-field-group',
							'update-field-group',
							'create-post-type',
							'update-post-type',
							'create-taxonomy',
							'update-taxonomy',
						),
					),
				),
			),
			'wp_woo'           => array(
				'platform' => 'plugins',
				'group'    => 'ecommerce',
				'pro'      => true,
				'label'    => __( 'WooCommerce', 'karmcp' ),
				'note'     => __( 'WooCommerce is exposed as two tools, one Read, one Write, scoped to the product catalog and the store setup a site builder needs. Orders, refunds and customers are deliberately not exposed: that is the money and personal-data surface, and building a site never needs it. The AI calls a tool with an operation name; toggle a tool to allow or block all of its operations at once. Deleting a product also requires confirm:true. Requires WooCommerce active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/woo-read'  => array(
						'label'            => __( 'WooCommerce Read', 'karmcp' ),
						'description'      => __( 'Read products, product categories, and the store setup (currency, base country, shop pages, catalog and stock settings). Call the tool with no operation to list them.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-products', 'get-product', 'list-product-categories', 'get-store-setup' ),
						'available'        => self::woo_available(),
						'unavailable_note' => __( 'Install & activate WooCommerce to enable this tool.', 'karmcp' ),
					),
					'karmcp/woo-write' => array(
						'label'            => __( 'WooCommerce Write', 'karmcp' ),
						'description'      => __( 'Create and update products (simple, grouped, external), set their categories and tags, and delete them. Prices are accepted in any human format and normalized. Deletes require confirm:true.', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'create-product', 'update-product', 'set-product-terms', 'delete-product' ),
						'available'        => self::woo_available(),
						'unavailable_note' => __( 'Install & activate WooCommerce to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_polylang'      => array(
				'platform' => 'plugins',
				'group'    => 'translation',
				'label'    => __( 'Polylang', 'karmcp' ),
				'note'     => __( 'Polylang exposed as two tools, one Read, one Write. Reads list the site\'s languages and tell you which translations of a page exist and which are missing; writes create a translation by duplicating the page, assigning its language and linking the pair, so the language switcher finds it. The copy carries the original text: translate it afterwards with the normal content tools.', 'karmcp' ),
				'tools'    => array(
					'karmcp/polylang-read'  => array(
						'label'            => __( 'Polylang Read', 'karmcp' ),
						'description'      => __( 'List configured languages and read a post\'s translation status.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-languages', 'get-translation-status' ),
						'available'        => self::polylang_available(),
						'unavailable_note' => __( 'Install & activate Polylang to enable this tool.', 'karmcp' ),
					),
					'karmcp/polylang-write' => array(
						'label'            => __( 'Polylang Write', 'karmcp' ),
						'description'      => __( 'Create a translation of a page, assign a post\'s language, and link existing posts as translations. Disabled by default.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'create-translation', 'set-post-language', 'link-translations' ),
						'available'        => self::polylang_available(),
						'unavailable_note' => __( 'Install & activate Polylang to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_wpml'          => array(
				'platform' => 'plugins',
				'group'    => 'translation',
				'label'    => __( 'WPML', 'karmcp' ),
				'note'     => __( 'WPML exposed as two tools, one Read, one Write, over its published hooks API. Same operations as the Polylang pair: read the languages and a page\'s translation status, create a translation by duplicating and linking, or link copies that already exist.', 'karmcp' ),
				'tools'    => array(
					'karmcp/wpml-read'  => array(
						'label'            => __( 'WPML Read', 'karmcp' ),
						'description'      => __( 'List configured languages and read a post\'s translation status.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-languages', 'get-translation-status' ),
						'available'        => self::wpml_available(),
						'unavailable_note' => __( 'Install & activate WPML to enable this tool.', 'karmcp' ),
					),
					'karmcp/wpml-write' => array(
						'label'            => __( 'WPML Write', 'karmcp' ),
						'description'      => __( 'Create a translation of a page, assign a post\'s language, and link existing posts as translations. Disabled by default.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'create-translation', 'set-post-language', 'link-translations' ),
						'available'        => self::wpml_available(),
						'unavailable_note' => __( 'Install & activate WPML to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_metabox'       => array(
				'platform' => 'plugins',
				'group'    => 'dynamic',
				'label'    => __( 'Meta Box', 'karmcp' ),
				'note'     => __( 'Plugin integrations are exposed as two tools, one Read, one Write. The AI calls a tool with an operation name; each tool bundles the operations listed on its card. Toggle a tool to allow or block all of its operations at once.', 'karmcp' ),
				'tools'    => array(
					'karmcp/metabox-read'  => array(
						'label'       => __( 'Meta Box Read', 'karmcp' ),
						'description' => __( 'Read Meta Box data, field groups, field definitions, and field values for posts and other supported object types.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array(
							'list-field-groups',
							'get-field-group',
							'get-fields',
						),
					),
					'karmcp/metabox-write' => array(
						'label'       => __( 'Meta Box Write', 'karmcp' ),
						'description' => __( 'Write Meta Box field values. No delete operations; unknown fields are skipped, not created.', 'karmcp' ),
						'badges'      => array(),
						'operations'  => array(
							'update-fields',
						),
					),
				),
			),
			'wp_ea'            => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Essential Addons for Elementor', 'karmcp' ),
				'note'     => __( 'Discovery for the Essential Addons widget pack. Its widgets are placed with the standard Add Free Widget tool, so this adds no widget-adding tool of its own, just the catalog and a readable schema (an addon widget can carry 400+ controls).', 'karmcp' ),
				'tools'    => array(
					'karmcp/essential-addons-read' => array(
						'label'            => __( 'Essential Addons Read', 'karmcp' ),
						'description'      => __( 'List Essential Addons widgets registered on this site and inspect a widget\'s content controls.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::essential_addons_available(),
						'unavailable_note' => __( 'Install & activate Essential Addons for Elementor to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_premium'       => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Premium Addons for Elementor', 'karmcp' ),
				'note'     => __( 'Discovery for the Premium Addons widget pack. As with Essential Addons, widgets are placed with the standard Add Free Widget tool; this supplies the catalog and a curated schema.', 'karmcp' ),
				'tools'    => array(
					'karmcp/premium-addons-read' => array(
						'label'            => __( 'Premium Addons Read', 'karmcp' ),
						'description'      => __( 'List Premium Addons widgets registered on this site and inspect a widget\'s content controls.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::premium_addons_available(),
						'unavailable_note' => __( 'Install & activate Premium Addons for Elementor to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_uae'           => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Ultimate Addons for Elementor', 'karmcp' ),
				'note'     => __( 'Ultimate Addons for Elementor (UAE, formerly Header Footer Elementor) exposed as two tools, one Read, one Write. UAE is both a widget pack and a template plugin: reads discover its widgets and list header/footer templates with their display conditions; writes create, update, retarget and delete templates. Widgets are placed, and template content built, with the normal Elementor tools. Delete requires confirm:true.', 'karmcp' ),
				'tools'    => array(
					'karmcp/uae-read'  => array(
						'label'            => __( 'Ultimate Addons for Elementor Read', 'karmcp' ),
						'description'      => self::uae_templates_available()
							? __( 'Discover UAE widgets, and list its header/footer/block templates with their type, status and display conditions.', 'karmcp' )
							: __( 'Discover the UAE widgets registered on this site and inspect the content controls of a widget.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => self::uae_templates_available()
							? array( 'list-widgets', 'get-widget-schema', 'list-templates', 'get-template' )
							: array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::uae_available(),
						'unavailable_note' => __( 'Install & activate Ultimate Addons for Elementor to enable this tool.', 'karmcp' ),
					),
					'karmcp/uae-write' => array(
						'label'            => __( 'Ultimate Addons for Elementor Write', 'karmcp' ),
						'description'      => __( 'Create, update, retarget and delete UAE templates. These render site-wide, so this tool is off by default and delete needs confirmation.', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'create-template', 'update-template', 'set-display-conditions', 'delete-template' ),
						'available'        => self::uae_templates_available(),
						'unavailable_note' => self::uae_pro_available()
							? __( 'UAE templates come from the free Ultimate Addons for Elementor plugin. UAE Pro on its own supplies widgets, which the Read tool already covers.', 'karmcp' )
							: __( 'Install & activate Ultimate Addons for Elementor to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_cf7'           => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'label'    => __( 'Contact Form 7', 'karmcp' ),
				'note'     => __( 'Contact Form 7 exposed as two tools, one Read, one Write. Reads list forms, fields, mail templates and messages; writes create forms from a field list and update mail, messages and settings. CF7 stores no submissions of its own, so list-entries reads them from Flamingo when that plugin is installed.', 'karmcp' ),
				'tools'    => array(
					'karmcp/cf7-read'  => array(
						'label'            => __( 'Contact Form 7 Read', 'karmcp' ),
						'description'      => __( 'Read CF7 forms, fields, mail templates, messages, settings, and stored submissions.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'get-settings', 'list-entries' ),
						'available'        => self::cf7_available(),
						'unavailable_note' => __( 'Install & activate Contact Form 7 to enable this tool.', 'karmcp' ),
					),
					'karmcp/cf7-write' => array(
						'label'            => __( 'Contact Form 7 Write', 'karmcp' ),
						'description'      => __( 'Create a CF7 form from a field list, and update mail templates, messages and additional settings.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'create-form', 'update-form', 'update-notification', 'update-messages', 'update-form-settings' ),
						'available'        => self::cf7_available(),
						'unavailable_note' => __( 'Install & activate Contact Form 7 to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_wpforms'       => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'WPForms', 'karmcp' ),
				'note'     => __( 'WPForms exposed as two tools, one Read, one Write. Reads cover forms, fields, notifications, and entries (entries require WPForms Pro); writes update settings/notifications and manage entries. Requires WPForms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/wpforms-read'  => array(
						'label'            => __( 'WPForms Read', 'karmcp' ),
						'description'      => __( 'Read WPForms forms, fields, notifications, and entries (entries require WPForms Pro).', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry', 'get-settings' ),
						'available'        => self::wpforms_available(),
						'unavailable_note' => __( 'Install & activate WPForms to enable this tool.', 'karmcp' ),
					),
					'karmcp/wpforms-write' => array(
						'label'            => __( 'WPForms Write', 'karmcp' ),
						'description'      => __( 'Update WPForms notifications, set entry status, and delete entries (confirm:true). Entry operations require WPForms Pro.', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-notification', 'update-entry-status', 'delete-entry' ),
						'available'        => self::wpforms_available(),
						'unavailable_note' => __( 'Install & activate WPForms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_gravityforms'  => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Gravity Forms', 'karmcp' ),
				'note'     => __( 'Gravity Forms exposed as two tools, one Read, one Write, over the GFAPI. Reads cover forms, fields, notifications, and entries; writes set entry status and delete entries. Requires Gravity Forms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/gravityforms-read'  => array(
						'label'            => __( 'Gravity Forms Read', 'karmcp' ),
						'description'      => __( 'Read Gravity Forms forms, fields, notifications, and entries.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry', 'get-settings' ),
						'available'        => self::gravityforms_available(),
						'unavailable_note' => __( 'Install & activate Gravity Forms to enable this tool.', 'karmcp' ),
					),
					'karmcp/gravityforms-write' => array(
						'label'            => __( 'Gravity Forms Write', 'karmcp' ),
						'description'      => __( 'Set Gravity Forms entry status (active/spam/trash) and delete entries (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::gravityforms_available(),
						'unavailable_note' => __( 'Install & activate Gravity Forms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_fluentforms'   => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Fluent Forms', 'karmcp' ),
				'note'     => __( 'Fluent Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, and submissions; writes set submission status and delete submissions. Requires Fluent Forms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/fluentforms-read'  => array(
						'label'            => __( 'Fluent Forms Read', 'karmcp' ),
						'description'      => __( 'Read Fluent Forms forms, fields, and submissions.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::fluentforms_available(),
						'unavailable_note' => __( 'Install & activate Fluent Forms to enable this tool.', 'karmcp' ),
					),
					'karmcp/fluentforms-write' => array(
						'label'            => __( 'Fluent Forms Write', 'karmcp' ),
						'description'      => __( 'Set Fluent Forms submission status and delete submissions (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::fluentforms_available(),
						'unavailable_note' => __( 'Install & activate Fluent Forms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_ninjaforms'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Ninja Forms', 'karmcp' ),
				'note'     => __( 'Ninja Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, and submissions; writes delete submissions. Requires Ninja Forms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/ninjaforms-read'  => array(
						'label'            => __( 'Ninja Forms Read', 'karmcp' ),
						'description'      => __( 'Read Ninja Forms forms, fields, and submissions.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::ninjaforms_available(),
						'unavailable_note' => __( 'Install & activate Ninja Forms to enable this tool.', 'karmcp' ),
					),
					'karmcp/ninjaforms-write' => array(
						'label'            => __( 'Ninja Forms Write', 'karmcp' ),
						'description'      => __( 'Delete Ninja Forms submissions (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::ninjaforms_available(),
						'unavailable_note' => __( 'Install & activate Ninja Forms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_formidable'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Formidable Forms', 'karmcp' ),
				'note'     => __( 'Formidable Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, notifications, and entries; writes update notifications and delete entries. Requires Formidable Forms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/formidable-read'  => array(
						'label'            => __( 'Formidable Forms Read', 'karmcp' ),
						'description'      => __( 'Read Formidable forms, fields, notifications, and entries.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry' ),
						'available'        => self::formidable_available(),
						'unavailable_note' => __( 'Install & activate Formidable Forms to enable this tool.', 'karmcp' ),
					),
					'karmcp/formidable-write' => array(
						'label'            => __( 'Formidable Forms Write', 'karmcp' ),
						'description'      => __( 'Delete Formidable entries (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::formidable_available(),
						'unavailable_note' => __( 'Install & activate Formidable Forms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_metform'       => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'MetForm', 'karmcp' ),
				'note'     => __( 'MetForm exposed as two tools, one Read, one Write. Reads cover forms, fields, and entries; writes delete entries. Requires MetForm (and Elementor) active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/metform-read'  => array(
						'label'            => __( 'MetForm Read', 'karmcp' ),
						'description'      => __( 'Read MetForm forms, fields, and entries.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::metform_available(),
						'unavailable_note' => __( 'Install & activate MetForm to enable this tool.', 'karmcp' ),
					),
					'karmcp/metform-write' => array(
						'label'            => __( 'MetForm Write', 'karmcp' ),
						'description'      => __( 'Delete MetForm entries (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::metform_available(),
						'unavailable_note' => __( 'Install & activate MetForm to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_sureforms'     => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'SureForms', 'karmcp' ),
				'note'     => __( 'SureForms exposed as two tools, one Read, one Write. Reads cover forms, fields, and entries; writes set entry status and delete entries. Requires SureForms active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/sureforms-read'  => array(
						'label'            => __( 'SureForms Read', 'karmcp' ),
						'description'      => __( 'Read SureForms forms, fields, and entries.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::sureforms_available(),
						'unavailable_note' => __( 'Install & activate SureForms to enable this tool.', 'karmcp' ),
					),
					'karmcp/sureforms-write' => array(
						'label'            => __( 'SureForms Write', 'karmcp' ),
						'description'      => __( 'Set SureForms entry status and delete entries (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::sureforms_available(),
						'unavailable_note' => __( 'Install & activate SureForms to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_forminator'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Forminator', 'karmcp' ),
				'note'     => __( 'Forminator exposed as two tools, one Read, one Write. Reads cover forms (id, name, shortcode, fields) and submissions; writes delete a submission. Requires Forminator active.', 'karmcp' ),
				'tools'    => array(
					'karmcp/forminator-read'  => array(
						'label'            => __( 'Forminator Read', 'karmcp' ),
						'description'      => __( 'Read Forminator forms, fields, shortcodes, and submissions.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::forminator_available(),
						'unavailable_note' => __( 'Install & activate Forminator to enable this tool.', 'karmcp' ),
					),
					'karmcp/forminator-write' => array(
						'label'            => __( 'Forminator Write', 'karmcp' ),
						'description'      => __( 'Delete a Forminator submission (confirm:true).', 'karmcp' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::forminator_available(),
						'unavailable_note' => __( 'Install & activate Forminator to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_slimseo'       => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'label'    => __( 'Slim SEO', 'karmcp' ),
				'note'     => __( 'Slim SEO exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) Slim SEO stores for posts and terms, plus its site settings.', 'karmcp' ),
				'tools'    => array(
					'karmcp/slimseo-read'  => array(
						'label'            => __( 'Slim SEO Read', 'karmcp' ),
						'description'      => __( 'Read Slim SEO post/term SEO metadata and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::slimseo_available(),
						'unavailable_note' => __( 'Install & activate Slim SEO to enable this tool.', 'karmcp' ),
					),
					'karmcp/slimseo-write' => array(
						'label'            => __( 'Slim SEO Write', 'karmcp' ),
						'description'      => __( 'Update Slim SEO post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::slimseo_available(),
						'unavailable_note' => __( 'Install & activate Slim SEO to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_yoast'         => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'Yoast SEO', 'karmcp' ),
				'note'     => __( 'Yoast SEO exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social, focus keyword) Yoast stores for posts and terms, plus site settings.', 'karmcp' ),
				'tools'    => array(
					'karmcp/yoast-read'  => array(
						'label'            => __( 'Yoast SEO Read', 'karmcp' ),
						'description'      => __( 'Read Yoast post/term SEO metadata and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::yoast_available(),
						'unavailable_note' => __( 'Install & activate Yoast SEO to enable this tool.', 'karmcp' ),
					),
					'karmcp/yoast-write' => array(
						'label'            => __( 'Yoast SEO Write', 'karmcp' ),
						'description'      => __( 'Update Yoast post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::yoast_available(),
						'unavailable_note' => __( 'Install & activate Yoast SEO to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_rankmath'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'Rank Math', 'karmcp' ),
				'note'     => __( 'Rank Math exposed as two tools, one Read, one Write. Read/write post & term SEO metadata and site settings; also read schema (structured data).', 'karmcp' ),
				'tools'    => array(
					'karmcp/rankmath-read'  => array(
						'label'            => __( 'Rank Math Read', 'karmcp' ),
						'description'      => __( 'Read Rank Math post/term SEO metadata, schema, and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-schema', 'get-settings' ),
						'available'        => self::rankmath_available(),
						'unavailable_note' => __( 'Install & activate Rank Math to enable this tool.', 'karmcp' ),
					),
					'karmcp/rankmath-write' => array(
						'label'            => __( 'Rank Math Write', 'karmcp' ),
						'description'      => __( 'Update Rank Math post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::rankmath_available(),
						'unavailable_note' => __( 'Install & activate Rank Math to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_aioseo'        => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'All in One SEO', 'karmcp' ),
				'note'     => __( 'All in One SEO exposed as two tools, one Read, one Write. Read/write post SEO metadata and read schema (structured data) + site settings.', 'karmcp' ),
				'tools'    => array(
					'karmcp/aioseo-read'  => array(
						'label'            => __( 'All in One SEO Read', 'karmcp' ),
						'description'      => __( 'Read AIOSEO post SEO metadata, schema, and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-schema', 'get-settings' ),
						'available'        => self::aioseo_available(),
						'unavailable_note' => __( 'Install & activate All in One SEO to enable this tool.', 'karmcp' ),
					),
					'karmcp/aioseo-write' => array(
						'label'            => __( 'All in One SEO Write', 'karmcp' ),
						'description'      => __( 'Update AIOSEO post SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo' ),
						'available'        => self::aioseo_available(),
						'unavailable_note' => __( 'Install & activate All in One SEO to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_seopress'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'SEOPress', 'karmcp' ),
				'note'     => __( 'SEOPress exposed as two tools, one Read, one Write. Read/write post & term SEO metadata and site settings; also read schema (structured data).', 'karmcp' ),
				'tools'    => array(
					'karmcp/seopress-read'  => array(
						'label'            => __( 'SEOPress Read', 'karmcp' ),
						'description'      => __( 'Read SEOPress post/term SEO metadata, schema, and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings', 'get-schema' ),
						'available'        => self::seopress_available(),
						'unavailable_note' => __( 'Install & activate SEOPress to enable this tool.', 'karmcp' ),
					),
					'karmcp/seopress-write' => array(
						'label'            => __( 'SEOPress Write', 'karmcp' ),
						'description'      => __( 'Update SEOPress post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::seopress_available(),
						'unavailable_note' => __( 'Install & activate SEOPress to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_seoframework'  => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'The SEO Framework', 'karmcp' ),
				'note'     => __( 'The SEO Framework exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) it stores for posts and terms.', 'karmcp' ),
				'tools'    => array(
					'karmcp/seoframework-read'  => array(
						'label'            => __( 'The SEO Framework Read', 'karmcp' ),
						'description'      => __( 'Read The SEO Framework post/term SEO metadata.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo' ),
						'available'        => self::seoframework_available(),
						'unavailable_note' => __( 'Install & activate The SEO Framework to enable this tool.', 'karmcp' ),
					),
					'karmcp/seoframework-write' => array(
						'label'            => __( 'The SEO Framework Write', 'karmcp' ),
						'description'      => __( 'Update The SEO Framework post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::seoframework_available(),
						'unavailable_note' => __( 'Install & activate The SEO Framework to enable this tool.', 'karmcp' ),
					),
				),
			),
			'wp_surerank'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'SureRank', 'karmcp' ),
				'note'     => __( 'SureRank exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) SureRank stores for posts and terms.', 'karmcp' ),
				'tools'    => array(
					'karmcp/surerank-read'  => array(
						'label'            => __( 'SureRank Read', 'karmcp' ),
						'description'      => __( 'Read SureRank post/term SEO metadata and site settings.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::surerank_available(),
						'unavailable_note' => __( 'Install & activate SureRank to enable this tool.', 'karmcp' ),
					),
					'karmcp/surerank-write' => array(
						'label'            => __( 'SureRank Write', 'karmcp' ),
						'description'      => __( 'Update SureRank post/term SEO metadata.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::surerank_available(),
						'unavailable_note' => __( 'Install & activate SureRank to enable this tool.', 'karmcp' ),
					),
				),
			),
			'theme_active'     => array(
				'platform' => 'themes',
				'label'    => __( 'Active Theme', 'karmcp' ),
				'note'     => __( 'Theme integrations are exposed as two tools, one Read, one Write, that bundle internal operations. The AI calls a tool with an operation name; toggle a tool to allow or block all of its operations at once.', 'karmcp' ),
				'tools'    => array(
					'karmcp/theme-read'  => array(
						'label'       => __( 'Theme Read', 'karmcp' ),
						'description' => __( 'Read the active theme: context (framework, block-theme, supports, menu locations, child status) and theme_mod values.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array( 'get-theme-context', 'get-mods' ),
					),
					'karmcp/theme-write' => array(
						'label'       => __( 'Theme Write', 'karmcp' ),
						'description' => __( 'Set theme_mod values and create + activate a child theme so the agent can edit theme files (create-child-theme requires confirm:true).', 'karmcp' ),
						'badges'      => array(),
						'operations'  => array( 'set-mods', 'create-child-theme' ),
					),
				),
			),
			'theme_astra_spectra' => array(
				'platform' => 'themes',
				'label'    => __( 'Astra + Spectra', 'karmcp' ),
				'note'     => __( 'The Astra theme and its Spectra blocks companion, grouped as one pack. Astra tools manage the theme\'s settings (enabled only when Astra is the active theme); Spectra tools give the block catalog + insertion (enabled only when the Spectra plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'karmcp' ),
				'notice'   => self::spectra_file_generation_notice(),
				'tools'    => array(
					'karmcp/astra-read'    => array(
						'label'            => __( 'Astra Read', 'karmcp' ),
						'description'      => __( 'Read Astra settings (colors, typography, layout, header/footer) with value + type/label/group metadata.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::astra_available(),
						'unavailable_note' => __( 'Activate the Astra theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/astra-write'   => array(
						'label'            => __( 'Astra Write', 'karmcp' ),
						'description'      => __( 'Write Astra settings; non-allowlisted keys are reported in skipped[].', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-settings' ),
						'available'        => self::astra_available(),
						'unavailable_note' => __( 'Activate the Astra theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/spectra-read'  => array(
						'label'            => __( 'Spectra Read', 'karmcp' ),
						'description'      => __( 'Catalog of available Spectra blocks (list-blocks) and each block\'s real attributes + example markup (get-block-schema).', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::spectra_available(),
						'unavailable_note' => __( 'Install & activate the Spectra plugin to enable this tool.', 'karmcp' ),
					),
					'karmcp/spectra-write' => array(
						'label'            => __( 'Spectra Write', 'karmcp' ),
						'description'      => __( 'Insert a Spectra block into a post with a generated block_id (add-block); Spectra applies its own defaults.', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'add-block' ),
						'available'        => self::spectra_available(),
						'unavailable_note' => __( 'Install & activate the Spectra plugin to enable this tool.', 'karmcp' ),
					),
				),
			),
			'theme_kadence'    => array(
				'platform' => 'themes',
				'label'    => __( 'Kadence + Kadence Blocks', 'karmcp' ),
				'note'     => __( 'The Kadence theme and its Kadence Blocks companion, grouped as one pack. Kadence tools manage the theme\'s settings (enabled only when Kadence is the active theme); Kadence Blocks tools give the block catalog + insertion (enabled only when the Kadence Blocks plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'karmcp' ),
				'tools'    => array(
					'karmcp/kadence-read'         => array(
						'label'            => __( 'Kadence Read', 'karmcp' ),
						'description'      => __( 'Read Kadence settings (palette, colors, typography, layout, buttons, header/footer) with value + type/label/group/shape metadata.', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::kadence_available(),
						'unavailable_note' => __( 'Activate the Kadence theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/kadence-write'        => array(
						'label'            => __( 'Kadence Write', 'karmcp' ),
						'description'      => __( 'Write Kadence settings as theme_mods; non-allowlisted keys are reported in skipped[].', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'update-settings' ),
						'available'        => self::kadence_available(),
						'unavailable_note' => __( 'Activate the Kadence theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/kadence-blocks-read'  => array(
						'label'            => __( 'Kadence Blocks Read', 'karmcp' ),
						'description'      => __( 'Catalog of available Kadence blocks (list-blocks) and each block\'s real attributes + example markup (get-block-schema).', 'karmcp' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::kadence_blocks_available(),
						'unavailable_note' => __( 'Install & activate the Kadence Blocks plugin to enable this tool.', 'karmcp' ),
					),
					'karmcp/kadence-blocks-write' => array(
						'label'            => __( 'Kadence Blocks Write', 'karmcp' ),
						'description'      => __( 'Insert a Kadence block into a post with a generated uniqueID + scaffolded inner blocks (add-block).', 'karmcp' ),
						'badges'           => array(),
						'operations'       => array( 'add-block' ),
						'available'        => self::kadence_blocks_available(),
						'unavailable_note' => __( 'Install & activate the Kadence Blocks plugin to enable this tool.', 'karmcp' ),
					),
				),
			),
			'theme_generatepress' => array(
				'platform' => 'themes',
				'label'    => __( 'GeneratePress + GenerateBlocks', 'karmcp' ),
				'note'     => __( 'The GeneratePress theme and its GenerateBlocks companion (Pro). GeneratePress tools manage the theme\'s settings (enabled only when GeneratePress is the active theme); GenerateBlocks tools give the block catalog + insertion (enabled only when the GenerateBlocks plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'karmcp' ),
				'tools'    => array(
					'karmcp/generatepress-read'   => array(
						'label'            => __( 'GeneratePress Read', 'karmcp' ),
						'description'      => __( 'Read GeneratePress settings (global palette, colors, layout, typography) with value + type/label/group/shape metadata.', 'karmcp' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::generatepress_available(),
						'unavailable_note' => __( 'Activate the GeneratePress theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/generatepress-write'  => array(
						'label'            => __( 'GeneratePress Write', 'karmcp' ),
						'description'      => __( 'Write GeneratePress settings; non-allowlisted keys are reported in skipped[].', 'karmcp' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'update-settings' ),
						'available'        => self::generatepress_available(),
						'unavailable_note' => __( 'Activate the GeneratePress theme to enable this tool.', 'karmcp' ),
					),
					'karmcp/generateblocks-read'  => array(
						'label'            => __( 'GenerateBlocks Read', 'karmcp' ),
						'description'      => __( 'Catalog of the GenerateBlocks V2 blocks (list-blocks) and each block\'s attributes + styles model (get-block-schema).', 'karmcp' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::generateblocks_available(),
						'unavailable_note' => __( 'Install & activate the GenerateBlocks plugin to enable this tool.', 'karmcp' ),
					),
					'karmcp/generateblocks-write' => array(
						'label'            => __( 'GenerateBlocks Write', 'karmcp' ),
						'description'      => __( 'Insert a GenerateBlocks V2 block with a generated uniqueId, styles object + compiled css, and content (add-block).', 'karmcp' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'add-block' ),
						'available'        => self::generateblocks_available(),
						'unavailable_note' => __( 'Install & activate the GenerateBlocks plugin to enable this tool.', 'karmcp' ),
					),
				),
			),
			'theme_blocksy'    => array(
				'platform' => 'themes',
				'label'    => __( 'Blocksy', 'karmcp' ),
				'note'     => __( 'Blocksy (Pro): its dynamic content blocks (query/tax-query loops, dynamic-data, about-me, socials, share-box, breadcrumbs, …) and its Blocksy Companion extensions (activate/deactivate). Enabled when Blocksy Companion is active. Theme settings are reachable via the free Active Theme tools (theme-read/theme-write).', 'karmcp' ),
				'tools'    => array(
					'karmcp/blocksy-blocks-read'      => array(
						'label'            => __( 'Blocksy Blocks Read', 'karmcp' ),
						'description'      => __( 'Catalog of the Blocksy blocks (list-blocks) and each block\'s attributes (get-block-schema).', 'karmcp' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::blocksy_blocks_available(),
						'unavailable_note' => __( 'Install & activate Blocksy Companion to enable this tool.', 'karmcp' ),
					),
					'karmcp/blocksy-blocks-write'     => array(
						'label'            => __( 'Blocksy Blocks Write', 'karmcp' ),
						'description'      => __( 'Insert a Blocksy block into a post (add-block); query/tax-query get a scaffolded template child.', 'karmcp' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'add-block' ),
						'available'        => self::blocksy_blocks_available(),
						'unavailable_note' => __( 'Install & activate Blocksy Companion to enable this tool.', 'karmcp' ),
					),
					'karmcp/blocksy-extensions-read'  => array(
						'label'            => __( 'Blocksy Extensions Read', 'karmcp' ),
						'description'      => __( 'List Blocksy Companion extensions with name, description, pro flag, and active status (list-extensions).', 'karmcp' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-extensions' ),
						'available'        => self::blocksy_extensions_available(),
						'unavailable_note' => __( 'Install & activate Blocksy Companion to enable this tool.', 'karmcp' ),
					),
					'karmcp/blocksy-extensions-write' => array(
						'label'            => __( 'Blocksy Extensions Write', 'karmcp' ),
						'description'      => __( 'Activate or deactivate a Blocksy Companion extension by slug.', 'karmcp' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'activate-extension', 'deactivate-extension' ),
						'available'        => self::blocksy_extensions_available(),
						'unavailable_note' => __( 'Install & activate Blocksy Companion to enable this tool.', 'karmcp' ),
					),
				),
			),
			'page'             => array(
				'platform' => 'elementor',
				'label' => __( 'Page Management', 'karmcp' ),
				'tools' => array(
					'karmcp/create-page'          => array(
						'label'       => __( 'Create Page', 'karmcp' ),
						'description' => __( 'Creates a new WordPress page with Elementor enabled.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-page-settings' => array(
						'label'       => __( 'Update Page Settings', 'karmcp' ),
						'description' => __( 'Updates Elementor page-level settings (layout, canvas, etc).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-page-content'  => array(
						'label'       => __( 'Delete Page Content', 'karmcp' ),
						'description' => __( 'Removes all Elementor content from a page.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/import-template'      => array(
						'label'       => __( 'Import Template', 'karmcp' ),
						'description' => __( 'Imports an Elementor template JSON into a page.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/export-page'          => array(
						'label'       => __( 'Export Page', 'karmcp' ),
						'description' => __( 'Exports a page\'s Elementor data as JSON.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'layout'           => array(
				'platform' => 'elementor',
				'label' => __( 'Layout & Structure', 'karmcp' ),
				'tools' => array(
					'karmcp/add-container'     => array(
						'label'       => __( 'Add Container', 'karmcp' ),
						'description' => __( 'Adds a new flexbox container to a page or inside another container.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/move-element'      => array(
						'label'       => __( 'Move Element', 'karmcp' ),
						'description' => __( 'Moves an element to a new parent or position.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/remove-element'    => array(
						'label'       => __( 'Remove Element', 'karmcp' ),
						'description' => __( 'Removes an element and all its children from the page.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/duplicate-element'    => array(
						'label'       => __( 'Duplicate Element', 'karmcp' ),
						'description' => __( 'Creates a deep copy of an element and inserts it after the original.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-container'     => array(
						'label'       => __( 'Update Container', 'karmcp' ),
						'description' => __( 'Updates settings on an existing container element.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/get-container-schema' => array(
						'label'       => __( 'Get Container Schema', 'karmcp' ),
						'description' => __( 'Returns the JSON schema for container settings.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/find-element'         => array(
						'label'       => __( 'Find Element', 'karmcp' ),
						'description' => __( 'Finds elements by type, settings, or CSS class within a page.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/update-element'       => array(
						'label'       => __( 'Update Element', 'karmcp' ),
						'description' => __( 'Updates settings on any element (widget or container) by ID. Also writes v4 atomic styles / editor_settings when included.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/batch-update'         => array(
						'label'       => __( 'Batch Update', 'karmcp' ),
						'description' => __( 'Applies multiple element updates in a single call.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/set-element-label'    => array(
						'label'       => __( 'Set Element Label', 'karmcp' ),
						'description' => __( 'Sets an element\'s Navigator label (editor_settings.title).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/reorder-elements'     => array(
						'label'       => __( 'Reorder Elements', 'karmcp' ),
						'description' => __( 'Reorders child elements within a container.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'widgets'          => array(
				'platform' => 'elementor',
				'label' => __( 'Widgets', 'karmcp' ),
				'tools' => array(
					'karmcp/add-free-widget' => array(
						'label'       => __( 'Add Widget', 'karmcp' ),
						'description' => __( 'Adds any free/core Elementor widget by type (discover with list-widgets / get-widget-schema).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-pro-widget'  => array(
						'label'       => __( 'Add Pro Widget', 'karmcp' ),
						'description' => __( 'Adds an Elementor Pro / WooCommerce widget by type. Registers only when Elementor Pro is active.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/update-widget'   => array(
						'label'       => __( 'Update Widget', 'karmcp' ),
						'description' => __( 'Updates settings on an existing widget (partial merge).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-contact-form' => array(
						'label'       => __( 'Add Contact Form', 'karmcp' ),
						'description' => __( 'Adds a working Elementor Pro form from a plain field list, wiring the notification email, reply-to and submit button. Registers only when the Form widget is available.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
				),
			),
			'template'         => array(
				'platform' => 'elementor',
				'label' => __( 'Templates', 'karmcp' ),
				'tools' => array(
					'karmcp/save-as-template' => array(
						'label'       => __( 'Save as Template', 'karmcp' ),
						'description' => __( 'Saves the current page content as a reusable template.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/apply-template'       => array(
						'label'       => __( 'Apply Template', 'karmcp' ),
						'description' => __( 'Applies a saved template to a target page.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/create-elementor-theme-template' => array(
						'label'       => __( 'Create Elementor Theme Template', 'karmcp' ),
						'description' => __( 'Creates a native Elementor Pro theme builder template (header, footer, single, archive, etc). For the builder-agnostic KarMCP Themer, use create-theme-template.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/set-elementor-template-conditions' => array(
						'label'       => __( 'Set Elementor Template Conditions', 'karmcp' ),
						'description' => __( 'Sets display conditions on a native Elementor Pro theme builder template.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/list-dynamic-tags'    => array(
						'label'       => __( 'List Dynamic Tags', 'karmcp' ),
						'description' => __( 'Lists all available dynamic tags and their categories.', 'karmcp' ),
						'badges'      => array( 'elementor-pro', 'read-only' ),
					),
					'karmcp/set-dynamic-tag'      => array(
						'label'       => __( 'Set Dynamic Tag', 'karmcp' ),
						'description' => __( 'Sets a dynamic tag on a specific element setting.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/create-popup'         => array(
						'label'       => __( 'Create Popup', 'karmcp' ),
						'description' => __( 'Creates an Elementor popup template.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/set-popup-settings'   => array(
						'label'       => __( 'Set Popup Settings', 'karmcp' ),
						'description' => __( 'Sets triggers, conditions, and timing on a popup template.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
				),
			),
			'global'           => array(
				'platform' => 'elementor',
				'label' => __( 'Global Settings', 'karmcp' ),
				'tools' => array(
					'karmcp/update-global-colors'     => array(
						'label'       => __( 'Update Global Colors', 'karmcp' ),
						'description' => __( 'Updates the site-wide Elementor color palette.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-global-typography' => array(
						'label'       => __( 'Update Global Typography', 'karmcp' ),
						'description' => __( 'Updates the site-wide Elementor typography presets.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'composite'        => array(
				'platform' => 'elementor',
				'label' => __( 'Composite', 'karmcp' ),
				'tools' => array(
					'karmcp/build-page' => array(
						'label'       => __( 'Build Page', 'karmcp' ),
						'description' => __( 'Creates a complete page from a declarative structure in one call.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'stock_images'     => array(
				'platform' => 'wordpress',
				'label' => __( 'Stock & Media Images', 'karmcp' ),
				'tools' => array(
					'karmcp/list-media'       => array(
						'label'       => __( 'List Media', 'karmcp' ),
						'description' => __( 'Lists and searches images already in the WordPress Media Library (the site\'s own uploads).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-media'        => array(
						'label'       => __( 'Get Media', 'karmcp' ),
						'description' => __( 'Full detail of one attachment (sizes, metadata, alt/caption).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/upload-media'     => array(
						'label'       => __( 'Upload Media', 'karmcp' ),
						'description' => __( 'Uploads a file from the client machine into the Media Library (bytes sent as base64). The companion to Sideload Image, which fetches a URL the server can reach.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-media'     => array(
						'label'       => __( 'Update Media', 'karmcp' ),
						'description' => __( 'Edit an attachment\'s alt text, title, caption, description.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/resize-media'     => array(
						'label'       => __( 'Resize Media', 'karmcp' ),
						'description' => __( 'Resize a Media Library image in place (scale to fit or crop), reversible via backup. Registers only when the Image Optimization module is enabled.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-media'     => array(
						'label'       => __( 'Delete Media', 'karmcp' ),
						'description' => __( 'Delete an attachment (permanent; requires confirm).', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/search-images'    => array(
						'label'       => __( 'Search Images', 'karmcp' ),
						'description' => __( 'Searches a stock-photo provider (Unsplash, Pexels, or Pixabay) for images. Core WordPress tool, available without Elementor. Needs a free provider API key (Connection tab).', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/sideload-image'   => array(
						'label'       => __( 'Sideload Image', 'karmcp' ),
						'description' => __( 'Downloads an external image URL into the WordPress Media Library. Core WordPress tool, available without Elementor.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-stock-image'  => array(
						'label'       => __( 'Add Stock Image', 'karmcp' ),
						'description' => __( 'Searches, downloads, and adds a stock image to the page in one call.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'svg_icons'        => array(
				'platform' => 'elementor',
				'label' => __( 'SVG Icons', 'karmcp' ),
				'tools' => array(
					'karmcp/upload-svg-icon'  => array(
						'label'       => __( 'Upload SVG Icon', 'karmcp' ),
						'description' => __( 'Uploads an SVG icon (from URL or raw markup) for use with icon/icon-box widgets.', 'karmcp' ),
						'badges'      => array(),
					),
				),
			),
			'custom_code'      => array(
				'platform' => 'elementor',
				'label' => __( 'Custom Code', 'karmcp' ),
				'tools' => array(
					'karmcp/add-custom-css'     => array(
						'label'       => __( 'Add Custom CSS', 'karmcp' ),
						'description' => __( 'Adds custom CSS to a specific element or the entire page.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/add-custom-js'      => array(
						'label'       => __( 'Add Custom JavaScript', 'karmcp' ),
						'description' => __( 'Adds a JavaScript snippet to a page via an HTML widget.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-code-snippet'   => array(
						'label'       => __( 'Add Code Snippet', 'karmcp' ),
						'description' => __( 'Creates a site-wide Custom Code snippet for head/body injection.', 'karmcp' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'karmcp/list-code-snippets' => array(
						'label'       => __( 'List Code Snippets', 'karmcp' ),
						'description' => __( 'Lists all existing Custom Code snippets.', 'karmcp' ),
						'badges'      => array( 'elementor-pro', 'read-only' ),
					),
				),
			),
		);

		// Atomic elements (Elementor 4.0+). The underlying abilities are only
		// registered when Elementor >= 4.0 is active, so we mirror that gate
		// here to avoid showing toggles for tools that don't exist.
		if ( class_exists( 'KarMCP_Atomic_Props' ) && KarMCP_Atomic_Props::is_atomic_supported() ) {
			$tools['atomic_layout'] = array(
				'platform' => 'elementor',
				'label' => __( 'Atomic Layout (Elementor 4.0+)', 'karmcp' ),
				'tools' => array(
					'karmcp/detect-elementor-version' => array(
						'label'       => __( 'Detect Elementor Version', 'karmcp' ),
						'description' => __( 'Returns the Elementor version and whether atomic elements are supported.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/list-global-classes'      => array(
						'label'       => __( 'List Global Classes', 'karmcp' ),
						'description' => __( 'Resolves Class Manager "g-" class IDs to their names and CSS properties.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/create-global-class'      => array(
						'label'       => __( 'Create Global Class', 'karmcp' ),
						'description' => __( 'Create an Elementor v4 Global Class with a label + styles; returns the new g- id.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-global-class'      => array(
						'label'       => __( 'Update Global Class', 'karmcp' ),
						'description' => __( 'Update a Global Class label and/or its styles (per breakpoint/state).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-global-class'      => array(
						'label'       => __( 'Delete Global Class', 'karmcp' ),
						'description' => __( 'Delete a Global Class by g- id (also removes it from elements using it); requires confirm:true.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/reorder-global-classes'   => array(
						'label'       => __( 'Reorder Global Classes', 'karmcp' ),
						'description' => __( 'Set the Class Manager order (= CSS source order / specificity) of the v4 Global Classes.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-flexbox'              => array(
						'label'       => __( 'Add Flexbox', 'karmcp' ),
						'description' => __( 'Adds an atomic flexbox container (e-flexbox).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-div-block'            => array(
						'label'       => __( 'Add Div Block', 'karmcp' ),
						'description' => __( 'Adds an atomic div-block container (e-div-block).', 'karmcp' ),
						'badges'      => array(),
					),
				),
			);

			$tools['atomic_widgets'] = array(
				'platform' => 'elementor',
				'label' => __( 'Atomic Widgets (Elementor 4.0+)', 'karmcp' ),
				'tools' => array(
					'karmcp/add-atomic-widget'    => array(
						'label'       => __( 'Add Atomic Widget', 'karmcp' ),
						'description' => __( 'Universal: adds any atomic widget by type with raw $$type settings.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/update-atomic-widget' => array(
						'label'       => __( 'Update Atomic Widget', 'karmcp' ),
						'description' => __( 'Universal: partial-merge update on an existing atomic widget.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-heading'   => array(
						'label'       => __( 'Add Atomic Heading', 'karmcp' ),
						'description' => __( 'Adds an atomic heading element (e-heading).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-paragraph' => array(
						'label'       => __( 'Add Atomic Paragraph', 'karmcp' ),
						'description' => __( 'Adds an atomic paragraph element (e-paragraph).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-button'    => array(
						'label'       => __( 'Add Atomic Button', 'karmcp' ),
						'description' => __( 'Adds an atomic button element (e-button).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-image'     => array(
						'label'       => __( 'Add Atomic Image', 'karmcp' ),
						'description' => __( 'Adds an atomic image element (e-image).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-svg'       => array(
						'label'       => __( 'Add Atomic SVG', 'karmcp' ),
						'description' => __( 'Adds an atomic SVG element (e-svg).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-youtube'   => array(
						'label'       => __( 'Add Atomic YouTube', 'karmcp' ),
						'description' => __( 'Adds an atomic YouTube embed (e-youtube).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-video'     => array(
						'label'       => __( 'Add Atomic Video', 'karmcp' ),
						'description' => __( 'Adds an atomic self-hosted video (e-self-hosted-video).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/add-atomic-divider'   => array(
						'label'       => __( 'Add Atomic Divider', 'karmcp' ),
						'description' => __( 'Adds an atomic divider element (e-divider).', 'karmcp' ),
						'badges'      => array(),
					),
				),
			);
		}

		// Brand Kits (Pro). Only shown to licensed sites — the underlying
		// abilities register only for Pro, matching this gate. No 'pro' badge so
		// they are NOT auto-disabled by maybe_apply_default_disabled_tools (this
		// is a headline Pro feature, on by default for licensed users).
		if (
			class_exists( 'KarMCP_Pro_Brand_Kits' )
			&& KarMCP_Pro_Brand_Kits::user_has_access()
		) {
			$tools['brand_kits'] = array(
				'platform' => 'elementor',
				'label' => __( 'Brand Kits', 'karmcp' ),
				'tools' => array(
					'karmcp/list-brand-kits'           => array(
						'label'       => __( 'List Brand Kits', 'karmcp' ),
						'description' => __( 'Lists available premium brand kits from the cached library.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/apply-brand-kit'           => array(
						'label'       => __( 'Apply Brand Kit', 'karmcp' ),
						'description' => __( 'Applies a brand kit: replaces system colors + typography site-wide.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/replace-system-colors'     => array(
						'label'       => __( 'Replace System Colors', 'karmcp' ),
						'description' => __( 'Replaces the four Elementor system color slots atomically.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
					'karmcp/replace-system-typography' => array(
						'label'       => __( 'Replace System Typography', 'karmcp' ),
						'description' => __( 'Replaces the four Elementor system typography slots atomically.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
				),
			);
		}

		// PHP Code Snippets (Sandbox) — free, but capability-gated and powerful,
		// so all six ship disabled-by-default (maybe_apply_default_disabled_tools
		// v4) and the admin re-enables them here. There is no "activate" tool: an
		// AI can only create drafts; a human admin activates them on the Sandbox tab.
		$tools['php_snippets'] = array(
			'platform' => 'wordpress',
			'label' => __( 'PHP Snippets (Sandbox)', 'karmcp' ),
			'tools' => array(
				'karmcp/validate-php-snippet' => array(
					'label'       => __( 'Validate PHP Snippet', 'karmcp' ),
					'description' => __( 'Statically checks snippet code (parse + security scan) without storing or running it.', 'karmcp' ),
					'badges'      => array( 'read-only' ),
				),
				'karmcp/create-php-snippet'   => array(
					'label'       => __( 'Create PHP Snippet', 'karmcp' ),
					'description' => __( 'Creates an INACTIVE draft snippet (validated; an admin must activate it before it runs).', 'karmcp' ),
					'badges'      => array(),
				),
				'karmcp/update-php-snippet'   => array(
					'label'       => __( 'Update PHP Snippet', 'karmcp' ),
					'description' => __( 'Updates a snippet\'s code/settings and re-validates.', 'karmcp' ),
					'badges'      => array(),
				),
				'karmcp/get-php-snippet'      => array(
					'label'       => __( 'Get PHP Snippet', 'karmcp' ),
					'description' => __( 'Returns a snippet\'s code, status, shortcode, and validation report.', 'karmcp' ),
					'badges'      => array( 'read-only' ),
				),
				'karmcp/list-php-snippets'    => array(
					'label'       => __( 'List PHP Snippets', 'karmcp' ),
					'description' => __( 'Lists PHP snippets with their status and run context.', 'karmcp' ),
					'badges'      => array( 'read-only' ),
				),
				'karmcp/delete-php-snippet'   => array(
					'label'       => __( 'Delete PHP Snippet', 'karmcp' ),
					'description' => __( 'Permanently deletes a snippet and its sandbox file.', 'karmcp' ),
					'badges'      => array( 'destructive' ),
				),
			),
		);

		// Sandbox Cloud (export/import) — free, always-on. Lets a sandbox artifact
		// (custom widget/block/snippet) be exported as a portable bundle and
		// imported on another site, so authored sandbox code isn't stuck to one
		// install. Both read/write in nature but low-risk (data movement, not
		// arbitrary execution) — enabled-by-default, unlike the sandboxes themselves.
		$tools['sandbox_cloud'] = array(
			'platform' => 'wordpress',
			'label' => __( 'Sandbox Cloud (Export / Import)', 'karmcp' ),
			'tools' => array(
				'karmcp/export-sandbox-artifact' => array(
					'label'       => __( 'Export Sandbox Artifact', 'karmcp' ),
					'description' => __( 'Exports a custom widget/block/snippet as a portable bundle.', 'karmcp' ),
					'badges'      => array( 'read-only' ),
				),
				'karmcp/import-sandbox-artifact' => array(
					'label'       => __( 'Import Sandbox Artifact', 'karmcp' ),
					'description' => __( 'Imports a sandbox artifact bundle produced by export-sandbox-artifact.', 'karmcp' ),
					'badges'      => array(),
				),
			),
		);

		// Agent Skills — the read side of the skills an admin writes under
		// KarMCP → Skills. Both read-only, so both ship enabled; the Agent Skills
		// module is the switch that removes them entirely.
		if ( class_exists( 'KarMCP_Agent_Skills_Module' ) && KarMCP_Agent_Skills_Module::is_enabled() ) {
			$tools['skills'] = array(
				'platform' => 'wordpress',
				'label'    => __( 'Agent Skills', 'karmcp' ),
				'tools'    => array(
					'karmcp/list-skills' => array(
						'label'       => __( 'List Skills', 'karmcp' ),
						'description' => __( 'Names and one-line summaries of this site\'s skills — the operating manuals you wrote for agents.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-skill'   => array(
						'label'       => __( 'Get Skill', 'karmcp' ),
						'description' => __( 'Full text of one skill by machine name.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
				),
			);
		}

		// Themer PHP Templates — free, capability-gated + master-switch-gated;
		// disabled by default. AI authors DRAFTS; a human attaches one in a
		// template metabox (the execution gate). Registered only when the Themer
		// module is active, alongside where the feature actually lives.
		if ( class_exists( 'KarMCP_Themer_Module' ) && KarMCP_Themer_Module::is_enabled() ) {
			$tools['themer_php'] = array(
				'platform' => 'wordpress',
				'label'    => __( 'Themer PHP Templates', 'karmcp' ),
				'tools'    => array(
					'karmcp/create-theme-php-template' => array(
						'label'       => __( 'Create Theme PHP Template', 'karmcp' ),
						'description' => __( 'Create a validated DRAFT PHP region template (never runs until a human attaches it).', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/list-theme-php-templates'  => array(
						'label'       => __( 'List Theme PHP Templates', 'karmcp' ),
						'description' => __( 'List draft PHP templates.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/get-theme-php-template'    => array(
						'label'       => __( 'Get Theme PHP Template', 'karmcp' ),
						'description' => __( 'Return one PHP template with its validation report.', 'karmcp' ),
						'badges'      => array( 'read-only' ),
					),
					'karmcp/update-theme-php-template' => array(
						'label'       => __( 'Update Theme PHP Template', 'karmcp' ),
						'description' => __( 'Update a PHP template and re-validate.', 'karmcp' ),
						'badges'      => array(),
					),
					'karmcp/delete-theme-php-template' => array(
						'label'       => __( 'Delete Theme PHP Template', 'karmcp' ),
						'description' => __( 'Delete a PHP template and its sandbox file.', 'karmcp' ),
						'badges'      => array( 'destructive' ),
					),
				),
			);
		}

		// SEO & Accessibility toolkit (Pro) + Widget Builder (Pro). ALWAYS added
		// to the catalog so free users see the (locked) Pro surface; get_all_tools()
		// flags each 'pro' category "Requires KarMCP Pro" and disables its toggles on
		// free builds, and the abilities themselves stay license-gated. Bare block
		// keeps the two category assignments grouped.
		{
			$tools['seo'] = array(
				'platform' => 'wordpress',
				'pro'      => true,
				'label' => __( 'SEO', 'karmcp' ),
				'tools' => array(
					'karmcp/audit-page-seo'                => array(
						'label'       => __( 'Audit Page SEO', 'karmcp' ),
						'description' => __( 'Scored on-page SEO report (H1, title/meta, canonical, alts, links, word count).', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/extract-keywords-from-content' => array(
						'label'       => __( 'Extract Keywords', 'karmcp' ),
						'description' => __( 'Frequency keyword + phrase extraction from page content.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/generate-meta-tags'            => array(
						'label'       => __( 'Generate Meta Tags', 'karmcp' ),
						'description' => __( 'Proposes (apply:true writes to Yoast/Rank Math) an SEO title and meta description. Dry-run by default.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/generate-schema-markup'        => array(
						'label'       => __( 'Generate Schema Markup', 'karmcp' ),
						'description' => __( 'Generates (apply:true injects) JSON-LD structured data (Article, LocalBusiness, FAQPage, etc.). Dry-run by default.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/set-social-image'              => array(
						'label'       => __( 'Set Social Image', 'karmcp' ),
						'description' => __( 'Sets the Open Graph + Twitter share image (Yoast / Rank Math) so link previews use the image you choose, not the first content image.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
				),
			);

			$tools['a11y'] = array(
				'platform' => 'elementor',
				'label' => __( 'Accessibility', 'karmcp' ),
				'tools' => array(
					'karmcp/audit-page-a11y'           => array(
						'label'       => __( 'Audit Page Accessibility', 'karmcp' ),
						'description' => __( 'WCAG-oriented report: contrast, alts, heading order, link text, form labels.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/fix-color-contrast'        => array(
						'label'       => __( 'Fix Color Contrast', 'karmcp' ),
						'description' => __( 'Proposes (apply:true to write) adjusted text colors so failing pairs meet WCAG AA. Dry-run by default.', 'karmcp' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
					'karmcp/add-alt-text-from-context' => array(
						'label'       => __( 'Add Alt Text from Context', 'karmcp' ),
						'description' => __( 'Proposes (apply:true to write) alt text for images lacking it, from filename/heading/title. Dry-run by default.', 'karmcp' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);

			$tools['widget_builder'] = array(
				'platform' => 'elementor',
				'pro'      => true,
				'label' => __( 'Widget Builder (Pro)', 'karmcp' ),
				'tools' => array(
					'karmcp/list-control-types'   => array(
						'label'       => __( 'List Control Types', 'karmcp' ),
						'description' => __( 'Returns the control types and template syntax for building widget specs.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/validate-widget-spec' => array(
						'label'       => __( 'Validate Widget Spec', 'karmcp' ),
						'description' => __( 'Validates a widget spec and dry-runs the generator without saving.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/create-custom-widget' => array(
						'label'       => __( 'Create Custom Widget', 'karmcp' ),
						'description' => __( 'Generates a custom Elementor widget from a spec into an isolated sandbox and activates it.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/update-custom-widget' => array(
						'label'       => __( 'Update Custom Widget', 'karmcp' ),
						'description' => __( 'Replaces a custom widget\'s spec and regenerates its code.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/get-custom-widget'    => array(
						'label'       => __( 'Get Custom Widget', 'karmcp' ),
						'description' => __( 'Returns a custom widget\'s spec, generated PHP, status, and last error.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/list-custom-widgets'  => array(
						'label'       => __( 'List Custom Widgets', 'karmcp' ),
						'description' => __( 'Lists all generated custom widgets with their status.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/set-widget-status'    => array(
						'label'       => __( 'Set Widget Status', 'karmcp' ),
						'description' => __( 'Activates or deactivates a custom widget.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/delete-custom-widget' => array(
						'label'       => __( 'Delete Custom Widget', 'karmcp' ),
						'description' => __( 'Permanently deletes a custom widget and its sandbox file.', 'karmcp' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);

			$tools['block_builder'] = array(
				'platform' => 'gutenberg',
				'pro'      => true,
				'label' => __( 'Block Builder (Pro)', 'karmcp' ),
				'tools' => array(
					'karmcp/list-block-control-types' => array(
						'label'       => __( 'List Block Control Types', 'karmcp' ),
						'description' => __( 'Returns the attribute types and template syntax for building block specs.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/validate-block-spec'      => array(
						'label'       => __( 'Validate Block Spec', 'karmcp' ),
						'description' => __( 'Validates a block spec and dry-runs the generator without saving.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/create-custom-block'      => array(
						'label'       => __( 'Create Custom Block', 'karmcp' ),
						'description' => __( 'Generates a custom Gutenberg block from a spec into an isolated sandbox and activates it.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/update-custom-block'      => array(
						'label'       => __( 'Update Custom Block', 'karmcp' ),
						'description' => __( 'Replaces a custom block\'s spec and regenerates its code.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/get-custom-block'          => array(
						'label'       => __( 'Get Custom Block', 'karmcp' ),
						'description' => __( 'Returns a custom block\'s spec, generated code, status, and last error.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/list-custom-blocks'        => array(
						'label'       => __( 'List Custom Blocks', 'karmcp' ),
						'description' => __( 'Lists all generated custom blocks with their status.', 'karmcp' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'karmcp/set-block-status'          => array(
						'label'       => __( 'Set Block Status', 'karmcp' ),
						'description' => __( 'Activates or deactivates a custom block.', 'karmcp' ),
						'badges'      => array( 'pro' ),
					),
					'karmcp/delete-custom-block'       => array(
						'label'       => __( 'Delete Custom Block', 'karmcp' ),
						'description' => __( 'Permanently deletes a custom block and its sandbox file.', 'karmcp' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);
		}

		return $tools;
	}

	/**
	 * Get a flat list of all tool slugs.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] All tool slugs.
	 */
	public function get_all_tool_slugs(): array {
		$slugs = array();
		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $slug => $tool ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Returns only the slugs of tools whose platform group is currently active.
	 *
	 * When Elementor is inactive, Elementor-platform tools are excluded because
	 * they are never registered and must not inflate "X of Y enabled" stats.
	 * Use get_all_tool_slugs() (unfiltered) anywhere the full canonical list is
	 * needed for data-management purposes (e.g. sanitize_disabled_tools).
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public function get_available_tool_slugs(): array {
		$categories = $this->get_all_tools();
		if ( ! KarMCP_Bootstrap::elementor_active() ) {
			$categories = self::filter_out_elementor( $categories );
		}
		$slugs = array();
		foreach ( $categories as $category ) {
			foreach ( $category['tools'] as $slug => $tool ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Count enabled tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of enabled tools.
	 */
	public function get_enabled_tool_count(): int {
		$all = $this->get_available_tool_slugs();

		$disabled = get_option( self::OPTION_DISABLED_TOOLS, array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		return count( array_diff( $all, $disabled ) );
	}

	/**
	 * Count total tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Total number of tools.
	 */
	public function get_total_tool_count(): int {
		return count( $this->get_available_tool_slugs() );
	}
}
