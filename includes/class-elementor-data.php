<?php
/**
 * Elementor data access layer.
 *
 * Wraps Elementor internals to provide a clean API for reading and writing
 * Elementor page data, widget registrations, and element trees.
 *
 * @package KarMCP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access layer wrapping Elementor's internal APIs.
 *
 * @since 1.0.0
 */
class KarMCP_Data {

	/** Elementor 4.2's rendered-element cache meta (Document::CACHE_META_KEY). */
	const ELEMENT_CACHE_META = '_elementor_element_cache';

	/**
	 * Register global hooks. Invalidates Elementor's rendered-element cache
	 * (`_elementor_element_cache`) whenever a post's `_elementor_data` changes,
	 * from ANY write path — our MCP tools' raw `update_post_meta()` writes, the
	 * editor, or an import.
	 *
	 * Elementor 4.2's Element Cache stores rendered HTML in post meta. Elementor
	 * clears it in `Document::save()`, but our CLI/proxy fallback writes data with
	 * raw `update_post_meta()` and bypasses that. On a persistent object cache
	 * (e.g. WP Engine) a stale/empty entry then survives every content write, so a
	 * page created via MCP — written while its data was still the empty `[]`,
	 * rendered (caching empty), then filled — keeps serving the cached empty
	 * render forever. This restores Elementor's invalidation universally, and also
	 * fixes the delayed visibility of edits to existing pages. See issue #111.
	 */
	public static function init(): void {
		add_action( 'added_post_meta', array( __CLASS__, 'flush_element_cache_on_data_write' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_element_cache_on_data_write' ), 10, 3 );
	}

	/**
	 * Clear the rendered-element cache when `_elementor_data` is written.
	 *
	 * @param int    $meta_id  Meta row id (unused).
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 */
	public static function flush_element_cache_on_data_write( $meta_id, $post_id, $meta_key ): void {
		if ( '_elementor_data' === $meta_key ) {
			delete_post_meta( (int) $post_id, self::ELEMENT_CACHE_META );
		}
	}

	/**
	 * Whether Elementor's document manager is available.
	 *
	 * False during Elementor's own activation/early boot (before its `init`
	 * builds `\Elementor\Plugin::$instance->documents`). Callers must check this
	 * before touching the document manager to avoid a null-deref fatal (#105).
	 *
	 * @since 3.8.2
	 *
	 * @return bool
	 */
	public static function elementor_documents_ready(): bool {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}
		$instance = \Elementor\Plugin::$instance;
		if ( ! $instance ) {
			return false;
		}
		// `?? null` tolerates the property being unset/null during activation.
		return (bool) ( $instance->documents ?? null );
	}

	/**
	 * Gets the Elementor document for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return \Elementor\Core\Base\Document|\WP_Error The document instance or WP_Error.
	 */
	public function get_document( int $post_id ) {
		// Elementor's document manager is built during its `init`, not during
		// activation. When Elementor is being activated it inserts its default
		// kit (a save_post that reaches our indexer) before that manager exists —
		// dereferencing `->documents->get()` then would fatal. Guard for it and
		// return a WP_Error the callers already handle (#105).
		if ( ! self::elementor_documents_ready() ) {
			return new \WP_Error(
				'elementor_not_ready',
				__( 'Elementor is not fully initialized yet.', 'karmcp' )
			);
		}

		$document = \Elementor\Plugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return new \WP_Error(
				'document_not_found',
				sprintf(
					/* translators: %d: post ID */
					__( 'Elementor document not found for post ID %d.', 'karmcp' ),
					$post_id
				)
			);
		}

		return $document;
	}

