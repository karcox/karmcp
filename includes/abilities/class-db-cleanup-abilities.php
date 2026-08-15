<?php
/**
 * clean-database: the performance audit finally does something.
 *
 * Same shape as harden-site, for the same reason — the analyzer measured the
 * database and said so, and nothing acted on it.
 *
 * @package KarMCP
 * @since   1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers clean-database.
 *
 * @since 1.11.0
 */
class KarMCP_DB_Cleanup_Abilities {

	/**
	 * @since 1.11.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/clean-database' );
	}

	/**
	 * @since 1.11.0
	 * @return void
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/clean-database',
			array(
				'label'               => __( 'Clean Database', 'karmcp' ),
				'description'         => __( 'Removes accumulated database bloat that slows WordPress down without touching caching: old post revisions, expired transients, orphaned metadata, spam and trashed comments, trashed posts, and table overhead reclaimed with OPTIMIZE TABLE. DRY-RUN BY DEFAULT — returns how much each task would remove, and only acts when called again with apply:true and confirm:true. Deletions run in bounded batches, so on a large site call it repeatedly until the counts reach zero. Revisions, comments and posts go through the WordPress API so their metadata goes with them; only genuinely orphaned rows are deleted with SQL. Several tasks are NOT reversible and the plan says which.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'apply'   => array(
							'type'        => 'boolean',
							'description' => __( 'Actually delete. Default false, which returns the plan only.', 'karmcp' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'description' => __( 'Required alongside apply:true. Acknowledges that several tasks cannot be undone.', 'karmcp' ),
						),
						'tasks'   => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Which tasks to run: revisions, transients, orphan_meta, spam_comments, trashed_posts, optimize. Omit to run every task that has something to do.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'applied'   => array( 'type' => 'boolean' ),
						'plan'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'removed'   => array( 'type' => 'object' ),
						'remaining' => array( 'type' => 'object' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Deleting content site-wide, so: administrators.
	 *
	 * @since 1.11.0
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @since 1.11.0
	 *
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute( $input ) {
		$counts = KarMCP_DB_Cleaner::counts();
		$plan   = KarMCP_DB_Cleaner::plan( $counts );

		if ( empty( $input['apply'] ) ) {
			return array(
				'applied'   => false,
				'plan'      => $plan,
				'removed'   => array(),
				'remaining' => $counts,
			);
		}

		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error(
				'confirmation_required',
				__( 'Revisions, spam comments, trashed posts and orphaned metadata are deleted permanently — there is no undo for those. Review the plan from a dry run, then call again with apply:true and confirm:true.', 'karmcp' )
			);
		}

		$wanted = array_values( array_filter( array_map( 'strval', (array) ( $input['tasks'] ?? array() ) ) ) );
		if ( ! $wanted ) {
			foreach ( $plan as $task ) {
				if ( ! empty( $task['worth_running'] ) ) {
					$wanted[] = (string) $task['id'];
				}
			}
		}

		$removed = KarMCP_DB_Cleaner::run( $wanted );

		return array(
			'applied'   => true,
			'plan'      => $plan,
			'removed'   => $removed,
			// Recounted after the fact: batches are bounded, so anything left
			// here means the tool should be called again.
			'remaining' => KarMCP_DB_Cleaner::counts(),
		);
	}
}
