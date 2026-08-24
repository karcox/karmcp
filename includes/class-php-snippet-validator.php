<?php
/**
 * PHP Snippet Validator — static analysis for admin/AI-submitted PHP snippets.
 *
 * Two layers:
 *   1. PARSE — token_get_all( …, TOKEN_PARSE ) so the snippet must be
 *      syntactically valid PHP before it can be stored as runnable.
 *   2. SECURITY SCAN — a token walk that flags dangerous constructs.
 *
 * THREE LEVELS, AND WHY IT IS NOT TWO
 * -----------------------------------
 * Findings used to be either CRITICAL or WARNING, and the warning list held
 * things every correct snippet does: reading `$_POST`, calling `exit` after a
 * redirect, firing `do_action`, defining a callback function. A well-written
 * snippet therefore arrived covered in warnings — and a reviewer told that ten
 * routine things are warnings learns to skim, which is exactly how the one that
 * mattered gets waved through. Noise is not a cosmetic problem here; the human
 * approval step is the real safety boundary, so anything that trains the human
 * to stop reading attacks the boundary itself.
 *
 *   CRITICAL — blocks creation and activation outright.
 *   WARNING  — a real side effect a reviewer should confirm is intended
 *              (writes an option, sends mail, changes a constant).
 *   NOTE     — ordinary in working code. Reported for completeness, and never
 *              on its own a reason to look twice.
 *
 * verdict() turns the tally into one plain sentence, and the admin screens
 * follow THAT rather than colouring any finding as an error.
 *
 * IMPORTANT — this is a GUARDRAIL, not a guarantee. PHP is expressive enough to
 * hide intent (variable functions, decoded strings, reflection), so static
 * analysis cannot prove arbitrary code is safe. The real safety boundary is the
 * capability gate (manage_options + unfiltered_html) plus the human approval
 * step: an AI can create a DRAFT and run the validator, but only an admin can
 * activate a snippet so it actually executes.
 *
 * @package KarMCP
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates PHP snippet source.
 *
 * @since 2.1.0
 */
class KarMCP_PHP_Snippet_Validator {

