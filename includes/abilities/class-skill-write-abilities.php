<?php
/**
 * Agent Skills MCP abilities — the write side.
 *
 * This tool exists against the instinct of the read side, which says in as many
 * words that letting an agent rewrite its own instructions is a governance hole
 * dressed up as a feature. That instinct is right, and the answer here is not to
 * argue with it but to give this tool the treatment the plugin already gives
 * everything else that is powerful enough to be dangerous: it ships DISABLED, an
 * administrator turns it on from KarMCP → Tools, it needs `manage_options` and
 * `unfiltered_html`, and overwriting a body needs `confirm`. Every write leaves
 * a WordPress revision, so any of it can be read back and reverted from the
 * dashboard.
 *
 * What made it necessary is that the alternative turned out to be worse. Editing
 * a skill by hand was corrupting it: the block editor escaped the Markdown one
 * level deeper on every save, and by the time anyone noticed, a 112 KB guide had
 * 113 `&amp;lt;` and 218 `&amp;gt;` where its author had typed angle brackets.
 * KarMCP_Skill_Editor stops that happening again; this is what can repair what
 * already happened, and it is why `edit` — a search and replace over the body —
 * matters more than `update`: a 120 KB skill cannot be re-sent whole through a
 * tool call, so replacing the whole body is exactly the operation that does not
 * work at the size where it is needed.
 *
 * @package KarMCP
 * @since   1.25.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `skill-write`.
 *
 * @since 1.25.2
 */
class KarMCP_Skill_Write_Abilities {

