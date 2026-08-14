<?php
/**
 * Agent Skills as a module.
 *
 * Controls the *runtime* exposure of a site's skills to connected AI agents: the
 * `list-skills` / `get-skill` MCP tools and the `## Skills` index injected into
 * the discovery context. Turning it off removes both, and with them their token
 * cost on every connection — without touching the skills themselves, which stay
 * editable under KarMCP → Skills either way.
 *
 * The gating is read statically, because the ability registrar runs on
 * `wp_abilities_api_init`, before this module boots on `init:5`.
 *
 * @package KarMCP
 * @since   3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Skills module.
 *
 * @since 3.2.0
 */
class KarMCP_Agent_Skills_Module extends KarMCP_Module {

	public function id(): string {
		return 'agent-skills';
	}

	public function title(): string {
		return __( 'Agent Skills', 'karmcp' );
	}

	public function description(): string {
		return __( 'Hand your skills to connected AI agents: an index of names and summaries in the discovery context, plus the list-skills / get-skill tools to fetch one on demand. Write and edit the skills themselves under KarMCP → Skills; this switch only decides whether agents can see them.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	public function default_active(): bool {
		return true;
	}

	/** Where the skills are written. */
	public function settings_url(): string {
		return admin_url( 'edit.php?post_type=' . KarMCP_Skill_Store::POST_TYPE );
	}

	/** No overlay knobs — the on/off toggle is the whole control. */
	public function render_settings(): void {}

	/**
	 * Wire the discovery index. The MCP tools are not wired here: the ability
	 * registrar runs before this, and gates itself on is_enabled().
	 */
	public function register(): void {
		KarMCP_Skill_Catalog::init();
	}

	/**
	 * Whether the module is active (static helper for the ability registrar,
	 * which runs on wp_abilities_api_init, before the module's init:5 boot).
	 * Reads the active-modules option directly.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( 'agent-skills', $active, true );
	}
}