	/**
	 * Function names that block a snippet outright (code exec, shell, dynamic
	 * calls, file writes/deletes, network egress, obfuscation decoders, runtime
	 * config). Maps lowercase function name => reviewer-facing reason.
	 *
	 * @var array<string,string>
	 */
	private static $critical_funcs = array(
		// Arbitrary code execution.
		'eval'                    => 'Executes arbitrary code (eval).',
		'assert'                  => 'Can execute arbitrary code (assert with a string).',
		'create_function'         => 'Creates and runs code from a string.',
		// Shell / process execution.
		'exec'                    => 'Runs an operating-system command.',
		'system'                  => 'Runs an operating-system command.',
		'shell_exec'              => 'Runs an operating-system command.',
		'passthru'                => 'Runs an operating-system command.',
		'proc_open'               => 'Spawns an operating-system process.',
		'popen'                   => 'Spawns an operating-system process.',
		'pcntl_exec'              => 'Executes a program in the current process.',
		'expect_popen'            => 'Spawns an operating-system process.',
		// Indirect / dynamic invocation (bypasses this very check).
		'call_user_func'          => 'Calls a function chosen at runtime (bypasses static checks).',
		'call_user_func_array'    => 'Calls a function chosen at runtime (bypasses static checks).',
		'forward_static_call'     => 'Calls a method chosen at runtime (bypasses static checks).',
		'forward_static_call_array' => 'Calls a method chosen at runtime (bypasses static checks).',
		'func_get_args'           => 'Unexpected in a snippet; often used to smuggle dynamic calls.',
		// File writes / deletes.
		'file_put_contents'       => 'Writes to the filesystem.',
		'fwrite'                  => 'Writes to the filesystem.',
		'fputs'                   => 'Writes to the filesystem.',
		'fputcsv'                 => 'Writes to the filesystem.',
		'ftruncate'               => 'Truncates a file.',
		'unlink'                  => 'Deletes a file.',
		'rmdir'                   => 'Removes a directory.',
		'rename'                  => 'Renames/moves a file.',
		'copy'                    => 'Copies a file.',
		'mkdir'                   => 'Creates a directory.',
		'chmod'                   => 'Changes file permissions.',
		'chown'                   => 'Changes file ownership.',
		'chgrp'                   => 'Changes file group.',
		'symlink'                 => 'Creates a symbolic link.',
		'link'                    => 'Creates a hard link.',
		'move_uploaded_file'      => 'Moves an uploaded file into the filesystem.',
		// Network egress.
		'curl_init'               => 'Makes an outbound network request.',
		'curl_exec'               => 'Makes an outbound network request.',
		'curl_setopt'             => 'Configures an outbound network request.',
		'fsockopen'               => 'Opens a network socket.',
		'pfsockopen'              => 'Opens a network socket.',
		'stream_socket_client'    => 'Opens a network socket.',
		'socket_create'           => 'Opens a network socket.',
		'socket_connect'          => 'Connects a network socket.',
		// Obfuscation decoders (the #1 malware signal; rarely needed in a snippet).
		'base64_decode'           => 'Decodes hidden data, a common malware obfuscation.',
		'gzinflate'               => 'Decompresses hidden data, a common malware obfuscation.',
		'gzuncompress'            => 'Decompresses hidden data, a common malware obfuscation.',
		'gzdecode'                => 'Decompresses hidden data, a common malware obfuscation.',
		'str_rot13'               => 'Decodes hidden data, a common malware obfuscation.',
		'convert_uudecode'        => 'Decodes hidden data, a common malware obfuscation.',
		'hex2bin'                 => 'Decodes hidden data, a common malware obfuscation.',
		// Runtime / environment manipulation.
		'dl'                      => 'Loads a PHP extension at runtime.',
		'putenv'                  => 'Changes environment variables.',
		'ini_set'                 => 'Changes PHP runtime configuration.',
		'ini_alter'               => 'Changes PHP runtime configuration.',
		'apache_setenv'           => 'Changes the web-server environment.',
		'virtual'                 => 'Performs an Apache sub-request.',
		'set_error_handler'       => 'Hijacks error handling.',
		'register_shutdown_function' => 'Schedules deferred code execution.',
		'register_tick_function'  => 'Schedules repeated code execution.',
		'extract'                 => 'Creates variables from arbitrary keys (variable injection).',
	);

	/**
	 * Function names that are flagged for the human reviewer but do not block.
	 *
	 * @var array<string,string>
	 */
	private static $warn_funcs = array(
		'fopen'             => 'Opens a file (could read or write).',
		'file_get_contents' => 'Reads a file or a remote URL.',
		'readfile'          => 'Reads and outputs a file.',
		'scandir'           => 'Lists a directory.',
		'glob'              => 'Lists files by pattern.',
		'opendir'           => 'Opens a directory.',
		'define'            => 'Defines a constant (could override core/config constants).',
		'header'            => 'Sends an HTTP header (could redirect).',
		'setcookie'         => 'Sets a cookie.',
		'error_reporting'   => 'Changes error reporting.',
		'update_option'     => 'Writes a site option.',
		'delete_option'     => 'Deletes a site option.',
		'add_option'        => 'Adds a site option.',
		'wp_mail'           => 'Sends email.',
		'wp_delete_post'    => 'Deletes a post.',
		'wp_delete_user'    => 'Deletes a user.',
		'wp_insert_user'    => 'Creates a user.',
		'wp_update_user'    => 'Updates a user (could escalate privileges).',
		'switch_theme'      => 'Switches the active theme.',
		'activate_plugin'   => 'Activates a plugin.',
		'deactivate_plugins' => 'Deactivates plugins.',
	);

	/**
	 * Functions that are ordinary in working code. Reported, never alarming.
	 *
	 * Every entry here used to be a WARNING, which is what made a good snippet
	 * arrive looking like a problem.
	 *
	 * @var array<string,string>
	 */
	private static $note_funcs = array(
		'fread'     => 'Reads from a file handle.',
		'fgets'     => 'Reads from a file handle.',
		'do_action' => 'Fires a hook.',
	);