	/**
	 * Gets the element tree for an Elementor page.
	 *
	 * Tries the Elementor document API first, falls back to reading raw
	 * post meta if the document returns empty data (common in CLI contexts).
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The elements data array or WP_Error.
	 */
	public function get_page_data( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$data = $document->get_elements_data();

		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		// Fallback: read from raw post meta (handles CLI/proxy contexts).
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! empty( $raw ) && is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * Gets the page-level settings for an Elementor document.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return array|\WP_Error The page settings array or WP_Error.
	 */
	public function get_page_settings( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return $document->get_settings();
	}

	/**
	 * Gets the document type for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post ID.
	 * @return string|\WP_Error The document type string or WP_Error.
	 */
	public function get_document_type( int $post_id ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return get_post_meta( $post_id, '_elementor_template_type', true );
	}

	/**
	 * Gets all registered Elementor widget types.
	 *
	 * @since 1.0.0
	 *
	 * @return \Elementor\Widget_Base[] Array of widget instances keyed by widget name.
	 */
	public function get_registered_widgets(): array {
		return \Elementor\Plugin::$instance->widgets_manager->get_widget_types();
	}

	/**
	 * Gets the controls for a specific widget type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $widget_type The widget type name.
	 * @return array|\WP_Error The controls array or WP_Error if widget not found.
	 */
	public function get_widget_controls( string $widget_type ) {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( $widget_type );

		if ( ! $widget ) {
			return new \WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget type name */
					__( 'Widget type "%s" not found.', 'karmcp' ),
					$widget_type
				)
			);
		}

