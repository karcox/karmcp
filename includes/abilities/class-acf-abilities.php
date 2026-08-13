<?php
/**
 * Advanced Custom Fields (ACF / ACF PRO) MCP abilities.
 *
 * Seven tools — list-acf-field-groups, get-acf-field-group, list-acf-options-pages,
 * get-acf-fields (read), update-acf-fields, create-acf-field-group,
 * update-acf-field-group (write) — for reading/writing ACF field values on posts
 * and options pages, discovering field groups (the "schema discovery" step an
 * agent runs before writing), and authoring field groups programmatically.
 *
 * The whole group only registers when ACF (free or Pro) is active. Pro-only
 * capabilities (options pages; repeater / flexible content / gallery / clone
 * fields) degrade gracefully on free ACF. Writes ship disabled-by-default and
 * there is deliberately NO delete tool: field groups and values can be created
 * and edited but never removed via MCP, and field names/keys can never be
 * renamed (renames orphan postmeta).
 *
 * @package KarMCP
 * @since   3.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the ACF abilities.
 *
 * @since 3.2.1
 */
class KarMCP_ACF_Abilities {

	/** Field types that require ACF PRO. @since 3.2.1 */
	const PRO_FIELD_TYPES = array( 'repeater', 'flexible_content', 'gallery', 'clone' );

	/** Max depth when normalizing values / validating field definitions. @since 3.2.1 */
	const MAX_DEPTH = 10;

	/** @since 3.2.1 @var string[] */
	private $ability_names = array();

	/**
	 * Per-request cache of resolved field indexes, keyed by target.
	 *
	 * @since 3.2.1
	 * @var array<string,array>
	 */
	private $field_index_cache = array();

	/** @since 3.2.1 @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers the ACF domain as TWO dispatcher tools — `acf-read` and
	 * `acf-write` — rather than 15 individual abilities, to keep the MCP
	 * tool-list small. Each of the 15 operations is still discoverable (call a
	 * dispatcher with no operation to get its catalog) and individually gated by
	 * its own admin toggle (Tools → Plugins → ACF) and its original capability
	 * check, both enforced per call in dispatch().
	 *
	 * @since 3.2.1
	 */
	public function register(): void {
		$this->register_read_dispatcher();
		$this->register_write_dispatcher();
	}

	// -------------------------------------------------------------------
	// Dispatcher tools (acf-read / acf-write)
	// -------------------------------------------------------------------

