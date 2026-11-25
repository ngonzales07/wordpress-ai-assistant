<?php
/**
 * Media library: search, edit details (alt/title/caption), import from URL.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Media {

	public static function tools() {
		return array(
			array(
				'name'         => 'search_media',
				'description'  => 'Search the media library. Can filter to images missing alt text or files above a size, which helps accessibility and page-speed work.',
				'cap'          => 'media',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'       => array( 'type' => 'string' ),
						'mime'        => array( 'type' => 'string', 'description' => 'e.g. "image", "image/png", "application/pdf".' ),
						'missing_alt' => array( 'type' => 'boolean' ),
						'min_kb'      => array( 'type' => 'integer', 'description' => 'Only files at least this large.' ),
						'ids'         => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
						'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
				),
				'run'          => array( __CLASS__, 'search' ),
			),
			array(
				'name'         => 'update_media',
				'description'  => 'Update alt text, title, caption or description for one or more attachments (max 100). The user approves first.',
				'cap'          => 'media',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'items' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 100,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'id'          => array( 'type' => 'integer' ),
									'alt'         => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'caption'     => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
								'required'   => array( 'id' ),
							),
						),
					),
					'required'   => array( 'items' ),
				),
				'preview'      => array( __CLASS__, 'preview_update' ),
				'apply'        => array( __CLASS__, 'apply_update' ),
			),
			array(
				'name'         => 'import_media',
				'description'  => 'Download an image or PDF from a public URL into the media library. Returns the new attachment ID for use in content or ACF image fields.',
				'cap'          => 'media',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'       => array( 'type' => 'string' ),
						'title'     => array( 'type' => 'string' ),
						'alt'       => array( 'type' => 'string' ),
						'attach_to' => array( 'type' => 'integer', 'description' => 'Optional parent post ID.' ),
					),
					'required'   => array( 'url' ),
				),
				'preview'      => array( __CLASS__, 'preview_import' ),
				'apply'        => array( __CLASS__, 'apply_import' ),
			),
		);
	}

	private static function row( WP_Post $a ) {
		$meta = wp_get_attachment_metadata( $a->ID );
		$file = get_attached_file( $a->ID );
		$size = $meta['filesize'] ?? ( $file && file_exists( $file ) ? filesize( $file ) : null );
		return array(
			'id'          => $a->ID,
			'title'       => $a->post_title,
			'alt'         => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $a->post_excerpt,
			'mime'        => $a->post_mime_type,
			'url'         => wp_get_attachment_url( $a->ID ),
			'width'       => $meta['width'] ?? null,
			'height'      => $meta['height'] ?? null,
			'kb'          => $size ? round( $size / 1024 ) : null,
			'attached_to' => $a->post_parent,
		);
	}

	public static function search( $input ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => min( 100, max( 1, (int) ( $input['limit'] ?? 40 ) ) ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $input['query'] ) ) {
			$args['s'] = $input['query'];
		}
		if ( ! empty( $input['mime'] ) ) {
			$args['post_mime_type'] = $input['mime'];
		}
		if ( ! empty( $input['ids'] ) ) {
			$args['post__in'] = array_map( 'intval', $input['ids'] );
		}
		if ( ! empty( $input['missing_alt'] ) ) {
			$args['post_mime_type'] = $args['post_mime_type'] ?? 'image';
			$args['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			);
		}
		$rows = array();
		foreach ( get_posts( $args ) as $a ) {
			$r = self::row( $a );
			if ( ! empty( $input['min_kb'] ) && ( null === $r['kb'] || $r['kb'] < (int) $input['min_kb'] ) ) {
				continue;
			}
			$rows[] = $r;
		}
		return array( 'count' => count( $rows ), 'items' => $rows );
	}

	public static function preview_update( $input ) {
		$changes = array();
		$map     = array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' );
		foreach ( (array) $input['items'] as $item ) {
			$a = get_post( (int) $item['id'] );
			if ( ! $a || 'attachment' !== $a->post_type ) {
				return CSA_Tool::err( "Attachment {$item['id']} not found." );
			}
			if ( ! current_user_can( 'edit_post', $a->ID ) ) {
				return CSA_Tool::err( "You cannot edit attachment {$a->ID}." );
			}
			if ( isset( $item['alt'] ) ) {
				$changes[] = array( 'label' => "#{$a->ID} alt", 'before' => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ), 'after' => $item['alt'] );
			}
			foreach ( $map as $k => $col ) {
				if ( isset( $item[ $k ] ) ) {
					$changes[] = array( 'label' => "#{$a->ID} $k", 'before' => $a->$col, 'after' => $item[ $k ] );
				}
			}
		}
		$n = count( (array) $input['items'] );
		return array(
			'summary' => "Update details on $n media item(s)",
			'risk'    => $n > 1 ? 'bulk' : 'single',
			'changes' => $changes,
		);
	}

	public static function apply_update( $input, CSA_Changeset $cs ) {
		$done = array();
		foreach ( (array) $input['items'] as $item ) {
			$id = (int) $item['id'];
			$cs->before_post( $id );
			if ( isset( $item['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $item['alt'] ) );
			}
			$upd = array( 'ID' => $id );
			if ( isset( $item['title'] ) ) {
				$upd['post_title'] = sanitize_text_field( $item['title'] );
			}
			if ( isset( $item['caption'] ) ) {
				$upd['post_excerpt'] = wp_kses_post( $item['caption'] );
			}
			if ( isset( $item['description'] ) ) {
				$upd['post_content'] = wp_kses_post( $item['description'] );
			}
			if ( count( $upd ) > 1 ) {
				wp_update_post( wp_slash( $upd ) );
			}
			$done[] = $id;
		}
		return array( 'updated' => $done );
	}

	public static function preview_import( $input ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return CSA_Tool::err( 'You do not have permission to upload files.' );
		}
		if ( ! CSA_Guard::media_url_allowed( $input['url'] ) ) {
			return CSA_Tool::err( 'Only public http(s) URLs can be imported.' );
		}
		$ext = strtolower( pathinfo( wp_parse_url( $input['url'], PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
		if ( $ext && ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'pdf' ), true ) ) {
			return CSA_Tool::err( 'Only images and PDFs can be imported.' );
		}
		return array(
			'summary' => 'Import media from ' . $input['url'],
			'risk'    => 'single',
			'changes' => array( array( 'label' => 'New attachment', 'before' => '', 'after' => $input['url'] . ( ! empty( $input['alt'] ) ? "\nalt: " . $input['alt'] : '' ) ) ),
		);
	}

	public static function apply_import( $input, CSA_Changeset $cs ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $input['url'], 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name = basename( (string) wp_parse_url( $input['url'], PHP_URL_PATH ) ) ?: 'import';
		$file = array( 'name' => sanitize_file_name( $name ), 'tmp_name' => $tmp );
		$id   = media_handle_sideload( $file, (int) ( $input['attach_to'] ?? 0 ), $input['title'] ?? null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return $id;
		}
		$cs->created_post( $id );
		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}
		return array( 'attachment_id' => $id, 'url' => wp_get_attachment_url( $id ) );
	}
}
