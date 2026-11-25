<?php
/**
 * Site overview, read-only theme access, and allowlisted options.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Site {

	public static function tools() {
		return array(
			array(
				'name'         => 'get_site_info',
				'description'  => 'Overview of this WordPress site: theme, post types with counts, page templates, whether ACF is active, menus, front page settings, and which agent capabilities the current user has. Call this first in a new conversation.',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'run'          => array( __CLASS__, 'site_info' ),
			),
			array(
				'name'         => 'list_theme_files',
				'description'  => 'List files in the active theme (and parent theme) that the agent can read: templates, ACF layout partials, CSS/SCSS/JS. Read-only; theme files are never edited by the agent.',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'subdir' => array( 'type' => 'string', 'description' => 'Optional subfolder, e.g. "template-parts/blocks".' ),
					),
				),
				'run'          => array( __CLASS__, 'list_theme_files' ),
			),
			array(
				'name'         => 'read_theme_file',
				'description'  => 'Read a file from the active theme (relative path), e.g. a flexible-content layout template, to understand how ACF fields render and which CSS classes exist. Read-only.',
				'cap'          => 'content_read',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array( 'type' => 'string' ),
					),
					'required'   => array( 'path' ),
				),
				'run'          => array( __CLASS__, 'read_theme_file' ),
			),
			array(
				'name'         => 'update_site_options',
				'description'  => 'Change basic WordPress options from a fixed allowlist: ' . implode( ', ', CSA_Guard::option_allowlist() ) . '. Anything else (URLs, plugins, users, permalinks, hosting) is refused.',
				'cap'          => 'settings',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'options' => array(
							'type'                 => 'object',
							'description'          => 'Map of option name to new value.',
							'additionalProperties' => true,
						),
					),
					'required'   => array( 'options' ),
				),
				'preview'      => array( __CLASS__, 'preview_options' ),
				'apply'        => array( __CLASS__, 'apply_options' ),
			),
		);
	}

	public static function site_info() {
		$theme = wp_get_theme();
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( ! CSA_Guard::post_type_writable( $pt->name ) && 'attachment' !== $pt->name ) {
				continue;
			}
			$counts            = (array) wp_count_posts( $pt->name );
			$types[ $pt->name ] = array(
				'label'        => $pt->label,
				'hierarchical' => $pt->hierarchical,
				'counts'       => array_filter( array_map( 'intval', $counts ) ),
			);
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		$all     = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$plugins[] = $all[ $file ]['Name'] . ' ' . $all[ $file ]['Version'];
			}
		}
		$menus = array();
		foreach ( wp_get_nav_menus() as $m ) {
			$menus[] = $m->name;
		}
		return array(
			'name'              => get_bloginfo( 'name' ),
			'tagline'           => get_bloginfo( 'description' ),
			'url'               => home_url(),
			'wordpress'         => get_bloginfo( 'version' ),
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'parent_theme'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
			'block_theme'       => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'acf_active'        => function_exists( 'acf_get_field_groups' ),
			'acf_version'       => defined( 'ACF_VERSION' ) ? ACF_VERSION : null,
			'post_types'        => $types,
			'page_templates'    => wp_get_theme()->get_page_templates(),
			'menus'             => $menus,
			'show_on_front'     => get_option( 'show_on_front' ),
			'page_on_front'     => (int) get_option( 'page_on_front' ),
			'page_for_posts'    => (int) get_option( 'page_for_posts' ),
			'active_plugins'    => $plugins,
			'snippets'          => count( CSA_Tools_Assets::get_snippets() ),
			'performance_rules' => count( CSA_Tools_Performance::get_rules() ),
			'user'              => array(
				'name'            => wp_get_current_user()->display_name,
				'roles'           => wp_get_current_user()->roles,
				'agent_caps'      => CSA_Settings::user_caps(),
				'unfiltered_html' => current_user_can( 'unfiltered_html' ),
			),
			'default_new_status' => CSA_Settings::get( 'default_status' ),
		);
	}

	public static function list_theme_files( $input ) {
		$sub   = trim( str_replace( '..', '', (string) ( $input['subdir'] ?? '' ) ), '/' );
		$files = array();
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $root ) {
			$dir = $sub ? $root . '/' . $sub : $root;
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					static function ( $f ) {
						return ! in_array( $f->getFilename(), array( 'node_modules', 'vendor', '.git', '.github' ), true );
					}
				)
			);
			foreach ( $it as $f ) {
				if ( count( $files ) >= 400 ) {
					break 2;
				}
				$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $root ) ) ), '/' );
				if ( ! is_wp_error( CSA_Guard::theme_read_path( $rel ) ) ) {
					$files[] = array( 'path' => $rel, 'bytes' => $f->getSize(), 'theme' => basename( $root ) );
				}
			}
		}
		return array( 'files' => $files, 'truncated' => count( $files ) >= 400 );
	}

	public static function read_theme_file( $input ) {
		$path = CSA_Guard::theme_read_path( $input['path'] ?? '' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		return array( 'path' => $input['path'], 'content' => CSA_Tool::clip( file_get_contents( $path ), 30000 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public static function preview_options( $input ) {
		$changes = array();
		foreach ( (array) $input['options'] as $name => $value ) {
			if ( ! CSA_Guard::option_writable( $name ) ) {
				return CSA_Tool::err( "Option \"$name\" is not on the allowlist. Allowed: " . implode( ', ', CSA_Guard::option_allowlist() ) );
			}
			$changes[] = array(
				'label'  => $name,
				'before' => (string) wp_json_encode( get_option( $name ) ),
				'after'  => (string) wp_json_encode( $value ),
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return CSA_Tool::err( 'Changing site options requires the manage_options capability.' );
		}
		return array(
			'summary' => 'Update ' . count( $changes ) . ' site option(s)',
			'risk'    => 'settings',
			'changes' => $changes,
		);
	}

	public static function apply_options( $input, CSA_Changeset $cs ) {
		$preview = self::preview_options( $input );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		foreach ( (array) $input['options'] as $name => $value ) {
			$cs->before_option( $name );
			update_option( $name, is_scalar( $value ) ? sanitize_option( $name, $value ) : $value );
		}
		return array( 'updated' => array_keys( (array) $input['options'] ) );
	}
}
