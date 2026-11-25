<?php
/**
 * Settings: API key, model, global capability toggles and the per-role matrix.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Settings {

	const OPTION = 'csa_settings';

	/**
	 * Capability groups. Each tool belongs to exactly one group.
	 */
	public static function capabilities() {
		return array(
			'content_read' => array(
				'label' => __( 'Read site content', 'claude-site-agent' ),
				'desc'  => __( 'Search and read posts, pages, ACF field groups, theme files (read-only) and site info.', 'claude-site-agent' ),
			),
			'content_write' => array(
				'label' => __( 'Create and edit content', 'claude-site-agent' ),
				'desc'  => __( 'Create pages/posts (as drafts by default) and edit single items, including ACF fields.', 'claude-site-agent' ),
			),
			'content_bulk' => array(
				'label' => __( 'Bulk edits and trashing', 'claude-site-agent' ),
				'desc'  => __( 'Search-and-replace across many items, and moving items to the trash. Always needs approval.', 'claude-site-agent' ),
			),
			'acf' => array(
				'label' => __( 'ACF fields and options pages', 'claude-site-agent' ),
				'desc'  => __( 'Write ACF field values, including flexible content layouts and options pages.', 'claude-site-agent' ),
			),
			'assets' => array(
				'label' => __( 'CSS and JS snippets', 'claude-site-agent' ),
				'desc'  => __( 'Add or change small CSS/JS snippets the plugin outputs on the front end. JS also needs the unfiltered_html capability.', 'claude-site-agent' ),
			),
			'media' => array(
				'label' => __( 'Media library', 'claude-site-agent' ),
				'desc'  => __( 'Edit alt text, titles and captions, and import images from a URL.', 'claude-site-agent' ),
			),
			'performance' => array(
				'label' => __( 'Page speed', 'claude-site-agent' ),
				'desc'  => __( 'Analyze pages and set front-end rules: defer/async scripts, dequeue assets per page, preload, lazy-loading.', 'claude-site-agent' ),
			),
			'settings' => array(
				'label' => __( 'Basic site settings', 'claude-site-agent' ),
				'desc'  => __( 'Change a short allowlist of WordPress options (site title, tagline, front page, image sizes). Never URLs, plugins, users or hosting settings.', 'claude-site-agent' ),
			),
		);
	}

	public static function defaults() {
		$caps = array_keys( self::capabilities() );
		return array(
			'api_key_enc'        => '',
			'model'              => 'claude-opus-5-5',
			'effort'             => 'medium',
			'max_steps'          => 25,
			'request_timeout'    => 110,
			'auto_apply_single'  => 0,
			'default_status'     => 'draft',
			'pagespeed_key'      => '',
			'global'             => array_fill_keys( $caps, 1 ) + array( 'settings' => 0 ),
			'roles'              => array(
				'administrator' => array_fill_keys( array_diff( $caps, array( 'settings' ) ), 1 ),
				'editor'        => array_fill_keys( array( 'content_read', 'content_write', 'acf', 'media' ), 1 ),
			),
			'extra_instructions' => '',
		);
	}

	public static function get( $key = null ) {
		$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$opts['global'] = wp_parse_args( (array) $opts['global'], self::defaults()['global'] );
		return null === $key ? $opts : ( $opts[ $key ] ?? null );
	}

	public static function models() {
		return array(
			'claude-opus-5-5'   => 'Claude Opus 5.5 (most capable, default)',
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (faster, lower cost)',
		);
	}

	public static function efforts() {
		return array(
			'low'    => 'Low (fastest)',
			'medium' => 'Medium (default)',
			'high'   => 'High',
			'xhigh'  => 'Extra high (slow, may hit host time limits)',
		);
	}

	/* ---------- API key ---------- */

	public static function api_key() {
		if ( defined( 'CSA_ANTHROPIC_API_KEY' ) && CSA_ANTHROPIC_API_KEY ) {
			return CSA_ANTHROPIC_API_KEY;
		}
		$enc = self::get( 'api_key_enc' );
		return $enc ? self::decrypt( $enc ) : '';
	}

	public static function api_key_source() {
		if ( defined( 'CSA_ANTHROPIC_API_KEY' ) && CSA_ANTHROPIC_API_KEY ) {
			return 'constant';
		}
		return self::get( 'api_key_enc' ) ? 'option' : 'none';
	}

	private static function cipher_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'csa', true );
	}

	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'b64:' . base64_encode( $plain );
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $enc, self::cipher_key(), true );
		return 'v1:' . base64_encode( $iv . $mac . $enc );
	}

	public static function decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			return (string) base64_decode( substr( $stored, 4 ) );
		}
		if ( 0 !== strpos( $stored, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ) );
		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$enc = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $enc, self::cipher_key(), true ), $mac ) ) {
			return '';
		}
		return (string) openssl_decrypt( $enc, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
	}

	/* ---------- Permissions ---------- */

	/**
	 * Capability groups the given user may use: globally enabled AND enabled for one of their roles.
	 */
	public static function user_caps( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return array();
		}
		$opts    = self::get();
		$allowed = array();
		foreach ( array_keys( self::capabilities() ) as $cap ) {
			if ( empty( $opts['global'][ $cap ] ) ) {
				continue;
			}
			foreach ( (array) $user->roles as $role ) {
				if ( ! empty( $opts['roles'][ $role ][ $cap ] ) ) {
					$allowed[] = $cap;
					break;
				}
			}
		}
		if ( is_multisite() && is_super_admin( $user->ID ) && empty( $allowed ) ) {
			$allowed = isset( $opts['roles']['administrator'] ) ? array_keys( array_filter( $opts['roles']['administrator'] ) ) : array();
			$allowed = array_values( array_filter( $allowed, static fn( $c ) => ! empty( $opts['global'][ $c ] ) ) );
		}
		return (array) apply_filters( 'csa_user_caps', $allowed, $user );
	}

	public static function user_can_use_agent( $user_id = 0 ) {
		$caps = self::user_caps( $user_id );
		return in_array( 'content_read', $caps, true ) && ( $user_id ? user_can( $user_id, 'edit_posts' ) : current_user_can( 'edit_posts' ) );
	}

	/* ---------- Settings page ---------- */

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'csa_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::get();
		}
		$old  = self::get();
		$out  = $old;
		$caps = array_keys( self::capabilities() );

		$new_key = trim( (string) ( $input['api_key'] ?? '' ) );
		if ( '' !== $new_key && false === strpos( $new_key, '•' ) ) {
			$out['api_key_enc'] = self::encrypt( $new_key );
		}
		if ( ! empty( $input['api_key_clear'] ) ) {
			$out['api_key_enc'] = '';
		}

		$out['model']             = array_key_exists( $input['model'] ?? '', self::models() ) ? $input['model'] : 'claude-opus-5-5';
		$out['effort']            = array_key_exists( $input['effort'] ?? '', self::efforts() ) ? $input['effort'] : 'medium';
		$out['max_steps']         = max( 1, min( 100, (int) ( $input['max_steps'] ?? 25 ) ) );
		$out['request_timeout']   = max( 20, min( 600, (int) ( $input['request_timeout'] ?? 110 ) ) );
		$out['auto_apply_single'] = empty( $input['auto_apply_single'] ) ? 0 : 1;
		$out['default_status']    = in_array( $input['default_status'] ?? 'draft', array( 'draft', 'pending' ), true ) ? $input['default_status'] : 'draft';
		$out['pagespeed_key']     = sanitize_text_field( $input['pagespeed_key'] ?? '' );
		$out['extra_instructions'] = sanitize_textarea_field( $input['extra_instructions'] ?? '' );

		$out['global'] = array();
		foreach ( $caps as $cap ) {
			$out['global'][ $cap ] = empty( $input['global'][ $cap ] ) ? 0 : 1;
		}
		$out['roles'] = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
			foreach ( $caps as $cap ) {
				if ( ! empty( $input['roles'][ $role ][ $cap ] ) ) {
					$out['roles'][ $role ][ $cap ] = 1;
				}
			}
		}
		return $out;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o     = self::get();
		$roles = wp_roles()->get_names();
		$caps  = self::capabilities();
		$src   = self::api_key_source();
		?>
		<div class="wrap csa-settings">
			<h1><?php esc_html_e( 'Claude Site Agent settings', 'claude-site-agent' ); ?></h1>
			<p class="description"><?php esc_html_e( 'The agent only works through WordPress itself. Server and hosting configuration (wp-config.php, .htaccess, WP Engine settings, plugins, themes, users) is always off limits, whatever these settings say.', 'claude-site-agent' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'csa_settings_group' ); ?>
				<h2><?php esc_html_e( 'Connection', 'claude-site-agent' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="csa-api-key"><?php esc_html_e( 'Anthropic API key', 'claude-site-agent' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $src ) : ?>
								<p><?php esc_html_e( 'Set by the CSA_ANTHROPIC_API_KEY constant.', 'claude-site-agent' ); ?></p>
							<?php else : ?>
								<input type="password" id="csa-api-key" name="<?php echo esc_attr( self::OPTION ); ?>[api_key]" class="regular-text" autocomplete="off" placeholder="<?php echo 'option' === $src ? esc_attr( '••••••••  (saved)' ) : 'sk-ant-…'; ?>">
								<?php if ( 'option' === $src ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[api_key_clear]" value="1"> <?php esc_html_e( 'Remove saved key', 'claude-site-agent' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Stored encrypted with your site salts. You can instead define CSA_ANTHROPIC_API_KEY yourself; the plugin never edits wp-config.php.', 'claude-site-agent' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Model', 'claude-site-agent' ); ?></th>
						<td><select name="<?php echo esc_attr( self::OPTION ); ?>[model]">
							<?php foreach ( self::models() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Effort', 'claude-site-agent' ); ?></th>
						<td><select name="<?php echo esc_attr( self::OPTION ); ?>[effort]">
							<?php foreach ( self::efforts() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $o['effort'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Higher effort thinks longer. Many hosts stop PHP requests after 60 seconds, so medium is a safe default.', 'claude-site-agent' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits', 'claude-site-agent' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Max agent steps per message', 'claude-site-agent' ); ?> <input type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[max_steps]" value="<?php echo esc_attr( $o['max_steps'] ); ?>" class="small-text"></label><br>
							<label><?php esc_html_e( 'API request timeout (seconds)', 'claude-site-agent' ); ?> <input type="number" min="20" max="600" name="<?php echo esc_attr( self::OPTION ); ?>[request_timeout]" value="<?php echo esc_attr( $o['request_timeout'] ); ?>" class="small-text"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Safety', 'claude-site-agent' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto_apply_single]" value="1" <?php checked( $o['auto_apply_single'] ); ?>> <?php esc_html_e( 'Apply single-item changes to drafts without asking (bulk changes, publishing, trashing, snippets and settings still ask)', 'claude-site-agent' ); ?></label><br>
							<label><?php esc_html_e( 'New content is created as', 'claude-site-agent' ); ?>
								<select name="<?php echo esc_attr( self::OPTION ); ?>[default_status]">
									<option value="draft" <?php selected( $o['default_status'], 'draft' ); ?>>draft</option>
									<option value="pending" <?php selected( $o['default_status'], 'pending' ); ?>>pending review</option>
								</select></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csa-psi"><?php esc_html_e( 'PageSpeed Insights API key (optional)', 'claude-site-agent' ); ?></label></th>
						<td><input type="text" id="csa-psi" name="<?php echo esc_attr( self::OPTION ); ?>[pagespeed_key]" value="<?php echo esc_attr( $o['pagespeed_key'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="csa-extra"><?php esc_html_e( 'Site instructions for the agent', 'claude-site-agent' ); ?></label></th>
						<td><textarea id="csa-extra" name="<?php echo esc_attr( self::OPTION ); ?>[extra_instructions]" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Brand voice, naming rules, which ACF layouts to prefer for landing pages…', 'claude-site-agent' ); ?>"><?php echo esc_textarea( $o['extra_instructions'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Capabilities', 'claude-site-agent' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A user can use a capability only when it is on globally and on for one of their roles. The agent also acts with the user\'s own WordPress permissions, so it can never do more than the user could do by hand.', 'claude-site-agent' ); ?></p>
				<div class="csa-matrix-wrap">
				<table class="widefat striped csa-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'claude-site-agent' ); ?></th>
							<th><?php esc_html_e( 'On globally', 'claude-site-agent' ); ?></th>
							<?php foreach ( $roles as $role => $name ) : ?>
								<th><?php echo esc_html( translate_user_role( $name ) ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $caps as $cap => $info ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $info['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $info['desc'] ); ?></span></td>
							<td><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[global][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( ! empty( $o['global'][ $cap ] ) ); ?>></td>
							<?php foreach ( array_keys( $roles ) as $role ) : ?>
								<td><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[roles][<?php echo esc_attr( $role ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( ! empty( $o['roles'][ $role ][ $cap ] ) ); ?>></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
