<?php
/**
 * Full-document canvas for a Themer body template takeover.
 *
 * WordPress includes this via the `template_include` filter in the template
 * loader's scope, so it pulls the resolved slots from the render controller's
 * memoized resolver rather than a local variable.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$slots = class_exists( 'KarMCP_Themer_Render_Controller' )
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
if ( ! empty( $slots['header'] ) ) {
	echo KarMCP_Themer_Content_Renderer::render( (int) $slots['header'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
if ( ! empty( $slots['body'] ) ) {
	echo '<main class="karmcp-themer-body">';
	echo KarMCP_Themer_Content_Renderer::render( (int) $slots['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</main>';
}
if ( ! empty( $slots['footer'] ) ) {
	echo KarMCP_Themer_Content_Renderer::render( (int) $slots['footer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
wp_footer();
?>
</body>
</html>
