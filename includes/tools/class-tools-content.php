<?php
/**
 * Posts and pages: search, read, create, update, bulk replace, trash.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Content {

	const BULK_MAX = 200;

	public static function tools() {
		$post_fields = array(
			'title'             => array( 'type' => 'string' ),
			'content'           => array( 'type' => 'string', 'description' => 'Full post_content (HTML or block markup). On ACF flexible-content pages this is usually empty; use "acf" instead.' ),
			'excerpt'           => array( 'type' => 'string' ),
			'status'            => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'private', 'publish', 'future' ) ),
			'date'              => array( 'type' => 'string', 'description' => 'Publish date (Y-m-d H:i:s, site time). Use with status "future" to schedule.' ),
			'slug'              => array( 'type' => 'string' ),
			'parent'            => array( 'type' => 'integer' ),
			'menu_order'        => array( 'type' => 'integer' ),
			'template'          => array( 'type' => 'string', 'description' => 'Page template file from get_site_info.page_templates, or "default".' ),
			'featured_image_id' => array( 'type' => 'integer' ),
			'acf'               => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'ACF field values keyed by field NAME (from get_acf_field_groups). Flexible content: an array of rows, each {"acf_fc_layout": "<layout name>", "<sub field name>": value}. Repeaters: array of row objects. Images/files: attachment IDs. Links: {"url","title","target"}.',
			),
			'meta'              => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => 'Plain (non-ACF) post meta. Protected keys starting with "_" are refused.',
			),
		);

		return array(
			array(
				'name'         => 'search_content',
				'description'  => 'Find posts/pages containing text. Searches title, content, excerpt and (by default) custom-field / ACF values. Returns matching items with short context snippets. Use before bulk edits to see the scope.',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'         => array( 'type' => 'string', 'description' => 'Text to find. Empty lists recent items.' ),
						'post_types'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Default: all editable types.' ),
						'statuses'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Default: publish, draft, pending, private, future.' ),
						'include_meta'  => array( 'type' => 'boolean', 'description' => 'Also search custom fields (default true).' ),
						'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
				),
				'run'          => array( __CLASS__, 'search' ),
			),
			array(
				'name'         => 'get_post',
				'description'  => 'Read one post/page: fields, content, template, featured image, terms and ACF values (raw, as used for writing).',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'id' ),
				),
				'run'          => array( __CLASS__, 'get' ),
			),
			array(
				'name'         => 'create_post',
				'description'  => 'Create a page, post or custom post type item. Created as a draft unless a status is given. For ACF-built pages, set "template" if needed and pass the layout rows in "acf". The user reviews a preview before it is created.',
				'cap'          => 'content_write',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'post_type' => array( 'type' => 'string', 'description' => 'e.g. "page" or "post".' ) ) + $post_fields,
					'required'   => array( 'post_type', 'title' ),
				),
				'preview'      => array( __CLASS__, 'preview_create' ),
				'apply'        => array( __CLASS__, 'apply_create' ),
			),
			array(
				'name'         => 'update_post',
				'description'  => 'Change one existing post/page. Only the fields you pass are changed. ACF fields not passed are left alone, but a flexible content or repeater field you pass is replaced as a whole, so send every row you want to keep. The user reviews a diff first.',
				'cap'          => 'content_write',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ) + $post_fields,
					'required'   => array( 'id' ),
				),
				'preview'      => array( __CLASS__, 'preview_update' ),
				'apply'        => array( __CLASS__, 'apply_update' ),
			),
			array(
				'name'         => 'bulk_replace',
				'description'  => 'Search and replace text across many posts/pages at once (title, content, excerpt and plain-text custom fields, including ACF text/wysiwyg values). Serialized values are skipped and reported. Max ' . self::BULK_MAX . ' items per call. Always shown to the user as a preview first.',
				'cap'          => 'content_bulk',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'         => array( 'type' => 'string' ),
						'replace'        => array( 'type' => 'string' ),
						'regex'          => array( 'type' => 'boolean', 'description' => 'Treat search as a PCRE pattern without delimiters.' ),
						'case_sensitive' => array( 'type' => 'boolean' ),
						'post_types'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'statuses'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'post_ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Limit to these items.' ),
						'fields'         => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => array( 'title', 'content', 'excerpt', 'meta' ) ), 'description' => 'Default: all four.' ),
					),
					'required'   => array( 'search', 'replace' ),
				),
				'preview'      => array( __CLASS__, 'preview_bulk' ),
				'apply'        => array( __CLASS__, 'apply_bulk' ),
			),
			array(
				'name'         => 'trash_posts',
				'description'  => 'Move posts/pages to the trash (recoverable). Never permanently deletes.',
				'cap'          => 'content_bulk',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'minItems' => 1, 'maxItems' => 100 ),
					),
					'required'   => array( 'ids' ),
				),
				'preview'      => array( __CLASS__, 'preview_trash' ),
				'apply'        => array( __CLASS__, 'apply_trash' ),
			),
		);
	}

	/* ---------- helpers ---------- */

	private static function editable_types( $requested = array() ) {
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ) ) as $t ) {
			if ( 'attachment' !== $t && CSA_Guard::post_type_writable( $t ) ) {
				$types[] = $t;
			}
		}
		return $requested ? array_values( array_intersect( $types, (array) $requested ) ) : $types;
	}

	private static function statuses( $requested = array() ) {
		$all = array( 'publish', 'draft', 'pending', 'private', 'future' );
		return $requested ? array_values( array_intersect( $all, (array) $requested ) ) : $all;
	}

	private static function snippet( $text, $needle, $width = 90 ) {
		$text = wp_strip_all_tags( (string) $text );
		$pos  = '' === $needle ? 0 : mb_stripos( $text, $needle );
		if ( false === $pos ) {
			return mb_substr( $text, 0, $width * 2 );
		}
		$start = max( 0, $pos - $width );
		return ( $start > 0 ? '…' : '' ) . mb_substr( $text, $start, $width * 2 + mb_strlen( $needle ) ) . '…';
	}

	/* ---------- read ---------- */

	public static function search( $input ) {
		global $wpdb;
		$q        = (string) ( $input['query'] ?? '' );
		$types    = self::editable_types( $input['post_types'] ?? array() );
		$statuses = self::statuses( $input['statuses'] ?? array() );
		$limit    = min( 100, max( 1, (int) ( $input['limit'] ?? 30 ) ) );
		$meta     = ! isset( $input['include_meta'] ) || $input['include_meta'];
		if ( ! $types ) {
			return CSA_Tool::err( 'No editable post types match.' );
		}
		$tp = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sp = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		if ( '' === $q ) {
			$sql = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ($tp) AND post_status IN ($sp) ORDER BY post_modified DESC LIMIT %d", array_merge( $types, $statuses, array( $limit ) ) ); // phpcs:ignore
		} else {
			$like      = '%' . $wpdb->esc_like( $q ) . '%';
			$meta_part = $meta ? " OR ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key NOT LIKE '\\_%' AND meta_value LIKE %s)" : '';
			$params    = array_merge( $types, $statuses, array( $like, $like, $like ), $meta ? array( $like ) : array(), array( $limit ) );
			$sql       = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ($tp) AND post_status IN ($sp) AND (post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s $meta_part) ORDER BY post_modified DESC LIMIT %d", $params ); // phpcs:ignore
		}
		$ids     = $wpdb->get_col( $sql ); // phpcs:ignore
		$results = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || ! current_user_can( 'read_post', $p->ID ) ) {
				continue;
			}
			$row = CSA_Tool::post_summary( $p );
			if ( '' !== $q ) {
				$where = array();
				foreach ( array( 'post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt' ) as $col => $label ) {
					if ( false !== mb_stripos( $p->$col, $q ) ) {
						$where[ $label ] = self::snippet( $p->$col, $q );
					}
				}
				if ( $meta ) {
					foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
						if ( '_' === $k[0] ) {
							continue;
						}
						foreach ( $vals as $v ) {
							if ( false !== mb_stripos( $v, $q ) ) {
								$where[ 'meta:' . $k ] = self::snippet( $v, $q );
								break;
							}
						}
						if ( count( $where ) > 8 ) {
							break;
						}
					}
				}
				$row['matches'] = $where;
			}
			$results[] = $row;
		}
		return array( 'count' => count( $results ), 'limit' => $limit, 'items' => $results );
	}

	public static function get( $input ) {
		$p = get_post( (int) $input['id'] );
		if ( ! $p || ! current_user_can( 'read_post', $p->ID ) ) {
			return CSA_Tool::err( 'Post not found or not readable.' );
		}
		$out             = CSA_Tool::post_summary( $p );
		$out['date']     = $p->post_date;
		$out['excerpt']  = $p->post_excerpt;
		$out['content']  = CSA_Tool::clip( $p->post_content, 40000 );
		$out['template'] = get_page_template_slug( $p ) ?: 'default';
		$out['featured_image_id'] = (int) get_post_thumbnail_id( $p );
		$out['menu_order'] = $p->menu_order;
		if ( has_blocks( $p->post_content ) ) {
			$out['blocks'] = array_values( array_filter( wp_list_pluck( parse_blocks( $p->post_content ), 'blockName' ) ) );
		}
		$terms = array();
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$names = wp_get_post_terms( $p->ID, $tax, array( 'fields' => 'names' ) );
			if ( $names && ! is_wp_error( $names ) ) {
				$terms[ $tax ] = $names;
			}
		}
		$out['terms'] = $terms;
		if ( function_exists( 'get_fields' ) ) {
			$acf        = get_fields( $p->ID, false );
			$out['acf'] = $acf ?: new stdClass();
		}
		$meta = array();
		foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
			if ( '_' === $k[0] || ( isset( $out['acf'] ) && is_array( $out['acf'] ) && self::is_acf_meta( $k, $p->ID ) ) ) {
				continue;
			}
			$meta[ $k ] = CSA_Tool::clip( maybe_serialize( $vals[0] ), 500 );
		}
		if ( $meta ) {
			$out['meta'] = $meta;
		}
		return $out;
	}

	private static function is_acf_meta( $key, $post_id ) {
		$ref = get_post_meta( $post_id, '_' . $key, true );
		return is_string( $ref ) && 0 === strpos( $ref, 'field_' );
	}

	/* ---------- create / update ---------- */

	private static function map_fields( array $in ) {
		$map = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'date'       => 'post_date',
			'slug'       => 'post_name',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
		);
		$out = array();
		foreach ( $map as $k => $col ) {
			if ( array_key_exists( $k, $in ) ) {
				$out[ $col ] = $in[ $k ];
			}
		}
		if ( isset( $out['post_content'] ) ) {
			$out['post_content'] = CSA_Tool::filter_content( $out['post_content'] );
		}
		if ( isset( $out['post_title'] ) ) {
			$out['post_title'] = sanitize_text_field( $out['post_title'] );
		}
		if ( isset( $out['post_name'] ) ) {
			$out['post_name'] = sanitize_title( $out['post_name'] );
		}
		return $out;
	}

	private static function check_status( $post_type, $status ) {
		if ( in_array( $status, array( 'publish', 'future' ), true ) ) {
			$pto = get_post_type_object( $post_type );
			if ( ! $pto || ! current_user_can( $pto->cap->publish_posts ) ) {
				return CSA_Tool::err( 'You do not have permission to publish this post type.' );
			}
		}
		return true;
	}

	private static function check_meta_and_acf( $input, $post_type, $post_id = 0 ) {
		foreach ( array_keys( (array) ( $input['meta'] ?? array() ) ) as $k ) {
			if ( ! CSA_Guard::meta_key_writable( $k ) ) {
				return CSA_Tool::err( "Meta key \"$k\" is protected." );
			}
		}
		if ( ! empty( $input['acf'] ) ) {
			if ( ! in_array( 'acf', CSA_Settings::user_caps(), true ) ) {
				return CSA_Tool::err( 'ACF editing is not enabled for your role.' );
			}
			return CSA_Tools_Acf::validate_fields( (array) $input['acf'], $post_id ?: null, $post_type, $input['template'] ?? '' );
		}
		return true;
	}

	public static function preview_create( $input ) {
		$type = (string) $input['post_type'];
		if ( ! CSA_Guard::post_type_writable( $type ) || 'attachment' === $type ) {
			return CSA_Tool::err( "Post type \"$type\" cannot be created by the agent." );
		}
		$pto = get_post_type_object( $type );
		if ( ! current_user_can( $pto->cap->create_posts ?? $pto->cap->edit_posts ) ) {
			return CSA_Tool::err( 'You do not have permission to create this post type.' );
		}
		$status = $input['status'] ?? CSA_Settings::get( 'default_status' );
		$ok     = self::check_status( $type, $status );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$ok = self::check_meta_and_acf( $input, $type );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$changes = array(
			array( 'label' => 'Title', 'before' => '', 'after' => (string) $input['title'] ),
			array( 'label' => 'Status', 'before' => '', 'after' => $status ),
		);
		foreach ( array( 'slug', 'template', 'parent', 'featured_image_id', 'excerpt' ) as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$changes[] = array( 'label' => ucfirst( str_replace( '_', ' ', $f ) ), 'before' => '', 'after' => (string) $input[ $f ] );
			}
		}
		if ( ! empty( $input['content'] ) ) {
			$changes[] = array( 'label' => 'Content', 'before' => '', 'after' => (string) $input['content'] );
		}
		if ( ! empty( $input['acf'] ) ) {
			$changes = array_merge( $changes, CSA_Tools_Acf::describe_changes( array(), (array) $input['acf'] ) );
		}
		if ( ! empty( $input['meta'] ) ) {
			foreach ( (array) $input['meta'] as $k => $v ) {
				$changes[] = array( 'label' => "Meta: $k", 'before' => '', 'after' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_PRETTY_PRINT ) );
			}
		}
		return array(
			'summary' => sprintf( 'Create %s "%s" as %s', $pto->labels->singular_name, $input['title'], $status ),
			'risk'    => in_array( $status, array( 'publish', 'future' ), true ) ? 'publish' : 'single',
			'changes' => $changes,
		);
	}

	public static function apply_create( $input, CSA_Changeset $cs ) {
		$fields              = self::map_fields( $input );
		$fields['post_type'] = $input['post_type'];
		$fields['post_status'] = $input['status'] ?? CSA_Settings::get( 'default_status' );
		$fields['post_author'] = get_current_user_id();
		$id = wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$cs->created_post( $id );
		$res = self::write_extras( $id, $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$p = get_post( $id );
		return array( 'created' => CSA_Tool::post_summary( $p ), 'preview_url' => get_preview_post_link( $p ) ) + $res;
	}

	private static function write_extras( $id, $input ) {
		$notes = array();
		if ( isset( $input['template'] ) ) {
			update_post_meta( $id, '_wp_page_template', 'default' === $input['template'] ? 'default' : sanitize_text_field( $input['template'] ) );
		}
		if ( isset( $input['featured_image_id'] ) ) {
			if ( $input['featured_image_id'] ) {
				set_post_thumbnail( $id, (int) $input['featured_image_id'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}
		foreach ( (array) ( $input['meta'] ?? array() ) as $k => $v ) {
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
		if ( ! empty( $input['acf'] ) ) {
			$r = CSA_Tools_Acf::write_fields( (array) $input['acf'], $id );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$notes = $r;
		}
		return $notes ? array( 'acf' => $notes ) : array();
	}

	public static function preview_update( $input ) {
		$p = CSA_Tool::editable_post( $input['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		if ( isset( $input['status'] ) ) {
			$ok = self::check_status( $p->post_type, $input['status'] );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$ok = self::check_meta_and_acf( $input, $p->post_type, $p->ID );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$changes = array();
		$fields  = self::map_fields( $input );
		foreach ( $fields as $col => $new ) {
			$old = (string) $p->$col;
			if ( (string) $new !== $old ) {
				$changes[] = array( 'label' => ucfirst( str_replace( 'post_', '', $col ) ), 'before' => $old, 'after' => (string) $new );
			}
		}
		if ( isset( $input['template'] ) ) {
			$old = get_page_template_slug( $p ) ?: 'default';
			if ( $old !== $input['template'] ) {
				$changes[] = array( 'label' => 'Template', 'before' => $old, 'after' => (string) $input['template'] );
			}
		}
		if ( isset( $input['featured_image_id'] ) && (int) get_post_thumbnail_id( $p ) !== (int) $input['featured_image_id'] ) {
			$changes[] = array( 'label' => 'Featured image', 'before' => (string) get_post_thumbnail_id( $p ), 'after' => (string) $input['featured_image_id'] );
		}
		foreach ( (array) ( $input['meta'] ?? array() ) as $k => $v ) {
			$old = get_post_meta( $p->ID, $k, true );
			$changes[] = array( 'label' => "Meta: $k", 'before' => is_scalar( $old ) ? (string) $old : wp_json_encode( $old, JSON_PRETTY_PRINT ), 'after' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v, JSON_PRETTY_PRINT ) );
		}
		if ( ! empty( $input['acf'] ) ) {
			$current = function_exists( 'get_fields' ) ? (array) get_fields( $p->ID, false ) : array();
			$changes = array_merge( $changes, CSA_Tools_Acf::describe_changes( $current, (array) $input['acf'] ) );
		}
		if ( ! $changes ) {
			return CSA_Tool::err( 'Nothing would change: the values passed match what is already saved.' );
		}
		$live      = in_array( $p->post_status, array( 'publish', 'future' ), true );
		$publishes = isset( $input['status'] ) && in_array( $input['status'], array( 'publish', 'future' ), true ) && ! $live;
		return array(
			'summary'     => sprintf( 'Edit "%s" (%s #%d%s)', get_the_title( $p ), $p->post_type, $p->ID, $live ? ', live' : '' ),
			'risk'        => ( $live || $publishes ) ? 'publish' : 'single',
			'changes'     => $changes,
			'link'        => get_permalink( $p ),
			'fingerprint' => $p->ID . ':' . $p->post_modified_gmt,
		);
	}

	public static function apply_update( $input, CSA_Changeset $cs ) {
		$p = CSA_Tool::editable_post( $input['id'] );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$cs->before_post( $p->ID );
		$fields = self::map_fields( $input );
		if ( $fields ) {
			$fields['ID'] = $p->ID;
			$r            = wp_update_post( wp_slash( $fields ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		$res = self::write_extras( $p->ID, $input );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'updated' => CSA_Tool::post_summary( get_post( $p->ID ) ) ) + $res;
	}

	/* ---------- bulk replace ---------- */

	private static function bulk_plan( $input ) {
		global $wpdb;
		$search = (string) $input['search'];
		if ( '' === $search ) {
			return CSA_Tool::err( 'Search text cannot be empty.' );
		}
		$regex = ! empty( $input['regex'] );
		$cs    = ! empty( $input['case_sensitive'] );
		if ( $regex ) {
			$pattern = '~' . str_replace( '~', '\~', $search ) . '~u' . ( $cs ? '' : 'i' );
			if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return CSA_Tool::err( 'Invalid regular expression.' );
			}
		} else {
			$pattern = '~' . preg_quote( $search, '~' ) . '~u' . ( $cs ? '' : 'i' );
		}
		$replace = (string) $input['replace'];
		if ( ! $regex ) {
			$replace = str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $replace );
		}
		$fields   = ! empty( $input['fields'] ) ? (array) $input['fields'] : array( 'title', 'content', 'excerpt', 'meta' );
		$types    = self::editable_types( $input['post_types'] ?? array() );
		$statuses = self::statuses( $input['statuses'] ?? array() );
		if ( ! $types ) {
			return CSA_Tool::err( 'No editable post types match.' );
		}
		$tp = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sp = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$id_clause = '';
		$params    = array_merge( $types, $statuses );
		if ( ! empty( $input['post_ids'] ) ) {
			$ids       = array_map( 'intval', (array) $input['post_ids'] );
			$id_clause = ' AND ID IN (' . implode( ',', $ids ) . ')';
		}
		if ( $regex ) {
			// Candidates are scanned in PHP because MySQL REGEXP is not PCRE.
			$sql = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ($tp) AND post_status IN ($sp) $id_clause ORDER BY ID LIMIT 3000", $params ); // phpcs:ignore
		} else {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type IN ($tp) AND post_status IN ($sp) $id_clause AND (post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s OR ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key NOT LIKE '\\_%%' AND meta_value LIKE %s)) ORDER BY ID LIMIT 3000", array_merge( $params, array( $like, $like, $like, $like ) ) ); // phpcs:ignore
		}
		$plan    = array();
		$skipped = array();
		foreach ( $wpdb->get_col( $sql ) as $id ) { // phpcs:ignore
			$p = get_post( $id );
			if ( ! $p ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $p->ID ) ) {
				$skipped[] = "#$id (no permission)";
				continue;
			}
			$edits = array();
			foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $f => $col ) {
				if ( in_array( $f, $fields, true ) && preg_match( $pattern, $p->$col ) ) {
					$new = preg_replace( $pattern, $replace, $p->$col, -1, $n );
					$edits[] = array( 'field' => $col, 'old' => $p->$col, 'new' => $new, 'count' => $n );
				}
			}
			if ( in_array( 'meta', $fields, true ) ) {
				foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
					if ( ! CSA_Guard::meta_key_writable( $k ) ) {
						continue;
					}
					foreach ( $vals as $v ) {
						if ( ! preg_match( $pattern, $v ) ) {
							continue;
						}
						if ( is_serialized( $v ) ) {
							$skipped[] = "#$id meta \"$k\" (serialized value)";
							continue;
						}
						$new     = preg_replace( $pattern, $replace, $v, -1, $n );
						$edits[] = array( 'field' => 'meta:' . $k, 'old' => $v, 'new' => $new, 'count' => $n );
					}
				}
			}
			if ( $edits ) {
				$plan[ $p->ID ] = array( 'post' => $p, 'edits' => $edits );
			}
			if ( count( $plan ) > self::BULK_MAX ) {
				return CSA_Tool::err( 'More than ' . self::BULK_MAX . ' items match. Narrow it with post_types, statuses or post_ids and run it in batches.' );
			}
		}
		return array( 'plan' => $plan, 'skipped' => $skipped, 'needle' => $regex ? '' : $search );
	}

	public static function preview_bulk( $input ) {
		$r = self::bulk_plan( $input );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r['plan'] ) {
			return CSA_Tool::err( 'No matches found.' . ( $r['skipped'] ? ' Skipped: ' . implode( ', ', $r['skipped'] ) : '' ) );
		}
		$changes = array();
		$total   = 0;
		$fp      = array();
		foreach ( $r['plan'] as $id => $row ) {
			$fp[] = $id . ':' . $row['post']->post_modified_gmt;
			foreach ( $row['edits'] as $e ) {
				$total    += $e['count'];
				$changes[] = array(
					'label'  => sprintf( '#%d %s — %s (%d)', $id, get_the_title( $row['post'] ), $e['field'], $e['count'] ),
					'before' => CSA_Tool::clip( $e['old'], 6000 ),
					'after'  => CSA_Tool::clip( $e['new'], 6000 ),
					'link'   => get_permalink( $row['post'] ),
				);
			}
		}
		return array(
			'summary'     => sprintf( 'Replace "%s" with "%s": %d replacements in %d items', $input['search'], $input['replace'], $total, count( $r['plan'] ) ),
			'risk'        => 'bulk',
			'changes'     => $changes,
			'notes'       => $r['skipped'] ? 'Skipped: ' . implode( ', ', array_slice( $r['skipped'], 0, 30 ) ) : '',
			'fingerprint' => md5( implode( '|', $fp ) ),
		);
	}

	public static function apply_bulk( $input, CSA_Changeset $cs ) {
		$r = self::bulk_plan( $input );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$done = array();
		foreach ( $r['plan'] as $id => $row ) {
			$cs->before_post( $id );
			$update = array( 'ID' => $id );
			foreach ( $row['edits'] as $e ) {
				if ( 0 === strpos( $e['field'], 'meta:' ) ) {
					update_post_meta( $id, substr( $e['field'], 5 ), wp_slash( $e['new'] ), $e['old'] );
				} else {
					$update[ $e['field'] ] = 'post_content' === $e['field'] ? CSA_Tool::filter_content( $e['new'] ) : $e['new'];
				}
			}
			if ( count( $update ) > 1 ) {
				wp_update_post( wp_slash( $update ) );
			}
			$done[] = $id;
		}
		return array( 'updated_ids' => $done, 'skipped' => $r['skipped'] );
	}

	/* ---------- trash ---------- */

	public static function preview_trash( $input ) {
		$changes = array();
		foreach ( (array) $input['ids'] as $id ) {
			$p = CSA_Tool::editable_post( $id );
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			if ( ! current_user_can( 'delete_post', $p->ID ) ) {
				return CSA_Tool::err( "You cannot delete #$id." );
			}
			$changes[] = array( 'label' => sprintf( '#%d %s (%s)', $p->ID, get_the_title( $p ), $p->post_status ), 'before' => $p->post_status, 'after' => 'trash', 'link' => get_permalink( $p ) );
		}
		return array(
			'summary' => 'Move ' . count( $changes ) . ' item(s) to the trash',
			'risk'    => 'destructive',
			'changes' => $changes,
		);
	}

	public static function apply_trash( $input, CSA_Changeset $cs ) {
		$done = array();
		foreach ( (array) $input['ids'] as $id ) {
			$cs->before_post( $id );
			if ( wp_trash_post( (int) $id ) ) {
				$done[] = (int) $id;
			}
		}
		return array( 'trashed' => $done );
	}
}
