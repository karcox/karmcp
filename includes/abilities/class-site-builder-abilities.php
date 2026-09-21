<?php
/**
 * build-site — the skeleton of a site in one call.
 *
 * Every piece of this already existed as its own tool; what was missing was the
 * choreography, and the order matters more than any single step: pages before
 * the menu (the menu points at them), the menu before the front page (setting a
 * static front page changes what the menu's home link means), and the palette
 * last, because it is the only step that is safe to fail.
 *
 * Dry-run by default. It reports what it would do, and only writes when asked
 * twice — `apply:true` plus `confirm:true`. A composite that half-succeeds is
 * worse than one that refuses, and this one is deliberately hard to trigger by
 * accident.
 *
 * Idempotent by slug: a page that already exists is adopted rather than
 * duplicated, so re-running a brief after fixing one line does not leave a
 * second copy of everything behind.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements `build-site`.
 *
 * @since 1.1.0
 */
class KarMCP_Site_Builder_Abilities {

	/**
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( 'karmcp/build-site' );
	}

	/**
	 * Site-wide settings and menus are administrator territory, not author.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_pages' );
	}

	/**
	 * Registers the ability.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			'karmcp/build-site',
			array(
				'label'               => __( 'Build Site', 'karmcp' ),
				'description'         => __( 'Creates the skeleton of a site in one call: the pages, the navigation menu pointing at them, the static front page, and the global colour palette and fonts. Dry-run by default — call it once to see the plan, then again with apply:true and confirm:true to write it. Idempotent by slug: a page that already exists is reused, not duplicated, so you can re-run a corrected brief safely. This creates empty pages; fill them afterwards with build-page or the container and widget tools, and check the result with render-page.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'site_name'  => array(
							'type'        => 'string',
							'description' => __( 'Used to name the menu.', 'karmcp' ),
						),
						'pages'      => array(
							'type'        => 'array',
							'description' => __( 'The pages to create, in menu order.', 'karmcp' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'title'      => array( 'type' => 'string' ),
									'slug'       => array(
										'type'        => 'string',
										'description' => __( 'Derived from the title when omitted.', 'karmcp' ),
									),
									'front_page' => array(
										'type'        => 'boolean',
										'description' => __( 'Make this the site\'s static front page. At most one.', 'karmcp' ),
									),
									'in_menu'    => array(
										'type'        => 'boolean',
										'description' => __( 'Include in the menu. Defaults to true for every page except the front page.', 'karmcp' ),
									),
									'status'     => array(
										'type'        => 'string',
										'description' => __( 'publish (default) or draft.', 'karmcp' ),
									),
								),
							),
						),
						'menu'       => array(
							'type'        => 'object',
							'description' => __( 'Menu settings: { name?, location? }. Pass false to skip the menu entirely.', 'karmcp' ),
						),
						'colors'     => array(
							'type'        => 'object',
							'description' => __( 'Global palette as hex values: { primary?, secondary?, text?, accent? }. Needs Elementor; skipped with a note when it is not active.', 'karmcp' ),
						),
						'typography' => array(
							'type'        => 'object',
							'description' => __( 'Global fonts: { headings?, body? } as font family names. Needs Elementor.', 'karmcp' ),
						),
						'apply'      => array(
							'type'        => 'boolean',
							'description' => __( 'false (default) returns the plan without writing anything. true performs it, and also needs confirm:true.', 'karmcp' ),
						),
						'confirm'    => array(
							'type'        => 'boolean',
							'description' => __( 'Required alongside apply:true.', 'karmcp' ),
						),
					),
					'required'   => array( 'pages' ),
				),
			)
		);
	}

	/**
	 * Execute callback.
	 *
	 * @since 1.1.0
	 *
	 * @param array $input Tool input.
	 * @return array|WP_Error
	 */
	public function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! class_exists( 'KarMCP_Site_Plan' ) ) {
			return new WP_Error( 'unavailable', __( 'The site planner is not available on this install.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$plan = KarMCP_Site_Plan::build( $input );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$apply = ! empty( $input['apply'] );

		if ( ! $apply ) {
			return array(
				'applied' => false,
				'plan'    => $this->describe( $plan ),
				'note'    => __( 'Nothing was written. Call again with apply:true and confirm:true to perform this plan.', 'karmcp' ),
			);
		}

		if ( ! isset( $input['confirm'] ) || true !== $input['confirm'] ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Building a site creates pages, a menu and changes the reading settings. Pass confirm:true alongside apply:true.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		return $this->apply( $plan );
	}

	/**
	 * Human-readable summary of a plan.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private function describe( array $plan ): array {
		$pages = array();
		foreach ( $plan['pages'] as $page ) {
			$existing = $this->find_page( $page['slug'] );
			$pages[]  = array(
				'title'      => $page['title'],
				'slug'       => $page['slug'],
				'front_page' => $page['front_page'],
				'in_menu'    => $page['in_menu'],
				'action'     => $existing ? 'reuse' : 'create',
				'post_id'    => $existing ?: null,
			);
		}

		$summary = array(
			'pages'      => $pages,
			'front_page' => $plan['front_page'],
			'menu'       => $plan['menu'] ? array(
				'name'     => $plan['menu']['name'],
				'location' => $plan['menu']['location'] ?: $this->default_location(),
				'items'    => array_column( $plan['menu']['items'], 'title' ),
			) : null,
			'colors'     => $plan['colors'],
			'typography' => $plan['typography'],
		);

		if ( ( $plan['colors'] || $plan['typography'] ) && ! $this->elementor_active() ) {
			$summary['skipped'] = array(
				__( 'Colours and fonts are Elementor global settings, and Elementor is not active here. Those steps will be skipped.', 'karmcp' ),
			);
		}

		return $summary;
	}

	/**
	 * Performs the plan.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	private function apply( array $plan ): array {
		$created = array();
		$reused  = array();
		$ids     = array();
		$notes   = array();

		// 1. Pages first: everything else refers to them.
		foreach ( $plan['pages'] as $page ) {
			$existing = $this->find_page( $page['slug'] );
			if ( $existing ) {
				$ids[ $page['slug'] ] = $existing;
				$reused[]             = array( 'slug' => $page['slug'], 'post_id' => $existing );
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_title'  => $page['title'],
					'post_name'   => $page['slug'],
					'post_type'   => 'page',
					'post_status' => in_array( $page['status'], array( 'publish', 'draft', 'private' ), true ) ? $page['status'] : 'publish',
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				$notes[] = sprintf(
					/* translators: 1: page title, 2: error message. */
					__( 'Could not create "%1$s": %2$s', 'karmcp' ),
					$page['title'],
					$post_id->get_error_message()
				);
				continue;
			}

			$row = array( 'slug' => $page['slug'], 'post_id' => (int) $post_id, 'title' => $page['title'] );
			if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
				$change_id = KarMCP_Change_Recorder::record_post_create(
					(int) $post_id,
					sprintf( 'Created page #%d', (int) $post_id ),
					trim( $page['title'] . ' (#' . (int) $post_id . ')' ),
					'build-site'
				);
				if ( '' !== $change_id ) {
					$row['change_id'] = $change_id;
				}
			}

			$ids[ $page['slug'] ] = (int) $post_id;
			$created[]            = $row;
		}

		// 2. The menu, pointing at the pages that now exist.
		$menu = null;
		if ( $plan['menu'] ) {
			$menu = $this->build_menu( $plan['menu'], $ids, $notes );
		}

		// 3. The front page. Done after the menu so the menu is built against
		// the page's own URL rather than the site root.
		$front = null;
		if ( $plan['front_page'] && isset( $ids[ $plan['front_page'] ] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $ids[ $plan['front_page'] ] );
			$front = $ids[ $plan['front_page'] ];
		}

		// 4. Palette and fonts, the only step that is safe to skip.
		$globals = $this->apply_globals( $plan, $notes );

		return array(
			'applied'    => true,
			'pages'      => array(
				'created' => $created,
				'reused'  => $reused,
			),
			'front_page' => $front,
			'menu'       => $menu,
			'globals'    => $globals,
			'notes'      => $notes,
			'next_step'  => __( 'The pages are empty. Fill them with build-page or the container and widget tools, then check each one with render-page.', 'karmcp' ),
		);
	}

	/**
	 * Creates or reuses the menu and fills it.
	 *
	 * @param array $menu_plan Menu plan.
	 * @param array $ids       slug => post id.
	 * @param array $notes     Notes, by reference.
	 * @return array|null
	 */
	private function build_menu( array $menu_plan, array $ids, array &$notes ): ?array {
		if ( ! function_exists( 'wp_create_nav_menu' ) ) {
			$notes[] = __( 'Menus are not available on this install; the menu step was skipped.', 'karmcp' );
			return null;
		}

		$existing = wp_get_nav_menu_object( $menu_plan['name'] );
		$menu_id  = $existing ? (int) $existing->term_id : (int) wp_create_nav_menu( $menu_plan['name'] );

		if ( is_wp_error( $menu_id ) || ! $menu_id ) {
			$notes[] = __( 'The navigation menu could not be created.', 'karmcp' );
			return null;
		}

		// Whatever is already in the menu stays: re-running a brief should not
		// wipe items a human added between runs.
		$present = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( isset( $item->object_id ) ) {
				$present[ (int) $item->object_id ] = true;
			}
		}

		$added = array();
		foreach ( $menu_plan['items'] as $position => $page ) {
			$post_id = $ids[ $page['slug' ] ] ?? 0;
			if ( ! $post_id || isset( $present[ $post_id ] ) ) {
				continue;
			}

			$item_id = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-title'     => $page['title'],
					'menu-item-object'    => 'page',
					'menu-item-object-id' => $post_id,
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-position'  => $position + 1,
				)
			);

			if ( ! is_wp_error( $item_id ) ) {
				$added[] = $page['title'];
			}
		}

		$location = $menu_plan['location'] ?: $this->default_location();
		$assigned = false;
		if ( '' !== $location && function_exists( 'get_registered_nav_menus' ) && array_key_exists( $location, get_registered_nav_menus() ) ) {
			$locations              = (array) get_theme_mod( 'nav_menu_locations', array() );
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			$assigned = true;
		} elseif ( '' !== $location ) {
			$notes[] = sprintf(
				/* translators: %s: theme location name. */
				__( 'The theme has no menu location called "%s", so the menu was created but not assigned.', 'karmcp' ),
				$location
			);
		}

		return array(
			'menu_id'  => $menu_id,
			'name'     => $menu_plan['name'],
			'added'    => $added,
			'location' => $assigned ? $location : null,
		);
	}

	/**
	 * Writes the global palette and fonts through Elementor's own kit.
	 *
	 * @param array $plan  Plan.
	 * @param array $notes Notes, by reference.
	 * @return array
	 */
	private function apply_globals( array $plan, array &$notes ): array {
		if ( empty( $plan['colors'] ) && empty( $plan['typography'] ) ) {
			return array();
		}

		if ( ! $this->elementor_active() ) {
			$notes[] = __( 'Elementor is not active, so the global colours and fonts were skipped. Everything else was created.', 'karmcp' );
			return array();
		}

		$applied = array();

		if ( ! empty( $plan['colors'] ) && class_exists( 'KarMCP_Global_Abilities' ) ) {
			// The kit stores a list of { _id, title, color }, not a slot map.
			$colors = array();
			foreach ( $plan['colors'] as $slot => $hex ) {
				$colors[] = array(
					'_id'   => $slot,
					'title' => ucfirst( $slot ),
					'color' => $hex,
				);
			}

			$result = $this->call_global( 'execute_update_global_colors', array( 'colors' => $colors ) );
			if ( is_wp_error( $result ) ) {
				$notes[] = sprintf(
					/* translators: %s: error message. */
					__( 'The colour palette could not be written: %s', 'karmcp' ),
					$result->get_error_message()
				);
			} else {
				$applied['colors'] = $plan['colors'];
			}
		}

		if ( ! empty( $plan['typography'] ) && class_exists( 'KarMCP_Global_Abilities' ) ) {
			// Same shape again, and the kit's own slot ids: "primary" is the
			// heading font and "secondary" the body font in every Elementor kit.
			$slots      = array(
				'headings' => 'primary',
				'body'     => 'secondary',
			);
			$typography = array();
			foreach ( $plan['typography'] as $slot => $family ) {
				$typography[] = array(
					'_id'                    => $slots[ $slot ] ?? $slot,
					'title'                  => ucfirst( $slot ),
					'typography_font_family' => $family,
				);
			}

			$result = $this->call_global( 'execute_update_global_typography', array( 'typography' => $typography ) );
			if ( is_wp_error( $result ) ) {
				$notes[] = sprintf(
					/* translators: %s: error message. */
					__( 'The global fonts could not be written: %s', 'karmcp' ),
					$result->get_error_message()
				);
			} else {
				$applied['typography'] = $plan['typography'];
			}
		}

		return $applied;
	}

	/**
	 * Calls a global-settings ability, tolerating a signature this build cannot
	 * verify. The palette is the least important step, so a mismatch becomes a
	 * note rather than losing the pages that were already created.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Input.
	 * @return mixed
	 */
	private function call_global( string $method, array $args ) {
		if ( ! method_exists( 'KarMCP_Global_Abilities', $method ) ) {
			return new WP_Error( 'unsupported', __( 'This build has no global-settings writer.', 'karmcp' ) );
		}

		try {
			$globals = new KarMCP_Global_Abilities( new KarMCP_Data() );
			return $globals->{$method}( $args );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'globals_failed', $e->getMessage() );
		}
	}

	/**
	 * The post id of a published page with this slug, or 0.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private function find_page( string $slug ): int {
		$page = get_page_by_path( $slug );
		return $page ? (int) $page->ID : 0;
	}

	/**
	 * The theme's primary menu location, best effort.
	 *
	 * @return string
	 */
	private function default_location(): string {
		if ( ! function_exists( 'get_registered_nav_menus' ) ) {
			return '';
		}

		$locations = array_keys( (array) get_registered_nav_menus() );
		if ( empty( $locations ) ) {
			return '';
		}

		// Themes name the main one all sorts of things; these are the common
		// ones, in the order they are worth preferring.
		foreach ( array( 'primary', 'main', 'menu-1', 'header', 'primary-menu' ) as $candidate ) {
			if ( in_array( $candidate, $locations, true ) ) {
				return $candidate;
			}
		}

		return (string) reset( $locations );
	}

	/**
	 * @return bool
	 */
	private function elementor_active(): bool {
		return class_exists( 'KarMCP_Bootstrap' ) && KarMCP_Bootstrap::elementor_active();
	}
}
