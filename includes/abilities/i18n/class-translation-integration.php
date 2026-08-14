<?php
/**
 * Base for multilingual-plugin integrations.
 *
 * Polylang and WPML model the same idea — a post exists once per language, and
 * the copies know about each other — behind completely different APIs. The four
 * operations an agent needs are identical either way, so they live here once and
 * each adapter supplies the five primitives underneath: list the languages, read
 * and set a post's language, read and write the translation group.
 *
 * `create-translation` is the operation that earns the file. Translating a page
 * by hand means duplicating it, assigning the new language, linking the pair,
 * and then translating the text — and the first three steps are exactly where it
 * goes wrong, because a copy that was never linked looks finished and is
 * invisible to the language switcher.
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
abstract class KarMCP_Translation_Integration {

	use KarMCP_Operation_Dispatcher;

	/**
	 * Short id used to build the tool names.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Whether the plugin is active.
	 *
	 * @return bool
	 */
	abstract public function is_active(): bool;

	/**
	 * Every configured language.
	 *
	 * @return array<int,array{code:string,name:string,default:bool}>
	 */
	abstract protected function languages(): array;

	/**
	 * A post's language code, or '' when it has none.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	abstract protected function language_of( int $post_id ): string;

	/**
	 * The whole translation group for a post, as code => post id. Includes the
	 * post itself.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,int>
	 */
	abstract protected function translations_of( int $post_id ): array;

	/**
	 * Assigns a language to a post.
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    Language code.
	 * @return true|WP_Error
	 */
	abstract protected function assign_language( int $post_id, string $code );

	/**
	 * Links a set of posts as translations of one another.
	 *
	 * @param array<string,int> $map Language code => post id.
	 * @return true|WP_Error
	 */
	abstract protected function link_group( array $map );

	/**
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->is_active();
	}

	/**
	 * @return string
	 */
	final public function read_tool(): string {
		return 'karmcp/' . $this->id() . '-read';
	}

	/**
	 * @return string
	 */
	final public function write_tool(): string {
		return 'karmcp/' . $this->id() . '-write';
	}

	/**
	 * @return string[]
	 */
	final public function get_ability_names(): array {
		return array( $this->read_tool(), $this->write_tool() );
	}

	/**
	 * Registers both dispatchers.
	 */
	public function register(): void {
		karmcp_register_ability(
			$this->read_tool(),
			array(
				'label'               => $this->label() . ' Read',
				'description'         => sprintf(
					/* translators: %s: plugin label. */
					__( '%s, read side. Operations: list-languages, get-translation-status. Use get-translation-status on a page to see which languages it exists in and which are missing before translating anything. Call with no operation to list them.', 'karmcp' ),
					$this->label()
				),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_read' ),
				'permission_callback' => array( $this, 'can_read' ),
				'input_schema'        => $this->operation_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);

		karmcp_register_ability(
			$this->write_tool(),
			array(
				'label'               => $this->label() . ' Write',
				'description'         => sprintf(
					/* translators: %s: plugin label. */
					__( '%1$s, write side. Operations: create-translation (duplicates a page into another language and links it, so the language switcher finds it), set-post-language, link-translations. create-translation copies the layout and the original text: translate the copy afterwards with the normal content tools. Call with no operation to list them.', 'karmcp' ),
					$this->label()
				),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'run_write' ),
				'permission_callback' => array( $this, 'can_write' ),
				'input_schema'        => $this->operation_schema(),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_read( $input ) {
		return $this->dispatch_operation( 'read', $input );
	}

	/**
	 * @param mixed $input Tool input.
	 * @return mixed
	 */
	public function run_write( $input ) {
		return $this->dispatch_operation( 'write', $input );
	}

	/**
	 * @return bool
	 */
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @return bool
	 */
	public function can_write(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @return bool
	 */
	protected function integration_is_active(): bool {
		return $this->is_active();
	}

	/**
	 * @return string
	 */
	protected function inactive_message(): string {
		return sprintf(
			/* translators: %s: plugin label. */
			__( 'Install and activate %s to use this tool.', 'karmcp' ),
			$this->label()
		);
	}

	/**
	 * @return array<string,array>
	 */
	protected function operations(): array {
		$can_read  = array( $this, 'can_read' );
		$can_write = array( $this, 'can_write' );

		return array(
			'list-languages'         => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_list_languages' ),
				'perm' => $can_read,
				'desc' => 'List the site\'s configured languages: code, name, and which one is the default.',
			),
			'get-translation-status' => array(
				'mode' => 'read',
				'run'  => array( $this, 'op_get_translation_status' ),
				'perm' => $can_read,
				'desc' => 'Translation status of a post: { post_id }. Returns its language, the translations that exist, and the languages still missing.',
			),
			'create-translation'     => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_create_translation' ),
				'perm' => $can_write,
				'desc' => 'Create a translation of a post: { post_id, language, title?, status? }. Duplicates the page with its layout and meta, assigns the target language, and links the two so the language switcher shows it. The copy still holds the original text — translate it afterwards.',
			),
			'set-post-language'      => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_set_post_language' ),
				'perm' => $can_write,
				'desc' => 'Assign a language to an existing post: { post_id, language }.',
			),
			'link-translations'      => array(
				'mode' => 'write',
				'run'  => array( $this, 'op_link_translations' ),
				'perm' => $can_write,
				'desc' => 'Link posts that already exist as translations of each other: { translations: { "es": 12, "en": 34 } }. Use when the copies were made by hand.',
			),
		);
	}

	// -----------------------------------------------------------------------
	// Operations.
	// -----------------------------------------------------------------------

	/**
	 * @param array $args Unused.
	 * @return array
	 */
	public function op_list_languages( array $args ): array {
		$languages = $this->languages();

		return array(
			'languages' => $languages,
			'count'     => count( $languages ),
			'default'   => $this->default_language(),
		);
	}

	/**
	 * @param array $args { post_id }.
	 * @return array|WP_Error
	 */
	public function op_get_translation_status( array $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}

		$existing = $this->translations_of( $post_id );
		$codes    = array_column( $this->languages(), 'code' );
		$missing  = array_values( array_diff( $codes, array_keys( $existing ) ) );

		$translations = array();
		foreach ( $existing as $code => $id ) {
			$translations[] = array(
				'language' => $code,
				'post_id'  => (int) $id,
				'title'    => get_the_title( $id ),
				'status'   => get_post_status( $id ),
				'is_self'  => (int) $id === $post_id,
			);
		}

		return array(
			'post_id'      => $post_id,
			'language'     => $this->language_of( $post_id ),
			'translations' => $translations,
			'missing'      => $missing,
		);
	}

	/**
	 * @param array $args { post_id, language, title?, status? }.
	 * @return array|WP_Error
	 */
	public function op_create_translation( array $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ), array( 'status' => 403 ) );
		}

		$language = $this->validate_language( $args['language'] ?? '' );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$existing = $this->translations_of( $post_id );
		if ( isset( $existing[ $language ] ) ) {
			return new WP_Error(
				'translation_exists',
				sprintf(
					/* translators: 1: language code, 2: existing post id. */
					__( 'A %1$s translation already exists: post %2$d. Edit that one instead of creating a second.', 'karmcp' ),
					$language,
					(int) $existing[ $language ]
				),
				array( 'status' => 409 )
			);
		}

		if ( ! class_exists( 'KarMCP_Post_Duplicator' ) ) {
			return new WP_Error( 'unavailable', __( 'The post duplicator is not available on this install.', 'karmcp' ), array( 'status' => 500 ) );
		}

		$new_id = KarMCP_Post_Duplicator::duplicate(
			$post_id,
			array(
				'title'  => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '',
				'status' => isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'draft',
			)
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$assigned = $this->assign_language( $new_id, $language );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		// The copy has to join the existing group, not start a new one, or the
		// site ends up with two pairs that each know half the story.
		$group              = $existing;
		$group[ $language ] = $new_id;
		$source_language    = $this->language_of( $post_id );
		if ( '' !== $source_language ) {
			$group[ $source_language ] = $post_id;
		}

		$linked = $this->link_group( $group );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return array(
			'created'   => true,
			'post_id'   => $new_id,
			'language'  => $language,
			'source'    => $post_id,
			'status'    => get_post_status( $new_id ),
			'edit_url'  => admin_url( 'post.php?post=' . $new_id . '&action=edit' ),
			'next_step' => __( 'The copy still carries the source language\'s text. Translate it with the normal content tools, then publish it.', 'karmcp' ),
		);
	}

	/**
	 * @param array $args { post_id, language }.
	 * @return array|WP_Error
	 */
	public function op_set_post_language( array $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'karmcp' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to edit this post.', 'karmcp' ), array( 'status' => 403 ) );
		}

		$language = $this->validate_language( $args['language'] ?? '' );
		if ( is_wp_error( $language ) ) {
			return $language;
		}

		$assigned = $this->assign_language( $post_id, $language );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		return array(
			'updated'  => true,
			'post_id'  => $post_id,
			'language' => $language,
		);
	}

	/**
	 * @param array $args { translations }.
	 * @return array|WP_Error
	 */
	public function op_link_translations( array $args ) {
		$map = ( isset( $args['translations'] ) && is_array( $args['translations'] ) ) ? $args['translations'] : array();
		if ( count( $map ) < 2 ) {
			return new WP_Error(
				'invalid_argument',
				__( 'translations must map at least two language codes to post ids, e.g. { "es": 12, "en": 34 }.', 'karmcp' ),
				array( 'status' => 400 )
			);
		}

		$clean = array();
		foreach ( $map as $code => $id ) {
			$language = $this->validate_language( (string) $code );
			if ( is_wp_error( $language ) ) {
				return $language;
			}
			$id = absint( $id );
			if ( ! $id || ! get_post( $id ) ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %s: language code. */
						__( 'No post for language %s.', 'karmcp' ),
						$language
					),
					array( 'status' => 404 )
				);
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'forbidden', __( 'You are not allowed to edit one of these posts.', 'karmcp' ), array( 'status' => 403 ) );
			}
			$clean[ $language ] = $id;
		}

		foreach ( $clean as $code => $id ) {
			$assigned = $this->assign_language( $id, $code );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}

		$linked = $this->link_group( $clean );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}

		return array(
			'linked'       => true,
			'translations' => $clean,
		);
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * The default language code.
	 *
	 * @return string
	 */
	protected function default_language(): string {
		foreach ( $this->languages() as $language ) {
			if ( ! empty( $language['default'] ) ) {
				return $language['code'];
			}
		}
		return '';
	}

	/**
	 * Checks a language code against what the site actually has configured.
	 *
	 * A code the site does not know is the failure worth catching early: both
	 * plugins accept it, store it, and leave a post in a language that has no
	 * switcher entry and no URL — visible nowhere.
	 *
	 * @param mixed $code Raw code.
	 * @return string|WP_Error
	 */
	protected function validate_language( $code ) {
		$code  = strtolower( trim( (string) $code ) );
		$codes = array_column( $this->languages(), 'code' );

		if ( '' === $code ) {
			return new WP_Error(
				'missing_argument',
				sprintf(
					/* translators: %s: comma-separated language codes. */
					__( 'Missing required argument: language. Configured on this site: %s.', 'karmcp' ),
					implode( ', ', $codes )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $code, $codes, true ) ) {
			return new WP_Error(
				'unknown_language',
				sprintf(
					/* translators: 1: given code, 2: comma-separated configured codes. */
					__( 'Language "%1$s" is not configured on this site. Configured: %2$s. Add it in the plugin\'s settings first.', 'karmcp' ),
					$code,
					implode( ', ', $codes )
				),
				array( 'status' => 400 )
			);
		}

		return $code;
	}
}
