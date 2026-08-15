<?php
/**
 * The fifth scanner category: known vulnerabilities.
 *
 * Slots into KarMCP_Security_Scanner's existing shape — `ALL_CHECKS` is a list
 * and `Finding::make()` is uniform, so the hole was already there.
 *
 * Two behaviours are deliberate and neither is obvious:
 *
 * - **A feed that could not be refreshed reports `info`, never `pass`.** Being
 *   unable to check is not the same as finding nothing, and the difference is
 *   the whole reason the security section exists.
 * - **Every finding carries the Defiant attribution and a link to the record.**
 *   That is the condition the data is licensed under, not a nicety.
 *
 * @package KarMCP
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known-vulnerability audit.
 *
 * @since 1.7.0
 */
class KarMCP_Vuln_Audit {

	/**
	 * Turns matched rows into findings. Pure.
	 *
	 * @since 1.7.0
	 *
	 * @param array $matches Rows from match_installed().
	 * @return array Finding[]
	 */
	public function evaluate( array $matches ): array {
		$out = array();

		foreach ( $matches as $m ) {
			$score  = (float) ( $m['cvss_score'] ?? 0 );
			$status = ( $score >= 7.0 ) ? 'critical' : 'warning';

			$fix = ! empty( $m['patched'] ) && ! empty( $m['fix_version'] )
				? sprintf(
					/* translators: 1: software name, 2: version. */
					__( 'Update %1$s to %2$s or later.', 'karmcp' ),
					(string) $m['slug'],
					(string) $m['fix_version']
				)
				: __( 'No patched version is available yet. Consider deactivating it until there is one.', 'karmcp' );

			$out[] = KarMCP_Security_Finding::make(
				'vulnerability',
				'vulnerability',
				sprintf(
					/* translators: 1: slug, 2: installed version. */
					__( '%1$s %2$s', 'karmcp' ),
					(string) $m['slug'],
					(string) $m['installed']
				),
				$status,
				array(
					'slug'        => (string) $m['slug'],
					'type'        => (string) $m['software_type'],
					'installed'   => (string) $m['installed'],
					'cve'         => (string) ( $m['cve'] ?? '' ),
					'cvss'        => $score,
					'rating'      => (string) ( $m['cvss_rating'] ?? '' ),
					'patched'     => ! empty( $m['patched'] ),
					'fix_version' => (string) ( $m['fix_version'] ?? '' ),
					// Required by the licence: the record must be linkable and
					// the copyright must travel with the copy.
					'reference'   => (string) ( $m['reference'] ?? '' ),
					'attribution' => KarMCP_Vuln_Store::attribution(),
				),
				trim(
					sprintf(
						/* translators: 1: vulnerability title, 2: CVE id or empty. */
						__( '%1$s %2$s', 'karmcp' ),
						(string) ( $m['title'] ?? '' ),
						'' !== (string) ( $m['cve'] ?? '' ) ? '(' . $m['cve'] . ')' : ''
					)
				),
				$fix
			);
		}

		return $out;
	}

	/**
	 * Cross-references what is installed against the stored feed.
	 *
	 * @since 1.7.0
	 * @return array<int,array<string,mixed>>
	 */
	public function match_installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array();

		foreach ( (array) get_plugins() as $file => $data ) {
			$slug = strtok( (string) $file, '/' );
			if ( $slug ) {
				$installed[] = array(
					'type'    => 'plugin',
					'slug'    => $slug,
					'version' => (string) ( $data['Version'] ?? '' ),
					'file'    => (string) $file,
				);
			}
		}

		foreach ( (array) wp_get_themes() as $stylesheet => $theme ) {
			$installed[] = array(
				'type'    => 'theme',
				'slug'    => (string) $stylesheet,
				'version' => (string) $theme->get( 'Version' ),
				'file'    => (string) $stylesheet,
			);
		}

		$matches = array();

		foreach ( $installed as $item ) {
			foreach ( KarMCP_Vuln_Store::for_software( $item['type'], $item['slug'] ) as $row ) {
				$ranges = json_decode( (string) $row['affected_versions'], true );
				$ranges = is_array( $ranges ) ? $ranges : array();

				if ( ! KarMCP_Vuln_Matcher::is_affected( $item['version'], $ranges ) ) {
					continue;
				}

				$patched            = json_decode( (string) $row['patched_versions'], true );
				$patched            = is_array( $patched ) ? $patched : array();
				$row['installed']   = $item['version'];
				$row['plugin_file'] = $item['file'];
				$row['fix_version'] = (string) ( reset( $patched ) ?: '' );
				$matches[]          = $row;
			}
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return (float) $b['cvss_score'] <=> (float) $a['cvss_score'];
			}
		);

		return $matches;
	}

	/**
	 * Live run for the scanner.
	 *
	 * @since 1.7.0
	 * @return array Finding[]
	 */
	public function run(): array {
		$state = KarMCP_Vuln_Store::state();

		if ( empty( $state['refreshed_at'] ) ) {
			return array(
				KarMCP_Security_Finding::make(
					'vulnerability_no_data',
					'vulnerability',
					__( 'Vulnerability data', 'karmcp' ),
					'info',
					false,
					__( 'The vulnerability feed has never been downloaded, so nothing has been checked against it. This is not the same as finding nothing.', 'karmcp' ),
					__( 'Add a free Wordfence Intelligence API key on the Security tab, then refresh the feed.', 'karmcp' )
				),
			);
		}

		$findings = $this->evaluate( $this->match_installed() );

		if ( empty( $state['ok'] ) ) {
			// The rows are usable but no longer current, and saying so beats
			// presenting yesterday's answer as today's.
			array_unshift(
				$findings,
				KarMCP_Security_Finding::make(
					'vulnerability_stale',
					'vulnerability',
					__( 'Vulnerability data', 'karmcp' ),
					'info',
					(int) $state['refreshed_at'],
					sprintf(
						/* translators: %s: error message. */
						__( 'The last feed refresh failed, so these results are based on older data. Reason: %s', 'karmcp' ),
						(string) ( $state['error'] ?? '' )
					),
					__( 'Check the API key and the request quota on the Security tab.', 'karmcp' )
				)
			);
		}

		if ( empty( $findings ) ) {
			$findings[] = KarMCP_Security_Finding::make(
				'vulnerability',
				'vulnerability',
				__( 'Known vulnerabilities', 'karmcp' ),
				'pass',
				0,
				__( 'No installed plugin or theme matches a known vulnerability in the current feed.', 'karmcp' )
			);
		}

		return $findings;
	}
}
