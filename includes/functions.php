<?php
/**
 * Settings and small helpers.
 *
 * @package StoreMessages
 */

defined( 'ABSPATH' ) || exit;

const STMS_SETTINGS = 'stms_settings';

function stms_default_settings() {
	// Translations may only load from "init" on (WordPress 6.7+); earlier, defaults are in English.
	$t = did_action( 'init' ) ? static fn( $text ) => translate( $text, 'store-messages' ) : static fn( $text ) => $text;
	return [
		'from_name'        => '',  // empty: WooCommerce's "From" name
		'from_email'       => '',  // empty: WooCommerce's "From" address
		'reply_to'         => '',  // empty: the From address
		'bcc'              => '',  // optional copy of every message
		'note_emails'      => 1,   // send "Note to customer" through Store Messages instead of WooCommerce's email
		'skip_completed'   => 1,   // when a message marks the order Completed, skip WooCommerce's "Order complete" email
		'show_tracking'    => 1,   // show tracking on My account → Orders and in WooCommerce emails
		'style_wc_emails'  => 1,   // WooCommerce's own emails use the layout
		'style_wp_emails'  => 1,   // WordPress's plain-text emails use the layout
		'review_url'       => '',
		'brand'            => [
			'logo'       => '',
			'logo_width' => 160,
			'accent'     => '',
			'background' => '#f4f4f5',
			'surface'    => '#ffffff',
			'text'       => '#27272a',
			'footer'     => '',
		],
		'layout'           => '',  // empty: built-in layout
		'updates'          => 1,
		'signup'           => [
			'double_optin'      => 0,   // confirm by email before someone counts as subscribed
			'checkout'          => 0,   // checkbox on the checkout page
			'checkout_position' => 'top', // top: before the terms; bottom: just above the order button
			'checkout_label'    => $t( 'Email me offers and news (you can unsubscribe at any time)' ),
			'account'           => 1,   // checkbox on My account → Account details
			'account_label'     => $t( 'Email me offers and news' ),
			'welcome'           => 1,   // send the "Welcome" template to new subscribers
			'welcome_delay'     => 1,   // minutes
			'popup'             => 0,
			'popup_heading'     => $t( 'Get 10% off your first order' ),
			'popup_text'        => $t( 'Join our newsletter and we will email you a personal code right away.' ),
			'popup_button'      => $t( 'Send me my code' ),
			'popup_note'        => $t( 'No spam. Unsubscribe with one click.' ),
			'popup_image'       => '',
			'popup_name'        => 1,   // ask for the first name too
			'popup_delay'       => 15,  // seconds; 0 = only on scroll
			'popup_scroll'      => 30,  // percent of the page; 0 = only after the delay
			'popup_days'        => 7,   // closed: ask again after this many days
			'popup_pages'       => 'all', // all | home | shop
			'popup_langs'       => '',  // comma-separated language codes; empty: all
			'success'           => $t( 'Thank you! Check your inbox: your code is on its way.' ),
			'brave'             => '',  // comma-separated Brave Popup ids whose forms add subscribers
		],
		'coupon'           => [
			'enabled'        => 1,  // {coupon_code} creates a personal code
			'type'           => 'percent', // percent | fixed_cart
			'amount'         => 10,
			'days'           => 30, // 0 = never expires
			'first_order'    => 1,  // only valid when the customer has no paid order yet
			'individual_use' => 1,  // cannot be combined with other codes
			'restrict_email' => 0,  // only for the subscriber's own email address
		],
		'sending'          => [
			'batch'    => 25, // emails per batch
			'interval' => 5,  // minutes between batches
		],
	];
}

/** Settings merged over defaults; brand colours fall back to WooCommerce's email colours. */
function stms_settings( $refresh = false ) {
	static $settings = null, $translated = false;
	if ( null === $settings || $refresh || ( ! $translated && did_action( 'init' ) ) ) {
		$translated = (bool) did_action( 'init' );
		$saved    = get_option( STMS_SETTINGS, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$defaults = stms_default_settings();
		$settings = array_merge( $defaults, $saved );
		foreach ( [ 'brand', 'signup', 'coupon', 'sending' ] as $group ) {
			$settings[ $group ] = array_merge( $defaults[ $group ], (array) ( $saved[ $group ] ?? [] ) );
		}
	}
	return $settings;
}

function stms_update_settings( array $changes ) {
	$current = get_option( STMS_SETTINGS, [] );
	update_option( STMS_SETTINGS, array_merge( is_array( $current ) ? $current : [], $changes ), false );
	stms_settings( true );
}

/** Brand values with WooCommerce and theme fallbacks applied. */
function stms_brand() {
	$b = stms_settings()['brand'];
	if ( '' === $b['accent'] ) {
		$b['accent'] = get_option( 'woocommerce_email_base_color' ) ?: '#2563eb';
	}
	if ( '' === $b['logo'] ) {
		$b['logo'] = get_option( 'woocommerce_email_header_image' ) ?: '';
		if ( '' === $b['logo'] && get_theme_mod( 'custom_logo' ) ) {
			$b['logo'] = (string) wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' );
		}
	}
	return $b;
}

function stms_from_name() {
	return stms_settings()['from_name'] ?: ( get_option( 'woocommerce_email_from_name' ) ?: get_bloginfo( 'name' ) );
}

function stms_from_email() {
	return stms_settings()['from_email'] ?: ( get_option( 'woocommerce_email_from_address' ) ?: get_option( 'admin_email' ) );
}

/** Store address on one line, from WooCommerce → Settings → General. */
function stms_store_address() {
	$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries : null;
	if ( ! $countries ) {
		return '';
	}
	$parts = array_filter( [
		$countries->get_base_address(),
		$countries->get_base_address_2(),
		trim( $countries->get_base_postcode() . ' ' . $countries->get_base_city() ),
		$countries->countries[ $countries->get_base_country() ] ?? '',
	] );
	return implode( ', ', $parts );
}

/** Contrast colour (dark or white) for text on a background colour. */
function stms_contrast( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		return '#ffffff';
	}
	[ $r, $g, $b ] = array_map( 'hexdec', str_split( $hex, 2 ) );
	return ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) > 165 ? '#18181b' : '#ffffff';
}
