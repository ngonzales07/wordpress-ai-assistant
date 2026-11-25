<?php
/**
 * Page speed: analyze front-end output and manage reversible performance rules
 * applied by CSA_Frontend through WordPress hooks (no server or cache config).
 *
 * @package ClaudeSiteAgent
 */

defined( 'ABSPATH' ) || exit;

class CSA_Tools_Performance {

	const OPTION = 'csa_rules';

	public static function rule_types() {
		return array(
			'defer_script'          => 'Add defer to a script handle',
			'async_script'          => 'Add async to a script handle',
			'dequeue_script'        => 'Stop loading a script handle',
			'dequeue_style'         => 'Stop loading a stylesheet handle',
			'preload'               => 'Add <link rel="preload"> for a URL (needs "as")',
			'preconnect'            => 'Add <link rel="preconnect"> for an origin',
			'lcp_image'             => 'Give an image URL fetchpriority="high" and no lazy-loading (largest contentful paint image)',
			'disable_emojis'        => 'Remove the WordPress emoji script and styles',
			'disable_embeds'        => 'Remove the oEmbed discovery script',
			'remove_jquery_migrate' => 'Stop loading jquery-migrate',
		);
	}

	public static function get_rules() {
		$r = get_option( self::OPTION, array() );
		return is_array( $r ) ? $r : array();
	}

