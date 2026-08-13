<?php
/**
 * Global settings MCP abilities for Elementor.
 *
 * Registers 2 tools for updating global colors and typography
 * in the Elementor kit (site-wide settings).
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the global settings abilities.
 *
 * @since 1.0.0
 */
class KarMCP_Global_Abilities {

	/**
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param KarMCP_Data $data The data access layer.
	 */
	public function __construct( KarMCP_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/update-global-colors',
			'karmcp/update-global-typography',
		);
	}

	/**
	 * Registers all global abilities.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		$this->register_update_global_colors();
		$this->register_update_global_typography();
	}

	/**
	 * Permission check for global settings (requires manage_options).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// update-global-colors
	// -------------------------------------------------------------------------

	private function register_update_global_colors(): void {
		karmcp_register_ability(
			'karmcp/update-global-colors',
			array(
				'label'               => __( 'Update Global Colors', 'karmcp' ),
				'description'         => __( 'Updates the site-wide color palette in the Elementor kit. Provide an array of color objects with id, title, and color (hex).', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_global_colors' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'colors' => array(
							'type'        => 'array',
							'description' => __( 'Array of color definitions.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'_id'   => array(
										'type'        => 'string',
										'description' => __( 'Unique color ID (e.g. "primary").', 'karmcp' ),
									),
									'title' => array(
										'type'        => 'string',
										'description' => __( 'Human-readable title.', 'karmcp' ),
									),
									'color' => array(
										'type'        => 'string',
										'description' => __( 'Color value in hex format (e.g. "#FF5733").', 'karmcp' ),
									),
								),
								'required' => array( '_id', 'title', 'color' ),
							),
						),
					),
					'required'   => array( 'colors' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the update-global-colors ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	/**
	 * Snapshot the kit's stored settings meta so a global change can be rolled back.
	 *
	 * @param object $kit Active Elementor kit document.
	 * @return array { id, before }.
	 */
	private function snapshot_kit_settings( $kit ): array {
		$kit_id = (int) $kit->get_id();
		return array( 'id' => $kit_id, 'before' => get_post_meta( $kit_id, '_elementor_page_settings', true ) );
	}

	/**
	 * Record a kit-settings change to the change ledger (meta-before-image).
	 *
	 * @param array  $snap    Snapshot from snapshot_kit_settings().
	 * @param string $summary Human summary.
	 */
	private function record_kit_change( array $snap, string $summary ): void {
		if ( class_exists( 'KarMCP_Change_Recorder' ) && (int) $snap['id'] > 0 ) {
			KarMCP_Change_Recorder::record_meta(
				'post',
				(int) $snap['id'],
				array( '_elementor_page_settings' => $snap['before'] ),
				$summary,
				'Elementor kit #' . (int) $snap['id'],
				'globals',
				'update-globals'
			);
		}
	}

	public function execute_update_global_colors( $input ) {
		$colors = $input['colors'] ?? array();

		if ( empty( $colors ) || ! is_array( $colors ) ) {
			return new \WP_Error( 'missing_colors', __( 'The colors parameter is required and must be an array.', 'karmcp' ) );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit || ! $kit->get_id() ) {
			return new \WP_Error( 'kit_not_found', __( 'Active Elementor kit not found.', 'karmcp' ) );
		}

		// Get current kit settings.
		$kit_settings = $kit->get_settings();

		// Merge colors: update existing by _id, add new ones.
		$existing_colors = $kit_settings['custom_colors'] ?? array();
		$existing_map    = array();

		foreach ( $existing_colors as $index => $existing ) {
			if ( isset( $existing['_id'] ) ) {
				$existing_map[ $existing['_id'] ] = $index;
			}
		}

		foreach ( $colors as $color ) {
			$color_id = sanitize_text_field( $color['_id'] ?? '' );
			if ( empty( $color_id ) ) {
				continue;
			}

			$color_entry = array(
				'_id'   => $color_id,
				'title' => sanitize_text_field( $color['title'] ?? '' ),
				'color' => sanitize_hex_color( $color['color'] ?? '' ),
			);

			if ( isset( $existing_map[ $color_id ] ) ) {
				$existing_colors[ $existing_map[ $color_id ] ] = $color_entry;
			} else {
				$existing_colors[] = $color_entry;
			}
		}

		$karmcp_kit_snap = $this->snapshot_kit_settings( $kit );
		$kit->update_settings( array( 'custom_colors' => $existing_colors ) );
		$this->record_kit_change( $karmcp_kit_snap, 'Updated global colors' );

		return array( 'success' => true );
	}

	// -------------------------------------------------------------------------
	// update-global-typography
	// -------------------------------------------------------------------------

	private function register_update_global_typography(): void {
		karmcp_register_ability(
			'karmcp/update-global-typography',
			array(
				'label'               => __( 'Update Global Typography', 'karmcp' ),
				'description'         => __( 'Updates the site-wide typography settings in the Elementor kit.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_global_typography' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'typography' => array(
							'type'        => 'array',
							'description' => __( 'Array of typography definitions.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'_id'                      => array(
										'type'        => 'string',
										'description' => __( 'Unique typography ID (e.g. "primary").', 'karmcp' ),
									),
									'title'                    => array(
										'type'        => 'string',
										'description' => __( 'Human-readable title.', 'karmcp' ),
									),
									'typography_font_family'   => array(
										'type'        => 'string',
										'description' => __( 'Font family name.', 'karmcp' ),
									),
									'typography_font_size'     => array(
										'type'        => 'object',
										'description' => __( 'Font size with size and unit.', 'karmcp' ),
									),
									'typography_font_weight'   => array(
										'type'        => 'string',
										'description' => __( 'Font weight (100-900, normal, bold).', 'karmcp' ),
									),
									'typography_line_height'   => array(
										'type'        => 'object',
										'description' => __( 'Line height with size and unit.', 'karmcp' ),
									),
									'typography_letter_spacing' => array(
										'type'        => 'object',
										'description' => __( 'Letter spacing with size and unit.', 'karmcp' ),
									),
								),
								'required' => array( '_id', 'title' ),
							),
						),
					),
					'required'   => array( 'typography' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the update-global-typography ability.
	 *
	 * @since 1.0.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_update_global_typography( $input ) {
		$typography = $input['typography'] ?? array();

		if ( empty( $typography ) || ! is_array( $typography ) ) {
			return new \WP_Error( 'missing_typography', __( 'The typography parameter is required and must be an array.', 'karmcp' ) );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();

		if ( ! $kit || ! $kit->get_id() ) {
			return new \WP_Error( 'kit_not_found', __( 'Active Elementor kit not found.', 'karmcp' ) );
		}

		$kit_settings      = $kit->get_settings();
		$existing_typo     = $kit_settings['custom_typography'] ?? array();
		$existing_map      = array();

		foreach ( $existing_typo as $index => $existing ) {
			if ( isset( $existing['_id'] ) ) {
				$existing_map[ $existing['_id'] ] = $index;
			}
		}

		$allowed_keys = array(
			'_id', 'title', 'typography_typography',
			'typography_font_family', 'typography_font_size',
			'typography_font_weight', 'typography_text_transform',
			'typography_font_style', 'typography_text_decoration',
			'typography_line_height', 'typography_letter_spacing',
			'typography_word_spacing',
		);

		foreach ( $typography as $typo ) {
			$typo_id = sanitize_text_field( $typo['_id'] ?? '' );
			if ( empty( $typo_id ) ) {
				continue;
			}

			// Build a sanitized entry with only allowed keys.
			$typo_entry = array();
			foreach ( $allowed_keys as $key ) {
				if ( isset( $typo[ $key ] ) ) {
					$typo_entry[ $key ] = $typo[ $key ];
				}
			}

			// Ensure typography_typography is set to 'custom' to activate overrides.
			$typo_entry['typography_typography'] = 'custom';

			if ( isset( $existing_map[ $typo_id ] ) ) {
				$existing_typo[ $existing_map[ $typo_id ] ] = array_merge(
					$existing_typo[ $existing_map[ $typo_id ] ],
					$typo_entry
				);
			} else {
				$existing_typo[] = $typo_entry;
			}
		}

		$karmcp_kit_snap = $this->snapshot_kit_settings( $kit );
		$kit->update_settings( array( 'custom_typography' => $existing_typo ) );
		$this->record_kit_change( $karmcp_kit_snap, 'Updated global typography' );

		return array( 'success' => true );
	}
}
