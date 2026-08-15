<?php
/**
 * Security tab.
 *
 * The four audits have existed since 3.0.0 and have never been visible inside
 * WordPress — `scan-security` returned its report to an agent and nowhere else.
 * This is that report, for a person.
 *
 * Deliberately not a Wordfence clone: score, age, what is critical, and a
 * button for the things that can be fixed. The one rule it must never break is
 * that an old or failed scan is shown as exactly that, never as a clean bill.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$karmcp_stored = KarMCP_Security_Monitor::last();
$karmcp_fresh  = KarMCP_Security_Monitor::freshness( $karmcp_stored, time() );
$karmcp_sum    = (array) ( $karmcp_stored['summary'] ?? array() );
$karmcp_counts = (array) ( $karmcp_sum['counts'] ?? array() );
$karmcp_applied = KarMCP_Security_Hardening_Fixer::applied();
?>

<div class="karmcp-security">

	<form method="post" style="margin-bottom:1.5em;">
		<?php wp_nonce_field( 'karmcp_security_scan' ); ?>
		<button type="submit" name="karmcp_security_scan" value="1" class="button button-primary">
			<?php esc_html_e( 'Scan now', 'karmcp' ); ?>
		</button>
		<span class="description" style="margin-left:.75em;">
			<?php esc_html_e( 'A scan also runs once a day in the background.', 'karmcp' ); ?>
		</span>
	</form>

	<?php if ( 'never' === $karmcp_fresh['state'] ) : ?>

		<div class="notice notice-info inline"><p>
			<strong><?php esc_html_e( 'This site has not been scanned yet.', 'karmcp' ); ?></strong>
			<?php esc_html_e( 'Nothing here means "no problems" until a scan has actually run.', 'karmcp' ); ?>
		</p></div>

	<?php elseif ( 'failed' === $karmcp_fresh['state'] ) : ?>

		<div class="notice notice-warning inline"><p>
			<strong><?php esc_html_e( 'The last scan did not finish.', 'karmcp' ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: error message. */
					__( 'The site has not been checked, which is not the same as being clean. Reason: %s', 'karmcp' ),
					(string) ( $karmcp_stored['reason'] ?? __( 'unknown', 'karmcp' ) )
				)
			);
			?>
		</p></div>

	<?php else : ?>

		<?php if ( 'stale' === $karmcp_fresh['state'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: human-readable age, e.g. "3 days". */
						__( 'This report is %s old. Anything published since then is not reflected here.', 'karmcp' ),
						human_time_diff( time() - $karmcp_fresh['age'], time() )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<h2 style="margin-top:0;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: score 0-100, 2: letter grade. */
					__( 'Score %1$d / 100 — grade %2$s', 'karmcp' ),
					(int) ( $karmcp_sum['score'] ?? 0 ),
					(string) ( $karmcp_sum['grade'] ?? '?' )
				)
			);
			?>
		</h2>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: critical count, 2: warning count, 3: relative time. */
					__( '%1$d critical, %2$d warnings. Last checked %3$s ago.', 'karmcp' ),
					(int) ( $karmcp_counts['critical'] ?? 0 ),
					(int) ( $karmcp_counts['warning'] ?? 0 ),
					human_time_diff( time() - $karmcp_fresh['age'], time() )
				)
			);
			?>
		</p>

		<?php
		foreach ( (array) ( $karmcp_stored['sections'] ?? array() ) as $karmcp_cat => $karmcp_items ) :
			$karmcp_bad = array_filter(
				(array) $karmcp_items,
				static function ( $f ) {
					return in_array( ( $f['status'] ?? '' ), array( 'critical', 'warning' ), true );
				}
			);
			if ( ! $karmcp_bad ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( ucfirst( (string) $karmcp_cat ) ); ?></h3>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $karmcp_bad as $karmcp_f ) : ?>
					<tr>
						<td style="width:6em;">
							<strong><?php echo esc_html( strtoupper( (string) ( $karmcp_f['status'] ?? '' ) ) ); ?></strong>
						</td>
						<td>
							<strong><?php echo esc_html( (string) ( $karmcp_f['label'] ?? '' ) ); ?></strong><br />
							<?php echo esc_html( (string) ( $karmcp_f['message'] ?? '' ) ); ?>
							<?php if ( ! empty( $karmcp_f['recommendation'] ) ) : ?>
								<br /><em><?php echo esc_html( (string) $karmcp_f['recommendation'] ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

	<?php endif; ?>

	<hr style="margin:2em 0;" />

	<h2><?php esc_html_e( 'Hardening', 'karmcp' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Each of these is a switch this plugin owns, not an edit to wp-config.php — so each one can be turned back off here. Three hardening findings are never applied automatically; the reasons are listed below them.', 'karmcp' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'karmcp_security_harden' ); ?>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( KarMCP_Security_Hardening_Fixer::catalog() as $karmcp_finding => $karmcp_fix ) : ?>
				<tr>
					<td style="width:2em;">
						<input type="checkbox" name="karmcp_harden[]" value="<?php echo esc_attr( $karmcp_fix['id'] ); ?>"
							<?php checked( in_array( $karmcp_fix['id'], $karmcp_applied, true ) ); ?> />
					</td>
					<td>
						<strong><?php echo esc_html( $karmcp_fix['label'] ); ?></strong><br />
						<?php echo esc_html( $karmcp_fix['does'] ); ?><br />
						<em><?php
						echo esc_html(
							sprintf(
								/* translators: %s: what the fix can break. */
								__( 'Can break: %s', 'karmcp' ),
								$karmcp_fix['breaks']
							)
						);
						?></em>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="submit" name="karmcp_security_harden" value="1" class="button">
				<?php esc_html_e( 'Save hardening', 'karmcp' ); ?>
			</button>
			<span class="description" style="margin-left:.75em;">
				<?php esc_html_e( 'Recorded in History, so it can be rolled back there too.', 'karmcp' ); ?>
			</span>
		</p>
	</form>

	<h3><?php esc_html_e( 'Not fixed automatically', 'karmcp' ); ?></h3>
	<ul class="ul-disc">
		<?php foreach ( KarMCP_Security_Hardening_Fixer::unfixable() as $karmcp_id => $karmcp_why ) : ?>
			<li><code><?php echo esc_html( (string) $karmcp_id ); ?></code> — <?php echo esc_html( $karmcp_why ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
