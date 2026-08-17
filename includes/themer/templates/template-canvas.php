<?php
/**
 * Full-document canvas for a Themer body template takeover.
 *
 * WordPress includes this via the `template_include` filter in the template
 * loader's scope, so it pulls the resolved slots from the render controller's
 * memoized resolver rather than a local variable.
 *
 * That scope is the GLOBAL one, which is why the one variable here carries the
 * plugin prefix: a bare `$slots` in the template loader is a global, free to
 * collide with any other plugin's. The view partials elsewhere are included
 * from inside a method and are genuinely local; this one is not.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_slots = class_exists( 'KarMCP_Themer_Render_Controller' )
	? KarMCP_Themer_Render_Controller::slots()
	: array();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'karmcp-themer-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( ! empty( $karmcp_slots['header'] ) ) {
	echo KarMCP_Themer_Content_Renderer::render( (int) $karmcp_slots['header'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( ! empty( $karmcp_slots['body'] ) ) {
	echo '<main class="karmcp-themer-body">';
	echo KarMCP_Themer_Content_Renderer::render( (int) $karmcp_slots['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</main>';
}
if ( ! empty( $karmcp_slots['footer'] ) ) {
	echo KarMCP_Themer_Content_Renderer::render( (int) $karmcp_slots['footer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_footer();
?>
</body>
</html>
