<?php
/**
 * Remove Store Messages settings when the plugin is deleted.
 * Order notes and the per-order message history stay with the orders.
 *
 * @package StoreMessages
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( [ 'stms_settings', 'stms_templates', 'stms_carriers', 'stms_activity', 'stms_carrier_by_country' ] as $option ) {
	delete_option( $option );
}
delete_site_transient( 'stms_update_release' );
