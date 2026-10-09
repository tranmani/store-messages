<?php
/**
 * Turns a template + an order into a finished email.
 *
 * Syntax inside templates and the layout:
 *   {first_name}                    a placeholder (escaped for HTML)
 *   {if tracking_url} … {/if}       shown only when the value is not empty
 *   {if !tracking_url} … {/if}      shown only when it is empty
 *   {if x} … {else} … {/if}         either / or (blocks can be nested)
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Renderer {

	/** Placeholders whose value is already HTML (everything else is escaped). */
	const HTML = [ 'order_items', 'message', 'note', 'shipping_address', 'billing_address', 'content', 'footer' ];

	/** Placeholders whose value is a URL. */
	const URLS = [ 'tracking_url', 'order_url', 'payment_url', 'site_url', 'shop_url', 'account_url', 'review_url', 'logo' ];

	/** Documented placeholders: [name => description], shown next to the editors. */
	public static function reference() {
		return [
			__( 'Customer', 'store-messages' ) => [
				'first_name'     => __( 'First name (billing)', 'store-messages' ),
				'last_name'      => __( 'Last name', 'store-messages' ),
				'customer_name'  => __( 'Full name', 'store-messages' ),
				'customer_email' => __( 'Email address', 'store-messages' ),
			],
			__( 'Order', 'store-messages' ) => [
				'order_number'     => __( 'Order number', 'store-messages' ),
				'order_date'       => __( 'Date the order was placed', 'store-messages' ),
				'order_status'     => __( 'Current status', 'store-messages' ),
				'order_total'      => __( 'Order total', 'store-messages' ),
				'order_items'      => __( 'Table of items with images (HTML)', 'store-messages' ),
				'shipping_address' => __( 'Shipping address (HTML)', 'store-messages' ),
				'billing_address'  => __( 'Billing address (HTML)', 'store-messages' ),
				'shipping_method'  => __( 'Shipping method', 'store-messages' ),
				'order_url'        => __( 'Order page in My account', 'store-messages' ),
				'payment_url'      => __( 'Payment page for unpaid orders', 'store-messages' ),
				'refund_amount'    => __( 'Total refunded', 'store-messages' ),
				'refund_reason'    => __( 'Reason given for the latest refund', 'store-messages' ),
			],
			__( 'Shipping', 'store-messages' ) => [
				'carrier'         => __( 'Carrier name', 'store-messages' ),
				'tracking_number' => __( 'Tracking number', 'store-messages' ),
				'tracking_url'    => __( 'Tracking link', 'store-messages' ),
			],
			__( 'Written when sending', 'store-messages' ) => [
				'message'      => __( 'Your personal message from the order screen (HTML)', 'store-messages' ),
				'note'         => __( 'The note text, in the "Note to customer" template (HTML)', 'store-messages' ),
				'note_excerpt' => __( 'First line of the note', 'store-messages' ),
			],
			__( 'Store', 'store-messages' ) => [
				'site_name'     => __( 'Store name', 'store-messages' ),
				'site_url'      => __( 'Home page', 'store-messages' ),
				'shop_url'      => __( 'Shop page', 'store-messages' ),
				'account_url'   => __( 'My account page', 'store-messages' ),
				'store_email'   => __( 'Store email address', 'store-messages' ),
				'store_address' => __( 'Store address', 'store-messages' ),
				'review_url'    => __( 'Review link (Settings tab)', 'store-messages' ),
				'accent'        => __( 'Accent colour', 'store-messages' ),
				'accent_text'   => __( 'Text colour that reads on the accent', 'store-messages' ),
				'year'          => __( 'Current year', 'store-messages' ),
			],
		];
	}

	/**
	 * Every value for an order. $extra adds or overrides values:
	 * tracking (from Carriers::resolve), message, note.
	 */
	public static function context( $order = null, array $extra = [] ) {
		$brand = stms_brand();
		$ctx   = [
			'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'      => home_url( '/' ),
			'shop_url'      => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
			'account_url'   => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ),
			'store_email'   => stms_from_email(),
			'store_address' => stms_store_address(),
			'review_url'    => (string) stms_settings()['review_url'],
			'accent'        => $brand['accent'],
			'accent_text'   => stms_contrast( $brand['accent'] ),
			'year'          => wp_date( 'Y' ),
		];
		if ( $order instanceof \WC_Order ) {
			$refunded = (float) $order->get_total_refunded();
			$refunds  = $order->get_refunds();
			$ctx     += [
				'first_name'       => $order->get_billing_first_name() ?: $order->get_shipping_first_name(),
				'last_name'        => $order->get_billing_last_name() ?: $order->get_shipping_last_name(),
				'customer_name'    => trim( $order->get_formatted_billing_full_name() ) ?: trim( $order->get_formatted_shipping_full_name() ),
				'customer_email'   => $order->get_billing_email(),
				'order_number'     => $order->get_order_number(),
				'order_date'       => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
				'order_status'     => wc_get_order_status_name( $order->get_status() ),
				'order_total'      => wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total(), ENT_QUOTES ) ),
				'order_items'      => self::items_table( $order ),
				'shipping_address' => $order->get_formatted_shipping_address(),
				'billing_address'  => $order->get_formatted_billing_address(),
				'shipping_method'  => $order->get_shipping_method(),
				'order_url'        => $order->get_view_order_url(),
				'payment_url'      => $order->get_checkout_payment_url(),
				'refund_amount'    => $refunded > 0 ? wp_strip_all_tags( html_entity_decode( wc_price( $refunded, [ 'currency' => $order->get_currency() ] ), ENT_QUOTES ) ) : '',
				'refund_reason'    => $refunds ? (string) $refunds[0]->get_reason() : '',
			];
			$saved = Tracking::get( $order );
			if ( $saved && empty( $extra['tracking'] ) ) {
				$extra['tracking'] = $saved;
			}
		}
		$tracking = (array) ( $extra['tracking'] ?? [] );
		$ctx['carrier']         = (string) ( $tracking['name'] ?? '' );
		$ctx['tracking_number'] = (string) ( $tracking['number'] ?? '' );
		$ctx['tracking_url']    = (string) ( $tracking['url'] ?? '' );
		$ctx['message']         = self::format_text( $extra['message'] ?? '' );
		$ctx['note']            = self::format_text( $extra['note'] ?? '' );
		$ctx['note_excerpt']    = wp_html_excerpt( wp_strip_all_tags( (string) ( $extra['note'] ?? '' ) ), 110, '…' );

		/**
		 * Add or change placeholder values. Keys are placeholder names without braces.
		 *
		 * @param array          $ctx   Values.
		 * @param \WC_Order|null $order The order, if any.
		 * @param array          $extra Values supplied when sending.
		 */
		return (array) apply_filters( 'stms_placeholders', $ctx, $order, $extra );
	}

	/** Sample values for previews when the store has no orders yet. */
	public static function sample_context( array $extra = [] ) {
		$ctx = self::context( null, $extra + [ 'tracking' => Carriers::resolve( '99.60.119884.01390957', array_key_first( Carriers::all() ) ?? '' ) ] );
		return array_merge( $ctx, [
			'first_name'       => 'Alex',
			'last_name'        => 'Martin',
			'customer_name'    => 'Alex Martin',
			'customer_email'   => 'alex@example.com',
			'order_number'     => '1042',
			'order_date'       => wc_format_datetime( wc_string_to_datetime( 'now' ) ),
			'order_status'     => __( 'Completed', 'woocommerce' ),
			'order_total'      => wp_strip_all_tags( html_entity_decode( wc_price( 89.5 ), ENT_QUOTES ) ),
			'order_items'      => self::items_table( null ),
			'shipping_address' => 'Alex Martin<br>Bahnhofstrasse 1<br>8001 Zürich',
			'billing_address'  => 'Alex Martin<br>Bahnhofstrasse 1<br>8001 Zürich',
			'shipping_method'  => __( 'Standard shipping', 'store-messages' ),
			'order_url'        => $ctx['account_url'],
			'payment_url'      => $ctx['account_url'],
			'refund_amount'    => wp_strip_all_tags( html_entity_decode( wc_price( 12 ), ENT_QUOTES ) ),
			'refund_reason'    => __( 'Item out of stock', 'store-messages' ),
		] );
	}

	/** Plain text → paragraphs with clickable links; simple HTML is kept. */
	public static function format_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$html = wpautop( make_clickable( wp_kses_post( $text ) ) );
		return str_replace( '<p>', '<p style="margin:0 0 14px">', $html );
	}

	/** Items as an email-safe table: image, name × quantity, line total. */
	public static function items_table( $order ) {
		$rows = [];
		if ( $order instanceof \WC_Order ) {
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$image   = $product ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : '';
				$meta    = wp_strip_all_tags( wc_display_item_meta( $item, [ 'echo' => false, 'separator' => ', ', 'before' => '', 'after' => '', 'label_before' => '', 'label_after' => ': ' ] ) );
				$rows[]  = [ $image, $item->get_name(), $meta, $item->get_quantity(), wp_strip_all_tags( html_entity_decode( $order->get_formatted_line_subtotal( $item ), ENT_QUOTES ) ) ];
			}
		} else {
			$rows = [
				[ '', __( 'Sample product', 'store-messages' ), '', 2, wp_strip_all_tags( html_entity_decode( wc_price( 59 ), ENT_QUOTES ) ) ],
				[ '', __( 'Another product', 'store-messages' ), __( 'Colour: Brown', 'store-messages' ), 1, wp_strip_all_tags( html_entity_decode( wc_price( 30.5 ), ENT_QUOTES ) ) ],
			];
		}
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 8px">';
		foreach ( $rows as [ $image, $name, $meta, $qty, $total ] ) {
			$html .= '<tr><td width="56" style="padding:10px 12px 10px 0;border-top:1px solid #f0f0f1;vertical-align:middle">'
				. ( $image ? '<img src="' . esc_url( $image ) . '" width="48" height="48" alt="" style="display:block;width:48px;height:48px;border-radius:8px;object-fit:cover;border:0">' : '<div style="width:48px;height:48px;border-radius:8px;background:#f4f4f5"></div>' )
				. '</td><td style="padding:10px 0;border-top:1px solid #f0f0f1;vertical-align:middle;font-size:14px">' . esc_html( $name )
				. ( $meta ? '<br><span style="font-size:12px;color:#71717a">' . esc_html( $meta ) . '</span>' : '' )
				. '<br><span style="font-size:12px;color:#71717a">× ' . (int) $qty . '</span></td>'
				. '<td align="right" style="padding:10px 0 10px 12px;border-top:1px solid #f0f0f1;vertical-align:middle;font-size:14px;white-space:nowrap">' . esc_html( $total ) . '</td></tr>';
		}
		return $html . '</table>';
	}

	/** Resolve {if}…{else}…{/if} blocks, innermost first. */
	public static function conditionals( $text, array $ctx ) {
		$pattern = '/\{if\s+(!?)([a-z0-9_]+)\s*\}((?:(?!\{if\s).)*?)(?:\{else\}((?:(?!\{if\s).)*?))?\{\/if\}/s';
		for ( $i = 0; $i < 50 && preg_match( $pattern, $text ); $i++ ) {
			$text = preg_replace_callback( $pattern, static function ( $m ) use ( $ctx ) {
				$set = isset( $ctx[ $m[2] ] ) && '' !== trim( (string) $ctx[ $m[2] ] );
				$yes = '!' === $m[1] ? ! $set : $set;
				return $yes ? $m[3] : ( $m[4] ?? '' );
			}, $text );
		}
		return $text;
	}

	/** Replace {placeholders}; unknown ones are left as typed so mistakes are visible in previews. */
	public static function placeholders( $text, array $ctx, $plain = false ) {
		return preg_replace_callback( '/\{([a-z0-9_]+)\}/', static function ( $m ) use ( $ctx, $plain ) {
			if ( ! array_key_exists( $m[1], $ctx ) ) {
				return $m[0];
			}
			$value = (string) $ctx[ $m[1] ];
			if ( $plain ) {
				return wp_strip_all_tags( $value );
			}
			if ( in_array( $m[1], self::HTML, true ) ) {
				return $value;
			}
			if ( in_array( $m[1], self::URLS, true ) ) {
				return esc_url( $value );
			}
			return esc_html( $value );
		}, $text );
	}

	public static function text( $template, array $ctx, $plain = false ) {
		return self::placeholders( self::conditionals( (string) $template, $ctx ), $ctx, $plain );
	}

	/**
	 * Build the email: [subject, html, text].
	 *
	 * @param array      $tpl    Template (subject, heading, preheader, body).
	 * @param array      $ctx    Values from context() or sample_context().
	 * @param string|null $layout Layout HTML; defaults to the saved layout.
	 */
	public static function render( array $tpl, array $ctx, $layout = null ) {
		$brand   = stms_brand();
		$subject = html_entity_decode( self::text( $tpl['subject'] ?? '', $ctx, true ), ENT_QUOTES, 'UTF-8' );
		$content = self::text( $tpl['body'] ?? '', $ctx );
		$footer  = '' !== trim( (string) $brand['footer'] ) ? $brand['footer'] : Templates::default_footer();
		$wrap    = $ctx + [
			'subject'    => $subject,
			'heading'    => html_entity_decode( self::text( $tpl['heading'] ?? '', $ctx, true ), ENT_QUOTES, 'UTF-8' ),
			'preheader'  => html_entity_decode( self::text( $tpl['preheader'] ?? '', $ctx, true ), ENT_QUOTES, 'UTF-8' ),
			'content'    => $content,
			'footer'     => self::text( $footer, $ctx ),
			'logo'       => $brand['logo'],
			'logo_width' => (int) $brand['logo_width'] ?: 160,
			'background' => $brand['background'],
			'surface'    => $brand['surface'],
			'text'       => $brand['text'],
			'lang'       => substr( get_locale(), 0, 2 ),
		];
		$layout = null === $layout ? Templates::layout() : (string) $layout;
		if ( false === strpos( $layout, '{content}' ) ) {
			$layout .= '{content}'; // a layout without {content} must not swallow the message
		}
		$html = self::text( $layout, $wrap );
		return [
			'subject' => $subject,
			'html'    => (string) apply_filters( 'stms_email_html', $html, $tpl, $ctx ),
			'text'    => self::plain_text( $wrap['heading'], $content, $wrap['footer'] ),
		];
	}

	/** Plain-text alternative (better deliverability, and readable in text-only clients). */
	public static function plain_text( $heading, $content, $footer ) {
		$html = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $content . '<br><br>' . $footer );
		$html = preg_replace( '#</td>#i', '  ', $html );
		$html = preg_replace( '#<(br|/p|/tr|/h[1-6]|/div|/table)[^>]*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n\s*\n\s*(\n\s*)+/", "\n\n", $text );
		return trim( ( $heading ? $heading . "\n\n" : '' ) . trim( preg_replace( '/^ +/m', '', $text ) ) );
	}
}
