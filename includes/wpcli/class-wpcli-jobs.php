<?php
/**
 * WP-CLI background jobs — dispatch a command as a detached process and poll it.
 *
 * A job is a directory under `uploads/karmcp-wpcli-jobs/<id>/` holding:
 *   meta.json  — command + argv + status + timestamps
 *   run.sh|bat — the generated launcher (argv passed via escapeshellarg — no
 *                interpolation of untrusted values)
 *   stdout.log / stderr.log — captured streams
 *   exit_code  — written by the launcher when the command finishes
 *
 * The launcher is spawned **detached** (nohup … & on POSIX, start /B on Windows)
 * so a long migration/bulk task outlives the originating request. Background jobs
 * require the shell path (a configured `wp` base command + proc_open) — an
 * in-process command can't be detached.
 *
 * @package KarMCP
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-CLI background jobs.
 *
 * @since 3.4.0
 */
class KarMCP_WPCLI_Jobs {

	/** Keep at most this many job directories; older ones are pruned. */
	const KEEP = 50;

	/** Absolute path to the jobs base directory (created + web-protected on demand). */
	public function dir(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'karmcp-wpcli-jobs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Block web access to job logs.
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Dispatch a command as a background job.
	 *
	 * @param string $command Raw `wp` command.
	 * @param int    $timeout Advisory max seconds (informational; the launcher runs to completion).
	 * @return array|\WP_Error { job_id, status } or WP_Error.
	 */
	public function dispatch( string $command, int $timeout = 900 ) {
		$runner = new KarMCP_WPCLI_Runner();
		if ( ! $runner->shell_available() ) {
			return new \WP_Error( 'wpcli_bg_unavailable', __( 'Background jobs require a configured wp base command and PHP proc_open. Set it on KarMCP → Connection or via KARMCP_WPCLI_COMMAND.', 'karmcp' ) );
		}
		$tokens = KarMCP_WPCLI_Validator::validate( $command );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->prune();

		$id  = gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
		$dir = $this->dir() . '/' . $id;
		wp_mkdir_p( $dir );

		$argv = array_merge( $runner->base_argv(), $tokens, array( '--path=' . rtrim( ABSPATH, '/\\' ), '--no-color' ) );
		$meta = array(
			'id'         => $id,
			'command'    => $command,
			'timeout'    => max( 1, $timeout ),
			'status'     => 'queued',
			'created'    => time(),
			'started'    => null,
			'finished'   => null,
			'exit_code'  => null,
			'user'       => get_current_user_id(),
		);
		file_put_contents( $dir . '/meta.json', wp_json_encode( $meta ) ); // phpcs:ignore
		file_put_contents( $dir . '/stdout.log', '' ); // phpcs:ignore
		file_put_contents( $dir . '/stderr.log', '' ); // phpcs:ignore

		if ( ! $this->spawn( $argv, $dir ) ) {
			$meta['status'] = 'failed';
			$meta['finished'] = time();
			file_put_contents( $dir . '/meta.json', wp_json_encode( $meta ) ); // phpcs:ignore
			return new \WP_Error( 'wpcli_spawn_failed', __( 'Could not start the background job.', 'karmcp' ) );
		}
		$meta['status']  = 'running';
		$meta['started'] = time();
		file_put_contents( $dir . '/meta.json', wp_json_encode( $meta ) ); // phpcs:ignore

		return array( 'job_id' => $id, 'status' => 'running' );
	}

	/**
	 * Read a job's current state + captured output.
	 *
	 * @param string $id Job id.
	 * @return array|\WP_Error
	 */
	public function get( string $id ) {
		$id  = preg_replace( '/[^a-z0-9\-]/i', '', $id );
		$dir = $this->dir() . '/' . $id;
		if ( '' === $id || ! is_file( $dir . '/meta.json' ) ) {
			return new \WP_Error( 'wpcli_job_not_found', __( 'Job not found.', 'karmcp' ) );
		}
		$meta = json_decode( (string) file_get_contents( $dir . '/meta.json' ), true ); // phpcs:ignore
		$meta = is_array( $meta ) ? $meta : array();

		// Derive terminal state from the exit_code file the launcher writes.
		if ( is_file( $dir . '/exit_code' ) ) {
			$code               = (int) trim( (string) file_get_contents( $dir . '/exit_code' ) ); // phpcs:ignore
			$meta['status']     = 0 === $code ? 'completed' : 'failed';
			$meta['exit_code']  = $code;
			$meta['finished']   = $meta['finished'] ?: filemtime( $dir . '/exit_code' );
		}
		$meta['stdout'] = $this->tail( $dir . '/stdout.log' );
		$meta['stderr'] = $this->tail( $dir . '/stderr.log' );
		return $meta;
	}

	/** List recent jobs (newest first), metadata only. @return array */
	public function all(): array {
		$base = $this->dir();
		$out  = array();
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( ! is_file( $dir . '/meta.json' ) ) {
				continue;
			}
			$id   = basename( $dir );
			$meta = $this->get( $id );
			if ( is_array( $meta ) ) {
				unset( $meta['stdout'], $meta['stderr'] );
				$out[] = $meta;
			}
		}
		usort( $out, static fn( $a, $b ) => ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 ) );
		return $out;
	}

	/** Delete the oldest job dirs beyond KEEP. */
	private function prune(): void {
		$dirs = (array) glob( $this->dir() . '/*', GLOB_ONLYDIR );
		if ( count( $dirs ) < self::KEEP ) {
			return;
		}
		usort( $dirs, static fn( $a, $b ) => filemtime( $a ) <=> filemtime( $b ) );
		foreach ( array_slice( $dirs, 0, count( $dirs ) - self::KEEP + 1 ) as $old ) {
			array_map( 'unlink', (array) glob( $old . '/*' ) );
			@rmdir( $old ); // phpcs:ignore
		}
	}

	/**
	 * Spawn the command detached, writing streams + exit code to the job dir.
	 * A generated launcher script keeps argument escaping out of the spawn call.
	 *
	 * @param array  $argv Full argv (base command + validated tokens + --path).
	 * @param string $dir  Job directory.
	 * @return bool
	 */
	private function spawn( array $argv, string $dir ): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}
		$cmd = implode( ' ', array_map( 'escapeshellarg', $argv ) );
		$out = $dir . '/stdout.log';
		$err = $dir . '/stderr.log';
		$ec  = $dir . '/exit_code';
		$win = 0 === stripos( PHP_OS, 'WIN' );

		if ( $win ) {
			$bat = "@echo off\r\n" . $cmd . ' 1> "' . $out . '" 2> "' . $err . "\"\r\n" . 'echo %ERRORLEVEL% 1> "' . $ec . "\"\r\n";
			file_put_contents( $dir . '/run.bat', $bat ); // phpcs:ignore
			$launch = 'cmd /c start /B "" cmd /c ' . escapeshellarg( $dir . '/run.bat' );
			$p      = popen( $launch, 'r' );
			if ( false === $p ) {
				return false;
			}
			pclose( $p );
			return true;
		}

		$sh = "#!/bin/sh\n" . $cmd . ' 1> ' . escapeshellarg( $out ) . ' 2> ' . escapeshellarg( $err ) . "\n"
			. 'echo $? > ' . escapeshellarg( $ec ) . "\n";
		file_put_contents( $dir . '/run.sh', $sh ); // phpcs:ignore
		$launch = 'nohup sh ' . escapeshellarg( $dir . '/run.sh' ) . ' > /dev/null 2>&1 &';
		$p      = proc_open( array( 'sh', '-c', $launch ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, ABSPATH );
		if ( ! is_resource( $p ) ) {
			return false;
		}
		foreach ( (array) $pipes as $pp ) {
			if ( is_resource( $pp ) ) {
				fclose( $pp );
			}
		}
		proc_close( $p );
		return true;
	}

	/** Read the tail of a log file, capped. */
	private function tail( string $file ): string {
		if ( ! is_file( $file ) ) {
			return '';
		}
		$data = (string) file_get_contents( $file ); // phpcs:ignore
		if ( strlen( $data ) <= KarMCP_WPCLI_Runner::OUTPUT_CAP ) {
			return $data;
		}
		return "…[output truncated]\n" . substr( $data, -KarMCP_WPCLI_Runner::OUTPUT_CAP );
	}
}
