<?php
/**
 * Unit tests for KarMCP_ACF_Abilities.
 *
 * Runs against the standalone stub harness in tests/bootstrap.php:
 *
 *     vendor/bin/phpunit -c tests/phpunit.xml
 *
 * @package KarMCP
 */

use PHPUnit\Framework\TestCase;

class AcfAbilitiesTest extends TestCase {

	/** @var KarMCP_ACF_Abilities */
	private $abilities;

	protected function setUp(): void {
		karmcp_test_reset();
		$this->abilities = new KarMCP_ACF_Abilities();
	}

	/**
	 * Fixture: one post (ID 10) with a field group containing a text field, a
	 * repeater (text + image sub-fields), and a flexible content field with a
	 * "hero" layout.
	 */
	private function seed_post_with_group(): void {
		$GLOBALS['karmcp_test']['posts'][10] = new WP_Post( array( 'ID' => 10, 'post_title' => 'Sample' ) );

		$group = array( 'key' => 'group_demo', 'ID' => 55, 'title' => 'Demo Group', 'active' => true );
		$GLOBALS['karmcp_test']['field_groups'][]        = $group;
		$GLOBALS['karmcp_test']['groups_for_post'][10]   = array( $group );
		$GLOBALS['karmcp_test']['group_fields']['group_demo'] = array(
			array( 'key' => 'field_headline', 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ),
			array(
				'key'        => 'field_items',
				'name'       => 'items',
				'label'      => 'Items',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_item_text', 'name' => 'item_text', 'label' => 'Item Text', 'type' => 'text' ),
					array( 'key' => 'field_item_img', 'name' => 'item_img', 'label' => 'Item Image', 'type' => 'image' ),
				),
			),
			array(
				'key'     => 'field_sections',
				'name'    => 'sections',
				'label'   => 'Sections',
				'type'    => 'flexible_content',
				'layouts' => array(
					array( 'key' => 'layout_hero', 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array(
						array( 'key' => 'field_hero_title', 'name' => 'hero_title', 'label' => 'Hero Title', 'type' => 'text' ),
					) ),
				),
			),
		);
	}

	// -------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------

	public function test_registers_two_dispatcher_tools(): void {
		$this->abilities->register();

		$expected = array( 'karmcp/acf-read', 'karmcp/acf-write' );
		$this->assertSame( $expected, $this->abilities->get_ability_names() );
		foreach ( $expected as $name ) {
			$this->assertArrayHasKey( $name, $GLOBALS['karmcp_test']['abilities'] );
		}
	}

	public function test_read_catalog_lists_all_read_operations(): void {
		$out = $this->abilities->run_acf_read( array() );
		$this->assertSame( 'read', $out['mode'] );
		$names = array_column( $out['operations'], 'operation' );
		$this->assertSame(
			array( 'list-field-groups', 'get-field-group', 'list-options-pages', 'get-fields', 'list-post-types', 'get-post-type', 'list-taxonomies', 'get-taxonomy' ),
			$names
		);
	}

	public function test_write_catalog_lists_all_write_operations(): void {
		$out   = $this->abilities->run_acf_write( array() );
		$this->assertSame( 'write', $out['mode'] );
		$names = array_column( $out['operations'], 'operation' );
		$this->assertSame(
			array( 'update-fields', 'create-field-group', 'update-field-group', 'create-post-type', 'update-post-type', 'create-taxonomy', 'update-taxonomy' ),
			$names
		);
	}

	public function test_read_dispatch_routes_to_executor(): void {
		$this->seed_post_with_group();
		$out = $this->abilities->run_acf_read( array( 'operation' => 'get-fields', 'arguments' => array( 'post_id' => 10 ) ) );
		$this->assertFalse( $out instanceof WP_Error, 'get-fields should route to its executor' );
		$this->assertArrayHasKey( 'fields', $out );
	}

	public function test_unknown_operation_is_rejected(): void {
		$out = $this->abilities->run_acf_read( array( 'operation' => 'delete-everything' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'unknown_operation', $out->get_error_code() );
	}

	public function test_write_operation_via_read_dispatcher_is_rejected(): void {
		$out = $this->abilities->run_acf_read( array( 'operation' => 'create-field-group' ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'unknown_operation', $out->get_error_code() );
	}

	public function test_structural_write_requires_manage_options(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' ); // no manage_options
		$out = $this->abilities->run_acf_write( array( 'operation' => 'create-taxonomy', 'arguments' => array( 'taxonomy' => 'x', 'title' => 'X' ) ) );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'forbidden', $out->get_error_code() );
	}

	public function test_cpt_tax_support_is_detected(): void {
		// The harness declares the ACF 6.1+ functions, so the gate is on.
		$this->assertTrue( KarMCP_ACF_Abilities::cpt_tax_supported() );
	}

	public function test_dispatchers_are_annotated_read_and_write(): void {
		$this->abilities->register();
		$abilities = $GLOBALS['karmcp_test']['abilities'];

		$this->assertTrue( $abilities['karmcp/acf-read']['meta']['annotations']['readonly'] );
		$this->assertFalse( $abilities['karmcp/acf-write']['meta']['annotations']['readonly'] );
	}

	// -------------------------------------------------------------------
	// Permissions
	// -------------------------------------------------------------------

	public function test_options_target_requires_manage_options(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' ); // no manage_options
		$this->assertFalse( $this->abilities->check_fields_permission( array( 'options_page' => 'options' ) ) );

		$GLOBALS['karmcp_test']['caps'] = array( 'manage_options' );
		$this->assertTrue( $this->abilities->check_fields_permission( array( 'options_page' => 'options' ) ) );
	}

	public function test_post_target_requires_edit_post_on_that_post(): void {
		$GLOBALS['karmcp_test']['caps']          = array( 'edit_posts' );
		$GLOBALS['karmcp_test']['post_caps'][10] = false;
		$this->assertFalse( $this->abilities->check_fields_permission( array( 'post_id' => 10 ) ) );

		$GLOBALS['karmcp_test']['post_caps'][10] = true;
		$this->assertTrue( $this->abilities->check_fields_permission( array( 'post_id' => 10 ) ) );
	}

	public function test_field_group_authoring_requires_manage_options(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' );
		$this->assertFalse( $this->abilities->check_manage_permission() );

		$GLOBALS['karmcp_test']['caps'] = array( 'manage_options' );
		$this->assertTrue( $this->abilities->check_manage_permission() );
	}

	// -------------------------------------------------------------------
	// Target validation
	// -------------------------------------------------------------------

	public function test_update_fields_rejects_both_targets(): void {
		$this->seed_post_with_group();
		$result = $this->abilities->execute_update_fields( array( 'post_id' => 10, 'options_page' => 'options', 'fields' => array( 'headline' => 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
	}

	public function test_update_fields_rejects_missing_target(): void {
		$result = $this->abilities->execute_update_fields( array( 'fields' => array( 'headline' => 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_target', $result->get_error_code() );
	}

	public function test_options_target_requires_pro(): void {
		$GLOBALS['karmcp_test']['acf_pro'] = false;
		$result = $this->abilities->execute_update_fields( array( 'options_page' => 'options', 'fields' => array( 'x' => 'y' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acf_pro_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------
	// update-acf-fields
	// -------------------------------------------------------------------

	public function test_update_writes_by_field_key_not_name(): void {
		$this->seed_post_with_group();
		$result = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'headline' => 'Hello World' ),
		) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'headline' ), $result['updated'] );
		$this->assertCount( 1, $GLOBALS['karmcp_test']['update_field_calls'] );
		$this->assertSame( array( 'field_headline', 'Hello World', 10 ), $GLOBALS['karmcp_test']['update_field_calls'][0] );
	}

	public function test_update_accepts_field_keys_directly(): void {
		$this->seed_post_with_group();
		$GLOBALS['karmcp_test']['fields_by_key']['field_headline'] = $GLOBALS['karmcp_test']['group_fields']['group_demo'][0];

		$result = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'field_headline' => 'Via key' ),
		) );

		$this->assertSame( array( 'headline' ), $result['updated'] );
		$this->assertSame( 'field_headline', $GLOBALS['karmcp_test']['update_field_calls'][0][0] );
	}

	public function test_update_skips_unknown_fields_with_reason(): void {
		$this->seed_post_with_group();
		$result = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'nope' => 'x' ),
		) );

		$this->assertSame( array(), $result['updated'] );
		$this->assertSame( array( array( 'field' => 'nope', 'reason' => 'field_not_found' ) ), $result['skipped'] );
		$this->assertCount( 0, $GLOBALS['karmcp_test']['update_field_calls'] );
	}

	public function test_update_repeater_rows_pass_through_to_acf(): void {
		$this->seed_post_with_group();
		$rows = array(
			array( 'item_text' => 'Row 1', 'item_img' => 42 ),
			array( 'item_text' => 'Row 2', 'item_img' => 43 ),
		);
		$result = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'items' => $rows ),
		) );

		$this->assertSame( array( 'items' ), $result['updated'] );
		$this->assertSame( array( 'field_items', $rows, 10 ), $GLOBALS['karmcp_test']['update_field_calls'][0] );
	}

	public function test_update_pro_field_rejected_on_free_acf(): void {
		$this->seed_post_with_group();
		$GLOBALS['karmcp_test']['acf_pro'] = false;

		$result = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'items' => array() ),
		) );

		$this->assertSame( array( array( 'field' => 'items', 'reason' => 'acf_pro_required' ) ), $result['skipped'] );
		$this->assertCount( 0, $GLOBALS['karmcp_test']['update_field_calls'] );
	}

	public function test_flexible_rows_require_known_layout(): void {
		$this->seed_post_with_group();

		$missing = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'sections' => array( array( 'hero_title' => 'No layout key' ) ) ),
		) );
		$this->assertSame( 'missing_acf_fc_layout', $missing['skipped'][0]['reason'] );

		$unknown = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'sections' => array( array( 'acf_fc_layout' => 'bogus' ) ) ),
		) );
		$this->assertSame( 'unknown_layout', $unknown['skipped'][0]['reason'] );

		$valid = $this->abilities->execute_update_fields( array(
			'post_id' => 10,
			'fields'  => array( 'sections' => array( array( 'acf_fc_layout' => 'hero', 'hero_title' => 'Hi' ) ) ),
		) );
		$this->assertSame( array( 'sections' ), $valid['updated'] );
	}

	// -------------------------------------------------------------------
	// get-acf-fields
	// -------------------------------------------------------------------

	public function test_get_fields_returns_null_for_unsaved_fields(): void {
		$this->seed_post_with_group();
		$GLOBALS['karmcp_test']['values']['10'] = array( 'field_headline' => 'Stored' );

		$result = $this->abilities->execute_get_fields( array( 'post_id' => 10 ) );

		$this->assertSame( 'Stored', $result['fields']['headline'] );
		$this->assertNull( $result['fields']['items'] );
		$this->assertNull( $result['fields']['sections'] );
	}

	public function test_get_fields_normalizes_wp_post_and_image_arrays(): void {
		$this->seed_post_with_group();
		$GLOBALS['karmcp_test']['values']['10'] = array(
			'field_headline' => new WP_Post( array( 'ID' => 7, 'post_title' => 'Linked', 'post_type' => 'page' ) ),
			'field_items'    => array( array( 'item_img' => array( 'ID' => 42, 'url' => 'http://example.test/i.jpg', 'mime_type' => 'image/jpeg', 'alt' => 'Alt' ) ) ),
		);

		$result = $this->abilities->execute_get_fields( array( 'post_id' => 10 ) );

		$this->assertSame( array( 'id' => 7, 'title' => 'Linked', 'post_type' => 'page', 'url' => 'http://example.test/?p=7' ), $result['fields']['headline'] );
		$this->assertSame( array( 'id' => 42, 'url' => 'http://example.test/i.jpg', 'alt' => 'Alt', 'mime' => 'image/jpeg' ), $result['fields']['items'][0]['item_img'] );
	}

	public function test_get_fields_filter_limits_output(): void {
		$this->seed_post_with_group();
		$result = $this->abilities->execute_get_fields( array( 'post_id' => 10, 'fields' => array( 'headline' ) ) );
		$this->assertSame( array( 'headline' ), array_keys( $result['fields'] ) );
	}

	// -------------------------------------------------------------------
	// Field group discovery
	// -------------------------------------------------------------------

	public function test_get_field_group_formats_recursive_tree(): void {
		$this->seed_post_with_group();
		$result = $this->abilities->execute_get_field_group( array( 'key' => 'group_demo' ) );

		$this->assertSame( 'group_demo', $result['key'] );
		$this->assertCount( 3, $result['fields'] );
		$this->assertSame( 'item_text', $result['fields'][1]['sub_fields'][0]['name'] );
		$this->assertSame( 'hero', $result['fields'][2]['layouts'][0]['name'] );
		$this->assertSame( 'hero_title', $result['fields'][2]['layouts'][0]['sub_fields'][0]['name'] );
	}

	public function test_list_field_groups_filters_inactive_by_default(): void {
		$GLOBALS['karmcp_test']['field_groups'] = array(
			array( 'key' => 'group_a', 'ID' => 1, 'title' => 'Active', 'active' => true ),
			array( 'key' => 'group_b', 'ID' => 2, 'title' => 'Inactive', 'active' => false ),
		);

		$result = $this->abilities->execute_list_field_groups( array() );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'group_a', $result['groups'][0]['key'] );

		$all = $this->abilities->execute_list_field_groups( array( 'active_only' => false ) );
		$this->assertSame( 2, $all['total'] );
	}

	// -------------------------------------------------------------------
	// create-acf-field-group
	// -------------------------------------------------------------------

	public function test_create_group_assigns_keys_and_imports(): void {
		$result = $this->abilities->execute_create_field_group( array(
			'title'  => 'New Group',
			'fields' => array(
				array( 'label' => 'Headline', 'name' => 'headline', 'type' => 'text' ),
				array(
					'label'      => 'Items',
					'name'       => 'items',
					'type'       => 'repeater',
					'sub_fields' => array(
						array( 'label' => 'Text', 'name' => 'item_text', 'type' => 'text' ),
					),
				),
			),
		) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $GLOBALS['karmcp_test']['imported_groups'] );
		$imported = $GLOBALS['karmcp_test']['imported_groups'][0];

		$this->assertStringStartsWith( 'group_', $imported['key'] );
		$this->assertStringStartsWith( 'field_', $imported['fields'][0]['key'] );
		$this->assertStringStartsWith( 'field_', $imported['fields'][1]['sub_fields'][0]['key'] );
		// Default location applied.
		$this->assertSame( 'post_type', $imported['location'][0][0]['param'] );
		$this->assertSame( (int) $imported['ID'], $result['id'] );
	}

	public function test_create_group_rejects_pro_types_on_free_acf(): void {
		$GLOBALS['karmcp_test']['acf_pro'] = false;
		$result = $this->abilities->execute_create_field_group( array(
			'title'  => 'New Group',
			'fields' => array( array( 'label' => 'Items', 'name' => 'items', 'type' => 'repeater' ) ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acf_pro_required', $result->get_error_code() );
		$this->assertCount( 0, $GLOBALS['karmcp_test']['imported_groups'] );
	}

	public function test_create_group_rejects_unknown_field_type(): void {
		$result = $this->abilities->execute_create_field_group( array(
			'title'  => 'New Group',
			'fields' => array( array( 'label' => 'X', 'name' => 'x', 'type' => 'unknown_type' ) ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_field', $result->get_error_code() );
	}

	public function test_create_group_validates_location_rules(): void {
		$result = $this->abilities->execute_create_field_group( array(
			'title'    => 'New Group',
			'fields'   => array( array( 'label' => 'X', 'name' => 'x', 'type' => 'text' ) ),
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '>=', 'value' => 'post' ) ) ),
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_location', $result->get_error_code() );
	}

	// -------------------------------------------------------------------
	// update-acf-field-group
	// -------------------------------------------------------------------

	public function test_update_group_refuses_local_groups(): void {
		$GLOBALS['karmcp_test']['field_groups'][] = array( 'key' => 'group_json', 'ID' => 9, 'title' => 'From JSON', 'local' => 'json' );

		$result = $this->abilities->execute_update_field_group( array( 'key' => 'group_json', 'title' => 'Renamed' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acf_local_group', $result->get_error_code() );
	}

	public function test_update_group_refuses_field_renames_and_type_changes(): void {
		$GLOBALS['karmcp_test']['field_groups'][] = array( 'key' => 'group_db', 'ID' => 12, 'title' => 'DB Group' );

		foreach ( array( 'name', 'type' ) as $immutable ) {
			$result = $this->abilities->execute_update_field_group( array(
				'key'           => 'group_db',
				'update_fields' => array( array( 'key' => 'field_x', $immutable => 'changed' ) ),
			) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'immutable_field_setting', $result->get_error_code() );
		}
	}

	public function test_update_group_appends_fields_and_updates_settings(): void {
		$GLOBALS['karmcp_test']['field_groups'][]                = array( 'key' => 'group_db', 'ID' => 12, 'title' => 'DB Group' );
		$GLOBALS['karmcp_test']['fields_by_key']['field_headline'] = array( 'key' => 'field_headline', 'name' => 'headline', 'label' => 'Old', 'type' => 'text' );

		$result = $this->abilities->execute_update_field_group( array(
			'key'           => 'group_db',
			'title'         => 'Renamed Group',
			'add_fields'    => array( array( 'label' => 'Extra', 'name' => 'extra', 'type' => 'text' ) ),
			'update_fields' => array( array( 'key' => 'field_headline', 'label' => 'New Label', 'required' => true ) ),
		) );

		$this->assertSame( array( 'title' ), $result['updated'] );
		$this->assertSame( 'extra', $result['fields_added'][0]['name'] );
		$this->assertSame( array( 'field_headline' ), $result['fields_updated'] );

		// The appended field was parented to the group and the setting change kept name/type intact.
		$added = $GLOBALS['karmcp_test']['updated_fields'][0];
		$this->assertSame( 12, $added['parent'] );
		$edited = $GLOBALS['karmcp_test']['updated_fields'][1];
		$this->assertSame( 'New Label', $edited['label'] );
		$this->assertSame( 'headline', $edited['name'] );
		$this->assertSame( 'text', $edited['type'] );
	}

	// -------------------------------------------------------------------
	// Options pages
	// -------------------------------------------------------------------

	public function test_list_options_pages_returns_pages(): void {
		$GLOBALS['karmcp_test']['options_pages'] = array(
			array( 'menu_slug' => 'site-settings', 'page_title' => 'Site Settings', 'post_id' => 'options', 'parent_slug' => '' ),
		);

		$result = $this->abilities->execute_list_options_pages( array() );
		$this->assertTrue( $result['pro'] );
		$this->assertSame( 'site-settings', $result['pages'][0]['menu_slug'] );
	}

	public function test_options_first_write_by_name_resolves_via_located_group(): void {
		// A group located on an options page whose field has NO stored value yet:
		// the name must still resolve to the key through the group index.
		$GLOBALS['karmcp_test']['field_groups'][] = array(
			'key'      => 'group_opts',
			'ID'       => 30,
			'title'    => 'Options Group',
			'active'   => true,
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'site-settings' ) ) ),
		);
		$GLOBALS['karmcp_test']['group_fields']['group_opts'] = array(
			array( 'key' => 'field_footer', 'name' => 'footer_text', 'label' => 'Footer', 'type' => 'text' ),
		);

		$result = $this->abilities->execute_update_fields( array(
			'options_page' => 'options',
			'fields'       => array( 'footer_text' => 'First write' ),
		) );

		$this->assertSame( array( 'footer_text' ), $result['updated'] );
		$this->assertSame( array( 'field_footer', 'First write', 'options' ), $GLOBALS['karmcp_test']['update_field_calls'][0] );
	}

	public function test_options_target_reads_via_field_objects(): void {
		$GLOBALS['karmcp_test']['field_objects']['options'] = array(
			'footer_text' => array( 'key' => 'field_footer', 'name' => 'footer_text', 'label' => 'Footer', 'type' => 'text' ),
		);
		$GLOBALS['karmcp_test']['values']['options'] = array( 'field_footer' => '© 2026' );

		$result = $this->abilities->execute_get_fields( array( 'options_page' => 'options' ) );
		$this->assertSame( '© 2026', $result['fields']['footer_text'] );
	}

	// -------------------------------------------------------------------
	// Custom Post Types (ACF 6.1+)
	// -------------------------------------------------------------------

	public function test_create_post_type_generates_key_and_imports(): void {
		$result = $this->abilities->execute_create_post_type( array(
			'post_type' => 'book',
			'title'     => 'Books',
			'singular'  => 'Book',
			'supports'  => array( 'title', 'editor', 'thumbnail' ),
		) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $GLOBALS['karmcp_test']['imported_types'] );
		$imported = $GLOBALS['karmcp_test']['imported_types'][0];
		$this->assertStringStartsWith( 'post_type_', $imported['key'] );
		$this->assertSame( 'book', $imported['post_type'] );
		$this->assertSame( 'Books', $imported['labels']['name'] );
		$this->assertSame( 'Book', $imported['labels']['singular_name'] );
		$this->assertSame( 'book', $result['post_type'] );
	}

	public function test_create_post_type_rejects_existing_slug(): void {
		$GLOBALS['karmcp_test']['existing_types'][] = 'book';
		$result = $this->abilities->execute_create_post_type( array( 'post_type' => 'book', 'title' => 'Books' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_type_exists', $result->get_error_code() );
	}

	public function test_create_post_type_rejects_reserved_slug(): void {
		$result = $this->abilities->execute_create_post_type( array( 'post_type' => 'page', 'title' => 'Pages' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reserved_slug', $result->get_error_code() );
	}

	public function test_create_post_type_rejects_overlong_slug(): void {
		$result = $this->abilities->execute_create_post_type( array( 'post_type' => str_repeat( 'a', 21 ), 'title' => 'X' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_slug', $result->get_error_code() );
	}

	public function test_update_post_type_rejects_slug_change(): void {
		$GLOBALS['karmcp_test']['acf_post_types']['post_type_x'] = array(
			'key' => 'post_type_x', 'ID' => 210, 'post_type' => 'book', 'title' => 'Books', 'active' => true,
		);
		$result = $this->abilities->execute_update_post_type( array( 'key' => 'post_type_x', 'post_type' => 'novel' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'immutable_slug', $result->get_error_code() );
	}

	public function test_update_post_type_changes_title_and_taxonomies(): void {
		$GLOBALS['karmcp_test']['acf_post_types']['post_type_x'] = array(
			'key' => 'post_type_x', 'ID' => 210, 'post_type' => 'book', 'title' => 'Books',
			'active' => true, 'labels' => array( 'name' => 'Books', 'singular_name' => 'Book' ),
		);
		$result = $this->abilities->execute_update_post_type( array(
			'key'        => 'post_type_x',
			'title'      => 'Publications',
			'taxonomies' => array( 'genre', 'genre', 'author' ),
		) );

		$this->assertIsArray( $result );
		$saved = $GLOBALS['karmcp_test']['updated_internal'][0]['item'];
		$this->assertSame( 'acf-post-type', $GLOBALS['karmcp_test']['updated_internal'][0]['post_type'] );
		$this->assertSame( 'Publications', $saved['title'] );
		$this->assertSame( 'Publications', $saved['labels']['name'] );
		$this->assertSame( array( 'genre', 'author' ), $saved['taxonomies'] ); // deduped, sanitized
	}

	public function test_get_post_type_missing_returns_error(): void {
		$result = $this->abilities->execute_get_post_type( array( 'key' => 'post_type_nope' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_list_post_types_filters_inactive_and_search(): void {
		$GLOBALS['karmcp_test']['acf_post_types'] = array(
			array( 'key' => 'post_type_a', 'ID' => 1, 'post_type' => 'book', 'title' => 'Books', 'active' => true ),
			array( 'key' => 'post_type_b', 'ID' => 2, 'post_type' => 'movie', 'title' => 'Movies', 'active' => false ),
		);
		$active = $this->abilities->execute_list_post_types( array() );
		$this->assertSame( 1, $active['total'] );
		$this->assertSame( 'book', $active['post_types'][0]['post_type'] );

		$all = $this->abilities->execute_list_post_types( array( 'active_only' => false ) );
		$this->assertSame( 2, $all['total'] );

		$search = $this->abilities->execute_list_post_types( array( 'active_only' => false, 'search' => 'movie' ) );
		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 'movie', $search['post_types'][0]['post_type'] );
	}

	// -------------------------------------------------------------------
	// Taxonomies (ACF 6.1+)
	// -------------------------------------------------------------------

	public function test_create_taxonomy_requires_object_type(): void {
		$result = $this->abilities->execute_create_taxonomy( array( 'taxonomy' => 'genre', 'title' => 'Genres', 'object_type' => array() ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	public function test_create_taxonomy_generates_key_and_imports(): void {
		$result = $this->abilities->execute_create_taxonomy( array(
			'taxonomy'    => 'genre',
			'title'       => 'Genres',
			'object_type' => array( 'book' ),
		) );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $GLOBALS['karmcp_test']['imported_taxes'] );
		$imported = $GLOBALS['karmcp_test']['imported_taxes'][0];
		$this->assertStringStartsWith( 'taxonomy_', $imported['key'] );
		$this->assertSame( 'genre', $imported['taxonomy'] );
		$this->assertSame( array( 'book' ), $imported['object_type'] );
		$this->assertTrue( $imported['hierarchical'] ); // default true for taxonomies
	}

	public function test_create_taxonomy_rejects_existing_slug(): void {
		$GLOBALS['karmcp_test']['existing_taxes'][] = 'genre';
		$result = $this->abilities->execute_create_taxonomy( array( 'taxonomy' => 'genre', 'title' => 'Genres', 'object_type' => array( 'book' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'taxonomy_exists', $result->get_error_code() );
	}

	public function test_update_taxonomy_rejects_slug_change_and_updates_object_type(): void {
		$GLOBALS['karmcp_test']['acf_taxonomies']['taxonomy_x'] = array(
			'key' => 'taxonomy_x', 'ID' => 310, 'taxonomy' => 'genre', 'title' => 'Genres',
			'active' => true, 'object_type' => array( 'book' ), 'labels' => array( 'name' => 'Genres' ),
		);
		$rename = $this->abilities->execute_update_taxonomy( array( 'key' => 'taxonomy_x', 'taxonomy' => 'category2' ) );
		$this->assertInstanceOf( WP_Error::class, $rename );
		$this->assertSame( 'immutable_slug', $rename->get_error_code() );

		$ok = $this->abilities->execute_update_taxonomy( array( 'key' => 'taxonomy_x', 'object_type' => array( 'book', 'movie' ) ) );
		$this->assertIsArray( $ok );
		$saved = $GLOBALS['karmcp_test']['updated_internal'][0]['item'];
		$this->assertSame( 'acf-taxonomy', $GLOBALS['karmcp_test']['updated_internal'][0]['post_type'] );
		$this->assertSame( array( 'book', 'movie' ), $saved['object_type'] );
	}

	// -------------------------------------------------------------------
	// CPT/tax permissions
	// -------------------------------------------------------------------

	public function test_cpt_tax_tools_require_manage_options(): void {
		$GLOBALS['karmcp_test']['caps'] = array( 'edit_posts' ); // no manage_options
		$this->assertFalse( $this->abilities->check_manage_permission() );

		$GLOBALS['karmcp_test']['caps'] = array( 'manage_options' );
		$this->assertTrue( $this->abilities->check_manage_permission() );
	}
}
