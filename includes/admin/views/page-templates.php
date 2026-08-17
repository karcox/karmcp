<?php
/**
 * Templates tab view.
 *
 * Pro users: categorized grid of premium Elementor templates with per-card
 * "Apply to new page" button + a "Sync Library" refresh button.
 * Free users: upgrade CTA.
 *
 * @package KarMCP
 * @since   1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_has_pro    = class_exists( 'KarMCP_Pro_Templates' ) && KarMCP_Pro_Templates::user_has_access();
$karmcp_pro_bundle = null;
$karmcp_pro_error  = null;
if ( $karmcp_has_pro ) {
	$karmcp_pro_result = KarMCP_Pro_Templates::get_bundle();
	if ( is_wp_error( $karmcp_pro_result ) ) {
		$karmcp_pro_error = $karmcp_pro_result->get_error_message();
	} else {
		$karmcp_pro_bundle = $karmcp_pro_result;
	}
}

?>

<div class="karmcp-templates">

	<?php if ( ! KarMCP_Bootstrap::elementor_active() ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e( 'Templates import ready-made designs into Elementor. Install and activate Elementor to use this feature.', 'karmcp' ); ?>
			<a href="<?php echo esc_url( self_admin_url( 'plugin-install.php?s=Elementor&tab=search&type=term' ) ); ?>">
				<?php esc_html_e( 'Install Elementor', 'karmcp' ); ?>
			</a>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( $karmcp_has_pro && is_array( $karmcp_pro_bundle ) ) :
		// Global per-template usage counts for the "Used N times" card badges.
		// This also warms the transient the dashboard's cached_counts() reads.
		$karmcp_usage_counts = class_exists( 'KarMCP_Pro_Usage' ) ? KarMCP_Pro_Usage::get_counts() : array();
		$karmcp_total = 0;
		foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) {
			$karmcp_total += is_array( $karmcp_cat['templates'] ?? null ) ? count( $karmcp_cat['templates'] ) : 0;
		}
	?>

		<div class="karmcp-pro-prompts">
			<div class="karmcp-pro-prompts-header">
				<div class="karmcp-pro-prompts-heading">
					<h2>
						<?php esc_html_e( 'Premium Templates Library', 'karmcp' ); ?>
						<span class="karmcp-badge karmcp-badge--pro">PRO</span>
					</h2>
					<p class="description">
						<?php
						printf(
							/* translators: %1$d: templates, %2$d: categories */
							esc_html__( '%1$d templates across %2$d categories. Create a new page from a template, or import it into Elementor\'s Saved Templates library.', 'karmcp' ),
							(int) $karmcp_total,
							(int) count( $karmcp_pro_bundle['categories'] )
						);
						?>
						<?php if ( ! empty( $karmcp_pro_bundle['fetched_at'] ) ) : ?>
							<span class="karmcp-pro-prompts-meta">
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
				<button
					type="button"
					class="button karmcp-pro-sync-btn"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_sync_pro_templates' ) ); ?>"
					data-sync-action="karmcp_sync_pro_templates"
				>
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Sync Library', 'karmcp' ); ?>
				</button>
			</div>

			<div class="karmcp-coming-soon" role="status">
				<span class="karmcp-coming-soon__icon" aria-hidden="true">
					<svg viewBox="0 0 20 20" width="16" height="16" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M10 2a1 1 0 011 1v1.05a6.002 6.002 0 015 5.95v3.382l1.447 2.894A1 1 0 0116.553 18H3.447a1 1 0 01-.894-1.724L4 13.382V10a6.002 6.002 0 015-5.95V3a1 1 0 011-1zm-2 17a2 2 0 104 0H8z"/></svg>
				</span>
				<div class="karmcp-coming-soon__text">
					<strong><?php esc_html_e( '50+ more premium templates on the way.', 'karmcp' ); ?></strong>
					<?php esc_html_e( 'We\'re actively expanding the library across every category. Click Sync Library above whenever you want the latest.', 'karmcp' ); ?>
				</div>
			</div>

			<?php if ( $karmcp_total > 0 ) : ?>
				<div class="karmcp-pro-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter by category', 'karmcp' ); ?>">
					<button type="button" class="karmcp-pro-filter is-active" data-category="all">
						<?php esc_html_e( 'All', 'karmcp' ); ?>
						<span class="karmcp-pro-filter-count"><?php echo (int) $karmcp_total; ?></span>
					</button>
					<?php foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) :
						$karmcp_cat_slug  = isset( $karmcp_cat['slug'] ) ? sanitize_key( $karmcp_cat['slug'] ) : '';
						$karmcp_cat_label = isset( $karmcp_cat['label'] ) ? (string) $karmcp_cat['label'] : '';
						$karmcp_cat_count = is_array( $karmcp_cat['templates'] ?? null ) ? count( $karmcp_cat['templates'] ) : 0;
						if ( '' === $karmcp_cat_slug || '' === $karmcp_cat_label ) {
							continue;
						}
					?>
						<button type="button" class="karmcp-pro-filter" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>">
							<?php echo esc_html( $karmcp_cat_label ); ?>
							<span class="karmcp-pro-filter-count"><?php echo (int) $karmcp_cat_count; ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div
					class="karmcp-template-grid"
					data-apply-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_apply_pro_template' ) ); ?>"
					data-import-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_import_pro_template' ) ); ?>"
				>
					<?php foreach ( $karmcp_pro_bundle['categories'] as $karmcp_cat ) :
						$karmcp_cat_slug  = isset( $karmcp_cat['slug'] ) ? sanitize_key( $karmcp_cat['slug'] ) : '';
						$karmcp_cat_label = isset( $karmcp_cat['label'] ) ? (string) $karmcp_cat['label'] : '';
						if ( '' === $karmcp_cat_slug || empty( $karmcp_cat['templates'] ) ) {
							continue;
						}
						foreach ( $karmcp_cat['templates'] as $karmcp_tpl ) :
							$karmcp_t_slug    = isset( $karmcp_tpl['slug'] ) ? sanitize_key( $karmcp_tpl['slug'] ) : '';
							$karmcp_t_title   = isset( $karmcp_tpl['title'] ) ? (string) $karmcp_tpl['title'] : '';
							$karmcp_t_desc    = isset( $karmcp_tpl['description'] ) ? (string) $karmcp_tpl['description'] : '';
							$karmcp_t_thumb   = isset( $karmcp_tpl['thumbnail_url'] ) ? (string) $karmcp_tpl['thumbnail_url'] : '';
							$karmcp_t_preview = isset( $karmcp_tpl['preview_url'] ) ? (string) $karmcp_tpl['preview_url'] : '';
							if ( '' === $karmcp_t_slug ) {
								continue;
							}
						?>
							<div class="karmcp-template-card" data-category="<?php echo esc_attr( $karmcp_cat_slug ); ?>">
								<?php if ( '' !== $karmcp_t_thumb ) : ?>
									<div class="karmcp-template-thumb">
										<img src="<?php echo esc_url( $karmcp_t_thumb ); ?>" alt="<?php echo esc_attr( $karmcp_t_title ); ?>" loading="lazy" />
									</div>
								<?php else : ?>
									<div class="karmcp-template-thumb karmcp-template-thumb--placeholder" aria-hidden="true">
										<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 4a2 2 0 012-2h14a2 2 0 012 2v16a2 2 0 01-2 2H5a2 2 0 01-2-2V4zm2 0v16h14V4H5zm2 3h10v2H7V7zm0 4h10v2H7v-2zm0 4h6v2H7v-2z"/></svg>
									</div>
								<?php endif; ?>
								<div class="karmcp-template-body">
									<div class="karmcp-template-header">
										<h3 class="karmcp-template-title"><?php echo esc_html( $karmcp_t_title ); ?></h3>
										<span class="karmcp-prompt-tag"><?php echo esc_html( $karmcp_cat_label ); ?></span>
									</div>
										<?php
										$karmcp_used = (int) ( $karmcp_usage_counts[ 'template:' . $karmcp_t_slug ] ?? 0 );
										if ( $karmcp_used > 0 ) :
											?>
											<span class="karmcp-template-usage"><?php echo esc_html( sprintf( /* translators: %s: times applied */ _n( 'Used %s time', 'Used %s times', $karmcp_used, 'karmcp' ), number_format_i18n( $karmcp_used ) ) ); ?></span>
										<?php endif; ?>
									<?php if ( '' !== $karmcp_t_desc ) : ?>
										<p class="karmcp-template-desc"><?php echo esc_html( $karmcp_t_desc ); ?></p>
									<?php endif; ?>
									<div class="karmcp-template-actions">
										<?php if ( '' !== $karmcp_t_preview ) : ?>
											<a
												href="<?php echo esc_url( $karmcp_t_preview ); ?>"
												class="button karmcp-template-preview"
												target="_blank"
												rel="noopener noreferrer"
												title="<?php esc_attr_e( 'Open the live demo of this template in a new tab.', 'karmcp' ); ?>"
											>
												<svg viewBox="0 0 20 20" width="14" height="14" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
													<path d="M10 4C5 4 1.7 10 1.7 10S5 16 10 16s8.3-6 8.3-6S15 4 10 4zm0 10a4 4 0 110-8 4 4 0 010 8zm0-2a2 2 0 100-4 2 2 0 000 4z" />
												</svg>
												<?php esc_html_e( 'Live Preview', 'karmcp' ); ?>
											</a>
										<?php endif; ?>
										<div class="karmcp-template-actions-row">
											<button
												type="button"
												class="button button-primary karmcp-template-apply"
												data-category-slug="<?php echo esc_attr( $karmcp_cat_slug ); ?>"
												data-template-slug="<?php echo esc_attr( $karmcp_t_slug ); ?>"
											>
												<?php esc_html_e( 'Create Page', 'karmcp' ); ?>
											</button>
											<button
												type="button"
												class="button karmcp-template-import"
												data-category-slug="<?php echo esc_attr( $karmcp_cat_slug ); ?>"
												data-template-slug="<?php echo esc_attr( $karmcp_t_slug ); ?>"
												title="<?php esc_attr_e( 'Add to Elementor\'s Saved Templates library, insertable from the editor\'s Add Template picker on any page.', 'karmcp' ); ?>"
											>
												<?php esc_html_e( 'Import to Library', 'karmcp' ); ?>
											</button>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>

			<?php else : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'The Premium Templates library is empty right now. Templates added on the server will appear here on the next sync.', 'karmcp' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

	<?php elseif ( $karmcp_has_pro && $karmcp_pro_error ) : ?>

		<div class="karmcp-pro-prompts">
			<div class="notice notice-warning inline">
				<p><?php echo esc_html( $karmcp_pro_error ); ?></p>
				<p>
					<button
						type="button"
						class="button karmcp-pro-sync-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'karmcp_sync_pro_templates' ) ); ?>"
						data-sync-action="karmcp_sync_pro_templates"
					>
						<?php esc_html_e( 'Retry Sync', 'karmcp' ); ?>
					</button>
				</p>
			</div>
		</div>

	<?php else : ?>

	<?php endif; ?>

</div>
