<?php
/**
 * OAuth persistence — registered clients and issued tokens live in two custom
 * tables; short-lived authorization codes live in transients. Tokens and codes
 * are stored SHA-256-hashed (never in the clear).
 *
 * @package KarMCP
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB access layer for the OAuth server.
 *
 * @since 3.4.1
 */
class KarMCP_OAuth_Store {

	const DB_VERSION = 3; // v3: clients.authorized_at — never auto-purge a client that signed in.
	// A freshly-registered client legitimately has no tokens until the user
	// finishes authorizing, so orphan-client pruning only touches rows older
	// than this grace window — and only rows that never completed an
	// authorization at all (authorized_at = 0).
	const ORPHAN_CLIENT_GRACE = DAY_IN_SECONDS;
	// Throttle for gc_throttled(): the shortest gap between sweeps when gc is
	// driven from a hot path (bearer validation on every MCP request).
	const GC_THROTTLE_OPTION   = 'karmcp_oauth_gc_throttle';
	const GC_THROTTLE_INTERVAL = 900; // 15 min
	// Authorization-code lifetime. Kept short per the OAuth spec (RFC 6749
	// §4.1.2 recommends a maximum of ~10 min), but generous enough for CLI MCP
	// clients that print the URL and require a manual copy-paste of the code
	// (e.g. OpenClaw), where 60s was easy to miss. The code stays single-use and
	// PKCE-bound; only the window widened.
	const CODE_TTL          = 300;              // seconds (5 minutes)
	const CODE_PREFIX       = 'karmcp_oauth_code_';

