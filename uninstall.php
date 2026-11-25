<?php
/**
 * Removes plugin data on uninstall (settings, snippets, performance rules, history tables).
 *
 * @package ClaudeSiteAgent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'csa_settings', 'csa_db_version', 'csa_snippets', 'csa_rules' ) as $opt ) {
	delete_option( $opt );
}
$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'csa\\_lock\\_%'" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}csa_sessions, {$wpdb->prefix}csa_changesets" ); // phpcs:ignore