	/**
	 * Functions whose first argument is a callback.
	 *
	 * A callback given as a STRING can name any function, so `array_map('system',
	 * $x)` executes a shell command without the scan above ever seeing a call to
	 * `system`. That is a real bypass, and it used to be handled by warning on
	 * every one of these — which flags `array_map('trim', $x)` just as loudly and
	 * buys nothing but noise.
	 *
	 * The callback argument is inspected instead: a string literal naming a
	 * critical function is CRITICAL (it is that call, merely spelled differently),
	 * and everything else is a note.
	 *
	 * @var string[]
	 */
	private static $callback_funcs = array(
		'array_map',
		'array_filter',
		'array_walk',
		'array_walk_recursive',
		'array_reduce',
		'usort',
		'uasort',
		'uksort',
		'ob_start',
		'preg_replace_callback',
		'preg_replace_callback_array',
		'set_exception_handler',
		'iterator_apply',
	);

	/**
	 * Superglobals whose use is worth noting (request-derived input).
	 *
	 * @var string[]
	 */
	private static $warn_superglobals = array( '$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER', '$_ENV', '$GLOBALS' );

	/**
	 * Validates a PHP snippet.
	 *
	 * @since 2.1.0
	 *
	 * @param string $code Raw snippet source (with or without PHP tags).
	 * @return array{valid:bool,safe:bool,parse_error:string,verdict:string,counts:array{critical:int,warning:int,note:int},findings:array<int,array{severity:string,rule:string,message:string,line:int}>}
	 */
	public static function validate( string $code ): array {
		$result = array(
			'valid'       => true,
			'safe'        => true,
			'parse_error' => '',
			'verdict'     => '',
			'counts'      => array(
				'critical' => 0,
				'warning'  => 0,
				'note'     => 0,
			),
			'findings'    => array(),
		);

		$clean = self::strip_tags( $code );

		if ( '' === trim( $clean ) ) {
			$result['valid']       = false;
			$result['parse_error'] = __( 'The snippet is empty.', 'karmcp' );
			$result['verdict']     = self::verdict( $result );
			return $result;
		}

		// Reject an embedded closing tag: it would let code break out of the
		// wrapper into raw HTML/inline output we can't reason about.
		if ( false !== strpos( $clean, '?>' ) ) {
			$result['safe'] = false;
			$result['findings'][] = self::finding( 'critical', 'close_tag', __( 'A PHP closing tag ( ?> ) is not allowed in a snippet.', 'karmcp' ), 0 );
		}

		// Wrap so top-level statements (return, etc.) are valid in a function
		// context — this is exactly how the snippet will be executed.
		$wrapped = '<?php function __karmcp_snippet_validate() { ' . $clean . "\n}";

		$tokens = null;
		try {
			$tokens = token_get_all( $wrapped, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			$result['valid']       = false;
			$result['parse_error'] = $e->getMessage();
			$result['verdict']     = self::verdict( $result );
			return $result;
		} catch ( \Throwable $e ) {
			$result['valid']       = false;
			$result['parse_error'] = $e->getMessage();
			$result['verdict']     = self::verdict( $result );
			return $result;
		}

		self::scan_tokens( $tokens, $result );

		foreach ( $result['findings'] as $f ) {
			$severity = $f['severity'];
			if ( isset( $result['counts'][ $severity ] ) ) {
				++$result['counts'][ $severity ];
			}
		}

		// `safe` is false if any CRITICAL finding exists. Warnings and notes never
		// block: they are for the human, who is the actual approval step.
		$result['safe']    = 0 === $result['counts']['critical'];
		$result['verdict'] = self::verdict( $result );

		return $result;
	}

	/**
	 * One plain sentence a reviewer can act on.
	 *
	 * The admin screens follow this rather than colouring any finding as an
	 * error, which is what made a snippet carrying a single routine note show up
	 * in a red box.
	 *
	 * @since 1.35.0
	 * @param array $result Result so far (counts already tallied).
	 * @return string
	 */
	public static function verdict( array $result ): string {
		if ( empty( $result['valid'] ) ) {
			return __( 'Does not parse, so it cannot be stored as runnable.', 'karmcp' );
		}

		$counts = isset( $result['counts'] ) && is_array( $result['counts'] ) ? $result['counts'] : array();
		$blocks = (int) ( $counts['critical'] ?? 0 );
		$warns  = (int) ( $counts['warning'] ?? 0 );
		$notes  = (int) ( $counts['note'] ?? 0 );

		$parts = array();
		if ( $blocks > 0 ) {
			/* translators: %d: number of blocking findings. */
			$parts[] = sprintf( _n( '%d blocking finding', '%d blocking findings', $blocks, 'karmcp' ), $blocks );
		}
		if ( $warns > 0 ) {
			/* translators: %d: number of warnings. */
			$parts[] = sprintf( _n( '%d warning', '%d warnings', $warns, 'karmcp' ), $warns );
		}
		if ( $notes > 0 ) {
			/* translators: %d: number of notes. */
			$parts[] = sprintf( _n( '%d note', '%d notes', $notes, 'karmcp' ), $notes );
		}
		$tally = implode( ', ', $parts );

		if ( $blocks > 0 ) {
			/* translators: %s: tally such as "2 blocking findings, 1 note". */
			return sprintf( __( 'Cannot be activated: %s.', 'karmcp' ), $tally );
		}
		if ( $warns > 0 ) {
			/* translators: %s: tally such as "1 warning, 3 notes". */
			return sprintf( __( 'Safe to activate, but read the warnings first: %s.', 'karmcp' ), $tally );
		}
		if ( $notes > 0 ) {
			/* translators: %s: tally such as "3 notes". */
			return sprintf( __( 'Safe to activate. %s, all ordinary in working code.', 'karmcp' ), $tally );
		}
		return __( 'Safe to activate. Nothing flagged.', 'karmcp' );
	}

	/**
	 * Strips a single leading PHP open tag (and a trailing close tag) so callers
	 * may submit code with or without tags.
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function strip_tags( string $code ): string {
		$code = trim( $code );
		// Leading <?php or <?=  or <?
		$code = preg_replace( '/^<\?php\b/i', '', $code, 1 );
		if ( null === $code ) {
			return '';
		}
		$code = preg_replace( '/^<\?=?/', '', $code, 1 );
		return null === $code ? '' : trim( $code );
	}

	/**
	 * Walks the token stream and records findings.
	 *
	 * @param array $tokens token_get_all() output.
	 * @param array $result Result array (by reference).
	 */
	private static function scan_tokens( array $tokens, array &$result ): void {
		// Build a list of significant tokens (drop whitespace/comments) with the
		// original line preserved, so we can look at neighbours cheaply.
		$sig = array();
		foreach ( $tokens as $tok ) {
			if ( is_array( $tok ) ) {
				if ( T_WHITESPACE === $tok[0] || T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0] ) {
					continue;
				}
				$sig[] = array( 'id' => $tok[0], 'text' => $tok[1], 'line' => (int) $tok[2] );
			} else {
				$sig[] = array( 'id' => null, 'text' => $tok, 'line' => 0 );
			}
		}

		$count = count( $sig );
		for ( $i = 0; $i < $count; $i++ ) {
			$t    = $sig[ $i ];
			$id   = $t['id'];
			$text = $t['text'];
			$line = $t['line'];
			$prev = $i > 0 ? $sig[ $i - 1 ] : null;
			$next = $i + 1 < $count ? $sig[ $i + 1 ] : null;

			// Backtick shell execution: `...`
			if ( null === $id && '`' === $text ) {
				$result['findings'][] = self::finding( 'critical', 'backtick', __( 'Shell execution via the backtick operator.', 'karmcp' ), $line );
				continue;
			}

			// Dynamic include/require.
			if ( in_array( $id, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$result['findings'][] = self::finding( 'critical', 'include', __( 'Loads and runs another PHP file (include/require).', 'karmcp' ), $line );
				continue;
			}

			// eval as a dedicated language construct (T_EVAL) where the engine emits it.
			if ( defined( 'T_EVAL' ) && T_EVAL === $id ) {
				$result['findings'][] = self::finding( 'critical', 'eval', __( 'Executes arbitrary code (eval).', 'karmcp' ), $line );
				continue;
			}

			// Variable function call: $var( …  or  $var->( …  treated as dynamic call.
			if ( T_VARIABLE === $id && $next && null === $next['id'] && '(' === $next['text'] ) {
				$result['findings'][] = self::finding( 'critical', 'variable_function', __( 'Calls a function named by a variable (bypasses static checks).', 'karmcp' ), $line );
				continue;
			}

			// Dynamic class instantiation: `new $var` — class chosen at runtime.
			if ( T_NEW === $id && $next && T_VARIABLE === $next['id'] ) {
				$result['findings'][] = self::finding( 'critical', 'dynamic_instantiation', __( 'Instantiates a class named by a variable (bypasses static checks).', 'karmcp' ), $line );
				continue;
			}

			// Reflection / closure factories that can invoke arbitrary code.
			if ( T_NEW === $id && $next && T_STRING === $next['id']
				&& in_array( strtolower( ltrim( $next['text'], '\\' ) ), array( 'reflectionfunction', 'reflectionmethod', 'reflectionclass', 'reflectionobject', 'closure' ), true ) ) {
				$result['findings'][] = self::finding( 'critical', 'reflection', sprintf(
					/* translators: %s: class name */
					__( 'Uses %s, which can invoke functions/methods chosen at runtime.', 'karmcp' ),
					$next['text']
				), $line );
				continue;
			}

			// die / exit. `wp_redirect(); exit;` is the canonical correct pattern, so
			// this is a note: alarming it trains the reviewer to skim.
			if ( defined( 'T_EXIT' ) && T_EXIT === $id ) {
				$result['findings'][] = self::finding( 'note', 'exit', __( 'Stops the request (die/exit) — normal after a redirect.', 'karmcp' ), $line );
				continue;
			}

			// Variable variable: $ immediately before a $var, or T_VARIABLE '${'.
			if ( null === $id && '$' === $text && $next && T_VARIABLE === $next['id'] ) {
				$result['findings'][] = self::finding( 'warning', 'variable_variable', __( 'Uses a variable variable ($$x).', 'karmcp' ), $line );
				continue;
			}

			// @ error suppression.
			if ( null === $id && '@' === $text ) {
				$result['findings'][] = self::finding( 'warning', 'suppress', __( 'Suppresses errors with @ (can hide failures).', 'karmcp' ), $line );
				continue;
			}

			// Superglobals. Reading request input is what a snippet handling a form
			// or a query var is FOR, so this is a note. It was the single biggest
			// source of warnings on snippets that had nothing wrong with them.
			if ( T_VARIABLE === $id && in_array( $text, self::$warn_superglobals, true ) ) {
				$result['findings'][] = self::finding(
					'note',
					'superglobal',
					sprintf(
						/* translators: %s: superglobal name */
						__( 'Reads request/server input (%s).', 'karmcp' ),
						$text
					),
					$line
				);
				continue;
			}

			// Destructive SQL inside a string literal.
			if ( in_array( $id, array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				if ( preg_match( '/\b(DROP|TRUNCATE|ALTER)\s+(TABLE|DATABASE)\b/i', $text ) || preg_match( '/\bDELETE\s+FROM\b/i', $text ) ) {
					$result['findings'][] = self::finding( 'critical', 'destructive_sql', __( 'Contains destructive SQL (DROP/TRUNCATE/ALTER/DELETE).', 'karmcp' ), $line );
				}
				continue;
			}

			// Function-name calls: a T_STRING followed by '(' that is not a method
			// (->name), static call (::name), or a definition (function name / new).
			if ( T_STRING === $id && $next && null === $next['id'] && '(' === $next['text'] ) {
				$prev_id   = $prev ? $prev['id'] : null;
				$prev_text = $prev ? $prev['text'] : '';
				$is_member = ( T_OBJECT_OPERATOR === $prev_id ) || ( T_DOUBLE_COLON === $prev_id )
					|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev_id );
				$is_def    = ( T_FUNCTION === $prev_id ) || ( T_NEW === $prev_id );
				if ( $is_member || $is_def ) {
					continue;
				}
				$name = strtolower( $text );
				if ( isset( self::$critical_funcs[ $name ] ) ) {
					$result['findings'][] = self::finding( 'critical', 'function:' . $name, self::$critical_funcs[ $name ], $line );
				} elseif ( in_array( $name, self::$callback_funcs, true ) ) {
					$result['findings'][] = self::callback_finding( $name, $sig, $i, $line );
				} elseif ( isset( self::$warn_funcs[ $name ] ) ) {
					$result['findings'][] = self::finding( 'warning', 'function:' . $name, self::$warn_funcs[ $name ], $line );
				} elseif ( isset( self::$note_funcs[ $name ] ) ) {
					$result['findings'][] = self::finding( 'note', 'function:' . $name, self::$note_funcs[ $name ], $line );
				}
				continue;
			}

			// NAMED function/class definitions inside a snippet (redeclaration risk).
			if ( in_array( $id, array( T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE ), true ) ) {
				// Skip the wrapper's own function token (line 1, name __karmcp_snippet_validate).
				if ( $next && T_STRING === $next['id'] && '__karmcp_snippet_validate' === $next['text'] ) {
					continue;
				}
				// A closure or an anonymous class declares no name, so it cannot be
				// redeclared and there is nothing to report. `add_action( 'x',
				// function () {} )` is the most ordinary line in a snippet, and it
				// was being flagged on every one of them.
				if ( ! $next || T_STRING !== $next['id'] ) {
					continue;
				}
				$result['findings'][] = self::finding( 'note', 'definition', __( 'Defines a function or class — would fatal only if the snippet ran twice in one request.', 'karmcp' ), $line );
				continue;
			}
		}
	}

	/**
	 * Judge one call to a callback-taking function by its callback argument.
	 *
	 * A string literal naming a critical function IS that call, written so the
	 * direct-call scan does not see it — `array_map('system', $cmds)` runs a shell
	 * command. Anything else (a closure, a first-class callable, a variable, an
	 * ordinary name like 'trim') is a note: warning on all of them flags routine
	 * code as loudly as the bypass and teaches the reviewer to skim past both.
	 *
	 * A callback held in a VARIABLE is not resolvable here, but it does not need
	 * to be: building one is a variable function call or a dynamic invocation,
	 * both already critical in their own right.
	 *
	 * @since 1.35.0
	 * @param string  $name Lowercase function name.
	 * @param array[] $sig  Significant tokens.
	 * @param int     $i    Index of the function-name token.
	 * @param int     $line Line number.
	 * @return array Finding row.
	 */
	private static function callback_finding( string $name, array $sig, int $i, int $line ): array {
		// The token after the '(' is the first argument.
		$arg = $sig[ $i + 2 ] ?? null;
		if ( $arg && T_CONSTANT_ENCAPSED_STRING === $arg['id'] ) {
			$callee = strtolower( trim( $arg['text'], "'\"" ) );
			if ( isset( self::$critical_funcs[ $callee ] ) ) {
				return self::finding(
					'critical',
					'callback:' . $callee,
					sprintf(
						/* translators: 1: the calling function, 2: the callback named as a string. */
						__( 'Passes %2$s to %1$s as a string callback, which runs it without naming it directly.', 'karmcp' ),
						$name,
						$callee
					),
					$line
				);
			}
		}
		return self::finding(
			'note',
			'function:' . $name,
			sprintf(
				/* translators: %s: function name. */
				__( 'Runs a callback (%s).', 'karmcp' ),
				$name
			),
			$line
		);
	}

	/**
	 * Builds a finding row.
	 *
	 * @param string $severity 'critical' | 'warning' | 'note'.
	 * @param string $rule     Machine rule id.
	 * @param string $message  Human message.
	 * @param int    $line     1-based line in the snippet (0 = whole snippet).
	 * @return array
	 */
	private static function finding( string $severity, string $rule, string $message, int $line ): array {
		return array(
			'severity' => $severity,
			'rule'     => $rule,
			'message'  => $message,
			'line'     => max( 0, $line ),
		);
	}
}