	public static function tools() {
		return array(
			array(
				'name'         => 'analyze_page',
				'description'  => 'Fetch a page on this site as a logged-out visitor and report response time, HTML size, cache headers, every script and stylesheet (with WordPress handle when detectable, defer/async, location), images missing width/height or lazy-loading, and preloads. Use before proposing speed changes.',
				'cap'          => 'performance',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string', 'description' => 'A URL on this site. Default: home page.' ),
					),
				),
				'run'          => array( __CLASS__, 'analyze' ),
			),
			array(
				'name'         => 'run_pagespeed',
				'description'  => 'Run Google PageSpeed Insights (Lighthouse) for a public URL on this site. Returns the performance score, Core Web Vitals and the top opportunities. Takes 20-50 seconds.',
				'cap'          => 'performance',
				'write'        => false,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'url'      => array( 'type' => 'string' ),
						'strategy' => array( 'type' => 'string', 'enum' => array( 'mobile', 'desktop' ) ),
					),
					'required'   => array( 'url' ),
				),
				'run'          => array( __CLASS__, 'pagespeed' ),
			),
			array(
				'name'         => 'list_performance_rules',
				'description'  => 'List the front-end performance rules this plugin applies, and the rule types available.',
				'cap'          => 'performance',
				'write'        => false,
				'input_schema' => array( 'type' => 'object', 'properties' => new stdClass() ),
				'run'          => array( __CLASS__, 'list_rules' ),
			),
			array(
				'name'         => 'set_performance_rule',
				'description'  => 'Add or replace a front-end performance rule (applied with WordPress hooks; never edits server, cache or hosting config). Types: ' . implode( '; ', array_map( fn( $k, $v ) => "$k = $v", array_keys( self::rule_types() ), self::rule_types() ) ) . '. Scope with post_ids to test on one page first. Dequeuing can break layouts, so analyze first.',
				'cap'          => 'performance',
				'write'        => true,
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'string', 'description' => 'Slug; reuse to replace.' ),
						'type'     => array( 'type' => 'string', 'enum' => array_keys( self::rule_types() ) ),
						'handle'   => array( 'type' => 'string', 'description' => 'Script/style handle for defer/async/dequeue.' ),
						'url'      => array( 'type' => 'string', 'description' => 'For preload, preconnect, lcp_image.' ),
						'as'       => array( 'type' => 'string', 'enum' => array( 'image', 'font', 'style', 'script', 'fetch' ) ),
						'post_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Only on these posts. Omit for site-wide.' ),
						'note'     => array( 'type' => 'string' ),
					),
					'required'   => array( 'id', 'type' ),
				),
				'preview'      => array( __CLASS__, 'preview_set' ),
				'apply'        => array( __CLASS__, 'apply_set' ),
			),
			array(
				'name'         => 'remove_performance_rule',
				'description'  => 'Remove a performance rule by id.',
				'cap'          => 'performance',
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

	/* ---------- analysis ---------- */

	private static function target_url( $input ) {
		if ( ! empty( $input['post_id'] ) ) {
			$p = get_post( (int) $input['post_id'] );
			if ( ! $p || 'publish' !== $p->post_status ) {
				return CSA_Tool::err( 'Only published posts can be analyzed as a visitor.' );
			}
			return get_permalink( $p );
		}
		$url = $input['url'] ?? home_url( '/' );
		if ( ! CSA_Guard::is_own_url( $url ) ) {
			return CSA_Tool::err( 'Only URLs on this site can be analyzed.' );
		}
		return $url;
	}

	public static function analyze( $input ) {
		$url = self::target_url( $input );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$start = microtime( true );
		$res   = wp_remote_get( $url, array( 'timeout' => 25, 'redirection' => 3, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		$ms    = round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$html    = wp_remote_retrieve_body( $res );
		$headers = wp_remote_retrieve_headers( $res );
		$out     = array(
			'url'           => $url,
			'status'        => wp_remote_retrieve_response_code( $res ),
			'response_ms'   => $ms,
			'html_kb'       => round( strlen( $html ) / 1024, 1 ),
			'cache_headers' => array_filter(
				array(
					'cache-control' => $headers['cache-control'] ?? null,
					'x-cache'       => $headers['x-cache'] ?? null,
					'x-cacheable'   => $headers['x-cacheable'] ?? null,
					'age'           => $headers['age'] ?? null,
				)
			),
		);
		if ( '' === $html ) {
			return $out;
		}
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		$xp = new DOMXPath( $dom );

		$scripts = array();
		$inline  = 0;
		foreach ( $xp->query( '//script' ) as $s ) {
			$src = $s->getAttribute( 'src' );
			if ( ! $src ) {
				$inline += strlen( $s->textContent );
				continue;
			}
			$id        = $s->getAttribute( 'id' );
			$scripts[] = array(
				'handle'   => $id ? preg_replace( '/-js$/', '', $id ) : null,
				'src'      => $src,
				'in_head'  => (bool) $xp->query( 'ancestor::head', $s )->length,
				'defer'    => $s->hasAttribute( 'defer' ),
				'async'    => $s->hasAttribute( 'async' ),
				'module'   => 'module' === $s->getAttribute( 'type' ),
			);
		}
		$styles = array();
		foreach ( $xp->query( '//link[@rel="stylesheet"]' ) as $l ) {
			$id       = $l->getAttribute( 'id' );
			$styles[] = array(
				'handle' => $id ? preg_replace( '/-css$/', '', $id ) : null,
				'href'   => $l->getAttribute( 'href' ),
				'media'  => $l->getAttribute( 'media' ) ?: 'all',
			);
		}
		$img_issues = array();
		$img_count  = 0;
		foreach ( $xp->query( '//img' ) as $i => $img ) {
			$img_count++;
			$issues = array();
			if ( ! $img->getAttribute( 'width' ) || ! $img->getAttribute( 'height' ) ) {
				$issues[] = 'no width/height';
			}
			if ( 'lazy' !== $img->getAttribute( 'loading' ) && $i > 2 ) {
				$issues[] = 'not lazy-loaded';
			}
			if ( '' === trim( $img->getAttribute( 'alt' ) ) && ! $img->hasAttribute( 'alt' ) ) {
				$issues[] = 'no alt attribute';
			}
			if ( $issues && count( $img_issues ) < 40 ) {
				$img_issues[] = array( 'src' => $img->getAttribute( 'src' ), 'position' => $i, 'issues' => $issues, 'fetchpriority' => $img->getAttribute( 'fetchpriority' ) ?: null );
			}
		}
		$preloads = array();
		foreach ( $xp->query( '//link[@rel="preload" or @rel="preconnect" or @rel="dns-prefetch"]' ) as $l ) {
			$preloads[] = $l->getAttribute( 'rel' ) . ' ' . $l->getAttribute( 'href' );
		}
		$out['scripts']         = $scripts;
		$out['render_blocking_scripts'] = count( array_filter( $scripts, fn( $s ) => $s['in_head'] && ! $s['defer'] && ! $s['async'] && ! $s['module'] ) );
		$out['stylesheets']     = $styles;
		$out['inline_script_kb'] = round( $inline / 1024, 1 );
		$out['images']          = $img_count;
		$out['image_issues']    = $img_issues;
		$out['preloads']        = $preloads;
		$out['active_rules']    = array_values( self::get_rules() );
		$out['note']            = 'Handles are inferred from WordPress id attributes (handle-js / handle-css). Page cache may hide changes until it is cleared.';
		return $out;
	}

	public static function pagespeed( $input ) {
		if ( ! CSA_Guard::is_own_url( $input['url'] ) ) {
			return CSA_Tool::err( 'Only URLs on this site can be tested.' );
		}
		$q = array(
			'url'      => $input['url'],
			'strategy' => 'desktop' === ( $input['strategy'] ?? '' ) ? 'desktop' : 'mobile',
			'category' => 'performance',
		);
		$key = CSA_Settings::get( 'pagespeed_key' );
		if ( $key ) {
			$q['key'] = $key;
		}
		$res = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $q ), 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ), array( 'timeout' => 55 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$d = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $d['lighthouseResult'] ) ) {
			return CSA_Tool::err( 'PageSpeed Insights error: ' . ( $d['error']['message'] ?? 'no result' ) );
		}
		$lh     = $d['lighthouseResult'];
		$audits = $lh['audits'];
		$opps   = array();
		foreach ( $audits as $id => $a ) {
			$save = $a['details']['overallSavingsMs'] ?? 0;
			if ( $save > 50 || ( isset( $a['score'] ) && null !== $a['score'] && $a['score'] < 0.5 && in_array( $a['details']['type'] ?? '', array( 'opportunity', 'table' ), true ) ) ) {
				$items = array();
				foreach ( array_slice( (array) ( $a['details']['items'] ?? array() ), 0, 6 ) as $it ) {
					$items[] = array_intersect_key( $it, array_flip( array( 'url', 'wastedBytes', 'wastedMs', 'totalBytes', 'node' ) ) );
				}
				$opps[] = array( 'id' => $id, 'title' => $a['title'], 'savings_ms' => round( $save ), 'display' => $a['displayValue'] ?? '', 'items' => $items );
			}
		}
		usort( $opps, fn( $a, $b ) => $b['savings_ms'] <=> $a['savings_ms'] );
		return array(
			'url'           => $input['url'],
			'strategy'      => $q['strategy'],
			'score'         => round( ( $lh['categories']['performance']['score'] ?? 0 ) * 100 ),
			'metrics'       => array(
				'LCP' => $audits['largest-contentful-paint']['displayValue'] ?? null,
				'CLS' => $audits['cumulative-layout-shift']['displayValue'] ?? null,
				'TBT' => $audits['total-blocking-time']['displayValue'] ?? null,
				'FCP' => $audits['first-contentful-paint']['displayValue'] ?? null,
				'SI'  => $audits['speed-index']['displayValue'] ?? null,
			),
			'opportunities' => array_slice( $opps, 0, 12 ),
		);
	}

	public static function list_rules() {
		return array( 'rules' => array_values( self::get_rules() ), 'types' => self::rule_types() );
	}

	/* ---------- rules ---------- */

	private static function normalize( $input ) {
		$type = $input['type'];
		if ( ! isset( self::rule_types()[ $type ] ) ) {
			return CSA_Tool::err( 'Unknown rule type.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return CSA_Tool::err( 'Performance rules require the edit_theme_options capability.' );
		}
		$rule = array(
			'id'       => sanitize_key( $input['id'] ),
			'type'     => $type,
			'post_ids' => array_values( array_map( 'intval', (array) ( $input['post_ids'] ?? array() ) ) ),
			'note'     => sanitize_text_field( $input['note'] ?? '' ),
			'updated'  => current_time( 'mysql' ),
		);
		if ( in_array( $type, array( 'defer_script', 'async_script', 'dequeue_script', 'dequeue_style' ), true ) ) {
			if ( empty( $input['handle'] ) ) {
				return CSA_Tool::err( 'This rule type needs a handle.' );
			}
			$rule['handle'] = sanitize_text_field( $input['handle'] );
			if ( in_array( $rule['handle'], array( 'jquery', 'jquery-core', 'wp-hooks', 'wp-i18n', 'admin-bar' ), true ) && 0 === strpos( $type, 'dequeue' ) ) {
				return CSA_Tool::err( 'Dequeuing core dependencies like ' . $rule['handle'] . ' breaks other scripts; refusing.' );
			}
		}
		if ( in_array( $type, array( 'preload', 'preconnect', 'lcp_image' ), true ) ) {
			$url = esc_url_raw( $input['url'] ?? '' );
			if ( ! $url ) {
				return CSA_Tool::err( 'This rule type needs a url.' );
			}
			$rule['url'] = $url;
			if ( 'preload' === $type ) {
				if ( empty( $input['as'] ) ) {
					return CSA_Tool::err( 'Preload needs "as".' );
				}
				$rule['as'] = $input['as'];
			}
		}
		return $rule;
	}

	public static function preview_set( $input ) {
		$r = self::normalize( $input );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$old = self::get_rules()[ $r['id'] ] ?? null;
		return array(
			'summary' => sprintf( '%s performance rule "%s": %s%s', $old ? 'Replace' : 'Add', $r['id'], self::rule_types()[ $r['type'] ], $r['post_ids'] ? ' on posts ' . implode( ', ', $r['post_ids'] ) : ' site-wide' ),
			'risk'    => 'code',
			'changes' => array( array( 'label' => $r['id'], 'before' => $old ? wp_json_encode( $old, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '', 'after' => wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ),
		);
	}

	public static function apply_set( $input, CSA_Changeset $cs ) {
		$r = self::normalize( $input );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$cs->before_option( self::OPTION );
		$all             = self::get_rules();
		$all[ $r['id'] ] = $r;
		update_option( self::OPTION, $all, true );
		return array( 'saved' => $r, 'note' => 'Clear the page cache, then re-run analyze_page to confirm the effect. Logged-in users can add ?csa_no_rules=1 to a URL to compare without rules.' );
	}

	public static function preview_remove( $input ) {
		$id  = sanitize_key( $input['id'] );
		$old = self::get_rules()[ $id ] ?? null;
		if ( ! $old ) {
			return CSA_Tool::err( "No rule \"$id\"." );
		}
		return array(
			'summary' => "Remove performance rule \"$id\"",
			'risk'    => 'code',
			'changes' => array( array( 'label' => $id, 'before' => wp_json_encode( $old, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), 'after' => '' ) ),
		);
	}

	public static function apply_remove( $input, CSA_Changeset $cs ) {
		$cs->before_option( self::OPTION );
		$all = self::get_rules();
		unset( $all[ sanitize_key( $input['id'] ) ] );
		update_option( self::OPTION, $all, true );
		return array( 'removed' => sanitize_key( $input['id'] ) );
	}
}
