<?php
/**
 * wp-admin menu, chat screen and assets.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CSA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		if ( CSA_Settings::user_can_use_agent() ) {
			add_menu_page( 'Claude Agent', 'Claude Agent', 'edit_posts', 'claude-site-agent', array( __CLASS__, 'render_chat' ), 'dashicons-format-chat', 3 );
			add_submenu_page( 'claude-site-agent', 'Claude Agent', __( 'Agent', 'claude-site-agent' ), 'edit_posts', 'claude-site-agent', array( __CLASS__, 'render_chat' ) );
			add_submenu_page( 'claude-site-agent', __( 'Claude Agent settings', 'claude-site-agent' ), __( 'Settings', 'claude-site-agent' ), 'manage_options', 'claude-site-agent-settings', array( 'CSA_Settings', 'render_page' ) );
		} elseif ( current_user_can( 'manage_options' ) ) {
			add_options_page( __( 'Claude Agent settings', 'claude-site-agent' ), 'Claude Agent', 'manage_options', 'claude-site-agent-settings', array( 'CSA_Settings', 'render_page' ) );
		}
	}

	public static function action_links( $links ) {
		$url = admin_url( CSA_Settings::user_can_use_agent() ? 'admin.php?page=claude-site-agent-settings' : 'options-general.php?page=claude-site-agent-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'claude-site-agent' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'claude-site-agent' ) ) {
			return;
		}
		wp_enqueue_style( 'csa-admin', CSA_URL . 'assets/admin.css', array(), CSA_VERSION );
		if ( false !== strpos( $hook, 'settings' ) ) {
			return;
		}
		wp_enqueue_script( 'csa-admin', CSA_URL . 'assets/admin.js', array( 'wp-api-fetch' ), CSA_VERSION, true );
		$caps = CSA_Settings::user_caps();
		wp_localize_script(
			'csa-admin',
			'CSA',
			array(
				'ns'          => CSA_Rest::NS,
				'hasKey'      => 'none' !== CSA_Settings::api_key_source(),
				'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=claude-site-agent-settings' ) : '',
				'canSeeAll'   => current_user_can( 'manage_options' ),
				'caps'        => array_values( array_map( fn( $c ) => CSA_Settings::capabilities()[ $c ]['label'], $caps ) ),
				'model'       => CSA_Settings::get( 'model' ),
			)
		);
	}

	public static function render_chat() {
		?>
		<div class="wrap csa-wrap">
			<h1 class="csa-title">Claude Agent <span class="csa-model" id="csa-model"></span></h1>
			<div id="csa-app" class="csa-app">
				<aside class="csa-side">
					<button type="button" class="button button-primary csa-new" id="csa-new"><?php esc_html_e( 'New conversation', 'claude-site-agent' ); ?></button>
					<nav class="csa-tabs" role="tablist">
						<button type="button" class="csa-tab is-active" data-tab="chats" role="tab"><?php esc_html_e( 'Conversations', 'claude-site-agent' ); ?></button>
						<button type="button" class="csa-tab" data-tab="history" role="tab"><?php esc_html_e( 'History & undo', 'claude-site-agent' ); ?></button>
					</nav>
					<ul class="csa-sessions" id="csa-sessions"></ul>
				</aside>
				<main class="csa-main" id="csa-chat-view">
					<div class="csa-transcript" id="csa-transcript" aria-live="polite"></div>
					<div class="csa-pending" id="csa-pending"></div>
					<div class="csa-status" id="csa-status"></div>
					<form class="csa-composer" id="csa-composer">
						<textarea id="csa-input" rows="3" placeholder="<?php esc_attr_e( 'Describe what you want: e.g. “Build a landing page for our spring sale using the hero, three-column features and CTA layouts. Content below…”', 'claude-site-agent' ); ?>"></textarea>
						<div class="csa-composer-actions">
							<span class="csa-hint"><?php esc_html_e( 'Enter to send, Shift+Enter for a new line. Every change is previewed before it is applied.', 'claude-site-agent' ); ?></span>
							<button type="button" class="button" id="csa-continue" hidden><?php esc_html_e( 'Continue', 'claude-site-agent' ); ?></button>
							<button type="button" class="button" id="csa-stop" hidden><?php esc_html_e( 'Stop', 'claude-site-agent' ); ?></button>
							<button type="submit" class="button button-primary" id="csa-send"><?php esc_html_e( 'Send', 'claude-site-agent' ); ?></button>
						</div>
					</form>
				</main>
				<main class="csa-main" id="csa-history-view" hidden>
					<div class="csa-history-head">
						<h2><?php esc_html_e( 'Changes made by the agent', 'claude-site-agent' ); ?></h2>
						<label id="csa-all-wrap" hidden><input type="checkbox" id="csa-all"> <?php esc_html_e( 'Show all users', 'claude-site-agent' ); ?></label>
					</div>
					<table class="widefat striped"><thead><tr>
						<th>#</th><th><?php esc_html_e( 'When', 'claude-site-agent' ); ?></th><th><?php esc_html_e( 'Who', 'claude-site-agent' ); ?></th><th><?php esc_html_e( 'Change', 'claude-site-agent' ); ?></th><th><?php esc_html_e( 'Status', 'claude-site-agent' ); ?></th><th></th>
					</tr></thead><tbody id="csa-history"></tbody></table>
				</main>
			</div>
		</div>
		<?php
	}
}
