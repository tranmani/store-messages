<?php
/**
 * Message templates and the shared email layout.
 *
 * A template is a subject, a heading, a short preview line and an HTML body.
 * The layout is a complete HTML document that wraps every message; {content}
 * marks where the template's body goes. Both are plain HTML the store edits
 * on the Templates and Layout tabs.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Templates {

	const OPTION = 'stms_templates';

	/** Built-in templates. Edited copies are stored in the option; "Reset" restores these. */
	public static function defaults() {
		$p      = static fn( $text, $style = 'margin:0 0 16px' ) => '<p style="' . $style . '">' . $text . "</p>\n";
		$hello  = $p( __( 'Hi {first_name},', 'store-messages' ) );
		$sign   = "\n" . $p( __( 'Thank you for shopping with us,', 'store-messages' ) . '<br>{site_name}', 'margin:24px 0 0' );
		$button = static fn( $url, $label, $margin = '8px 0 24px' ) => <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:$margin">
  <tr>
    <td style="border-radius:8px;background:{accent}">
      <a href="$url" style="display:inline-block;padding:13px 26px;font-weight:600;color:{accent_text};text-decoration:none;border-radius:8px">$label</a>
    </td>
  </tr>
</table>

HTML;
		$message = "\n{if message}\n<div style=\"margin:0 0 20px\">{message}</div>\n{/if}\n";
		$card    = static fn( $label, $inner, $margin = '0 0 20px' ) => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:' . $margin . ";border:1px solid #e4e4e7;border-radius:12px\">\n  <tr>\n    <td style=\"padding:18px 20px\">\n"
			. '      <p style="margin:0 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#71717a">' . $label . "</p>\n"
			. preg_replace( '/^(?=.)/m', '      ', $inner )
			. "    </td>\n  </tr>\n</table>\n";
		$tracking = "\n{if tracking_url}\n" . $card(
			__( 'Tracking', 'store-messages' ),
			'<p style="margin:0 0 14px;font-size:15px"><strong>{carrier}</strong>{if tracking_number} · {tracking_number}{/if}</p>' . "\n" . $button( '{tracking_url}', __( 'Track your package', 'store-messages' ), '0' ),
			'0 0 8px'
		) . "{/if}\n";
		$items = "\n" . '<h3 style="margin:28px 0 8px;font-size:15px">' . __( 'Your order', 'store-messages' ) . "</h3>\n{order_items}\n";

		return [
			'shipped' => [
				'name'        => __( 'Order shipped (tracking)', 'store-messages' ),
				'description' => __( 'Your order is on its way, with the tracking link.', 'store-messages' ),
				'subject'     => __( 'Your order #{order_number} is on its way', 'store-messages' ),
				'heading'     => __( 'Your order is on its way', 'store-messages' ),
				'preheader'   => __( 'Track your package: {carrier} {tracking_number}', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'Good news: your order <strong>#{order_number}</strong> has been shipped and is on its way to you.', 'store-messages' ), 'margin:0 0 20px' )
					. $tracking
					. $p( __( 'Tracking can take up to 24 hours to show the first update.', 'store-messages' ), 'margin:16px 0 20px;font-size:13px;color:#71717a' )
					. $message
					. "\n" . '<h3 style="margin:28px 0 8px;font-size:15px">' . __( 'In this parcel', 'store-messages' ) . "</h3>\n{order_items}\n"
					. "\n{if shipping_address}\n" . $p( __( 'Shipping to', 'store-messages' ) . '<br>{shipping_address}', 'margin:16px 0 0;font-size:13px;color:#71717a' ) . "{/if}\n"
					. $sign,
			],
			'ready_pickup' => [
				'name'        => __( 'Ready for pickup', 'store-messages' ),
				'description' => __( 'The order can be collected from your store.', 'store-messages' ),
				'subject'     => __( 'Your order #{order_number} is ready for pickup', 'store-messages' ),
				'heading'     => __( 'Ready for pickup', 'store-messages' ),
				'preheader'   => __( 'Your order is waiting for you at {site_name}.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'Your order <strong>#{order_number}</strong> is packed and ready for you to collect.', 'store-messages' ), 'margin:0 0 20px' )
					. $message
					. "\n{if store_address}\n" . $card( __( 'Pickup address', 'store-messages' ), '<p style="margin:0">{store_address}</p>' . "\n" ) . "{/if}\n\n"
					. $p( __( 'Please bring your order number with you.', 'store-messages' ), 'margin:0 0 20px' )
					. $items
					. $sign,
			],
			'item_unavailable' => [
				'name'        => __( 'Item unavailable', 'store-messages' ),
				'description' => __( 'Something in the order is out of stock; explain what happens next.', 'store-messages' ),
				'subject'     => __( 'An update about your order #{order_number}', 'store-messages' ),
				'heading'     => __( 'An update about your order', 'store-messages' ),
				'preheader'   => __( 'One item in your order is unavailable.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'Thank you for your order <strong>#{order_number}</strong>. Unfortunately, one of the items you ordered is currently out of stock and we are unable to send it.', 'store-messages' ) )
					. "\n{if message}\n<div style=\"margin:0 0 16px\">{message}</div>\n{else}\n"
					. $p( __( 'We have refunded that item to your original payment method. The rest of your order is being prepared as usual.', 'store-messages' ) )
					. "{/if}\n\n{if refund_amount}\n"
					. $p( __( 'Refunded: <strong>{refund_amount}</strong>. It can take a few business days to appear on your statement.', 'store-messages' ) )
					. "{/if}\n\n"
					. $p( __( 'We are very sorry for the inconvenience. Simply reply to this email if you have any questions.', 'store-messages' ) )
					. $sign,
			],
			'refund' => [
				'name'        => __( 'Refund issued', 'store-messages' ),
				'description' => __( 'Confirms a full or partial refund, with the amount.', 'store-messages' ),
				'subject'     => __( 'Your refund for order #{order_number}', 'store-messages' ),
				'heading'     => __( 'Your refund is on its way', 'store-messages' ),
				'preheader'   => __( 'Refund of {refund_amount} for order #{order_number}.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'We have processed a refund of <strong>{refund_amount}</strong> for your order <strong>#{order_number}</strong>.', 'store-messages' ) )
					. "\n{if refund_reason}\n" . $p( __( 'Reason: {refund_reason}', 'store-messages' ) ) . "{/if}\n"
					. $message
					. "\n" . $p( __( 'Depending on your bank, it can take a few business days for the money to reach your account.', 'store-messages' ) )
					. $sign,
			],
			'delayed' => [
				'name'        => __( 'Order delayed', 'store-messages' ),
				'description' => __( 'Let the customer know the order needs a little more time.', 'store-messages' ),
				'subject'     => __( 'Your order #{order_number} needs a little more time', 'store-messages' ),
				'heading'     => __( 'A short delay', 'store-messages' ),
				'preheader'   => __( 'An update on when your order will ship.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'We wanted to let you know that your order <strong>#{order_number}</strong> is taking a little longer than usual to prepare.', 'store-messages' ) )
					. $message
					. "\n" . $p( __( 'We will send you the tracking link as soon as it ships. Thank you for your patience!', 'store-messages' ) )
					. $sign,
			],
			'payment_reminder' => [
				'name'        => __( 'Payment reminder', 'store-messages' ),
				'description' => __( 'For pending orders: a link to finish paying.', 'store-messages' ),
				'subject'     => __( 'Complete your order #{order_number}', 'store-messages' ),
				'heading'     => __( 'Your order is waiting', 'store-messages' ),
				'preheader'   => __( 'Finish your payment to confirm order #{order_number}.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'Your order <strong>#{order_number}</strong> ({order_total}) is not paid yet. You can complete the payment securely with the button below.', 'store-messages' ) )
					. $message
					. "\n" . $button( '{payment_url}', __( 'Complete payment', 'store-messages' ) )
					. $items
					. $sign,
			],
			'review_request' => [
				'name'        => __( 'Review request', 'store-messages' ),
				'description' => __( 'Ask a happy customer for a review.', 'store-messages' ),
				'subject'     => __( 'How are you enjoying your order, {first_name}?', 'store-messages' ),
				'heading'     => __( 'How did we do?', 'store-messages' ),
				'preheader'   => __( 'Your feedback helps other customers.', 'store-messages' ),
				'body'        => $hello
					. $p( __( 'We hope you are enjoying your order. Would you take a minute to tell other customers what you think? It means a lot to a small business like ours.', 'store-messages' ) )
					. $message
					. "\n{if review_url}\n" . $button( '{review_url}', __( 'Leave a review', 'store-messages' ) ) . "{/if}\n"
					. $items
					. $sign,
			],
			'custom' => [
				'name'        => __( 'Personal message', 'store-messages' ),
				'description' => __( 'A blank message: just write what you want to say.', 'store-messages' ),
				'subject'     => __( 'A message about your order #{order_number}', 'store-messages' ),
				'heading'     => __( 'A message from {site_name}', 'store-messages' ),
				'preheader'   => '',
				'body'        => $hello . "\n{message}\n" . $sign,
			],
			'customer_note' => [
				'name'        => __( 'Note to customer', 'store-messages' ),
				'description' => __( 'Used when you add a "Note to customer" on the order screen. A tracking link in the note becomes a button.', 'store-messages' ),
				'subject'     => __( 'A note about your order #{order_number}', 'store-messages' ),
				'heading'     => '{if tracking_url}' . __( 'Your order is on its way', 'store-messages' ) . '{else}' . __( 'A note about your order', 'store-messages' ) . '{/if}',
				'preheader'   => '{note_excerpt}',
				'body'        => "<div style=\"margin:0 0 20px\">{note}</div>\n"
					. str_replace( 'margin:0 0 8px;', 'margin:0 0 20px;', $tracking )
					. "\n" . $p( __( 'Order #{order_number} · placed {order_date}', 'store-messages' ), 'margin:0;font-size:13px;color:#71717a' ),
				'system'      => true,
			],
			'welcome' => [
				'name'        => __( 'Welcome (new subscriber)', 'store-messages' ),
				'description' => __( 'Sent automatically to new subscribers, with their personal code.', 'store-messages' ),
				'subject'     => __( 'Welcome to {site_name}{if coupon_code}: your {coupon_amount} code{/if}', 'store-messages' ),
				'heading'     => __( 'Thanks for signing up!', 'store-messages' ),
				'preheader'   => '{if coupon_code}' . __( 'Your personal code for {coupon_amount} off your first order is inside.', 'store-messages' ) . '{else}' . __( 'Good to have you with us.', 'store-messages' ) . '{/if}',
				'body'        => $p( '{if first_name}' . __( 'Hi {first_name},', 'store-messages' ) . '{else}' . __( 'Hello,', 'store-messages' ) . '{/if}' )
					. $p( __( 'Welcome to {site_name}! From now on you are the first to hear about new products and offers.', 'store-messages' ), 'margin:0 0 20px' )
					. "\n{if coupon_code}\n" . $card(
						__( 'Your personal code', 'store-messages' ),
						'<p style="margin:4px 0 6px;font-size:26px;line-height:1.2;font-weight:700;letter-spacing:.04em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace">{coupon_code}</p>' . "\n"
						. '<p style="margin:0 0 16px;font-size:14px;color:#71717a">' . __( '{coupon_amount} off your first order{if coupon_expires}, valid until {coupon_expires}{/if}. Enter it at checkout.', 'store-messages' ) . "</p>\n"
						. $button( '{shop_url}', __( 'Start shopping', 'store-messages' ), '0' )
					) . "{/if}\n"
					. "\n{if !coupon_code}\n" . $button( '{shop_url}', __( 'Start shopping', 'store-messages' ) ) . "{/if}\n"
					. "\n" . '<h3 style="margin:28px 0 12px;font-size:15px">' . __( 'Our bestsellers', 'store-messages' ) . "</h3>\n{bestsellers}\n"
					. $sign,
				'system'      => true,
				'marketing'   => true,
			],
			'confirm_subscription' => [
				'name'        => __( 'Confirm subscription', 'store-messages' ),
				'description' => __( 'Sent when double opt-in is on: the link that confirms the sign-up.', 'store-messages' ),
				'subject'     => __( 'Please confirm your subscription to {site_name}', 'store-messages' ),
				'heading'     => __( 'One more step', 'store-messages' ),
				'preheader'   => __( 'Confirm your email address to start receiving our news.', 'store-messages' ),
				'body'        => $p( '{if first_name}' . __( 'Hi {first_name},', 'store-messages' ) . '{else}' . __( 'Hello,', 'store-messages' ) . '{/if}' )
					. $p( __( 'Please confirm that you want to receive emails from {site_name}.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{confirm_url}', __( 'Yes, subscribe me', 'store-messages' ) )
					. $p( __( 'If you did not sign up, simply ignore this email: you will not hear from us.', 'store-messages' ), 'margin:0;font-size:13px;color:#71717a' ),
				'system'      => true,
				'marketing'   => true,
			],
		];
	}

	/** All templates: saved ones (in their saved order) plus any built-in not yet saved. */
	public static function all() {
		$saved    = get_option( self::OPTION, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$defaults = self::defaults();
		$out      = [];
		foreach ( $saved as $id => $tpl ) {
			if ( ! empty( $tpl['deleted'] ) ) {
				continue;
			}
			$out[ $id ] = array_merge( [ 'description' => '', 'preheader' => '' ], $defaults[ $id ] ?? [], $tpl, [ 'builtin' => isset( $defaults[ $id ] ) ] );
		}
		foreach ( $defaults as $id => $tpl ) {
			if ( ! isset( $out[ $id ] ) && empty( $saved[ $id ]['deleted'] ) ) {
				$out[ $id ] = $tpl + [ 'builtin' => true ];
			}
		}
		return $out;
	}

	public static function get( $id ) {
		return self::all()[ $id ] ?? null;
	}

	/** Templates offered on the order screen (everything except the note template). */
	public static function for_orders() {
		return array_filter( self::all(), static fn( $t ) => empty( $t['system'] ) );
	}

	public static function save( $id, array $data ) {
		$saved        = get_option( self::OPTION, [] );
		$saved        = is_array( $saved ) ? $saved : [];
		$saved[ $id ] = array_intersect_key( $data, array_flip( [ 'name', 'description', 'subject', 'heading', 'preheader', 'body' ] ) );
		update_option( self::OPTION, $saved, false );
	}

	public static function delete( $id ) {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];
		if ( isset( self::defaults()[ $id ] ) ) {
			if ( ! empty( self::defaults()[ $id ]['system'] ) ) {
				return false;
			}
			$saved[ $id ] = [ 'deleted' => 1 ]; // keeps a deleted built-in from coming back
		} else {
			unset( $saved[ $id ] );
		}
		update_option( self::OPTION, $saved, false );
		return true;
	}

	/** Restore a built-in template's original text. */
	public static function reset( $id ) {
		$saved = get_option( self::OPTION, [] );
		unset( $saved[ $id ] );
		update_option( self::OPTION, $saved, false );
	}

	/** Restore every deleted built-in template. */
	public static function restore_deleted() {
		$saved = get_option( self::OPTION, [] );
		$saved = array_filter( (array) $saved, static fn( $t ) => empty( $t['deleted'] ) );
		update_option( self::OPTION, $saved, false );
	}

	public static function new_id( $name ) {
		$base = sanitize_key( str_replace( ' ', '_', remove_accents( (string) $name ) ) ) ?: 'message';
		$id   = $base;
		$i    = 2;
		while ( isset( self::all()[ $id ] ) || isset( self::defaults()[ $id ] ) ) {
			$id = $base . '_' . $i++;
		}
		return $id;
	}

	/** Does this template use tracking details (so the order screen asks for them)? */
	public static function uses_tracking( array $tpl ) {
		return (bool) preg_match( '/\{(?:if !?)?(tracking_url|tracking_number|carrier)\b/', $tpl['subject'] . $tpl['heading'] . $tpl['preheader'] . $tpl['body'] );
	}

	public static function uses_message( array $tpl ) {
		return false !== strpos( $tpl['body'], 'message}' );
	}

	/**
	 * The default layout: a centred card on a soft background, logo on top,
	 * table-based with inline styles so it renders in Outlook, Gmail and Apple Mail.
	 */
	public static function default_layout() {
		return <<<'HTML'
<!DOCTYPE html>
<html lang="{lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{subject}</title>
<style>
  body { margin:0; padding:0; background:{background}; }
  a { color:{accent}; }
  @media (max-width: 620px) {
    .stms-card { padding:28px 22px !important; }
    .stms-outer { padding:16px 10px !important; }
    h1 { font-size:22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:{background};">
{if preheader}<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">{preheader}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>{/if}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{background}">
  <tr>
    <td class="stms-outer" align="center" style="padding:36px 16px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">
        <tr>
          <td align="center" style="padding:0 0 24px">
            {if logo}<a href="{site_url}" style="text-decoration:none"><img src="{logo}" width="{logo_width}" alt="{site_name}" style="display:block;width:{logo_width}px;max-width:100%;height:auto;border:0"></a>{else}<a href="{site_url}" style="font:700 22px/1.2 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{text};text-decoration:none">{site_name}</a>{/if}
          </td>
        </tr>
        <tr>
          <td class="stms-card" style="background:{surface};border-radius:16px;padding:40px 44px;font:400 15px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{text}">
            <div style="width:40px;height:4px;border-radius:4px;background:{accent};margin:0 0 22px"></div>
            {if heading}<h1 style="margin:0 0 20px;font-size:24px;line-height:1.3;font-weight:700;color:{text}">{heading}</h1>{/if}
            {content}
          </td>
        </tr>
        <tr>
          <td align="center" style="padding:24px 12px 0;font:400 12px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#71717a">
            {footer}
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
	}

	public static function default_footer() {
		return __( 'Questions? Just reply to this email.', 'store-messages' ) . '<br><a href="{site_url}" style="color:#71717a">{site_name}</a>{if store_address} · {store_address}{/if}';
	}

	public static function layout() {
		$layout = (string) stms_settings()['layout'];
		return '' !== trim( $layout ) ? $layout : self::default_layout();
	}
}
