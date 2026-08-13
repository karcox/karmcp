<?php
/**
 * KarMCP Cloud module (free, on by default). Boots the OAuth client admin flow.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KarMCP_Cloud_Module extends KarMCP_Module {
	public function id(): string {
		return 'cloud';
	}

	public function title(): string {
		return __( 'KarMCP Cloud', 'karmcp' );
	}

	public function description(): string {
		return __( 'Connect this site to your KarMCP Cloud account to back up and sync your work.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	public function default_active(): bool {
		return true;
	}

	public function register(): void {
		KarMCP_Cloud_Connect::init();
	}

	public function render_settings(): void {
		echo '<p>' . esc_html__( 'Connect or disconnect on the Connection tab.', 'karmcp' ) . '</p>';
	}

	public function settings_url(): string {
		return admin_url( 'admin.php?page=karmcp-connection#karmcp-conn-main' );
	}

	/**
	 * Static gate for the Connection-tab card (runs before init:5).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( 'cloud', $active, true );
	}
}
