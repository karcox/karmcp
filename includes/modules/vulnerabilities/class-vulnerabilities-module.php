<?php
/**
 * Known Vulnerabilities module.
 *
 * Off by default because it is the one part of KarMCP that talks to a third
 * party. What it sends is nothing about this site: the endpoint only serves the
 * complete feed and takes no parameters, so the inventory is matched locally
 * and the provider never learns which plugins or versions run here.
 *
 * It also owns the Package Guard exception. Elementor and Elementor Pro are
 * normally unupdatable over MCP; when a **known vulnerability affects the
 * installed version and the available update actually clears it**, that one
 * update is allowed. Wiring it as a filter keeps the guard — core plumbing —
 * from depending on a module that ships disabled.
 *
 * @package KarMCP
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vulnerability module.
 *
 * @since 1.7.0
 */
class KarMCP_Vulnerabilities_Module extends KarMCP_Module {

	const ID = 'vulnerabilities';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Known Vulnerabilities', 'karmcp' );
	}

	public function description(): string {
		return __( 'Checks every installed plugin and theme against the Wordfence Intelligence vulnerability database — CVE, CVSS score and the version that fixes it — and adds them to the Security report. The whole feed is downloaded once a day and matched locally, so this site never tells anyone what it runs. Needs a free API key from a wordfence.com account.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	/** Off by default: it makes an outbound request, which is opt-in here. */
	public function default_active(): bool {
		return false;
	}

	/**
	 * @since 1.7.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( self::ID, $active, true );
	}

	/**
	 * @since 1.7.0
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( 'KarMCP_Vuln_Store' ) ) {
			return;
		}
		KarMCP_Vuln_Store::init();
		add_filter( 'karmcp_allow_protected_update', array( __CLASS__, 'allow_security_update' ), 10, 2 );
	}

	/**
	 * @since 1.7.0
	 * @return array<string,array>
	 */
	public function settings_fields(): array {
		return array(
			KarMCP_Vuln_Store::OPTION_API_KEY => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $v ) {
					return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $v );
				},
			),
		);
	}

	/**
	 * @since 1.7.0
	 * @return void
	 */
	public function render_settings(): void {
		$key   = (string) get_option( KarMCP_Vuln_Store::OPTION_API_KEY, '' );
		$state = KarMCP_Vuln_Store::state();
		?>
		<p>
			<label>
				<?php esc_html_e( 'Wordfence Intelligence API key', 'karmcp' ); ?><br />
				<input type="text" class="regular-text code" name="<?php echo esc_attr( KarMCP_Vuln_Store::OPTION_API_KEY ); ?>"
					value="<?php echo esc_attr( $key ); ?>" autocomplete="off" />
			</label>
			<br />
			<span class="description">
				<?php esc_html_e( 'Free, for personal and commercial use. Create a wordfence.com account, then generate a key under Integrations — it is shown once. Note that the older unauthenticated v1 and v2 feeds are gone (410), whatever the older documentation says.', 'karmcp' ); ?>
			</span>
		</p>
		<p class="description">
			<?php
			if ( empty( $state['refreshed_at'] ) ) {
				esc_html_e( 'The feed has never been downloaded.', 'karmcp' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: 1: relative time, 2: row count. */
						__( 'Last refreshed %1$s ago, %2$d records.', 'karmcp' ),
						human_time_diff( (int) $state['refreshed_at'], time() ),
						(int) ( $state['rows'] ?? 0 )
					)
				);
				if ( empty( $state['ok'] ) ) {
					echo ' ' . esc_html(
						sprintf(
							/* translators: %s: error message. */
							__( 'The last attempt failed: %s', 'karmcp' ),
							(string) ( $state['error'] ?? '' )
						)
					);
				}
			}
			?>
		</p>
		<?php
	}

	/**
	 * The Package Guard exception.
	 *
	 * Normally Elementor, Elementor Pro and KarMCP cannot be updated over MCP.
	 * This lifts that for the first two — and only them — when the installed
	 * version is inside a known affected range **and** the update on offer
	 * genuinely leaves that range. Both halves are required: updating to
	 * something still vulnerable fixes nothing, and having bypassed the guard
	 * for it would be worse than not bypassing it at all.
	 *
	 * KarMCP never updates itself. Replacing your own code mid-request is not
	 * the same problem as updating a dependency.
	 *
	 * @since 1.7.0
	 *
	 * @param bool   $allow Current verdict.
	 * @param string $file  Plugin file, e.g. `elementor/elementor.php`.
	 * @return bool
	 */
	public static function allow_security_update( $allow, $file ) {
		if ( $allow ) {
			return true;
		}

		$eligible = array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' );
		if ( ! in_array( (string) $file, $eligible, true ) ) {
			return false;
		}

		$slug = strtok( (string) $file, '/' );
		if ( ! $slug ) {
			return false;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all       = get_plugins();
		$installed = (string) ( $all[ $file ]['Version'] ?? '' );
		if ( '' === $installed ) {
			return false;
		}

		$updates  = get_site_transient( 'update_plugins' );
		$offered  = '';
		if ( is_object( $updates ) && ! empty( $updates->response[ $file ]->new_version ) ) {
			$offered = (string) $updates->response[ $file ]->new_version;
		}
		if ( '' === $offered ) {
			return false; // Nothing to update to.
		}

		foreach ( KarMCP_Vuln_Store::for_software( 'plugin', $slug ) as $row ) {
			$ranges = json_decode( (string) $row['affected_versions'], true );
			$ranges = is_array( $ranges ) ? $ranges : array();

			if ( ! KarMCP_Vuln_Matcher::is_affected( $installed, $ranges ) ) {
				continue;
			}

			$software = array(
				'patched'           => ! empty( $row['patched'] ),
				'patched_versions'  => (array) json_decode( (string) $row['patched_versions'], true ),
				'affected_versions' => $ranges,
			);

			if ( KarMCP_Vuln_Matcher::fixes( $offered, $software ) ) {
				return true;
			}
		}

		return false;
	}
}
