<?php
/**
 * The `/authorize` endpoint — validates the authorization request, gates it
 * behind a WordPress login + administrator consent, and (on approval) issues a
 * single-use, PKCE-bound authorization code before redirecting back to the
 * client.
 *
 * Client/redirect-URI validation happens before anything is echoed or
 * redirected, so a bad client can never be used as an open redirect. All other
 * errors are returned to the (validated) redirect URI per RFC 6749 §4.1.2.1.
 *
 * @package KarMCP
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorization endpoint + consent screen.
 *
 * @since 3.4.1
 */
class KarMCP_OAuth_Authorize {

	const NONCE_ACTION = 'karmcp_oauth_consent';

	/**
	 * The browser-facing authorize path. This is served as a normal front-end
	 * request (NOT a REST route) so WordPress cookie auth applies — a REST
	 * endpoint would require a nonce the client's browser navigation can't
	 * provide, and cookie sessions would never be recognized.
	 */
	const PATH = '/karmcp-oauth/authorize';

	/**
	 * Wire the root-level request interception for the authorize endpoint.
	 */
	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * The absolute authorize endpoint URL (advertised in the AS metadata).
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		// Reachable public base (rest_url-derived / admin-overridable), NOT
		// home_url() — see KarMCP_Site_Context::public_base_url(). Keeps the
		// advertised authorize URL on the host clients can actually reach.
		if ( class_exists( 'KarMCP_Site_Context' ) ) {
			return KarMCP_Site_Context::public_base_url() . self::PATH;
		}
		return home_url( self::PATH );
	}

	/**
	 * Serve the authorize endpoint when the request path matches; no-op
	 * otherwise. Dispatches GET (render consent) vs POST (record decision).
	 *
	 * @param WP $wp Current environment (unused).
	 */
	public static function maybe_serve( $wp = null ): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! self::path_matches( $path, self::site_path_prefix() ) ) {
			return;
		}
		if ( ! KarMCP_OAuth_Server::is_enabled() ) {
			self::error_page( __( 'OAuth sign-in is not enabled on this site.', 'karmcp' ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'POST' === strtoupper( $method ) ) {
			self::handle_post();
		} else {
			self::handle_get();
		}
	}

	/**
	 * Whether a request path is this endpoint.
	 *
	 * Pure, so the subdirectory case is testable without WordPress.
	 *
	 * The endpoint is advertised as `<base>/karmcp-oauth/authorize`, and on a
	 * WordPress installed in a subdirectory that base carries the subdirectory —
	 * so the request arrives as `/blog/karmcp-oauth/authorize` while the constant
	 * is `/karmcp-oauth/authorize`. Comparing the two directly means the endpoint
	 * we published is never served and sign-in dead-ends on the site's 404 page.
	 * The bare path is still accepted, for a proxy that strips the prefix before
	 * the request reaches WordPress.
	 *
	 * @since 1.16.2
	 *
	 * @param string $request_path Path of the incoming request (no query string).
	 * @param string $site_prefix  The installation's path prefix ('' at the root).
	 * @return bool
	 */
	public static function path_matches( string $request_path, string $site_prefix ): bool {
		if ( '/' !== $request_path ) {
			$request_path = rtrim( $request_path, '/' );
		}
		if ( self::PATH === $request_path ) {
			return true;
		}
		$site_prefix = rtrim( $site_prefix, '/' );
		return '' !== $site_prefix && ( $site_prefix . self::PATH ) === $request_path;
	}

	/**
	 * The path WordPress is installed under, '' when it is at the domain root.
	 *
	 * Read from home_url() — the site as it is actually served here — not from
	 * the Server URL override, which may point at a different host entirely.
	 *
	 * @since 1.16.2
	 *
	 * @return string
	 */
	private static function site_path_prefix(): string {
		$path = (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_PATH );
		$path = rtrim( $path, '/' );
		return '/' === $path ? '' : $path;
	}

	/**
	 * Read request params from a superglobal (unslashed, string values only).
	 * Values are validated / escaped downstream.
	 *
	 * @param array $src $_GET or $_POST.
	 * @return array<string,string>
	 */
	private static function request_params( array $src ): array {
		$out = array();
		foreach ( $src as $k => $v ) {
			if ( is_string( $v ) ) {
				$out[ (string) $k ] = (string) wp_unslash( $v );
			}
		}
		return $out;
	}

	/**
	 * The capability required to approve a connection (filterable).
	 *
	 * @return string
	 */
	public static function required_cap(): string {
		return (string) apply_filters( 'karmcp_oauth_authorize_cap', 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// GET — validate + login-gate + render consent
	// ---------------------------------------------------------------------

	private static function handle_get(): void {
		$params       = self::request_params( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public authorization endpoint; params validated below, no state mutation on GET.
		$client_id    = (string) ( $params['client_id'] ?? '' );
		$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );
		$client       = self::lookup_client( $client_id );

		// Client + redirect must be valid before we trust redirect_uri as a target.
		// The two failures need different things from whoever is reading the page,
		// so they are reported apart. Neither value is a secret: the caller sent
		// one and registered the other.
		if ( '' === $client_id || null === $client ) {
			self::error_page(
				__( 'This site does not recognise the app making this connection request.', 'karmcp' ),
				self::stale_client_hint()
			);
		}
		if ( '' === $redirect_uri || ! self::redirect_registered( $client, $redirect_uri ) ) {
			self::error_page(
				__( 'The return address this app asked for does not match the one it registered.', 'karmcp' ),
				self::redirect_mismatch_hint( $client, $redirect_uri )
			);
		}

		$state = (string) ( $params['state'] ?? '' );
		$valid = self::validate_params( $params, $client );
		if ( is_wp_error( $valid ) ) {
			self::redirect_error( $redirect_uri, $valid->get_error_code(), $state );
		}

		if ( ! is_user_logged_in() ) {
			// This one goes to our own login screen, so it takes the safe
			// variant — unlike the two client redirects below, which cannot.
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}
		if ( ! current_user_can( self::required_cap() ) ) {
			self::error_page( __( 'Only administrators can authorize an MCP connection on this site.', 'karmcp' ) );
		}

		echo self::render_consent( array_merge( $valid, array( 'client_name' => $client['client_name'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_consent escapes.
		exit;
	}

	// ---------------------------------------------------------------------
	// POST — record the approve/deny decision
	// ---------------------------------------------------------------------

	private static function handle_post(): void {
		if ( ! is_user_logged_in() || ! current_user_can( self::required_cap() ) ) {
			self::error_page( __( 'You are not allowed to authorize this connection.', 'karmcp' ) );
		}

		$p     = self::request_params( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified immediately below.
		$nonce = (string) ( $p['_karmcp_oauth_nonce'] ?? '' );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::error_page( __( 'Security check failed. Please start the connection again.', 'karmcp' ) );
		}

		$client_id    = (string) ( $p['client_id'] ?? '' );
		$redirect_uri = (string) ( $p['redirect_uri'] ?? '' );
		$client       = self::lookup_client( $client_id );
		if ( null === $client ) {
			self::error_page(
				__( 'This site does not recognise the app making this connection request.', 'karmcp' ),
				self::stale_client_hint()
			);
		}
		if ( ! self::redirect_registered( $client, $redirect_uri ) ) {
			self::error_page(
				__( 'The return address this app asked for does not match the one it registered.', 'karmcp' ),
				self::redirect_mismatch_hint( $client, $redirect_uri )
			);
		}

		$state = (string) ( $p['state'] ?? '' );
		if ( 'approve' !== ( $p['action'] ?? '' ) ) {
			self::redirect_error( $redirect_uri, 'access_denied', $state );
		}

		$challenge = (string) ( $p['code_challenge'] ?? '' );
		if ( '' === $challenge ) {
			self::redirect_error( $redirect_uri, 'invalid_request', $state );
		}

		$code = KarMCP_OAuth_Store::issue_code(
			array(
				'client_id'      => $client['client_id'],
				'user_id'        => get_current_user_id(),
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $challenge,
				// Re-normalized rather than trusted: this value round-trips
				// through a hidden field on the consent form.
				'scopes'         => self::normalize_scope( (string) ( $p['scope'] ?? '' ) ),
			)
		);

		// wp_redirect, not wp_safe_redirect, and that is the protocol: the
		// authorization code goes back to the CLIENT's redirect_uri, which is by
		// definition an external address. wp_safe_redirect() would restrict it to
		// this host and break every sign-in. What makes it safe is not the
		// redirect function but redirect_registered() above, which matched this
		// URI against the ones the client registered — an unregistered URI never
		// reaches this line.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- see above: external by protocol, validated against the client's registered URIs.
		wp_redirect( self::build_redirect( $redirect_uri, array( 'code' => $code, 'state' => $state ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit-tested)
	// ---------------------------------------------------------------------

	/**
	 * Validate the non-client authorization parameters.
	 *
	 * @param array      $params Request params.
	 * @param array|null $client The resolved client (null if unknown).
	 * @return array|WP_Error Normalized params, or an error whose code is a valid
	 *                        OAuth error slug (safe to return to redirect_uri).
	 */
	public static function validate_params( array $params, ?array $client ) {
		if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		if ( null === $client ) {
			return new WP_Error( 'invalid_request', 'Unknown client.' );
		}
		if ( 'S256' !== ( $params['code_challenge_method'] ?? '' ) || '' === (string) ( $params['code_challenge'] ?? '' ) ) {
			return new WP_Error( 'invalid_request', 'PKCE with S256 is required.' );
		}
		return array(
			'client_id'      => (string) ( $params['client_id'] ?? '' ),
			'redirect_uri'   => (string) ( $params['redirect_uri'] ?? '' ),
			'code_challenge' => (string) $params['code_challenge'],
			'state'          => (string) ( $params['state'] ?? '' ),
			'scope'          => self::normalize_scope( (string) ( $params['scope'] ?? '' ) ),
		);
	}

	/**
	 * Reduce a requested scope string to what this server actually grants.
	 *
	 * The request value was previously stored and echoed back verbatim as the
	 * GRANTED scope, so a client asking for `mcp admin:everything` was told it
	 * had been given it. Nothing downstream reads the column, so this was a
	 * truthfulness bug rather than an open door — but the moment a scope check
	 * is added, an unfiltered column is what it would be reading. RFC 6749 §3.3
	 * allows granting a narrower scope than asked for, as long as the response
	 * says so, which the token response already does.
	 *
	 * @since 1.2.0
	 * @param string $requested Space-separated scopes from the client.
	 * @return string Space-separated granted scopes; never empty.
	 */
	public static function normalize_scope( string $requested ): string {
		$supported = array( KarMCP_OAuth_Server::SCOPE );
		$granted   = array();

		foreach ( (array) preg_split( '/\s+/', trim( $requested ) ) as $scope ) {
			$scope = (string) $scope;
			if ( '' !== $scope && in_array( $scope, $supported, true ) && ! in_array( $scope, $granted, true ) ) {
				$granted[] = $scope;
			}
		}

		return empty( $granted ) ? KarMCP_OAuth_Server::SCOPE : implode( ' ', $granted );
	}

	/**
	 * Whether a redirect URI is registered for the client.
	 *
	 * @param array  $client       Client with a `redirect_uris` array.
	 * @param string $redirect_uri Candidate.
	 * @return bool
	 */
	public static function redirect_registered( array $client, string $redirect_uri ): bool {
		foreach ( (array) ( $client['redirect_uris'] ?? array() ) as $registered ) {
			if ( is_string( $registered ) && KarMCP_OAuth_Util::redirect_uri_matches( $registered, $redirect_uri ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append query args to a redirect URI (handles existing query strings).
	 *
	 * @param string $redirect_uri Base URI.
	 * @param array  $args         Args (empty values are dropped).
	 * @return string
	 */
	public static function build_redirect( string $redirect_uri, array $args ): string {
		$pairs = array();
		foreach ( $args as $k => $v ) {
			if ( '' !== (string) $v ) {
				$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
		}
		if ( empty( $pairs ) ) {
			return $redirect_uri;
		}
		$sep = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		return $redirect_uri . $sep . implode( '&', $pairs );
	}

	/**
	 * Render the consent screen HTML.
	 *
	 * @param array $ctx { client_id, client_name, redirect_uri, code_challenge, state, scope }.
	 * @return string
	 */
	public static function render_consent( array $ctx ): string {
		$user       = wp_get_current_user();
		$site       = get_bloginfo( 'name' );
		$client     = (string) ( $ctx['client_name'] ?? 'An MCP client' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$deny_label = __( 'Deny', 'karmcp' );

		$hidden = '';
		foreach ( array( 'client_id', 'redirect_uri', 'code_challenge', 'state', 'scope' ) as $k ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) ( $ctx[ $k ] ?? '' ) ) . '" />';
		}
		$hidden .= '<input type="hidden" name="_karmcp_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';

		$action = esc_url( self::endpoint_url() );

		return '<!doctype html><html><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex" />'
			. '<title>' . esc_html__( 'Authorize connection', 'karmcp' ) . '</title>'
			. '<style>'
			. 'body{margin:0;background:#f5f6fa;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:#0a0a14}'
			. '.wrap{max-width:460px;margin:8vh auto;padding:0 20px}'
			. '.card{background:#fff;border:1px solid #0a0a141a;border-radius:16px;padding:32px}'
			. '.eyebrow{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#4338ca;font-weight:700;margin-bottom:14px}'
			. 'h1{font-size:22px;line-height:1.25;margin:0 0 14px}'
			. 'p{font-size:15px;line-height:1.6;color:#3a3b52;margin:0 0 14px}'
			. '.who{background:#f5f6fa;border:1px solid #0a0a1410;border-radius:10px;padding:12px 14px;font-size:14px;margin:0 0 20px}'
			. '.who b{color:#0a0a14}'
			. '.warn{font-size:13px;color:#71748b;margin:0 0 22px}'
			. '.row{display:flex;gap:10px}'
			. 'button{flex:1;padding:12px 16px;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;border:1px solid transparent}'
			. '.approve{background:#4f46e5;color:#fff}'
			. '.deny{background:#fff;border-color:#0a0a1428;color:#3a3b52}'
			. '</style></head><body><div class="wrap"><div class="card">'
			. '<div class="eyebrow">' . esc_html__( 'Authorize MCP connection', 'karmcp' ) . '</div>'
			. '<h1>' . sprintf(
				/* translators: 1: client name, 2: site name */
				esc_html__( '%1$s wants to connect to %2$s', 'karmcp' ),
				'<b>' . esc_html( $client ) . '</b>',
				esc_html( $site )
			) . '</h1>'
			. '<p>' . esc_html__( 'It will connect as your WordPress account and can do anything you can through the MCP tools you have enabled.', 'karmcp' ) . '</p>'
			. '<div class="who">' . sprintf(
				/* translators: 1: display name, 2: user login */
				esc_html__( 'Signed in as %1$s (%2$s)', 'karmcp' ),
				'<b>' . esc_html( $user->display_name ) . '</b>',
				esc_html( $user->user_login )
			) . '</div>'
			. '<p class="warn">' . esc_html__( 'Only approve connections you started yourself. You can revoke access anytime from KarMCP → Connection.', 'karmcp' ) . '</p>'
			. '<form method="post" action="' . $action . '">' . $hidden
			. '<div class="row">'
			. '<button class="deny" type="submit" name="action" value="deny">' . esc_html( $deny_label ) . '</button>'
			. '<button class="approve" type="submit" name="action" value="approve">' . esc_html__( 'Approve', 'karmcp' ) . '</button>'
			. '</div></form></div></div></body></html>';
	}

	// ---------------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------------

	/**
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	private static function lookup_client( string $client_id ): ?array {
		return '' === $client_id ? null : KarMCP_OAuth_Store::get_client( $client_id );
	}

	/**
	 * Redirect back to the client with an OAuth error, then exit.
	 *
	 * @param string $redirect_uri Validated redirect URI. Callers MUST have run
	 *                             redirect_registered() first — this is an
	 *                             unrestricted redirect by protocol.
	 * @param string $error        OAuth error code.
	 * @param string $state        Opaque state to echo back.
	 */
	private static function redirect_error( string $redirect_uri, string $error, string $state ): void {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external by protocol; the caller validated this URI against the client's registered set.
		wp_redirect( self::build_redirect( $redirect_uri, array( 'error' => $error, 'state' => $state ) ) );
		exit;
	}

	/**
	 * Output a minimal HTML error page (used when there is no safe redirect
	 * target), then exit.
	 *
	 * @param string $message Message.
	 */
	private static function error_page( string $message, string $hint = '' ): never {
		if ( ! headers_sent() ) {
			status_header( 400 );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo '<!doctype html><meta charset="utf-8" /><title>' . esc_html__( 'Connection error', 'karmcp' ) . '</title>'
			. '<div style="max-width:460px;margin:12vh auto;font-family:sans-serif;text-align:center;color:#0a0a14">'
			. '<h1 style="font-size:20px">' . esc_html__( 'Connection error', 'karmcp' ) . '</h1>'
			. '<p style="color:#3a3b52">' . esc_html( $message ) . '</p>';
		if ( '' !== $hint ) {
			echo '<p style="color:#6a6b82;font-size:13px;word-break:break-all">' . esc_html( $hint ) . '</p>';
		}
		echo '</div>';
		exit;
	}

	/**
	 * What to tell someone whose app is not recognised: almost always a client
	 * that registered once and is reconnecting against a registration this site
	 * no longer holds.
	 *
	 * @since 1.28.0
	 * @return string
	 */
	private static function stale_client_hint(): string {
		return __( 'This usually means the app is reconnecting with a registration this site no longer recognises. In your AI app, remove this MCP connector and add it again to start a fresh connection.', 'karmcp' );
	}

	/**
	 * What to tell someone whose return address does not match. Both addresses
	 * are shown, because comparing them is the only way to act on this, and
	 * neither is a secret: the caller supplied one and registered the other.
	 *
	 * @since 1.28.0
	 * @param array  $client       Client with a `redirect_uris` array.
	 * @param string $redirect_uri The address the app asked for.
	 * @return string
	 */
	private static function redirect_mismatch_hint( array $client, string $redirect_uri ): string {
		$registered = array_values( array_filter( (array) ( $client['redirect_uris'] ?? array() ), 'is_string' ) );
		$hint       = __( 'The app registered a different return address than the one it is now asking for. Removing this MCP connector in the app and adding it again usually clears it.', 'karmcp' );
		if ( ! $registered ) {
			return $hint;
		}
		return $hint . ' ' . sprintf(
			/* translators: 1: the return address requested, 2: comma-separated list of registered addresses. */
			__( 'Requested: %1$s. Registered: %2$s.', 'karmcp' ),
			'' !== $redirect_uri ? $redirect_uri : __( '(none)', 'karmcp' ),
			implode( ', ', $registered )
		);
	}

	/**
	 * The absolute URL of the current request (for the login return).
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$scheme = ( function_exists( 'is_ssl' ) && is_ssl() ) ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		// Deliberately not sanitize_text_field(): REQUEST_URI still carries its
		// percent-encoding here, and that function strips every %XX octet. The
		// client's redirect_uri=http%3A%2F%2F127.0.0.1%3A33418%2Fcallback came back
		// from wp-login.php as http127.0.0.133418callback — no longer the URI the
		// client registered, so authorize rejected it and sign-in dead-ended for
		// every user who was not already logged in. esc_url_raw(), in
		// build_return_url() below, is the sanitizer for a URL: it keeps %XX intact.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() in build_return_url() sanitizes it; see the note above for why the usual sanitizer cannot be used here.
		return self::build_return_url( $scheme, $host, (string) $uri );
	}

	/**
	 * Assemble the absolute login-return URL from its parts.
	 *
	 * Pure, so the percent-encoding the OAuth parameters ride on is testable
	 * without WordPress.
	 *
	 * @since 1.37.1
	 *
	 * @param string $scheme Request scheme.
	 * @param string $host   Request host.
	 * @param string $uri    Request URI, percent-encoding intact.
	 * @return string
	 */
	public static function build_return_url( string $scheme, string $host, string $uri ): string {
		return esc_url_raw( $scheme . '://' . $host . $uri );
	}
}
