<?php
/**
 * Dashboard tab — the landing screen for KarMCP.
 *
 * Shows the headline stat cards (large format), a sneak-peek grid of every
 * feature area that doubles as fast navigation, a row of featured video guides,
 * and a help & resources panel. Included from KarMCP_Admin::render_page(),
 * so `$this` is the admin instance.
 *
 * @package KarMCP
 * @since   3.1.0
 *
 * @var KarMCP_Admin $this
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_page = KarMCP_Admin::PAGE_SLUG;

/**
 * Inline SVGs for the headline stat cards, keyed by the stat `key` returned by
 * KarMCP_Admin::get_dashboard_stats(). Kept here (not in the class) so the
 * data method stays markup-free.
 */
$karmcp_stat_svgs = array(
	'tools'      => '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>',
	'active'     => '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>',
	'pro'        => '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>',
);

/**
 * Feature sneak-peek cards. `href` is the destination; `pro` badges a
 * premium-tier area; `show` gates visibility (module-backed cards drop when
 * their module is off, matching the tab nav).
 */
$karmcp_features = array(
	array(
		'icon'  => 'dashicons-admin-tools',
		'title' => __( 'MCP Tools', 'karmcp' ),
		'desc'  => __( 'Toggle the ~140 abilities your AI client can call, Elementor, WordPress core, and Gutenberg.', 'karmcp' ),
		'href'  => admin_url( 'admin.php?page=' . $karmcp_page . '-tools' ),
		'show'  => true,
	),
	array(
		'icon'  => 'dashicons-admin-links',
		'title' => __( 'Connection', 'karmcp' ),
		'desc'  => __( 'Connect Claude, Cursor, the ChatGPT App and more, copy-paste configs, app passwords, and a one-click bundle.', 'karmcp' ),
		'href'  => admin_url( 'admin.php?page=' . $karmcp_page . '-connection' ),
		'show'  => true,
	),
	array(
		'icon'  => 'dashicons-screenoptions',
		'title' => __( 'Modules', 'karmcp' ),
		'desc'  => __( 'Turn big features on and off: Themer, Image Optimization, Redirects, Login Guard, Known Vulnerabilities and more.', 'karmcp' ),
		'href'  => admin_url( 'admin.php?page=' . $karmcp_page . '-modules' ),
		'show'  => true,
	),
	array(
		'icon'  => 'dashicons-undo',
		'title' => __( 'History', 'karmcp' ),
		'desc'  => __( 'Review every change your AI made and roll any of them back, a unified change ledger with one-click undo.', 'karmcp' ),
		'href'  => admin_url( 'admin.php?page=' . $karmcp_page . '-history' ),
		'show'  => true,
	),
	array(
		'icon'  => 'dashicons-layout',
		'title' => __( 'KarMCP Themer', 'karmcp' ),
		'desc'  => __( 'Build headers, footers, and dynamic layouts with any page builder, assigned by display conditions.', 'karmcp' ),
		'href'  => admin_url( 'edit.php?post_type=karmcp_theme_tpl' ),
		'show'  => class_exists( 'KarMCP_Themer_Module' ) && KarMCP_Themer_Module::is_enabled(),
	),
	array(
		'icon'  => 'dashicons-editor-code',
		'title' => __( 'PHP Sandbox', 'karmcp' ),
		'desc'  => __( 'Review and activate AI-authored PHP snippets behind a human approval gate, nothing runs unattended.', 'karmcp' ),
		'href'  => admin_url( 'admin.php?page=' . $karmcp_page . '-widgets' ),
		'show'  => true,
	),
);

/**
 * Featured video guides. Empty by design: each entry renders a thumbnail
 * straight from i.ytimg.com, so a populated list makes every dashboard load
 * call YouTube. Add our own videos here when we have them — `id` is the
 * YouTube video ID (drives both thumbnail and watch link), `channel` the
 * creator. The section below skips itself entirely while this is empty.
 */
$karmcp_videos = array();
?>

