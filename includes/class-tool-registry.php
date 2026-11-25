<?php
/**
 * Collects tool definitions and filters them by the current user's capabilities.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tool_Registry {

	private static $all = null;

	public static function all() {
		if ( null === self::$all ) {
			$tools = array_merge(
				CSA_Tools_Site::tools(),
				CSA_Tools_Content::tools(),
				CSA_Tools_Acf::tools(),
				CSA_Tools_Media::tools(),
				CSA_Tools_Assets::tools(),
				CSA_Tools_Performance::tools()
			);
			/**
			 * Add or remove tools. Each must follow the CSA_Tool definition format.
			 * Tools added here still pass through approval and change-set handling.
			 */
			$tools     = (array) apply_filters( 'csa_tools', $tools );
			self::$all = array();
			foreach ( $tools as $t ) {
				self::$all[ $t['name'] ] = $t;
			}
		}
		return self::$all;
	}

	/** Tools the given user can use right now. */
	public static function for_user( $user_id = 0 ) {
		$caps = CSA_Settings::user_caps( $user_id );
		return array_filter( self::all(), fn( $t ) => in_array( $t['cap'], $caps, true ) );
	}

	public static function get( $name ) {
		return self::all()[ $name ] ?? null;
	}

	/** API-shaped tool list (only name/description/input_schema). */
	public static function api_tools( array $tools ) {
		$out = array();
		foreach ( $tools as $t ) {
			$out[] = array(
				'name'         => $t['name'],
				'description'  => $t['description'] . ( $t['write'] ? ' [Requires user approval before it runs.]' : '' ),
				'input_schema' => $t['input_schema'],
			);
		}
		return $out;
	}
}
