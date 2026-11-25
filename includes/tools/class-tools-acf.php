<?php
/**
 * ACF: field group discovery (including flexible content layouts), validation and writes.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Acf {

	public static function active() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'update_field' );
	}

	public static function tools() {
		if ( ! self::active() ) {
			return array();
		}
		return array(
			array(
				'name'         => 'get_acf_field_groups',
				'description'  => 'Describe the ACF field groups that apply to a post type / template / existing post, with every field\'s name, type, choices and, for flexible content, each layout and its sub fields. Read this before building or editing an ACF page so you use real layout and field names.',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer', 'description' => 'Existing post to match location rules against.' ),
						'post_type' => array( 'type' => 'string', 'description' => 'For new content, e.g. "page".' ),
						'template'  => array( 'type' => 'string', 'description' => 'Page template file, if location rules depend on it.' ),
						'options_page' => array( 'type' => 'string', 'description' => 'Options page slug instead of a post.' ),
					),
				),
				'run'          => array( __CLASS__, 'field_groups' ),
			),
			array(
				'name'         => 'get_acf_options',
				'description'  => 'List ACF options pages and their current values (site-wide settings such as header/footer content).',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'options_page' => array( 'type' => 'string', 'description' => 'Slug; omit to list pages only.' ),
					),
				),
				'run'          => array( __CLASS__, 'options_values' ),
			),
			array(
				'name'         => 'update_acf_options',
				'description'  => 'Update ACF fields on an options page. Fields keyed by name, same value format as create_post.acf. The user reviews a diff first.',
				'cap'          => 'acf',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'options_page' => array( 'type' => 'string' ),
						'fields'       => array( 'type' => 'object', 'additionalProperties' => true ),
					),
					'required'   => array( 'options_page', 'fields' ),
				),
				'preview'      => array( __CLASS__, 'preview_options' ),
				'apply'        => array( __CLASS__, 'apply_options' ),
			),
		);
	}

	/* ---------- discovery ---------- */

	private static function groups_for( $post_id = null, $post_type = '', $template = '', $options_page = '' ) {
		if ( $options_page ) {
			return acf_get_field_groups( array( 'options_page' => $options_page ) );
		}
		if ( $post_id ) {
			return acf_get_field_groups( array( 'post_id' => $post_id ) );
		}
		$args = array( 'post_type' => $post_type ?: 'page' );
		if ( $template ) {
			$args['page_template'] = $template;
		}
		return acf_get_field_groups( $args );
	}

	private static function describe_field( array $f, $depth = 0 ) {
		$out = array(
			'name'  => $f['name'],
			'label' => $f['label'],
			'type'  => $f['type'],
		);
		if ( ! empty( $f['required'] ) ) {
			$out['required'] = true;
		}
		if ( ! empty( $f['instructions'] ) ) {
			$out['instructions'] = CSA_Tool::clip( $f['instructions'], 200 );
		}
		if ( ! empty( $f['choices'] ) ) {
			$out['choices'] = $f['choices'];
		}
		if ( isset( $f['default_value'] ) && '' !== $f['default_value'] && array() !== $f['default_value'] ) {
			$out['default'] = $f['default_value'];
		}
		if ( in_array( $f['type'], array( 'image', 'file', 'gallery' ), true ) ) {
			$out['value_format'] = 'attachment ID' . ( 'gallery' === $f['type'] ? 's (array)' : '' );
		}
		if ( in_array( $f['type'], array( 'post_object', 'relationship', 'page_link' ), true ) ) {
			$out['value_format'] = 'post ID' . ( ! empty( $f['multiple'] ) || 'relationship' === $f['type'] ? 's (array)' : '' );
		}
		if ( $depth > 4 ) {
			return $out;
		}
		if ( ! empty( $f['sub_fields'] ) ) {
			$out['sub_fields'] = array_map( fn( $s ) => self::describe_field( $s, $depth + 1 ), $f['sub_fields'] );
		}
		if ( 'flexible_content' === $f['type'] && ! empty( $f['layouts'] ) ) {
			$out['layouts'] = array();
			foreach ( $f['layouts'] as $l ) {
				$out['layouts'][] = array(
					'name'       => $l['name'],
					'label'      => $l['label'],
					'sub_fields' => array_map( fn( $s ) => self::describe_field( $s, $depth + 1 ), $l['sub_fields'] ?? array() ),
				);
			}
		}
		if ( 'clone' === $f['type'] && ! empty( $f['sub_fields'] ) ) {
			$out['note'] = 'Clone field: write its sub fields' . ( ! empty( $f['prefix_name'] ) ? ' prefixed with "' . $f['name'] . '_"' : ' directly' ) . '.';
		}
		return $out;
	}

	public static function field_groups( $input ) {
		$groups = self::groups_for( $input['post_id'] ?? null, $input['post_type'] ?? '', $input['template'] ?? '', $input['options_page'] ?? '' );
		$out    = array();
		foreach ( $groups as $g ) {
			$fields = acf_get_fields( $g );
			$out[]  = array(
				'title'  => $g['title'],
				'key'    => $g['key'],
				'fields' => array_map( array( __CLASS__, 'describe_field' ), $fields ?: array() ),
			);
		}
		return array( 'groups' => $out, 'note' => $out ? 'Write values by field name.' : 'No field groups match. Try another post_type/template, or pass post_id.' );
	}

	public static function options_values( $input ) {
		$pages = function_exists( 'acf_get_options_pages' ) ? (array) acf_get_options_pages() : array();
		$list  = array();
		foreach ( $pages as $slug => $p ) {
			$list[] = array( 'slug' => $slug, 'title' => $p['page_title'] ?? $slug, 'post_id' => $p['post_id'] ?? 'options' );
		}
		if ( empty( $input['options_page'] ) ) {
			return array( 'options_pages' => $list );
		}
		$values = array();
		foreach ( self::groups_for( null, '', '', $input['options_page'] ) as $g ) {
			foreach ( (array) acf_get_fields( $g ) as $f ) {
				$values[ $f['name'] ] = get_field( $f['key'], self::options_post_id( $input['options_page'] ), false );
			}
		}
		return array( 'options_page' => $input['options_page'], 'values' => $values );
	}

	private static function options_post_id( $slug ) {
		if ( function_exists( 'acf_get_options_page' ) ) {
			$p = acf_get_options_page( $slug );
			if ( $p && ! empty( $p['post_id'] ) ) {
				return $p['post_id'];
			}
		}
		return 'option';
	}

	/* ---------- validation & writes ---------- */

	/** Map of field name => field array for the groups that apply. */
	private static function field_map( $post_id, $post_type, $template, $options_page = '' ) {
		$map = array();
		foreach ( self::groups_for( $post_id, $post_type, $template, $options_page ) as $g ) {
			foreach ( (array) acf_get_fields( $g ) as $f ) {
				$map[ $f['name'] ] = $f;
			}
		}
		return $map;
	}

	private static function sub_field_names( array $fields ) {
		$names = array();
		foreach ( $fields as $s ) {
			$names[] = $s['name'];
			$names[] = $s['key'];
		}
		return $names;
	}

	/**
	 * Check names and flexible layouts before anything is written. Returns true or WP_Error.
	 */
	public static function validate_fields( array $values, $post_id, $post_type, $template = '', $options_page = '' ) {
		if ( ! self::active() ) {
			return CSA_Tool::err( 'ACF is not active on this site.' );
		}
		$map = self::field_map( $post_id, $post_type, $template, $options_page );
		foreach ( $values as $name => $value ) {
			if ( ! isset( $map[ $name ] ) ) {
				return CSA_Tool::err( "Unknown ACF field \"$name\" for this content. Available: " . implode( ', ', array_keys( $map ) ) . '. Call get_acf_field_groups.' );
			}
			$f = $map[ $name ];
			if ( 'flexible_content' === $f['type'] ) {
				if ( ! is_array( $value ) ) {
					return CSA_Tool::err( "\"$name\" is flexible content and needs an array of rows." );
				}
				$layouts = array();
				foreach ( (array) $f['layouts'] as $l ) {
					$layouts[ $l['name'] ] = $l;
				}
				foreach ( array_values( $value ) as $i => $row ) {
					$ln = is_array( $row ) ? ( $row['acf_fc_layout'] ?? '' ) : '';
					if ( ! isset( $layouts[ $ln ] ) ) {
						return CSA_Tool::err( "Row $i of \"$name\" has unknown layout \"$ln\". Layouts: " . implode( ', ', array_keys( $layouts ) ) );
					}
					$allowed = self::sub_field_names( $layouts[ $ln ]['sub_fields'] ?? array() );
					foreach ( array_keys( $row ) as $k ) {
						if ( 'acf_fc_layout' !== $k && ! in_array( $k, $allowed, true ) ) {
							return CSA_Tool::err( "Row $i ($ln) of \"$name\" has unknown sub field \"$k\". Sub fields: " . implode( ', ', array_column( $layouts[ $ln ]['sub_fields'] ?? array(), 'name' ) ) );
						}
					}
				}
			}
			if ( 'repeater' === $f['type'] && is_array( $value ) ) {
				$allowed = self::sub_field_names( $f['sub_fields'] ?? array() );
				foreach ( array_values( $value ) as $i => $row ) {
					foreach ( array_keys( (array) $row ) as $k ) {
						if ( ! in_array( $k, $allowed, true ) ) {
							return CSA_Tool::err( "Row $i of repeater \"$name\" has unknown sub field \"$k\"." );
						}
					}
				}
			}
		}
		return true;
	}

	public static function describe_changes( array $current, array $new ) {
		$changes = array();
		foreach ( $new as $name => $value ) {
			$before = $current[ $name ] ?? '';
			$changes[] = array(
				'label'  => "ACF: $name",
				'before' => is_scalar( $before ) ? (string) $before : wp_json_encode( $before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'after'  => is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			);
		}
		return $changes;
	}

	/** Write values by resolving names to field keys (needed for new posts with no references yet). */
	public static function write_fields( array $values, $post_id, $options_page = '' ) {
		$p    = is_numeric( $post_id ) ? get_post( $post_id ) : null;
		$map  = self::field_map( $p ? $p->ID : null, $p ? $p->post_type : '', '', $options_page );
		$done = array();
		foreach ( $values as $name => $value ) {
			if ( ! isset( $map[ $name ] ) ) {
				return CSA_Tool::err( "Unknown ACF field \"$name\"." );
			}
			if ( is_string( $value ) && in_array( $map[ $name ]['type'], array( 'wysiwyg', 'textarea', 'text' ), true ) ) {
				$value = CSA_Tool::filter_content( $value );
			}
			update_field( $map[ $name ]['key'], $value, $post_id );
			$done[] = $name;
		}
		return array( 'fields_written' => $done );
	}

	/* ---------- options pages ---------- */

	public static function preview_options( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return CSA_Tool::err( 'Editing options pages requires the manage_options capability.' );
		}
		$ok = self::validate_fields( (array) $input['fields'], null, '', '', $input['options_page'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$pid     = self::options_post_id( $input['options_page'] );
		$current = array();
		foreach ( array_keys( (array) $input['fields'] ) as $name ) {
			$current[ $name ] = get_field( $name, $pid, false );
		}
		return array(
			'summary' => 'Update options page "' . $input['options_page'] . '"',
			'risk'    => 'settings',
			'changes' => self::describe_changes( $current, (array) $input['fields'] ),
		);
	}

	public static function apply_options( $input, CSA_Changeset $cs ) {
		$pid    = self::options_post_id( $input['options_page'] );
		$prefix = ( 'option' === $pid || 'options' === $pid ) ? 'options_' : $pid . '_';
		$names  = array_keys( (array) $input['fields'] );
		// Snapshot each field and all of its nested rows (options_<name>, options_<name>_0_x, ...).
		$before = self::option_rows( $prefix, $names );
		foreach ( $before as $opt ) {
			$cs->before_option( $opt );
		}
		$res = self::write_fields( (array) $input['fields'], $pid, $input['options_page'] );
		foreach ( array_diff( self::option_rows( $prefix, $names ), $before ) as $opt ) {
			$cs->created_option( $opt );
		}
		return $res;
	}

	private static function option_rows( $prefix, array $names ) {
		global $wpdb;
		$rows = array();
		foreach ( $names as $name ) {
			$like = $wpdb->esc_like( $prefix . $name ) . '%';
			$rows = array_merge( $rows, $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s", $like, '\\_' . $like ) ) );
		}
		return array_values( array_unique( $rows ) );
	}
}
