<?php
/**
 * Shared helpers for tool definitions.
 *
 * A tool definition is an array:
 *   name, description, input_schema  — sent to Claude
 *   cap                              — capability group (see CSA_Settings::capabilities())
 *   write                            — false: run immediately via 'run'
 *                                      true:  'preview' builds a diff for approval, 'apply' performs it
 *   run( array $input ) / preview( array $input ) / apply( array $input, CSA_Changeset $cs )
 *
 * preview() returns array( 'summary' => string, 'risk' => single|bulk|destructive|publish|code|settings,
 *                          'changes' => [ [ 'label', 'before', 'after', 'link' ] ] ) or WP_Error.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tool {

	/** Trim long strings so tool results stay small in Claude's context. */
	public static function clip( $text, $max = 4000 ) {
		$text = (string) $text;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return mb_substr( $text, 0, $max ) . "\n…[truncated " . ( mb_strlen( $text ) - $max ) . ' characters]';
	}

	public static function post_summary( WP_Post $p ) {
		return array(
			'id'       => $p->ID,
			'type'     => $p->post_type,
			'status'   => $p->post_status,
			'title'    => get_the_title( $p ),
			'slug'     => $p->post_name,
			'parent'   => $p->post_parent,
			'modified' => $p->post_modified,
			'url'      => get_permalink( $p ),
			'edit_url' => get_edit_post_link( $p->ID, 'raw' ),
		);
	}

	public static function err( $msg ) {
		return new WP_Error( 'csa_tool', $msg );
	}

	/** Make sure the current user may edit this post and the post type is not protected. */
	public static function editable_post( $id ) {
		$p = get_post( (int) $id );
		if ( ! $p ) {
			return self::err( "Post $id not found." );
		}
		if ( ! CSA_Guard::post_type_writable( $p->post_type ) ) {
			return self::err( "Post type {$p->post_type} cannot be changed by the agent." );
		}
		if ( ! current_user_can( 'edit_post', $p->ID ) ) {
			return self::err( 'You do not have permission to edit this item.' );
		}
		return $p;
	}

	/** Content with scripts/iframes etc. requires unfiltered_html, same as the WP editor. */
	public static function filter_content( $html ) {
		return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
	}
}
