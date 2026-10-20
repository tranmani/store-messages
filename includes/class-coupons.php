<?php
/**
 * Personal welcome coupons: one unique code per subscriber, created the first
 * time an email uses {coupon_code}. Optionally valid on a first order only.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Coupons {

	const META_FIRST = '_stms_first_order';
	const META_SUB   = '_stms_subscriber';

	public static function init() {
		add_filter( 'woocommerce_coupon_is_valid', [ __CLASS__, 'check_first_order' ], 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'check_at_checkout' ], 10, 2 );
	}

	public static function settings() {
		return stms_settings()['coupon'];
	}

	/** The subscriber's code, created on first use. Empty when coupons are off or creation failed. */
	public static function issue( $sub ) {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || ! $sub || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return '';
		}
		if ( '' !== (string) $sub->coupon && wc_get_coupon_id_by_code( $sub->coupon ) ) {
			return (string) $sub->coupon;
		}
		$code = self::new_code();
		try {
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'fixed_cart' === $s['type'] ? 'fixed_cart' : 'percent' );
			$coupon->set_amount( (float) $s['amount'] );
			$coupon->set_individual_use( ! empty( $s['individual_use'] ) );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			if ( (int) $s['days'] > 0 ) {
				$coupon->set_date_expires( time() + (int) $s['days'] * DAY_IN_SECONDS );
			}
			if ( ! empty( $s['restrict_email'] ) ) {
				$coupon->set_email_restrictions( [ $sub->email ] );
			}
			/* translators: %s: email address */
			$coupon->set_description( sprintf( __( 'Welcome code for %s (Store Messages)', 'store-messages' ), $sub->email ) );
			$coupon->update_meta_data( self::META_SUB, (int) $sub->id );
			if ( ! empty( $s['first_order'] ) ) {
				$coupon->update_meta_data( self::META_FIRST, 1 );
			}
			$coupon->save();
		} catch ( \Exception $e ) {
			return '';
		}
		Subscribers::update( $sub->id, [ 'coupon' => $code ] );
		$sub->coupon = $code;
		return $code;
	}

	/** "Ab3k-7xq2mz-p9tw" style, lower case, never already in use. */
	private static function new_code() {
		$chars = 'abcdefghjkmnpqrstuvwxyz23456789';
		do {
			$part = static function ( $n ) use ( $chars ) {
				$out = '';
				for ( $i = 0; $i < $n; $i++ ) {
					$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
				}
				return $out;
			};
			$code = $part( 4 ) . '-' . $part( 6 ) . '-' . $part( 4 );
		} while ( wc_get_coupon_id_by_code( $code ) );
		return $code;
	}

	/** How the discount reads in an email: "10%" or "CHF 10.00". */
	public static function amount_text( $s = null ) {
		$s = $s ?: self::settings();
		if ( 'fixed_cart' === $s['type'] ) {
			return wp_strip_all_tags( html_entity_decode( wc_price( (float) $s['amount'] ), ENT_QUOTES ) );
		}
		return rtrim( rtrim( number_format( (float) $s['amount'], 2, '.', '' ), '0' ), '.' ) . '%';
	}

	public static function expiry_text( $code ) {
		$id = $code ? wc_get_coupon_id_by_code( $code ) : 0;
		if ( ! $id ) {
			$days = (int) self::settings()['days'];
			return $days > 0 ? wc_format_datetime( new \WC_DateTime( '@' . ( time() + $days * DAY_IN_SECONDS ) ) ) : '';
		}
		$expires = ( new \WC_Coupon( $id ) )->get_date_expires();
		return $expires ? wc_format_datetime( $expires ) : '';
	}

	/* ------------------------------------------------------- first order -- */

	public static function has_paid_order( $email, $user_id = 0 ) {
		$query = [ 'limit' => 1, 'return' => 'ids', 'status' => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ], 'type' => 'shop_order' ];
		if ( $user_id && wc_get_orders( $query + [ 'customer_id' => (int) $user_id ] ) ) {
			return true;
		}
		return is_email( $email ) && (bool) wc_get_orders( $query + [ 'billing_email' => $email ] );
	}

	private static function first_order_message() {
		return __( 'This code is for your first order only.', 'store-messages' );
	}

	public static function check_first_order( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! $coupon->get_meta( self::META_FIRST ) || ! function_exists( 'WC' ) || ! WC()->customer ) {
			return $valid;
		}
		if ( self::has_paid_order( (string) WC()->customer->get_billing_email(), get_current_user_id() ) ) {
			throw new \Exception( esc_html( self::first_order_message() ) ); // WooCommerce shows the message.
		}
		return $valid;
	}

	/** At checkout the billing email is final: check it again. */
	public static function check_at_checkout( $data, $errors ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( $coupon->get_meta( self::META_FIRST ) && self::has_paid_order( (string) ( $data['billing_email'] ?? '' ), get_current_user_id() ) ) {
				$errors->add( 'stms_first_order', self::first_order_message() );
			}
		}
	}
}
