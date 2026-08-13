<?php
/**
 * Marketplace tab: browse published KarMCP Cloud listings and install them into
 * this site (as draft artifacts). Requires an active KarMCP Cloud connection.
 *
 * @package KarMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_connected = class_exists( 'KarMCP_Cloud' ) && KarMCP_Cloud::is_connected();
$karmcp_kind_label = array(
	'block'       => __( 'Gutenberg block', 'karmcp' ),
	'widget'      => __( 'Elementor widget', 'karmcp' ),
	'snippet'     => __( 'PHP snippet', 'karmcp' ),
	'php_snippet' => __( 'PHP snippet', 'karmcp' ),
	'template'    => __( 'Template', 'karmcp' ),
);
$karmcp_mk       = isset( $_GET['mk'] ) ? sanitize_key( wp_unslash( $_GET['mk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_mk_id    = isset( $_GET['mk_id'] ) ? absint( wp_unslash( $_GET['mk_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
// A freshly-installed draft is a sandbox CPT with no standard post editor, so
// get_edit_post_link() is empty. Route to its Sandbox management screen instead.
$karmcp_mk_views = array( 'karmcp_widget' => 'widgets', 'karmcp_block' => 'blocks', 'karmcp_php_snippet' => 'snippets' );
$karmcp_mk_type  = $karmcp_mk_id ? get_post_type( $karmcp_mk_id ) : '';
$karmcp_mk_edit  = isset( $karmcp_mk_views[ $karmcp_mk_type ] )
	? add_query_arg( array( 'page' => 'karmcp-widgets', 'view' => $karmcp_mk_views[ $karmcp_mk_type ] ), admin_url( 'admin.php' ) )
	: '';
$karmcp_category = isset( $_GET['mk_cat'] ) ? sanitize_text_field( wp_unslash( $_GET['mk_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_q        = isset( $_GET['mk_q'] ) ? sanitize_text_field( wp_unslash( $_GET['mk_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_f_kind   = isset( $_GET['mk_kind'] ) ? sanitize_key( wp_unslash( $_GET['mk_kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_f_access = isset( $_GET['mk_access'] ) ? sanitize_key( wp_unslash( $_GET['mk_access'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_sort     = isset( $_GET['mk_sort'] ) ? sanitize_key( wp_unslash( $_GET['mk_sort'] ) ) : 'newest'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_page     = isset( $_GET['mk_page'] ) ? max( 1, absint( wp_unslash( $_GET['mk_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$karmcp_per_page = 24;
?>
<div class="elementor-mcp-section">
	<h2><?php esc_html_e( 'Marketplace', 'karmcp' ); ?></h2>
	<p class="elementor-mcp-activate-note">
		<?php esc_html_e( 'Browse community and Pro blocks, widgets, snippets, and templates from KarMCP Cloud and install them into this site. Installs land as a draft for you to review before publishing.', 'karmcp' ); ?>
	</p>

	<?php if ( 'installed' === $karmcp_mk ) : ?>
		<div class="notice notice-success inline"><p>
			<?php esc_html_e( 'Installed as a draft.', 'karmcp' ); ?>
			<?php if ( $karmcp_mk_edit ) : ?>
				<a href="<?php echo esc_url( $karmcp_mk_edit ); ?>"><?php esc_html_e( 'Review it →', 'karmcp' ); ?></a>
			<?php endif; ?>
		</p></div>
	<?php elseif ( 'pro' === $karmcp_mk ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'That item requires a paid KarMCP Cloud plan. Upgrade your Cloud plan to install Pro items.', 'karmcp' ); ?></p></div>
	<?php elseif ( 'err' === $karmcp_mk ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'Could not install that item. Please try again.', 'karmcp' ); ?></p></div>
	<?php endif; ?>

	<?php
	if ( ! $karmcp_connected ) :
		?>
		<div class="notice notice-info inline" style="margin-top:12px;">
			<p><?php esc_html_e( 'Connect this site to KarMCP Cloud to browse and install marketplace items.', 'karmcp' ); ?></p>
			<?php if ( class_exists( 'KarMCP_Cloud_Connect' ) ) :
				$karmcp_connect_label = __( 'Connect to KarMCP Cloud', 'karmcp' );
				require KARMCP_DIR . 'includes/admin/views/partials/cloud-connect-form.php';
			else : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-connection' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Connect to KarMCP Cloud', 'karmcp' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
		return;
	endif;

	// Fetch one page, filtered + sorted server-side. Facets come back with the
	// response so the dropdowns always list every kind/category.
	$karmcp_res = KarMCP_Cloud_Sync::marketplace_list(
		array(
			'q'        => $karmcp_q,
			'kind'     => $karmcp_f_kind,
			'category' => $karmcp_category,
			'access'   => $karmcp_f_access,
			'sort'     => $karmcp_sort,
			'page'     => $karmcp_page,
			'per_page' => $karmcp_per_page,
		)
	);
	$karmcp_listings = ( ! is_wp_error( $karmcp_res ) && isset( $karmcp_res['listings'] ) && is_array( $karmcp_res['listings'] ) ) ? $karmcp_res['listings'] : array();

	// Facet options from the response (full distinct set, filter-agnostic).
	$karmcp_facets = ( ! is_wp_error( $karmcp_res ) && isset( $karmcp_res['facets'] ) && is_array( $karmcp_res['facets'] ) ) ? $karmcp_res['facets'] : array();
	$karmcp_kinds  = ( isset( $karmcp_facets['kinds'] ) && is_array( $karmcp_facets['kinds'] ) ) ? $karmcp_facets['kinds'] : array();
	$karmcp_cats   = ( isset( $karmcp_facets['categories'] ) && is_array( $karmcp_facets['categories'] ) ) ? $karmcp_facets['categories'] : array();

	// Pagination meta (present only when the endpoint paginated the response).
	$karmcp_total       = isset( $karmcp_res['total'] ) ? (int) $karmcp_res['total'] : count( $karmcp_listings );
	$karmcp_pages       = isset( $karmcp_res['pages'] ) ? max( 1, (int) $karmcp_res['pages'] ) : 1;
	$karmcp_cur_page    = isset( $karmcp_res['page'] ) ? max( 1, (int) $karmcp_res['page'] ) : $karmcp_page;
	$karmcp_active_filters = ( '' !== $karmcp_q ) + ( '' !== $karmcp_f_kind ) + ( '' !== $karmcp_category ) + ( '' !== $karmcp_f_access );

	// Base URL that preserves the active filters, swapping only the page number.
	$karmcp_page_href = static function ( $n ) use ( $karmcp_q, $karmcp_f_kind, $karmcp_category, $karmcp_f_access, $karmcp_sort ) {
		$args = array( 'page' => 'karmcp-marketplace' );
		if ( '' !== $karmcp_q ) {
			$args['mk_q'] = $karmcp_q;
		}
		if ( '' !== $karmcp_f_kind ) {
			$args['mk_kind'] = $karmcp_f_kind;
		}
		if ( '' !== $karmcp_category ) {
			$args['mk_cat'] = $karmcp_category;
		}
		if ( '' !== $karmcp_f_access ) {
			$args['mk_access'] = $karmcp_f_access;
		}
		if ( 'newest' !== $karmcp_sort && '' !== $karmcp_sort ) {
			$args['mk_sort'] = $karmcp_sort;
		}
		if ( (int) $n > 1 ) {
			$args['mk_page'] = (int) $n;
		}
		return admin_url( 'admin.php?' . http_build_query( $args ) );
	};
	?>

	<?php if ( is_wp_error( $karmcp_res ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $karmcp_res->get_error_message() ); ?></p></div>
	<?php elseif ( 0 === $karmcp_total && 0 === (int) $karmcp_active_filters ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'No published items yet. Check back soon.', 'karmcp' ); ?></p></div>
	<?php else : ?>

		<form method="get" class="karmcp-mk-filters">
			<input type="hidden" name="page" value="karmcp-marketplace" />
			<input type="search" name="mk_q" value="<?php echo esc_attr( $karmcp_q ); ?>" placeholder="<?php esc_attr_e( 'Search marketplace…', 'karmcp' ); ?>" class="karmcp-mk-filters__search" />
			<select name="mk_kind">
				<option value=""><?php esc_html_e( 'All types', 'karmcp' ); ?></option>
				<?php foreach ( $karmcp_kinds as $karmcp_k ) : ?>
					<option value="<?php echo esc_attr( $karmcp_k ); ?>" <?php selected( $karmcp_f_kind, $karmcp_k ); ?>><?php echo esc_html( $karmcp_kind_label[ $karmcp_k ] ?? $karmcp_k ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( ! empty( $karmcp_cats ) ) : ?>
				<select name="mk_cat">
					<option value=""><?php esc_html_e( 'All categories', 'karmcp' ); ?></option>
					<?php foreach ( $karmcp_cats as $karmcp_c ) : ?>
						<option value="<?php echo esc_attr( $karmcp_c ); ?>" <?php selected( $karmcp_category, $karmcp_c ); ?>><?php echo esc_html( $karmcp_c ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<select name="mk_access">
				<option value=""><?php esc_html_e( 'All access', 'karmcp' ); ?></option>
				<option value="community" <?php selected( $karmcp_f_access, 'community' ); ?>><?php esc_html_e( 'Community', 'karmcp' ); ?></option>
				<option value="pro" <?php selected( $karmcp_f_access, 'pro' ); ?>><?php esc_html_e( 'Pro', 'karmcp' ); ?></option>
			</select>
			<select name="mk_sort">
				<option value="newest" <?php selected( $karmcp_sort, 'newest' ); ?>><?php esc_html_e( 'Newest', 'karmcp' ); ?></option>
				<option value="popular" <?php selected( $karmcp_sort, 'popular' ); ?>><?php esc_html_e( 'Most installed', 'karmcp' ); ?></option>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Apply', 'karmcp' ); ?></button>
			<?php if ( $karmcp_active_filters > 0 ) : ?>
				<a class="button-link karmcp-mk-filters__clear" href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-marketplace' ) ); ?>"><?php esc_html_e( 'Clear', 'karmcp' ); ?></a>
			<?php endif; ?>
		</form>

		<p class="karmcp-mk-count">
			<?php
			echo esc_html( sprintf( _n( '%d result', '%d results', $karmcp_total, 'karmcp' ), $karmcp_total ) );
			if ( $karmcp_pages > 1 ) {
				/* translators: 1: current page number, 2: total number of pages. */
				echo ' · ' . esc_html( sprintf( __( 'page %1$d of %2$d', 'karmcp' ), $karmcp_cur_page, $karmcp_pages ) );
			}
			?>
		</p>

		<?php if ( empty( $karmcp_listings ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Nothing matches those filters.', 'karmcp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=karmcp-marketplace' ) ); ?>"><?php esc_html_e( 'Clear filters', 'karmcp' ); ?></a>
			</p></div>
		<?php else : ?>

		<div class="karmcp-mk-grid">
			<?php foreach ( $karmcp_listings as $karmcp_l ) :
				$karmcp_slug   = (string) ( $karmcp_l['slug'] ?? '' );
				$karmcp_title  = (string) ( $karmcp_l['title'] ?? $karmcp_slug );
				$karmcp_kind   = (string) ( $karmcp_l['kind'] ?? '' );
				$karmcp_klabel = $karmcp_kind_label[ $karmcp_kind ] ?? $karmcp_kind;
				$karmcp_is_pro = ( 'pro' === ( $karmcp_l['access'] ?? 'community' ) );
				$karmcp_shot   = ( isset( $karmcp_l['screenshots'] ) && is_array( $karmcp_l['screenshots'] ) && ! empty( $karmcp_l['screenshots'][0] ) ) ? (string) $karmcp_l['screenshots'][0] : '';
				$karmcp_prev   = (string) ( $karmcp_l['preview_url'] ?? '' );
				$karmcp_ins    = (int) ( $karmcp_l['install_count'] ?? 0 );
				$karmcp_author = ( isset( $karmcp_l['author'] ) && is_array( $karmcp_l['author'] ) ) ? $karmcp_l['author'] : array();
				$karmcp_a_name = (string) ( $karmcp_author['name'] ?? '' );
				$karmcp_a_av   = (string) ( $karmcp_author['avatar'] ?? '' );
				$karmcp_a_ver  = ! empty( $karmcp_author['verified'] );
				$karmcp_a_url  = (string) ( $karmcp_author['profile_url'] ?? '' );
				?>
				<div class="karmcp-mk-card">
					<?php if ( $karmcp_shot ) : ?>
						<img class="karmcp-mk-card__shot" src="<?php echo esc_url( $karmcp_shot ); ?>" alt="<?php echo esc_attr( $karmcp_title ); ?>" loading="lazy" />
					<?php else : ?>
						<div class="karmcp-mk-card__shot karmcp-mk-card__shot--empty" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $karmcp_klabel, 0, 1 ) ) ); ?></div>
					<?php endif; ?>
					<div class="karmcp-mk-card__body">
						<div class="karmcp-mk-card__k">
							<span><?php echo esc_html( $karmcp_klabel ); ?><?php echo ! empty( $karmcp_l['category'] ) ? ' · ' . esc_html( $karmcp_l['category'] ) : ''; ?></span>
							<?php if ( $karmcp_is_pro ) : ?><span class="karmcp-mk-card__pro">Pro</span><?php endif; ?>
						</div>
						<h3 class="karmcp-mk-card__title"><?php echo esc_html( $karmcp_title ); ?></h3>
						<?php if ( ! empty( $karmcp_l['summary'] ) ) : ?>
							<p class="karmcp-mk-card__sum"><?php echo esc_html( $karmcp_l['summary'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $karmcp_a_name ) : ?>
							<div class="karmcp-mk-card__author">
								<?php if ( '' !== $karmcp_a_av ) : ?>
									<img class="karmcp-mk-card__av" src="<?php echo esc_url( $karmcp_a_av ); ?>" alt="" loading="lazy" />
								<?php else : ?>
									<span class="karmcp-mk-card__av karmcp-mk-card__av--fb" aria-hidden="true"><?php echo esc_html( strtoupper( substr( $karmcp_a_name, 0, 1 ) ) ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $karmcp_a_url ) : ?>
									<a class="karmcp-mk-card__aname karmcp-mk-card__aname--link" href="<?php echo esc_url( $karmcp_a_url ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $karmcp_a_name ); ?></a>
								<?php else : ?>
									<span class="karmcp-mk-card__aname"><?php echo esc_html( $karmcp_a_name ); ?></span>
								<?php endif; ?>
								<?php if ( $karmcp_a_ver ) : ?>
									<img class="karmcp-mk-card__ver" src="<?php echo esc_url( KARMCP_URL . 'assets/img/pro.svg' ); ?>" alt="<?php esc_attr_e( 'Verified', 'karmcp' ); ?>" title="<?php esc_attr_e( 'Verified KarMCP Pro member', 'karmcp' ); ?>" width="15" height="15" />
								<?php endif; ?>
							</div>
						<?php endif; ?>
						<div class="karmcp-mk-card__foot">
							<span><?php echo esc_html( sprintf( _n( '%d install', '%d installs', $karmcp_ins, 'karmcp' ), $karmcp_ins ) ); ?></span>
							<?php if ( $karmcp_prev ) : ?><a href="<?php echo esc_url( $karmcp_prev ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'Preview ↗', 'karmcp' ); ?></a><?php endif; ?>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="karmcp-mk-card__cta">
							<input type="hidden" name="action" value="karmcp_marketplace_install" />
							<input type="hidden" name="slug" value="<?php echo esc_attr( $karmcp_slug ); ?>" />
							<?php wp_nonce_field( 'karmcp_marketplace_install' ); ?>
							<button type="submit" class="button button-primary" style="width:100%;justify-content:center;"><?php esc_html_e( 'Install', 'karmcp' ); ?></button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $karmcp_pages > 1 ) : ?>
			<nav class="karmcp-mk-pager" aria-label="<?php esc_attr_e( 'Pagination', 'karmcp' ); ?>">
				<?php if ( $karmcp_cur_page > 1 ) : ?>
					<a class="button karmcp-mk-pager__edge" href="<?php echo esc_url( $karmcp_page_href( $karmcp_cur_page - 1 ) ); ?>">&larr; <?php esc_html_e( 'Prev', 'karmcp' ); ?></a>
				<?php else : ?>
					<span class="button disabled karmcp-mk-pager__edge">&larr; <?php esc_html_e( 'Prev', 'karmcp' ); ?></span>
				<?php endif; ?>

				<?php
				// Windowed page numbers: first, last, current ±1, ellipses.
				$karmcp_nums = array();
				if ( $karmcp_pages <= 7 ) {
					for ( $i = 1; $i <= $karmcp_pages; $i++ ) {
						$karmcp_nums[] = $i;
					}
				} else {
					$karmcp_nums[] = 1;
					$karmcp_lo     = max( 2, $karmcp_cur_page - 1 );
					$karmcp_hi     = min( $karmcp_pages - 1, $karmcp_cur_page + 1 );
					if ( $karmcp_lo > 2 ) {
						$karmcp_nums[] = 0;
					}
					for ( $i = $karmcp_lo; $i <= $karmcp_hi; $i++ ) {
						$karmcp_nums[] = $i;
					}
					if ( $karmcp_hi < $karmcp_pages - 1 ) {
						$karmcp_nums[] = 0;
					}
					$karmcp_nums[] = $karmcp_pages;
				}
				foreach ( $karmcp_nums as $karmcp_n ) :
					if ( 0 === $karmcp_n ) :
						?>
						<span class="karmcp-mk-pager__gap" aria-hidden="true">…</span>
						<?php
					elseif ( $karmcp_n === $karmcp_cur_page ) :
						?>
						<span class="button button-primary karmcp-mk-pager__num" aria-current="page"><?php echo esc_html( (string) $karmcp_n ); ?></span>
						<?php
					else :
						?>
						<a class="button karmcp-mk-pager__num" href="<?php echo esc_url( $karmcp_page_href( $karmcp_n ) ); ?>"><?php echo esc_html( (string) $karmcp_n ); ?></a>
						<?php
					endif;
				endforeach;
				?>

				<?php if ( $karmcp_cur_page < $karmcp_pages ) : ?>
					<a class="button karmcp-mk-pager__edge" href="<?php echo esc_url( $karmcp_page_href( $karmcp_cur_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'karmcp' ); ?> &rarr;</a>
				<?php else : ?>
					<span class="button disabled karmcp-mk-pager__edge"><?php esc_html_e( 'Next', 'karmcp' ); ?> &rarr;</span>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>

