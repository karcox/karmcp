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

/**
 * How each count is written. Overhead is megabytes, everything else is rows,
 * and the JavaScript reuses these so a refreshed count keeps its unit and its
 * translation instead of turning back into a bare number.
 */
$karmcp_formats = array();
foreach ( $karmcp_plan as $karmcp_t ) {
	$karmcp_formats[ $karmcp_t['id'] ] = ( 'optimize' === $karmcp_t['id'] )
		/* translators: %d: megabytes of table overhead. */
		? __( '%d MB', 'karmcp' )
		: '%d';
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

	<?php // Always printed, hidden while there is work: the last cleanup that empties the table reveals it without a reload. ?>
	<div class="notice notice-info inline" id="karmcp-clean-empty" <?php echo $karmcp_any ? 'style="display:none;"' : ''; ?>><p>
		<?php esc_html_e( 'Nothing to clean. Every task below counts zero.', 'karmcp' ); ?>
	</p></div>

	<table class="widefat striped karmcp-clean-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Task', 'karmcp' ); ?></th>
				<th style="width:8em;text-align:right;"><?php esc_html_e( 'Found', 'karmcp' ); ?></th>
				<th style="width:16em;"><?php esc_html_e( 'Action', 'karmcp' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $karmcp_plan as $karmcp_task ) : ?>
			<tr>
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
				<td style="text-align:right;" class="karmcp-clean-count" data-task="<?php echo esc_attr( $karmcp_task['id'] ); ?>">
					<?php
					echo esc_html(
						sprintf( $karmcp_formats[ $karmcp_task['id'] ], (int) $karmcp_task['count'] )
					);
					?>
				</td>
				<td>
					<?php
					// One form per task, so the page still works with JavaScript
					// off: it posts that single task and comes back through the
					// redirect. With JavaScript on, the submit is intercepted.
					?>
					<form method="post" style="margin:0;" class="karmcp-clean-form"
						data-task="<?php echo esc_attr( $karmcp_task['id'] ); ?>"
						<?php if ( empty( $karmcp_task['reversible'] ) ) : ?>
							data-confirm="<?php echo esc_attr(
								sprintf(
									/* translators: %s: task name. */
									__( '“%s” deletes content permanently. Continue?', 'karmcp' ),
									$karmcp_task['label']
								)
							); ?>"
						<?php endif; ?>
					>
						<?php wp_nonce_field( 'karmcp_db_clean' ); ?>
						<input type="hidden" name="karmcp_task[]" value="<?php echo esc_attr( $karmcp_task['id'] ); ?>" />
						<button type="submit" name="karmcp_db_clean" value="1" class="button"
							<?php disabled( empty( $karmcp_task['worth_running'] ) ); ?>>
							<?php
							echo esc_html(
								'optimize' === $karmcp_task['id']
									? __( 'Reclaim', 'karmcp' )
									: __( 'Clean', 'karmcp' )
							);
							?>
						</button>
						<span class="karmcp-clean-state" style="display:none;"></span>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description" style="max-width:46em;">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: batch size. */
				__( 'Up to %d items per task per run, so a large site cannot time out halfway. Run it again until the counts reach zero.', 'karmcp' ),
				KarMCP_DB_Cleaner::BATCH
			)
		);
		?>
	</p>

	<h3><?php esc_html_e( 'What this deliberately does not do', 'karmcp' ); ?></h3>
	<ul class="ul-disc" style="max-width:46em;">
		<li><?php esc_html_e( 'It does not change autoloaded options. The performance report names the largest ones, but switching autoload off on the wrong option breaks the plugin that owns it, and no rule can tell which those are. That one stays a human decision.', 'karmcp' ); ?></li>
		<li><?php esc_html_e( 'It does not touch tables belonging to plugins you have removed. Recognising an abandoned table by name means guessing, and a wrong guess deletes real data.', 'karmcp' ); ?></li>
		<li><?php esc_html_e( 'It does not cache anything. That is a different job and other plugins do it well.', 'karmcp' ); ?></li>
	</ul>
</div>

<script>
( function () {
	var forms = document.querySelectorAll( '.karmcp-clean-form' );
	if ( ! forms.length || ! window.fetch ) { return; }

	var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'karmcp_db_clean' ) ); ?>;
	var formats = <?php echo wp_json_encode( $karmcp_formats ); ?>;
	var working = <?php echo wp_json_encode( __( 'Cleaning…', 'karmcp' ) ); ?>;
	var empty   = document.getElementById( 'karmcp-clean-empty' );

	function count( n, task ) {
		return String( formats[ task ] || '%d' ).replace( '%d', String( n ) );
	}

	// Every count is redrawn after every run, because the tasks are not
	// independent: deleting revisions leaves free space, which is what the
	// overhead figure measures. A row is only re-armed when it has work left.
	function repaint( counts ) {
		var work = false;

		Array.prototype.forEach.call(
			document.querySelectorAll( '.karmcp-clean-count' ),
			function ( cell ) {
				var task = cell.getAttribute( 'data-task' );
				var n    = parseInt( counts[ task ], 10 ) || 0;
				cell.textContent = count( n, task );

				var form = document.querySelector( '.karmcp-clean-form[data-task="' + task + '"]' );
				if ( form ) {
					form.querySelector( 'button' ).disabled = ( 0 === n );
				}
				if ( n > 0 ) { work = true; }
			}
		);

		if ( empty ) { empty.style.display = work ? 'none' : ''; }
	}

	Array.prototype.forEach.call( forms, function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var ask = form.getAttribute( 'data-confirm' );
			if ( ask && ! window.confirm( ask ) ) { return; }

			var button = form.querySelector( 'button' );
			var state  = form.querySelector( '.karmcp-clean-state' );

			// A batch of three hundred deletions is seconds of work. Disable
			// first, then say so: a button that looks inert for that long gets
			// clicked twice, and the second click would start a second batch
			// over the first.
			button.disabled = true;
			state.style.display = 'inline-block';
			state.style.color = '';
			state.innerHTML = '<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>' + working;

			var body = new FormData();
			body.append( 'action', 'karmcp_db_clean' );
			body.append( '_wpnonce', nonce );
			body.append( 'task', form.getAttribute( 'data-task' ) );

			fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					var ok = res && res.success;
					state.textContent = ( res && res.data && res.data.message ) || '';
					state.style.color = ok ? '#00713b' : '#b32d2e';

					if ( ok && res.data.counts ) {
						repaint( res.data.counts );
					} else {
						button.disabled = false;
					}
				} )
				.catch( function ( err ) {
					state.textContent = String( err );
					state.style.color = '#b32d2e';
					button.disabled = false;
				} );
		} );
	} );
}() );
</script>
