<?php
/**
 * Database tables for agent sessions and change sets (undo history).
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Store {

	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'csa_sessions';
	}

	public static function changesets_table() {
		global $wpdb;
		return $wpdb->prefix . 'csa_changesets';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$s       = self::sessions_table();
		$c       = self::changesets_table();

		dbDelta(
			"CREATE TABLE $s (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT 'idle',
				messages longtext NOT NULL,
				pending longtext NULL,
				notice text NULL,
				steps_this_turn int(11) NOT NULL DEFAULT 0,
				input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $c (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL,
				tool varchar(64) NOT NULL,
				summary text NOT NULL,
				items longtext NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'applied',
				created_at datetime NOT NULL,
				undone_at datetime NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY user_id (user_id)
			) $charset;"
		);
		update_option( 'csa_db_version', CSA_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'csa_db_version' ) !== CSA_DB_VERSION ) {
			self::install();
		}
	}

	/* ---------- Sessions ---------- */

	public static function create_session( $user_id, $title = '' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::sessions_table(),
			array(
				'user_id'    => $user_id,
				'title'      => mb_substr( $title, 0, 200 ),
				'status'     => 'idle',
				'messages'   => '[]',
				'pending'    => null,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_session( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return null;
		}
		// Decode as objects so replayed API content (e.g. empty tool inputs "{}") round-trips byte-for-byte.
		$row['messages'] = json_decode( $row['messages'] ) ?: array();
		$row['pending']  = $row['pending'] ? json_decode( $row['pending'], true ) : null;
		return $row;
	}

	public static function save_session( array $s ) {
		global $wpdb;
		$wpdb->update(
			self::sessions_table(),
			array(
				'title'           => $s['title'],
				'status'          => $s['status'],
				'messages'        => wp_json_encode( $s['messages'] ),
				'pending'         => null === $s['pending'] ? null : wp_json_encode( $s['pending'] ),
				'notice'          => (string) ( $s['notice'] ?? '' ),
				'steps_this_turn' => (int) $s['steps_this_turn'],
				'input_tokens'    => (int) $s['input_tokens'],
				'output_tokens'   => (int) $s['output_tokens'],
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $s['id'] )
		);
	}

	public static function list_sessions( $user_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, input_tokens, output_tokens, created_at, updated_at FROM ' . self::sessions_table() . ' WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d', $user_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function delete_session( $id ) {
		global $wpdb;
		$wpdb->delete( self::sessions_table(), array( 'id' => $id ) );
	}

	/* ---------- Change sets ---------- */

	public static function insert_changeset( $session_id, $user_id, $tool, $summary, array $items ) {
		global $wpdb;
		$wpdb->insert(
			self::changesets_table(),
			array(
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'tool'       => $tool,
				'summary'    => $summary,
				'items'      => wp_json_encode( $items ),
				'status'     => 'applied',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get_changeset( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::changesets_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $row ) {
			$row['items'] = json_decode( $row['items'], true ) ?: array();
		}
		return $row;
	}

	public static function mark_undone( $id ) {
		global $wpdb;
		$wpdb->update( self::changesets_table(), array( 'status' => 'undone', 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	public static function list_changesets( $user_id = 0, $limit = 100 ) {
		global $wpdb;
		$t = self::changesets_table();
		if ( $user_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT id, session_id, user_id, tool, summary, status, created_at, undone_at FROM $t WHERE user_id = %d ORDER BY id DESC LIMIT %d", $user_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, session_id, user_id, tool, summary, status, created_at, undone_at FROM $t ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
