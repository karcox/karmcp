<?php
/**
 * Singular preview canvas for a Themer template (`karmcp_theme_tpl`).
 *
 * A blank, full-width document that calls `the_content()`. Two jobs:
 *   1. Elementor's editor loads the post's front-end URL in its preview iframe
 *      and refuses to attach unless the template calls `the_content()` ("Sorry,
 *      the content area was not found in your page."). This canvas provides it.
 *   2. Viewing a template at its own URL renders its built content standalone.
 *
 * This is used ONLY when the CURRENT request is the template's own singular view
 * (editing/previewing the template itself) — never when applying a template to a
 * real request. The render controller routes to it and skips Themer resolution.
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'karmcp-themer-edit-canvas' ); ?>>
<?php
while ( have_posts() ) :
	the_post();
	the_content();
endwhile;
wp_footer();
?>
</body>
</html>
