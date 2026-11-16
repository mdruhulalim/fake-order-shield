<?php
/**
 * Remove data on uninstall when the admin chose "Delete all data".
 *
 * @package OrderShield
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$oshield_settings = get_option( 'oshield_settings', array() );
if ( empty( $oshield_settings['delete_on_uninstall'] ) || '1' !== (string) $oshield_settings['delete_on_uninstall'] ) {
	return;
}

global $wpdb;
foreach ( array( 'oshield_blocks', 'oshield_events', 'oshield_incomplete', 'oshield_recordings', 'oshield_sms_log', 'oshield_shipments', 'oshield_visitors', 'oshield_pageviews' ) as $oshield_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$oshield_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
delete_option( 'oshield_settings' );
delete_option( 'oshield_db_version' );
delete_option( 'oshield_pathao_token' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_oshield\_%' OR option_name LIKE '\_transient\_timeout\_oshield\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'oshield_hourly' );
wp_clear_scheduled_hook( 'oshield_daily' );
