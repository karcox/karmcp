<?php
/**
 * WooCommerce integration — two dispatcher tools (woo-read / woo-write) over
 * WooCommerce's own CRUD API.
 *
 * Scope is products and the store settings a page builder needs to reason about
 * a shop. Orders, refunds and customers are deliberately out: they are the
 * money and personal-data surface, and nothing about building a website needs
 * an agent reading them.
 *
 * Everything goes through `wc_get_product()` / `WC_Product::save()` and the term
 * API, never the posts or lookup tables. WooCommerce keeps three tables in sync
 * on save (posts, postmeta, wc_product_meta_lookup) and a direct write leaves a
 * product that the shop queries cannot find.
 *
 * @package KarMCP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 1.1.0
 */
class KarMCP_Woo_Integration {

	use KarMCP_Operation_Dispatcher;

	/**
	 * Hard cap on a listing, whatever the caller asks for.
	 */
	const MAX_LIMIT = 100;

	/**
	 * Whether WooCommerce is active.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public static function woo_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * @return bool
	 */
	public function is_available(): bool {
		return self::woo_active();
	}

	/**
	 * @return string
	 */
	public function read_tool(): string {
		return 'karmcp/woo-read';
	}

	/**
	 * @return string
	 */
	public function write_tool(): string {
		return 'karmcp/woo-write';
	}

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array( $this->read_tool(), $this->write_tool() );
	}

	/**
	 * Registers both dispatchers.
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		karmcp_register_ability(
			$this->read_tool(),
			array(
				'label'               => __( 'WooCommerce Read', 'karmcp' ),
				'description'         => __( 'WooCommerce products and store setup, read side. Operations: list-products, get-product, list-product-categories, get-store-setup. Call with no operation to list them with their arguments. Read-only.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'input_schema'        => $this->dispatch_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		karmcp_register_ability(
			$this->write_tool(),
			array(
				'label'               => __( 'WooCommerce Write', 'karmcp' ),
				'description'         => __( 'WooCommerce products, write side. Operations: create-product, update-product, set-product-terms, delete-product. Prices accept any human format ("19,90", "1.299,00", 19.9) and are normalized. delete-product needs confirm:true. Call with no operation to list them with their arguments.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_write' ),
				'permission_callback' => array( $this, 'can_write' ),
				'input_schema'        => $this->dispatch_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Coarse read gate.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Coarse write gate. The real capability is WooCommerce's own, re-checked
	 * per operation.
	 *
	 * @return bool
	 */
	public function can_write(): bool {
		return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_read( $input ) {
		return $this->dispatch( 'read', $input );
	}

	/**
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_write( $input ) {
		return $this->dispatch( 'write', $input );
	}

	/**
	 * The operation map.
	 *
	 * @return array<string,array>
	 */
	protected function operations(): array {
		$can_read   = static function (): bool {
			return current_user_can( 'edit_posts' );
		};
		$can_write  = static function (): bool {
			return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
		};
		$can_delete = static function (): bool {
			return current_user_can( 'delete_products' ) || current_user_can( 'manage_woocommerce' );
		};

		return array(
			'list-products'           => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_products' ),
				'perm' => $can_read,
				'desc' => 'List products: { search?, status?, type?, category?, sku?, limit?, page?, orderby? }. Returns id, name, sku, type, status, prices, stock.',
			),
			'get-product'             => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_product' ),
				'perm' => $can_read,
				'desc' => 'Get one product in full by { product_id } or { sku }.',
			),
			'list-product-categories' => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_product_categories' ),
				'perm' => $can_read,
				'desc' => 'List product categories with their ids, slugs, parents and product counts.',
			),
			'get-store-setup'         => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_store_setup' ),
				'perm' => $can_read,
				'desc' => 'Store basics worth knowing before building shop pages: currency and its formatting, base location, the shop/cart/checkout/account page ids, catalog and stock settings.',
			),
			'create-product'          => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_create_product' ),
				'perm' => $can_write,
				'desc' => 'Create a product: { name, type?, regular_price?, sale_price?, sku?, description?, short_description?, status?, categories?, tags?, image_ids?, manage_stock?, stock_quantity?, stock_status?, virtual?, downloadable?, featured?, weight?, length?, width?, height?, external_url?, button_text? }. Types: simple (default), grouped, external.',
			),
			'update-product'          => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_update_product' ),
				'perm' => $can_write,
				'desc' => 'Update a product by { product_id } (or { sku }) plus any of the create-product fields. Only what you send changes. Pass sale_price:"" to clear a discount.',
			),
			'set-product-terms'       => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_set_product_terms' ),
				'perm' => $can_write,
				'desc' => 'Set a product\'s categories and/or tags: { product_id, categories?, tags?, append? }. Names that do not exist yet are created. append:true adds to what is already there instead of replacing it.',
			),
			'delete-product'          => array(
				'mode'    => 'write',
				'run'     => array( $this, 'op_delete_product' ),
				'perm'    => $can_delete,
				'confirm' => true,
				'desc'    => 'Delete a product: { product_id, force? }. Goes to the trash unless force:true. Needs confirm:true.',
			),
		);
	}

	// -----------------------------------------------------------------------
	// Read operations.
	// -----------------------------------------------------------------------

	/**
	 * @param array $args Operation arguments.
	 * @return array|WP_Error
	 */
	public function op_list_products( array $args ) {
		$query = array(
			'limit'    => isset( $args['limit'] ) ? max( 1, min( self::MAX_LIMIT, absint( $args['limit'] ) ) ) : 20,
			'page'     => isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1,
			'paginate' => true,
		);

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}
		if ( ! empty( $args['status'] ) ) {
			$query['status'] = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['type'] ) ) {
			$query['type'] = sanitize_key( (string) $args['type'] );
		}
		if ( ! empty( $args['sku'] ) ) {
			$query['sku'] = sanitize_text_field( (string) $args['sku'] );
		}
		if ( ! empty( $args['category'] ) ) {
			// wc_get_products takes category slugs, not ids or names.
			$query['category'] = array_map( 'sanitize_title', (array) $args['category'] );
		}
		if ( ! empty( $args['orderby'] ) ) {
			$query['orderby'] = sanitize_key( (string) $args['orderby'] );
		}

		$results  = wc_get_products( $query );
		$products = is_object( $results ) && isset( $results->products ) ? $results->products : (array) $results;

		$out = array();
		foreach ( $products as $product ) {
			$out[] = $this->summarize( $product );
		}

		return array(
			'products' => $out,
			'count'    => count( $out ),
			'total'    => is_object( $results ) && isset( $results->total ) ? (int) $results->total : count( $out ),
			'pages'    => is_object( $results ) && isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1,
		);
	}

	/**
	 * @param array $args { product_id | sku }.
	 * @return array|WP_Error
	 */
	public function op_get_product( array $args ) {
		$product = $this->resolve_product( $args );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$data = $this->summarize( $product );

		$data['description']       = $product->get_description();
		$data['short_description'] = $product->get_short_description();
		$data['categories']        = $this->terms_of( $product->get_id(), 'product_cat' );
		$data['tags']              = $this->terms_of( $product->get_id(), 'product_tag' );
		$data['image_ids']         = array_values(
			array_filter(
				array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', (array) $product->get_gallery_image_ids() ) )
			)
		);
		$data['virtual']           = $product->is_virtual();
		$data['downloadable']      = $product->is_downloadable();
		$data['featured']          = $product->is_featured();
		$data['weight']            = $product->get_weight();
		$data['dimensions']        = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);
		$data['catalog_visibility'] = $product->get_catalog_visibility();

		if ( $product->is_type( 'external' ) && method_exists( $product, 'get_product_url' ) ) {
			$data['external_url'] = $product->get_product_url();
			$data['button_text']  = $product->get_button_text();
		}

		return $data;
	}

	/**
	 * @param array $args Unused.
	 * @return array
	 */
	public function op_list_product_categories( array $args ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$out = array();
		foreach ( ( is_wp_error( $terms ) ? array() : (array) $terms ) as $term ) {
			$out[] = array(
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
				'count'  => (int) $term->count,
			);
		}

		return array(
			'categories' => $out,
			'count'      => count( $out ),
		);
	}

	/**
	 * @param array $args Unused.
	 * @return array
	 */
	public function op_get_store_setup( array $args ): array {
		return array(
			'currency'       => array(
				'code'               => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
				'symbol'            => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '',
				'position'          => (string) get_option( 'woocommerce_currency_pos' ),
				'thousand_separator' => (string) get_option( 'woocommerce_price_thousand_sep' ),
				'decimal_separator' => (string) get_option( 'woocommerce_price_decimal_sep' ),
				'decimals'          => (int) get_option( 'woocommerce_price_num_decimals' ),
			),
			'location'       => array(
				'store_country'  => (string) get_option( 'woocommerce_default_country' ),
				'selling_to'     => (string) get_option( 'woocommerce_allowed_countries' ),
				'calc_taxes'     => 'yes' === get_option( 'woocommerce_calc_taxes' ),
				'prices_include_tax' => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
			),
			'pages'          => array(
				'shop'      => (int) wc_get_page_id( 'shop' ),
				'cart'      => (int) wc_get_page_id( 'cart' ),
				'checkout'  => (int) wc_get_page_id( 'checkout' ),
				'myaccount' => (int) wc_get_page_id( 'myaccount' ),
				'terms'     => (int) wc_get_page_id( 'terms' ),
			),
			'catalog'        => array(
				'products_per_row'  => (int) get_option( 'woocommerce_catalog_columns' ),
				'rows_per_page'     => (int) get_option( 'woocommerce_catalog_rows' ),
				'reviews_enabled'   => 'yes' === get_option( 'woocommerce_enable_reviews' ),
			),
			'stock'          => array(
				'manage_stock'        => 'yes' === get_option( 'woocommerce_manage_stock' ),
				'low_stock_amount'    => (int) get_option( 'woocommerce_notify_low_stock_amount' ),
				'hide_out_of_stock'   => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ),
			),
			'product_count'  => (int) wp_count_posts( 'product' )->publish,
			'woo_version'    => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		);
	}

	// -----------------------------------------------------------------------
	// Write operations.
	// -----------------------------------------------------------------------

	/**
	 * @param array $args Product fields.
	 * @return array|WP_Error
	 */
	public function op_create_product( array $args ) {
		$fields = KarMCP_Woo_Product_Input::normalize( $args, false );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! empty( $fields['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$existing = (int) wc_get_product_id_by_sku( $fields['sku'] );
			if ( $existing > 0 ) {
				return new WP_Error(
					'duplicate_sku',
					sprintf(
						/* translators: 1: SKU, 2: existing product id. */
						__( 'SKU "%1$s" already belongs to product %2$d. WooCommerce requires SKUs to be unique.', 'karmcp' ),
						$fields['sku'],
						$existing
					),
					array( 'status' => 409 )
				);
			}
		}

		$product = $this->instantiate( $fields['type'] ?? 'simple' );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$this->apply( $product, $fields );

		$id = $product->save();
		if ( ! $id ) {
			return new WP_Error( 'create_failed', __( 'WooCommerce did not save the product.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$this->apply_terms( $id, $fields, false );

		return array(
			'created'   => true,
			'product'   => $this->summarize( wc_get_product( $id ) ),
			'edit_url'  => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			'next_step' => __( 'Product pages are rendered by WooCommerce templates, so check the result with render-page on the shop page or the product itself.', 'karmcp' ),
		);
	}

	/**
	 * @param array $args { product_id | sku } + fields.
	 * @return array|WP_Error
	 */
	public function op_update_product( array $args ) {
		$product = $this->resolve_product( $args );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$fields = KarMCP_Woo_Product_Input::normalize( $args, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// A type change means a different product class, and swapping it on a
		// live product would drop type-specific data. Refuse rather than lose it.
		if ( isset( $fields['type'] ) && $fields['type'] !== $product->get_type() ) {
			return new WP_Error(
				'type_change_unsupported',
				sprintf(
					/* translators: 1: current type, 2: requested type. */
					__( 'This product is a %1$s and changing it to %2$s would discard its type-specific data. Change the type in the WooCommerce editor.', 'karmcp' ),
					$product->get_type(),
					$fields['type']
				),
				array( 'status' => 409 )
			);
		}
		unset( $fields['type'] );

		// A sale price sent alone still has to be checked against the price
		// already stored, which the pure validator never sees.
		if ( isset( $fields['sale_price'] ) && '' !== $fields['sale_price'] && ! isset( $fields['regular_price'] ) ) {
			$regular = (float) $product->get_regular_price();
			if ( $regular > 0 && (float) $fields['sale_price'] > $regular ) {
				return new WP_Error(
					'invalid_argument',
					sprintf(
						/* translators: 1: sale price, 2: stored regular price. */
						__( 'sale_price (%1$s) is higher than this product\'s regular price (%2$s), so WooCommerce would never apply it.', 'karmcp' ),
						$fields['sale_price'],
						(string) $regular
					),
					array( 'status' => 400 )
				);
			}
		}

		if ( isset( $fields['sku'] ) && $fields['sku'] !== $product->get_sku() && '' !== $fields['sku'] && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$existing = (int) wc_get_product_id_by_sku( $fields['sku'] );
			if ( $existing > 0 && $existing !== $product->get_id() ) {
				return new WP_Error(
					'duplicate_sku',
					sprintf(
						/* translators: 1: SKU, 2: existing product id. */
						__( 'SKU "%1$s" already belongs to product %2$d.', 'karmcp' ),
						$fields['sku'],
						$existing
					),
					array( 'status' => 409 )
				);
			}
		}

		$this->apply( $product, $fields );
		$product->save();

		$this->apply_terms( $product->get_id(), $fields, false );

		return array(
			'updated' => true,
			'product' => $this->summarize( wc_get_product( $product->get_id() ) ),
			'changed' => array_keys( $fields ),
		);
	}

	/**
	 * @param array $args { product_id, categories?, tags?, append? }.
	 * @return array|WP_Error
	 */
	public function op_set_product_terms( array $args ) {
		$product = $this->resolve_product( $args );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( ! isset( $args['categories'] ) && ! isset( $args['tags'] ) ) {
			return new WP_Error( 'missing_argument', __( 'Send categories, tags, or both.', 'karmcp' ), array( 'status' => 400 ) );
		}

		$fields = KarMCP_Woo_Product_Input::normalize(
			array_intersect_key( $args, array_flip( array( 'categories', 'tags' ) ) ),
			true
		);
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$this->apply_terms( $product->get_id(), $fields, ! empty( $args['append'] ) );

		return array(
			'updated'    => true,
			'product_id' => $product->get_id(),
			'categories' => $this->terms_of( $product->get_id(), 'product_cat' ),
			'tags'       => $this->terms_of( $product->get_id(), 'product_tag' ),
		);
	}

	/**
	 * @param array $args { product_id, force? }.
	 * @return array|WP_Error
	 */
	public function op_delete_product( array $args ) {
		$product = $this->resolve_product( $args );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$id    = $product->get_id();
		$force = ! empty( $args['force'] );

		if ( ! $product->delete( $force ) ) {
			return new WP_Error( 'delete_failed', __( 'WooCommerce did not delete the product.', 'karmcp' ), array( 'status' => 500 ) );
		}

		return array(
			'deleted'    => true,
			'product_id' => $id,
			'permanent'  => $force,
		);
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/**
	 * Builds an empty product object of the requested type.
	 *
	 * @param string $type Product type.
	 * @return WC_Product|WP_Error
	 */
	private function instantiate( string $type ) {
		$classes = array(
			'simple'   => 'WC_Product_Simple',
			'grouped'  => 'WC_Product_Grouped',
			'external' => 'WC_Product_External',
		);

		$class = $classes[ $type ] ?? '';
		if ( '' === $class || ! class_exists( $class ) ) {
			return new WP_Error(
				'unsupported_type',
				sprintf(
					/* translators: %s: product type. */
					__( 'This WooCommerce install has no product class for type "%s".', 'karmcp' ),
					$type
				),
				array( 'status' => 409 )
			);
		}

		return new $class();
	}

	/**
	 * Writes normalized fields onto a product object. Does not save.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $fields  Normalized fields.
	 */
	private function apply( $product, array $fields ): void {
		$setters = array(
			'name'               => 'set_name',
			'description'        => 'set_description',
			'short_description'  => 'set_short_description',
			'sku'                => 'set_sku',
			'status'             => 'set_status',
			'catalog_visibility' => 'set_catalog_visibility',
			'regular_price'      => 'set_regular_price',
			'sale_price'         => 'set_sale_price',
			'manage_stock'       => 'set_manage_stock',
			'stock_quantity'     => 'set_stock_quantity',
			'stock_status'       => 'set_stock_status',
			'virtual'            => 'set_virtual',
			'downloadable'       => 'set_downloadable',
			'featured'           => 'set_featured',
			'weight'             => 'set_weight',
			'length'             => 'set_length',
			'width'              => 'set_width',
			'height'             => 'set_height',
			'external_url'       => 'set_product_url',
			'button_text'        => 'set_button_text',
		);

		foreach ( $setters as $field => $setter ) {
			if ( array_key_exists( $field, $fields ) && method_exists( $product, $setter ) ) {
				$product->{$setter}( $fields[ $field ] );
			}
		}

		if ( isset( $fields['image_ids'] ) ) {
			$ids = $fields['image_ids'];
			$product->set_image_id( $ids ? array_shift( $ids ) : '' );
			$product->set_gallery_image_ids( $ids );
		}
	}

	/**
	 * Resolves category and tag names or ids to term ids, creating what is
	 * missing, and assigns them.
	 *
	 * @param int   $product_id Product id.
	 * @param array $fields     Normalized fields.
	 * @param bool  $append     Add to the existing terms instead of replacing.
	 */
	private function apply_terms( int $product_id, array $fields, bool $append ): void {
		$taxonomies = array(
			'categories' => 'product_cat',
			'tags'       => 'product_tag',
		);

		foreach ( $taxonomies as $field => $taxonomy ) {
			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}

			$ids = array();
			foreach ( $fields[ $field ] as $term ) {
				$resolved = $this->resolve_term( $term, $taxonomy );
				if ( $resolved > 0 ) {
					$ids[] = $resolved;
				}
			}

			wp_set_object_terms( $product_id, $ids, $taxonomy, $append );
		}
	}

	/**
	 * Finds a term by id, slug or name, creating it when it is a name we have
	 * never seen. Building a shop means inventing its categories as you go.
	 *
	 * @param int|string $term     Term id, slug or name.
	 * @param string     $taxonomy Taxonomy.
	 * @return int Term id, or 0.
	 */
	private function resolve_term( $term, string $taxonomy ): int {
		if ( is_int( $term ) || ctype_digit( (string) $term ) ) {
			$found = get_term( (int) $term, $taxonomy );
			return ( $found && ! is_wp_error( $found ) ) ? (int) $found->term_id : 0;
		}

		$name = trim( (string) $term );
		if ( '' === $name ) {
			return 0;
		}

		foreach ( array( 'slug', 'name' ) as $field ) {
			$found = get_term_by( $field, 'slug' === $field ? sanitize_title( $name ) : $name, $taxonomy );
			if ( $found && ! is_wp_error( $found ) ) {
				return (int) $found->term_id;
			}
		}

		$created = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * The compact product view used by every listing.
	 *
	 * @param WC_Product|null $product Product.
	 * @return array
	 */
	private function summarize( $product ): array {
		if ( ! $product ) {
			return array();
		}

		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'price'          => $product->get_price(),
			'on_sale'        => $product->is_on_sale(),
			'manage_stock'   => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
			'permalink'      => get_permalink( $product->get_id() ),
		);
	}

	/**
	 * A product's terms in one taxonomy.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return array<int,array>
	 */
	private function terms_of( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}

		return $out;
	}

	/**
	 * Resolves the product an operation is about.
	 *
	 * @param array $args Operation arguments.
	 * @return WC_Product|WP_Error
	 */
	private function resolve_product( array $args ) {
		$id = isset( $args['product_id'] ) ? absint( $args['product_id'] ) : 0;

		if ( ! $id && ! empty( $args['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( sanitize_text_field( (string) $args['sku'] ) );
		}

		if ( ! $id ) {
			return new WP_Error( 'missing_argument', __( 'Missing required argument: product_id (or sku).', 'karmcp' ), array( 'status' => 400 ) );
		}

		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				sprintf(
					/* translators: %d: product id. */
					__( 'No product with id %d.', 'karmcp' ),
					$id
				),
				array( 'status' => 404 )
			);
		}

		return $product;
	}

	/**
	 * @return bool
	 */
	protected function integration_is_active(): bool {
		return self::woo_active();
	}

	/**
	 * @return string
	 */
	protected function inactive_message(): string {
		return __( 'Install and activate WooCommerce to use this tool.', 'karmcp' );
	}

	/**
	 * @param string $mode  read|write.
	 * @param mixed  $input Tool input.
	 * @return mixed
	 */
	private function dispatch( string $mode, $input ) {
		return $this->dispatch_operation( $mode, $input );
	}

	/**
	 * @return array
	 */
	private function dispatch_schema(): array {
		return $this->operation_schema();
	}
}
