<?php
/**
 * Hard safety rules. Nothing in settings can switch these off.
 *
 * The agent may only change WordPress data through WordPress APIs: posts, pages,
 * post meta / ACF fields, media attachments, a short allowlist of options, and the
 * plugin's own CSS/JS snippets and performance rules. It never writes files outside
 * the uploads directory (media import), never runs shell commands or PHP, never
 * installs/activates plugins or themes, never manages users, and never touches
 * hosting / server configuration (wp-config.php, .htaccess, nginx, WP Engine settings).
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Guard {

	/**
	 * Options the agent may change when the "settings" capability is on.
	 * Everything else is read-only or invisible. Extend with the
	 * `csa_option_allowlist` filter (the denylist below still wins).
	 */
	public static function option_allowlist() {
		$list = array(
			'blogname',
			'blogdescription',
			'date_format',
			'time_format',
			'start_of_week',
			'posts_per_page',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
		);
		return array_values( array_unique( (array) apply_filters( 'csa_option_allowlist', $list ) ) );
	}

	/**
	 * Patterns that can never be read or written by the agent, even if a filter adds them.
	 * Covers URLs, plugin/theme activation, users and roles, rewrite rules (which
	 * write .htaccess), cron, salts/keys, hosting (WP Engine) and this plugin's own settings.
	 */
	public static function option_denied( $name ) {
		$name = strtolower( (string) $name );
		$exact = array(
			'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet',
			'users_can_register', 'default_role', 'admin_email', 'new_admin_email', 'cron',
			'rewrite_rules', 'permalink_structure', 'category_base', 'tag_base', 'upload_path',
			'upload_url_path', 'uploads_use_yearmonth_folders', 'db_version', 'recently_activated',
			'auth_key', 'auth_salt', 'secure_auth_key', 'secure_auth_salt', 'logged_in_key',
			'logged_in_salt', 'nonce_key', 'nonce_salt', 'blog_public', 'wp_page_for_privacy_policy',
			'can_compress_scripts', 'auto_update_plugins', 'auto_update_themes', 'uninstall_plugins',
		);
		if ( in_array( $name, $exact, true ) ) {
			return true;
		}
		$patterns = array(
			'/user_roles$/',
			'/^wpe/',
			'/wpengine/',
			'/^wp_engine/',
			'/^_transient/',
			'/^_site_transient/',
			'/^csa_/',          // This plugin's own settings: the agent can never change its own permissions.
			'/secret|password|api_key|apikey|token|license/',
			'/^theme_mods_/',
		);
		foreach ( $patterns as $p ) {
			if ( preg_match( $p, $name ) ) {
				return true;
			}
		}
		return false;
	}

	public static function option_writable( $name ) {
		return ! self::option_denied( $name ) && in_array( $name, self::option_allowlist(), true );
	}

	/**
	 * Every option the agent can ever write: the allowlist, the plugin's own snippet and
	 * performance-rule stores (written only through their dedicated tools), and ACF
	 * options-page values (written only through update_field()).
	 */
	public static function agent_option_writable( $name ) {
		if ( in_array( $name, array( 'csa_snippets', 'csa_rules' ), true ) ) {
			return true;
		}
		if ( preg_match( '/^_?options_/', $name ) ) {
			return ! preg_match( '/secret|password|api_key|apikey|token|license/i', $name );
		}
		return self::option_writable( $name );
	}

	/**
	 * Post meta keys the agent may write directly. Protected (underscore) keys are
	 * blocked except for a few well-known safe ones. ACF fields go through update_field().
	 */
	public static function meta_key_writable( $key ) {
		$key = (string) $key;
		$safe_protected = array( '_thumbnail_id', '_wp_page_template', '_wp_attachment_image_alt' );
		if ( in_array( $key, $safe_protected, true ) ) {
			return true;
		}
		if ( '' === $key || '_' === $key[0] ) {
			return false;
		}
		return ! preg_match( '/wpe|wpengine|secret|password|token|api_key/i', $key );
	}

	/**
	 * Post types the agent may never create or modify.
	 */
	public static function post_type_writable( $post_type ) {
		$blocked = array(
			'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
			'user_request', 'wp_global_styles', 'acf-field-group', 'acf-field', 'acf-post-type',
			'acf-taxonomy', 'acf-ui-options-page', 'wp_navigation', 'wp_template', 'wp_template_part',
		);
		if ( in_array( $post_type, $blocked, true ) ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		return $obj && ( $obj->show_ui || 'attachment' === $post_type );
	}

	/**
	 * Resolve a path inside the active theme (or parent theme) for READ-ONLY access.
	 * Returns the absolute path or WP_Error.
	 */
	public static function theme_read_path( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
			return new WP_Error( 'csa_path', 'Invalid path.' );
		}
		$ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$allowed = array( 'css', 'scss', 'sass', 'less', 'js', 'mjs', 'ts', 'json', 'php', 'twig', 'html', 'svg', 'md', 'txt' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'csa_path', 'File type not readable by the agent.' );
		}
		$base_name = strtolower( basename( $relative ) );
		if ( in_array( $base_name, array( 'wp-config.php', '.htaccess', '.env', 'auth.json' ), true ) ) {
			return new WP_Error( 'csa_path', 'That file is off limits.' );
		}
		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $root ) {
			$root_real = realpath( $root );
			$full      = realpath( $root . '/' . $relative );
			if ( $root_real && $full && 0 === strpos( $full, $root_real . DIRECTORY_SEPARATOR ) && is_file( $full ) ) {
				if ( preg_match( '#/(node_modules|vendor|\.git)/#', str_replace( '\\', '/', substr( $full, strlen( $root_real ) ) ) ) ) {
					return new WP_Error( 'csa_path', 'Dependency and VCS folders are not readable.' );
				}
				return $full;
			}
		}
		return new WP_Error( 'csa_path', 'File not found in the active theme.' );
	}

	/**
	 * Only allow fetching URLs on this site (prevents the agent using the server as a proxy).
	 */
	public static function is_own_url( $url ) {
		$home = wp_parse_url( home_url() );
		$u    = wp_parse_url( (string) $url );
		if ( empty( $u['host'] ) || empty( $home['host'] ) ) {
			return false;
		}
		return in_array( $u['scheme'] ?? '', array( 'http', 'https' ), true ) && strtolower( $u['host'] ) === strtolower( $home['host'] );
	}

	/**
	 * Media import: http(s) only, no private/reserved IPs, image/PDF types only.
	 */
	public static function media_url_allowed( $url ) {
		$u = wp_parse_url( (string) $url );
		if ( empty( $u['host'] ) || ! in_array( $u['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}
		return (bool) wp_http_validate_url( $url );
	}
}
