<?php
/**
 * WP-CLI tools — run wp-cli commands over MCP (disabled-by-default).
 *
 * `run-wp-cli` runs a command synchronously; `dispatch-wp-cli` runs it as a
 * detached background job for long migrations / bulk tasks, with `get-wp-cli-job`
 * + `list-wp-cli-jobs` to poll. Every command passes the blocklist validator and
 * is executed with argv arrays (no shell interpolation). All four tools require
 * `manage_options` and ship disabled-by-default; runs are recorded to the change
 * ledger.
 *
 * > **Risk notice.** WP-CLI is powerful and, via the shell path, is effectively
 * > command execution. The tool is confined by a command blocklist (no eval,
 * > eval-file, shell, raw db query, config writes, package install, or arbitrary
 * > PHP flags), ships disabled-by-default, is admin-gated, and audit-logs runs.
 *
 * @package KarMCP
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WP-CLI abilities.
 *
 * @since 3.4.0
 */
class KarMCP_WPCLI_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** The four WP-CLI tool slugs (for the disabled-by-default defaults step). */
	public static function slugs(): array {
		return array(
			'karmcp/run-wp-cli',
			'karmcp/dispatch-wp-cli',
			'karmcp/get-wp-cli-job',
			'karmcp/list-wp-cli-jobs',
		);
	}

	public function register(): void {
		$this->ability(
			'karmcp/run-wp-cli',
			__( 'Run WP-CLI Command', 'karmcp' ),
			__( 'Run a wp-cli command and return stdout, stderr, and the exit code. Runs in-process over the WP-CLI stdio transport, or via a configured wp binary over HTTP. Dangerous commands (eval, shell, raw db query, config writes, package install) are refused. Disabled by default; manage_options.', 'karmcp' ),
			'execute_run',
			array(
				'command' => array( 'type' => 'string', 'description' => 'The wp-cli command, without the leading "wp" (e.g. "plugin list --format=json").' ),
				'timeout' => array( 'type' => 'integer', 'description' => 'Max seconds (shell path). Default 60, max 300.' ),
			),
			array( 'command' ),
			false
		);
		$this->ability(
			'karmcp/dispatch-wp-cli',
			__( 'Dispatch WP-CLI Job', 'karmcp' ),
			__( 'Run a wp-cli command as a detached background job (for long migrations / bulk tasks) and return a job id. Requires a configured wp base command. Poll it with get-wp-cli-job. Disabled by default; manage_options.', 'karmcp' ),
			'execute_dispatch',
			array(
				'command' => array( 'type' => 'string', 'description' => 'The wp-cli command, without the leading "wp".' ),
				'timeout' => array( 'type' => 'integer', 'description' => 'Advisory max seconds. Default 900.' ),
			),
			array( 'command' ),
			false
		);
		$this->ability(
			'karmcp/get-wp-cli-job',
			__( 'Get WP-CLI Job', 'karmcp' ),
			__( 'Return a background job\'s status (running/completed/failed), exit code, and captured stdout/stderr. Read-only; manage_options.', 'karmcp' ),
			'execute_get_job',
			array( 'job_id' => array( 'type' => 'string' ) ),
			array( 'job_id' ),
			true
		);
		$this->ability(
			'karmcp/list-wp-cli-jobs',
			__( 'List WP-CLI Jobs', 'karmcp' ),
			__( 'List recent WP-CLI background jobs with their status and timestamps. Read-only; manage_options.', 'karmcp' ),
			'execute_list_jobs',
			array(),
			array(),
			true
		);
	}

	/** All WP-CLI tools require manage_options. */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function ability( string $name, string $label, string $description, string $exec, array $props, array $required, bool $readonly ): void {
		$this->ability_names[] = $name;
		karmcp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, $exec ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => $props, 'required' => $required ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly ), 'show_in_rest' => true ),
			)
		);
	}

	public function execute_run( $input ) {
		$command = trim( (string) ( $input['command'] ?? '' ) );
		$timeout = min( 300, max( 1, (int) ( $input['timeout'] ?? 60 ) ) );
		$result  = ( new KarMCP_WPCLI_Runner() )->run( $command, $timeout );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->log( 'run', $command, $result['exit_code'] );
		$result['command'] = $command;
		return $result;
	}

	public function execute_dispatch( $input ) {
		$command = trim( (string) ( $input['command'] ?? '' ) );
		$timeout = min( 86400, max( 1, (int) ( $input['timeout'] ?? 900 ) ) );
		$result  = ( new KarMCP_WPCLI_Jobs() )->dispatch( $command, $timeout );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->log( 'dispatch', $command, null );
		return $result;
	}

	public function execute_get_job( $input ) {
		return ( new KarMCP_WPCLI_Jobs() )->get( (string) ( $input['job_id'] ?? '' ) );
	}

	public function execute_list_jobs( $input ) {
		return array( 'jobs' => ( new KarMCP_WPCLI_Jobs() )->all() );
	}

	/** Record a run to the change ledger (not reversible — commands have no before-image). */
	private function log( string $action, string $command, $exit_code ): void {
		if ( ! class_exists( 'KarMCP_Change_Log' ) ) {
			return;
		}
		KarMCP_Change_Log::record(
			array(
				'domain'  => 'wpcli',
				'action'  => $action,
				'target'  => 'wp ' . $command,
				'summary' => 'run' === $action
					? 'Ran: wp ' . $command . ( null !== $exit_code ? ' (exit ' . (int) $exit_code . ')' : '' )
					: 'Dispatched background job: wp ' . $command,
			)
		);
	}
}