<div class="karmcp-dash">

	<!-- Headline stats -->
	<section class="karmcp-dash-stats" aria-label="<?php esc_attr_e( 'At a glance', 'karmcp' ); ?>">
		<?php foreach ( $this->get_dashboard_stats() as $karmcp_stat ) : ?>
			<div class="karmcp-dash-stat">
				<span class="karmcp-dash-stat-icon karmcp-dash-stat-icon--<?php echo esc_attr( $karmcp_stat['key'] ); ?>">
					<?php echo isset( $karmcp_stat_svgs[ $karmcp_stat['key'] ] ) ? $karmcp_stat_svgs[ $karmcp_stat['key'] ] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, trusted inline SVG markup. ?>
				</span>
				<span class="karmcp-dash-stat-body">
					<span class="karmcp-dash-stat-value"><?php echo esc_html( number_format_i18n( $karmcp_stat['value'] ) ); ?></span>
					<span class="karmcp-dash-stat-label"><?php echo esc_html( $karmcp_stat['label'] ); ?></span>
				</span>
			</div>
		<?php endforeach; ?>
	</section>

	<?php
	// Activity pulse: change-ledger overview, most-used actions, and the
	// Sandbox item count.

	// Change-ledger overview + most-used actions.
	$karmcp_log       = class_exists( 'KarMCP_Change_Log' ) ? KarMCP_Change_Log::all() : array();
	$karmcp_changes   = count( $karmcp_log );
	$karmcp_rolled    = 0;
	$karmcp_last_ts   = 0;
	$karmcp_action_ct = array();
	foreach ( $karmcp_log as $karmcp_e ) {
		if ( ! empty( $karmcp_e['rolled_back'] ) ) {
			++$karmcp_rolled;
		}
		if ( isset( $karmcp_e['ts'] ) && (int) $karmcp_e['ts'] > $karmcp_last_ts ) {
			$karmcp_last_ts = (int) $karmcp_e['ts'];
		}
		$karmcp_dom = isset( $karmcp_e['domain'] ) ? (string) $karmcp_e['domain'] : '';
		$karmcp_act = isset( $karmcp_e['action'] ) ? (string) $karmcp_e['action'] : '';
		if ( '' === $karmcp_dom && '' === $karmcp_act ) {
			continue;
		}
		$karmcp_key = $karmcp_dom . '|' . $karmcp_act;
		if ( ! isset( $karmcp_action_ct[ $karmcp_key ] ) ) {
			$karmcp_action_ct[ $karmcp_key ] = 0;
		}
		++$karmcp_action_ct[ $karmcp_key ];
	}
	arsort( $karmcp_action_ct );
	$karmcp_top_actions = array_slice( $karmcp_action_ct, 0, 4, true );

	// Sandbox items across all three pillars (blocks + widgets + snippets):
	// active = publish, drafts = draft. Blocks/widgets are Pro CPTs; on a free
	// site they simply do not exist and wp_count_posts() returns zeros.
	$karmcp_snip_active = 0;
	$karmcp_snip_draft  = 0;
	if ( function_exists( 'wp_count_posts' ) ) {
		foreach ( array( 'karmcp_block', 'karmcp_widget', 'karmcp_php_snippet' ) as $karmcp_sb_cpt ) {
			$karmcp_ct = wp_count_posts( $karmcp_sb_cpt );
			$karmcp_snip_active += ( $karmcp_ct && isset( $karmcp_ct->publish ) ) ? (int) $karmcp_ct->publish : 0;
			$karmcp_snip_draft  += ( $karmcp_ct && isset( $karmcp_ct->draft ) ) ? (int) $karmcp_ct->draft : 0;
		}
	}
	$karmcp_snip_total = $karmcp_snip_active + $karmcp_snip_draft;

	$karmcp_url_history = admin_url( 'admin.php?page=' . $karmcp_page . '-history' );
	$karmcp_url_sandbox = admin_url( 'admin.php?page=' . $karmcp_page . '-widgets' );
	?>
	<section class="karmcp-dash-section" aria-labelledby="karmcp-dash-usage-h">
		<div class="karmcp-dash-section-head">
			<h2 id="karmcp-dash-usage-h" class="karmcp-dash-section-title"><?php esc_html_e( 'Your usage', 'karmcp' ); ?></h2>
			<p class="karmcp-dash-section-sub"><?php esc_html_e( 'A quick pulse on what your AI has done on this site.', 'karmcp' ); ?></p>
		</div>
		<div class="karmcp-dash-usage-grid">

			<!-- History overview -->
			<a class="karmcp-dash-ucard" href="<?php echo esc_url( $karmcp_url_history ); ?>">
				<span class="karmcp-dash-ucard-head">
					<span class="karmcp-dash-ucard-ico karmcp-dash-ucard-ico--history"><span class="dashicons dashicons-undo" aria-hidden="true"></span></span>
					<span class="karmcp-dash-ucard-title"><?php esc_html_e( 'History', 'karmcp' ); ?></span>
				</span>
				<span class="karmcp-dash-ucard-num"><?php echo esc_html( number_format_i18n( $karmcp_changes ) ); ?></span>
				<span class="karmcp-dash-ucard-sub"><?php esc_html_e( 'changes recorded', 'karmcp' ); ?></span>
				<span class="karmcp-dash-ucard-foot">
					<?php
					if ( $karmcp_last_ts > 0 ) {
						printf(
							/* translators: 1: rolled-back count, 2: human-readable time since last change */
							esc_html__( '%1$s rolled back · last %2$s ago', 'karmcp' ),
							esc_html( number_format_i18n( $karmcp_rolled ) ),
							esc_html( human_time_diff( $karmcp_last_ts, time() ) )
						);
					} else {
						esc_html_e( 'No changes recorded yet', 'karmcp' );
					}
					?>
				</span>
			</a>

			<!-- Most used actions -->
			<a class="karmcp-dash-ucard" href="<?php echo esc_url( $karmcp_url_history ); ?>">
				<span class="karmcp-dash-ucard-head">
					<span class="karmcp-dash-ucard-ico karmcp-dash-ucard-ico--tools"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span></span>
					<span class="karmcp-dash-ucard-title"><?php esc_html_e( 'Most used', 'karmcp' ); ?></span>
				</span>
				<?php if ( ! empty( $karmcp_top_actions ) ) : ?>
					<ul class="karmcp-dash-ucard-list">
						<?php
						foreach ( $karmcp_top_actions as $karmcp_key => $karmcp_cnt ) :
							$karmcp_parts = explode( '|', $karmcp_key, 2 );
							$karmcp_dom   = $karmcp_parts[0];
							$karmcp_act   = isset( $karmcp_parts[1] ) ? $karmcp_parts[1] : '';
							?>
							<li>
								<span class="karmcp-dash-ucard-act"><?php if ( '' !== $karmcp_dom ) : ?><span class="karmcp-dash-ucard-dom"><?php echo esc_html( $karmcp_dom ); ?> </span><?php endif; ?><?php echo esc_html( $karmcp_act ); ?></span>
								<span class="karmcp-dash-ucard-cnt"><?php echo esc_html( number_format_i18n( $karmcp_cnt ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<span class="karmcp-dash-ucard-empty"><?php esc_html_e( 'No activity yet', 'karmcp' ); ?></span>
				<?php endif; ?>
			</a>

			<!-- Sandbox items -->
			<a class="karmcp-dash-ucard" href="<?php echo esc_url( $karmcp_url_sandbox ); ?>">
				<span class="karmcp-dash-ucard-head">
					<span class="karmcp-dash-ucard-ico karmcp-dash-ucard-ico--sandbox"><span class="dashicons dashicons-editor-code" aria-hidden="true"></span></span>
					<span class="karmcp-dash-ucard-title"><?php esc_html_e( 'Sandbox', 'karmcp' ); ?></span>
				</span>
				<span class="karmcp-dash-ucard-num"><?php echo esc_html( number_format_i18n( $karmcp_snip_total ) ); ?></span>
				<span class="karmcp-dash-ucard-sub"><?php esc_html_e( 'Blocks, widgets & snippets', 'karmcp' ); ?></span>
				<span class="karmcp-dash-ucard-foot">
					<?php
					printf(
						/* translators: 1: active sandbox-item count, 2: draft sandbox-item count */
						esc_html__( '%1$s active · %2$s drafts', 'karmcp' ),
						esc_html( number_format_i18n( $karmcp_snip_active ) ),
						esc_html( number_format_i18n( $karmcp_snip_draft ) )
					);
					?>
				</span>
			</a>

		</div>
	</section>

	<!-- Feature sneak peek -->
	<!-- Explore your toolkit + KarMCP Cloud banner, side by side (75/25) -->
	<div class="karmcp-dash-row karmcp-dash-row--toolkit">
	<section class="karmcp-dash-section karmcp-dash-section--toolkit" aria-labelledby="karmcp-dash-features-h">
		<div class="karmcp-dash-section-head">
			<h2 id="karmcp-dash-features-h" class="karmcp-dash-section-title"><?php esc_html_e( 'Explore your toolkit', 'karmcp' ); ?></h2>
			<p class="karmcp-dash-section-sub"><?php esc_html_e( 'Everything this plugin can do, jump straight in.', 'karmcp' ); ?></p>
		</div>
		<div class="karmcp-dash-grid">
			<?php
			foreach ( $karmcp_features as $karmcp_feature ) :
				if ( empty( $karmcp_feature['show'] ) ) {
					continue;
				}
				?>
				<a class="karmcp-dash-card" href="<?php echo esc_url( $karmcp_feature['href'] ); ?>">
					<span class="karmcp-dash-card-icon"><span class="dashicons <?php echo esc_attr( $karmcp_feature['icon'] ); ?>" aria-hidden="true"></span></span>
					<span class="karmcp-dash-card-body">
						<span class="karmcp-dash-card-title">
							<?php echo esc_html( $karmcp_feature['title'] ); ?>
						</span>
						<span class="karmcp-dash-card-desc"><?php echo esc_html( $karmcp_feature['desc'] ); ?></span>
					</span>
					<span class="karmcp-dash-card-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>

	</div><!-- .karmcp-dash-row--toolkit -->

	<!-- Video guides + help, side by side (70/30) -->
	<div class="karmcp-dash-row">

	<!-- Featured video guides -->
	<?php if ( ! empty( $karmcp_videos ) ) : ?>
	<section class="karmcp-dash-section karmcp-dash-section--videos" aria-labelledby="karmcp-dash-videos-h">
		<div class="karmcp-dash-section-head">
			<h2 id="karmcp-dash-videos-h" class="karmcp-dash-section-title"><?php esc_html_e( 'Featured video guides', 'karmcp' ); ?></h2>
			<p class="karmcp-dash-section-sub"><?php esc_html_e( 'Watch and learn, from first connection to full-page builds.', 'karmcp' ); ?></p>
		</div>
		<div class="karmcp-dash-videos">
			<?php
			foreach ( $karmcp_videos as $karmcp_video ) :
				$karmcp_video_url = 'https://www.youtube.com/watch?v=' . rawurlencode( $karmcp_video['id'] );
				$karmcp_video_img = 'https://i.ytimg.com/vi/' . rawurlencode( $karmcp_video['id'] ) . '/hqdefault.jpg';
				?>
				<a class="karmcp-dash-video" href="<?php echo esc_url( $karmcp_video_url ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="karmcp-dash-video-thumb">
						<img class="karmcp-dash-video-img" src="<?php echo esc_url( $karmcp_video_img ); ?>" alt="" loading="lazy" />
						<span class="karmcp-dash-video-play" aria-hidden="true"><span class="dashicons dashicons-controls-play"></span></span>
					</span>
					<span class="karmcp-dash-video-meta">
						<span class="karmcp-dash-video-title"><?php echo esc_html( $karmcp_video['title'] ); ?></span>
						<span class="karmcp-dash-video-channel"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span><?php echo esc_html( $karmcp_video['channel'] ); ?></span>
					</span>
				</a>
			<?php endforeach; ?>
			<a class="karmcp-dash-video karmcp-dash-video--more" href="<?php echo esc_url( KarMCP_Admin::DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="karmcp-dash-more-inner">
					<span class="karmcp-dash-more-icon"><span class="dashicons dashicons-playlist-video" aria-hidden="true"></span></span>
					<span class="karmcp-dash-more-title"><?php esc_html_e( 'Read the docs', 'karmcp' ); ?></span>
					<span class="karmcp-dash-more-sub"><?php esc_html_e( 'Guides and reference', 'karmcp' ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></span>
				</span>
			</a>
		</div>
	</section>
	<?php endif; ?>

	<!-- Help & resources -->
	<section class="karmcp-dash-section karmcp-dash-section--help" aria-labelledby="karmcp-dash-help-h">
		<div class="karmcp-dash-section-head">
			<h2 id="karmcp-dash-help-h" class="karmcp-dash-section-title"><?php esc_html_e( 'Help &amp; resources', 'karmcp' ); ?></h2>
			<p class="karmcp-dash-section-sub"><?php esc_html_e( 'Quick links to the free and premium support channels.', 'karmcp' ); ?></p>
		</div>
		<?php
		// This build updates manually (no auto-updater), so the installed
		// version is always "latest" as far as the dashboard knows.
		$karmcp_ver = array(
			'current'          => KARMCP_VERSION,
			'latest'           => KARMCP_VERSION,
			'update_available' => false,
			'update_url'       => admin_url( 'plugins.php' ),
		);
		?>
		<?php if ( ! empty( $karmcp_ver['update_available'] ) ) : ?>
			<a class="karmcp-dash-version karmcp-dash-version--update" href="<?php echo esc_url( $karmcp_ver['update_url'] ); ?>">
				<span class="karmcp-dash-version-dot" aria-hidden="true"></span>
				<span class="karmcp-dash-version-text">
					<span class="karmcp-dash-version-title"><?php esc_html_e( 'Update available', 'karmcp' ); ?></span>
					<span class="karmcp-dash-version-sub">
						<?php
						printf(
							/* translators: 1: installed version, 2: available version */
							esc_html__( 'You have v%1$s, v%2$s is ready to install.', 'karmcp' ),
							esc_html( $karmcp_ver['current'] ),
							esc_html( $karmcp_ver['latest'] )
						);
						?>
					</span>
				</span>
				<span class="karmcp-dash-version-cta"><?php esc_html_e( 'Update now', 'karmcp' ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></span>
			</a>
		<?php else : ?>
			<div class="karmcp-dash-version karmcp-dash-version--ok">
				<span class="karmcp-dash-version-dot" aria-hidden="true"></span>
				<span class="karmcp-dash-version-text">
					<span class="karmcp-dash-version-title"><?php esc_html_e( 'You\'re on the latest version', 'karmcp' ); ?></span>
					<span class="karmcp-dash-version-sub">
						<?php
						printf(
							/* translators: %s: installed version number */
							esc_html__( 'KarMCP v%s', 'karmcp' ),
							esc_html( $karmcp_ver['current'] )
						);
						?>
					</span>
				</span>
			</div>
		<?php endif; ?>
		<div class="karmcp-dash-help">
			<a class="karmcp-dash-help-link" href="<?php echo esc_url( KarMCP_Admin::DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-book" aria-hidden="true"></span>
				<span>
					<span class="karmcp-dash-help-title"><?php esc_html_e( 'Documentation', 'karmcp' ); ?></span>
					<span class="karmcp-dash-help-desc"><?php esc_html_e( 'Guides and reference for every feature.', 'karmcp' ); ?></span>
				</span>
			</a>
			<a class="karmcp-dash-help-link" href="<?php echo esc_url( KarMCP_Admin::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-sos" aria-hidden="true"></span>
				<span>
					<span class="karmcp-dash-help-title"><?php esc_html_e( 'Support', 'karmcp' ); ?></span>
					<span class="karmcp-dash-help-desc"><?php esc_html_e( 'Stuck? Report a problem or ask a question.', 'karmcp' ); ?></span>
				</span>
			</a>
			<a class="karmcp-dash-help-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $karmcp_page . '-changelog' ) ); ?>">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<span>
					<span class="karmcp-dash-help-title"><?php esc_html_e( 'Changelog', 'karmcp' ); ?></span>
					<span class="karmcp-dash-help-desc"><?php esc_html_e( 'See what\'s new in the latest releases.', 'karmcp' ); ?></span>
				</span>
			</a>
		</div>
	</section>

	</div><!-- .karmcp-dash-row -->

</div>
