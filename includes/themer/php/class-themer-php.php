<?php
/**
 * KarMCP Themer PHP Templates — coordinator + feature flag.
 *
 * Thin owner wired by the Themer module's register(): registers the karmcp_theme_php
 * CPT and (when enabled) the review-list admin page. The MCP abilities are gated
 * separately in the ability registrar (they register before init). The whole
 * feature is a true kill switch: OFF => metabox/render-delegation/tools all no-op.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class KarMCP_Themer_PHP {

	/** Feature toggle option (disabled-by-default). */
	const OPTION_ENABLED = 'karmcp_themer_php_enabled';

	/**
	 * Feature is usable: the Themer module is active AND the admin opted in.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		if ( ! class_exists( 'KarMCP_Themer_Module' ) || ! KarMCP_Themer_Module::is_enabled() ) {
			return false;
		}
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Wire everything. Called by the module on init:5 (only when the module is active).
	 */
	public function init(): void {
		// Register the CPT within an active module so existing drafts remain
		// queryable/deletable even if the feature toggle is later switched off.
		KarMCP_Themer_PHP_Store::register_post_type();

		if ( ! self::enabled() ) {
			return;
		}

		if ( is_admin() && class_exists( 'KarMCP_Themer_PHP_Admin' ) ) {
			( new KarMCP_Themer_PHP_Admin() )->init();
		}
	}
}
