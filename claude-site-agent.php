<?php
/**
 * Plugin Name:       Claude Site Agent
 * Description:       A Claude-powered agent inside wp-admin that builds pages (including ACF flexible content), makes bulk content edits, manages small CSS/JS snippets, media details and front-end performance rules. Every write is previewed, approved and undoable. It never touches server or hosting configuration.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Nick
 * License:           GPL-2.0-or-later
 * Text Domain:       claude-site-agent
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

define( 'CSA_VERSION', '0.1.0' );
define( 'CSA_FILE', __FILE__ );
define( 'CSA_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSA_URL', plugin_dir_url( __FILE__ ) );
define( 'CSA_DB_VERSION', '1' );

require_once CSA_DIR . 'includes/class-guard.php';
require_once CSA_DIR . 'includes/class-settings.php';
require_once CSA_DIR . 'includes/class-store.php';
require_once CSA_DIR . 'includes/class-changeset.php';
require_once CSA_DIR . 'includes/class-api-client.php';
require_once CSA_DIR . 'includes/tools/class-tool.php';
require_once CSA_DIR . 'includes/tools/class-tools-site.php';
require_once CSA_DIR . 'includes/tools/class-tools-content.php';
require_once CSA_DIR . 'includes/tools/class-tools-acf.php';
require_once CSA_DIR . 'includes/tools/class-tools-assets.php';
require_once CSA_DIR . 'includes/tools/class-tools-media.php';
require_once CSA_DIR . 'includes/tools/class-tools-performance.php';
require_once CSA_DIR . 'includes/class-tool-registry.php';
require_once CSA_DIR . 'includes/class-agent.php';
require_once CSA_DIR . 'includes/class-rest.php';
require_once CSA_DIR . 'includes/class-admin.php';
require_once CSA_DIR . 'includes/class-frontend.php';

register_activation_hook( __FILE__, array( 'CSA_Store', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		CSA_Store::maybe_upgrade();
		CSA_Settings::init();
		CSA_Rest::init();
		CSA_Admin::init();
		CSA_Frontend::init();
	}
);
