<?php
/**
 * Prompts as a module.
 *
 * A tab-only feature: the module gates the Prompts admin tab (and its stat
 * card); the free/Pro content inside the tab is unchanged. register() is a
 * no-op — the admin class reads the module's active state to show/hide the tab.
 * Free tier; on by default.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompts module.
 *
 * @since 3.1.0
 */
class KarMCP_Prompts_Module extends KarMCP_Module {

	public function id(): string {
		return 'prompts';
	}

	public function title(): string {
		return __( 'Prompts', 'karmcp' );
	}

	public function description(): string {
		return __( 'Ready-to-use AI prompt blueprints for building pages, bundled samples plus the premium library.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	public function default_active(): bool {
		return true;
	}

	/** The dedicated Prompts admin tab is the config surface. */
	public function settings_url(): string {
		return admin_url( 'admin.php?page=' . KarMCP_Admin::PAGE_SLUG . '-prompts' );
	}

	/** No overlay settings; the feature lives on its admin tab. */
	public function render_settings(): void {}

	/** Tab-only feature — the admin class gates the tab; nothing to wire here. */
	public function register(): void {}
}
