<?php
/**
 * History tab — the AI-safe change ledger with one-click rollback.
 *
 * Surfaces KarMCP_Change_Log entries (Elementor edits, filesystem writes,
 * database writes) to a human administrator, with a rollback action per
 * reversible entry. Included from KarMCP_Admin::render_page().
 *
 * @package KarMCP
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_entries = class_exists( 'KarMCP_Change_Log' ) ? array_reverse( KarMCP_Change_Log::all() ) : array();

// Domain filter.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filter.
$karmcp_domain = isset( $_GET['domain'] ) ? sanitize_key( wp_unslash( $_GET['domain'] ) ) : '';
if ( '' !== $karmcp_domain ) {
	$karmcp_entries = array_values( array_filter( $karmcp_entries, static function ( $e ) use ( $karmcp_domain ) {
		return ( $e['domain'] ?? '' ) === $karmcp_domain;
	} ) );
}

$karmcp_domain_labels = array(
	'elementor'  => __( 'Elementor', 'karmcp' ),
	'filesystem' => __( 'Filesystem', 'karmcp' ),
	'database'   => __( 'Database', 'karmcp' ),
);

// Result notice after a rollback.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$karmcp_rb = isset( $_GET['rollback'] ) ? sanitize_key( wp_unslash( $_GET['rollback'] ) ) : '';
// Result notices after a delete / clear-all.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$karmcp_del = isset( $_GET['deleted'] ) ? sanitize_key( wp_unslash( $_GET['deleted'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice.
$karmcp_cleared = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : -1;
?>

<div class="karmcp-history">
	<?php if ( 'ok' === $karmcp_rb ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Change rolled back.', 'karmcp' ); ?></strong></p></div>
	<?php elseif ( 'partial' === $karmcp_rb ) : ?>
		<div class="notice notice-warning is-dismissible"><p><strong><?php esc_html_e( 'Change rolled back — partially.', 'karmcp' ); ?></strong>
			<?php esc_html_e( 'The before-image was capped, so some rows may not have been restored.', 'karmcp' ); ?></p></div>
	<?php elseif ( 'conflict' === $karmcp_rb ) : ?>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only; the force action below carries its own nonce.
		$karmcp_conflict_id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
		?>
		<div class="notice notice-warning"><p>
			<strong><?php esc_html_e( 'This target changed since the change was recorded.', 'karmcp' ); ?></strong>
			<?php esc_html_e( 'Rolling back now would overwrite the newer edits.', 'karmcp' ); ?>
			<?php if ( '' !== $karmcp_conflict_id ) : ?>
				<a class="button button-secondary" style="margin-left:8px;"
					href="<?php echo esc_url( KarMCP_Admin::rollback_change_url( $karmcp_conflict_id, true ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Overwrite the newer state and roll back anyway?', 'karmcp' ) ); ?>');">
					<?php esc_html_e( 'Roll back anyway', 'karmcp' ); ?>
				</a>
			<?php endif; ?>
		</p></div>
	<?php elseif ( 'error' === $karmcp_rb ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Rollback failed.', 'karmcp' ); ?></strong>
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message text.
		echo isset( $_GET['msg'] ) ? ' ' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) : '';
		?>
		</p></div>
	<?php endif; ?>

	<?php if ( '1' === $karmcp_del ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'History entry deleted.', 'karmcp' ); ?></strong></p></div>
	<?php elseif ( '0' === $karmcp_del ) : ?>
		<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'That history entry no longer exists.', 'karmcp' ); ?></strong></p></div>
	<?php endif; ?>

	<?php if ( $karmcp_cleared > -1 ) : ?>
		<div class="notice notice-success is-dismissible"><p><strong>
			<?php
			printf(
				/* translators: %s: number of history entries removed */
				esc_html( _n( 'Cleared %s history entry.', 'Cleared %s history entries.', $karmcp_cleared, 'karmcp' ) ),
				esc_html( number_format_i18n( $karmcp_cleared ) )
			);
			?>
		</strong></p></div>
	<?php endif; ?>

	<div class="karmcp-history__head">
		<div>
			<h2 class="karmcp-history__title"><?php esc_html_e( 'Change history', 'karmcp' ); ?></h2>
			<p class="karmcp-history__intro">
				<?php esc_html_e( 'Every AI-made change to Elementor pages, files, and the database is recorded here. Reversible changes can be rolled back with one click.', 'karmcp' ); ?>
			</p>
			<?php if ( ! empty( $karmcp_entries ) ) : ?>
				<p class="karmcp-history__clear-wrap">
					<a class="button button-link-delete karmcp-history__clear"
						href="<?php echo esc_url( KarMCP_Admin::clear_changes_url() ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Clear the entire change history? This cannot be undone, and any entries that could still be rolled back will lose that ability.', 'karmcp' ) ); ?>');">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Clear all', 'karmcp' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<ul class="karmcp-history__filters">
			<li<?php echo '' === $karmcp_domain ? ' class="is-active"' : ''; ?>><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . KarMCP_Admin::PAGE_SLUG . '-history' ) ); ?>"><?php esc_html_e( 'All', 'karmcp' ); ?></a></li>
			<?php foreach ( $karmcp_domain_labels as $karmcp_dk => $karmcp_dl ) : ?>
				<li<?php echo $karmcp_domain === $karmcp_dk ? ' class="is-active"' : ''; ?>><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . KarMCP_Admin::PAGE_SLUG . '-history&domain=' . $karmcp_dk ) ); ?>"><?php echo esc_html( $karmcp_dl ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<?php if ( empty( $karmcp_entries ) ) : ?>
		<div class="karmcp-history__empty">
			<span class="dashicons dashicons-undo" aria-hidden="true"></span>
			<p><?php esc_html_e( 'No changes recorded yet. As soon as a connected AI edits a page, writes a file, or changes the database, it will appear here, ready to roll back.', 'karmcp' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped karmcp-history__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Who', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Type', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Change', 'karmcp' ); ?></th>
					<th><?php esc_html_e( 'Target', 'karmcp' ); ?></th>
					<th class="karmcp-history__actions-col"><?php esc_html_e( 'Action', 'karmcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $karmcp_entries as $karmcp_e ) : ?>
					<?php
					$karmcp_id         = (string) ( $karmcp_e['id'] ?? '' );
					$karmcp_ts         = (int) ( $karmcp_e['ts'] ?? 0 );
					$karmcp_reversible = ! empty( $karmcp_e['rollback'] ) && empty( $karmcp_e['rolled_back'] );
					$karmcp_dk         = (string) ( $karmcp_e['domain'] ?? '' );
					?>
					<tr>
						<td>
							<?php echo esc_html( $karmcp_ts ? date_i18n( 'Y-m-d H:i', $karmcp_ts ) : ', ' ); ?>
							<?php if ( $karmcp_ts ) : ?>
								<span class="karmcp-history__ago"><?php echo esc_html( sprintf( /* translators: %s: human time diff */ __( '%s ago', 'karmcp' ), human_time_diff( $karmcp_ts ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $karmcp_e['user_login'] ?? '' ) ); ?></td>
						<td><span class="karmcp-history__badge karmcp-history__badge--<?php echo esc_attr( $karmcp_dk ); ?>"><?php echo esc_html( $karmcp_domain_labels[ $karmcp_dk ] ?? $karmcp_dk ); ?></span></td>
						<td>
							<strong><?php echo esc_html( (string) ( $karmcp_e['action'] ?? '' ) ); ?></strong><br />
							<span class="karmcp-history__summary"><?php echo esc_html( (string) ( $karmcp_e['summary'] ?? '' ) ); ?></span>
						</td>
						<td class="karmcp-history__target"><?php echo esc_html( (string) ( $karmcp_e['target'] ?? '' ) ); ?></td>
						<td class="karmcp-history__actions-col">
							<?php if ( ! empty( $karmcp_e['rolled_back'] ) ) : ?>
								<span class="karmcp-history__state karmcp-history__state--done"><?php esc_html_e( 'Rolled back', 'karmcp' ); ?></span>
							<?php elseif ( $karmcp_reversible ) : ?>
								<a class="button button-secondary karmcp-history__rollback"
									href="<?php echo esc_url( KarMCP_Admin::rollback_change_url( $karmcp_id ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Roll this change back? This restores the previous state.', 'karmcp' ) ); ?>');">
									<span class="dashicons dashicons-undo" aria-hidden="true"></span>
									<?php esc_html_e( 'Roll back', 'karmcp' ); ?>
								</a>
							<?php else : ?>
								<span class="karmcp-history__state">, </span>
							<?php endif; ?>
							<a class="karmcp-history__delete"
								href="<?php echo esc_url( KarMCP_Admin::delete_change_url( $karmcp_id ) ); ?>"
								aria-label="<?php esc_attr_e( 'Delete this history entry', 'karmcp' ); ?>"
								onclick="return confirm('<?php echo esc_js(
									$karmcp_reversible
										? __( 'Delete this entry? It can still be rolled back, deleting it discards that ability permanently.', 'karmcp' )
										: __( 'Delete this history entry? This cannot be undone.', 'karmcp' )
								); ?>');">
								<span class="dashicons dashicons-trash" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'karmcp' ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="karmcp-history__note"><?php esc_html_e( 'The ledger keeps the most recent changes (older entries age out). Rolling a change back records its own entry.', 'karmcp' ); ?></p>
	<?php endif; ?>
</div>
