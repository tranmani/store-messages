<?php
/**
 * Plugin Name:       Store Messages
 * Plugin URI:        https://github.com/tranmani/store-messages
 * Description:       Beautiful, customisable emails from your WooCommerce store to your customers: tracking links, pickup notices, refunds, delays and personal messages, sent from the order screen. Templates and layout are plain HTML you control.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Store Messages contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       store-messages
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'STMS_VERSION', '1.0.0' );
define( 'STMS_FILE', __FILE__ );
define( 'STMS_DIR', __DIR__ );
define( 'STMS_URL', plugin_dir_url( __FILE__ ) );

require_once STMS_DIR . '/includes/functions.php';
require_once STMS_DIR . '/includes/class-carriers.php';
require_once STMS_DIR . '/includes/class-templates.php';
require_once STMS_DIR . '/includes/class-renderer.php';
require_once STMS_DIR . '/includes/class-tracking.php';
require_once STMS_DIR . '/includes/class-mailer.php';
require_once STMS_DIR . '/includes/class-woocommerce.php';
require_once STMS_DIR . '/includes/class-updater.php';

if ( is_admin() ) {
	require_once STMS_DIR . '/admin/class-admin.php';
	require_once STMS_DIR . '/admin/class-order-box.php';
}

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'store-messages', false, dirname( plugin_basename( STMS_FILE ) ) . '/languages' );
	StoreMessages\Updater::init();
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Store Messages needs WooCommerce to be active.', 'store-messages' ) . '</p></div>';
			}
		} );
		return;
	}
	StoreMessages\WooCommerce::init();
	if ( is_admin() ) {
		StoreMessages\Admin\Admin::init();
		StoreMessages\Admin\Order_Box::init();
	}
}, 20 );

// Declare compatibility with WooCommerce's order storage (HPOS).
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', STMS_FILE, true );
	}
} );
