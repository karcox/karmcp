<?php
/**
 * What can be fixed by updating, and doing it.
 *
 * The half of the module that was documented as "the biggest return" and then
 * not wired: the feed already names the version that fixes each vulnerability
 * and `update-plugin` already works, but nothing joined them, so the report
 * told you what was wrong and left you to go and do it by hand.
 *
 * Grouped by plugin, deliberately. Fifteen JetEngine CVEs are one update, not
 * fifteen buttons — and the button has to say how many it actually clears,
 * because on a real site an available update often fixes some and not others.
 *
 * @package KarMCP
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update-based remediation.
 *
 * @since 1.8.0
 */
class KarMCP_Vuln_Remediation {

	/**
	 * Groups the matched vulnerabilities by plugin and works out what an update
	 * would achieve. Pure given its inputs.
	 *
	 * @since 1.8.0
	 *
	 * @param array $matches Rows from KarMCP_Vuln_Audit::match_installed().
	 * @param array $offers  file => available version.
	 * @return array<string,array<string,mixed>> Keyed by plugin file.
	 */
	public static function plan( array $matches, array $offers ): array {
		$out = array();

		foreach ( $matches as $m ) {
			$file = (string) ( $m['plugin_file'] ?? '' );
			if ( '' === $file || 'plugin' !== (string) ( $m['software_type'] ?? '' ) ) {
				continue;
			}

			if ( ! isset( $out[ $file ] ) ) {
				$out[ $file ] = array(
					'file'      => $file,
					'slug'      => (string) $m['slug'],
					'installed' => (string) $m['installed'],
					'available' => (string) ( $offers[ $file ] ?? '' ),
					'total'     => 0,
					'fixed'     => 0,
					'remaining' => 0,
					'worst'     => 0.0,
					'unpatched' => 0,
				);
			}

			$entry = &$out[ $file ];
			++$entry['total'];
			$entry['worst'] = max( (float) $entry['worst'], (float) ( $m['cvss_score'] ?? 0 ) );

			if ( empty( $m['patched'] ) ) {
				++$entry['unpatched'];
			}

			$software = array(
				'patched'           => ! empty( $m['patched'] ),
				'patched_versions'  => (array) json_decode( (string) ( $m['patched_versions'] ?? '[]' ), true ),
				'affected_versions' => (array) json_decode( (string) ( $m['affected_versions'] ?? '[]' ), true ),
			);

			if ( '' !== $entry['available'] && KarMCP_Vuln_Matcher::fixes( $entry['available'], $software ) ) {
				++$entry['fixed'];
			} else {
				++$entry['remaining'];
			}
			unset( $entry );
		}

		// Worst first: the 9.8 unauthenticated RCE is what someone should click.
		uasort(
			$out,
			static function ( $a, $b ) {
				return (float) $b['worst'] <=> (float) $a['worst'];
			}
		);

		return $out;
	}

	/**
	 * The versions WordPress is currently offering, keyed by plugin file.
	 *
	 * @since 1.8.0
	 * @return array<string,string>
	 */
	public static function offers(): array {
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();

		if ( is_object( $updates ) && ! empty( $updates->response ) && is_array( $updates->response ) ) {
			foreach ( $updates->response as $file => $data ) {
				if ( ! empty( $data->new_version ) ) {
					$out[ (string) $file ] = (string) $data->new_version;
				}
			}
		}
		return $out;
	}

	/**
	 * Updates one plugin.
	 *
	 * Goes through the same Package Guard as the MCP tool, including the
	 * vulnerability exception this module supplies — so Elementor and Elementor
	 * Pro are updatable from here exactly when a known vulnerability affects the
	 * installed version and the update clears it, and never otherwise.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file Plugin file.
	 * @return true|\WP_Error
	 */
	public static function update( string $file ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to update plugins.', 'karmcp' ) );
		}

		if ( class_exists( 'KarMCP_Package_Guard' )
			&& KarMCP_Package_Guard::is_protected_plugin( $file )
			&& ! apply_filters( 'karmcp_allow_protected_update', false, $file ) ) {
			return new \WP_Error(
				'protected_plugin',
				sprintf(
					/* translators: %s: plugin file. */
					__( '"%s" is protected. It can only be updated here when a known vulnerability affects the installed version and the available update clears it.', 'karmcp' ),
					$file
				)
			);
		}

		foreach ( array( 'file.php', 'misc.php', 'class-wp-upgrader.php', 'plugin.php' ) as $inc ) {
			$path = ABSPATH . 'wp-admin/includes/' . $inc;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
		if ( ! class_exists( '\Plugin_Upgrader' ) ) {
			return new \WP_Error( 'upgrader_unavailable', __( 'The plugin upgrader is not available.', 'karmcp' ) );
		}

		$ready = KarMCP_Package_Guard::filesystem_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$was_active = is_plugin_active( $file );

		// Refresh the update transient first, and this is not belt and braces.
		// Plugin_Upgrader reads that transient to find the package to install,
		// and a *successful* upgrade ends by calling wp_clean_plugins_cache(),
		// which deletes it. So the second update in the same request found
		// nothing on offer and returned false — reported as "the update did not
		// complete" when in truth it had never started. It looked like only one
		// plugin could be updated per page load, and that is exactly what it was.
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$current = get_site_transient( 'update_plugins' );
		if ( ! is_object( $current ) || empty( $current->response[ $file ] ) ) {
			return new \WP_Error(
				'up_to_date',
				sprintf(
					/* translators: %s: plugin file. */
					__( 'WordPress is not offering an update for "%s" — it may already be current, or its licence may not be delivering updates. Nothing was changed.', 'karmcp' ),
					$file
				)
			);
		}

		$upgrader = new \Plugin_Upgrader( KarMCP_Package_Guard::make_skin() );
		$result   = $upgrader->upgrade( $file );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error(
				'update_failed',
				__( 'The upgrader refused the update. The most common causes are the filesystem not being writable and the download failing.', 'karmcp' )
			);
		}

		// Plugin_Upgrader deactivates before upgrading. A plugin that was running
		// before must still be running after, or "update" quietly becomes
		// "update and switch off".
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file );
		}

		return true;
	}
}
