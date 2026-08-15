<?php
/**
 * WordPress Media Library MCP ability for Elementor.
 *
 * Registers the Media Library CRUD tools — `list-media`, `get-media`,
 * `upload-media`, `update-media` and `delete-media` — so an AI agent can work
 * with the site's own uploads rather than only with stock photos: those find
 * generic images, but can't surface a client's own (e.g. 300+ job-site photos
 * already in their library). The reads are a direct WP_Query on attachments,
 * no HTTP round-trip.
 *
 * `upload-media` is the companion to `sideload-image`: that one fetches a URL
 * the *server* can already reach, this one takes the bytes from the *client*
 * machine as base64, which is the only way an agent can put a file the user
 * has locally into the library.
 *
 * @package KarMCP
 * @since   2.0.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the Media Library query ability.
 *
 * @since 2.0.2
 */
class KarMCP_Media_Library_Abilities {

	/**
	 * The data access layer.
	 *
	 * @var KarMCP_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since 2.0.2
	 *
	 * @param KarMCP_Data $data The data access layer.
	 */
	public function __construct( KarMCP_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'karmcp/list-media',
			'karmcp/get-media',
			'karmcp/upload-media',
			'karmcp/update-media',
			'karmcp/delete-media',
		);
	}

	/**
	 * Registers the Media Library abilities.
	 *
	 * @since 2.0.2
	 */
	public function register(): void {
		$this->register_list_media();
		$this->register_get_media();
		$this->register_upload_media();
		$this->register_update_media();
		$this->register_delete_media();
	}

	/**
	 * Permission check for read-only library queries.
	 *
	 * Mirrors search-images: read access is gated on `edit_posts`.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	// -------------------------------------------------------------------------
	// list-media
	// -------------------------------------------------------------------------

	private function register_list_media(): void {
		karmcp_register_ability(
			'karmcp/list-media',
			array(
				'label'               => __( 'List Media', 'karmcp' ),
				'description'         => __( 'Lists and searches images already in the WordPress Media Library. Use this to find a site\'s own uploaded photos (e.g. a client\'s product or job-site images) before reaching for stock photos. The optional "search" matches the title, alt text, caption, and description. Returns attachment IDs and URLs you can pass straight to add-free-widget.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_list_media' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Keyword to match against the attachment title, alt text, caption, and description. Omit to list everything.', 'karmcp' ),
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => __( 'MIME type filter. Accepts a top-level type ("image") or a specific type ("image/jpeg", "image/png"). Use "any" for all media types. Default: image.', 'karmcp' ),
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => __( 'Page number (1-based). Default: 1.', 'karmcp' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1-100). Default: 20.', 'karmcp' ),
						),
						'orderby'   => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'title' ),
							'description' => __( 'Sort field. Default: date (newest first).', 'karmcp' ),
						),
						'order'     => array(
							'type'        => 'string',
							'enum'        => array( 'desc', 'asc' ),
							'description' => __( 'Sort direction. Default: desc.', 'karmcp' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'result_count' => array( 'type' => 'integer' ),
						'page'         => array( 'type' => 'integer' ),
						'page_count'   => array( 'type' => 'integer' ),
						'total'        => array( 'type' => 'integer' ),
						'results'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array( 'type' => 'integer' ),
									'title'         => array( 'type' => 'string' ),
									'url'           => array( 'type' => 'string' ),
									'thumbnail_url' => array( 'type' => 'string' ),
									'alt'           => array( 'type' => 'string' ),
									'mime_type'     => array( 'type' => 'string' ),
									'width'         => array( 'type' => 'integer' ),
									'height'        => array( 'type' => 'integer' ),
									'filesize'      => array( 'type' => 'integer' ),
									'date'          => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the list-media ability.
	 *
	 * @since 2.0.2
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_list_media( $input ) {
		$search   = sanitize_text_field( $input['search'] ?? '' );
		$mime     = sanitize_text_field( $input['mime_type'] ?? 'image' );
		$page     = max( 1, absint( $input['page'] ?? 1 ) );
		$per_page = absint( $input['per_page'] ?? 20 );
		$per_page = max( 1, min( 100, $per_page ) );

		$orderby = ( isset( $input['orderby'] ) && 'title' === $input['orderby'] ) ? 'title' : 'date';
		$order   = ( isset( $input['order'] ) && 'asc' === strtolower( (string) $input['order'] ) ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		// Default to images; allow a specific MIME or "any" to widen.
		if ( '' !== $mime && 'any' !== strtolower( $mime ) && '*' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		// Keyword search. WP_Query's `s` covers the title, caption (excerpt) and
		// description (content) but NOT the alt text, which lives in postmeta.
		// So we resolve the matching attachment IDs from both sources up front
		// (lightweight id-only queries) and feed the union into the paginated
		// query via post__in — no global query filters, nothing to leak.
		if ( '' !== $search ) {
			$id_args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			);
			if ( isset( $args['post_mime_type'] ) ) {
				$id_args['post_mime_type'] = $args['post_mime_type'];
			}

			$text_ids = get_posts( array_merge( $id_args, array( 's' => $search ) ) );
			$alt_ids  = get_posts(
				array_merge(
					$id_args,
					array(
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to attachments; the alt-text postmeta has no dedicated column.
							array(
								'key'     => '_wp_attachment_image_alt',
								'value'   => $search,
								'compare' => 'LIKE',
							),
						),
					)
				)
			);

			$ids = array_values( array_unique( array_map( 'absint', array_merge( (array) $text_ids, (array) $alt_ids ) ) ) );

			if ( empty( $ids ) ) {
				return array(
					'result_count' => 0,
					'page'         => $page,
					'page_count'   => 0,
					'total'        => 0,
					'results'      => array(),
				);
			}

			$args['post__in'] = $ids;
		}

		$query   = new \WP_Query( $args );
		$results = array();
		foreach ( $query->posts as $attachment ) {
			$results[] = $this->format_attachment( $attachment );
		}

		return array(
			'result_count' => count( $results ),
			'page'         => $page,
			'page_count'   => (int) $query->max_num_pages,
			'total'        => (int) $query->found_posts,
			'results'      => $results,
		);
	}

	/**
	 * Edit permission for a specific attachment (attachments are posts).
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry an `id`.
	 * @return bool
	 */
	public function check_edit_permission( $input = null ): bool {
		$id = absint( $input['id'] ?? 0 );
		return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
	}

	/**
	 * Delete permission for a specific attachment.
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry an `id`.
	 * @return bool
	 */
	public function check_delete_permission( $input = null ): bool {
		$id = absint( $input['id'] ?? 0 );
		return $id ? current_user_can( 'delete_post', $id ) : current_user_can( 'delete_posts' );
	}

	/**
	 * Resolve an id to an attachment post, or a WP_Error.
	 *
	 * @since 3.0.0
	 * @param mixed $raw
	 * @return object|\WP_Error WP_Post-like on success.
	 */
	private function resolve_attachment( $raw ) {
		$id = absint( $raw );
		if ( ! $id ) {
			return new \WP_Error( 'missing_params', __( 'An attachment "id" is required.', 'karmcp' ) );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'karmcp' ) );
		}
		if ( 'attachment' !== ( $post->post_type ?? '' ) ) {
			return new \WP_Error( 'not_an_attachment', __( 'That ID is not a media attachment.', 'karmcp' ) );
		}
		return $post;
	}

	// -------------------------------------------------------------------------
	// get-media
	// -------------------------------------------------------------------------

	private function register_get_media(): void {
		karmcp_register_ability(
			'karmcp/get-media',
			array(
				'label'               => __( 'Get Media', 'karmcp' ),
				'description'         => __( 'Returns full detail for one Media Library attachment: title, URL, every registered image size (url + dimensions), mime type, filesize, alt text, caption, description, and raw attachment metadata. The single-item complement to list-media.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_get_media' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'karmcp' ) ) ),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ),
					'slug' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ), 'filesize' => array( 'type' => 'integer' ),
					'alt' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ), 'date' => array( 'type' => 'string' ),
					'author' => array( 'type' => 'object' ), 'post_parent' => array( 'type' => 'integer' ),
					'width' => array( 'type' => 'integer' ), 'height' => array( 'type' => 'integer' ),
					'sizes' => array( 'type' => 'object' ), 'metadata' => array( 'type' => 'object' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id   = (int) $post->ID;
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$sizes = array();
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size ) {
				$src = wp_get_attachment_image_src( $id, $size );
				if ( is_array( $src ) ) {
					$sizes[ $size ] = array( 'url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] );
				}
			}
		}

		$author_id  = (int) ( $post->post_author ?? 0 );
		$author_obj = $author_id && function_exists( 'get_userdata' ) ? get_userdata( $author_id ) : null;

		$filesize = 0;
		if ( isset( $meta['filesize'] ) ) {
			$filesize = (int) $meta['filesize'];
		}

		return array(
			'id'          => $id,
			'title'       => (string) $post->post_title,
			'slug'        => (string) $post->post_name,
			'url'         => (string) wp_get_attachment_url( $id ),
			'mime_type'   => (string) ( $post->post_mime_type ?? '' ),
			'filesize'    => $filesize,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'date'        => (string) ( $post->post_date ?? '' ),
			'author'      => array( 'id' => $author_id, 'name' => $author_obj ? (string) $author_obj->display_name : '' ),
			'post_parent' => (int) ( $post->post_parent ?? 0 ),
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'sizes'       => $sizes,
			'metadata'    => $meta,
		);
	}

	// -------------------------------------------------------------------------
	// upload-media
	// -------------------------------------------------------------------------

	/**
	 * Upload permission: `upload_files`, plus edit rights on the parent post
	 * when the caller attaches the upload to one.
	 *
	 * Attaching is a write to that post's media list, so an author who may
	 * upload must still not be able to hang a file off somebody else's page.
	 *
	 * Existence is resolved BEFORE the capability, and that order is the whole
	 * point: `map_meta_cap()` resolves `edit_post` against a post that is not
	 * there to `do_not_allow`, so asking the capability first reports a
	 * permission problem for what is really a mistyped id — the bare
	 * "Permission denied" that 1.2.1 went and removed everywhere else.
	 *
	 * @since 1.3.0
	 *
	 * @param array|null $input Tool input; may carry a `post_id`.
	 * @return true|\WP_Error
	 */
	public function check_upload_permission( $input = null ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error(
				'missing_capability',
				sprintf(
					/* translators: %s: capability name */
					__( 'The current user does not have the "%s" capability, which WordPress requires to add anything to the Media Library.', 'karmcp' ),
					'upload_files'
				),
				array( 'required_capability' => 'upload_files' )
			);
		}

		$parent = absint( $input['post_id'] ?? 0 );
		if ( ! $parent ) {
			return true;
		}

		if ( ! get_post( $parent ) ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d exists to attach the upload to. Omit post_id to upload without attaching.', 'karmcp' ),
					$parent
				),
				array( 'post_id' => $parent )
			);
		}

		if ( ! current_user_can( 'edit_post', $parent ) ) {
			$post_type = get_post_type( $parent );
			return new \WP_Error(
				'cannot_edit_post',
				sprintf(
					/* translators: 1: post ID, 2: post type */
					__( 'The current user may upload files but not edit post %1$d (post type %2$s), so the upload cannot be attached to it. Omit post_id to upload without attaching.', 'karmcp' ),
					$parent,
					false !== $post_type ? $post_type : 'unknown'
				),
				array(
					'required_capability' => 'edit_post',
					'post_id'             => $parent,
					'post_type'           => false !== $post_type ? $post_type : null,
				)
			);
		}

		return true;
	}

	private function register_upload_media(): void {
		karmcp_register_ability(
			'karmcp/upload-media',
			array(
				'label'               => __( 'Upload Media', 'karmcp' ),
				'description'         => __( 'Uploads a file from the CLIENT machine into the WordPress Media Library by passing its raw bytes as base64. Use this for a file the user has locally; use sideload-image instead when the image is at a public URL the server can fetch itself. Only file types WordPress accepts are allowed (executables are refused), and the content is verified against the extension. Returns the attachment ID and URL, ready to pass to add-free-widget, or to update-post as featured_image:{id}.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_upload_media' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'filename'     => array(
							'type'        => 'string',
							'description' => __( 'File name WITH its extension (e.g. "team-photo.jpg"). The extension decides the accepted type, so it must match the real content. Any directory part is stripped.', 'karmcp' ),
						),
						'data'         => array(
							'type'        => 'string',
							'description' => __( 'The file contents, base64-encoded. A "data:<mime>;base64," prefix is accepted and stripped.', 'karmcp' ),
						),
						'alt'          => array(
							'type'        => 'string',
							'description' => __( 'Alt text (accessibility/SEO). Strongly recommended for images.', 'karmcp' ),
						),
						'title'        => array(
							'type'        => 'string',
							'description' => __( 'Attachment title. Defaults to the filename.', 'karmcp' ),
						),
						'caption'      => array(
							'type'        => 'string',
							'description' => __( 'Attachment caption.', 'karmcp' ),
						),
						'description'  => array(
							'type'        => 'string',
							'description' => __( 'Attachment description.', 'karmcp' ),
						),
						'post_id'      => array(
							'type'        => 'integer',
							'description' => __( 'Attach the upload to this post/page (sets post_parent). Requires edit rights on it. Omit to leave it unattached.', 'karmcp' ),
						),
						'convert_webp' => array(
							'type'        => 'boolean',
							'description' => __( 'Convert the uploaded image to WebP (default true, and only when the Image Optimization module is active). Set false to skip conversion when it is timing out on shared hosting.', 'karmcp' ),
						),
					),
					'required'   => array( 'filename', 'data' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id' => array( 'type' => 'integer' ),
						'url'           => array( 'type' => 'string' ),
						'filename'      => array( 'type' => 'string' ),
						'title'         => array( 'type' => 'string' ),
						'mime_type'     => array( 'type' => 'string' ),
						'filesize'      => array( 'type' => 'integer' ),
						'width'         => array( 'type' => 'integer' ),
						'height'        => array( 'type' => 'integer' ),
						'post_parent'   => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the upload-media ability.
	 *
	 * @since 1.3.0
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_upload_media( $input ) {
		// These live in wp-admin/includes, which the REST/WP-CLI requests the MCP
		// server runs in do not load. Each is guarded on its own file's function:
		// wp_tempnam() is in file.php and media_handle_sideload() in media.php, so
		// keying both off the latter would fatal on a request where something else
		// had already pulled in media.php alone.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$filename = $this->resolve_upload_filename( $input['filename'] ?? '' );
		if ( is_wp_error( $filename ) ) {
			return $filename;
		}

		// Both the existence of the parent and the right to edit it were settled in
		// check_upload_permission(), which the adapter always runs first (including
		// through the dispatcher). Kept as a backstop for a direct call from PHP.
		$parent = absint( $input['post_id'] ?? 0 );
		if ( $parent && ! get_post( $parent ) ) {
			return new \WP_Error(
				'post_not_found',
				sprintf(
					/* translators: %d: post ID. */
					__( 'No post with ID %d exists to attach the upload to.', 'karmcp' ),
					$parent
				)
			);
		}

		$bytes = $this->decode_upload_payload( $input['data'] ?? '' );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$tmp_file = wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new \WP_Error( 'temp_file_failed', __( 'Could not create a temporary file for the upload.', 'karmcp' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temp file outside ABSPATH, exactly as core's download_url() writes it; WP_Filesystem is not initialised on this path.
		$written = file_put_contents( $tmp_file, $bytes );
		if ( false === $written || $written !== strlen( $bytes ) ) {
			wp_delete_file( $tmp_file );
			return new \WP_Error( 'temp_write_failed', __( 'Could not write the decoded file to disk (check the temp directory is writable and has space).', 'karmcp' ) );
		}
		unset( $bytes );

		// Read the content and confirm it is what the extension claims. WordPress
		// does this too, inside media_handle_sideload(), and refuses — but with
		// core's own translated "you are not allowed to upload this file type",
		// which on a Spanish site is a locale-specific string that says nothing
		// about the actual problem and invites an agent to retry the same bytes.
		// Doing it here lets the refusal name the mismatch, in this plugin's voice
		// and independent of the site's language.
		$verified = wp_check_filetype_and_ext( $tmp_file, $filename );
		if ( empty( $verified['type'] ) ) {
			wp_delete_file( $tmp_file );
			return new \WP_Error(
				'content_type_mismatch',
				sprintf(
					/* translators: 1: filename, 2: file extension. */
					__( 'The contents of %1$s are not a valid ".%2$s" file. WordPress reads the bytes, not just the name, so renaming a file does not change what it is. Send the real file, or the filename that matches these bytes.', 'karmcp' ),
					$filename,
					strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) )
				),
				array( 'filename' => $filename )
			);
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		// media_handle_sideload() runs wp_generate_attachment_metadata()
		// synchronously, which is where the Image Optimization module compresses
		// and generates WebP. When the caller asks to skip conversion
		// (convert_webp:false — for slow shared hosting), suppress it for just
		// this upload. It repeats the content check above, which is deliberate:
		// that one is for the message, this one is the guarantee.
		$skip_webp = array_key_exists( 'convert_webp', (array) $input ) && false === $input['convert_webp'];
		if ( $skip_webp ) {
			add_filter( 'karmcp_optimize_attachment', '__return_false', 99 );
		}
		$attachment_id = media_handle_sideload( $file_array, $parent );
		if ( $skip_webp ) {
			remove_filter( 'karmcp_optimize_attachment', '__return_false', 99 );
		}

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			// The type mismatch is already caught above, so what reaches here is a
			// move or a processing failure — the only case where convert_webp:false
			// can help. Appending that hint to every failure told the caller to
			// retry with a flag that could not possibly change the outcome.
			return new \WP_Error(
				'upload_failed',
				sprintf(
					/* translators: 1: filename, 2: error message. */
					__( 'Upload of %1$s failed: %2$s. If the file is large this may be image processing timing out — retry with convert_webp:false.', 'karmcp' ),
					$filename,
					$attachment_id->get_error_message()
				)
			);
		}

		$attachment_id = (int) $attachment_id;
		$this->apply_attachment_fields( $attachment_id, $input );

		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			KarMCP_Change_Recorder::record_post_create(
				$attachment_id,
				sprintf(
					/* translators: 1: filename, 2: attachment ID. */
					__( 'Uploaded media %1$s (#%2$d)', 'karmcp' ),
					$filename,
					$attachment_id
				),
				$filename . ' (#' . $attachment_id . ')'
			);
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		$post = get_post( $attachment_id );

		$filesize = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
		if ( ! $filesize ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && file_exists( $path ) ) {
				$filesize = (int) filesize( $path );
			}
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => (string) wp_get_attachment_url( $attachment_id ),
			// Not necessarily what was passed: WordPress dedupes a colliding name
			// and corrects an extension that misnames the content.
			'filename'      => (string) wp_basename( (string) get_attached_file( $attachment_id ) ),
			'title'         => (string) ( $post->post_title ?? '' ),
			'mime_type'     => (string) ( $post->post_mime_type ?? '' ),
			'filesize'      => $filesize,
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'post_parent'   => (int) ( $post->post_parent ?? 0 ),
		);
	}

	/**
	 * Reduces the caller's `filename` to a bare, accepted filename — or explains
	 * why it cannot be one.
	 *
	 * Everything here runs before the payload is decoded, so a name that could
	 * never be stored costs nothing.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $raw The `filename` input value.
	 * @return string|\WP_Error Sanitized filename, or an error.
	 */
	private function resolve_upload_filename( $raw ) {
		// A path is never meaningful here — the bytes come over the wire, so any
		// directory part is either an agent leaking its local layout or an attempt
		// at traversal. The backslashes are normalised first because basename() is
		// separator-aware only for the platform it runs on, so a Windows client's
		// "C:\Users\me\photo.jpg" would otherwise survive as one long "name" and
		// come out of sanitize_file_name() as "CUsersmephoto.jpg".
		$filename = sanitize_file_name( basename( str_replace( '\\', '/', (string) $raw ) ) );

		if ( '' === $filename ) {
			return new \WP_Error( 'missing_params', __( 'A "filename" is required, with its extension (e.g. "photo.jpg").', 'karmcp' ) );
		}
		if ( ! preg_match( '/\.[A-Za-z0-9]{1,10}$/', $filename ) ) {
			return new \WP_Error(
				'missing_extension',
				sprintf(
					/* translators: %s: the filename that was passed. */
					__( 'The filename "%s" has no extension. WordPress decides what a file is by its extension, so it must be present (e.g. "photo.jpg").', 'karmcp' ),
					$filename
				)
			);
		}

		// Reject a type this site (and this user) cannot accept BEFORE decoding
		// what may be megabytes. wp_check_filetype() resolves against
		// get_allowed_mime_types(), so the SVG Support module widening the list is
		// honoured, and so is the narrowing core applies to a user without
		// `unfiltered_html` (it drops html, js and css for them). The authoritative
		// test is still the one media_handle_sideload() runs on the content.
		$checked = wp_check_filetype( $filename );
		if ( empty( $checked['type'] ) ) {
			return new \WP_Error(
				'disallowed_file_type',
				sprintf(
					/* translators: %s: the file extension that was rejected. */
					__( 'WordPress does not accept ".%s" uploads on this site. Executable and unknown types are refused; for SVG, enable the SVG Support module first.', 'karmcp' ),
					strtolower( (string) ( empty( $checked['ext'] ) ? pathinfo( $filename, PATHINFO_EXTENSION ) : $checked['ext'] ) )
				)
			);
		}

		return $filename;
	}

	/**
	 * Decodes the base64 payload, refusing anything the site would not accept as
	 * an upload anyway.
	 *
	 * The size is estimated from the encoded length first: base64 costs 4 bytes
	 * per 3, so a payload over the limit can be refused without allocating the
	 * decoded copy alongside it.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $raw The `data` input value.
	 * @return string|\WP_Error Decoded bytes, or an error.
	 */
	private function decode_upload_payload( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error( 'missing_params', __( 'A base64-encoded "data" payload is required.', 'karmcp' ) );
		}

		// Accept a data: URI as pasted by most clients, and drop the whitespace
		// that line-wrapped base64 carries (strict decoding rejects it). The
		// media-type part is matched up to the comma, not up to the first
		// semicolon, so a URI carrying extra parameters is stripped too.
		$payload = preg_replace( '#^data:[^,]*;base64,#i', '', $raw );
		$payload = preg_replace( '/\s+/', '', (string) $payload );

		if ( '' === $payload ) {
			return new \WP_Error( 'missing_params', __( 'The "data" payload is empty once the data: prefix is removed.', 'karmcp' ) );
		}

		$limit = function_exists( 'wp_max_upload_size' ) ? (int) wp_max_upload_size() : 0;
		if ( $limit > 0 && (int) ( strlen( $payload ) * 3 / 4 ) > $limit ) {
			return $this->too_large_error( $limit );
		}

		$bytes = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the caller's file payload is this tool's whole purpose; strict mode rejects anything that is not base64.
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'invalid_base64', __( 'The "data" payload is not valid base64. Send the raw file bytes base64-encoded, not a file path, a URL, or JSON.', 'karmcp' ) );
		}
		if ( $limit > 0 && strlen( $bytes ) > $limit ) {
			return $this->too_large_error( $limit );
		}

		return $bytes;
	}

	/**
	 * The "over the upload limit" error, with the limit named so an agent can act
	 * on it (resize the image) instead of retrying the same payload.
	 *
	 * @since 1.3.0
	 *
	 * @param int $limit Maximum upload size in bytes.
	 * @return \WP_Error
	 */
	private function too_large_error( int $limit ): \WP_Error {
		return new \WP_Error(
			'file_too_large',
			sprintf(
				/* translators: %s: human-readable size, e.g. "8 MB". */
				__( 'The file is larger than this site accepts (%s). Resize or recompress it, or raise the PHP upload_max_filesize / post_max_size limits.', 'karmcp' ),
				size_format( $limit )
			)
		);
	}

	/**
	 * Applies the optional title/alt/caption/description to a fresh upload.
	 *
	 * @since 1.3.0
	 *
	 * @param int   $attachment_id The new attachment ID.
	 * @param array $input         The tool input.
	 * @return void
	 */
	private function apply_attachment_fields( int $attachment_id, array $input ): void {
		$postarr = array( 'ID' => $attachment_id );

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' !== $title ) {
			$postarr['post_title'] = $title;
		}
		$caption = sanitize_text_field( (string) ( $input['caption'] ?? '' ) );
		if ( '' !== $caption ) {
			$postarr['post_excerpt'] = $caption;
		}
		if ( array_key_exists( 'description', $input ) && '' !== (string) $input['description'] ) {
			// Description maps to post_content, which allows HTML by design;
			// wp_update_post applies wp_filter_post_kses for users without
			// unfiltered_html. Title and caption are plain text, so they are
			// sanitized above — this deliberately is not.
			$postarr['post_content'] = (string) $input['description'];
		}

		if ( count( $postarr ) > 1 ) {
			wp_update_post( wp_slash( $postarr ) );
		}

		$alt = sanitize_text_field( (string) ( $input['alt'] ?? '' ) );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	// -------------------------------------------------------------------------
	// update-media
	// -------------------------------------------------------------------------

	private function register_update_media(): void {
		karmcp_register_ability(
			'karmcp/update-media',
			array(
				'label'               => __( 'Update Media', 'karmcp' ),
				'description'         => __( 'Updates an existing attachment\'s metadata: title, alt text, caption, and/or description. Only the fields you pass change. Great for fixing missing alt text (accessibility/SEO) on images already in the library.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_update_media' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'karmcp' ) ),
						'title'       => array( 'type' => 'string' ),
						'alt'         => array( 'type' => 'string', 'description' => __( 'Alt text (accessibility).', 'karmcp' ) ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'alt' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ),
					'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id      = (int) $post->ID;
		$updated = array();

		// Capture the before-image of exactly what this update changes, for rollback.
		$karmcp_before = null;
		if ( class_exists( 'KarMCP_Change_Recorder' ) ) {
			$karmcp_bf = array();
			if ( array_key_exists( 'title', $input ) )       { $karmcp_bf['post_title'] = $post->post_title; }
			if ( array_key_exists( 'caption', $input ) )     { $karmcp_bf['post_excerpt'] = $post->post_excerpt; }
			if ( array_key_exists( 'description', $input ) ) { $karmcp_bf['post_content'] = $post->post_content; }
			$karmcp_bm = array();
			if ( array_key_exists( 'alt', $input ) ) {
				$karmcp_prior_alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
				$karmcp_bm['_wp_attachment_image_alt'] = ( '' === $karmcp_prior_alt ) ? '__DELETE__' : $karmcp_prior_alt;
			}
			if ( $karmcp_bf || $karmcp_bm ) {
				$karmcp_before = array( 'fields' => $karmcp_bf, 'meta' => $karmcp_bm, 'terms' => array() );
			}
		}

		$postarr = array( 'ID' => $id );
		if ( array_key_exists( 'title', $input ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
			$updated[]             = 'title';
		}
		if ( array_key_exists( 'caption', $input ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $input['caption'] );
			$updated[]               = 'caption';
		}
		if ( array_key_exists( 'description', $input ) ) {
			// Description maps to post_content, which allows HTML by design;
			// wp_update_post applies wp_filter_post_kses for users without
			// unfiltered_html. Do NOT sanitize_text_field this (it would strip
			// legitimate markup) — title/caption are plain-text, so they are.
			$postarr['post_content'] = (string) $input['description'];
			$updated[]               = 'description';
		}
		if ( count( $postarr ) > 1 ) {
			$res = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( array_key_exists( 'alt', $input ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
			$updated[] = 'alt';
		}

		if ( null !== $karmcp_before && ! empty( $updated ) ) {
			KarMCP_Change_Recorder::record_post_fields(
				$id,
				$karmcp_before,
				sprintf( 'Updated media #%d (%s)', $id, implode( ', ', $updated ) ),
				trim( (string) $post->post_title . ' (#' . $id . ')' ),
				'media',
				'update-media'
			);
		}

		$fresh = get_post( $id );
		return array(
			'id'          => $id,
			'updated'     => $updated,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => (string) ( $fresh->post_title ?? $post->post_title ),
			'caption'     => (string) ( $fresh->post_excerpt ?? $post->post_excerpt ),
			'description' => (string) ( $fresh->post_content ?? $post->post_content ),
		);
	}

	// -------------------------------------------------------------------------
	// delete-media
	// -------------------------------------------------------------------------

	private function register_delete_media(): void {
		karmcp_register_ability(
			'karmcp/delete-media',
			array(
				'label'               => __( 'Delete Media', 'karmcp' ),
				'description'         => __( 'Deletes a Media Library attachment. DESTRUCTIVE and effectively permanent, WordPress bypasses Trash for media unless MEDIA_TRASH is defined. Requires confirm:true. Pass force:true to skip Trash even when MEDIA_TRASH is on.', 'karmcp' ),
				'category'            => 'karmcp',
				'execute_callback'    => array( $this, 'execute_delete_media' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'karmcp' ) ),
						'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true to proceed (acknowledges permanent deletion).', 'karmcp' ) ),
						'force'   => array( 'type' => 'boolean', 'description' => __( 'Skip Trash even when MEDIA_TRASH is defined. Default: false.', 'karmcp' ) ),
					),
					'required'   => array( 'id', 'confirm' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'success' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ),
					'deleted' => array( 'type' => 'string' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_delete_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Deleting media is permanent on most sites (WordPress bypasses Trash unless MEDIA_TRASH is defined). Pass confirm:true to proceed.', 'karmcp' ) );
		}
		$id      = (int) $post->ID;
		$force   = ! empty( $input['force'] );
		$trashed = ! $force && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH;

		// Snapshot the attachment (post + meta + a trashed copy of every file)
		// BEFORE deleting, so the delete is reversible from the change ledger.
		$has_rec  = class_exists( 'KarMCP_Change_Recorder' ) && ! KarMCP_Change_Log::$suppress;
		$snapshot = ( $has_rec && ! $trashed ) ? KarMCP_Change_Recorder::snapshot_attachment( $id ) : array();

		$res = wp_delete_attachment( $id, $force );

		if ( $has_rec && $res && ! empty( $snapshot ) ) {
			KarMCP_Change_Recorder::record_attachment_delete(
				$snapshot,
				$id,
				sprintf( 'Deleted media #%d', $id ),
				trim( (string) $post->post_title . ' (#' . $id . ')' )
			);
		}
		return array(
			'success' => (bool) $res,
			'id'      => $id,
			'deleted' => $trashed ? 'trashed' : 'deleted',
		);
	}

	/**
	 * Normalizes an attachment post into the tool's result shape.
	 *
	 * @since 2.0.2
	 *
	 * @param \WP_Post $attachment The attachment post object.
	 * @return array
	 */
	private function format_attachment( $attachment ): array {
		$id   = (int) $attachment->ID;
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$filesize = 0;
		if ( isset( $meta['filesize'] ) ) {
			$filesize = (int) $meta['filesize'];
		} else {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$filesize = (int) filesize( $file );
			}
		}

		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );

		return array(
			'id'            => $id,
			'title'         => $attachment->post_title,
			'url'           => (string) wp_get_attachment_url( $id ),
			'thumbnail_url' => $thumb ? $thumb : '',
			'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime_type'     => $attachment->post_mime_type,
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'filesize'      => $filesize,
			'date'          => $attachment->post_date_gmt,
		);
	}
}
