<?php
/**
 * Prompts tab view for the KarMCP admin settings page.
 *
 * Shows the bundled sample prompts.
 *
 * @package KarMCP
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sample prompt metadata: filename (without .md) => title, industry tag, description.
 */
$karmcp_prompt_meta = array(
	'LOCAL_BUSINESS'          => array(
		'title'       => __( 'Local Business', 'karmcp' ),
		'industry'    => __( 'General', 'karmcp' ),
		'description' => __( 'Multi-purpose small business landing page with hero, services, testimonials, and contact section.', 'karmcp' ),
	),
	'DENTAL_CLINIC'           => array(
		'title'       => __( 'Dental Clinic', 'karmcp' ),
		'industry'    => __( 'Health & Wellness', 'karmcp' ),
		'description' => __( 'Professional dental practice with services grid, team profiles, insurance info, and appointment booking.', 'karmcp' ),
	),
	'WEB_DEVELOPER_PORTFOLIO' => array(
		'title'       => __( 'Web Developer Portfolio', 'karmcp' ),
		'industry'    => __( 'Professional Services', 'karmcp' ),
		'description' => __( 'Developer portfolio with project showcase, tech stack, GitHub stats, and contact form.', 'karmcp' ),
	),
	'HAIR_SALON'              => array(
		'title'       => __( 'Hair Salon', 'karmcp' ),
		'industry'    => __( 'Lifestyle', 'karmcp' ),
		'description' => __( 'Stylish salon page with services menu, stylist profiles, gallery, and online booking.', 'karmcp' ),
	),
	'LOCAL_NEWS_SITE'         => array(
		'title'       => __( 'Local News Site', 'karmcp' ),
		'industry'    => __( 'Media', 'karmcp' ),
		'description' => __( 'Hyperlocal news article as a standalone page, with Google News structured data and image SEO built in.', 'karmcp' ),
	),
	'CAR_WASH'                => array(
		'title'       => __( 'Car Wash', 'karmcp' ),
		'industry'    => __( 'Lifestyle', 'karmcp' ),
		'description' => __( 'Car wash site with wash packages, add-on services, membership plans, and booking form.', 'karmcp' ),
	),
);

$karmcp_prompts_dir = KARMCP_DIR . 'prompts/';

?>

<div class="karmcp-prompts">

	<?php // -------------------------------------------------------------------
	// One-time notice for users arriving from an older version: the prompt
	// library was rewritten, so the cards below look different than before.
	// ------------------------------------------------------------------- ?>

	<?php if ( ! KarMCP_Admin::prompts_notice_dismissed() ) : ?>
		<div class="karmcp-prompts-whatsnew">
			<div class="karmcp-prompts-whatsnew-body">
				<h3><?php esc_html_e( 'The prompts have been rewritten', 'karmcp' ); ?></h3>
				<p>
					<?php esc_html_e( 'Prompts no longer dictate a fixed, section-by-section layout. Each one now gives the AI a style guide, a design direction, the exact content, and hard standards, accessibility, real photography, consistent SVG icons, and a working contact form, then lets it design the page. Expect noticeably better, more distinctive results.', 'karmcp' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'They also work with any page builder: change the first line of a prompt from Elementor to Gutenberg, Bricks, or plain HTML/CSS, and the rest still applies.', 'karmcp' ); ?>
				</p>
			</div>
			<a
				href="<?php echo esc_url( KarMCP_Admin::prompts_notice_dismiss_url() ); ?>"
				class="karmcp-prompts-whatsnew-dismiss"
				aria-label="<?php esc_attr_e( 'Dismiss this notice', 'karmcp' ); ?>"
			>
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</a>
		</div>
	<?php endif; ?>

	<div class="karmcp-prompts-intro">
		<h2><?php esc_html_e( 'Sample Prompts', 'karmcp' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Ready-to-use landing page blueprints for AI agents. Copy any prompt below and paste it into your AI client (Claude, Cursor, etc.), it will automatically build a complete Elementor page using MCP tools.', 'karmcp' ); ?>
		</p>
	</div>

	<div class="karmcp-prompts-grid">
		<?php foreach ( $karmcp_prompt_meta as $karmcp_slug => $karmcp_meta ) :
			$karmcp_file_path = $karmcp_prompts_dir . $karmcp_slug . '.md';
			if ( ! file_exists( $karmcp_file_path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
			$karmcp_content = file_get_contents( $karmcp_file_path );
			$karmcp_copy_id = 'karmcp-prompt-' . sanitize_title( $karmcp_slug );
		?>
			<div class="karmcp-prompt-card">
				<div class="karmcp-prompt-header">
					<h3 class="karmcp-prompt-title"><?php echo esc_html( $karmcp_meta['title'] ); ?></h3>
					<span class="karmcp-prompt-tag"><?php echo esc_html( $karmcp_meta['industry'] ); ?></span>
				</div>
				<p class="karmcp-prompt-desc"><?php echo esc_html( $karmcp_meta['description'] ); ?></p>
				<div class="karmcp-prompt-actions">
					<button type="button" class="button karmcp-copy-btn" data-target="<?php echo esc_attr( $karmcp_copy_id ); ?>">
						<svg viewBox="0 0 20 20" width="14" height="14" xmlns="http://www.w3.org/2000/svg"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/></svg>
					<?php esc_html_e( 'Copy Prompt', 'karmcp' ); ?>
					</button>
				</div>
				<textarea id="<?php echo esc_attr( $karmcp_copy_id ); ?>" class="karmcp-copy-source"><?php echo esc_textarea( $karmcp_content ); ?></textarea>
			</div>
		<?php endforeach; ?>
	</div>

</div>
