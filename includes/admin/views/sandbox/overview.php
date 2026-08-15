<?php
/**
 * Sandbox overview — the parent Sandbox page.
 *
 * Three flat navigation cards (Blocks / Widgets / PHP Snippets). Each card is a
 * single click target into that pillar's full management screen
 * (?page=karmcp-widgets&view=blocks|widgets|snippets), with a compact count
 * line and a hover affordance. No gradients.
 *
 * @package KarMCP
 * @since   3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_sb_base_url    = menu_page_url( 'karmcp-widgets', false );

/**
 * Splits a store's list into an [active, draft] count pair.
 *
 * @param array $list Rows each carrying a 'status' key.
 * @return array{0:int,1:int}
 */
$karmcp_sb_counts = static function ( array $list ): array {
	$active = 0;
	$draft  = 0;
	foreach ( $list as $row ) {
		if ( 'active' === ( $row['status'] ?? '' ) ) {
			++$active;
		} else {
			++$draft;
		}
	}
	return array( $active, $draft );
};

// ----- Blocks -----
$karmcp_sb_blocks_ok = class_exists( 'KarMCP_Block_Store' ) && KarMCP_Block_Store::user_has_access();
list( $karmcp_sb_bl_active, $karmcp_sb_bl_draft ) = $karmcp_sb_counts(
	$karmcp_sb_blocks_ok ? KarMCP_Block_Store::instance()->list_blocks( 'any' ) : array()
);

// ----- Widgets -----
$karmcp_sb_widgets_ok = class_exists( 'KarMCP_Widget_Store' ) && KarMCP_Widget_Store::user_has_access();
list( $karmcp_sb_wd_active, $karmcp_sb_wd_draft ) = $karmcp_sb_counts(
	$karmcp_sb_widgets_ok ? KarMCP_Widget_Store::list_widgets( 'any' ) : array()
);

// ----- Element extensions -----
$karmcp_sb_ext_ok = class_exists( 'KarMCP_Extension_Store' ) && KarMCP_Extension_Store::user_has_access();
list( $karmcp_sb_ex_active, $karmcp_sb_ex_draft ) = $karmcp_sb_counts(
	$karmcp_sb_ext_ok ? KarMCP_Extension_Store::instance()->list_extensions( 'any' ) : array()
);

// ----- PHP Snippets (capability-gated) -----
$karmcp_sb_snip_ok = class_exists( 'KarMCP_PHP_Snippet_Store' ) && KarMCP_PHP_Snippet_Store::can_edit();
list( $karmcp_sb_sn_active, $karmcp_sb_sn_draft ) = $karmcp_sb_counts(
	class_exists( 'KarMCP_PHP_Snippet_Store' ) ? KarMCP_PHP_Snippet_Store::list_snippets( 'any' ) : array()
);

$karmcp_sb_cards = array(
	array(
		'view'        => 'blocks',
		'label'       => __( 'Blocks', 'karmcp' ),
		'icon'        => 'dashicons-block-default',
		'ico_mod'     => 'usage',
		'desc'        => __( 'AI-generated custom Gutenberg blocks, compiled from a spec and reviewed here before they go live.', 'karmcp' ),
		'available'   => $karmcp_sb_blocks_ok,
		'active'      => $karmcp_sb_bl_active,
		'draft'       => $karmcp_sb_bl_draft,
		'note'        => __( 'Needs permission', 'karmcp' ),
	),
	array(
		'view'        => 'widgets',
		'label'       => __( 'Widgets', 'karmcp' ),
		'icon'        => 'dashicons-screenoptions',
		'ico_mod'     => 'tools',
		'desc'        => __( 'AI-generated custom Elementor widgets, sandboxed and shown in the panel under "Custom (KarMCP)".', 'karmcp' ),
		'available'   => $karmcp_sb_widgets_ok,
		'active'      => $karmcp_sb_wd_active,
		'draft'       => $karmcp_sb_wd_draft,
		'note'        => __( 'Needs permission', 'karmcp' ),
	),
	array(
		'view'        => 'extensions',
		'label'       => __( 'Extensions', 'karmcp' ),
		'icon'        => 'dashicons-admin-plugins',
		'ico_mod'     => 'sandbox',
		'desc'        => __( 'Options your AI agent added to Elementor\'s own elements: a control in the panel of any container that switches an effect on.', 'karmcp' ),
		'available'   => $karmcp_sb_ext_ok,
		'active'      => $karmcp_sb_ex_active,
		'draft'       => $karmcp_sb_ex_draft,
		'note'        => __( 'Needs permission', 'karmcp' ),
	),
	array(
		'view'        => 'snippets',
		'label'       => __( 'PHP Snippets', 'karmcp' ),
		'icon'        => 'dashicons-editor-code',
		'ico_mod'     => 'sandbox',
		'desc'        => __( 'Small PHP snippets an AI agent can draft, run as a shortcode or on a hook, that stay inactive until you review and activate them.', 'karmcp' ),
		'available'   => $karmcp_sb_snip_ok,
		'active'      => $karmcp_sb_sn_active,
		'draft'       => $karmcp_sb_sn_draft,
		'note'        => __( 'Needs permission', 'karmcp' ),
	),
);
?>

<div class="karmcp-sandbox-overview">
	<h2><?php esc_html_e( 'Sandbox', 'karmcp' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Code your AI agent generated through the MCP tools lives here, isolated under wp-content/karmcp-sandbox, never in your theme, core, or other plugins.', 'karmcp' ); ?>
	</p>

	<div class="karmcp-sandbox-cards">
		<?php foreach ( $karmcp_sb_cards as $karmcp_c ) : ?>
			<a class="karmcp-sb-card" href="<?php echo esc_url( $karmcp_sb_base_url . '&view=' . $karmcp_c['view'] ); ?>">
				<span class="karmcp-sb-card-top">
					<span class="karmcp-sb-card-ico karmcp-dash-ucard-ico--<?php echo esc_attr( $karmcp_c['ico_mod'] ); ?>">
						<span class="dashicons <?php echo esc_attr( $karmcp_c['icon'] ); ?>" aria-hidden="true"></span>
					</span>
					<span class="karmcp-sb-card-titles">
						<span class="karmcp-sb-card-title"><?php echo esc_html( $karmcp_c['label'] ); ?></span>
					</span>
					<span class="karmcp-sb-card-arrow" aria-hidden="true">&rarr;</span>
				</span>

				<span class="karmcp-sb-card-desc"><?php echo esc_html( $karmcp_c['desc'] ); ?></span>

				<span class="karmcp-sb-card-meta">
					<?php if ( $karmcp_c['available'] ) : ?>
						<span class="karmcp-sb-card-meta-active"><?php echo esc_html( number_format_i18n( $karmcp_c['active'] ) ); ?></span>
						<?php esc_html_e( 'active', 'karmcp' ); ?>
						<span class="karmcp-sb-card-dot">&middot;</span>
						<span class="karmcp-sb-card-meta-draft"><?php echo esc_html( number_format_i18n( $karmcp_c['draft'] ) ); ?></span>
						<?php esc_html_e( 'drafts', 'karmcp' ); ?>
					<?php else : ?>
						<span class="karmcp-sb-card-locked">
							<span class="dashicons dashicons-lock" aria-hidden="true"></span>
							<?php echo esc_html( $karmcp_c['note'] ); ?>
						</span>
					<?php endif; ?>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
