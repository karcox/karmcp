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

if ( ! function_exists( 'karmcp_security_where' ) ) {
	/**
	 * The human-readable "where" of a finding.
	 *
	 * `value` is deliberately loose across the audits — a path string for the
	 * malware walk, `{location: ...}` for some, `{name, current, new}` for the
	 * software audit, a bare bool for hardening. This pulls out whatever
	 * identifies the subject and returns '' when there is nothing worth showing,
	 * so the caller never has to know which audit produced the finding.
	 *
	 * @since 1.7.1
	 *
	 * @param mixed $value Finding value.
	 * @return string
	 */
	function karmcp_security_where( $value ): string {
		if ( is_string( $value ) ) {
			return ( '' !== $value && '1' !== $value ) ? $value : '';
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		// A vulnerability finding: name the fix and the severity, which is what
		// the reader has to act on.
		if ( ! empty( $value['slug'] ) && isset( $value['cvss'] ) ) {
			$out = (string) $value['slug'] . ' ' . (string) ( $value['installed'] ?? '' );
			if ( ! empty( $value['fix_version'] ) ) {
				$out .= ' → ' . (string) $value['fix_version'];
			} else {
				$out .= ' — sin parche';
			}
			$out .= '   CVSS ' . (string) $value['cvss'];
			if ( ! empty( $value['cve'] ) ) {
				$out .= '   ' . (string) $value['cve'];
			}
			return $out;
		}

		foreach ( array( 'location', 'path', 'file' ) as $key ) {
			if ( ! empty( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
				return $value[ $key ];
			}
		}
		if ( ! empty( $value['name'] ) && is_string( $value['name'] ) ) {
			$out = $value['name'];
			if ( ! empty( $value['current'] ) && ! empty( $value['new'] ) ) {
				$out .= '  ' . $value['current'] . ' → ' . $value['new'];
			}
			return $out;
		}
		return '';
	}
}

$karmcp_stored = KarMCP_Security_Monitor::last();
$karmcp_fresh  = KarMCP_Security_Monitor::freshness( $karmcp_stored, time() );
$karmcp_sum    = (array) ( $karmcp_stored['summary'] ?? array() );
$karmcp_counts = (array) ( $karmcp_sum['counts'] ?? array() );
$karmcp_applied = KarMCP_Security_Hardening_Fixer::applied();
?>

<div class="karmcp-security">

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only echo of the redirect result.
	$karmcp_upd = isset( $_GET['karmcp_updated'] ) ? sanitize_text_field( wp_unslash( $_GET['karmcp_updated'] ) ) : '';
	if ( '' !== $karmcp_upd ) :
		?>
		<div class="notice <?php echo 'ok' === $karmcp_upd ? 'notice-success' : 'notice-error'; ?> inline"><p>
			<?php
			echo 'ok' === $karmcp_upd
				? esc_html__( 'Plugin updated. Scan again to confirm the vulnerabilities are cleared.', 'karmcp' )
				: esc_html( $karmcp_upd );
			?>
		</p></div>
	<?php endif; ?>

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
		/**
		 * Findings are grouped by id before rendering, and each one shows WHERE.
		 *
		 * Both halves were learned the hard way on a real site: the first scan
		 * printed 142 rows of identical text, and not one of them said which
		 * file it meant, because the path lives in the finding's `value` and
		 * nothing rendered it. A report you cannot act on is not a report.
		 */
		foreach ( (array) ( $karmcp_stored['sections'] ?? array() ) as $karmcp_cat => $karmcp_items ) :
			$karmcp_groups = array();
			foreach ( (array) $karmcp_items as $karmcp_f ) {
				$karmcp_st = (string) ( $karmcp_f['status'] ?? '' );
				if ( ! in_array( $karmcp_st, array( 'critical', 'warning' ), true ) ) {
					continue;
				}
				// El CVE entra en la clave: sin él, quince vulnerabilidades
				// distintas del mismo plugin se agrupaban en una fila que
				// mostraba solo la primera y decía "×15", como si fuera la misma
				// repetida. Agrupar tiene que unir lo idéntico, no lo parecido.
				$karmcp_cve = is_array( $karmcp_f['value'] ?? null ) ? (string) ( $karmcp_f['value']['cve'] ?? '' ) : '';
				$karmcp_k   = $karmcp_st . '|' . (string) ( $karmcp_f['id'] ?? '' ) . '|' . (string) ( $karmcp_f['label'] ?? '' ) . '|' . $karmcp_cve;
				if ( ! isset( $karmcp_groups[ $karmcp_k ] ) ) {
					$karmcp_groups[ $karmcp_k ] = array(
						'finding' => $karmcp_f,
						'count'   => 0,
						'where'   => array(),
					);
				}
				++$karmcp_groups[ $karmcp_k ]['count'];
				$karmcp_w = karmcp_security_where( $karmcp_f['value'] ?? null );
				if ( '' !== $karmcp_w ) {
					$karmcp_groups[ $karmcp_k ]['where'][] = $karmcp_w;
				}
			}
			if ( ! $karmcp_groups ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( ucfirst( (string) $karmcp_cat ) ); ?></h3>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $karmcp_groups as $karmcp_g ) : ?>
					<?php $karmcp_f = $karmcp_g['finding']; ?>
					<tr>
						<td style="width:6em;">
							<strong><?php echo esc_html( strtoupper( (string) ( $karmcp_f['status'] ?? '' ) ) ); ?></strong>
							<?php if ( $karmcp_g['count'] > 1 ) : ?>
								<br /><span class="description">&times;<?php echo (int) $karmcp_g['count']; ?></span>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( (string) ( $karmcp_f['label'] ?? '' ) ); ?></strong><br />
							<?php echo esc_html( (string) ( $karmcp_f['message'] ?? '' ) ); ?>
							<?php if ( $karmcp_g['where'] ) : ?>
								<br />
								<?php foreach ( array_slice( $karmcp_g['where'], 0, 10 ) as $karmcp_where ) : ?>
									<code style="display:block;"><?php echo esc_html( $karmcp_where ); ?></code>
								<?php endforeach; ?>
								<?php if ( count( $karmcp_g['where'] ) > 10 ) : ?>
									<span class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: how many more locations were not listed. */
												__( '…and %d more.', 'karmcp' ),
												count( $karmcp_g['where'] ) - 10
											)
										);
										?>
									</span>
								<?php endif; ?>
							<?php endif; ?>
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

	<?php if ( class_exists( 'KarMCP_Vulnerabilities_Module' ) && KarMCP_Vulnerabilities_Module::is_enabled() ) : ?>
		<hr style="margin:2em 0;" />
		<h2><?php esc_html_e( 'Known vulnerabilities', 'karmcp' ); ?></h2>
		<?php
		$karmcp_vstate = KarMCP_Vuln_Store::state();
		$karmcp_vaudit = new KarMCP_Vuln_Audit();
		$karmcp_vmatch = empty( $karmcp_vstate['refreshed_at'] ) ? array() : $karmcp_vaudit->match_installed();
		$karmcp_attrib = KarMCP_Vuln_Store::attribution();
		?>
		<form method="post" style="margin-bottom:1em;">
			<?php wp_nonce_field( 'karmcp_vuln_refresh' ); ?>
			<button type="submit" name="karmcp_vuln_refresh" value="1" class="button">
				<?php esc_html_e( 'Refresh vulnerability feed now', 'karmcp' ); ?>
			</button>
			<span class="description" style="margin-left:.75em;">
				<?php esc_html_e( 'Also runs once a day. The free Wordfence quota allows roughly one download an hour, so a 429 here is normal and leaves the stored data untouched.', 'karmcp' ); ?>
			</span>
		</form>
		<?php if ( empty( $karmcp_vstate['refreshed_at'] ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'The vulnerability feed has never been downloaded, so nothing has been checked against it. That is an unknown result, not a clean one. Add an API key under Modules to start.', 'karmcp' ); ?>
			</p></div>
		<?php else : ?>
			<?php if ( empty( $karmcp_vstate['ok'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: error message. */
							__( 'The last feed refresh failed, so this is based on older data: %s', 'karmcp' ),
							(string) ( $karmcp_vstate['error'] ?? '' )
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number affecting this site, 2: relative time. */
						__( '%1$d affecting this site. Feed refreshed %2$s ago.', 'karmcp' ),
						count( $karmcp_vmatch ),
						human_time_diff( (int) $karmcp_vstate['refreshed_at'], time() )
					)
				);
				?>
			</p>
			<?php
			$karmcp_fixable = KarMCP_Vuln_Remediation::plan( $karmcp_vmatch, KarMCP_Vuln_Remediation::offers() );
			if ( $karmcp_fixable ) :
				?>
				<h3><?php esc_html_e( 'Fixable by updating', 'karmcp' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'One button per plugin, not per vulnerability — a single update usually clears several. The count says how many it actually clears, because an available update does not always cover every one.', 'karmcp' ); ?>
				</p>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $karmcp_fixable as $karmcp_fx ) : ?>
						<tr>
							<td style="width:5em;"><strong><?php echo esc_html( (string) $karmcp_fx['worst'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $karmcp_fx['slug'] . ' ' . $karmcp_fx['installed'] ); ?></strong>
								<?php if ( '' !== $karmcp_fx['available'] ) : ?>
									→ <strong><?php echo esc_html( $karmcp_fx['available'] ); ?></strong>
								<?php endif; ?>
								<br />
								<?php
								if ( '' === $karmcp_fx['available'] ) {
									echo esc_html(
										sprintf(
											/* translators: %d: number of vulnerabilities. */
											__( '%d vulnerability(ies), and WordPress is offering no update. For a premium plugin this usually means the licence is not delivering updates.', 'karmcp' ),
											(int) $karmcp_fx['total']
										)
									);
								} else {
									echo esc_html(
										sprintf(
											/* translators: 1: fixed count, 2: total count. */
											__( 'Clears %1$d of %2$d.', 'karmcp' ),
											(int) $karmcp_fx['fixed'],
											(int) $karmcp_fx['total']
										)
									);
									if ( $karmcp_fx['remaining'] > 0 ) {
										echo ' ' . esc_html(
											sprintf(
												/* translators: %d: how many remain. */
												__( '%d would remain — the update does not reach their patched version.', 'karmcp' ),
												(int) $karmcp_fx['remaining']
											)
										);
									}
								}
								?>
							</td>
							<td style="width:11em;text-align:right;">
								<?php if ( '' !== $karmcp_fx['available'] && $karmcp_fx['fixed'] > 0 ) : ?>
									<form method="post" style="margin:0;">
										<?php wp_nonce_field( 'karmcp_vuln_update' ); ?>
										<input type="hidden" name="karmcp_vuln_plugin" value="<?php echo esc_attr( $karmcp_fx['file'] ); ?>" />
										<button type="submit" name="karmcp_vuln_update" value="1" class="button button-primary">
											<?php esc_html_e( 'Update', 'karmcp' ); ?>
										</button>
									</form>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'no update available', 'karmcp' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $karmcp_vmatch ) : ?>
				<h3><?php esc_html_e( 'All of them', 'karmcp' ); ?></h3>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( array_slice( $karmcp_vmatch, 0, 50 ) as $karmcp_v ) : ?>
						<tr>
							<td style="width:5em;"><strong><?php echo esc_html( (string) $karmcp_v['cvss_score'] ); ?></strong><br /><?php echo esc_html( (string) $karmcp_v['cvss_rating'] ); ?></td>
							<td>
								<strong><?php echo esc_html( (string) $karmcp_v['slug'] . ' ' . (string) $karmcp_v['installed'] ); ?></strong>
								<?php if ( ! empty( $karmcp_v['fix_version'] ) ) : ?>
									→ <?php echo esc_html( (string) $karmcp_v['fix_version'] ); ?>
								<?php else : ?>
									— <em><?php esc_html_e( 'no fix yet', 'karmcp' ); ?></em>
								<?php endif; ?>
								<br />
								<?php echo esc_html( (string) $karmcp_v['title'] ); ?>
								<?php if ( ! empty( $karmcp_v['reference'] ) ) : ?>
									<a href="<?php echo esc_url( (string) $karmcp_v['reference'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'record', 'karmcp' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<p class="description" style="margin-top:1em;">
			<?php echo esc_html( $karmcp_attrib['notice'] ); ?>
			<a href="<?php echo esc_url( $karmcp_attrib['license_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Licence terms', 'karmcp' ); ?></a>
		</p>
	<?php endif; ?>

	<hr style="margin:2em 0;" />

	<h2><?php esc_html_e( 'Recovery from fatal errors', 'karmcp' ); ?></h2>
	<?php
	$karmcp_dropin = KarMCP_Fatal_Handler_Template::status();
	$karmcp_fcfg   = (array) get_option( KarMCP_Fatal_Handler_Template::OPTION_CONFIG, array() );
	$karmcp_fatals = (array) get_option( KarMCP_Fatal_Handler_Template::OPTION_LOG, array() );
	$karmcp_pausd  = (array) get_option( KarMCP_Fatal_Handler_Template::OPTION_PAUSED, array() );
	?>
	<p class="description">
		<?php esc_html_e( 'When PHP fatals, the REST API dies and MCP with it — so the agent cannot help at exactly the moment you need it. The Recovery Mode built into WordPress does not fix this: it emails a link and pauses the plugin for that recovery session, while visitors keep seeing the error. This handler records what broke and, optionally, deactivates the plugin responsible so the next request succeeds.', 'karmcp' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'karmcp_security_dropin' ); ?>
		<p>
			<strong><?php esc_html_e( 'Handler:', 'karmcp' ); ?></strong>
			<?php
			if ( 'ours' === $karmcp_dropin ) {
				esc_html_e( 'installed', 'karmcp' );
			} elseif ( 'foreign' === $karmcp_dropin ) {
				esc_html_e( 'another plugin owns wp-content/fatal-error-handler.php', 'karmcp' );
			} else {
				esc_html_e( 'not installed', 'karmcp' );
			}
			?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="karmcp_fatal_auto_pause" value="1" <?php checked( ! empty( $karmcp_fcfg['auto_pause'] ) ); ?> />
				<?php esc_html_e( 'Deactivate a plugin after it fatals 3 times in 10 minutes', 'karmcp' ); ?>
			</label>
			<br />
			<span class="description"><?php esc_html_e( 'Repeated, never on the first crash: one transient fatal must not be able to take the site offline in a different way. KarMCP can never deactivate itself.', 'karmcp' ); ?></span>
		</p>
		<p>
			<button type="submit" name="karmcp_dropin_action" value="install" class="button">
				<?php echo 'ours' === $karmcp_dropin ? esc_html__( 'Reinstall handler', 'karmcp' ) : esc_html__( 'Install handler', 'karmcp' ); ?>
			</button>
			<?php if ( 'ours' === $karmcp_dropin ) : ?>
				<button type="submit" name="karmcp_dropin_action" value="uninstall" class="button">
					<?php esc_html_e( 'Remove handler', 'karmcp' ); ?>
				</button>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( $karmcp_pausd ) : ?>
		<h3><?php esc_html_e( 'Plugins the handler deactivated', 'karmcp' ); ?></h3>
		<ul class="ul-disc">
			<?php foreach ( $karmcp_pausd as $karmcp_pf => $karmcp_pm ) : ?>
				<li>
					<code><?php echo esc_html( (string) $karmcp_pf ); ?></code> —
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of fatals, 2: relative time. */
							__( '%1$d fatals, %2$s ago', 'karmcp' ),
							(int) ( $karmcp_pm['hits'] ?? 0 ),
							human_time_diff( (int) ( $karmcp_pm['at'] ?? time() ), time() )
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $karmcp_fatals ) : ?>
		<h3><?php esc_html_e( 'Recent fatal errors', 'karmcp' ); ?></h3>
		<table class="widefat striped">
			<tbody>
			<?php foreach ( array_reverse( $karmcp_fatals ) as $karmcp_e ) : ?>
				<tr>
					<td style="width:9em;"><?php echo esc_html( human_time_diff( (int) ( $karmcp_e['at'] ?? time() ), time() ) ); ?></td>
					<td>
						<?php if ( ! empty( $karmcp_e['plugin'] ) ) : ?>
							<strong><?php echo esc_html( (string) $karmcp_e['plugin'] ); ?></strong><br />
						<?php endif; ?>
						<?php echo esc_html( (string) ( $karmcp_e['message'] ?? '' ) ); ?><br />
						<code><?php echo esc_html( (string) ( $karmcp_e['file'] ?? '' ) . ':' . (int) ( $karmcp_e['line'] ?? 0 ) ); ?></code>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<hr style="margin:2em 0;" />

	<h3><?php esc_html_e( 'Not fixed automatically', 'karmcp' ); ?></h3>
	<ul class="ul-disc">
		<?php foreach ( KarMCP_Security_Hardening_Fixer::unfixable() as $karmcp_id => $karmcp_why ) : ?>
			<li><code><?php echo esc_html( (string) $karmcp_id ); ?></code> — <?php echo esc_html( $karmcp_why ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
