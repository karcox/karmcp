<?php
/**
 * Login Guard MCP tools.
 *
 * Two: see who is locked out, and let them back in. The Security tab is where
 * these will eventually live for a human, but the guard has to be operable
 * before that screen exists — and a lockout with no visible escape hatch is how
 * an administrator ends up disabling the whole module.
 *
 * @package KarMCP
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Login Guard abilities.
 *
 * @since 1.4.0
 */
class KarMCP_Login_Guard_Abilities {

	/**
	 * @since 1.4.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-login-lockouts',
			'karmcp/clear-login-lockout',
		);
	}

	/**
	 * @since 1.4.0
	 * @return void
	 */
	public function register(): void {
		$this->register_list();
		$this->register_clear();
	}

	/**
	 * Both tools read and write who may sign in, which is site administration.
	 *
	 * @since 1.4.0
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function register_list(): void {
		karmcp_register_ability(
			'karmcp/list-login-lockouts',
			array(
				'label'               => __( 'List Login Lockouts', 'karmcp' ),
				'description'         => __( 'Lists the sign-in lockouts currently in force, newest expiry first: whether each is on an IP address or a username, the attempted username, when it started and when it lifts. Read-only. Requires the Login Guard module.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => __( 'Maximum lockouts to return (1-500). Default: 100.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'    => array( 'type' => 'integer' ),
						'lockouts' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.4.0
	 * @param array $input Tool input.
	 * @return array
	 */
	public function execute_list( $input ) {
		$now   = time();
		$limit = absint( $input['limit'] ?? 100 );
		$rows  = KarMCP_Login_Guard_Store::active_locks( $now, $limit > 0 ? $limit : 100 );

		$out = array();
		foreach ( $rows as $r ) {
			$until = (int) $r['expires_at'];
			$out[] = array(
				'subject_type'  => (string) $r['subject_type'],
				'subject'       => (string) $r['subject'],
				'username'      => (string) $r['username'],
				'locked_at'     => (int) $r['created_at'],
				'expires_at'    => $until,
				'seconds_left'  => max( 0, $until - $now ),
			);
		}

		return array(
			'count'    => count( $out ),
			'lockouts' => $out,
		);
	}

	private function register_clear(): void {
		karmcp_register_ability(
			'karmcp/clear-login-lockout',
			array(
				'label'               => __( 'Clear Login Lockout', 'karmcp' ),
				'description'         => __( 'Lifts a sign-in lockout for one IP address or username, and forgets its escalation history so the next lock starts from the base delay instead of a doubled one. Use it when a legitimate user locked themselves out. Requires the Login Guard module.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_clear' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'subject_type' => array(
							'type'        => 'string',
							'enum'        => array( 'ip', 'user' ),
							'description' => __( 'Whether the lockout is on an address or a username.', 'karmcp' ),
						),
						'subject'      => array(
							'type'        => 'string',
							'description' => __( 'The address or username to unblock, exactly as list-login-lockouts reports it.', 'karmcp' ),
						),
					),
					'required'   => array( 'subject_type', 'subject' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'cleared'      => array( 'type' => 'boolean' ),
						'rows_removed' => array( 'type' => 'integer' ),
						'subject'      => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @since 1.4.0
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute_clear( $input ) {
		$type = (string) ( $input['subject_type'] ?? '' );
		if ( ! in_array( $type, array( 'ip', 'user' ), true ) ) {
			return new \WP_Error( 'invalid_subject_type', __( 'The subject_type must be "ip" or "user".', 'karmcp' ) );
		}

		$subject = trim( (string) ( $input['subject'] ?? '' ) );
		if ( '' === $subject ) {
			return new \WP_Error( 'missing_params', __( 'A "subject" is required — the address or username to unblock.', 'karmcp' ) );
		}
		if ( 'ip' === $type ) {
			$clean = KarMCP_Login_Guard_Policy::clean_ip( $subject );
			if ( '' === $clean ) {
				return new \WP_Error(
					'invalid_ip',
					sprintf(
						/* translators: %s: the value supplied. */
						__( '"%s" is not a valid IP address. Pass it exactly as list-login-lockouts reports it.', 'karmcp' ),
						$subject
					)
				);
			}
			$subject = $clean;
		}

		$removed = KarMCP_Login_Guard_Store::clear( $type, $subject );

		return array(
			'cleared'      => true,
			'rows_removed' => $removed,
			'subject'      => $subject,
		);
	}
}
