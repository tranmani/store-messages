<?php
/**
 * WooCommerce integration: "Note to customer" emails, the Order complete email,
 * and tracking on the customer's order page and in WooCommerce emails.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class WooCommerce {

	public static function init() {
		$s = stms_settings();
		if ( ! empty( $s['note_emails'] ) ) {
			add_filter( 'woocommerce_email_enabled_customer_note', '__return_false', 99 );
			add_action( 'woocommerce_new_customer_note', [ __CLASS__, 'customer_note' ], 20 );
		}
		if ( ! empty( $s['skip_completed'] ) ) {
			add_filter( 'woocommerce_email_enabled_customer_completed_order', [ __CLASS__, 'maybe_skip_completed' ], 99 );
		}
		if ( ! empty( $s['show_tracking'] ) ) {
			add_action( 'woocommerce_order_details_after_order_table', [ __CLASS__, 'account_tracking' ], 5 );
			add_action( 'woocommerce_email_order_meta', [ __CLASS__, 'email_tracking' ], 5, 4 );
		}
	}

	/**
	 * A "Note to customer" was added on the order screen: send it with the
	 * Store Messages layout. A tracking link in the note becomes a button and is
	 * saved on the order.
	 */
	public static function customer_note( $data ) {
		$order = wc_get_order( $data['order_id'] ?? 0 );
		$note  = (string) ( $data['customer_note'] ?? '' );
		if ( ! $order || '' === trim( $note ) || ! is_email( $order->get_billing_email() ) ) {
			return;
		}
		$tracking = Carriers::find_in_text( $note );
		if ( $tracking ) {
			// The link is shown as a button; drop it from the text when it stands on its own.
			$note = trim( preg_replace( '#\s*' . preg_quote( $tracking['url'], '#' ) . '\s*\.?#', ' ', $note ) );
			$note = preg_replace( '/[\s:]+$/u', '', $note );
		}
		Mailer::send( $order, 'customer_note', array_filter( [ 'note' => $note, 'tracking' => $tracking ] ) );
	}

	public static function maybe_skip_completed( $enabled ) {
		return Mailer::$completing ? false : $enabled;
	}

	public static function account_tracking( $order ) {
		$tracking = $order instanceof \WC_Order ? Tracking::get( $order ) : null;
		if ( ! $tracking || empty( $tracking['url'] ) ) {
			return;
		}
		echo '<section class="stms-tracking" style="margin:0 0 2em;padding:1em 1.25em;border:1px solid rgba(0,0,0,.1);border-radius:8px">';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Tracking', 'store-messages' ) . '</h2>';
		echo '<p style="margin:0 0 .75em">' . esc_html( trim( $tracking['name'] . ' ' . $tracking['number'] ) ) . '</p>';
		echo '<a class="button" href="' . esc_url( $tracking['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Track your package', 'store-messages' ) . '</a>';
		echo '</section>';
	}

	public static function email_tracking( $order, $sent_to_admin, $plain_text, $email = null ) {
		$tracking = $order instanceof \WC_Order ? Tracking::get( $order ) : null;
		if ( $sent_to_admin || ! $tracking || empty( $tracking['url'] ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Tracking', 'store-messages' ) . ': ' . esc_html( trim( $tracking['name'] . ' ' . $tracking['number'] ) ) . "\n" . esc_url( $tracking['url'] ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Tracking', 'store-messages' ) . '</h2><p>' . esc_html( trim( $tracking['name'] . ' ' . $tracking['number'] ) ) . '<br><a href="' . esc_url( $tracking['url'] ) . '">' . esc_html__( 'Track your package', 'store-messages' ) . '</a></p>';
	}
}