	/**
	 * The operation map behind the two dispatchers. Each entry declares the
	 * executor + capability check + backing admin-toggle slug so the dispatcher
	 * can gate and route by name. `cpt_tax` operations need ACF 6.1+.
	 *
	 * @since 3.2.1
	 * @return array<string,array<string,mixed>>
	 */
	private function operations(): array {
		return array(
			// Reads ---------------------------------------------------------
			'list-field-groups'  => array( 'mode' => 'read', 'run' => 'execute_list_field_groups', 'perm' => 'check_read_permission', 'slug' => 'karmcp/list-acf-field-groups', 'cpt_tax' => false, 'desc' => __( 'List ACF field groups (key, title, active state, field count). No arguments.', 'karmcp' ) ),
			'get-field-group'    => array( 'mode' => 'read', 'run' => 'execute_get_field_group', 'perm' => 'check_read_permission', 'slug' => 'karmcp/get-acf-field-group', 'cpt_tax' => false, 'desc' => __( 'Get one field group\'s location rules + recursive field tree. arguments: { key }.', 'karmcp' ) ),
			'list-options-pages' => array( 'mode' => 'read', 'run' => 'execute_list_options_pages', 'perm' => 'check_read_permission', 'slug' => 'karmcp/list-acf-options-pages', 'cpt_tax' => false, 'desc' => __( 'List registered ACF options pages (PRO feature; empty on free ACF). No arguments.', 'karmcp' ) ),
			'get-fields'         => array( 'mode' => 'read', 'run' => 'execute_get_fields', 'perm' => 'check_fields_permission', 'slug' => 'karmcp/get-acf-fields', 'cpt_tax' => false, 'desc' => __( 'Read ACF field values from a post or options page. arguments: { post_id } or { options_page }.', 'karmcp' ) ),
			'list-post-types'    => array( 'mode' => 'read', 'run' => 'execute_list_post_types', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/list-acf-post-types', 'cpt_tax' => true, 'desc' => __( 'List ACF-managed Custom Post Types (ACF 6.1+). No arguments.', 'karmcp' ) ),
			'get-post-type'      => array( 'mode' => 'read', 'run' => 'execute_get_post_type', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/get-acf-post-type', 'cpt_tax' => true, 'desc' => __( 'Get one ACF-managed Custom Post Type definition. arguments: { key }.', 'karmcp' ) ),
			'list-taxonomies'    => array( 'mode' => 'read', 'run' => 'execute_list_taxonomies', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/list-acf-taxonomies', 'cpt_tax' => true, 'desc' => __( 'List ACF-managed taxonomies (ACF 6.1+). No arguments.', 'karmcp' ) ),
			'get-taxonomy'       => array( 'mode' => 'read', 'run' => 'execute_get_taxonomy', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/get-acf-taxonomy', 'cpt_tax' => true, 'desc' => __( 'Get one ACF-managed taxonomy definition. arguments: { key }.', 'karmcp' ) ),
			// Writes --------------------------------------------------------
			'update-fields'      => array( 'mode' => 'write', 'run' => 'execute_update_fields', 'perm' => 'check_fields_permission', 'slug' => 'karmcp/update-acf-fields', 'cpt_tax' => false, 'desc' => __( 'Write ACF field values on a post or options page (incl. repeater/flexible/gallery rows). arguments: { post_id|options_page, fields: { name: value } }.', 'karmcp' ) ),
			'create-field-group' => array( 'mode' => 'write', 'run' => 'execute_create_field_group', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/create-acf-field-group', 'cpt_tax' => false, 'desc' => __( 'Create a field group with fields + location rules. arguments: { title, fields: [...], location: [[...]] }.', 'karmcp' ) ),
			'update-field-group' => array( 'mode' => 'write', 'run' => 'execute_update_field_group', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/update-acf-field-group', 'cpt_tax' => false, 'desc' => __( 'Edit a stored field group: settings, new fields, or per-field setting changes (no deletes/renames). arguments: { key, ... }.', 'karmcp' ) ),
			'create-post-type'   => array( 'mode' => 'write', 'run' => 'execute_create_post_type', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/create-acf-post-type', 'cpt_tax' => true, 'desc' => __( 'Register a Custom Post Type through ACF (data, no code). arguments: { post_type, title, ... }.', 'karmcp' ) ),
			'update-post-type'   => array( 'mode' => 'write', 'run' => 'execute_update_post_type', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/update-acf-post-type', 'cpt_tax' => true, 'desc' => __( 'Edit an ACF-managed Custom Post Type (slug immutable). arguments: { key, ... }.', 'karmcp' ) ),
			'create-taxonomy'    => array( 'mode' => 'write', 'run' => 'execute_create_taxonomy', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/create-acf-taxonomy', 'cpt_tax' => true, 'desc' => __( 'Register a taxonomy through ACF (data, no code). arguments: { taxonomy, title, object_type: [...] }.', 'karmcp' ) ),
			'update-taxonomy'    => array( 'mode' => 'write', 'run' => 'execute_update_taxonomy', 'perm' => 'check_manage_permission', 'slug' => 'karmcp/update-acf-taxonomy', 'cpt_tax' => true, 'desc' => __( 'Edit an ACF-managed taxonomy (slug immutable). arguments: { key, ... }.', 'karmcp' ) ),
		);
	}

	private function register_read_dispatcher(): void {
		$this->ability_names[] = 'karmcp/acf-read';
		karmcp_register_ability(
			'karmcp/acf-read',
			array(
				'label'               => __( 'ACF Read', 'karmcp' ),
				'description'         => __( 'Read Advanced Custom Fields data: field groups, field values, options pages, and (ACF 6.1+) ACF-managed post types and taxonomies. Call with no "operation" to list the available read operations and their arguments, then call again with { operation, arguments }.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_acf_read' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'description' => __( 'The read operation to run. Omit to list operations. One of: list-field-groups, get-field-group, list-options-pages, get-fields, list-post-types, get-post-type, list-taxonomies, get-taxonomy.', 'karmcp' ) ),
						'arguments' => array( 'type' => 'object', 'description' => __( 'Arguments for the chosen operation (see the catalog returned when operation is omitted).', 'karmcp' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	private function register_write_dispatcher(): void {
		$this->ability_names[] = 'karmcp/acf-write';
		karmcp_register_ability(
			'karmcp/acf-write',
			array(
				'label'               => __( 'ACF Write', 'karmcp' ),
				'description'         => __( 'Write Advanced Custom Fields data: field values, field groups, and (ACF 6.1+) ACF-managed post types and taxonomies. Individual write operations are disabled by default, enable them under KarMCP → Tools → Plugins → ACF. Call with no "operation" to list the available write operations and their arguments, then call again with { operation, arguments }.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_acf_write' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'operation' => array( 'type' => 'string', 'description' => __( 'The write operation to run. Omit to list operations. One of: update-fields, create-field-group, update-field-group, create-post-type, update-post-type, create-taxonomy, update-taxonomy.', 'karmcp' ) ),
						'arguments' => array( 'type' => 'object', 'description' => __( 'Arguments for the chosen operation (see the catalog returned when operation is omitted).', 'karmcp' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/** @since 3.2.1 */
	public function run_acf_read( $input ) {
		return $this->dispatch( 'read', $input );
	}

	/** @since 3.2.1 */
	public function run_acf_write( $input ) {
		return $this->dispatch( 'write', $input );
	}

	/**
	 * Routes an operation to its executor after gating: availability (ACF 6.1+
	 * for CPT/tax ops), the per-operation admin toggle, then the operation's own
	 * capability check. An empty operation returns the discovery catalog.
	 *
	 * @since 3.2.1
	 * @param string $mode  'read' or 'write'.
	 * @param mixed  $input Dispatcher input ({ operation, arguments }).
	 * @return mixed
	 */
	private function dispatch( string $mode, $input ) {
		$operation = isset( $input['operation'] ) ? str_replace( '_', '-', sanitize_key( (string) $input['operation'] ) ) : '';
		if ( '' === $operation ) {
			return $this->operations_catalog( $mode );
		}

		$ops = $this->operations();
		if ( ! isset( $ops[ $operation ] ) || $ops[ $operation ]['mode'] !== $mode ) {
			return new \WP_Error(
				'unknown_operation',
				sprintf(
					/* translators: 1: mode (read/write), 2: operation name */
					__( 'Unknown ACF %1$s operation "%2$s". Call acf-%1$s with no operation to list the available operations.', 'karmcp' ),
					$mode,
					$operation
				)
			);
		}

		$op = $ops[ $operation ];
		if ( ! empty( $op['cpt_tax'] ) && ! self::cpt_tax_supported() ) {
			return new \WP_Error( 'acf_cpt_tax_unsupported', __( 'This operation requires ACF 6.1+ (Custom Post Type / Taxonomy registration API).', 'karmcp' ) );
		}

		// Note: read vs write access is gated at the tool level — the acf-read
		// and acf-write tools are individually enable-able on the Tools screen
		// (acf-write ships off), so a disabled dispatcher never reaches here.

		$args = ( isset( $input['arguments'] ) && is_array( $input['arguments'] ) ) ? $input['arguments'] : array();

		$perm = $op['perm'];
		if ( ! $this->$perm( $args ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to perform this ACF operation.', 'karmcp' ) );
		}

		$run = $op['run'];
		return $this->$run( $args );
	}

	/**
	 * Builds the discovery catalog for a mode: every available operation with a
	 * description. CPT/tax operations are omitted when ACF is below 6.1.
	 *
	 * @since 3.2.1
	 * @param string $mode 'read' or 'write'.
	 * @return array
	 */
	private function operations_catalog( string $mode ): array {
		$list = array();
		foreach ( $this->operations() as $name => $op ) {
			if ( $op['mode'] !== $mode ) {
				continue;
			}
			if ( ! empty( $op['cpt_tax'] ) && ! self::cpt_tax_supported() ) {
				continue;
			}
			$list[] = array(
				'operation'   => $name,
				'description' => $op['desc'],
			);
		}
		return array(
			'mode'       => $mode,
			'operations' => $list,
			'usage'      => sprintf(
				/* translators: %s: mode (read/write) */
				__( 'Call acf-%s again with { "operation": "<name>", "arguments": { ... } }.', 'karmcp' ),
				$mode
			),
		);
	}

	// -------------------------------------------------------------------
	// Environment detection
	// -------------------------------------------------------------------

	/**
	 * Whether ACF (free or Pro) is active and exposes the field-group API.
	 *
	 * @since 3.2.1
	 * @return bool
	 */
	public static function acf_active(): bool {
		return function_exists( 'acf_get_field_groups' );
	}

	/**
	 * Whether ACF PRO is active.
	 *
	 * @since 3.2.1
	 * @return bool
	 */
	public static function is_pro(): bool {
		if ( function_exists( 'acf_get_setting' ) ) {
			return (bool) acf_get_setting( 'pro' );
		}
		return defined( 'ACF_PRO' );
	}

	/**
	 * Whether ACF exposes its Custom Post Type / Taxonomy registration API
	 * (added in ACF 6.1).
	 *
	 * @since 3.2.1
	 * @return bool
	 */
	public static function cpt_tax_supported(): bool {
		return function_exists( 'acf_get_acf_post_types' )
			&& function_exists( 'acf_get_acf_taxonomies' )
			&& function_exists( 'acf_import_post_type' )
			&& function_exists( 'acf_import_taxonomy' );
	}

	// -------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------

	/**
	 * Read/discovery permission: editors need to see field groups to fill values.
	 *
	 * @since 3.2.1
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Field-value permission: `edit_post` on the target post, or `manage_options`
	 * for options-page targets (site-wide data, consistent with the Settings domain).
	 *
	 * @since 3.2.1
	 * @param array|null $input Tool input; carries `post_id` or `options_page`.
	 * @return bool
	 */
	public function check_fields_permission( $input = null ): bool {
		if ( isset( $input['options_page'] ) && '' !== (string) $input['options_page'] ) {
			return current_user_can( 'manage_options' );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Field-group authoring permission: field groups are site-wide definitions.
	 *
	 * @since 3.2.1
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------
	// list-acf-field-groups
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_field_groups( $input ): array {
		$args = array();
		if ( ! empty( $input['post_id'] ) ) {
			$args['post_id'] = absint( $input['post_id'] );
		}
		$groups      = acf_get_field_groups( $args );
		$search      = isset( $input['search'] ) ? strtolower( sanitize_text_field( (string) $input['search'] ) ) : '';
		$active_only = ! isset( $input['active_only'] ) || (bool) $input['active_only'];

		$rows = array();
		foreach ( (array) $groups as $group ) {
			if ( $active_only && empty( $group['active'] ) ) {
				continue;
			}
			$title = (string) ( $group['title'] ?? '' );
			if ( '' !== $search && false === strpos( strtolower( $title ), $search ) ) {
				continue;
			}
			$fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $group ) : array();
			$rows[] = array(
				'key'         => (string) ( $group['key'] ?? '' ),
				'id'          => (int) ( $group['ID'] ?? 0 ),
				'title'       => $title,
				'active'      => ! empty( $group['active'] ),
				'local'       => ! empty( $group['local'] ) ? (string) $group['local'] : null,
				'field_count' => count( $fields ),
			);
		}

		return array(
			'groups' => $rows,
			'total'  => count( $rows ),
			'pro'    => self::is_pro(),
		);
	}

	// -------------------------------------------------------------------
	// get-acf-field-group
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_field_group( $input ) {
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_params', __( 'A field group "key" is required.', 'karmcp' ) );
		}
		$group = acf_get_field_group( is_numeric( $key ) ? (int) $key : $key );
		if ( ! $group ) {
			return new \WP_Error( 'group_not_found', __( 'Field group not found.', 'karmcp' ) );
		}
		$fields = function_exists( 'acf_get_fields' ) ? (array) acf_get_fields( $group ) : array();

		return array(
			'key'      => (string) ( $group['key'] ?? '' ),
			'id'       => (int) ( $group['ID'] ?? 0 ),
			'title'    => (string) ( $group['title'] ?? '' ),
			'active'   => ! empty( $group['active'] ),
			'local'    => ! empty( $group['local'] ) ? (string) $group['local'] : null,
			'location' => (array) ( $group['location'] ?? array() ),
			'position' => (string) ( $group['position'] ?? '' ),
			'fields'   => array_map( array( $this, 'format_field' ), $fields ),
		);
	}

	/**
	 * Compact recursive view of one field definition (sub_fields / layouts included).
	 *
	 * @since 3.2.1
	 * @param array $field ACF field array.
	 * @param int   $depth Recursion depth guard.
	 * @return array
	 */
	private function format_field( $field, int $depth = 0 ): array {
		$field = (array) $field;
		$out   = array(
			'key'      => (string) ( $field['key'] ?? '' ),
			'name'     => (string) ( $field['name'] ?? '' ),
			'label'    => (string) ( $field['label'] ?? '' ),
			'type'     => (string) ( $field['type'] ?? '' ),
			'required' => ! empty( $field['required'] ),
		);
		foreach ( array( 'instructions', 'choices', 'default_value', 'return_format', 'min', 'max', 'multiple', 'allow_null', 'post_type', 'taxonomy' ) as $setting ) {
			if ( isset( $field[ $setting ] ) && '' !== $field[ $setting ] && array() !== $field[ $setting ] ) {
				$out[ $setting ] = $field[ $setting ];
			}
		}
		if ( $depth >= self::MAX_DEPTH ) {
			return $out;
		}
		if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$out['sub_fields'] = array();
			foreach ( $field['sub_fields'] as $sub ) {
				$out['sub_fields'][] = $this->format_field( $sub, $depth + 1 );
			}
		}
		if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $field['layouts'] as $layout ) {
				$layout_row = array(
					'key'   => (string) ( $layout['key'] ?? '' ),
					'name'  => (string) ( $layout['name'] ?? '' ),
					'label' => (string) ( $layout['label'] ?? '' ),
				);
				if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$layout_row['sub_fields'] = array();
					foreach ( $layout['sub_fields'] as $sub ) {
						$layout_row['sub_fields'][] = $this->format_field( $sub, $depth + 1 );
					}
				}
				$out['layouts'][] = $layout_row;
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// list-acf-options-pages
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_options_pages( $input ): array {
		if ( ! function_exists( 'acf_get_options_pages' ) ) {
			return array( 'pro' => self::is_pro(), 'pages' => array() );
		}
		$pages = acf_get_options_pages();
		$rows  = array();
		foreach ( (array) $pages as $page ) {
			$page   = (array) $page;
			$rows[] = array(
				'menu_slug'   => (string) ( $page['menu_slug'] ?? '' ),
				'page_title'  => (string) ( $page['page_title'] ?? '' ),
				'post_id'     => (string) ( $page['post_id'] ?? 'options' ),
				'parent_slug' => (string) ( $page['parent_slug'] ?? '' ),
			);
		}
		return array( 'pro' => true, 'pages' => $rows );
	}

	// -------------------------------------------------------------------
	// get-acf-fields
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_fields( $input ) {
		$target = $this->resolve_target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$index = $this->field_index( $target );
		$only  = array();
		if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) ) {
			$only = array_map( 'strval', $input['fields'] );
		}
		$with_objects = ! empty( $input['include_field_objects'] );

		$out = array();
		foreach ( $index['fields'] as $field ) {
			if ( $only && ! in_array( $field['name'], $only, true ) && ! in_array( $field['key'], $only, true ) ) {
				continue;
			}
			$value = function_exists( 'get_field' ) ? get_field( $field['key'], $target, true ) : null;
			$value = $this->normalize_value( $value );
			if ( $with_objects ) {
				$out[ $field['name'] ] = array(
					'key'   => $field['key'],
					'type'  => (string) ( $field['type'] ?? '' ),
					'label' => (string) ( $field['label'] ?? '' ),
					'value' => $value,
				);
			} else {
				$out[ $field['name'] ] = $value;
			}
		}

		return array(
			'target' => (string) $target,
			'fields' => $out,
			'pro'    => self::is_pro(),
		);
	}

	// -------------------------------------------------------------------
	// update-acf-fields
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_fields( $input ) {
		$target = $this->resolve_target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$fields = $input['fields'] ?? null;
		if ( ! is_array( $fields ) || array() === $fields ) {
			return new \WP_Error( 'missing_params', __( 'A non-empty "fields" map is required.', 'karmcp' ) );
		}

		$updated = array();
		$skipped = array();
		$values  = array();
		$before  = array(); // field_key => prior raw value, for rollback.

		foreach ( $fields as $name_or_key => $value ) {
			$name_or_key = (string) $name_or_key;
			$field       = $this->resolve_field( $name_or_key, $target );
			if ( ! $field ) {
				$skipped[] = array( 'field' => $name_or_key, 'reason' => 'field_not_found' );
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( in_array( $type, self::PRO_FIELD_TYPES, true ) && ! self::is_pro() ) {
				$skipped[] = array( 'field' => $name_or_key, 'reason' => 'acf_pro_required' );
				continue;
			}
			if ( 'flexible_content' === $type ) {
				$layout_error = $this->validate_flexible_rows( $value, $field );
				if ( null !== $layout_error ) {
					$skipped[] = array( 'field' => $name_or_key, 'reason' => $layout_error );
					continue;
				}
			}

			// Capture the prior raw value (by key) before overwriting, for rollback.
			if ( class_exists( 'KarMCP_Change_Recorder' ) && function_exists( 'get_field' ) ) {
				$before[ (string) $field['key'] ] = get_field( $field['key'], $target, false );
			}

			// Always write by field KEY: update_field() by name silently fails on
			// targets that have no stored value for the field yet.
			update_field( $field['key'], $value, $target );

			// update_field() also returns false on a no-op (identical value), so
			// success is confirmed by re-reading instead of trusting the return.
			$name             = (string) ( $field['name'] ?? $name_or_key );
			$updated[]        = $name;
			$values[ $name ] = $this->normalize_value( function_exists( 'get_field' ) ? get_field( $field['key'], $target, true ) : null );
		}

		// The per-request field index predates these writes — drop it so a
		// later read in the same request sees fresh state.
		unset( $this->field_index_cache[ (string) $target ] );

		if ( ! empty( $updated ) && ! empty( $before ) && class_exists( 'KarMCP_Change_Recorder' ) ) {
			KarMCP_Change_Recorder::record_acf_fields(
				$target,
				$before,
				sprintf( 'Updated %d ACF field(s)', count( $updated ) ),
				(string) $target
			);
		}

		return array(
			'target'  => (string) $target,
			'updated' => $updated,
			'skipped' => $skipped,
			'values'  => $values,
		);
	}

	/**
	 * Validates flexible-content rows: each row must be an array carrying an
	 * `acf_fc_layout` that matches one of the field's layout names.
	 *
	 * @since 3.2.1
	 * @param mixed $rows  Incoming value.
	 * @param array $field Flexible content field definition.
	 * @return string|null Error reason, or null when valid.
	 */
	private function validate_flexible_rows( $rows, array $field ): ?string {
		if ( ! is_array( $rows ) ) {
			return 'invalid_flexible_value';
		}
		$layout_names = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			$layout = (array) $layout;
			if ( ! empty( $layout['name'] ) ) {
				$layout_names[] = (string) $layout['name'];
			}
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) {
				return 'missing_acf_fc_layout';
			}
			if ( ! in_array( (string) $row['acf_fc_layout'], $layout_names, true ) ) {
				return 'unknown_layout';
			}
		}
		return null;
	}

	// -------------------------------------------------------------------
	// create-acf-field-group
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_create_field_group( $input ) {
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'missing_params', __( 'A field group "title" is required.', 'karmcp' ) );
		}
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return new \WP_Error( 'missing_params', __( 'A non-empty "fields" array is required.', 'karmcp' ) );
		}
		if ( ! function_exists( 'acf_import_field_group' ) ) {
			return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose acf_import_field_group().', 'karmcp' ) );
		}

		$fields = $this->sanitize_field_defs( $input['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$location = $this->sanitize_location( $input['location'] ?? null );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$group = array(
			'key'        => uniqid( 'group_' ),
			'title'      => $title,
			'fields'     => $fields,
			'location'   => $location,
			'active'     => ! isset( $input['active'] ) || (bool) $input['active'],
			'menu_order' => absint( $input['menu_order'] ?? 0 ),
		);
		if ( isset( $input['position'] ) && in_array( $input['position'], array( 'normal', 'acf_after_title', 'side' ), true ) ) {
			$group['position'] = $input['position'];
		}

		// acf_import_field_group() persists the group AND its fields to the
		// acf-field-group CPT. acf_add_local_field_group() would be memory-only.
		$saved = acf_import_field_group( $group );
		if ( ! $saved || empty( $saved['ID'] ) ) {
			return new \WP_Error( 'group_create_failed', __( 'ACF did not save the field group.', 'karmcp' ) );
		}

		$this->field_index_cache = array();

		$summary = array();
		foreach ( $fields as $f ) {
			$summary[] = array( 'key' => $f['key'], 'name' => $f['name'], 'type' => $f['type'] );
		}
		return array(
			'key'       => (string) $saved['key'],
			'id'        => (int) $saved['ID'],
			'title'     => $title,
			'fields'    => $summary,
			'edit_link' => admin_url( 'post.php?post=' . (int) $saved['ID'] . '&action=edit' ),
		);
	}

	// -------------------------------------------------------------------
	// update-acf-field-group
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_field_group( $input ) {
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_params', __( 'A field group "key" is required.', 'karmcp' ) );
		}
		$group = acf_get_field_group( $key );
		if ( ! $group ) {
			return new \WP_Error( 'group_not_found', __( 'Field group not found.', 'karmcp' ) );
		}
		if ( ! empty( $group['local'] ) ) {
			// Updating a local (acf-json / PHP-registered) group would create a DB
			// copy that shadows the code-registered definition.
			return new \WP_Error( 'acf_local_group', __( 'This field group is registered from code (acf-json/PHP) and cannot be edited via MCP.', 'karmcp' ) );
		}
		if ( empty( $group['ID'] ) ) {
			return new \WP_Error( 'acf_local_group', __( 'This field group is not stored in the database and cannot be edited via MCP.', 'karmcp' ) );
		}

		$updated = array();

		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$group['title'] = sanitize_text_field( (string) $input['title'] );
			$updated[]      = 'title';
		}
		if ( isset( $input['location'] ) ) {
			$location = $this->sanitize_location( $input['location'] );
			if ( is_wp_error( $location ) ) {
				return $location;
			}
			$group['location'] = $location;
			$updated[]         = 'location';
		}
		if ( isset( $input['active'] ) ) {
			$group['active'] = (bool) $input['active'];
			$updated[]       = 'active';
		}
		if ( isset( $input['position'] ) && in_array( $input['position'], array( 'normal', 'acf_after_title', 'side' ), true ) ) {
			$group['position'] = $input['position'];
			$updated[]         = 'position';
		}
		if ( $updated ) {
			acf_update_field_group( $group );
		}

		$fields_added = array();
		if ( ! empty( $input['add_fields'] ) && is_array( $input['add_fields'] ) ) {
			if ( ! function_exists( 'acf_update_field' ) ) {
				return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose acf_update_field().', 'karmcp' ) );
			}
			$defs = $this->sanitize_field_defs( $input['add_fields'] );
			if ( is_wp_error( $defs ) ) {
				return $defs;
			}
			foreach ( $defs as $def ) {
				$def['parent'] = (int) $group['ID'];
				$saved         = acf_update_field( $def );
				if ( $saved && ! empty( $saved['key'] ) ) {
					$fields_added[] = array( 'key' => (string) $saved['key'], 'name' => (string) ( $saved['name'] ?? '' ), 'type' => (string) ( $saved['type'] ?? '' ) );
				}
			}
		}

		$fields_updated = array();
		if ( ! empty( $input['update_fields'] ) && is_array( $input['update_fields'] ) ) {
			if ( ! function_exists( 'acf_get_field' ) || ! function_exists( 'acf_update_field' ) ) {
				return new \WP_Error( 'acf_unavailable', __( 'This ACF version does not expose the field update API.', 'karmcp' ) );
			}
			foreach ( $input['update_fields'] as $change ) {
				$change    = (array) $change;
				$field_key = sanitize_text_field( (string) ( $change['key'] ?? '' ) );
				if ( '' === $field_key ) {
					return new \WP_Error( 'missing_params', __( 'Each update_fields entry needs the field "key".', 'karmcp' ) );
				}
				foreach ( array( 'name', 'type' ) as $immutable ) {
					if ( isset( $change[ $immutable ] ) ) {
						return new \WP_Error(
							'immutable_field_setting',
							sprintf( /* translators: 1: setting, 2: field key */ __( 'The "%1$s" of an existing field cannot change via MCP (field %2$s). Add a new field instead.', 'karmcp' ), $immutable, $field_key )
						);
					}
				}
				$field = acf_get_field( $field_key );
				if ( ! $field ) {
					return new \WP_Error( 'field_not_found', sprintf( /* translators: %s: field key */ __( 'Field "%s" not found.', 'karmcp' ), $field_key ) );
				}
				foreach ( $this->mutable_field_settings() as $setting ) {
					if ( array_key_exists( $setting, $change ) ) {
						$field[ $setting ] = $this->sanitize_field_setting( $setting, $change[ $setting ] );
					}
				}
				acf_update_field( $field );
				$fields_updated[] = $field_key;
			}
		}

		$this->field_index_cache = array();

		return array(
			'key'            => (string) $group['key'],
			'updated'        => $updated,
			'fields_added'   => $fields_added,
			'fields_updated' => $fields_updated,
		);
	}

	// -------------------------------------------------------------------
	// Target + field resolution
	// -------------------------------------------------------------------

	/**
	 * Resolves the tool target: exactly one of post_id / options_page.
	 *
	 * @since 3.2.1
	 * @param array $input Tool input.
	 * @return int|string|\WP_Error Post ID, ACF options post_id string, or error.
	 */
	private function resolve_target( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$options = isset( $input['options_page'] ) ? sanitize_text_field( (string) $input['options_page'] ) : '';

		if ( $post_id && '' !== $options ) {
			return new \WP_Error( 'invalid_target', __( 'Pass either "post_id" or "options_page", not both.', 'karmcp' ) );
		}
		if ( $post_id ) {
			if ( ! get_post( $post_id ) ) {
				return new \WP_Error( 'post_not_found', __( 'Post not found.', 'karmcp' ) );
			}
			return $post_id;
		}
		if ( '' !== $options ) {
			if ( ! self::is_pro() ) {
				return new \WP_Error( 'acf_pro_required', __( 'Options pages require ACF PRO.', 'karmcp' ) );
			}
			return 'option' === $options ? 'options' : $options;
		}
		return new \WP_Error( 'invalid_target', __( 'A target is required: pass "post_id" or "options_page".', 'karmcp' ) );
	}

	/**
	 * Builds (and caches per request) the field index for a target: every
	 * top-level field of every field group that applies to it.
	 *
	 * For post targets the applicable groups come from ACF's own location
	 * matching; for options targets ACF has no reverse lookup, so the index
	 * falls back to the fields that already carry a stored value.
	 *
	 * @since 3.2.1
	 * @param int|string $target
	 * @return array{fields: array<int,array>, by_name: array<string,array>, by_key: array<string,array>}
	 */
	private function field_index( $target ): array {
		$cache_key = (string) $target;
		if ( isset( $this->field_index_cache[ $cache_key ] ) ) {
			return $this->field_index_cache[ $cache_key ];
		}

		$fields = array();
		if ( is_int( $target ) ) {
			$groups = acf_get_field_groups( array( 'post_id' => $target ) );
			foreach ( (array) $groups as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					$fields[] = (array) $field;
				}
			}
		} else {
			// ACF cannot reverse-match an options target to its groups, so take
			// every group with an options_page location rule (covers first writes
			// on fields with no stored value yet) plus the stored field objects.
			foreach ( (array) acf_get_field_groups() as $group ) {
				if ( $this->group_targets_options( (array) $group ) ) {
					foreach ( (array) acf_get_fields( $group ) as $field ) {
						$fields[] = (array) $field;
					}
				}
			}
			if ( function_exists( 'get_field_objects' ) ) {
				$objects = get_field_objects( $target, false );
				foreach ( (array) $objects as $field ) {
					$fields[] = (array) $field;
				}
			}
		}

		$index = array( 'fields' => array(), 'by_name' => array(), 'by_key' => array() );
		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) || empty( $field['name'] ) || isset( $index['by_key'][ $field['key'] ] ) ) {
				continue;
			}
			$index['fields'][]                    = $field;
			$index['by_name'][ $field['name'] ]   = $field;
			$index['by_key'][ $field['key'] ]     = $field;
		}

		$this->field_index_cache[ $cache_key ] = $index;
		return $index;
	}

	/**
	 * Whether a field group has any options_page location rule.
	 *
	 * @since 3.2.1
	 * @param array $group Field group array.
	 * @return bool
	 */
	private function group_targets_options( array $group ): bool {
		foreach ( (array) ( $group['location'] ?? array() ) as $rule_group ) {
			foreach ( (array) $rule_group as $rule ) {
				if ( 'options_page' === ( $rule['param'] ?? '' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Resolves a field name or key to its full definition for a target.
	 *
	 * @since 3.2.1
	 * @param string     $name_or_key Field name or field_xxx key.
	 * @param int|string $target
	 * @return array|null
	 */
	private function resolve_field( string $name_or_key, $target ): ?array {
		if ( 0 === strpos( $name_or_key, 'field_' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $name_or_key );
			if ( $field ) {
				return (array) $field;
			}
		}
		$index = $this->field_index( $target );
		if ( isset( $index['by_name'][ $name_or_key ] ) ) {
			return $index['by_name'][ $name_or_key ];
		}
		if ( isset( $index['by_key'][ $name_or_key ] ) ) {
			return $index['by_key'][ $name_or_key ];
		}
		// Last resort (covers options targets with no stored value yet): a
		// stored field object lookup by name via ACF itself.
		if ( function_exists( 'get_field_object' ) ) {
			$field = get_field_object( $name_or_key, $target, false, false );
			if ( is_array( $field ) && ! empty( $field['key'] ) && 0 === strpos( (string) $field['key'], 'field_' ) ) {
				return $field;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------
	// Field definition sanitization (create / add fields)
	// -------------------------------------------------------------------

	/**
	 * Field settings an agent may set on create and change on update.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	private function mutable_field_settings(): array {
		return array(
			'label', 'instructions', 'required', 'choices', 'default_value',
			'placeholder', 'min', 'max', 'step', 'return_format', 'allow_null',
			'multiple', 'ui', 'post_type', 'taxonomy', 'mime_types', 'layout',
			'button_label', 'preview_size', 'display_format', 'new_lines',
		);
	}

	/**
	 * Sanitizes one field setting value by shape (scalar vs array passthrough).
	 *
	 * @since 3.2.1
	 * @param string $setting
	 * @param mixed  $value
	 * @return mixed
	 */
	private function sanitize_field_setting( string $setting, $value ) {
		if ( is_array( $value ) ) {
			// choices / post_type / taxonomy etc. — sanitize leaves only.
			array_walk_recursive(
				$value,
				static function ( &$leaf ) {
					if ( is_string( $leaf ) ) {
						$leaf = sanitize_text_field( $leaf );
					}
				}
			);
			return $value;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Validates + sanitizes an array of incoming field definitions, assigning
	 * fresh field_ keys recursively (sub_fields and flexible layouts included).
	 *
	 * @since 3.2.1
	 * @param array $defs  Raw definitions from the tool input.
	 * @param int   $depth Recursion depth guard.
	 * @return array|\WP_Error
	 */
	private function sanitize_field_defs( array $defs, int $depth = 0 ) {
		if ( $depth > self::MAX_DEPTH ) {
			return new \WP_Error( 'invalid_field', __( 'Field definitions are nested too deeply.', 'karmcp' ) );
		}
		$out = array();
		foreach ( $defs as $def ) {
			$def   = (array) $def;
			$name  = sanitize_key( (string) ( $def['name'] ?? '' ) );
			$type  = sanitize_key( (string) ( $def['type'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $def['label'] ?? '' ) );
			if ( '' === $name || '' === $type || '' === $label ) {
				return new \WP_Error( 'invalid_field', __( 'Every field definition needs "label", "name", and "type".', 'karmcp' ) );
			}
			if ( in_array( $type, self::PRO_FIELD_TYPES, true ) && ! self::is_pro() ) {
				return new \WP_Error( 'acf_pro_required', sprintf( /* translators: %s: field type */ __( 'The "%s" field type requires ACF PRO.', 'karmcp' ), $type ) );
			}
			if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $type ) ) {
				return new \WP_Error( 'invalid_field', sprintf( /* translators: %s: field type */ __( 'Unknown ACF field type "%s".', 'karmcp' ), $type ) );
			}

			$field = array(
				'key'      => uniqid( 'field_' ),
				'label'    => $label,
				'name'     => $name,
				'type'     => $type,
				'required' => ! empty( $def['required'] ) ? 1 : 0,
			);
			foreach ( $this->mutable_field_settings() as $setting ) {
				if ( array_key_exists( $setting, $def ) && ! in_array( $setting, array( 'label', 'required' ), true ) ) {
					$field[ $setting ] = $this->sanitize_field_setting( $setting, $def[ $setting ] );
				}
			}

			if ( ! empty( $def['sub_fields'] ) && is_array( $def['sub_fields'] ) ) {
				$subs = $this->sanitize_field_defs( $def['sub_fields'], $depth + 1 );
				if ( is_wp_error( $subs ) ) {
					return $subs;
				}
				$field['sub_fields'] = $subs;
			}
			if ( ! empty( $def['layouts'] ) && is_array( $def['layouts'] ) ) {
				$layouts = array();
				foreach ( $def['layouts'] as $layout ) {
					$layout      = (array) $layout;
					$layout_name = sanitize_key( (string) ( $layout['name'] ?? '' ) );
					if ( '' === $layout_name ) {
						return new \WP_Error( 'invalid_field', __( 'Every flexible content layout needs a "name".', 'karmcp' ) );
					}
					$layout_row = array(
						'key'     => uniqid( 'layout_' ),
						'name'    => $layout_name,
						'label'   => sanitize_text_field( (string) ( $layout['label'] ?? $layout_name ) ),
						'display' => 'block',
					);
					if ( ! empty( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$subs = $this->sanitize_field_defs( $layout['sub_fields'], $depth + 1 );
						if ( is_wp_error( $subs ) ) {
							return $subs;
						}
						$layout_row['sub_fields'] = $subs;
					}
					$layouts[ $layout_row['key'] ] = $layout_row;
				}
				$field['layouts'] = $layouts;
			}

			$out[] = $field;
		}
		return $out;
	}

	/**
	 * Validates ACF location rules: OR-array of AND-arrays of {param, operator, value}.
	 *
	 * @since 3.2.1
	 * @param mixed $location Raw location input (null = default post_type==post).
	 * @return array|\WP_Error
	 */
	private function sanitize_location( $location ) {
		if ( null === $location || array() === $location ) {
			return array(
				array(
					array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ),
				),
			);
		}
		if ( ! is_array( $location ) ) {
			return new \WP_Error( 'invalid_location', __( 'Location must be an array of rule groups.', 'karmcp' ) );
		}
		$out = array();
		foreach ( $location as $rule_group ) {
			if ( ! is_array( $rule_group ) ) {
				return new \WP_Error( 'invalid_location', __( 'Each location rule group must be an array of rules.', 'karmcp' ) );
			}
			$group_out = array();
			foreach ( $rule_group as $rule ) {
				$rule = (array) $rule;
				if ( empty( $rule['param'] ) || empty( $rule['operator'] ) || ! isset( $rule['value'] ) ) {
					return new \WP_Error( 'invalid_location', __( 'Each location rule needs "param", "operator", and "value".', 'karmcp' ) );
				}
				if ( ! in_array( (string) $rule['operator'], array( '==', '!=' ), true ) ) {
					return new \WP_Error( 'invalid_location', __( 'Location rule operator must be "==" or "!=".', 'karmcp' ) );
				}
				$group_out[] = array(
					'param'    => sanitize_key( (string) $rule['param'] ),
					'operator' => (string) $rule['operator'],
					'value'    => sanitize_text_field( (string) $rule['value'] ),
				);
			}
			if ( $group_out ) {
				$out[] = $group_out;
			}
		}
		if ( array() === $out ) {
			return new \WP_Error( 'invalid_location', __( 'At least one location rule is required.', 'karmcp' ) );
		}
		return $out;
	}

	// -------------------------------------------------------------------
	// Output value normalization
	// -------------------------------------------------------------------

	/**
	 * Makes an ACF value JSON-friendly and compact regardless of each field's
	 * return_format: WP_Post/WP_User objects and attachment arrays become small
	 * summaries; nesting is depth-capped.
	 *
	 * @since 3.2.1
	 * @param mixed $value
	 * @param int   $depth Recursion depth guard.
	 * @return mixed
	 */
	private function normalize_value( $value, int $depth = 0 ) {
		if ( $depth >= self::MAX_DEPTH ) {
			return is_scalar( $value ) || null === $value ? $value : '[max depth reached]';
		}
		if ( $value instanceof \WP_Post ) {
			return array(
				'id'        => (int) $value->ID,
				'title'     => (string) $value->post_title,
				'post_type' => (string) $value->post_type,
				'url'       => function_exists( 'get_permalink' ) ? (string) get_permalink( $value ) : '',
			);
		}
		if ( class_exists( '\WP_User' ) && $value instanceof \WP_User ) {
			return array(
				'id'           => (int) $value->ID,
				'display_name' => (string) $value->display_name,
			);
		}
		if ( class_exists( '\WP_Term' ) && $value instanceof \WP_Term ) {
			return array(
				'id'       => (int) $value->term_id,
				'name'     => (string) $value->name,
				'taxonomy' => (string) $value->taxonomy,
			);
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			// ACF image/file arrays (return_format=array) are huge; keep a summary.
			if ( isset( $value['ID'], $value['url'], $value['mime_type'] ) ) {
				return array(
					'id'   => (int) $value['ID'],
					'url'  => (string) $value['url'],
					'alt'  => (string) ( $value['alt'] ?? '' ),
					'mime' => (string) $value['mime_type'],
				);
			}
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->normalize_value( $v, $depth + 1 );
			}
			return $out;
		}
		return $value;
	}

	// ===================================================================
	// Custom Post Types (ACF-managed, ACF 6.1+)
	// ===================================================================

	// -------------------------------------------------------------------
	// list-acf-post-types
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_post_types( $input ): array {
		$search      = isset( $input['search'] ) ? strtolower( sanitize_text_field( (string) $input['search'] ) ) : '';
		$active_only = ! isset( $input['active_only'] ) || (bool) $input['active_only'];

		$rows = array();
		foreach ( (array) acf_get_acf_post_types() as $pt ) {
			$pt = (array) $pt;
			if ( $active_only && empty( $pt['active'] ) ) {
				continue;
			}
			if ( '' !== $search
				&& false === strpos( strtolower( (string) ( $pt['title'] ?? '' ) ), $search )
				&& false === strpos( strtolower( (string) ( $pt['post_type'] ?? '' ) ), $search ) ) {
				continue;
			}
			$rows[] = $this->format_acf_post_type( $pt, false );
		}

		return array( 'post_types' => $rows, 'total' => count( $rows ) );
	}

	// -------------------------------------------------------------------
	// get-acf-post-type
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_post_type( $input ) {
		$pt = $this->find_internal( $input['key'] ?? '', 'acf-post-type' );
		if ( is_wp_error( $pt ) ) {
			return $pt;
		}
		return $this->format_acf_post_type( $pt, true );
	}

	// -------------------------------------------------------------------
	// create-acf-post-type
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_create_post_type( $input ) {
		$slug = $this->sanitize_type_slug( $input['post_type'] ?? '', 20, 'post_type' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'missing_params', __( 'A "title" is required.', 'karmcp' ) );
		}
		if ( post_type_exists( $slug ) ) {
			return new \WP_Error( 'post_type_exists', sprintf( /* translators: %s: slug */ __( 'A post type "%s" already exists.', 'karmcp' ), $slug ) );
		}

		$singular = sanitize_text_field( (string) ( $input['singular'] ?? $title ) );
		$def      = array(
			'key'          => uniqid( 'post_type_' ),
			'title'        => $title,
			'post_type'    => $slug,
			'active'       => true,
			'public'       => ! isset( $input['public'] ) || (bool) $input['public'],
			'hierarchical' => ! empty( $input['hierarchical'] ),
			'show_in_rest' => ! isset( $input['show_in_rest'] ) || (bool) $input['show_in_rest'],
			'supports'     => $this->sanitize_string_list( $input['supports'] ?? array( 'title', 'editor', 'thumbnail' ) ),
			'labels'       => array( 'name' => $title, 'singular_name' => $singular ),
		);
		if ( isset( $input['has_archive'] ) ) {
			$def['has_archive'] = (bool) $input['has_archive'];
		}
		if ( isset( $input['taxonomies'] ) ) {
			$def['taxonomies'] = $this->sanitize_string_list( $input['taxonomies'] );
		}

		$saved = acf_import_post_type( $def );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return new \WP_Error( 'post_type_create_failed', __( 'ACF did not save the post type.', 'karmcp' ) );
		}
		return $this->format_acf_post_type( (array) $saved, true );
	}

	// -------------------------------------------------------------------
	// update-acf-post-type
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_post_type( $input ) {
		$pt = $this->find_internal( $input['key'] ?? '', 'acf-post-type' );
		if ( is_wp_error( $pt ) ) {
			return $pt;
		}
		if ( isset( $input['post_type'] ) && sanitize_key( (string) $input['post_type'] ) !== (string) $pt['post_type'] ) {
			return new \WP_Error( 'immutable_slug', __( 'The post_type slug cannot change via MCP (it would orphan existing content).', 'karmcp' ) );
		}

		$pt = $this->apply_type_updates( $pt, $input );
		if ( array_key_exists( 'has_archive', $input ) ) {
			$pt['has_archive'] = (bool) $input['has_archive'];
		}
		if ( array_key_exists( 'hierarchical', $input ) ) {
			$pt['hierarchical'] = (bool) $input['hierarchical'];
		}
		if ( array_key_exists( 'supports', $input ) ) {
			$pt['supports'] = $this->sanitize_string_list( $input['supports'] );
		}
		if ( array_key_exists( 'taxonomies', $input ) ) {
			$pt['taxonomies'] = $this->sanitize_string_list( $input['taxonomies'] );
		}

		$saved = acf_update_internal_post_type( $pt, 'acf-post-type' );
		if ( ! is_array( $saved ) ) {
			return new \WP_Error( 'post_type_update_failed', __( 'ACF did not save the post type.', 'karmcp' ) );
		}
		return $this->format_acf_post_type( (array) $saved, true );
	}

	// ===================================================================
	// Taxonomies (ACF-managed, ACF 6.1+)
	// ===================================================================

	// -------------------------------------------------------------------
	// list-acf-taxonomies
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_taxonomies( $input ): array {
		$search      = isset( $input['search'] ) ? strtolower( sanitize_text_field( (string) $input['search'] ) ) : '';
		$active_only = ! isset( $input['active_only'] ) || (bool) $input['active_only'];

		$rows = array();
		foreach ( (array) acf_get_acf_taxonomies() as $tax ) {
			$tax = (array) $tax;
			if ( $active_only && empty( $tax['active'] ) ) {
				continue;
			}
			if ( '' !== $search
				&& false === strpos( strtolower( (string) ( $tax['title'] ?? '' ) ), $search )
				&& false === strpos( strtolower( (string) ( $tax['taxonomy'] ?? '' ) ), $search ) ) {
				continue;
			}
			$rows[] = $this->format_acf_taxonomy( $tax, false );
		}

		return array( 'taxonomies' => $rows, 'total' => count( $rows ) );
	}

	// -------------------------------------------------------------------
	// get-acf-taxonomy
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_taxonomy( $input ) {
		$tax = $this->find_internal( $input['key'] ?? '', 'acf-taxonomy' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		return $this->format_acf_taxonomy( $tax, true );
	}

	// -------------------------------------------------------------------
	// create-acf-taxonomy
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_create_taxonomy( $input ) {
		$slug = $this->sanitize_type_slug( $input['taxonomy'] ?? '', 32, 'taxonomy' );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'missing_params', __( 'A "title" is required.', 'karmcp' ) );
		}
		$object_type = $this->sanitize_string_list( $input['object_type'] ?? array() );
		if ( array() === $object_type ) {
			return new \WP_Error( 'missing_params', __( 'A non-empty "object_type" array (post type slugs) is required.', 'karmcp' ) );
		}
		if ( taxonomy_exists( $slug ) ) {
			return new \WP_Error( 'taxonomy_exists', sprintf( /* translators: %s: slug */ __( 'A taxonomy "%s" already exists.', 'karmcp' ), $slug ) );
		}

		$singular = sanitize_text_field( (string) ( $input['singular'] ?? $title ) );
		$def      = array(
			'key'          => uniqid( 'taxonomy_' ),
			'title'        => $title,
			'taxonomy'     => $slug,
			'active'       => true,
			'object_type'  => $object_type,
			'hierarchical' => ! isset( $input['hierarchical'] ) || (bool) $input['hierarchical'],
			'public'       => ! isset( $input['public'] ) || (bool) $input['public'],
			'show_in_rest' => ! isset( $input['show_in_rest'] ) || (bool) $input['show_in_rest'],
			'labels'       => array( 'name' => $title, 'singular_name' => $singular ),
		);

		$saved = acf_import_taxonomy( $def );
		if ( ! is_array( $saved ) || empty( $saved['ID'] ) ) {
			return new \WP_Error( 'taxonomy_create_failed', __( 'ACF did not save the taxonomy.', 'karmcp' ) );
		}
		return $this->format_acf_taxonomy( (array) $saved, true );
	}

	// -------------------------------------------------------------------
	// update-acf-taxonomy
	// -------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_taxonomy( $input ) {
		$tax = $this->find_internal( $input['key'] ?? '', 'acf-taxonomy' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		if ( isset( $input['taxonomy'] ) && sanitize_key( (string) $input['taxonomy'] ) !== (string) $tax['taxonomy'] ) {
			return new \WP_Error( 'immutable_slug', __( 'The taxonomy slug cannot change via MCP (it would orphan existing terms).', 'karmcp' ) );
		}

		$tax = $this->apply_type_updates( $tax, $input );
		if ( array_key_exists( 'hierarchical', $input ) ) {
			$tax['hierarchical'] = (bool) $input['hierarchical'];
		}
		if ( array_key_exists( 'object_type', $input ) ) {
			$tax['object_type'] = $this->sanitize_string_list( $input['object_type'] );
		}

		$saved = acf_update_internal_post_type( $tax, 'acf-taxonomy' );
		if ( ! is_array( $saved ) ) {
			return new \WP_Error( 'taxonomy_update_failed', __( 'ACF did not save the taxonomy.', 'karmcp' ) );
		}
		return $this->format_acf_taxonomy( (array) $saved, true );
	}

	// -------------------------------------------------------------------
	// CPT / taxonomy helpers
	// -------------------------------------------------------------------

	/**
	 * Loads an ACF-managed internal post type (acf-post-type / acf-taxonomy) by
	 * key or numeric ID, returning a WP_Error when absent.
	 *
	 * @since 3.2.1
	 * @param mixed  $key       Key string or numeric ID.
	 * @param string $post_type 'acf-post-type' or 'acf-taxonomy'.
	 * @return array|\WP_Error
	 */
	private function find_internal( $key, string $post_type ) {
		$key = sanitize_text_field( (string) $key );
		if ( '' === $key ) {
			return new \WP_Error( 'missing_params', __( 'A "key" is required.', 'karmcp' ) );
		}
		$item = acf_get_internal_post_type( is_numeric( $key ) ? (int) $key : $key, $post_type );
		if ( ! $item || ! is_array( $item ) ) {
			return new \WP_Error( 'not_found', __( 'Not found, or not managed by ACF.', 'karmcp' ) );
		}
		return $item;
	}

	/**
	 * Applies the settings shared by CPT and taxonomy updates (title, labels,
	 * public, show_in_rest, active). Slug + type-specific keys are handled by
	 * the callers.
	 *
	 * @since 3.2.1
	 * @param array $item  Current definition.
	 * @param array $input Tool input.
	 * @return array
	 */
	private function apply_type_updates( array $item, array $input ): array {
		if ( isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ) {
			$item['title']                  = sanitize_text_field( (string) $input['title'] );
			$item['labels']['name']         = $item['title'];
		}
		if ( isset( $input['singular'] ) && '' !== trim( (string) $input['singular'] ) ) {
			$item['labels']['singular_name'] = sanitize_text_field( (string) $input['singular'] );
		}
		if ( array_key_exists( 'public', $input ) ) {
			$item['public'] = (bool) $input['public'];
		}
		if ( array_key_exists( 'show_in_rest', $input ) ) {
			$item['show_in_rest'] = (bool) $input['show_in_rest'];
		}
		if ( array_key_exists( 'active', $input ) ) {
			$item['active'] = (bool) $input['active'];
		}
		return $item;
	}

	/**
	 * Validates + sanitizes a post-type / taxonomy slug.
	 *
	 * @since 3.2.1
	 * @param mixed  $raw   Incoming slug.
	 * @param int    $max   Max length (20 for post types, 32 for taxonomies).
	 * @param string $which 'post_type' or 'taxonomy' (for the error message).
	 * @return string|\WP_Error
	 */
	private function sanitize_type_slug( $raw, int $max, string $which ) {
		$slug = sanitize_key( (string) $raw );
		if ( '' === $slug ) {
			return new \WP_Error( 'missing_params', sprintf( /* translators: %s: field name */ __( 'A "%s" slug is required.', 'karmcp' ), $which ) );
		}
		if ( strlen( $slug ) > $max ) {
			return new \WP_Error( 'invalid_slug', sprintf( /* translators: 1: field, 2: max length */ __( 'The %1$s slug must be %2$d characters or fewer.', 'karmcp' ), $which, $max ) );
		}
		if ( in_array( $slug, self::reserved_type_slugs(), true ) ) {
			return new \WP_Error( 'reserved_slug', sprintf( /* translators: %s: slug */ __( '"%s" is a reserved WordPress slug.', 'karmcp' ), $slug ) );
		}
		return $slug;
	}

	/**
	 * WordPress-reserved post type / taxonomy slugs that must not be claimed.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	private static function reserved_type_slugs(): array {
		return array(
			'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css',
			'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
			'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
			'action', 'author', 'order', 'theme', 'category', 'post_tag', 'post_format',
			'nav_menu', 'link_category',
		);
	}

	/**
	 * Sanitizes a flat list of slug-ish strings.
	 *
	 * @since 3.2.1
	 * @param mixed $list
	 * @return string[]
	 */
	private function sanitize_string_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $item ) {
			$clean = sanitize_key( (string) $item );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Compact (list) or full (get/create/update) view of an ACF post type.
	 *
	 * @since 3.2.1
	 * @param array $pt   ACF post-type definition.
	 * @param bool  $full Include the full setting set.
	 * @return array
	 */
	private function format_acf_post_type( array $pt, bool $full ): array {
		$out = array(
			'key'          => (string) ( $pt['key'] ?? '' ),
			'id'           => (int) ( $pt['ID'] ?? 0 ),
			'post_type'    => (string) ( $pt['post_type'] ?? '' ),
			'title'        => (string) ( $pt['title'] ?? '' ),
			'active'       => ! empty( $pt['active'] ),
			'public'       => ! empty( $pt['public'] ),
			'hierarchical' => ! empty( $pt['hierarchical'] ),
			'has_archive'  => ! empty( $pt['has_archive'] ),
			'supports'     => array_values( (array) ( $pt['supports'] ?? array() ) ),
			'taxonomies'   => array_values( (array) ( $pt['taxonomies'] ?? array() ) ),
		);
		if ( $full ) {
			$out['labels']       = (array) ( $pt['labels'] ?? array() );
			$out['show_in_rest'] = ! empty( $pt['show_in_rest'] );
			$out['description']  = (string) ( $pt['description'] ?? '' );
			$out['edit_link']    = ! empty( $pt['ID'] ) ? admin_url( 'post.php?post=' . (int) $pt['ID'] . '&action=edit' ) : '';
		}
		return $out;
	}

	/**
	 * Compact (list) or full (get/create/update) view of an ACF taxonomy.
	 *
	 * @since 3.2.1
	 * @param array $tax  ACF taxonomy definition.
	 * @param bool  $full Include the full setting set.
	 * @return array
	 */
	private function format_acf_taxonomy( array $tax, bool $full ): array {
		$out = array(
			'key'          => (string) ( $tax['key'] ?? '' ),
			'id'           => (int) ( $tax['ID'] ?? 0 ),
			'taxonomy'     => (string) ( $tax['taxonomy'] ?? '' ),
			'title'        => (string) ( $tax['title'] ?? '' ),
			'active'       => ! empty( $tax['active'] ),
			'public'       => ! empty( $tax['public'] ),
			'hierarchical' => ! empty( $tax['hierarchical'] ),
			'object_type'  => array_values( (array) ( $tax['object_type'] ?? array() ) ),
		);
		if ( $full ) {
			$out['labels']       = (array) ( $tax['labels'] ?? array() );
			$out['show_in_rest'] = ! empty( $tax['show_in_rest'] );
			$out['description']  = (string) ( $tax['description'] ?? '' );
			$out['edit_link']    = ! empty( $tax['ID'] ) ? admin_url( 'post.php?post=' . (int) $tax['ID'] . '&action=edit' ) : '';
		}
		return $out;
	}
}
