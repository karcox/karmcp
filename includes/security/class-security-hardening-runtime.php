<?php
/**
 * Applies whichever hardening fixes are switched on, on every request.
 *
 * Kept apart from the fixer on purpose: that one decides and records, this one
 * only enforces. It runs unconditionally and does nothing when the option is
 * empty, so the cost on a site that never hardened anything is one option read
 * that WordPress has already cached.
 *
 * @package KarMCP
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hardening enforcement.
 *
 * @since 1.5.0
 */
class KarMCP_Security_Hardening_Runtime {

	/**
	 * Wires whatever is switched on. Called from the bootstrap on `plugins_loaded`.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function init(): void {
		$applied = KarMCP_Security_Hardening_Fixer::applied();
		if ( empty( $applied ) ) {
			return;
		}

		if ( in_array( 'disallow_file_edit', $applied, true ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			// WordPress reads this when it builds the admin menu and when the
			// editor screens load, both of which happen after plugins — so
			// defining it here is as effective as the wp-config line, and unlike
			// that line it can be undone from a checkbox.
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( in_array( 'disable_xmlrpc', $applied, true ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'strip_multicall' ) );
		}

		if ( in_array( 'hide_version', $applied, true ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_version_arg' ), 9999 );
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_version_arg' ), 9999 );
		}

		if ( in_array( 'security_headers', $applied, true ) ) {
			add_filter( 'wp_headers', array( __CLASS__, 'add_security_headers' ) );
		}
	}

	/**
	 * @since 1.5.0
	 * @param mixed $methods XML-RPC methods.
	 * @return mixed
	 */
	public static function strip_multicall( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}
		unset( $methods['system.multicall'], $methods['pingback.ping'] );
		return $methods;
	}

	/**
	 * Replaces `?ver=6.9.1` with a stable hash of it.
	 *
	 * Stripping the argument outright is the common advice and it is wrong: the
	 * version is what busts the browser cache after an upgrade, so removing it
	 * leaves visitors on stale CSS. Substituting a hash keeps the busting and
	 * loses the disclosure.
	 *
	 * @since 1.5.0
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public static function strip_version_arg( $src ) {
		$src = (string) $src;
		if ( '' === $src || false === strpos( $src, 'ver=' ) ) {
			return $src;
		}

		$parts = wp_parse_url( $src );
		if ( empty( $parts['query'] ) ) {
			return $src;
		}

		parse_str( (string) $parts['query'], $args );
		if ( ! isset( $args['ver'] ) || '' === $args['ver'] ) {
			return $src;
		}

		// Only the WordPress version leaks anything; a plugin's own version in
		// its asset URL is not a core fingerprint and is left alone.
		global $wp_version;
		if ( (string) $args['ver'] !== (string) $wp_version ) {
			return $src;
		}

		$args['ver'] = substr( md5( (string) $wp_version . AUTH_SALT ), 0, 8 );
		return add_query_arg( $args, strtok( $src, '?' ) );
	}

	/**
	 * Adds the headers that are safe to generate.
	 *
	 * Content-Security-Policy is deliberately absent. A CSP that a machine
	 * guessed breaks page builders, inline handlers and half the third-party
	 * embeds on a real site — and a CSP that had to be loosened until the site
	 * worked again protects nothing while looking like it does.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $headers Existing headers.
	 * @return mixed
	 */
	public static function add_security_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}

		$defaults = array(
			'X-Frame-Options'        => 'SAMEORIGIN',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		);

		// HSTS only over HTTPS: sent on plain HTTP it is ignored by browsers,
		// and promising it before the certificate exists locks visitors out of a
		// site that cannot yet answer on 443.
		if ( is_ssl() ) {
			$defaults['Strict-Transport-Security'] = 'max-age=15552000';
		}

		foreach ( $defaults as $name => $value ) {
			if ( ! isset( $headers[ $name ] ) ) {
				$headers[ $name ] = $value;
			}
		}

		return $headers;
	}
}