	/**
	 * Registered-clients table name.
	 *
	 * @return string
	 */
	public static function clients_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_oauth_clients';
	}

	/**
	 * Issued-tokens table name.
	 *
	 * @return string
	 */
	public static function tokens_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'karmcp_oauth_tokens';
	}

	/**
	 * Create/upgrade the OAuth tables when the stored version is behind.
	 */
	public static function maybe_install(): void {
		// Autoloaded schema map, not an own option with autoload off: OAuth's
		// on_init runs on init:20 of every request, whether or not the sign-in
		// flow is enabled. See KarMCP_Schema_State.
		$installed = KarMCP_Schema_State::installed( KarMCP_Schema_State::KEY_OAUTH );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}
		self::install_tables();
		if ( $installed < 3 ) {
			self::backfill_authorized_clients();
		}
		KarMCP_Schema_State::mark( KarMCP_Schema_State::KEY_OAUTH, self::DB_VERSION );
	}

	/**
	 * (Re)create the OAuth tables via dbDelta — idempotent. Also called to
	 * self-heal a drifted or missing schema when a token insert fails.
	 */
	public static function install_tables(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $upgrade ) ) {
				require_once $upgrade;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}
		global $wpdb;
			$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
			$clients = self::clients_table();
			$tokens  = self::tokens_table();

			dbDelta(
				"CREATE TABLE {$clients} (
					client_id VARCHAR(64) NOT NULL,
					client_name VARCHAR(191) NOT NULL,
					redirect_uris TEXT NOT NULL,
					created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
					created_at BIGINT NOT NULL,
					authorized_at BIGINT NOT NULL DEFAULT 0,
					PRIMARY KEY (client_id)
				) {$charset};"
			);
			dbDelta(
				"CREATE TABLE {$tokens} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					token_hash CHAR(64) NOT NULL,
					token_type VARCHAR(10) NOT NULL,
					client_id VARCHAR(64) NOT NULL,
					user_id BIGINT UNSIGNED NOT NULL,
					scopes VARCHAR(191) NOT NULL DEFAULT '',
					expires_at BIGINT NOT NULL,
					refresh_of BIGINT UNSIGNED NULL DEFAULT NULL,
					created_at BIGINT NOT NULL,
					PRIMARY KEY (id),
					UNIQUE KEY token_hash (token_hash),
					KEY client_id (client_id),
					KEY user_id (user_id),
					KEY expires_at (expires_at),
					KEY refresh_of (refresh_of)
				) {$charset};"
			);
	}

	// ---------------------------------------------------------------------
	// Clients
	// ---------------------------------------------------------------------

	/**
	 * Register a new public client (Dynamic Client Registration).
	 *
	 * @param string   $name          Human-readable client name.
	 * @param string[] $redirect_uris Registered redirect URIs.
	 * @param int      $user_id       Authorizing user id (audit).
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}
	 */
	public static function create_client( string $name, array $redirect_uris, int $user_id = 0 ): array {
		global $wpdb;
		$uris = array_values( array_unique( array_filter( array_map( 'strval', $redirect_uris ) ) ) );

		// Reuse an existing public client with the same name + redirect URIs
		// instead of minting a fresh row on every connect. MCP clients (Claude,
		// Codex) re-run Dynamic Client Registration each time they connect; without
		// this the clients table grows unbounded (one dead row per connect). These
		// are public PKCE clients (no secret), and tokens are bound to the
		// authorizing user, so sharing a client_id across connects is safe.
		$existing = self::find_client_by_registration( $name, $uris );
		if ( $existing ) {
			return $existing;
		}

		$client_id = KarMCP_OAuth_Util::generate_client_id();

		$wpdb->insert(
			self::clients_table(),
			array(
				'client_id'     => $client_id,
				'client_name'   => mb_substr( $name, 0, 191 ),
				'redirect_uris' => wp_json_encode( $uris ),
				'created_by'    => $user_id,
				'created_at'    => time(),
			),
			array( '%s', '%s', '%s', '%d', '%d' )
		);

		return array(
			'client_id'     => $client_id,
			'client_name'   => $name,
			'redirect_uris' => $uris,
		);
	}

	/**
	 * Find an existing client whose name + normalized redirect URIs match a
	 * registration request, so repeat DCR from the same MCP client reuses it.
	 *
	 * @param string   $name Client name.
	 * @param string[] $uris Normalized (unique, filtered) redirect URIs.
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|null
	 */
	public static function find_client_by_registration( string $name, array $uris ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT client_id FROM %i WHERE client_name = %s AND redirect_uris = %s LIMIT 1',
				self::clients_table(),
				mb_substr( $name, 0, 191 ),
				(string) wp_json_encode( $uris )
			),
			ARRAY_A
		);
		return $row ? self::get_client( (string) $row['client_id'] ) : null;
	}

	/**
	 * Fetch a client by id.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id:string,client_name:string,redirect_uris:string[]}|null
	 */
	public static function get_client( string $client_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s', self::clients_table(), $client_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$uris = json_decode( (string) $row['redirect_uris'], true );
		return array(
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'redirect_uris' => is_array( $uris ) ? $uris : array(),
		);
	}

	// ---------------------------------------------------------------------
	// Authorization codes (transient-backed, single-use, short TTL)
	// ---------------------------------------------------------------------

	/**
	 * Store a single-use authorization code and return the raw code.
	 *
	 * @param array $payload { client_id, user_id, redirect_uri, code_challenge, scopes }.
	 * @return string The raw authorization code to hand to the client.
	 */
	public static function issue_code( array $payload ): string {
		$code = KarMCP_OAuth_Util::generate_token();
		set_transient( self::CODE_PREFIX . KarMCP_OAuth_Util::hash_token( $code ), $payload, self::CODE_TTL );
		return $code;
	}

	/**
	 * Consume (fetch + delete) an authorization code. Returns null if unknown or
	 * already used/expired.
	 *
	 * @param string $code Raw authorization code.
	 * @return array|null
	 */
	public static function consume_code( string $code ) {
		$key     = self::CODE_PREFIX . KarMCP_OAuth_Util::hash_token( $code );
		$payload = get_transient( $key );
		if ( false === $payload || ! is_array( $payload ) ) {
			return null;
		}
		delete_transient( $key );
		return $payload;
	}

	// ---------------------------------------------------------------------
	// Tokens
	// ---------------------------------------------------------------------

	/**
	 * Issue and persist a token (stored hashed). Returns the raw token.
	 *
	 * @param string   $type       'access' | 'refresh'.
	 * @param string   $client_id  Client id.
	 * @param int      $user_id    User the token acts as.
	 * @param string   $scopes     Space-separated scopes.
	 * @param int      $ttl        Lifetime in seconds.
	 * @param int|null $refresh_of Token id this access token is bound to (rotation).
	 * @return array{token:string,id:int} Raw token + row id.
	 */
	public static function issue_token( string $type, string $client_id, int $user_id, string $scopes, int $ttl, ?int $refresh_of = null ): array {
		global $wpdb;
		$token   = KarMCP_OAuth_Util::generate_token();
		$data    = array(
			'token_hash' => KarMCP_OAuth_Util::hash_token( $token ),
			'token_type' => $type,
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'scopes'     => $scopes,
			'expires_at' => time() + $ttl,
			'refresh_of' => $refresh_of,
			'created_at' => time(),
		);
		$formats = array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d' );

		$ok = $wpdb->insert( self::tokens_table(), $data, $formats );
		if ( false === $ok ) {
			// The insert failed — most often a drifted/missing tokens table after an
			// upgrade. Recreate the tables (dbDelta is idempotent) and retry once.
			self::install_tables();
			$ok = $wpdb->insert( self::tokens_table(), $data, $formats );
		}
		if ( false === $ok || ! $wpdb->insert_id ) {
			// Never hand back a token we didn't store — the caller turns this into an
			// OAuth error instead of a 200 with an unusable token. Log the DB error so
			// the real cause is visible.
			if ( function_exists( 'error_log' ) ) {
				error_log( '[KarMCP] OAuth token was not persisted: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return array( 'token' => '', 'id' => 0 );
		}
		// A token was issued for this client, so it completed an authorization.
		// Stamp it, so gc() never prunes the registration once those tokens lapse.
		self::mark_client_authorized( $client_id );
		return array( 'token' => $token, 'id' => (int) $wpdb->insert_id );
	}

	/**
	 * Look up an unexpired token row by raw token + type.
	 *
	 * @param string $token Raw token.
	 * @param string $type  'access' | 'refresh'.
	 * @return array|null Row (assoc) or null.
	 */
	public static function find_token( string $token, string $type ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s AND expires_at > %d',
				self::tokens_table(),
				KarMCP_OAuth_Util::hash_token( $token ),
				$type,
				time()
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Delete a token row and any access token bound to it. Used for explicit
	 * revocation (RFC 7009 /revoke) and client teardown — there we DO want the
	 * bound access token gone immediately.
	 *
	 * @param int $id Token row id.
	 */
	public static function revoke_token( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::tokens_table(), array( 'id' => $id ), array( '%d' ) );
		$wpdb->delete( self::tokens_table(), array( 'refresh_of' => $id ), array( '%d' ) );
	}

	/**
	 * Rotate a refresh token out WITHOUT touching its bound access token.
	 *
	 * On refresh the old refresh token must become unusable (rotation), but the
	 * old ACCESS token is a short-lived bearer credential that in-flight MCP
	 * requests may still be carrying. Cascade-deleting it (as revoke_token does)
	 * 401s those requests the instant the client refreshes — which surfaced as
	 * connections dropping mid-chat. Letting the access token live out its own
	 * TTL is the standard OAuth behaviour (RFC 6749 §1.5: refreshing does not
	 * invalidate previously issued access tokens).
	 *
	 * When a positive $grace is given, the rotated refresh token is not deleted
	 * but SOFT-EXPIRED to `now + grace` instead — it stays usable for that short
	 * window so a lost-response retry (the client's refresh succeeded server-side
	 * but the response was dropped, so it re-presents the same refresh token)
	 * re-rotates and gets a fresh pair rather than a 401 mid-chat. We only ever
	 * shorten the lifetime (grace << remaining refresh TTL), then it expires on
	 * its own and gc() prunes it. $grace = 0 keeps the immediate-delete behaviour.
	 *
	 * @param int $id    Refresh token row id.
	 * @param int $grace Grace window in seconds (0 = delete immediately).
	 */
	public static function rotate_out_refresh( int $id, int $grace = 0 ): void {
		global $wpdb;
		if ( $grace <= 0 ) {
			$wpdb->delete( self::tokens_table(), array( 'id' => $id ), array( '%d' ) );
			return;
		}
		$until = time() + $grace;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET expires_at = %d WHERE id = %d AND expires_at > %d',
				self::tokens_table(),
				$until,
				$id,
				$until
			)
		);
	}

	/**
	 * Revoke every token issued to a client. Returns rows removed.
	 *
	 * @param string $client_id Client id.
	 * @return int
	 */
	public static function revoke_client( string $client_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( self::tokens_table(), array( 'client_id' => $client_id ), array( '%s' ) );
	}

	/**
	 * List registered clients that have at least one live token, with usage
	 * detail for the admin "Authorized clients" table.
	 *
	 * @return array<int,array{client_id:string,client_name:string,user_id:int,created_at:int,active_tokens:int}>
	 */
	public static function list_authorized_clients(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.client_id, c.client_name, c.created_at,
					COUNT( t.id ) AS active_tokens,
					MAX( t.user_id ) AS user_id
				FROM %i c
				INNER JOIN %i t
					ON t.client_id = c.client_id AND t.expires_at > %d
				GROUP BY c.client_id, c.client_name, c.created_at
				ORDER BY c.created_at DESC',
				self::clients_table(),
				self::tokens_table(),
				time()
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $r ) {
				return array(
					'client_id'     => (string) $r['client_id'],
					'client_name'   => (string) $r['client_name'],
					'user_id'       => (int) $r['user_id'],
					'created_at'    => (int) $r['created_at'],
					'active_tokens' => (int) $r['active_tokens'],
				);
			},
			$rows
		);
	}

	/**
	 * Housekeeping: delete expired tokens, then delete orphan client rows (no
	 * tokens and older than the grace window). Runs on the daily cron and
	 * opportunistically; idempotent.
	 */
	public static function gc(): void {
		global $wpdb;
		$now = time();

		// 1) Expired access/refresh tokens.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', self::tokens_table(), $now ) );

		// 2) Orphan clients — repeat DCR (or an abandoned registration retry)
		// leaves token-less rows; drop those older than the grace window.
		//
		// Only rows that never completed an authorization (authorized_at = 0).
		// "Has no tokens right now" is not the same as "abandoned": a real,
		// connected client whose refresh token lapsed after 30 days idle also has
		// zero tokens, and deleting its registration turned a recoverable "sign in
		// again" into a permanent "Invalid client" — the app still has the
		// client_id cached, so it reopened the authorize page on a loop and could
		// never reconnect. A client that signed in once is kept; only the admin
		// screen or the uninstaller removes it.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE c FROM %i c
				 LEFT JOIN %i t ON t.client_id = c.client_id
				 WHERE t.id IS NULL AND c.authorized_at = 0 AND c.created_at < %d',
				self::clients_table(),
				self::tokens_table(),
				$now - self::ORPHAN_CLIENT_GRACE
			)
		);
	}

	/**
	 * Stamp a client as having completed an authorization, so gc() never prunes
	 * it. Idempotent and cheap: the WHERE only matches the first time.
	 *
	 * @since 1.28.0
	 * @param string $client_id Client id.
	 */
	public static function mark_client_authorized( string $client_id ): void {
		if ( '' === $client_id ) {
			return;
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET authorized_at = %d WHERE client_id = %s AND authorized_at = 0',
				self::clients_table(),
				time(),
				$client_id
			)
		);
	}

	/**
	 * Upgrade backfill (DB v3): a client that holds a token has demonstrably
	 * completed an authorization, so stamp it before the next gc() runs.
	 * Without this, an existing connection whose tokens lapse right after the
	 * upgrade would still be purged once. Clients with no tokens keep
	 * authorized_at = 0 and stay prunable, which is what genuinely abandoned
	 * registrations should be.
	 *
	 * @since 1.28.0
	 */
	public static function backfill_authorized_clients(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i c
				 SET c.authorized_at = %d
				 WHERE c.authorized_at = 0
				   AND EXISTS ( SELECT 1 FROM %i t WHERE t.client_id = c.client_id )',
				self::clients_table(),
				time(),
				self::tokens_table()
			)
		);
	}

	/**
	 * Run gc() at most once per interval (transient-guarded), so it can be called
	 * cheaply from a hot path — bearer validation on every MCP request — without a
	 * DELETE per request. This is the "clean at validation time" path: any site
	 * actively serving MCP traffic sweeps expired tokens/orphans within the
	 * interval, independent of WP-Cron reliability. Returns whether it swept.
	 *
	 * @param int $interval Minimum seconds between sweeps.
	 * @return bool
	 */
	public static function gc_throttled( int $interval = self::GC_THROTTLE_INTERVAL ): bool {
		if ( get_transient( self::GC_THROTTLE_OPTION ) ) {
			return false;
		}
		set_transient( self::GC_THROTTLE_OPTION, 1, max( 60, $interval ) );
		self::gc();
		return true;
	}
}
