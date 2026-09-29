<?php
/**
 * Uninstall MCSM.
 *
 * @package MCSnippetManager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$delete_site_data = static function () use ( $wpdb ) {
	if ( 'yes' !== get_option( 'mcsm_delete_data_on_uninstall', 'no' ) ) {
		return;
	}

	$table_name = $wpdb->prefix . 'mc_snippets';
	$activity_table = $wpdb->prefix . 'mc_snippet_activity';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$activity_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_mcsm_local_snippets' ), array( '%s' ) );
	delete_option( 'mcsm_delete_data_on_uninstall' );
	delete_option( 'mcsm_output_comments' );
	delete_option( 'mcsm_activity_log_retention' );
	delete_option( 'mcsm_version' );
};

if ( is_multisite() ) {
	$offset = 0;

	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( 'mcsm_cleanup_activity_log' );
			$delete_site_data();
			restore_current_blog();
		}

		$offset += count( $site_ids );
	} while ( 100 === count( $site_ids ) );
} else {
	wp_clear_scheduled_hook( 'mcsm_cleanup_activity_log' );
	$delete_site_data();
}
