<?php
/**
 * WP-CLI command validator — the security gate for the WP-CLI tool.
 *
 * Tokenizes a `wp` command string into an argv array (quote-aware) and rejects
 * the RCE-grade subcommands and injection flags. Args are always passed to the
 * runner as an array (never interpolated into a shell string), so metacharacters
 * in *values* are inert; this validator blocks the *command surface* that would
 * let an operator run arbitrary PHP, raw SQL, or arbitrary shell.
 *
 * **This is a denylist, and a denylist is never complete.** It is defence in
 * depth behind the real gates — the tool ships disabled, needs `manage_options`,
 * and WP-CLI itself then applies WordPress's own permissions. Treat enabling it
 * as granting shell-adjacent power, and prefer the narrower dedicated tools
 * (`install-plugin`, `update-settings`, `create-user`) where they exist. To
 * lock it down to a known set of commands instead, use the
 * `karmcp_wpcli_blocked_commands` filter or refuse everything else in
 * `karmcp_wpcli_validate`.
 *
 * @package KarMCP
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates + tokenizes WP-CLI command strings.
 *
 * @since 3.4.0
 */
class KarMCP_WPCLI_Validator {

	/**
	 * Whole commands that are never allowed (arbitrary code / shell / servers).
	 *
	 * `search-replace` is a TOP-LEVEL WP-CLI command, not `db search-replace`:
	 * it rewrites every table in place, which would sail straight past the
	 * protected-table list that guards the structured DB write tools.
	 */
	const BLOCKED_COMMANDS = array( 'eval', 'eval-file', 'shell', 'server', 'search-replace' );

	/** `command subcommand` pairs that are never allowed. */
	const BLOCKED_SUBCOMMANDS = array(
		'db query', 'db cli', 'db import', 'db export', 'db reset', 'db drop', 'db clean',
		// Writes were blocked but reads were not: `config get`/`config list`
		// print DB_PASSWORD and every salt to stdout. Those salts are what
		// KarMCP_Secret derives its encryption key from, so leaking them
		// decrypts every stored secret — and the Filesystem guard already
		// refuses to read wp-config.php for exactly this reason.
		'config set', 'config delete', 'config edit',
		'config get', 'config list', 'config path', 'config has',
		'package install', 'package update', 'package uninstall',
		'cli update', 'cli cmd-dump', 'cli info',
		// Runs whatever callback a scheduled event points at, on demand.
		'cron event run',
	);

	/**
	 * Options that decide which code runs, where the site lives, or who may
	 * sign up. Rewriting one of these is a takeover, not a settings change.
	 */
	const PROTECTED_OPTIONS = array(
		'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet',
		'siteurl', 'home', 'users_can_register', 'default_role', 'cron',
	);

	/**
	 * Global flags that are refused wherever they appear: they load arbitrary
	 * PHP (--exec/--require), retarget the install (--path/--url/--ssh/--http),
	 * or would hang on a prompt (--prompt).
	 */
	const BLOCKED_FLAG_PREFIXES = array( '--exec', '--require', '--path', '--ssh', '--http', '--prompt', '--user=0' );

	/**
	 * Validate + tokenize a command string.
	 *
	 * @param string $command Raw `wp` command (without the leading `wp`).
	 * @return array|\WP_Error argv token array on success, WP_Error on rejection.
	 */
	public static function validate( string $command ) {
		$command = trim( $command );
		if ( '' === $command ) {
			return new \WP_Error( 'wpcli_empty', __( 'No command given.', 'karmcp' ) );
		}
		if ( preg_match( '/[\r\n]/', $command ) ) {
			return new \WP_Error( 'wpcli_newline', __( 'A command may not contain line breaks.', 'karmcp' ) );
		}
		// A leading "wp" is tolerated and stripped ("wp plugin list" == "plugin list").
		$command = preg_replace( '/^wp\s+/i', '', $command );

		$tokens = self::tokenize( $command );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( empty( $tokens ) ) {
			return new \WP_Error( 'wpcli_empty', __( 'No command given.', 'karmcp' ) );
		}

		// Injection / retargeting flags anywhere in the args.
		foreach ( $tokens as $tok ) {
			foreach ( self::blocked_flag_prefixes() as $flag ) {
				if ( 0 === stripos( $tok, $flag ) ) {
					/* translators: %s: the refused flag */
					return new \WP_Error( 'wpcli_blocked_flag', sprintf( __( 'The flag "%s" is not allowed.', 'karmcp' ), $flag ) );
				}
			}
		}

		// The command word (first non-flag token).
		$positional = array_values( array_filter( $tokens, static fn( $t ) => 0 !== strpos( $t, '-' ) ) );
		$cmd        = strtolower( $positional[0] ?? '' );
		$sub        = strtolower( $positional[1] ?? '' );

		if ( in_array( $cmd, self::blocked_commands(), true ) ) {
			/* translators: %s: the refused command */
			return new \WP_Error( 'wpcli_blocked', sprintf( __( 'The command "wp %s" is not allowed for security reasons.', 'karmcp' ), $cmd ) );
		}
		// Match both `cmd sub` and `cmd sub verb`: WP-CLI nests three deep in
		// places (`cron event run`), and comparing only the pair would let the
		// third level straight through.
		$third  = strtolower( $positional[2] ?? '' );
		$blocked = self::blocked_subcommands();
		foreach ( array( $cmd . ' ' . $sub, $cmd . ' ' . $sub . ' ' . $third ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' !== $sub && in_array( $candidate, $blocked, true ) ) {
				/* translators: %s: the refused command path */
				return new \WP_Error( 'wpcli_blocked', sprintf( __( 'The command "wp %s" is not allowed for security reasons.', 'karmcp' ), $candidate ) );
			}
		}

		$reason = self::check_arguments( $positional, $tokens );
		if ( null !== $reason ) {
			return new \WP_Error( 'wpcli_blocked', $reason );
		}

		return $tokens;
	}

