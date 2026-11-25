<?php
/**
 * Applies the agent's CSS/JS snippets and performance rules on the front end.
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Frontend {

	private static $lcp_urls = array();

	public static function init() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	private static function in_scope( array $item ) {
		if ( empty( $item['post_ids'] ) ) {
			return true;
		}
		$id = get_queried_object_id();
		return $id && in_array( (int) $id, array_map( 'intval', $item['post_ids'] ), true );
	}

	private static function bypass() {
		return isset( $_GET['csa_no_rules'] ) && is_user_logged_in() && current_user_can( 'edit_posts' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function setup() {
		if ( self::bypass() ) {
			return;
		}
		$snippets = array_filter( CSA_Tools_Assets::get_snippets(), fn( $s ) => ! empty( $s['enabled'] ) && self::in_scope( $s ) );
		if ( $snippets ) {
			add_action(
				'wp_head',
				static function () use ( $snippets ) {
					foreach ( $snippets as $s ) {
						if ( 'css' === $s['type'] ) {
							printf( "<style id=\"csa-snippet-%s\">\n%s\n</style>\n", esc_attr( $s['id'] ), $s['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						} elseif ( 'head' === $s['location'] ) {
							printf( "<script id=\"csa-snippet-%s\">\n%s\n</script>\n", esc_attr( $s['id'] ), $s['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					}
				},
				100
			);
			add_action(
				'wp_footer',
				static function () use ( $snippets ) {
					foreach ( $snippets as $s ) {
						if ( 'js' === $s['type'] && 'head' !== $s['location'] ) {
							printf( "<script id=\"csa-snippet-%s\">\n%s\n</script>\n", esc_attr( $s['id'] ), $s['code'] ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					}
				},
				100
			);
		}

		$rules = array_filter( CSA_Tools_Performance::get_rules(), array( __CLASS__, 'in_scope' ) );
		if ( ! $rules ) {
			return;
		}
		$attr  = array();
		$deq_s = array();
		$deq_c = array();
		$head  = array();
		foreach ( $rules as $r ) {
			switch ( $r['type'] ) {
				case 'defer_script':
					$attr[ $r['handle'] ] = 'defer';
					break;
				case 'async_script':
					$attr[ $r['handle'] ] = 'async';
					break;
				case 'dequeue_script':
					$deq_s[] = $r['handle'];
					break;
				case 'dequeue_style':
					$deq_c[] = $r['handle'];
					break;
				case 'preload':
					$cross  = 'font' === $r['as'] ? ' crossorigin' : '';
					$head[] = sprintf( '<link rel="preload" href="%s" as="%s"%s>', esc_url( $r['url'] ), esc_attr( $r['as'] ), $cross );
					break;
				case 'preconnect':
					$head[] = sprintf( '<link rel="preconnect" href="%s" crossorigin>', esc_url( $r['url'] ) );
					break;
				case 'lcp_image':
					self::$lcp_urls[] = $r['url'];
					break;
				case 'disable_emojis':
					remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
					remove_action( 'wp_print_styles', 'print_emoji_styles' );
					remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
					remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
					break;
				case 'disable_embeds':
					add_action( 'wp_footer', static fn() => wp_dequeue_script( 'wp-embed' ), 1 );
					remove_action( 'wp_head', 'wp_oembed_add_host_js' );
					break;
				case 'remove_jquery_migrate':
					add_action(
						'wp_default_scripts',
						static function ( $scripts ) {
							if ( isset( $scripts->registered['jquery'] ) ) {
								$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
							}
						}
					);
					if ( wp_script_is( 'jquery', 'registered' ) ) {
						$jq       = wp_scripts()->registered['jquery'];
						$jq->deps = array_diff( $jq->deps, array( 'jquery-migrate' ) );
					}
					break;
			}
		}
		if ( $attr ) {
			add_filter(
				'script_loader_tag',
				static function ( $tag, $handle ) use ( $attr ) {
					if ( isset( $attr[ $handle ] ) && ! preg_match( '/\s(defer|async)[\s>=]/', $tag ) ) {
						$tag = preg_replace( '/<script\s/', '<script ' . $attr[ $handle ] . ' ', $tag, 1 );
					}
					return $tag;
				},
				20,
				2
			);
		}
		if ( $deq_s || $deq_c ) {
			add_action(
				'wp_enqueue_scripts',
				static function () use ( $deq_s, $deq_c ) {
					foreach ( $deq_s as $h ) {
						wp_dequeue_script( $h );
					}
					foreach ( $deq_c as $h ) {
						wp_dequeue_style( $h );
					}
				},
				9999
			);
		}
		if ( $head ) {
			add_action( 'wp_head', static fn() => print( implode( "\n", $head ) . "\n" ), 1 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( self::$lcp_urls ) {
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'lcp_attrs' ), 20 );
			add_filter( 'wp_content_img_tag', array( __CLASS__, 'lcp_tag' ), 20 );
		}
	}

	private static function is_lcp( $src ) {
		foreach ( self::$lcp_urls as $u ) {
			if ( $src && ( $src === $u || false !== strpos( $src, preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', basename( $u ) ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function lcp_attrs( $attr ) {
		if ( self::is_lcp( $attr['src'] ?? '' ) ) {
			$attr['fetchpriority'] = 'high';
			unset( $attr['loading'] );
		}
		return $attr;
	}

	public static function lcp_tag( $tag ) {
		if ( preg_match( '/src="([^"]+)"/', $tag, $m ) && self::is_lcp( $m[1] ) ) {
			$tag = preg_replace( '/\sloading="lazy"/', '', $tag );
			if ( false === strpos( $tag, 'fetchpriority' ) ) {
				$tag = str_replace( '<img ', '<img fetchpriority="high" ', $tag );
			}
		}
		return $tag;
	}
}
