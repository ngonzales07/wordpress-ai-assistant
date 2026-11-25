<?php
/**
 * Minimal Claude Messages API client on the WordPress HTTP API.
 *
 * Uses wp_remote_post rather than a vendored SDK so the plugin ships with no
 * Composer dependencies that could clash with other plugins, and so it honours
 * the host's WordPress HTTP settings (proxies, timeouts, SSL bundle).
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Api_Client {

	const API_VERSION = '2026-10-21';

	/**
	 * Send one Messages API request.
	 *
	 * @param array $args system (array of blocks), tools, messages.
	 * @return array|WP_Error Decoded response.
	 */
	public static function create_message( array $args ) {
		$key = CSA_Settings::api_key();
		if ( ! $key ) {
			return new WP_Error( 'csa_no_key', __( 'Add your Anthropic API key in Claude Agent → Settings first.', 'claude-site-agent' ) );
		}
		$opts  = CSA_Settings::get();
		$model = $opts['model'];

		$body = array(
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => $args['system'],
			'tools'         => $args['tools'],
			'messages'      => $args['messages'],
			// Adaptive thinking; effort is the depth control on current models.
			'thinking'      => array( 'type' => 'adaptive' ),
			'output_config' => array( 'effort' => $opts['effort'] ),
			// Automatic prompt caching of the stable prefix (tools + system + history).
			'cache_control' => array( 'type' => 'ephemeral' ),
			// If a safety classifier declines, retry server-side on Anthropic's recommended fallback model.
			'fallbacks'     => 'default',
		);
		$body = apply_filters( 'csa_api_request_body', $body );

		$url      = apply_filters( 'csa_api_url', 'https://api.anthropic.com/v1/messages' );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $opts['request_timeout'],
				'headers' => array(
					'content-type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'anthropic-beta'    => 'server-side-fallback-2026-07-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_message();
			if ( false !== stripos( $msg, 'timed out' ) ) {
				$msg = __( 'The request to Claude timed out. Try a lower effort setting, or split the task into smaller steps.', 'claude-site-agent' );
			}
			return new WP_Error( 'csa_http', $msg );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 || ! is_array( $data ) ) {
			$api_msg = is_array( $data ) && isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unexpected response';
			switch ( true ) {
				case 401 === $code:
					$msg = __( 'The API key was rejected. Check it in Settings.', 'claude-site-agent' );
					break;
				case 429 === $code:
					$msg = __( 'Rate limited by the Claude API. Wait a moment and press Continue.', 'claude-site-agent' );
					break;
				case 529 === $code || $code >= 500:
					$msg = __( 'The Claude API is busy or unavailable. Press Continue to retry.', 'claude-site-agent' );
					break;
				default:
					$msg = sprintf( 'Claude API error %d: %s', $code, $api_msg );
			}
			return new WP_Error( 'csa_api', $msg, array( 'status' => $code, 'retryable' => 429 === $code || $code >= 500 ) );
		}
		// Keep an object-decoded copy of the content so it can be replayed exactly
		// (empty objects stay {}, thinking blocks and signatures are untouched).
		$obj                      = json_decode( $raw );
		$data['_content_objects'] = $obj->content ?? array();
		return $data;
	}
}
