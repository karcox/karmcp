<?php
/**
 * Sandbox tab — thin view=-routed dispatcher.
 *
 * The Sandbox parent page (?page=karmcp-widgets) shows a card overview
 * (Blocks | Widgets | Extensions | PHP Snippets); each pillar's full
 * management UI lives in its own view file under includes/admin/views/sandbox/
 * and is reached via ?page=karmcp-widgets&view=blocks|widgets|extensions|snippets.
 * Those views are intentionally hidden from the wp-admin menu (no submenu
 * entries), routed only through this file.
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_view = KarMCP_Admin::sandbox_view();
$karmcp_map  = array(
	'overview'   => 'sandbox/overview.php',
	'blocks'     => 'sandbox/blocks.php',
	'widgets'    => 'sandbox/widgets.php',
	'extensions' => 'sandbox/extensions.php',
	'snippets'   => 'sandbox/snippets.php',
);
$karmcp_file = KARMCP_DIR . 'includes/admin/views/' . ( $karmcp_map[ $karmcp_view ] ?? $karmcp_map['overview'] );

if ( file_exists( $karmcp_file ) ) {
	include $karmcp_file;
} else {
	// Graceful fallback (e.g. blocks.php not yet shipped) — show the overview.
	include KARMCP_DIR . 'includes/admin/views/' . $karmcp_map['overview'];
}
