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

	/** Whole commands that are never allowed (arbitrary code / shell / servers). */
	const BLOCKED_COMMANDS = array( 'eval', 'eval-file', 'shell', 'server' );

	/** `command subcommand` pairs that are never allowed. */
	const BLOCKED_SUBCOMMANDS = array(
		'db query', 'db cli', 'db import', 'db export', 'db reset', 'db drop', 'db clean',
		'config set', 'config delete', 'config edit',
		'package install', 'package update', 'package uninstall',
		'cli update', 'cli cmd-dump', 'cli info',
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
		if ( '' !== $sub && in_array( $cmd . ' ' . $sub, self::blocked_subcommands(), true ) ) {
			/* translators: %s: the refused command + subcommand */
			return new \WP_Error( 'wpcli_blocked', sprintf( __( 'The command "wp %s" is not allowed for security reasons.', 'karmcp' ), $cmd . ' ' . $sub ) );
		}

		return $tokens;
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
