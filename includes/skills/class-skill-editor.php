<?php
/**
 * The editing surface for a skill's body.
 *
 * A skill is Markdown. The block editor is not, and handing it Markdown does
 * real damage: it treats the text as HTML, so every `<` and `>` comes back
 * escaped, and it does so again on the next save. The escaping compounds.
 * Measured on this site's `montar-curso` skill, which had already been through
 * it: 113 `&lt;` and 218 `&gt;` where the author had typed `<` and `>`, and one
 * more edit turned those into `&amp;lt;` and `&amp;gt;`. The examples in the
 * guide stop reading as code, and the block quotes stop being block quotes —
 * in a document whose entire job is to be read literally by an agent.
 *
 * So the post type does not declare `editor` support at all. The body gets a
 * plain monospaced textarea, and what comes out of it is stored exactly as it
 * went in.
 *
 * @package KarMCP
 * @since   1.25.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the block editor with a verbatim Markdown field.
 *
 * @since 1.25.2
 */
class KarMCP_Skill_Editor {

	/** The textarea's field name, and the nonce action. */
	const FIELD = 'karmcp_skill_body';

	/** Registers the meta box and the save path. */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'inject_body' ), 10, 2 );
	}

	/** Adds the body field, high on the edit screen where the editor used to be. */
	public static function add_meta_box(): void {
		add_meta_box(
			'karmcp-skill-body',
			__( 'Skill body (Markdown)', 'karmcp' ),
			array( __CLASS__, 'render' ),
			KarMCP_Skill_Store::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the textarea.
	 *
	 * @param WP_Post $post The skill being edited.
	 */
	public static function render( $post ): void {
		wp_nonce_field( self::FIELD, self::FIELD . '_nonce' );

		echo '<p class="description">';
		echo esc_html__( 'Markdown, stored exactly as written. This is what an agent reads, so write it for a reader who will follow it literally.', 'karmcp' );
		echo '</p>';

		printf(
			'<textarea id="%1$s" name="%1$s" rows="32" style="width:100%%;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6;white-space:pre;overflow-wrap:normal;overflow-x:auto;" spellcheck="false">%2$s</textarea>',
			esc_attr( self::FIELD ),
			esc_textarea( $post instanceof WP_Post ? $post->post_content : '' )
		);
	}

	/**
	 * Writes the field into the post data, untouched.
	 *
	 * This runs on `wp_insert_post_data`, which fires *after* the `_save_pre`
	 * filters, so the value skips `wp_filter_post_kses` and lands byte for
	 * byte. That is the entire point — a Markdown document that gets "cleaned"
	 * is a Markdown document that has been altered — and it is why the checks
	 * below are not optional: the nonce proves the request came from the edit
	 * screen, and the capability is the post type's own (`manage_options`),
	 * which is the same bar WordPress applies to anyone editing a skill at all.
	 *
	 * `$_POST` arrives slashed, and `$data` is expected slashed, so the value
	 * passes straight through with no unslash/reslash round trip that could
	 * lose a backslash of its own.
	 *
	 * @param array $data    Sanitized post data, slashed.
	 * @param array $postarr Raw post array, slashed.
	 * @return array
	 */
	public static function inject_body( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) ) {
			return $data;
		}
		if ( KarMCP_Skill_Store::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		// Autosaves and revisions carry no field of ours; leaving early keeps
		// them from blanking the body.
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			return $data;
		}
		if ( ! isset( $_POST[ self::FIELD . '_nonce' ] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::FIELD . '_nonce' ] ) ), self::FIELD )
		) {
			return $data;
		}
		if ( ! current_user_can( KarMCP_Skill_Store::CAP ) ) {
			return $data;
		}

		// Deliberately unsanitized: this is Markdown authored by an administrator,
		// and every sanitizer WordPress offers would alter it — kses escapes the
		// angle brackets, sanitize_textarea_field strips the tags in the examples.
		// The nonce and capability checks above are what makes this safe, and the
		// value never reaches the browser unescaped: get-skill returns it as data
		// and the edit screen re-renders it through esc_textarea().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$data['post_content'] = (string) $_POST[ self::FIELD ];

		return $data;
	}
}
