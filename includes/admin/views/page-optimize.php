<?php
/**
 * Optimize tab.
 *
 * Database housekeeping, and deliberately nothing to do with caching. Caching
 * hides slow work behind a stored copy; this removes work the database is doing
 * for nothing — which is the kind of speed that survives a cache flush, and the
 * kind a caching plugin cannot give you.
 *
 * @package KarMCP
 * @since   1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_counts = KarMCP_DB_Cleaner::counts();
$karmcp_plan   = KarMCP_DB_Cleaner::plan( $karmcp_counts );
$karmcp_any    = false;
foreach ( $karmcp_plan as $karmcp_t ) {
	if ( ! empty( $karmcp_t['worth_running'] ) ) {
		$karmcp_any = true;
		break;
	}
}
?>

<div class="karmcp-optimize">

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from our own redirect.
	if ( isset( $_GET['cleaned'] ) ) :
		?>
		<div class="notice notice-success inline"><p>
			<?php esc_html_e( 'Cleanup run. Deletions happen in batches, so if a count below is still above zero, run it again.', 'karmcp' ); ?>
		</p></div>
	<?php endif; ?>

	<h2 style="margin-top:0;"><?php esc_html_e( 'Database cleanup', 'karmcp' ); ?></h2>
	<p class="description" style="max-width:46em;">
		<?php esc_html_e( 'This is not caching. Caching hides slow work behind a stored copy; this removes work the database is doing for no reason — rows nothing reads, revisions nobody will open, options loaded on every request that expired weeks ago. It is the kind of speed that survives a cache flush.', 'karmcp' ); ?>
	</p>

	<?php if ( ! $karmcp_any ) : ?>
		<div class="notice notice-info inline"><p>
			<?php esc_html_e( 'Nothing to clean. Every task below counts zero.', 'karmcp' ); ?>
		</p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'karmcp_db_clean' ); ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:2em;"></th>
					<th><?php esc_html_e( 'Task', 'karmcp' ); ?></th>
					<th style="width:8em;text-align:right;"><?php esc_html_e( 'Found', 'karmcp' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $karmcp_plan as $karmcp_task ) : ?>
				<tr>
					<td>
						<input type="checkbox" name="karmcp_task[]" value="<?php echo esc_attr( $karmcp_task['id'] ); ?>"
							<?php disabled( empty( $karmcp_task['worth_running'] ) ); ?> />
					</td>
					<td>
						<strong><?php echo esc_html( $karmcp_task['label'] ); ?></strong>
						<?php if ( empty( $karmcp_task['reversible'] ) ) : ?>
							<span style="color:#b32d2e;"> — <?php esc_html_e( 'permanent', 'karmcp' ); ?></span>
						<?php endif; ?>
						<br />
						<?php echo esc_html( $karmcp_task['does'] ); ?><br />
						<em><?php
						echo esc_html(
							sprintf(
								/* translators: %s: what the task can break. */
								__( 'Can break: %s', 'karmcp' ),
								$karmcp_task['breaks']
							)
						);
						?></em>
					</td>
					<td style="text-align:right;">
						<?php
						if ( 'optimize' === $karmcp_task['id'] ) {
							echo esc_html(
								sprintf(
									/* translators: %d: megabytes of table overhead. */
									__( '%d MB', 'karmcp' ),
									(int) $karmcp_task['count']
								)
							);
						} else {
							echo (int) $karmcp_task['count'];
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="submit" name="karmcp_db_clean" value="1" class="button button-primary"
				onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Several of these delete content permanently. Continue?', 'karmcp' ) ) ); ?> );">
				<?php esc_html_e( 'Clean selected', 'karmcp' ); ?>
			</button>
			<span class="description" style="margin-left:.75em;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: batch size. */
						__( 'Up to %d items per task per run, so a large site cannot time out halfway. Run it again until the counts reach zero.', 'karmcp' ),
						KarMCP_DB_Cleaner::BATCH
					)
				);
				?>
			</span>
		</p>
	</form>

	<h3><?php esc_html_e( 'What this deliberately does not do', 'karmcp' ); ?></h3>
	<ul class="ul-disc" style="max-width:46em;">
		<li><?php esc_html_e( 'It does not change autoloaded options. The performance report names the largest ones, but switching autoload off on the wrong option breaks the plugin that owns it, and no rule can tell which those are. That one stays a human decision.', 'karmcp' ); ?></li>
		<li><?php esc_html_e( 'It does not touch tables belonging to plugins you have removed. Recognising an abandoned table by name means guessing, and a wrong guess deletes real data.', 'karmcp' ); ?></li>
		<li><?php esc_html_e( 'It does not cache anything. That is a different job and other plugins do it well.', 'karmcp' ); ?></li>
	</ul>
</div>
