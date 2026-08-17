<?php
/**
 * Plugin Name:       KarMCP
 * Plugin URI:        https://github.com/karcox/karmcp
 * Description:       Extends the WordPress MCP Adapter to expose Elementor data, widgets, and page design tools as MCP tools for AI agents.
 * Version:           1.14.2
 * Requires at least: 6.9
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            karcox
 * Author URI:        https://github.com/karcox
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        false
 * Text Domain:       karmcp
 * Domain Path:       /languages
 *
 * Third-party copyright notices and licence terms are in NOTICE and LICENSE.
 *
 * This build updates manually: there is no auto-updater, and `Update URI: false`
 * stops WordPress from consulting wordpress.org on a slug match.
 *
 * This file is the bootstrap ONLY: plugin header, the legacy-rename guard,
 * constants, the uninstall hook, and the entry point that hands off to
 * KarMCP_Bootstrap. All feature logic lives in classes under includes/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Double-load guard.
 *
 * WordPress treats two plugin folders as separate plugins, so `require_once`
 * does NOT dedupe across them: activating a second copy of KarMCP while the
 * first is active would redeclare every class (starting at
 * includes/class-migration.php below) and fatal. This runs BEFORE any require,
 * so it can never redeclare.
 *
 * KarMCP has a single tier, so this is a plain re-entry check — there is no
 * free/premium arbitration to do.
 */
if ( defined( 'KARMCP_VERSION' ) ) {
	return;
}

/**
 * Legacy coexistence guard.
 *
 * A site upgrading from the older `elementor-mcp` plugin may still have it
 * active alongside this one. Every PHP symbol here is prefixed KarMCP_/karmcp_,
 * so the two never collide at load time — but both would register an MCP
 * server over the same data, which the MCP client cannot disambiguate. So
 * while the old plugin is active we do NOT boot: we snapshot its settings
 * (admin only) and show a notice, then bail before defining constants or
 * registering anything.
 */
require_once __DIR__ . '/includes/class-migration.php';

if ( KarMCP_Migration::is_legacy_plugin_active() ) {
	// Snapshot the old plugin's settings into the new keys WHILE it's still
	// installed — once the user deletes it, its uninstall hook wipes them.
	if ( is_admin() ) {
		KarMCP_Migration::migrate();
	}
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses(
				__( '<strong>KarMCP:</strong> Another MCP plugin (folder <code>elementor-mcp</code>) is active and would register a competing MCP server over the same data. Please <strong>deactivate and delete</strong> it &mdash; your settings carry over automatically. KarMCP stays paused until then.', 'karmcp' ),
				array(
					'strong' => array(),
					'code'   => array(),
				)
			);
			echo '</p></div>';
		}
	);
	// Bail before booting anything else (no constants, no abilities).
	return;
}

// Plugin constants.
define( 'KARMCP_VERSION', '1.14.2' );
define( 'KARMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KARMCP_URL', plugin_dir_url( __FILE__ ) );
define( 'KARMCP_BASENAME', plugin_basename( __FILE__ ) );

// Claim the WP\MCP namespace for our bundled MCP Adapter copy, at file-load —
// BEFORE any other plugin can autoload an adapter class. Other plugins bundle
// the adapter behind their own autoloaders (Rank Math SEO, …) and PHP allows
// only one class of a given name per request, so without this the namespace
// can shear across two adapter versions and the MCP session dies mid-request
// ("Session terminated", -32600). Lazy: registers a resolver, loads nothing.
require_once KARMCP_DIR . 'includes/class-mcp-adapter-bootstrap.php';
KarMCP_Adapter_Bootstrap::preload_bundled_namespace();

// Uninstall cleanup on WordPress's own hook — without it, removing the plugin
// would leave every option and table behind.
require_once KARMCP_DIR . 'includes/class-uninstaller.php';
register_uninstall_hook( __FILE__, array( 'KarMCP_Uninstaller', 'run' ) );

// Hand off to the bootstrap (loads classes + wires hooks) once dependencies
// like Elementor are available.
require_once KARMCP_DIR . 'includes/class-bootstrap.php';
add_action( 'plugins_loaded', array( 'KarMCP_Bootstrap', 'boot' ), 20 );