<style>
	.karmcp-mk-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 16px 0 4px; }
	.karmcp-mk-filters__search { min-width: 220px; flex: 1 1 220px; max-width: 340px; }
	.karmcp-mk-filters__clear { margin-left: 2px; color: #b32d2e; text-decoration: none; }
	.karmcp-mk-count { font-size: 12px; color: #787c82; margin: 8px 0 0; }
	.karmcp-mk-grid { display: grid; grid-template-columns: repeat( auto-fill, minmax( 240px, 1fr ) ); gap: 16px; margin-top: 12px; }
	.karmcp-mk-card { display: flex; flex-direction: column; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; overflow: hidden; }
	/* Artifact screenshots are 1200x630 (1.91:1, OG ratio) — show the full image, uncropped. */
	.karmcp-mk-card__shot { width: 100%; aspect-ratio: 1200 / 630; object-fit: contain; display: block; border-bottom: 1px solid #f0f0f1; background: #f6f7f7; }
	.karmcp-mk-card__shot--empty { display: grid; place-items: center; font-size: 34px; font-weight: 700; color: #c3c4c7; }
	.karmcp-mk-card__body { padding: 14px 16px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
	.karmcp-mk-card__k { display: flex; align-items: center; gap: 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #787c82; }
	.karmcp-mk-card__pro { font-weight: 700; color: #4338ca; background: #eef0ff; padding: 1px 7px; border-radius: 999px; }
	.karmcp-mk-card__title { font-size: 15px; margin: 2px 0 0; }
	.karmcp-mk-card__sum { font-size: 13px; color: #50575e; margin: 0; flex: 1; }
	.karmcp-mk-card__author { display: flex; align-items: center; gap: 7px; margin-top: 8px; }
	.karmcp-mk-card__av { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; flex: none; border: 1px solid #e0e0e6; }
	.karmcp-mk-card__av--fb { display: grid; place-items: center; font-size: 11px; font-weight: 700; color: #fff; background: #4338ca; }
	.karmcp-mk-card__aname { font-size: 12.5px; font-weight: 600; color: #3c434a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	a.karmcp-mk-card__aname--link { text-decoration: none; }
	a.karmcp-mk-card__aname--link:hover { color: #2271b1; text-decoration: underline; }
	.karmcp-mk-card__ver { flex: none; width: 15px; height: 15px; display: block; }
	.karmcp-mk-card__foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; color: #787c82; margin-top: 4px; }
	.karmcp-mk-card__cta { margin-top: 8px; }
	.karmcp-mk-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px; margin: 24px 0 4px; }
	.karmcp-mk-pager__num { min-width: 34px; text-align: center; justify-content: center; }
	.karmcp-mk-pager__gap { padding: 0 4px; color: #787c82; }
	.karmcp-mk-pager .button.disabled { pointer-events: none; opacity: .5; }
</style>
