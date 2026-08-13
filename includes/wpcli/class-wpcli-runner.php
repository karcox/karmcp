<?php
/**
 * WP-CLI runner — executes a validated command and captures output.
 *
 * Two execution modes:
 *  - **in-process** (`WP_CLI::runcommand`, `launch => false`): used when the MCP
 *    server is reached over the WP-CLI stdio transport. No shell, no new process.
 *  - **shell** (`proc_open` with an argv *array*, no shell interpolation): used
 *    over HTTP/proxy, only when an admin has configured a `wp` base command.
 *
 * @package KarMCP
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs WP-CLI commands.
 *
 * @since 3.4.0
 */
class KarMCP_WPCLI_Runner {

	/** Cap captured output per stream (bytes) so a chatty command can't blow up the response. */
	const OUTPUT_CAP = 262144; // 256 KB

	/** True when this request is a WP-CLI process (in-process execution is possible). */
	public function is_cli_context(): bool {
		return defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' );
	}

	/** The configured `wp` base command (constant > option > filter). Empty when unset. */
	public function base_command(): string {
		$cmd = '';
		if ( defined( 'KARMCP_WPCLI_COMMAND' ) && KARMCP_WPCLI_COMMAND ) {
			$cmd = (string) KARMCP_WPCLI_COMMAND;
		} else {
			$cmd = trim( (string) get_option( 'karmcp_wpcli_command', '' ) );
		}
		return (string) apply_filters( 'karmcp_wpcli_command', $cmd );
	}

	/** Whether the shell path (proc_open + configured binary) is usable. */
	public function shell_available(): bool {
		return function_exists( 'proc_open' ) && '' !== $this->base_command() && ! $this->exec_disabled();
	}

	/** Whether the command can run at all in this context (in-process OR shell). */
	public function available(): bool {
		return $this->is_cli_context() || $this->shell_available();
	}

	/** The base command tokenized into an argv array (e.g. "php wp-cli.phar" → [php, wp-cli.phar]). */
	public function base_argv(): array {
		$tokens = KarMCP_WPCLI_Validator::tokenize( $this->base_command() );
		return is_wp_error( $tokens ) ? array() : $tokens;
	}

	/**
	 * Validate + run a command, capturing stdout/stderr/exit code.
	 *
	 * @param string $command Raw `wp` command (without `wp`).
	 * @param int    $timeout Wall-clock seconds for the shell path.
	 * @return array|\WP_Error { stdout, stderr, exit_code, mode, timed_out }.
	 */
	public function run( string $command, int $timeout = 60 ) {
		$tokens = KarMCP_WPCLI_Validator::validate( $command );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( $this->is_cli_context() ) {
			return $this->run_in_process( preg_replace( '/^wp\s+/i', '', trim( $command ) ) );
		}
		if ( $this->shell_available() ) {
			return $this->run_shell( $tokens, max( 1, $timeout ) );
		}
		return new \WP_Error(
			'wpcli_unavailable',
			__( 'WP-CLI can only run in-process over the WP-CLI stdio transport. To enable it over HTTP, set a wp base command (KarMCP → Connection, or the KARMCP_WPCLI_COMMAND constant) and ensure PHP proc_open is available.', 'karmcp' )
		);
	}

	/** Run in the current WP-CLI process (no shell). */
	private function run_in_process( string $command ) {
		$result = \WP_CLI::runcommand(
			$command,
			array( 'return' => 'all', 'exit_error' => false, 'launch' => false, 'parse' => false )
		);
		return array(
			'stdout'    => $this->cap( (string) ( $result->stdout ?? '' ) ),
			'stderr'    => $this->cap( (string) ( $result->stderr ?? '' ) ),
			'exit_code' => (int) ( $result->return_code ?? 0 ),
			'mode'      => 'in-process',
			'timed_out' => false,
		);
	}

	/** Run the wp binary via proc_open with an argv array (no shell interpolation). */
	private function run_shell( array $tokens, int $timeout ) {
		$argv = array_merge( $this->base_argv(), $tokens, array( '--path=' . rtrim( ABSPATH, '/\\' ), '--no-color' ) );
		$desc = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		// PHP 7.4+: an array command is executed WITHOUT a shell — arguments are
		// passed verbatim, so no metacharacter can be interpreted.
		$proc = proc_open( $argv, $desc, $pipes, ABSPATH );
		if ( ! is_resource( $proc ) ) {
			return new \WP_Error( 'wpcli_spawn_failed', __( 'Could not start the wp process.', 'karmcp' ) );
		}
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout   = '';
		$stderr   = '';
		$deadline = microtime( true ) + $timeout;
		$timedout = false;

		do {
			$stdout .= (string) stream_get_contents( $pipes[1] );
			$stderr .= (string) stream_get_contents( $pipes[2] );
			$status  = proc_get_status( $proc );
			if ( ! $status['running'] ) {
				break;
			}
			if ( microtime( true ) >= $deadline ) {
				proc_terminate( $proc, 9 );
				$timedout = true;
				break;
			}
			usleep( 50000 );
		} while ( true );

		// Drain anything left.
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $proc );

		return array(
			'stdout'    => $this->cap( $stdout ),
			'stderr'    => $this->cap( $stderr ),
			'exit_code' => $timedout ? 124 : (int) $exit,
			'mode'      => 'shell',
			'timed_out' => $timedout,
		);
	}

	/** True when PHP exec-family is disabled for proc_open. */
	private function exec_disabled(): bool {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return in_array( 'proc_open', $disabled, true );
	}

	private function cap( string $s ): string {
		if ( strlen( $s ) <= self::OUTPUT_CAP ) {
			return $s;
		}
		return substr( $s, 0, self::OUTPUT_CAP ) . "\n…[output truncated]";
	}
}
