<?php
/**
 * Plugin Name:       KarMCP
 * Plugin URI:        https://github.com/karcox/karmcp
 * Description:       Extends the WordPress MCP Adapter to expose Elementor data, widgets, and page design tools as MCP tools for AI agents.
 * Version:           3.12.0
 * Requires at least: 6.9
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Author:            karcox
 * Author URI:        https://github.com/karcox
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        false
 * Text Domain:       karmcp
 * Domain Path:       /languages
 *
 * KarMCP is a fork of EMCP Tools 3.12.0 by Mian Shahzad Raza
 * (https://msrbuilds.com — https://github.com/msrbuilds/elementor-mcp),
 * used and redistributed under the GPL-2.0-or-later. The original copyright
 * notices and the LICENSE file are retained unchanged, as the licence requires.
 *
 * This build updates manually: there is no GitHub/Freemius auto-updater, and
 * `Update URI: false` stops WordPress from consulting wordpress.org on a slug
 * match.
 *
 * This file is the bootstrap ONLY: plugin header, the legacy-rename guard,
 * constants, the Freemius SDK helper, the uninstall hook, and the entry point
 * that hands off to KarMCP_Bootstrap. All feature logic lives in classes
 * under includes/.
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
 * Upstream also carried a free⇄premium arbitration here (a `.karmcp-pro`
 * marker file, a sibling `karmcp-pro/` folder, and mutual deactivation). KarMCP
 * has a single tier, so that machinery is gone; only the re-entry check remains.
 */
if ( defined( 'KARMCP_VERSION' ) ) {
	return;
}

/**
 * Legacy coexistence guard.
 *
 * This plugin was renamed from the `elementor-mcp` folder/slug to `karmcp`.
 * On an existing site the old `elementor-mcp/elementor-mcp.php` plugin may still
 * be active alongside this one during the transition. All PHP symbols were
 * re-prefixed (KarMCP_* / karmcp_*) so the two can coexist without
 * "cannot redeclare" fatals — but they would still both register the same MCP
 * abilities/server and share data. So while the old plugin is active we do NOT
 * boot: we snapshot its settings (admin only) and show a notice, then bail
 * before defining constants, initializing Freemius, or registering anything.
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
				__( '<strong>KarMCP:</strong> The previous &#8220;MCP Tools for Elementor&#8221; plugin (folder <code>elementor-mcp</code>) is still active. KarMCP has replaced it &mdash; please <strong>deactivate and delete</strong> the old plugin to finish the upgrade. Your settings and license carry over automatically. KarMCP stays paused until then.', 'karmcp' ),
				array(
					'strong' => array(),
					'code'   => array(),
				)
			);
			echo '</p></div>';
		}
	);
	// Bail before booting anything else (no constants, no Freemius, no abilities).
	return;
}

// Plugin constants.
define( 'KARMCP_VERSION', '3.12.0' );
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

// Uninstall cleanup. Upstream routed this through Freemius's `after_uninstall`
// action because Freemius rejects builds containing an uninstall.php. With the
// SDK gone that action never fires, so wire WordPress's own uninstall hook —
// otherwise removing the plugin would leave every option and table behind.
require_once KARMCP_DIR . 'includes/class-uninstaller.php';
register_uninstall_hook( __FILE__, array( 'KarMCP_Uninstaller', 'run' ) );

// Hand off to the bootstrap (loads classes + wires hooks) once dependencies
// like Elementor are available.
require_once KARMCP_DIR . 'includes/class-bootstrap.php';
add_action( 'plugins_loaded', array( 'KarMCP_Bootstrap', 'boot' ), 20 );
