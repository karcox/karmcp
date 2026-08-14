<?php
/**
 * Prompts tab view for the KarMCP admin settings page.
 *
 * Free section: 5 bundled sample prompts.
 * Premium section: 50+ categorized prompts fetched from the KarMCP Pro
 * server when a valid license is active.
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
	'CAR_WASH'                => array(
		'title'       => __( 'Car Wash', 'karmcp' ),
		'industry'    => __( 'Lifestyle', 'karmcp' ),
		'description' => __( 'Car wash site with wash packages, add-on services, membership plans, and booking form.', 'karmcp' ),
	),
);

$karmcp_prompts_dir = KARMCP_DIR . 'prompts/';

$karmcp_has_pro    = class_exists( 'KarMCP_Pro_Prompts' ) && KarMCP_Pro_Prompts::user_has_access();
$karmcp_pro_bundle = null;
$karmcp_pro_error  = null;
if ( $karmcp_has_pro ) {
	$karmcp_pro_result = KarMCP_Pro_Prompts::get_bundle();
	if ( is_wp_error( $karmcp_pro_result ) ) {
		$karmcp_pro_error = $karmcp_pro_result->get_error_message();
	} else {
		$karmcp_pro_bundle = $karmcp_pro_result;
	}
}

// Legacy (v1) prompt archive: bundled with premium builds only, and streamed
// through a capability + nonce + license gated admin-post handler.
$karmcp_v1_available = class_exists( 'KarMCP_Pro_Prompts' )
	&& method_exists( 'KarMCP_Pro_Prompts', 'v1_zip_available' )
	&& KarMCP_Pro_Prompts::v1_zip_available();
$karmcp_v1_url       = $karmcp_v1_available ? KarMCP_Pro_Prompts::v1_download_url() : '';
?>

<div class="elementor-mcp-prompts">

	<?php // -------------------------------------------------------------------
	// One-time notice for users arriving from an older version: the prompt
	// library was rewritten, so the cards below look different than before.
	// ------------------------------------------------------------------- ?>

	<?php if ( ! KarMCP_Admin::prompts_notice_dismissed() ) : ?>
		<div class="elementor-mcp-prompts-whatsnew">
			<div class="elementor-mcp-prompts-whatsnew-body">
				<h3><?php esc_html_e( 'The prompts have been rewritten', 'karmcp' ); ?></h3>
				<p>
					<?php esc_html_e( 'Prompts no longer dictate a fixed, section-by-section layout. Each one now gives the AI a style guide, a design direction, the exact content, and hard standards, accessibility, real photography, consistent SVG icons, and a working contact form, then lets it design the page. Expect noticeably better, more distinctive results.', 'karmcp' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'They also work with any page builder: change the first line of a prompt from Elementor to Gutenberg, Bricks, or plain HTML/CSS, and the rest still applies.', 'karmcp' ); ?>
					<?php if ( $karmcp_v1_available ) : ?>
						<?php esc_html_e( 'Prefer the originals? Download the v1 prompts using the button below.', 'karmcp' ); ?>
					<?php endif; ?>
				</p>
			</div>
			<a
				href="<?php echo esc_url( KarMCP_Admin::prompts_notice_dismiss_url() ); ?>"
				class="elementor-mcp-prompts-whatsnew-dismiss"
				aria-label="<?php esc_attr_e( 'Dismiss this notice', 'karmcp' ); ?>"
			>
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</a>
		</div>
	<?php endif; ?>

	<?php
	// Hide the bundled sample-prompts section when the user has Pro AND the
	// premium bundle loaded successfully — the 5 samples are a subset of the
	// 50+ premium prompts, so showing both is duplication. Free users (and
	// Pro users hitting a fetch error) still get the samples.
	$karmcp_show_samples = ! ( $karmcp_has_pro && is_array( $karmcp_pro_bundle ) );
	?>

	<?php if ( $karmcp_show_samples ) : ?>
		<div class="elementor-mcp-prompts-intro">
			<h2><?php esc_html_e( 'Sample Prompts', 'karmcp' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Ready-to-use landing page blueprints for AI agents. Copy any prompt below and paste it into your AI client (Claude, Cursor, etc.), it will automatically build a complete Elementor page using MCP tools.', 'karmcp' ); ?>
			</p>
		</div>

		<div class="elementor-mcp-prompts-grid">
			<?php foreach ( $karmcp_prompt_meta as $karmcp_slug => $karmcp_meta ) :
				$karmcp_file_path = $karmcp_prompts_dir . $karmcp_slug . '.md';
				if ( ! file_exists( $karmcp_file_path ) ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file.
				$karmcp_content = file_get_contents( $karmcp_file_path );
				$karmcp_copy_id = 'elementor-mcp-prompt-' . sanitize_title( $karmcp_slug );
			?>
				<div class="elementor-mcp-prompt-card">
					<div class="elementor-mcp-prompt-header">
						<h3 class="elementor-mcp-prompt-title"><?php echo esc_html( $karmcp_meta['title'] ); ?></h3>
						<span class="elementor-mcp-prompt-tag"><?php echo esc_html( $karmcp_meta['industry'] ); ?></span>
					</div>
					<p class="elementor-mcp-prompt-desc"><?php echo esc_html( $karmcp_meta['description'] ); ?></p>
					<div class="elementor-mcp-prompt-actions">
						<button type="button" class="button elementor-mcp-copy-btn" data-target="<?php echo esc_attr( $karmcp_copy_id ); ?>">
							<svg viewBox="0 0 20 20" width="14" height="14" xmlns="http://www.w3.org/2000/svg"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/></svg>
						<?php esc_html_e( 'Copy Prompt', 'karmcp' ); ?>
						</button>
					</div>
					<textarea id="<?php echo esc_attr( $karmcp_copy_id ); ?>" class="elementor-mcp-copy-source"><?php echo esc_textarea( $karmcp_content ); ?></textarea>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php // -------------------------------------------------------------------
	// Premium prompts library.
	// ------------------------------------------------------------------- ?>

	<?php if ( $karmcp_has_pro && is_array( $karmcp_pro_bundle ) ) : ?>

		<div class="elementor-mcp-pro-prompts">
			<div class="elementor-mcp-pro-prompts-header">
				<div class="elementor-mcp-pro-prompts-heading">
					<h2>
						<?php esc_html_e( 'Premium Prompts Library', 'karmcp' ); ?>
						<span class="elementor-mcp-badge elementor-mcp-badge--pro">PRO</span>
					</h2>
					<p class="description">
						<?php
						$karmcp_total = 0;
						foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) {
							$karmcp_total += is_array( $karmcp_cat['prompts'] ?? null ) ? count( $karmcp_cat['prompts'] ) : 0;
						}
						printf(
							/* translators: %1$d: prompts, %2$d: categories */
							esc_html__( '%1$d prompts across %2$d categories. Updated automatically.', 'karmcp' ),
							(int) $karmcp_total,
							(int) count( $karmcp_pro_bundle['categories'] )
						);
						?>
						<?php if ( ! empty( $karmcp_pro_bundle['fetched_at'] ) ) : ?>
							<span class="elementor-mcp-pro-prompts-meta">
								<?php
								printf(
									/* translators: %s: human-readable time since last sync */
									esc_html__( 'Last synced %s ago.', 'karmcp' ),
									esc_html( human_time_diff( (int) $karmcp_pro_bundle['fetched_at'], time() ) )
								);
								?>
							</span>
						<?php endif; ?>
					</p>
				</div>
				<div class="elementor-mcp-pro-prompts-actions">
					<?php if ( $karmcp_v1_available ) : ?>
						<a
							href="<?php echo esc_url( $karmcp_v1_url ); ?>"
							class="button elementor-mcp-legacy-btn"
							title="<?php esc_attr_e( 'The original prompts, which prescribe an explicit section-by-section Elementor layout.', 'karmcp' ); ?>"
						>
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Download v1 Prompts', 'karmcp' ); ?>
						</a>
					<?php endif; ?>
					<button
						type="button"
						class="button elementor-mcp-pro-sync-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_sync_pro_prompts' ) ); ?>"
					>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'Sync Library', 'karmcp' ); ?>
					</button>
				</div>
			</div>

			<div class="elementor-mcp-pro-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'karmcp' ); ?>">
				<button type="button" class="elementor-mcp-pro-filter is-active" data-category="all">
					<?php esc_html_e( 'All', 'karmcp' ); ?>
					<span class="elementor-mcp-pro-filter-count"><?php echo (int) $karmcp_total; ?></span>
				</button>
				<?php foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) :
					$karmcp_cat_slug  = isset( $karmcp_cat['slug'] ) ? sanitize_key( $karmcp_cat['slug'] ) : '';
					$karmcp_cat_label = isset( $karmcp_cat['label'] ) ? (string) $karmcp_cat['label'] : '';
					$karmcp_cat_count = is_array( $karmcp_cat['prompts'] ?? null ) ? count( $karmcp_cat['prompts'] ) : 0;
					if ( '' === $karmcp_cat_slug || '' === $karmcp_cat_label ) {
						continue;
					}
				?>
					<button type="button" class="elementor-mcp-pro-filter" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>">
						<?php echo esc_html( $karmcp_cat_label ); ?>
						<span class="elementor-mcp-pro-filter-count"><?php echo (int) $karmcp_cat_count; ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="elementor-mcp-prompts-grid elementor-mcp-pro-prompts-grid">
				<?php foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) :
					$karmcp_cat_slug  = isset( $karmcp_cat['slug'] ) ? sanitize_key( $karmcp_cat['slug'] ) : '';
					$karmcp_cat_label = isset( $karmcp_cat['label'] ) ? (string) $karmcp_cat['label'] : '';
					if ( '' === $karmcp_cat_slug || empty( $karmcp_cat['prompts'] ) ) {
						continue;
					}
					foreach ( $karmcp_cat['prompts'] as $karmcp_prompt ) :
						$karmcp_p_slug    = isset( $karmcp_prompt['slug'] ) ? sanitize_key( $karmcp_prompt['slug'] ) : '';
						$karmcp_p_title   = isset( $karmcp_prompt['title'] ) ? (string) $karmcp_prompt['title'] : '';
						$karmcp_p_desc    = isset( $karmcp_prompt['description'] ) ? (string) $karmcp_prompt['description'] : '';
						$karmcp_p_content = isset( $karmcp_prompt['content'] ) ? (string) $karmcp_prompt['content'] : '';
						if ( '' === $karmcp_p_slug || '' === $karmcp_p_content ) {
							continue;
						}
						$karmcp_copy_id = 'elementor-mcp-pro-prompt-' . $karmcp_cat_slug . '-' . $karmcp_p_slug;
					?>
						<div class="elementor-mcp-prompt-card elementor-mcp-pro-prompt-card" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>" data-prompt-slug="<?php echo esc_attr( $karmcp_p_slug ); ?>">
							<div class="elementor-mcp-prompt-header">
								<h3 class="elementor-mcp-prompt-title"><?php echo esc_html( $karmcp_p_title ); ?></h3>
								<span class="elementor-mcp-prompt-tag"><?php echo esc_html( $karmcp_cat_label ); ?></span>
							</div>
							<p class="elementor-mcp-prompt-desc"><?php echo esc_html( $karmcp_p_desc ); ?></p>
							<div class="elementor-mcp-prompt-actions">
								<button type="button" class="button elementor-mcp-copy-btn" data-target="<?php echo esc_attr( $karmcp_copy_id ); ?>">
									<svg viewBox="0 0 20 20" width="14" height="14" xmlns="http://www.w3.org/2000/svg"><path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/><path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/></svg>
									<?php esc_html_e( 'Copy Prompt', 'karmcp' ); ?>
								</button>
							</div>
							<textarea id="<?php echo esc_attr( $karmcp_copy_id ); ?>" class="elementor-mcp-copy-source"><?php echo esc_textarea( $karmcp_p_content ); ?></textarea>
						</div>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</div>
		</div>

	<?php elseif ( $karmcp_has_pro && $karmcp_pro_error ) : ?>

		<div class="elementor-mcp-pro-prompts">
			<div class="notice notice-warning inline">
				<p>
					<?php echo esc_html( $karmcp_pro_error ); ?>
				</p>
				<p class="elementor-mcp-pro-prompts-actions">
					<button
						type="button"
						class="button elementor-mcp-pro-sync-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_sync_pro_prompts' ) ); ?>"
					>
						<?php esc_html_e( 'Retry Sync', 'karmcp' ); ?>
					</button>
					<?php if ( $karmcp_v1_available ) : ?>
						<a href="<?php echo esc_url( $karmcp_v1_url ); ?>" class="button elementor-mcp-legacy-btn">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Download v1 Prompts', 'karmcp' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
		</div>

	<?php else : ?>

	<?php endif; ?>

</div>
