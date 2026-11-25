<?php
/**
 * REST API used by the admin chat screen. All routes require a logged-in user who is
 * allowed to use the agent, and sessions are private to the user who created them.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Rest {

	const NS = 'claude-site-agent/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can_use() {
		return is_user_logged_in() && CSA_Settings::user_can_use_agent();
	}

	public static function routes() {
		$perm = array( __CLASS__, 'can_use' );
		$id   = '(?P<id>\d+)';

		register_rest_route( self::NS, '/sessions', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_sessions' ), 'permission_callback' => $perm ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_session' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, "/sessions/$id", array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_session' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_session' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, "/sessions/$id/message", array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'message' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, "/sessions/$id/continue", array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'continue_run' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, "/sessions/$id/decide", array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'decide' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/changesets', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'list_changesets' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, "/changesets/$id", array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'get_changeset' ), 'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, "/changesets/$id/undo", array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'undo' ), 'permission_callback' => $perm,
		) );
	}

	/* ---------- helpers ---------- */

	private static function own_session( $id ) {
		$s = CSA_Store::get_session( (int) $id );
		if ( ! $s || (int) $s['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'csa_not_found', 'Conversation not found.', array( 'status' => 404 ) );
		}
		return $s;
	}

	private static function lock( $id ) {
		$key = 'csa_lock_' . (int) $id;
		$t   = (int) get_option( $key );
		if ( $t && $t > time() - 180 ) {
			return false;
		}
		if ( $t ) {
			delete_option( $key );
		}
		return add_option( $key, time(), '', false );
	}

	private static function unlock( $id ) {
		delete_option( 'csa_lock_' . (int) $id );
	}

	/** Run a mutating agent call under a per-session lock. */
	private static function locked( $id, callable $fn ) {
		$s = self::own_session( $id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		if ( ! self::lock( $id ) ) {
			return new WP_Error( 'csa_busy', 'This conversation is already working. Wait for it to finish.', array( 'status' => 409 ) );
		}
		@set_time_limit( 300 ); // phpcs:ignore
		try {
			$r = $fn( $s );
		} finally {
			self::unlock( $id );
		}
		if ( is_wp_error( $r ) ) {
			$r->add_data( array( 'status' => 400 ) );
			return $r;
		}
		return rest_ensure_response( self::view( $r ) );
	}

	/** UI-friendly view of a session: a flat transcript plus any pending approvals. */
	public static function view( array $s ) {
		$messages = json_decode( wp_json_encode( $s['messages'] ), true ) ?: array();
		$items    = array();
		$results  = array();
		foreach ( $messages as $m ) {
			if ( 'user' === $m['role'] && is_array( $m['content'] ) ) {
				foreach ( $m['content'] as $b ) {
					if ( 'tool_result' === ( $b['type'] ?? '' ) ) {
						$results[ $b['tool_use_id'] ] = $b;
					}
				}
			}
		}
		// Read tools that already ran while write tools wait for approval.
		foreach ( (array) $s['pending'] as $p ) {
			if ( 'result' === ( $p['kind'] ?? '' ) ) {
				$results[ $p['id'] ] = array(
					'content'  => is_string( $p['result'] ) ? $p['result'] : wp_json_encode( $p['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					'is_error' => ! empty( $p['is_error'] ),
				);
			}
		}
		foreach ( $messages as $m ) {
			$content = is_string( $m['content'] ) ? array( array( 'type' => 'text', 'text' => $m['content'] ) ) : $m['content'];
			foreach ( $content as $b ) {
				$type = $b['type'] ?? '';
				if ( 'text' === $type && '' !== trim( $b['text'] ) ) {
					$items[] = array( 'kind' => $m['role'], 'text' => $b['text'] );
				} elseif ( 'tool_use' === $type ) {
					$r       = $results[ $b['id'] ] ?? null;
					$items[] = array(
						'kind'     => 'tool',
						'id'       => $b['id'],
						'name'     => $b['name'],
						'input'    => CSA_Tool::clip( wp_json_encode( $b['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), 3000 ),
						'result'   => $r ? CSA_Tool::clip( is_string( $r['content'] ) ? $r['content'] : wp_json_encode( $r['content'] ), 3000 ) : null,
						'is_error' => $r ? ! empty( $r['is_error'] ) : false,
					);
				}
			}
		}
		$pending = array();
		foreach ( (array) $s['pending'] as $p ) {
			if ( 'approval' === ( $p['kind'] ?? '' ) ) {
				$pending[] = array( 'id' => $p['id'], 'name' => $p['name'], 'preview' => $p['preview'] );
			}
		}
		return array(
			'id'            => (int) $s['id'],
			'title'         => $s['title'],
			'status'        => $s['status'],
			'notice'        => $s['notice'] ?? '',
			'items'         => $items,
			'pending'       => $pending,
			'input_tokens'  => (int) $s['input_tokens'],
			'output_tokens' => (int) $s['output_tokens'],
		);
	}

	/* ---------- sessions ---------- */

	public static function list_sessions() {
		return rest_ensure_response( CSA_Store::list_sessions( get_current_user_id() ) );
	}

	public static function create_session( WP_REST_Request $req ) {
		$id = CSA_Store::create_session( get_current_user_id() );
		$s  = CSA_Store::get_session( $id );
		return rest_ensure_response( self::view( $s ) );
	}

	public static function get_session( WP_REST_Request $req ) {
		$s = self::own_session( $req['id'] );
		return is_wp_error( $s ) ? $s : rest_ensure_response( self::view( $s ) );
	}

	public static function delete_session( WP_REST_Request $req ) {
		$s = self::own_session( $req['id'] );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		CSA_Store::delete_session( $s['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function message( WP_REST_Request $req ) {
		return self::locked( $req['id'], fn( $s ) => CSA_Agent::send_message( $s, (string) $req->get_param( 'text' ) ) );
	}

	public static function continue_run( WP_REST_Request $req ) {
		return self::locked( $req['id'], fn( $s ) => CSA_Agent::continue_run( $s ) );
	}

	public static function decide( WP_REST_Request $req ) {
		$decisions = (array) $req->get_param( 'decisions' );
		$clean     = array();
		foreach ( $decisions as $tid => $d ) {
			$clean[ sanitize_text_field( $tid ) ] = array(
				'approve' => ! empty( $d['approve'] ),
				'note'    => sanitize_textarea_field( $d['note'] ?? '' ),
			);
		}
		return self::locked( $req['id'], fn( $s ) => CSA_Agent::decide( $s, $clean ) );
	}

	/* ---------- change sets ---------- */

	private static function can_see_all() {
		return current_user_can( 'manage_options' );
	}

	public static function list_changesets( WP_REST_Request $req ) {
		$all  = $req->get_param( 'all' ) && self::can_see_all();
		$rows = CSA_Store::list_changesets( $all ? 0 : get_current_user_id() );
		foreach ( $rows as &$r ) {
			$u          = get_userdata( $r['user_id'] );
			$r['user']  = $u ? $u->display_name : '#' . $r['user_id'];
			$r['mine']  = (int) $r['user_id'] === get_current_user_id();
		}
		return rest_ensure_response( $rows );
	}

	private static function own_changeset( $id ) {
		$cs = CSA_Store::get_changeset( (int) $id );
		if ( ! $cs || ( (int) $cs['user_id'] !== get_current_user_id() && ! self::can_see_all() ) ) {
			return new WP_Error( 'csa_not_found', 'Change set not found.', array( 'status' => 404 ) );
		}
		return $cs;
	}

	public static function get_changeset( WP_REST_Request $req ) {
		$cs = self::own_changeset( $req['id'] );
		if ( is_wp_error( $cs ) ) {
			return $cs;
		}
		$items = array();
		foreach ( $cs['items'] as $i ) {
			$label = $i['type'];
			if ( isset( $i['id'] ) ) {
				$label .= ' #' . $i['id'] . ' ' . get_the_title( $i['id'] );
			}
			if ( isset( $i['name'] ) ) {
				$label .= ' ' . $i['name'];
			}
			$items[] = $label;
		}
		unset( $cs['items'] );
		$cs['item_labels'] = $items;
		return rest_ensure_response( $cs );
	}

	public static function undo( WP_REST_Request $req ) {
		$cs = self::own_changeset( $req['id'] );
		if ( is_wp_error( $cs ) ) {
			return $cs;
		}
		foreach ( $cs['items'] as $i ) {
			if ( 'option' === $i['type'] ) {
				$needs = in_array( $i['name'], array( 'csa_snippets', 'csa_rules' ), true ) ? 'edit_theme_options' : 'manage_options';
				if ( ! current_user_can( $needs ) ) {
					return new WP_Error( 'csa_forbidden', 'Undoing this change needs the ' . $needs . ' capability.', array( 'status' => 403 ) );
				}
			}
		}
		$r = CSA_Changeset::undo( $cs['id'], (bool) $req->get_param( 'force' ) );
		return rest_ensure_response( $r );
	}
}
