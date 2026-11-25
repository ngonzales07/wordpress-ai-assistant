<?php
/**
 * Change sets: every write the agent makes is snapshotted first so it can be undone,
 * independent of whether the host limits post revisions.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Changeset {

	private $items = array();
	private $seen  = array();

	/** Snapshot a post (fields + all meta) before changing it. Safe to call more than once. */
	public function before_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( $this->seen[ 'post:' . $post_id ] ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		// Also make a normal WordPress revision so the change shows in the revisions UI.
		if ( wp_revisions_enabled( $post ) ) {
			wp_save_post_revision( $post_id );
		}
		$this->seen[ 'post:' . $post_id ] = true;
		$this->items[] = array(
			'type'   => 'post',
			'id'     => $post_id,
			'before' => array(
				'fields' => self::post_fields( $post ),
				'meta'   => self::raw_meta( $post_id ),
			),
		);
	}

	public function created_post( $post_id ) {
		$this->seen[ 'post:' . (int) $post_id ] = true;
		$this->items[] = array( 'type' => 'post_created', 'id' => (int) $post_id );
	}

	public function before_option( $name ) {
		if ( isset( $this->seen[ 'option:' . $name ] ) ) {
			return;
		}
		$this->seen[ 'option:' . $name ] = true;
		$sentinel = '__csa_missing__';
		$value    = get_option( $name, $sentinel );
		$this->items[] = array(
			'type'    => 'option',
			'name'    => $name,
			'existed' => $sentinel !== $value,
			'before'  => $sentinel !== $value ? maybe_serialize( $value ) : null,
		);
	}

	/** An option that did not exist before this change (undo deletes it). */
	public function created_option( $name ) {
		if ( isset( $this->seen[ 'option:' . $name ] ) ) {
			return;
		}
		$this->seen[ 'option:' . $name ] = true;
		$this->items[] = array( 'type' => 'option', 'name' => $name, 'existed' => false, 'before' => null );
	}

	public function is_empty() {
		return empty( $this->items );
	}

	/** Persist. Records each post's modified time so undo can detect later manual edits. */
	public function save( $session_id, $user_id, $tool, $summary ) {
		foreach ( $this->items as &$item ) {
			if ( in_array( $item['type'], array( 'post', 'post_created' ), true ) ) {
				clean_post_cache( $item['id'] );
				$p                     = get_post( $item['id'] );
				$item['after_modified'] = $p ? $p->post_modified_gmt : '';
			}
		}
		unset( $item );
		return CSA_Store::insert_changeset( $session_id, $user_id, $tool, $summary, $this->items );
	}

	private static function post_fields( WP_Post $post ) {
		return array(
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
		);
	}

	private static function raw_meta( $post_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d ORDER BY meta_id ASC", $post_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( in_array( $r['meta_key'], array( '_edit_lock', '_edit_last' ), true ) ) {
				continue;
			}
			$out[ $r['meta_key'] ][] = $r['meta_value'];
		}
		return $out;
	}

	/**
	 * Undo a stored change set. Returns array( 'ok' => bool, 'conflicts' => [...], 'message' => '' ).
	 */
	public static function undo( $changeset_id, $force = false ) {
		$cs = CSA_Store::get_changeset( $changeset_id );
		if ( ! $cs ) {
			return array( 'ok' => false, 'message' => 'Change set not found.' );
		}
		if ( 'applied' !== $cs['status'] ) {
			return array( 'ok' => false, 'message' => 'This change set was already undone.' );
		}

		$conflicts = array();
		foreach ( $cs['items'] as $item ) {
			if ( in_array( $item['type'], array( 'post', 'post_created' ), true ) ) {
				$p = get_post( $item['id'] );
				if ( $p && ! empty( $item['after_modified'] ) && $p->post_modified_gmt !== $item['after_modified'] ) {
					$conflicts[] = array( 'id' => $item['id'], 'title' => get_the_title( $p ) );
				}
				if ( $p && ! current_user_can( 'edit_post', $p->ID ) ) {
					return array( 'ok' => false, 'message' => 'You do not have permission to edit "' . get_the_title( $p ) . '".' );
				}
			}
		}
		if ( $conflicts && ! $force ) {
			return array(
				'ok'        => false,
				'conflicts' => $conflicts,
				'message'   => 'Some items were edited after this change. Undo again with force to overwrite those later edits.',
			);
		}

		foreach ( array_reverse( $cs['items'] ) as $item ) {
			switch ( $item['type'] ) {
				case 'post':
					self::restore_post( $item['id'], $item['before'] );
					break;
				case 'post_created':
					$p = get_post( $item['id'] );
					if ( $p ) {
						if ( 'attachment' === $p->post_type ) {
							wp_delete_attachment( $p->ID, true );
						} else {
							wp_trash_post( $p->ID );
						}
					}
					break;
				case 'option':
					if ( ! CSA_Guard::agent_option_writable( $item['name'] ) ) {
						break;
					}
					if ( $item['existed'] ) {
						update_option( $item['name'], maybe_unserialize( $item['before'] ) );
					} else {
						delete_option( $item['name'] );
					}
					break;
			}
		}
		CSA_Store::mark_undone( $changeset_id );
		return array( 'ok' => true, 'message' => 'Undone.' );
	}

	private static function restore_post( $post_id, array $before ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return;
		}
		$fields       = $before['fields'];
		$fields['ID'] = $post_id;
		wp_update_post( wp_slash( $fields ) );

		$current = self::raw_meta( $post_id );
		foreach ( array_keys( $current ) as $key ) {
			if ( ! array_key_exists( $key, $before['meta'] ) ) {
				delete_post_meta( $post_id, $key );
			}
		}
		foreach ( $before['meta'] as $key => $values ) {
			if ( ( $current[ $key ] ?? null ) === $values ) {
				continue;
			}
			delete_post_meta( $post_id, $key );
			foreach ( $values as $v ) {
				add_post_meta( $post_id, $key, wp_slash( maybe_unserialize( $v ) ) );
			}
		}
		clean_post_cache( $post_id );
	}
}
