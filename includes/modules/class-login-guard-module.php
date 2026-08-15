<?php
/**
 * Login Guard module.
 *
 * Wraps the brute-force protection as a toggleable module. **Off by default**,
 * following the plugin's rule that anything affecting the whole site ships
 * disabled: this one refuses sign-ins, and a misconfigured proxy setting can
 * lock out the owner. Turning it on is a deliberate act.
 *
 * The abilities register on `wp_abilities_api_init`, before this module boots on
 * `init:5`, so the registrar gates them on the static is_enabled() rather than
 * on register() — same pattern as Redirects and Themer.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login Guard module.
 *
 * @since 1.4.0
 */
class KarMCP_Login_Guard_Module extends KarMCP_Module {

	const ID = 'login-guard';

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Login Guard', 'karmcp' );
	}

	public function description(): string {
		return __( 'Blocks brute-force sign-in attempts: counts failures per address and per username, locks with an escalating but always time-limited delay, and closes the reconnaissance that precedes an attack — anonymous user enumeration over REST and the XML-RPC multicall that batches hundreds of password guesses into one request. Locks are visible and clearable over MCP.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	/**
	 * Off by default. It refuses sign-ins, so it follows the same rule as every
	 * other site-wide behaviour in this plugin: opt in, never surprise.
	 */
	public function default_active(): bool {
		return false;
	}

	/**
	 * Static gate for the ability registrar, which runs before modules boot.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( self::ID, $active, true );
	}

	/**
	 * Boots the guard. Called by the registry only when the module is active.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function register(): void {
		if ( class_exists( 'KarMCP_Login_Guard' ) ) {
			KarMCP_Login_Guard::init();
		}
	}

	/**
	 * The single option this module owns, with its sanitizer. The admin walks
	 * every module's fields and hands each one to register_setting(), so the
	 * shape is `key => args`, not a list.
	 *
	 * @since 1.4.0
	 * @return array<string,array>
	 */
	public function settings_fields(): array {
		return array(
			KarMCP_Login_Guard::OPTION_SETTINGS => array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			),
		);
	}

	/**
	 * Inline knobs on the Modules card.
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function render_settings(): void {
		$s    = KarMCP_Login_Guard::settings();
		$name = KarMCP_Login_Guard::OPTION_SETTINGS;
		?>
		<p>
			<label>
				<?php esc_html_e( 'Failed attempts before locking', 'karmcp' ); ?><br />
				<input type="number" min="1" max="100" name="<?php echo esc_attr( $name ); ?>[threshold]"
					value="<?php echo esc_attr( (string) $s['threshold'] ); ?>" class="small-text" />
			</label>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'First lock, in minutes (each further lock doubles it, up to 24 h)', 'karmcp' ); ?><br />
				<input type="number" min="1" max="1440" name="<?php echo esc_attr( $name ); ?>[base_lockout_minutes]"
					value="<?php echo esc_attr( (string) (int) round( $s['base_lockout'] / 60 ) ); ?>" class="small-text" />
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_enumeration]" value="1"
					<?php checked( ! empty( $s['block_enumeration'] ) ); ?> />
				<?php esc_html_e( 'Block anonymous user enumeration (REST users route and ?author=N)', 'karmcp' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[harden_xmlrpc]" value="1"
					<?php checked( ! empty( $s['harden_xmlrpc'] ) ); ?> />
				<?php esc_html_e( 'Disable XML-RPC and its multicall batching', 'karmcp' ); ?>
			</label>
			<br />
			<span class="description"><?php esc_html_e( 'Breaks the WordPress mobile app and Jetpack, which sign in over XML-RPC.', 'karmcp' ); ?></span>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Reverse proxy addresses, one per line', 'karmcp' ); ?><br />
				<textarea name="<?php echo esc_attr( $name ); ?>[trusted_proxies_text]" rows="3" cols="30"
					class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['trusted_proxies'] ) ); ?></textarea>
			</label>
			<br />
			<span class="description">
				<?php esc_html_e( 'Leave empty unless the site sits behind Cloudflare or another proxy. Empty means the guard uses REMOTE_ADDR, which is correct for a direct connection. Behind a proxy every visitor shares one address, so without this the first few failures by anybody lock out everyone — and the forwarded header that fixes it is forgeable unless it arrives from an address listed here.', 'karmcp' ); ?>
			</span>
		</p>
		<p>
			<label>
				<?php esc_html_e( 'Forwarded-for header', 'karmcp' ); ?><br />
				<input type="text" name="<?php echo esc_attr( $name ); ?>[forwarded_header]"
					value="<?php echo esc_attr( (string) $s['forwarded_header'] ); ?>" class="regular-text"
					placeholder="HTTP_CF_CONNECTING_IP" />
			</label>
			<br />
			<span class="description"><?php esc_html_e( 'Only read when the request arrives from one of the proxies above. Cloudflare: HTTP_CF_CONNECTING_IP.', 'karmcp' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Sanitizes the settings form. Registered by the admin alongside the field.
	 *
	 * @since 1.4.0
	 *
	 * @param mixed $raw Submitted value.
	 * @return array
	 */
	public static function sanitize_settings( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();

		$out = array(
			'threshold'    => (int) ( $raw['threshold'] ?? KarMCP_Login_Guard_Policy::DEFAULTS['threshold'] ),
			'base_lockout' => 60 * (int) ( $raw['base_lockout_minutes'] ?? 15 ),
		);

		$out['block_enumeration'] = ! empty( $raw['block_enumeration'] );
		$out['harden_xmlrpc']     = ! empty( $raw['harden_xmlrpc'] );

		$header                   = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $raw['forwarded_header'] ?? '' ) ) );
		$out['forwarded_header']  = $header;

		$proxies = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) ( $raw['trusted_proxies_text'] ?? '' ) ) as $line ) {
			$ip = KarMCP_Login_Guard_Policy::clean_ip( (string) $line );
			if ( '' !== $ip ) {
				$proxies[] = $ip;
			}
		}
		$out['trusted_proxies'] = array_values( array_unique( $proxies ) );

		return $out;
	}
}