	use KarMCP_Operation_Dispatcher;

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
	 * Writing a skill needs both caps, and for different reasons.
	 *
	 * `manage_options` because a skill steers every agent on the site, which
	 * puts it closer to configuration than to content — it is the capability the
	 * post type itself demands. `unfiltered_html` because the body must be
	 * stored byte for byte: without it WordPress runs the content through kses
	 * on the way in, which escapes the angle brackets in every code example and
	 * quietly produces the very corruption this tool exists to repair. It is the
	 * same pair the PHP snippet tools require, for the same reason.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( KarMCP_Skill_Store::CAP ) && current_user_can( 'unfiltered_html' );
	}

	/** Registers this group's MCP abilities. */
	public function register(): void {
		$this->ability_names[] = 'karmcp/skill-write';
		karmcp_register_ability(
			'karmcp/skill-write',
			array(
				'label'               => __( 'Write Skill', 'karmcp' ),
				'description'         => __( 'Creates and edits the site\'s skills, the operating manuals agents read before working here. Takes { operation, arguments }; call it with no operation to list them. Operations: create, update, edit. Prefer "edit" — it is a search and replace over the body and is the only one that works on a long skill, since replacing a whole body means sending it whole and a large skill will not fit in one call. Every write leaves a WordPress revision, so it can be reverted from KarMCP → Skills. Needs manage_options + unfiltered_html.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_write' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array(
							'type'        => 'string',
							'enum'        => array( 'create', 'update', 'edit' ),
							'description' => __( 'Which operation to run. Omit to list them with their arguments.', 'karmcp' ),
						),
						'arguments' => array(
							'type'        => 'object',
							'description' => __( 'Arguments for the operation.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'      => array( 'type' => 'string' ),
						'post_id'   => array( 'type' => 'integer' ),
						'chars'     => array( 'type' => 'integer', 'description' => __( 'Size of the stored body after the write.', 'karmcp' ) ),
						'replaced'  => array( 'type' => 'integer', 'description' => __( 'For edit: how many occurrences were replaced.', 'karmcp' ) ),
						'edit_link' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Tool entry point.
	 *
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function execute_write( $input ) {
		return $this->dispatch_operation( 'write', $input );
	}

	/**
	 * The operation map.
	 *
	 * @return array<string,array>
	 */
	protected function operations(): array {
		$perm = array( $this, 'check_permission' );

		return array(
			'create' => array(
				'mode' => 'write',
				'perm' => $perm,
				'desc' => __( 'Create a skill. Arguments: name (machine name / slug), title, summary, body, status (publish|draft, default draft).', 'karmcp' ),
				'run'  => array( $this, 'op_create' ),
			),
			'update' => array(
				'mode'    => 'write',
				'perm'    => $perm,
				'desc'    => __( 'Update a skill\'s fields. Arguments: name or post_id, plus any of title, summary, status, body. Passing body REPLACES the whole body and needs confirm:true — on a long skill use edit instead.', 'karmcp' ),
				'confirm' => false,
				'run'     => array( $this, 'op_update' ),
			),
			'edit'   => array(
				'mode' => 'write',
				'perm' => $perm,
				'desc' => __( 'Search and replace inside a skill\'s body. Arguments: name or post_id, old_string, new_string, replace_all (default false). old_string must appear exactly once unless replace_all is true, so an ambiguous edit fails instead of guessing.', 'karmcp' ),
				'run'  => array( $this, 'op_edit' ),
			),
		);
	}

	/**
	 * The skills post type is part of this plugin, so there is nothing external
	 * to be missing. Kept because the trait requires it.
	 *
	 * @return bool
	 */
	protected function integration_is_active(): bool {
		return post_type_exists( KarMCP_Skill_Store::POST_TYPE );
	}

	/**
	 * Message when the post type is somehow absent.
	 *
	 * @return string
	 */
	protected function inactive_message(): string {
		return __( 'The skills post type is not registered.', 'karmcp' );
	}

	// ---------------------------------------------------------------------
	// Operations
	// ---------------------------------------------------------------------

	/**
	 * Creates a skill.
	 *
	 * @param array $args Operation arguments.
	 * @return array|WP_Error
	 */
	public function op_create( array $args ) {
		$name = isset( $args['name'] ) ? sanitize_title( (string) $args['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A machine name is required: it is how get-skill addresses the skill.', 'karmcp' ), array( 'status' => 400 ) );
		}
		if ( KarMCP_Skill_Store::find( $name ) ) {
			return new WP_Error(
				'already_exists',
				sprintf(
					/* translators: %s: skill machine name. */
					__( 'A skill named "%s" already exists. Use update or edit.', 'karmcp' ),
					$name
				),
				array( 'status' => 409 )
			);
		}

		$status = ( isset( $args['status'] ) && 'publish' === $args['status'] ) ? 'publish' : 'draft';

		$post_id = wp_insert_post(
			array(
				'post_type'    => KarMCP_Skill_Store::POST_TYPE,
				'post_name'    => $name,
				'post_title'   => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : $name,
				'post_excerpt' => isset( $args['summary'] ) ? sanitize_textarea_field( (string) $args['summary'] ) : '',
				// The body is Markdown and goes in verbatim. See check_permission().
				'post_content' => wp_slash( isset( $args['body'] ) ? (string) $args['body'] : '' ),
				'post_status'  => $status,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return $this->result( (int) $post_id );
	}

	/**
	 * Updates a skill's fields.
	 *
	 * @param array $args Operation arguments.
	 * @return array|WP_Error
	 */
	public function op_update( array $args ) {
		$skill = $this->resolve( $args );
		if ( is_wp_error( $skill ) ) {
			return $skill;
		}

		$data = array( 'ID' => $skill->ID );

		if ( isset( $args['title'] ) ) {
			$data['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( isset( $args['summary'] ) ) {
			$data['post_excerpt'] = sanitize_textarea_field( (string) $args['summary'] );
		}
		if ( isset( $args['status'] ) ) {
			$status = sanitize_key( (string) $args['status'] );
			if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
				return new WP_Error( 'invalid_status', __( 'Status must be publish or draft.', 'karmcp' ), array( 'status' => 400 ) );
			}
			$data['post_status'] = $status;
		}

		if ( isset( $args['body'] ) ) {
			// Replacing a body wholesale is the one irreversible-feeling move
			// here, because what it overwrites may be thousands of lines nobody
			// re-read first. The revision makes it recoverable; the confirm makes
			// it deliberate.
			if ( ! isset( $args['confirm'] ) || true !== $args['confirm'] ) {
				return new WP_Error(
					'confirmation_required',
					sprintf(
						/* translators: %d: size of the current body in characters. */
						__( 'Replacing the body would overwrite %d characters. Pass confirm:true, or use the edit operation to change part of it.', 'karmcp' ),
						strlen( $skill->post_content )
					),
					array( 'status' => 400 )
				);
			}
			$data['post_content'] = wp_slash( (string) $args['body'] );
		}

		if ( 1 === count( $data ) ) {
			return new WP_Error( 'nothing_to_do', __( 'Pass at least one of title, summary, status or body.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$result = wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->result( $skill->ID );
	}

	/**
	 * Search and replace within a skill's body.
	 *
	 * The operation that makes this tool usable at all. A skill of any real size
	 * cannot be re-sent whole through a tool call, so this is how a long one gets
	 * edited: name the piece to change and what it becomes.
	 *
	 * The uniqueness contract is borrowed from `edit-file` and matters for the
	 * same reason: a string that appears twice means the caller is thinking of one
	 * of them and cannot say which, so the edit is refused rather than applied to
	 * a guess.
	 *
	 * @param array $args Operation arguments.
	 * @return array|WP_Error
	 */
	public function op_edit( array $args ) {
		$skill = $this->resolve( $args );
		if ( is_wp_error( $skill ) ) {
			return $skill;
		}

		$old = isset( $args['old_string'] ) ? (string) $args['old_string'] : '';
		$new = isset( $args['new_string'] ) ? (string) $args['new_string'] : '';

		if ( '' === $old ) {
			return new WP_Error( 'missing_old_string', __( 'old_string is required.', 'karmcp' ), array( 'status' => 400 ) );
		}
		if ( $old === $new ) {
			return new WP_Error( 'no_change', __( 'old_string and new_string are identical.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$body  = $skill->post_content;
		$count = substr_count( $body, $old );

		if ( 0 === $count ) {
			return new WP_Error(
				'not_found',
				__( 'old_string does not appear in this skill. Read it with get-skill first — whitespace and line breaks have to match exactly.', 'karmcp' ),
				array( 'status' => 404 )
			);
		}

		$replace_all = ! empty( $args['replace_all'] );

		if ( $count > 1 && ! $replace_all ) {
			return new WP_Error(
				'not_unique',
				sprintf(
					/* translators: %d: number of occurrences found. */
					__( 'old_string appears %d times, so which one to change is ambiguous. Extend it with surrounding text until it is unique, or pass replace_all:true to change every one.', 'karmcp' ),
					$count
				),
				array( 'status' => 409 )
			);
		}

		$body = $replace_all
			? str_replace( $old, $new, $body )
			: $this->replace_first( $body, $old, $new );

		$result = wp_update_post(
			array(
				'ID'           => $skill->ID,
				'post_content' => wp_slash( $body ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$out             = $this->result( $skill->ID );
		$out['replaced'] = $replace_all ? $count : 1;

		return $out;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Replaces only the first occurrence.
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   What to find.
	 * @param string $replace  What to put there.
	 * @return string
	 */
	private function replace_first( string $haystack, string $needle, string $replace ): string {
		$at = strpos( $haystack, $needle );
		if ( false === $at ) {
			return $haystack;
		}
		return substr_replace( $haystack, $replace, $at, strlen( $needle ) );
	}

	/**
	 * Resolves a skill from a name or a post id.
	 *
	 * @param array $args Operation arguments.
	 * @return WP_Post|WP_Error
	 */
	private function resolve( array $args ) {
		if ( ! empty( $args['post_id'] ) ) {
			$post = get_post( absint( $args['post_id'] ) );
			if ( $post && KarMCP_Skill_Store::POST_TYPE === $post->post_type ) {
				return $post;
			}
			return new WP_Error( 'not_found', __( 'No skill with that post_id.', 'karmcp' ), array( 'status' => 404 ) );
		}

		$name = isset( $args['name'] ) ? sanitize_title( (string) $args['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'missing_target', __( 'Pass name or post_id.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$post = KarMCP_Skill_Store::find( $name );
		if ( ! $post ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: skill machine name. */
					__( 'No skill named "%s". list-skills has the names.', 'karmcp' ),
					$name
				),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * The shape every operation answers with, read back from storage rather than
	 * assumed — so `chars` reports what was actually stored, which is the number
	 * that tells a caller whether a write landed intact.
	 *
	 * @param int $post_id Skill id.
	 * @return array
	 */
	private function result( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'name'      => $post ? $post->post_name : '',
			'post_id'   => $post_id,
			'chars'     => $post ? strlen( $post->post_content ) : 0,
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