		return $widget->get_controls();
	}

	/**
	 * Recursively searches for an element by ID within an element tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data The element tree array.
	 * @param string $id   The element ID to find.
	 * @return array|null The element array if found, null otherwise.
	 */
	public function find_element_by_id( array $data, string $id ): ?array {
		foreach ( $data as $element ) {
			if ( isset( $element['id'] ) && $element['id'] === $id ) {
				return $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = $this->find_element_by_id( $element['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Whether an exception thrown by Document::save() is Elementor's atomic
	 * settings/styles validation rejecting a value (as opposed to a genuine
	 * fatal). Elementor formats these as `Settings validation failed. …` /
	 * `Styles validation failed …`, and the per-prop reason is `<prop>: invalid_value`.
	 *
	 * A validation rejection on our save path is over-strict — the tree is
	 * coerce_tree()'d on the way out, so what remains is editor-authored data the
	 * live editor persists fine (e.g. a `{$$type:'dynamic'}` paragraph whose tag
	 * the CLI/REST atomic registry can't resolve, #112). Those route to the raw
	 * meta-write fallback; anything else hard-fails.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The exception message from Document::save().
	 * @return bool True when the message is a settings/styles validation rejection.
	 */
	public static function is_atomic_validation_rejection( string $message ): bool {
		return false !== stripos( $message, 'validation failed' )
			|| false !== stripos( $message, 'invalid_value' );
	}

	/**
	 * Saves page data using Elementor's native save mechanism.
	 *
	 * Tries document save() first (triggers CSS regeneration). If that fails
	 * (e.g. non-browser context like WP-CLI or REST API), falls back to direct
	 * meta update and manual CSS cache invalidation.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The elements data array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_data( int $post_id, array $data ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		// v4 atomic: Elementor validates the WHOLE element tree on save, so one
		// widget holding raw (unwrapped) prop values blocks every save of the
		// page, including the edit meant to repair it. Coercing only the element
		// being written left users stuck on a page they could not unstick (#102).
		//
		// Sweep the tree on the way out instead. This only ever turns invalid
		// values into valid ones, so it is a no-op for healthy pages.
		$data = KarMCP_Atomic_Props::coerce_tree( $data );

		// Capture the prior Elementor data so the change ledger can offer a rollback.
		$karmcp_before_raw = get_post_meta( $post_id, '_elementor_data', true );

		// Safety net: if the current _elementor_data is a non-empty string that
		// does not decode to an array, it is corrupt/unreadable. get_page_data()
		// treats that as an empty page, so an edit built on top would overwrite
		// the original for good — preserve it first so it stays recoverable.
		if ( is_string( $karmcp_before_raw ) && '' !== $karmcp_before_raw && ! is_array( json_decode( $karmcp_before_raw, true ) ) ) {
			update_post_meta( $post_id, '_elementor_data_karmcp_corrupt', wp_slash( $karmcp_before_raw ) );
		}

		// Attempt native Elementor save (handles CSS regen, cache busting).
		// Elementor 4.0 atomic widgets THROW on invalid settings instead of
		// returning false, so catch it and return a clean error rather than
		// letting it fatal the whole request. The fallback meta-write below runs
		// ONLY for the no-exception falsy case (false OR null — valid data,
		// non-browser context), so invalid data is never written raw. Document::save()
		// can return null (not just false) in CLI/REST, hence `! $result`. (F-005, #36)
		$result           = false;
		$validation_throw = false;
		try {
			$result = $document->save( array( 'elements' => $data ) );
		} catch ( \Throwable $e ) {
			// Elementor 4.x atomic validation THROWS (get_data_for_save →
			// parse_atomic_settings → Props_Parser) on a value it cannot verify.
			// That includes an editor-authored `{$$type:'dynamic'}` prop whose tag
			// is not resolvable in this (CLI/REST) context: the atomic dynamic-tags
			// registry is under-populated outside the live editor, so a dynamic
			// paragraph that renders fine on the front end is rejected here — and
			// because save() validates the WHOLE tree, that blocks EVERY save of the
			// document, including edits to unrelated elements (#112).
			//
			// The tree was already swept by coerce_tree() above, so a validation
			// throw now is over-strict rejection of data the editor itself persists,
			// not a raw value we introduced. Route it into the direct meta write —
			// the same fallback used when save() returns falsy — instead of locking
			// the document out of the API. Any non-validation throw (a genuine fatal)
			// still hard-fails.
			if ( ! self::is_atomic_validation_rejection( $e->getMessage() ) ) {
				return new \WP_Error(
					'save_rejected',
					sprintf(
						/* translators: %s: error message from Elementor */
						__( 'Elementor rejected the element data: %s', 'karmcp' ),
						$e->getMessage()
					)
				);
			}
			$validation_throw = true;
		}

		// Verify the native save actually persisted our elements. Elementor's
		// Document::save() can return a truthy value in some 4.x / atomic / REST
		// contexts yet drop the elements so `_elementor_data` ends up empty —
		// a silent write failure the caller never sees (issue #98). Re-read and,
		// if we sent real elements but nothing landed, force the direct meta write
		// below rather than reporting a phantom success. A validation throw (#112)
		// also routes straight to the fallback.
		$needs_fallback = $validation_throw || ! $result;
		if ( ! $needs_fallback && ! empty( $data ) ) {
			$persisted_raw = get_post_meta( $post_id, '_elementor_data', true );
			$persisted     = ( is_string( $persisted_raw ) && '' !== $persisted_raw )
				? json_decode( $persisted_raw, true )
				: null;
			if ( empty( $persisted ) || ! is_array( $persisted ) ) {
				$needs_fallback = true;
			}
		}

		if ( $needs_fallback ) {
			// Fallback: direct meta write for non-browser contexts (CLI, REST proxy)
			// and for the silent-drop case above.
			$json = wp_json_encode( $data );

			if ( false === $json ) {
				return new \WP_Error(
					'json_encode_failed',
					__( 'Failed to encode element data as JSON.', 'karmcp' )
				);
			}

			update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

			// Invalidate Elementor CSS cache so it regenerates on next page view.
			delete_post_meta( $post_id, '_elementor_css' );

			// Invalidate Elementor 4.2's rendered-element cache — the update above
			// fires the meta hook that clears it, but do it explicitly too so a
			// re-save of identical data (which skips the hook) still refreshes a
			// stale/empty cached render. See init() + issue #111.
			delete_post_meta( $post_id, self::ELEMENT_CACHE_META );

			$upload_dir = wp_get_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $post_id . '.css';
			if ( file_exists( $css_path ) ) {
				wp_delete_file( $css_path );
			}
		}

		// Mark the post as built with Elementor on BOTH paths, not just the
		// fallback. This block used to live inside the branch above, which read
		// as safe — surely a successful native save sets its own flag — and is
		// not: Document::save() never writes `_elementor_edit_mode`. See
		// mark_built_with_elementor() for who does.
		self::mark_built_with_elementor( $post_id );

		// Record the edit to the unified change ledger (skipped during rollback).
		if ( class_exists( 'KarMCP_Change_Log' ) && ! KarMCP_Change_Log::$suppress ) {
			$karmcp_before = array();
			if ( is_string( $karmcp_before_raw ) && '' !== $karmcp_before_raw ) {
				$karmcp_decoded = json_decode( $karmcp_before_raw, true );
				if ( is_array( $karmcp_decoded ) ) {
					$karmcp_before = $karmcp_decoded;
				}
			}
			$karmcp_title = function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';
			if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
				KarMCP_Change_Recorder::record_elementor(
					$post_id,
					$karmcp_before,
					sprintf( 'Edited Elementor page #%d', $post_id ),
					trim( $karmcp_title . ' (#' . $post_id . ')' )
				);
			} else {
				KarMCP_Change_Log::record( array(
					'domain'   => 'elementor',
					'action'   => 'page-edit',
					'target'   => trim( $karmcp_title . ' (#' . $post_id . ')' ),
					'summary'  => sprintf( 'Edited Elementor page #%d', $post_id ),
					'rollback' => array( 'type' => 'elementor-data', 'post_id' => $post_id, 'before' => $karmcp_before ),
				) );
			}
		}

		return true;
	}

	/**
	 * Flags a post as built with Elementor, so Elementor treats it as its own.
	 *
	 * `_elementor_edit_mode` IS `Document::is_built_with_elementor()`, and that
	 * gates whether Elementor enqueues the post's generated CSS and whether a
	 * document renders its builder content at all. Without it a post whose
	 * `_elementor_data` is perfectly correct renders raw — no containers, no
	 * padding, widget templates unresolved — or, for a CPT whose own document
	 * class gates on the flag, renders nothing.
	 *
	 * KarMCP has to write it because Elementor does not: `Document::save()`
	 * never touches this meta. `set_is_built_with_elementor()` is only reached
	 * by opening the editor, the editor's own save, the classic-editor metabox
	 * and document creation — none of which happen on an MCP write. Posts built
	 * through create-page / create-popup / create-theme-template / build-page
	 * looked fine only because those tools seed the flag themselves at creation;
	 * anything reached another way (create-post, or a post that already existed)
	 * silently did not.
	 *
	 * Idempotent, and called on every Elementor write, so it also repairs posts
	 * an earlier version left unflagged.
	 *
	 * @since 1.17.0
	 *
	 * @param int $post_id The post ID being written.
	 * @return void
	 */
	public static function mark_built_with_elementor( int $post_id ): void {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * Saves page-level settings.
	 *
	 * Tries native Elementor save first, falls back to direct meta for
	 * non-browser contexts (WP-CLI, REST API proxy).
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id  The post ID.
	 * @param array $settings The page settings array.
	 * @return bool|\WP_Error True on success, WP_Error on failure.
	 */
	public function save_page_settings( int $post_id, array $settings ) {
		$document = $this->get_document( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$existing = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Same `null`-deletes convention as update-element. The markers are
		// resolved before the save so Elementor is never handed a literal null
		// (which it stores as a set-but-empty control), and re-applied after it
		// because the native save rebuilds page settings from its own model and
		// would put a removed key straight back.
		$deleting = array_keys( array_filter( $settings, 'is_null' ) );
		$settings = self::strip_null_deletions( $existing, $settings );

		/*
		 * Merge BEFORE the save, not only in the fallback below.
		 *
		 * Elementor's page settings manager ends at
		 * `update_metadata( 'post', $id, '_elementor_page_settings', $settings )`
		 * — whatever array it is handed becomes the whole meta. Handing it just
		 * the incoming keys therefore dropped every key already stored: sending
		 * two background keys to a post took its page settings from eight
		 * entries to two, and `container_custom_height` — the full-screen height
		 * of a popup — went with them.
		 *
		 * The fallback path merged already, so the same call silently either
		 * merged or replaced depending on whether the native save succeeded.
		 * Both paths now merge, which is what update-element has always done.
		 */
		$merged = array_merge( $existing, $settings );

		$result = $document->save( array( 'settings' => $merged ) );

		if ( ! $result ) {
			update_post_meta( $post_id, '_elementor_page_settings', $merged );

			// Invalidate CSS cache.
			delete_post_meta( $post_id, '_elementor_css' );
		}

		if ( $deleting ) {
			$stored = get_post_meta( $post_id, '_elementor_page_settings', true );
			if ( is_array( $stored ) ) {
				foreach ( $deleting as $deleted_key ) {
					unset( $stored[ $deleted_key ] );
				}
				update_post_meta( $post_id, '_elementor_page_settings', $stored );
				delete_post_meta( $post_id, '_elementor_css' );
			}
		}

		return true;
	}

	/**
	 * Inserts an element into the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data      The element tree (passed by reference).
	 * @param string $parent_id The parent element ID. Empty string for top-level.
	 * @param array  $element   The element to insert.
	 * @param int    $position  The insertion position (-1 = append).
	 * @return bool True if inserted, false if parent not found.
	 */
	public function insert_element( array &$data, string $parent_id, array $element, int $position = -1 ): bool {
		// Top-level insertion.
		if ( empty( $parent_id ) ) {
			if ( $position < 0 || $position >= count( $data ) ) {
				$data[] = $element;
			} else {
				array_splice( $data, $position, 0, array( $element ) );
			}
			return true;
		}

		// Find parent and insert.
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $parent_id ) {
				if ( ! isset( $item['elements'] ) ) {
					$item['elements'] = array();
				}

				if ( $position < 0 || $position >= count( $item['elements'] ) ) {
					$item['elements'][] = $element;
				} else {
					array_splice( $item['elements'], $position, 0, array( $element ) );
				}

				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->insert_element( $item['elements'], $parent_id, $element, $position ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Removes an element from the page data tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to remove.
	 * @return bool True if removed, false if not found.
	 */
	public function remove_element( array &$data, string $element_id ): bool {
		foreach ( $data as $index => &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				array_splice( $data, $index, 1 );
				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->remove_element( $item['elements'], $element_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Settings that carry a template's own imagery, and therefore its identity.
	 *
	 * Copying a block from a house template drags these along: the photo behind
	 * the hero, the dark overlay over it, the stock shots in the cards, the
	 * filters on them. A stylesheet cannot undo it — an element's own
	 * `background-image` beats any rule aimed at the same element — so a
	 * white-label build ends up wearing the house's clothes unless the keys
	 * themselves go.
	 *
	 * @since 1.17.0
	 *
	 * @var string[]
	 */
	private const MEDIA_SETTING_KEYS = array(
		'background_image',
		'background_overlay_background',
		'background_overlay_image',
		'background_overlay_color',
		'background_overlay_opacity',
		'image',
		'image_css_filter_css_filter',
	);

	/**
	 * Recursively removes the media settings above from an element tree.
	 *
	 * Removes rather than blanks: a blanked control is still a set control, and
	 * `{url:'',id:''}` is exactly the guesswork this is meant to end. Also clears
	 * each key's responsive overrides — a tablet-only background would otherwise
	 * survive and reappear at one breakpoint — and any `__globals__` binding for
	 * the same key, which would keep painting on its own.
	 *
	 * Classic elements only. Atomic (v4) elements keep their background in the
	 * typed `styles` map, which this does not walk.
	 *
	 * @since 1.17.0
	 *
	 * @param array $elements The element tree.
	 * @param int   $removed  Receives the number of settings actually removed.
	 * @return array The tree with media settings stripped.
	 */
	public function strip_media( array $elements, int &$removed ): array {
		foreach ( $elements as &$element ) {
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::strip_media_settings( $element['settings'], $removed );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = $this->strip_media( $element['elements'], $removed );
			}
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Strips the media keys from one element's settings.
	 *
	 * @since 1.17.0
	 *
	 * @param array $settings The element settings.
	 * @param int   $removed  Running count of removed settings.
	 * @return array The settings without media keys.
	 */
	private static function strip_media_settings( array $settings, int &$removed ): array {
		$suffixes = array( '', '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' );

		foreach ( self::MEDIA_SETTING_KEYS as $base ) {
			foreach ( $suffixes as $suffix ) {
				$key = $base . $suffix;

				if ( array_key_exists( $key, $settings ) ) {
					unset( $settings[ $key ] );
					++$removed;
				}

				if ( isset( $settings['__globals__'][ $key ] ) ) {
					unset( $settings['__globals__'][ $key ] );
					++$removed;
				}
			}
		}

		return $settings;
	}

	/**
	 * Recursively reassigns fresh IDs to all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return array The tree with new IDs.
	 */
	public function reassign_ids( array $elements ): array {
		foreach ( $elements as &$element ) {
			$element = $this->reassign_element_ids( $element );
		}
		unset( $element );

		return $elements;
	}

	/**
	 * Reassigns a fresh ID to a single element and all its children.
	 *
	 * @since 1.0.0
	 *
	 * @param array $element The element array.
	 * @return array The element with new IDs.
	 */
	public function reassign_element_ids( array $element ): array {
		$element['id'] = KarMCP_Id_Generator::generate();

		// v4 atomic elements: local style classes are named `e-<id>-<hash>` and
		// belong to a single element. A fresh element id must get fresh local
		// classes, or the duplicate shares the source's local classes — causing
		// cross-element style bleed and Style Origin doubling (issue #97).
		if ( class_exists( 'KarMCP_Atomic_Styles' ) ) {
			KarMCP_Atomic_Styles::remap_local_classes( $element );
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$element['elements'] = $this->reassign_ids( $element['elements'] );
		}

		return $element;
	}

	/**
	 * Recursively counts all elements in a tree.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements The element tree.
	 * @return int Total count.
	 */
	public function count_elements( array $elements ): int {
		$count = count( $elements );

		foreach ( $elements as $element ) {
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elements( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Resolves the `null`-means-delete convention against a settings array.
	 *
	 * Every key the caller sent as `null` is removed from the existing settings
	 * and dropped from the incoming ones, so the subsequent merge neither
	 * restores the old value nor writes a literal `null` (which Elementor stores
	 * happily and then reads as a set-but-empty control).
	 *
	 * @since 1.17.0
	 *
	 * @param array $existing The element's current settings, modified in place.
	 * @param array $incoming The settings the caller sent.
	 * @return array The incoming settings with the deletion markers removed.
	 */
	private static function strip_null_deletions( array &$existing, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if ( null === $value ) {
				unset( $existing[ $key ], $incoming[ $key ] );
			}
		}

		return $incoming;
	}

	/**
	 * Updates settings for a specific element in the tree.
	 *
	 * Modifies `$data` by reference. Returns true if element was found
	 * and updated, false if the element ID was not found.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $data       The element tree (passed by reference).
	 * @param string $element_id The element ID to update.
	 * @param array  $settings   The settings to merge.
	 * @return bool True if updated, false if not found.
	 */
	public function update_element_settings( array &$data, string $element_id, array $settings ): bool {
		foreach ( $data as &$item ) {
			if ( isset( $item['id'] ) && $item['id'] === $element_id ) {
				if ( ! isset( $item['settings'] ) ) {
					$item['settings'] = array();
				}

				// Sibling-root keys: on v4 atomic elements the local `styles`
				// map and `editor_settings` (Navigator label = editor_settings.
				// title) live at the element ROOT, as siblings of `settings`.
				// An agent naturally nests them under `settings`; hoist them out
				// and deep-merge into the root so they actually persist instead
				// of being written to a dead `settings.styles` key (#72, #73).
				$touched_styles = false;
				foreach ( array( 'styles', 'editor_settings' ) as $root_key ) {
					if ( ! array_key_exists( $root_key, $settings ) ) {
						continue;
					}

					if ( 'styles' === $root_key ) {
						$touched_styles = true;
					}

					$incoming = $settings[ $root_key ];
					unset( $settings[ $root_key ] );

					if ( is_array( $incoming ) ) {
						$existing = isset( $item[ $root_key ] ) && is_array( $item[ $root_key ] ) ? $item[ $root_key ] : array();
						$item[ $root_key ] = self::deep_merge( $existing, $incoming );
					} else {
						$item[ $root_key ] = $incoming;
					}
				}

				$el_type     = (string) ( $item['elType'] ?? '' );
				$widget_type = (string) ( $item['widgetType'] ?? '' );

				// v4 atomic elements keep their classes in the typed `classes`
				// prop and their background in the `styles` map, so none of the
				// classic key rewriting below describes them. Running it there
				// would invent keys, not repair them.
				$is_atomic = ( 0 === strpos( $el_type, 'e-' ) )
					|| ( '' !== $widget_type
						&& class_exists( 'KarMCP_Atomic_Widget_Map' )
						&& KarMCP_Atomic_Widget_Map::is_atomic( $widget_type ) );

				if ( ! $is_atomic ) {
					// Containers: rewrite MCP shorthand keys (`justify_content`,
					// `align_items`, `align_content`) to Elementor's prefixed flex
					// keys before merging. Without this, the values are saved
					// but never read by Elementor's CSS generator (issue #32).
					if ( 'container' === $el_type ) {
						$settings = KarMCP_Element_Factory::normalize_container_settings( $settings );
					} else {
						// Widgets/other elTypes: rewrite the CSS-class key for this
						// element type and flatten the same background shorthand so
						// an update-time background applies (containers already
						// covered by normalize_container_settings above).
						$settings = KarMCP_Element_Factory::normalize_css_classes_key( $el_type, $settings );
						$settings = KarMCP_Element_Factory::normalize_background_settings( $settings );
					}
				}

				// An incoming `null` means "remove this key", not "store a null".
				// A merge can only add or overwrite, so without this there is no
				// way to undo a setting: the caller has to guess the value that
				// neutralises each control — `{url:'',id:'',size:''}` for an
				// image, `{size:0}` for an overlay's opacity, `''` for a filter —
				// and guessing wrong leaves the setting in place without saying so.
				// Runs after the rewrites above so deleting an aliased key
				// (`justify_content`, `_css_classes`) removes the key actually stored.
				$settings = self::strip_null_deletions( $item['settings'], $settings );

				$item['settings'] = array_merge( $item['settings'], $settings );

				if ( ! $is_atomic ) {
					// Only now are the element's true, complete settings known,
					// which is the earliest point the background activator can be
					// decided without destroying one that was already set.
					$item['settings'] = KarMCP_Element_Factory::apply_background_activator( $item['settings'] );
				}

				// v4 atomic: props are typed, and a raw value like `'Hello'`
				// instead of `{$$type:'html-v3',…}` is not merely ignored, it
				// poisons the element. Elementor falls back to the prop default
				// (so the widget renders placeholder text) and every later save
				// of the page throws `Settings validation failed`, locking the
				// page out of both the API and the editor (#101).
				//
				// Coerce on the MERGED settings so this both accepts the plain
				// values an agent naturally sends AND repairs anything an earlier
				// version already wrote.
				if ( 'widget' === ( $item['elType'] ?? '' ) && ! empty( $item['widgetType'] ) ) {
					$item['settings'] = KarMCP_Atomic_Props::coerce_settings(
						(string) $item['widgetType'],
						$item['settings']
					);
				}

				// v4 atomic: a local style class only renders when the element's
				// `classes` prop references it. An agent that writes a `styles`
				// map but forgets to add the class id to settings.classes gets a
				// silent no-op — the styles persist but never apply (#92). Wire
				// every local class id from the styles map into settings.classes.
				if ( $touched_styles ) {
					self::sync_local_class_refs( $item );
				}

				return true;
			}

			if ( ! empty( $item['elements'] ) && is_array( $item['elements'] ) ) {
				if ( $this->update_element_settings( $item['elements'], $element_id, $settings ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Recursively merges $incoming into $existing. Associative arrays are merged
	 * key-by-key; lists (numeric-keyed arrays, e.g. a `variants` array) and
	 * scalars are replaced wholesale by the incoming value. This lets a partial
	 * `styles`/`editor_settings` update touch one class or key without dropping
	 * the siblings, while still replacing a variants list the caller supplied in
	 * full.
	 *
	 * @since 3.2.1
	 *
	 * @param array $existing The current value.
	 * @param array $incoming The value to merge in (wins on conflicts).
	 * @return array The merged array.
	 */
	private static function deep_merge( array $existing, array $incoming ): array {
		foreach ( $incoming as $key => $value ) {
			if (
				is_string( $key )
				&& isset( $existing[ $key ] )
				&& is_array( $existing[ $key ] )
				&& is_array( $value )
				&& self::is_assoc( $existing[ $key ] )
				&& self::is_assoc( $value )
			) {
				$existing[ $key ] = self::deep_merge( $existing[ $key ], $value );
			} else {
				$existing[ $key ] = $value;
			}
		}

		return $existing;
	}

	/**
	 * Whether an array is associative (has any string key / non-sequential
	 * integer keys). An empty array is treated as a list (not associative).
	 *
	 * @since 3.2.1
	 *
	 * @param array $arr The array to test.
	 * @return bool
	 */
	private static function is_assoc( array $arr ): bool {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Ensures every local style class in an atomic element's `styles` map is
	 * referenced by its `settings.classes` prop, so Elementor actually applies
	 * the styles at render time.
	 *
	 * In Elementor 4.0 (atomic), a per-element local class lives in the element
	 * root `styles` map keyed by an `e-<id>-<hash>` class id, with a sibling
	 * `settings.classes = { $$type:'classes', value:[ ...ids ] }` that lists the
	 * classes actually applied to the element. Writing a `styles` entry alone
	 * persists the definition but renders nothing until the id is also in
	 * `classes.value`. This wires up any missing references (idempotent) so a
	 * `styles` write is self-contained (#92).
	 *
	 * @since 3.4.1
	 *
	 * @param array $item Element structure (by reference).
	 */
	private static function sync_local_class_refs( array &$item ): void {
		if ( empty( $item['styles'] ) || ! is_array( $item['styles'] ) ) {
			return;
		}

		// Collect local class ids from the styles map (type:'class' only).
		$ids = array();
		foreach ( $item['styles'] as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			if ( isset( $def['type'] ) && 'class' !== $def['type'] ) {
				continue;
			}
			$id = ( isset( $def['id'] ) && is_string( $def['id'] ) && '' !== $def['id'] )
				? $def['id']
				: ( is_string( $key ) ? $key : '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return;
		}

		if ( ! isset( $item['settings'] ) || ! is_array( $item['settings'] ) ) {
			$item['settings'] = array();
		}
		$classes = ( isset( $item['settings']['classes'] ) && is_array( $item['settings']['classes'] ) )
			? $item['settings']['classes']
			: array();

		// Normalize to the atomic classes wrapper { $$type:'classes', value:[] }.
		if ( ! isset( $classes['$$type'] ) ) {
			$classes['$$type'] = 'classes';
		}
		if ( ! isset( $classes['value'] ) || ! is_array( $classes['value'] ) ) {
			$classes['value'] = array();
		}

		foreach ( $ids as $id ) {
			if ( ! in_array( $id, $classes['value'], true ) ) {
				$classes['value'][] = $id;
			}
		}

		$item['settings']['classes'] = $classes;
	}
}
