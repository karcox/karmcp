<?php
/**
 * Guardrails module — site-owner policy over what an agent may write.
 *
 * Every tool already checks a real WordPress capability, so an agent can only do
 * what its user could do by hand. That is the security floor. This module is the
 * layer above it: rules an admin sets for *this* site regardless of capability —
 * a freeze window during business hours, a read-only period, posts nobody may
 * touch, a whole content type left alone.
 *
 * It works on two seams at once, and the pairing is the point:
 *
 *   - `karmcp_discovery_memory` publishes the rules into the agent's discovery
 *     context, so it plans around them instead of finding out by failing.
 *   - `karmcp_before_write` enforces them, for when it tries anyway.
 *
 * The veto is the narrowest choke point in the plugin: the wrapper in
 * KarMCP_Schema_Compat treats any ability without an explicit
 * `readonly` annotation as a write, and it survives compact/dispatcher mode
 * because `call-tool` runs the target through the same wrapped callback.
 *
 * Free tier; opt-in, since an unconfigured policy should change nothing.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guardrails module.
 *
 * @since 1.0.0
 */
class KarMCP_Guardrails_Module extends KarMCP_Module {

	const ID     = 'guardrails';
	const PREFIX = 'karmcp_module_guardrails_';

	/**
	 * Input keys that unambiguously name a post. `id` is deliberately absent:
	 * plenty of tools use it for a snippet, redirect or template row, and
	 * blocking redirect 42 because post 42 is protected would be a bug that
	 * looks like a policy. It is resolved separately, only when it really is a
	 * post.
	 */
	const POST_ID_KEYS = array( 'post_id', 'page_id', 'parent_id' );

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'Guardrails', 'karmcp' );
	}

	public function description(): string {
		return __( 'Site rules an agent cannot talk its way around: freeze writes during business hours, put the site in read-only mode, or protect specific posts and content types. The rules are enforced on every write and published into the agent\'s context, so it plans around them instead of failing into them.', 'karmcp' );
	}

	public function tier(): string {
		return 'free';
	}

	/** Opt-in: an unconfigured policy must not change how anyone's site behaves. */
	public function default_active(): bool {
		return false;
	}

	/**
	 * The knobs. Stored as strings (the Settings API idiom the other modules
	 * use); KarMCP_Guardrails_Policy::from_options() reads them back as types.
	 *
	 * @return array<string,array>
	 */
	public function settings_fields(): array {
		$bool = static function ( $v ) {
			return '1' === (string) $v ? '1' : '0';
		};
		$time = static function ( $v ) {
			return KarMCP_Guardrails_Policy::time_or( (string) $v, '' );
		};
		$days = static function ( $v ) {
			$out = array();
			foreach ( (array) $v as $day ) {
				$n = (int) $day;
				if ( $n >= 1 && $n <= 7 && ! in_array( $n, $out, true ) ) {
					$out[] = $n;
				}
			}
			sort( $out );
			return implode( ',', $out );
		};
		$ids = static function ( $v ) {
			$out = array();
			foreach ( KarMCP_Guardrails_Policy::split( (string) $v ) as $item ) {
				$n = (int) $item;
				if ( $n > 0 && ! in_array( $n, $out, true ) ) {
					$out[] = $n;
				}
			}
			return implode( ', ', $out );
		};
		$slugs = static function ( $v ) {
			$out = array();
			foreach ( KarMCP_Guardrails_Policy::split( (string) $v ) as $item ) {
				$slug = sanitize_key( $item );
				if ( '' !== $slug && ! in_array( $slug, $out, true ) ) {
					$out[] = $slug;
				}
			}
			return implode( ', ', $out );
		};
		$notes = static function ( $v ) {
			return trim( wp_kses_post( (string) $v ) );
		};

		return array(
			self::PREFIX . 'read_only'         => array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => $bool,
			),
			self::PREFIX . 'block_destructive' => array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => $bool,
			),
			self::PREFIX . 'freeze'            => array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => $bool,
			),
			self::PREFIX . 'freeze_from'       => array(
				'type'              => 'string',
				'default'           => '09:00',
				'sanitize_callback' => $time,
			),
			self::PREFIX . 'freeze_to'         => array(
				'type'              => 'string',
				'default'           => '19:00',
				'sanitize_callback' => $time,
			),
			self::PREFIX . 'freeze_days'       => array(
				'type'              => 'string',
				'default'           => '1,2,3,4,5',
				'sanitize_callback' => $days,
			),
			self::PREFIX . 'protected_posts'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => $ids,
			),
			self::PREFIX . 'protected_types'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => $slugs,
			),
			self::PREFIX . 'notes'             => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => $notes,
			),
		);
	}

	/**
	 * Current policy, typed.
	 *
	 * @return array
	 */
	public function current_policy(): array {
		$raw = array();
		foreach ( array_keys( $this->settings_fields() ) as $key ) {
			$short         = substr( $key, strlen( self::PREFIX ) );
			$raw[ $short ] = get_option( $key, '' );
		}
		return KarMCP_Guardrails_Policy::from_options( $raw );
	}

	/** Wire the module's runtime hooks. Called only when active + available. */
	public function register(): void {
		add_filter( 'karmcp_before_write', array( $this, 'veto_write' ), 10, 3 );
		add_filter( 'karmcp_discovery_memory', array( $this, 'discovery_memory' ) );
	}

	/**
	 * The `karmcp_before_write` veto.
	 *
	 * @param null|WP_Error $veto  Null to allow; WP_Error from an earlier listener.
	 * @param string        $name  Ability name.
	 * @param array         $input Ability input.
	 * @return null|WP_Error
	 */
	public function veto_write( $veto, $name, $input ) {
		// Someone already blocked it — don't overwrite their reason.
		if ( is_wp_error( $veto ) ) {
			return $veto;
		}

		$name  = (string) $name;
		$input = is_array( $input ) ? $input : array();

		// Compact mode: `call-tool` is itself a non-readonly ability, so the veto
		// fires for the envelope before it fires for the target. Judging the
		// envelope would block reads — an agent calling list-posts through
		// call-tool would be refused by a write rule. Let it through: the target
		// runs via the same wrapped callback, so policy still applies, with the
		// real tool name and the real arguments.
		if ( 'karmcp/call-tool' === $name ) {
			return null;
		}

		$post_ids = $this->post_ids( $input );

		return KarMCP_Guardrails_Policy::evaluate(
			$this->current_policy(),
			array(
				'tool'        => $name,
				'destructive' => $this->is_destructive( $name ),
				'post_ids'    => $post_ids,
				'post_types'  => $this->post_types( $post_ids, $input ),
				'time'        => (string) current_time( 'H:i' ),
				'day'         => (int) current_time( 'N' ),
			)
		);
	}

	/**
	 * Publish the active policy into the agent's discovery context.
	 *
	 * @param string $memory Existing memory block.
	 * @return string
	 */
	public function discovery_memory( $memory ): string {
		$block = KarMCP_Guardrails_Policy::context_block( $this->current_policy() );
		$memory = (string) $memory;

		if ( '' === $block ) {
			return $memory;
		}
		return ( '' === trim( $memory ) ) ? $block : $memory . "\n\n" . $block;
	}

	/**
	 * Post IDs an input refers to.
	 *
	 * `id` is only counted when it actually resolves to a post, so a redirect or
	 * snippet id never collides with a protected post id.
	 *
	 * @param array $input Ability input.
	 * @return int[]
	 */
	private function post_ids( array $input ): array {
		$found = array();

		foreach ( self::POST_ID_KEYS as $key ) {
			if ( isset( $input[ $key ] ) && (int) $input[ $key ] > 0 ) {
				$found[] = (int) $input[ $key ];
			}
		}

		if ( isset( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			foreach ( $input['post_ids'] as $id ) {
				if ( (int) $id > 0 ) {
					$found[] = (int) $id;
				}
			}
		}

		if ( isset( $input['id'] ) && (int) $input['id'] > 0 && function_exists( 'get_post' ) && get_post( (int) $input['id'] ) ) {
			$found[] = (int) $input['id'];
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Post types in play: the types of the referenced posts, plus any type named
	 * outright in the input (a create call has no post id yet).
	 *
	 * @param int[] $post_ids Referenced post IDs.
	 * @param array $input    Ability input.
	 * @return string[]
	 */
	private function post_types( array $post_ids, array $input ): array {
		$types = array();

		if ( function_exists( 'get_post' ) ) {
			foreach ( $post_ids as $id ) {
				$post = get_post( $id );
				if ( $post && isset( $post->post_type ) && '' !== (string) $post->post_type ) {
					$types[] = (string) $post->post_type;
				}
			}
		}

		if ( isset( $input['post_type'] ) && is_string( $input['post_type'] ) && '' !== $input['post_type'] ) {
			$types[] = $input['post_type'];
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Whether an ability is annotated destructive.
	 *
	 * @param string $name Ability name.
	 * @return bool
	 */
	private function is_destructive( string $name ): bool {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return false;
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability || ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}
		$meta = (array) $ability->get_meta();
		return ! empty( $meta['annotations']['destructive'] );
	}

	/** Render the knobs inside the module card. */
	public function render_settings(): void {
		$policy = $this->current_policy();
		include KARMCP_DIR . 'includes/modules/guardrails/settings-fields.php';
	}

	/**
	 * Whether the module is active (static helper for callers that run before
	 * the module's init:5 boot). Reads the active-modules option directly.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$active = (array) get_option( KarMCP_Module::OPTION_ACTIVE, array() );
		return in_array( self::ID, $active, true );
	}
}
