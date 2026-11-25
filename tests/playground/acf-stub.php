<?php
/* Minimal ACF stand-in for tests: one flexible content field group on pages + one options page.
 * Storage mimics ACF: name => value / row count / layout list, _name => field key. */
define( 'ACF_VERSION', 'stub' );
function csa_stub_groups() {
	$sub = fn( $k, $n, $t = 'text', $extra = array() ) => array( 'key' => "field_$k", 'name' => $n, 'label' => ucfirst( $n ), 'type' => $t ) + $extra;
	return array(
		array( 'key' => 'group_page', 'title' => 'Page builder', 'loc' => array( 'post_type' => 'page' ), 'fields' => array(
			$sub( 'intro', 'intro_text', 'textarea' ),
			$sub( 'blocks', 'page_blocks', 'flexible_content', array( 'layouts' => array(
				array( 'name' => 'hero', 'label' => 'Hero', 'sub_fields' => array( $sub( 'h1', 'heading' ), $sub( 'h2', 'subheading' ), $sub( 'h3', 'image', 'image' ) ) ),
				array( 'name' => 'features', 'label' => 'Features', 'sub_fields' => array( $sub( 'f1', 'items', 'repeater', array( 'sub_fields' => array( $sub( 'f2', 'title' ), $sub( 'f3', 'text', 'wysiwyg' ) ) ) ) ) ),
				array( 'name' => 'cta', 'label' => 'Call to action', 'sub_fields' => array( $sub( 'c1', 'text' ), $sub( 'c2', 'button', 'link' ) ) ),
			) ) ),
		) ),
		array( 'key' => 'group_opts', 'title' => 'Site options', 'loc' => array( 'options_page' => 'site-options' ), 'fields' => array( $sub( 'ft', 'footer_text' ) ) ),
	);
}
function acf_get_field_groups( $args = array() ) {
	$out = array();
	foreach ( csa_stub_groups() as $g ) {
		if ( isset( $args['options_page'] ) ) { if ( ( $g['loc']['options_page'] ?? '' ) === $args['options_page'] ) $out[] = $g; continue; }
		$pt = $args['post_type'] ?? ( isset( $args['post_id'] ) ? get_post_type( $args['post_id'] ) : '' );
		if ( ( $g['loc']['post_type'] ?? '' ) === $pt ) $out[] = $g;
	}
	return $out;
}
function acf_get_fields( $g ) { return $g['fields']; }
function acf_get_options_pages() { return array( 'site-options' => array( 'page_title' => 'Site options', 'post_id' => 'options' ) ); }
function acf_get_options_page( $s ) { $p = acf_get_options_pages(); return $p[ $s ] ?? false; }
function csa_stub_find( $sel ) {
	foreach ( csa_stub_groups() as $g ) foreach ( $g['fields'] as $f ) if ( $f['key'] === $sel || $f['name'] === $sel ) return $f;
	return null;
}
function csa_stub_set( $pid, $k, $v ) { is_numeric( $pid ) ? update_post_meta( $pid, $k, $v ) : update_option( "options_$k", $v ); }
function csa_stub_get( $pid, $k ) { return is_numeric( $pid ) ? get_post_meta( $pid, $k, true ) : get_option( "options_$k", '' ); }
function csa_stub_write( $pid, $prefix, $f, $v ) {
	$name = $prefix . $f['name'];
	csa_stub_set( $pid, '_' . $name, $f['key'] );
	if ( 'flexible_content' === $f['type'] ) {
		$layouts = array();
		foreach ( array_values( (array) $v ) as $i => $row ) {
			$layouts[] = $row['acf_fc_layout'];
			foreach ( $f['layouts'] as $l ) if ( $l['name'] === $row['acf_fc_layout'] ) foreach ( $l['sub_fields'] as $s ) csa_stub_write( $pid, "{$name}_{$i}_", $s, $row[ $s['name'] ] ?? $row[ $s['key'] ] ?? null );
		}
		csa_stub_set( $pid, $name, $layouts );
	} elseif ( 'repeater' === $f['type'] ) {
		foreach ( array_values( (array) $v ) as $i => $row ) foreach ( $f['sub_fields'] as $s ) csa_stub_write( $pid, "{$name}_{$i}_", $s, $row[ $s['name'] ] ?? null );
		csa_stub_set( $pid, $name, count( (array) $v ) );
	} else {
		csa_stub_set( $pid, $name, $v );
	}
}
function csa_stub_read( $pid, $prefix, $f ) {
	$name = $prefix . $f['name'];
	$raw  = csa_stub_get( $pid, $name );
	if ( 'flexible_content' === $f['type'] ) {
		$rows = array();
		foreach ( (array) $raw as $i => $ln ) { $row = array( 'acf_fc_layout' => $ln ); foreach ( $f['layouts'] as $l ) if ( $l['name'] === $ln ) foreach ( $l['sub_fields'] as $s ) $row[ $s['name'] ] = csa_stub_read( $pid, "{$name}_{$i}_", $s ); $rows[] = $row; }
		return $rows;
	}
	if ( 'repeater' === $f['type'] ) {
		$rows = array();
		for ( $i = 0; $i < (int) $raw; $i++ ) { $row = array(); foreach ( $f['sub_fields'] as $s ) $row[ $s['name'] ] = csa_stub_read( $pid, "{$name}_{$i}_", $s ); $rows[] = $row; }
		return $rows;
	}
	return $raw;
}
function update_field( $sel, $v, $pid = false ) { $f = csa_stub_find( $sel ); if ( ! $f ) return false; csa_stub_write( 'options' === $pid || 'option' === $pid ? 'options' : $pid, '', $f, $v ); return true; }
function get_field( $sel, $pid = false, $fmt = true ) { $f = csa_stub_find( $sel ); return $f ? csa_stub_read( 'option' === $pid || 'options' === $pid ? 'options' : $pid, '', $f ) : null; }
function get_fields( $pid = false, $fmt = true ) {
	$out = array();
	foreach ( acf_get_field_groups( array( 'post_id' => $pid ) ) as $g ) foreach ( $g['fields'] as $f ) if ( '' !== csa_stub_get( $pid, $f['name'] ) ) $out[ $f['name'] ] = csa_stub_read( $pid, '', $f );
	return $out ?: false;
}
