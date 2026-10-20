<?php
/**
 * Marketing email to subscribers: the welcome email, the confirmation email
 * for double opt-in, and campaigns (see Campaigns for the sending queue).
 *
 * Every marketing email has an unsubscribe link in the footer and
 * List-Unsubscribe headers, so email apps show their own "Unsubscribe" button.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Marketing {

	const WELCOME_HOOK = 'stms_send_welcome';
	const GROUP        = 'store-messages';

	public static function init() {
		add_action( 'stms_subscribed', [ __CLASS__, 'schedule_welcome' ] );
		add_action( self::WELCOME_HOOK, [ __CLASS__, 'send_welcome' ] );
	}

	/* --------------------------------------------------------------- links -- */

	/** Confirm and unsubscribe links go through admin-post.php: never cached by page caches or CDNs. */
	public static function link( $sub, $do ) {
		return add_query_arg( [ 'action' => 'stms_subscription', 'do' => $do, 't' => $sub->token ], admin_url( 'admin-post.php' ) );
	}

	/* ------------------------------------------------------------- context -- */

	/** Does any part of a template use one of these placeholders? */
	private static function uses( array $tpl, $needle ) {
		return false !== strpos( ( $tpl['subject'] ?? '' ) . ( $tpl['heading'] ?? '' ) . ( $tpl['preheader'] ?? '' ) . ( $tpl['body'] ?? '' ), $needle );
	}

	/**
	 * Placeholder values for a subscriber. A personal coupon is created only
	 * when the template shows one (and never for tests).
	 */
	public static function context( $sub, array $tpl, $test = false ) {
		$ctx = Renderer::context();
		$ctx = array_merge( $ctx, [
			'first_name'      => (string) $sub->first_name,
			'last_name'       => (string) $sub->last_name,
			'customer_name'   => trim( $sub->first_name . ' ' . $sub->last_name ),
			'customer_email'  => (string) $sub->email,
			'unsubscribe_url' => self::link( $sub, 'unsubscribe' ),
			'confirm_url'     => self::link( $sub, 'confirm' ),
			'coupon_code'     => '',
			'coupon_amount'   => '',
			'coupon_expires'  => '',
		] );
		if ( self::uses( $tpl, '{coupon_' ) || self::uses( $tpl, 'coupon_code' ) ) {
			$code = $test ? 'ab3k-7xq2mz-p9tw' : Coupons::issue( $sub );
			if ( '' !== $code ) {
				$ctx['coupon_code']    = $code;
				$ctx['coupon_amount']  = Coupons::amount_text();
				$ctx['coupon_expires'] = Coupons::expiry_text( $test ? '' : $code );
			}
		}
		/**
		 * Placeholder values for a marketing email.
		 *
		 * @param array  $ctx Values.
		 * @param object $sub Subscriber row.
		 * @param array  $tpl Template or campaign.
		 */
		return (array) apply_filters( 'stms_marketing_placeholders', $ctx, $sub, $tpl );
	}

	/** A pretend subscriber for previews and test sends. */
	public static function sample_subscriber( $email = '' ) {
		return (object) [
			'id'         => 0,
			'email'      => $email ?: 'alex@example.com',
			'first_name' => 'Alex',
			'last_name'  => 'Martin',
			'lang'       => '',
			'token'      => 'preview0000000000000000000000000',
			'coupon'     => '',
			'status'     => Subscribers::SUBSCRIBED,
		];
	}

	/** Render a template or campaign for a subscriber, in their language: [subject, html, text]. */
	public static function render( $sub, array $tpl, $test = false ) {
		$lang = (string) ( $sub->lang ?? '' );
		return Language::run( $lang, static function () use ( $sub, $tpl, $test, $lang ) {
			$tpl = array_merge( $tpl, [
				'subject'   => Language::translate_text( (string) ( $tpl['subject'] ?? '' ), $lang ),
				'heading'   => Language::translate_text( (string) ( $tpl['heading'] ?? '' ), $lang ),
				'preheader' => Language::translate_text( (string) ( $tpl['preheader'] ?? '' ), $lang ),
			] );
			$mail         = Renderer::render( $tpl, self::context( $sub, $tpl, $test ) );
			$mail['html'] = Language::translate_html( $mail['html'], $lang );
			return $mail;
		} );
	}

	/**
	 * Send a template or campaign to a subscriber.
	 *
	 * @param object $sub  Subscriber row.
	 * @param array  $tpl  subject, heading, preheader, body.
	 * @param array  $args test (bool), to (string, test sends only).
	 * @return true|\WP_Error
	 */
	public static function send( $sub, array $tpl, array $args = [] ) {
		$test = ! empty( $args['test'] );
		$to   = $test ? sanitize_email( $args['to'] ?? '' ) : $sub->email;
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'stms_to', __( 'Enter a valid email address.', 'store-messages' ) );
		}
		$mail = self::render( $sub, $tpl, $test );
		if ( $test ) {
			/* translators: %s: email subject */
			$mail['subject'] = sprintf( __( '[Test] %s', 'store-messages' ), $mail['subject'] );
		}
		$headers = Mailer::headers();
		if ( ! $test && ! empty( $sub->token ) ) {
			$headers[] = 'List-Unsubscribe: <' . self::link( $sub, 'unsubscribe' ) . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}
		$sent = Mailer::deliver( $to, $mail, (array) apply_filters( 'stms_marketing_headers', $headers, $sub, $tpl ) );
		return $sent ? true : new \WP_Error( 'stms_send', Mailer::last_error() );
	}

	/* ------------------------------------------------------------- welcome -- */

	public static function schedule_welcome( $sub ) {
		$s = stms_settings()['signup'];
		if ( empty( $s['welcome'] ) || ! empty( $sub->welcomed_at ) || Subscribers::SUBSCRIBED !== $sub->status ) {
			return;
		}
		$when = time() + max( 0, (int) $s['welcome_delay'] ) * MINUTE_IN_SECONDS;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::WELCOME_HOOK, [ (int) $sub->id ], self::GROUP );
		} else {
			wp_schedule_single_event( $when, self::WELCOME_HOOK, [ (int) $sub->id ] );
		}
	}

	public static function send_welcome( $id ) {
		$sub = Subscribers::get( (int) $id );
		$tpl = Templates::get( 'welcome' );
		if ( ! $sub || ! $tpl || Subscribers::SUBSCRIBED !== $sub->status || ! empty( $sub->welcomed_at ) ) {
			return;
		}
		// Marked first: a failed send is not retried into a double welcome.
		Subscribers::update( $sub->id, [ 'welcomed_at' => Database::now() ] );
		$result = self::send( $sub, $tpl );
		self::log( $sub, $tpl, $result );
	}

	public static function send_confirmation( $sub ) {
		$tpl = Templates::get( 'confirm_subscription' );
		if ( $tpl ) {
			self::log( $sub, $tpl, self::send( $sub, $tpl ) );
		}
	}

	/** Welcome and confirmation emails appear on the Activity tab. */
	private static function log( $sub, array $tpl, $result ) {
		$activity = (array) get_option( Mailer::ACTIVITY, [] );
		array_unshift( $activity, [
			'time'     => time(),
			'user'     => 0,
			'template' => $tpl['id'] ?? '',
			'name'     => $tpl['name'] ?? '',
			'subject'  => wp_strip_all_tags( Renderer::text( $tpl['subject'] ?? '', [ 'site_name' => get_bloginfo( 'name' ), 'first_name' => $sub->first_name ], true ) ),
			'to'       => $sub->email,
			'ok'       => true === $result,
			'error'    => is_wp_error( $result ) ? $result->get_error_message() : '',
			'tracking' => '',
			'order'    => 0,
			'number'   => '',
		] );
		update_option( Mailer::ACTIVITY, array_slice( $activity, 0, 200 ), false );
	}
}