	/**
	 * Pure: rules that depend on a command's ARGUMENTS, which a flat
	 * command/subcommand denylist cannot express.
	 *
	 * Each one closes a route to the same place — running code you chose, or
	 * becoming an administrator — that the command name alone looks innocent for.
	 *
	 * @param string[] $positional Non-flag tokens, in order.
	 * @param string[] $tokens     Every token, flags included.
	 * @return string|null Reason to refuse, or null to allow.
	 */
	public static function check_arguments( array $positional, array $tokens ): ?string {
		$cmd = strtolower( $positional[0] ?? '' );
		$sub = strtolower( $positional[1] ?? '' );

		// Installing from a URL or a local archive is arbitrary code execution:
		// the payload is whatever that zip contains. The dedicated install-plugin
		// tool is wordpress.org-slug-only for this reason; match it here.
		if ( in_array( $cmd, array( 'plugin', 'theme' ), true ) && in_array( $sub, array( 'install', 'update' ), true ) ) {
			foreach ( array_slice( $positional, 2 ) as $target ) {
				if ( false !== strpos( $target, '://' ) || preg_match( '/\.zip$/i', $target ) ) {
					return __( 'Installing from a URL or a .zip is not allowed — it runs whatever code the archive contains. Use a wordpress.org slug.', 'karmcp' );
				}
			}
		}

		// Minting or seizing an administrator. The dedicated user tools refuse
		// admin-grade roles and never set a password; do the same here.
		if ( 'user' === $cmd && in_array( $sub, array( 'create', 'update', 'add-role', 'set-role' ), true ) ) {
			foreach ( $tokens as $tok ) {
				$low = strtolower( $tok );
				if ( 0 === strpos( $low, '--user_pass=' ) ) {
					return __( 'Setting a user password over WP-CLI is not allowed.', 'karmcp' );
				}
				if ( preg_match( '/^--role=(administrator|super-admin)$/', $low ) ) {
					return __( 'Granting an administrator role over WP-CLI is not allowed.', 'karmcp' );
				}
			}
			foreach ( array_slice( $positional, 2 ) as $arg ) {
				if ( in_array( strtolower( $arg ), array( 'administrator', 'super-admin' ), true ) ) {
					return __( 'Granting an administrator role over WP-CLI is not allowed.', 'karmcp' );
				}
			}
		}

		// Options that decide what code loads or who can register.
		if ( 'option' === $cmd && in_array( $sub, array( 'update', 'set', 'add', 'delete', 'patch' ), true ) ) {
			$key = strtolower( $positional[2] ?? '' );
			if ( in_array( $key, self::protected_options(), true ) ) {
				/* translators: %s: the option name */
				return sprintf( __( 'The option "%s" cannot be changed over WP-CLI — it controls which code runs or who may register.', 'karmcp' ), $key );
			}
		}

		return null;
	}

	/** Options that may never be written over WP-CLI, filterable. @return string[] */
	public static function protected_options(): array {
		return array_map( 'strtolower', (array) apply_filters( 'karmcp_wpcli_protected_options', self::PROTECTED_OPTIONS ) );
	}

	/** Blocked commands, filterable. @return string[] */
	public static function blocked_commands(): array {
		return array_map( 'strtolower', (array) apply_filters( 'karmcp_wpcli_blocked_commands', self::BLOCKED_COMMANDS ) );
	}

	/** Blocked `command subcommand` pairs, filterable. @return string[] */
	public static function blocked_subcommands(): array {
		return array_map( 'strtolower', (array) apply_filters( 'karmcp_wpcli_blocked_subcommands', self::BLOCKED_SUBCOMMANDS ) );
	}

	/** Blocked flag prefixes, filterable. @return string[] */
	public static function blocked_flag_prefixes(): array {
		return (array) apply_filters( 'karmcp_wpcli_blocked_flags', self::BLOCKED_FLAG_PREFIXES );
	}

	/**
	 * Quote-aware tokenizer: splits on unquoted whitespace, honoring single
	 * quotes, double quotes, and backslash escapes.
	 *
	 * @param string $command Command string.
	 * @return array|\WP_Error Tokens, or WP_Error on an unterminated quote.
	 */
	public static function tokenize( string $command ) {
		$tokens  = array();
		$current = '';
		$has     = false;
		$quote   = '';
		$len     = strlen( $command );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $command[ $i ];

			if ( '' !== $quote ) {
				if ( $ch === $quote ) {
					$quote = '';
				} elseif ( '\\' === $ch && '"' === $quote && $i + 1 < $len && ( '"' === $command[ $i + 1 ] || '\\' === $command[ $i + 1 ] ) ) {
					// Inside double quotes only \" and \\ are escapes; a lone
					// backslash (e.g. a Windows path C:\wp\wp-cli.phar) is literal.
					$current .= $command[ ++$i ];
				} else {
					$current .= $ch;
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$quote = $ch;
				$has   = true;
				continue;
			}
			// Outside quotes a backslash is literal (Windows-path friendly). Use
			// quotes for arguments that contain spaces.
			if ( ' ' === $ch || "\t" === $ch ) {
				if ( $has ) {
					$tokens[] = $current;
					$current  = '';
					$has      = false;
				}
				continue;
			}
			$current .= $ch;
			$has      = true;
		}

		if ( '' !== $quote ) {
			return new \WP_Error( 'wpcli_unterminated_quote', __( 'The command has an unterminated quote.', 'karmcp' ) );
		}
		if ( $has ) {
			$tokens[] = $current;
		}
		return $tokens;
	}
}
