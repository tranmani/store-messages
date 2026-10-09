<?php
/**
 * Tracking details saved on the order.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Tracking {

	const META = '_stms_tracking';

	/** [carrier, name, number, url, date] or null. Falls back to WooCommerce Shipment Tracking's data. */
	public static function get( \WC_Order $order ) {
		$saved = $order->get_meta( self::META );
		if ( is_array( $saved ) && ! empty( $saved['url'] ) ) {
			return $saved;
		}
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) && $items ) {
			$last = end( $items );
			$url  = $last['custom_tracking_link'] ?? '';
			if ( $url || ! empty( $last['tracking_number'] ) ) {
				return [
					'carrier' => '',
					'name'    => $last['custom_tracking_provider'] ?: ( $last['tracking_provider'] ?? '' ),
					'number'  => (string) ( $last['tracking_number'] ?? '' ),
					'url'     => (string) $url,
					'date'    => (int) ( $last['date_shipped'] ?? 0 ),
				];
			}
		}
		return null;
	}

	public static function save( \WC_Order $order, array $tracking ) {
		if ( empty( $tracking['url'] ) ) {
			return;
		}
		$order->update_meta_data( self::META, [
			'carrier' => (string) ( $tracking['carrier'] ?? '' ),
			'name'    => (string) ( $tracking['name'] ?? '' ),
			'number'  => (string) ( $tracking['number'] ?? '' ),
			'url'     => esc_url_raw( $tracking['url'] ),
			'date'    => time(),
		] );
		$order->save_meta_data();
	}
}
