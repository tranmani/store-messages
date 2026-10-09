<?php
/**
 * Settings and small helpers.
 *
 * @package StoreMessages
 */

defined( 'ABSPATH' ) || exit;

const STMS_SETTINGS = 'stms_settings';

function stms_default_settings() {
	return [
		'from_name'        => '',  // empty: WooCommerce's "From" name
		'from_email'       => '',  // empty: WooCommerce's "From" address
		'reply_to'         => '',  // empty: the From address
		'bcc'              => '',  // optional copy of every message
		'note_emails'      => 1,   // send "Note to customer" through Store Messages instead of WooCommerce's email
		'skip_completed'   => 1,   // when a message marks the order Completed, skip WooCommerce's "Order complete" email
		'show_tracking'    => 1,   // show tracking on My account → Orders and in WooCommerce emails
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
	];
}

/** Settings merged over defaults; brand colours fall back to WooCommerce's email colours. */
function stms_settings( $refresh = false ) {
	static $settings = null;
	if ( null === $settings || $refresh ) {
		$saved    = get_option( STMS_SETTINGS, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$defaults = stms_default_settings();
		$settings = array_merge( $defaults, $saved );
		$settings['brand'] = array_merge( $defaults['brand'], (array) ( $saved['brand'] ?? [] ) );
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
