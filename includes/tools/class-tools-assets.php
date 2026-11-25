<?php
/**
 * CSS / JS snippets stored in the database and printed by the plugin on the front end.
 * Theme files are never modified: snippets are versioned with change sets and can be
 * switched off instantly, and they do not drift from your theme's git repository.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Assets {

	const OPTION   = 'csa_snippets';
	const MAX_SIZE = 20000;

	public static function get_snippets() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function tools() {
		return array(
			array(
				'name'         => 'list_snippets',
				'description'  => 'List the CSS/JS snippets this plugin outputs on the front end, plus the theme\'s Customizer "Additional CSS".',
				'cap'          => 'assets',
				'write'        => false,
				'input_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'run'          => array( __CLASS__, 'list_snippets' ),
			),
			array(
				'name'         => 'save_snippet',
				'description'  => 'Create or replace a CSS or JS snippet output on the front end (never in wp-admin). Use for small style fixes and small scripts. Scope it to specific post IDs when it only applies to some pages. Prefer CSS over JS. Shown to the user for approval.',
				'cap'          => 'assets',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'string', 'description' => 'Short slug, e.g. "hero-spacing-fix". Reusing an id replaces that snippet.' ),
						'type'        => array( 'type' => 'string', 'enum' => array( 'css', 'js' ) ),
						'code'        => array( 'type' => 'string', 'description' => 'Raw CSS or JS without <style>/<script> tags.' ),
						'description' => array( 'type' => 'string' ),
						'post_ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Only output on these posts/pages. Omit for the whole site.' ),
						'location'    => array( 'type' => 'string', 'enum' => array( 'head', 'footer' ), 'description' => 'JS only. Default footer.' ),
						'enabled'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'id', 'type', 'code' ),
				),
				'preview'      => array( __CLASS__, 'preview_save' ),
				'apply'        => array( __CLASS__, 'apply_save' ),
			),
			array(
				'name'         => 'remove_snippet',
				'description'  => 'Remove a snippet by id.',
				'cap'          => 'assets',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'string' ) ),
					'required'   => array( 'id' ),
				),
				'preview'      => array( __CLASS__, 'preview_remove' ),
				'apply'        => array( __CLASS__, 'apply_remove' ),
			),
		);
	}

	public static function list_snippets() {
		return array(
			'snippets'       => array_values( self::get_snippets() ),
			'additional_css' => CSA_Tool::clip( wp_get_custom_css(), 8000 ),
		);
	}

	private static function normalize( $input ) {
		$id   = sanitize_key( $input['id'] );
		$type = 'js' === $input['type'] ? 'js' : 'css';
		$code = (string) $input['code'];
		if ( strlen( $code ) > self::MAX_SIZE ) {
			return CSA_Tool::err( 'Snippets are limited to ' . self::MAX_SIZE . ' bytes; this is for small changes. Larger work belongs in the theme.' );
		}
		if ( preg_match( '#</\s*(style|script)#i', $code ) ) {
			return CSA_Tool::err( 'Do not include </style> or </script> in snippet code.' );
		}
		if ( 'js' === $type && ! current_user_can( 'unfiltered_html' ) ) {
			return CSA_Tool::err( 'JS snippets require the unfiltered_html capability.' );
		}
		if ( 'css' === $type && ! current_user_can( 'edit_theme_options' ) && ! current_user_can( 'unfiltered_html' ) ) {
			return CSA_Tool::err( 'CSS snippets require the edit_theme_options capability.' );
		}
		return array(
			'id'          => $id,
			'type'        => $type,
			'code'        => $code,
			'description' => sanitize_text_field( $input['description'] ?? '' ),
			'post_ids'    => array_values( array_map( 'intval', (array) ( $input['post_ids'] ?? array() ) ) ),
			'location'    => 'head' === ( $input['location'] ?? '' ) ? 'head' : 'footer',
			'enabled'     => ! isset( $input['enabled'] ) || (bool) $input['enabled'],
			'updated'     => current_time( 'mysql' ),
			'author'      => get_current_user_id(),
		);
	}

	public static function preview_save( $input ) {
		$s = self::normalize( $input );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$all = self::get_snippets();
		$old = $all[ $s['id'] ] ?? null;
		return array(
			'summary' => sprintf( '%s %s snippet "%s"%s', $old ? 'Replace' : 'Add', strtoupper( $s['type'] ), $s['id'], $s['post_ids'] ? ' on posts ' . implode( ', ', $s['post_ids'] ) : ' site-wide' ),
			'risk'    => 'code',
			'changes' => array( array( 'label' => $s['id'] . '.' . $s['type'], 'before' => $old ? $old['code'] : '', 'after' => $s['code'] ) ),
		);
	}

	public static function apply_save( $input, CSA_Changeset $cs ) {
		$s = self::normalize( $input );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$cs->before_option( self::OPTION );
		$all             = self::get_snippets();
		$all[ $s['id'] ] = $s;
		update_option( self::OPTION, $all, true );
		return array( 'saved' => $s['id'], 'note' => 'Clear any page cache (e.g. WP Engine cache) if the change does not show.' );
	}

	public static function preview_remove( $input ) {
		$all = self::get_snippets();
		$id  = sanitize_key( $input['id'] );
		if ( ! isset( $all[ $id ] ) ) {
			return CSA_Tool::err( "No snippet \"$id\"." );
		}
		return array(
			'summary' => "Remove snippet \"$id\"",
			'risk'    => 'code',
			'changes' => array( array( 'label' => $id, 'before' => $all[ $id ]['code'], 'after' => '' ) ),
		);
	}

	public static function apply_remove( $input, CSA_Changeset $cs ) {
		$cs->before_option( self::OPTION );
		$all = self::get_snippets();
		unset( $all[ sanitize_key( $input['id'] ) ] );
		update_option( self::OPTION, $all, true );
		return array( 'removed' => sanitize_key( $input['id'] ) );
	}
}
