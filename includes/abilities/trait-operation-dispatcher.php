<?php
/**
 * The read/write dispatcher shared by plugin integrations.
 *
 * Integrations expose two MCP tools — `<id>-read` and `<id>-write` — each taking
 * `{ operation, arguments }`, so supporting a plugin with forty operations still
 * costs two entries in the tool list. This trait owns the part of that pattern
 * that has nothing to do with any particular plugin: resolving an operation,
 * re-checking its capability, gating the destructive ones behind `confirm`, and
 * answering with a catalog when no operation is named.
 *
 * The forms base class predates this and keeps its own copy; new integrations
 * use the trait.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operation dispatch for a two-tool integration.
 *
 * The user of this trait must provide `operations()` returning a map of
 * name => { mode, run, perm, desc, confirm? } and `integration_is_active()`.
 *
 * @since 1.1.0
 */
trait KarMCP_Operation_Dispatcher {

	/**
	 * The operation map.
	 *
	 * @return array<string,array>
	 */
	abstract protected function operations(): array;

	/**
	 * Whether the underlying plugin is active.
	 *
	 * @return bool
	 */
	abstract protected function integration_is_active(): bool;

	/**
	 * Message returned when the plugin is not active.
	 *
	 * @return string
	 */
	abstract protected function inactive_message(): string;

	/**
	 * Resolve + run an operation, or return the catalog when none is given.
	 *
	 * Every operation re-checks its own capability, so a tool the admin left
	 * enabled can never grant more than the user already had.
	 *
	 * @param string $mode  read|write.
	 * @param mixed  $input Tool input.
	 * @return mixed
	 */
	protected function dispatch_operation( string $mode, $input ) {
		$input     = is_array( $input ) ? $input : array();
		$operation = isset( $input['operation'] ) ? str_replace( '_', '-', sanitize_key( (string) $input['operation'] ) ) : '';
		$ops       = $this->operations();

		if ( '' === $operation ) {
			return $this->operation_catalog( $mode, $ops );
		}

		if ( ! $this->integration_is_active() ) {
			return new WP_Error( 'plugin_inactive', $this->inactive_message(), array( 'status' => 409 ) );
		}

		if ( ! isset( $ops[ $operation ] ) || $ops[ $operation ]['mode'] !== $mode ) {
			return new WP_Error(
				'unknown_operation',
				sprintf(
					/* translators: 1: mode (read/write), 2: operation name. */
					__( 'Unknown %1$s operation: %2$s. Call the tool with no operation to list them.', 'karmcp' ),
					$mode,
					$operation
				),
				array( 'status' => 404 )
			);
		}

		$op   = $ops[ $operation ];
		$args = ( isset( $input['arguments'] ) && is_array( $input['arguments'] ) ) ? $input['arguments'] : array();

		if ( ! call_user_func( $op['perm'] ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission for this operation.', 'karmcp' ),
				array( 'status' => 403 )
			);
		}

		if ( ! empty( $op['confirm'] ) && ( ! isset( $args['confirm'] ) || true !== $args['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'This operation is irreversible. Pass confirm:true in arguments to proceed.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}
		unset( $args['confirm'] );

		return call_user_func( $op['run'], $args );
	}

	/**
	 * The discovery catalog for one mode.
	 *
	 * @param string $mode Mode.
	 * @param array  $ops  Operation map.
	 * @return array
	 */
	protected function operation_catalog( string $mode, array $ops ): array {
		$out = array();
		foreach ( $ops as $name => $op ) {
			if ( $op['mode'] === $mode ) {
				$out[] = array(
					'operation'   => $name,
					'description' => $op['desc'],
					'confirm'     => ! empty( $op['confirm'] ),
				);
			}
		}

		return array(
			'mode'       => $mode,
			'operations' => $out,
		);
	}

	/**
	 * The shared { operation, arguments } input schema.
	 *
	 * @return array
	 */
	protected function operation_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation' => array(
					'type'        => 'string',
					'description' => __( 'Operation name. Omit to list the available operations.', 'karmcp' ),
				),
				'arguments' => array(
					'type'        => 'object',
					'description' => __( 'Arguments for the operation.', 'karmcp' ),
				),
			),
		);
	}
}
