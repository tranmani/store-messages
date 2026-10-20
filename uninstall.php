<?php
/**
 * Remove Store Messages settings when the plugin is deleted.
 * Order notes and the per-order message history stay with the orders.
 *
 * The subscriber list and campaigns are kept, so deleting and reinstalling
 * the plugin never loses who agreed to your newsletter. To remove them as
 * well, add define( 'STMS_REMOVE_ALL_DATA', true ); to wp-config.php first.
 *
 * @package StoreMessages
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'stms_settings', 'stms_templates', 'stms_carriers', 'stms_activity', 'stms_carrier_by_country' ] as $option ) {
	delete_option( $option );
}
delete_site_transient( 'stms_update_release' );

if ( defined( 'STMS_REMOVE_ALL_DATA' ) && STMS_REMOVE_ALL_DATA ) {
	global $wpdb;
	foreach ( [ 'stms_subscribers', 'stms_campaigns', 'stms_queue' ] as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
	}
	delete_option( 'stms_db_version' );
}
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', [], 'store-messages' );
}
