<?php
/**
 * Condition metabox for the Themer template edit screen.
 *
 * Renders a step-wise cascading condition builder (Relation → Group → Sub-type →
 * [Pro] Object) driven by a type-aware schema, mounted by themer-conditions.js and
 * serialized to a hidden JSON field. Free shows Include + broad leaves; the Pro
 * overlay adds the Exclude relation, specific-object search, and priority via the
 * schema filter. Search/404 templates need no conditions (a note is shown instead).
 * Saving parses the hidden JSON, validates selectors against the registered set,
 * and writes the conditions meta (the index rebuilds on save_post).
 *
 * @package KarMCP
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class KarMCP_Themer_Metabox {

	const NONCE = 'karmcp_themer_conditions_nonce';

	/** Wire admin hooks. */
	public function init(): void {
		add_action( 'add_meta_boxes_' . KarMCP_Themer_CPT::POST_TYPE, array( $this, 'add' ) );
		add_action( 'save_post_' . KarMCP_Themer_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Register the metabox. */
	public function add(): void {
		add_meta_box(
			'karmcp-themer-conditions',
			__( 'KarMCP Themer, Display Conditions', 'karmcp' ),
			array( $this, 'render' ),
			KarMCP_Themer_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/** Whether the Pro condition layer is active (a granular selector is registered). */
	private function is_pro(): bool {
		return in_array( 'post', (array) apply_filters( 'karmcp_themer_selectors', array() ), true );
	}

	/** The selector keys valid for saving (free broad set + any Pro-registered). */
	private function valid_selectors(): array {
		return (array) apply_filters(
			'karmcp_themer_selectors',
			array( 'entire-site', 'all-singular', 'all-archives', 'front-page', 'post-type', 'post-type-archive', 'tax-archive' )
		);
	}

	/**
	 * Enqueue the builder assets on the template edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || KarMCP_Themer_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'karmcp-themer-conditions', KARMCP_URL . 'assets/css/themer-conditions.css', array(), KARMCP_VERSION );
		wp_enqueue_script( 'karmcp-themer-conditions', KARMCP_URL . 'assets/js/themer-conditions.js', array( 'jquery' ), KARMCP_VERSION, true );

		$schemas = array();
		foreach ( KarMCP_Themer_CPT::TYPES as $t ) {
			if ( KarMCP_Themer_Condition_Schema::type_uses_builder( $t ) ) {
				$schemas[ $t ] = KarMCP_Themer_Condition_Schema::for_type( $t );
			}
		}

		wp_localize_script(
			'karmcp-themer-conditions',
			'karmcpThemerCond',
			array(
				'schemasByType' => $schemas,
				'isPro'         => $this->is_pro(),
				'ajax'          => array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => 'karmcp_themer_object_search',
					'nonce'  => wp_create_nonce( 'karmcp_themer_object_search' ),
				),
				'i18n'          => array(
					'include'      => __( 'Include', 'karmcp' ),
					'exclude'      => __( 'Exclude', 'karmcp' ),
					'addCondition' => __( 'Add condition', 'karmcp' ),
					'chooseGroup'  => __( 'Choose…', 'karmcp' ),
					'all'          => __( 'All', 'karmcp' ),
					'searchType'   => __( 'Type 1+ characters…', 'karmcp' ),
					'noBuilder'    => __( 'This template type applies automatically, no display conditions needed.', 'karmcp' ),
					'proHint'      => __( 'The granular condition layer is not active: Exclude rules, per-page / per-category / per-author targeting and priority are unavailable.', 'karmcp' ),
					'remove'       => __( 'Remove', 'karmcp' ),
				),
			)
		);

		// PHP-template picker: rebuild its option list client-side whenever the type
		// select changes (server-side it's only correct for the saved type — a new
		// template starts with no type, so the dropdown must react live).
		if ( class_exists( 'KarMCP_Themer_PHP' ) && KarMCP_Themer_PHP::enabled() ) {
			$templates = array();
			foreach ( KarMCP_Themer_PHP_Store::list_templates() as $tpl ) {
				$templates[] = array( 'id' => (int) $tpl['template_id'], 'title' => (string) $tpl['title'], 'type' => (string) $tpl['type'] );
			}
			wp_localize_script(
				'karmcp-themer-conditions',
				'karmcpThemerPhp',
				array(
					'templates' => $templates,
					'i18n'      => array(
						'none'       => __( ', None (use builder content) , ', 'karmcp' ),
						'chooseType' => __( 'Choose a template type first to list matching PHP templates.', 'karmcp' ),
						'noMatch'    => __( 'No PHP templates match this type yet. Ask your AI agent to create one.', 'karmcp' ),
					),
				)
			);
			wp_add_inline_script( 'karmcp-themer-conditions', self::php_picker_script() );
		}
	}

	/** Inline JS that keeps the "Render with PHP template" select in sync with the type. */
	private static function php_picker_script(): string {
		return <<<'JS'
(function(){
	var data = window.karmcpThemerPhp; if (!data) return;
	function init(){
		var typeSel = document.getElementById('karmcp-themer-type');
		var phpSel  = document.getElementById('karmcp-themer-php');
		var msg     = document.getElementById('karmcp-themer-php-msg');
		if (!typeSel || !phpSel) return;
		var attached = phpSel.getAttribute('data-attached') || '0';
		function rebuild(){
			var type = typeSel.value;
			while (phpSel.firstChild) phpSel.removeChild(phpSel.firstChild);
			var none = document.createElement('option'); none.value='0'; none.textContent=data.i18n.none; phpSel.appendChild(none);
			if (!type){ phpSel.style.display='none'; if(msg){ msg.textContent=data.i18n.chooseType; msg.style.display=''; } return; }
			var matches = (data.templates||[]).filter(function(t){ return t.type===type || t.type==='any'; });
			matches.forEach(function(t){
				var o=document.createElement('option'); o.value=String(t.id); o.textContent=t.title+' ('+t.type+')';
				if (String(t.id)===String(attached)) o.selected=true;
				phpSel.appendChild(o);
			});
			phpSel.style.display='';
			if (msg){ msg.textContent = matches.length ? '' : data.i18n.noMatch; msg.style.display = matches.length ? 'none' : ''; }
		}
		typeSel.addEventListener('change', rebuild);
		rebuild();
	}
	if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
JS;
	}

	/**
	 * Render the metabox: type select + the condition-builder mount + hidden JSON.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$type = (string) get_post_meta( $post->ID, '_karmcp_themer_type', true );
		if ( '' === $type ) {
			// New template: seed from ?karmcp_themer_type= if present, otherwise leave
			// UNSET so the user must consciously choose. Do NOT default to a real
			// type ('header') — that silently mistyped templates (e.g. a template
			// named "Single Page" saved as a header, which then renders in the
			// header slot instead of the body).
			$req  = isset( $_GET['karmcp_themer_type'] ) ? sanitize_key( wp_unslash( $_GET['karmcp_themer_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = in_array( $req, KarMCP_Themer_CPT::TYPES, true ) ? $req : '';
		}
		$cond = get_post_meta( $post->ID, '_karmcp_themer_conditions', true );
		$cond = is_array( $cond ) ? $cond : array( 'include' => array(), 'exclude' => array(), 'priority' => 0 );

		$type_labels = array(
			'header'  => __( 'Header', 'karmcp' ),
			'footer'  => __( 'Footer', 'karmcp' ),
			'single'  => __( 'Single (post/page)', 'karmcp' ),
			'archive' => __( 'Archive', 'karmcp' ),
			'search'  => __( 'Search results', 'karmcp' ),
			'404'     => __( '404 (not found)', 'karmcp' ),
		);

		$php_enabled = class_exists( 'KarMCP_Themer_PHP' ) && KarMCP_Themer_PHP::enabled();

		// Template type + (optional) PHP-template override, side by side in one row.
		echo '<div class="karmcp-themer-field-row" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin:0 0 4px;">';

		// Template type (left column, or full width when the PHP field is hidden).
		echo '<div class="karmcp-themer-field" style="flex:1 1 260px;min-width:240px;">';
		echo '<p style="margin-top:0;"><label for="karmcp-themer-type"><strong>' . esc_html__( 'Template type', 'karmcp' ) . '</strong> <span style="color:#d63638">*</span></label><br>';
		echo '<select id="karmcp-themer-type" name="karmcp_themer_type" class="karmcp-themer-type-select" required style="width:100%;max-width:340px;">';
		printf( '<option value="" %s>%s</option>', selected( $type, '', false ), esc_html__( ', Choose a template type , ', 'karmcp' ) );
		foreach ( KarMCP_Themer_CPT::TYPES as $t ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $t ), selected( $type, $t, false ), esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ) );
		}
		echo '</select>';
		echo '<br><span class="description">' . esc_html__( 'What this template replaces on the front end. A Single template renders in the content area (keeping your theme header/footer); a Header/Footer template replaces the theme\'s header/footer.', 'karmcp' ) . '</span></p>';
		echo '</div>';

		// Optional PHP-template override (right column). The <select> + message are
		// always rendered; php_picker_script() (re)builds the options client-side to
		// match the chosen type — a new template starts with no saved type.
		if ( $php_enabled ) {
			$attached  = (int) get_post_meta( $post->ID, '_karmcp_themer_php_template', true );
			$has_type  = ( '' !== $type );
			echo '<div class="karmcp-themer-field" style="flex:1 1 260px;min-width:240px;">';
			echo '<p style="margin-top:0;"><label for="karmcp-themer-php"><strong>' . esc_html__( 'Render with PHP template', 'karmcp' ) . '</strong></label><br>';
			printf(
				'<select id="karmcp-themer-php" name="karmcp_themer_php_template" data-attached="%d" style="width:100%%;max-width:340px;%s">',
				$attached,
				$has_type ? '' : 'display:none;'
			);
			printf( '<option value="0">%s</option>', esc_html__( ', None (use builder content) , ', 'karmcp' ) );
			if ( $has_type ) {
				foreach ( self::eligible_templates( $type ) as $tpl ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $tpl['template_id'],
						selected( $attached, (int) $tpl['template_id'], false ),
						esc_html( $tpl['title'] . ' (' . $tpl['type'] . ')' )
					);
				}
			}
			echo '</select>';
			printf(
				'<span id="karmcp-themer-php-msg" class="description"%s>%s</span>',
				$has_type ? ' style="display:none;"' : '',
				$has_type ? '' : esc_html__( 'Choose a template type first to list matching PHP templates.', 'karmcp' )
			);
			echo '<br><span class="description">' . esc_html__( 'If selected, this PHP template renders this region instead of the builder content.', 'karmcp' ) . '</span></p>';
			echo '</div>';
		}

		echo '</div>'; // .karmcp-themer-field-row

		// Conflict notice: another template of the same type already targets an
		// overlapping condition. Only one can render a given slot, so warn the admin.
		$conflicts = self::find_conflicts( (int) $post->ID, $type, $cond );
		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning inline karmcp-themer-conflict" style="margin:4px 0 14px;padding:8px 12px;">';
			echo '<p style="margin:.35em 0;"><strong>' . esc_html__( 'Conflict', 'karmcp' ) . '</strong>: ';
			echo esc_html(
				sprintf(
					/* translators: 1: count, 2: template type label */
					_n(
						'%1$d other %2$s template targets an overlapping condition. Only one template can render a given page, the resolver picks the most specific rule, then the highest priority, then the newest.',
						'%1$d other %2$s templates target overlapping conditions. Only one template can render a given page, the resolver picks the most specific rule, then the highest priority, then the newest.',
						count( $conflicts ),
						'karmcp'
					),
					count( $conflicts ),
					strtolower( (string) ( $type_labels[ $type ] ?? $type ) )
				)
			);
			echo '</p><ul style="margin:.2em 0 .35em 1.3em;list-style:disc;">';
			foreach ( $conflicts as $c ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="description">(%3$s)</span></li>',
					esc_url( $c['edit'] ),
					esc_html( '' !== $c['title'] ? $c['title'] : __( '(untitled)', 'karmcp' ) ),
					/* translators: shared condition selectors */
					esc_html( sprintf( __( 'shares: %s', 'karmcp' ), implode( ', ', $c['shared'] ) ) )
				);
			}
			echo '</ul></div>';
		}

		// Mount point for the JS cascading builder + the serialized value it writes.
		echo '<div id="karmcp-themer-conditions-app" class="karmcp-themer-conditions"></div>';
		printf(
			'<input type="hidden" id="karmcp-themer-conditions-json" name="karmcp_themer_conditions_json" value="%s">',
			esc_attr( (string) wp_json_encode( $cond ) )
		);

		if ( ! $this->is_pro() ) {
			echo '<p class="description karmcp-themer-pro-hint">' . esc_html__( 'Only broad Include rules are available: the granular condition layer is not active on this site.', 'karmcp' ) . '</p>';
		}
	}

	/**
	 * Persist type + conditions from the hidden JSON field.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['karmcp_themer_type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['karmcp_themer_type'] ) );
			if ( in_array( $type, KarMCP_Themer_CPT::TYPES, true ) ) {
				update_post_meta( $post_id, '_karmcp_themer_type', $type );
			}
		}

		// PHP-template attachment (feature-gated; type-enforced server-side).
		if ( class_exists( 'KarMCP_Themer_PHP' ) && KarMCP_Themer_PHP::enabled() && isset( $_POST['karmcp_themer_php_template'] ) ) {
			$prev   = (int) get_post_meta( $post_id, '_karmcp_themer_php_template', true );
			$chosen = absint( wp_unslash( $_POST['karmcp_themer_php_template'] ) );
			$ptype  = (string) get_post_meta( $post_id, '_karmcp_themer_type', true );
			if ( $chosen > 0 ) {
				$ok = self::validate_attachment( $chosen, $ptype );
				if ( is_wp_error( $ok ) ) {
					set_transient( 'karmcp_themer_php_notice_' . get_current_user_id(), $ok->get_error_message(), 60 );
					$chosen = $prev; // keep the previous state on rejection
				}
			}
			self::apply_attachment( $post_id, $chosen, $prev );
		}

		if ( ! isset( $_POST['karmcp_themer_conditions_json'] ) ) {
			return;
		}
		$raw     = sanitize_textarea_field( wp_unslash( $_POST['karmcp_themer_conditions_json'] ) );
		$decoded = json_decode( $raw, true );
		update_post_meta( $post_id, '_karmcp_themer_conditions', $this->sanitize_conditions( is_array( $decoded ) ? $decoded : array() ) );
	}

	/**
	 * Sanitize + validate a decoded conditions payload. Drops rules with unknown
	 * (or Pro-only, when unlicensed) selectors; strips Exclude/priority on free.
	 *
	 * @param array $cond Decoded { include, exclude, priority }.
	 * @return array
	 */
	private function sanitize_conditions( array $cond ): array {
		$valid = $this->valid_selectors();
		$pro   = $this->is_pro();

		$clean_rules = static function ( $rules ) use ( $valid ) {
			$out = array();
			foreach ( (array) $rules as $rule ) {
				$object = is_array( $rule ) ? (string) ( $rule['object'] ?? '' ) : '';
				if ( '' === $object ) {
					continue;
				}
				$key = false === strpos( $object, ':' ) ? $object : substr( $object, 0, strpos( $object, ':' ) );
				if ( in_array( $key, $valid, true ) ) {
					$out[] = array( 'object' => sanitize_text_field( $object ) );
				}
			}
			return $out;
		};

		$include  = $clean_rules( $cond['include'] ?? array() );
		$exclude  = $pro ? $clean_rules( $cond['exclude'] ?? array() ) : array();
		$priority = ( $pro && isset( $cond['priority'] ) ) ? (int) $cond['priority'] : 0;

		return array( 'include' => $include, 'exclude' => $exclude, 'priority' => $priority );
	}

	// -------------------------------------------------------------------------
	// Conflict detection (another template of the same type overlaps this one)
	// -------------------------------------------------------------------------

	/**
	 * The include-condition object keys of a conditions payload (e.g. `all-archives`,
	 * `post-type:post`, `post:12`).
	 *
	 * @param array $cond Conditions { include, exclude, priority }.
	 * @return string[]
	 */
	public static function include_objects( array $cond ): array {
		$objs = array();
		foreach ( (array) ( $cond['include'] ?? array() ) as $rule ) {
			if ( is_array( $rule ) && '' !== (string) ( $rule['object'] ?? '' ) ) {
				$objs[] = (string) $rule['object'];
			}
		}
		return array_values( array_unique( $objs ) );
	}

	/**
	 * Other same-type templates whose include conditions overlap this one's — the
	 * resolver can only render one per slot, so an overlap is a conflict the admin
	 * should know about. Matches on shared include-object keys.
	 *
	 * @param int    $current_id Template being edited.
	 * @param string $type       Its type.
	 * @param array  $cond       Its stored conditions.
	 * @return array<int,array{id:int,title:string,shared:string[],edit:string}>
	 */
	public static function find_conflicts( int $current_id, string $type, array $cond ): array {
		if ( '' === $type ) {
			return array();
		}
		$mine = self::include_objects( $cond );
		if ( empty( $mine ) ) {
			return array();
		}
		$q = new WP_Query(
			array(
				'post_type'      => KarMCP_Themer_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'post__not_in'   => array( $current_id ),
				'meta_key'       => '_karmcp_themer_type',
				'meta_value'     => $type,
			)
		);
		$out = array();
		foreach ( (array) $q->posts as $oid ) {
			$oid    = (int) $oid;
			$ocond  = get_post_meta( $oid, '_karmcp_themer_conditions', true );
			$ocond  = is_array( $ocond ) ? $ocond : array();
			$shared = array_values( array_intersect( $mine, self::include_objects( $ocond ) ) );
			if ( ! empty( $shared ) ) {
				$out[] = array(
					'id'     => $oid,
					'title'  => (string) get_the_title( $oid ),
					'shared' => $shared,
					'edit'   => (string) get_edit_post_link( $oid, 'raw' ),
				);
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// PHP-template attachment (feature-gated; the human selection is the gate)
	// -------------------------------------------------------------------------

	/**
	 * Templates attachable to a Themer post of $themer_type (matching type + any).
	 *
	 * @param string $themer_type Themer template type.
	 * @return array<int,array>
	 */
	public static function eligible_templates( string $themer_type ): array {
		if ( ! class_exists( 'KarMCP_Themer_PHP_Store' ) ) {
			return array();
		}
		$out = array();
		foreach ( KarMCP_Themer_PHP_Store::list_templates() as $tpl ) {
			if ( $tpl['type'] === $themer_type || 'any' === $tpl['type'] ) {
				$out[] = $tpl;
			}
		}
		return $out;
	}

	/**
	 * Validate an attach: template exists + type matches (or any).
	 *
	 * @param int    $php_id      PHP-template id.
	 * @param string $themer_type Themer template type.
	 * @return true|WP_Error
	 */
	public static function validate_attachment( int $php_id, string $themer_type ) {
		$summary = KarMCP_Themer_PHP_Store::summary( $php_id );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}
		if ( $summary['type'] !== $themer_type && 'any' !== $summary['type'] ) {
			return new WP_Error( 'type_mismatch', __( 'That PHP template is for a different template type.', 'karmcp' ) );
		}
		return true;
	}

	/**
	 * Persist/clear the attachment meta, then reconcile the compiled file for both ids.
	 *
	 * @param int $themer_id   Themer template id.
	 * @param int $new_php_id  Newly-attached PHP-template id (0 = detach).
	 * @param int $prev_php_id Previously-attached PHP-template id (0 = none).
	 */
	public static function apply_attachment( int $themer_id, int $new_php_id, int $prev_php_id ): void {
		if ( $new_php_id > 0 ) {
			update_post_meta( $themer_id, '_karmcp_themer_php_template', $new_php_id );
		} else {
			delete_post_meta( $themer_id, '_karmcp_themer_php_template' );
		}
		// Reconcile after the meta write so reference_count() reflects the new state.
		if ( $prev_php_id > 0 && $prev_php_id !== $new_php_id ) {
			KarMCP_Themer_PHP_Store::sync_reference( $prev_php_id );
		}
		if ( $new_php_id > 0 ) {
			KarMCP_Themer_PHP_Store::sync_reference( $new_php_id );
		}
	}
}
