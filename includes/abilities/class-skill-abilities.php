<?php
/**
 * Agent Skills MCP abilities — the read side.
 *
 * `list-skills` returns the index, `get-skill` returns one body. Both read-only,
 * and deliberately so: a skill is the instruction an agent follows, and a tool
 * that let the agent rewrite its own instructions would be a governance hole
 * dressed up as a feature. Skills are written by a human, in the dashboard.
 *
 * Registered only when the Agent Skills module is on, so an admin can pull the
 * whole surface — tools and discovery block — with one toggle.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the skill read abilities.
 *
 * @since 1.0.0
 */
class KarMCP_Skill_Abilities {

	/**
	 * Names of the abilities actually registered by register().
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Returns the names of all abilities registered by this group.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Anyone who can edit content can read the house rules — that is the point
	 * of writing them down. Managing skills is a separate, admin-only matter,
	 * handled by the post type's own capabilities.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/** Registers this group's MCP abilities. */
	public function register(): void {
		$this->ability_names[] = 'karmcp/list-skills';
		karmcp_register_ability(
			'karmcp/list-skills',
			array(
				'label'               => __( 'List Skills', 'karmcp' ),
				'description'         => __( 'List this site\'s skills: short operating manuals written by the site owner describing how work here is expected to be done. Returns names and one-line summaries, not the full text. Fetch a body with get-skill. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_skills' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'skills' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'name'    => array( 'type' => 'string' ),
									'title'   => array( 'type' => 'string' ),
									'summary' => array( 'type' => 'string' ),
								),
							),
						),
						'count'  => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);

		$this->ability_names[] = 'karmcp/get-skill';
		karmcp_register_ability(
			'karmcp/get-skill',
			array(
				'label'               => __( 'Get Skill', 'karmcp' ),
				'description'         => __( 'Return the full text of one skill by its machine name, as listed by list-skills or in the Skills block of the discovery context. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_skill' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'name' ),
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => __( 'The skill\'s machine name, e.g. "landing-pages".', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'    => array( 'type' => 'string' ),
						'title'   => array( 'type' => 'string' ),
						'summary' => array( 'type' => 'string' ),
						'body'    => array( 'type' => 'string' ),
					),
				),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * list-skills: the index.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public function execute_list_skills( $input ) {
		unset( $input );
		$skills = KarMCP_Skill_Catalog::index();
		return array(
			'skills' => $skills,
			'count'  => count( $skills ),
		);
	}

	/**
	 * get-skill: one body.
	 *
	 * The not-found error lists what does exist. An agent that guessed a name
	 * can then correct itself in one step instead of guessing again.
	 *
	 * @param array $input { name: string }
	 * @return array|WP_Error
	 */
	public function execute_get_skill( $input ) {
		$name  = isset( $input['name'] ) ? (string) $input['name'] : '';
		$skill = KarMCP_Skill_Store::find( $name );

		if ( ! $skill ) {
			$known = array();
			foreach ( KarMCP_Skill_Catalog::index() as $entry ) {
				$known[] = $entry['name'];
			}

			return new WP_Error(
				'karmcp_skill_not_found',
				array() === $known
					? __( 'No skill by that name — this site has no published skills.', 'karmcp' )
					: sprintf(
						/* translators: 1: requested name, 2: comma-separated list of available names */
						__( 'No skill named "%1$s". Available: %2$s.', 'karmcp' ),
						$name,
						implode( ', ', $known )
					),
				array( 'status' => 404 )
			);
		}

		return KarMCP_Skill_Store::expand( $skill );
	}
}
