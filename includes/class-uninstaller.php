<?php
/**
 * Uninstall cleanup.
 *
 * Wired to Freemius's `after_uninstall` action from the bootstrap file. Removes
 * plugin-owned options/transients/user-meta and, critically, the generated
 * executable PHP (custom widgets + PHP snippets) which must never survive an
 * uninstall.
 *
 * @package KarMCP
 * @since   2.1.0 (extracted from karmcp_after_uninstall, since 1.6.1)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes plugin-owned data on uninstall.
 *
 * @since 2.1.0
 */
class KarMCP_Uninstaller {

	/**
	 * Runs the uninstall cleanup.
	 *
	 * @since 2.1.0
	 */
	public static function run(): void {
		delete_option( 'karmcp_disabled_tools' );
		delete_option( 'karmcp_low_tool_mode' );
		delete_option( 'karmcp_defaults_applied' );
		delete_option( 'karmcp_cloud_connection' );
		delete_option( 'karmcp_site_uuid' );
		delete_option( 'karmcp_cloud_base_url' );
		delete_transient( 'karmcp_cloud_pending' );
		delete_transient( 'karmcp_pro_prompts_bundle' );
		delete_transient( 'karmcp_pro_templates_bundle' );
		delete_transient( 'karmcp_pro_brand_kits_bundle' );
		// Drop the dismissal flags from every user.
		delete_metadata( 'user', 0, 'karmcp_upgrade_notice_dismissed', '', true );
		delete_metadata( 'user', 0, 'karmcp_community_notice_dismissed', '', true );
		// Brand-kit backups (karmcp_kit_backup CPT) are intentionally LEFT in place
		// on uninstall — treated as recoverable user content so a user who removes
		// the plugin can still roll back their pre-kit brand after reinstalling.

		// Widget Builder: generated executable PHP must NOT survive uninstall —
		// delete every karmcp_widget post and remove the uploads sandbox tree.
		if ( ! class_exists( 'KarMCP_Widget_Store' ) ) {
			require_once KARMCP_DIR . 'includes/class-widget-store.php';
		}
		if ( class_exists( 'KarMCP_Widget_Store' ) ) {
			KarMCP_Widget_Store::uninstall_cleanup();
		}

		// PHP Snippets: generated executable PHP must NOT survive uninstall either.
		if ( ! class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			require_once KARMCP_DIR . 'includes/class-php-snippet-store.php';
		}
		if ( class_exists( 'KarMCP_PHP_Snippet_Store' ) ) {
			KarMCP_PHP_Snippet_Store::uninstall_cleanup();
		}

		// Leftovers from the upstream Pro overlay. Those features are not part of
		// this build, but an install that once ran the upstream plugin may still
		// carry their options and user meta, so the deletes stay.
		delete_option( 'karmcp_ai_models' );
		delete_metadata( 'user', 0, 'karmcp_ai_keys', '', true );
		delete_metadata( 'user', 0, 'karmcp_ai_defaults', '', true );
	}
}
