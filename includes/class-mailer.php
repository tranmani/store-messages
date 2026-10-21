<?php
/**
 * Sends messages and keeps a record of them.
 *
 * Every message goes through wp_mail(), so whatever SMTP or delivery plugin the
 * site uses applies. Each send is written to the order (a private note and the
 * order's message history) and to the store-wide Activity list.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Mailer {

	const LOG      = '_stms_log';
	const ACTIVITY = 'stms_activity';

	/** True while a message is marking its order Completed (see WooCommerce::maybe_skip_completed). */
	public static $completing = false;

	private static $alt_body   = '';
	private static $last_error = '';

	/**
	 * Send a template for an order.
	 *
	 * @param \WC_Order|null $order    The order (null for test sends with sample data).
	 * @param string         $template Template id.
	 * @param array          $args     to, tracking (array), message, note, complete (bool), test (bool).
	 * @return true|\WP_Error
	 */
	public static function send( $order, $template, array $args = [] ) {
		$test = ! empty( $args['test'] );
		$to   = sanitize_email( $args['to'] ?? ( $order ? $order->get_billing_email() : '' ) );
		$extra = array_intersect_key( $args, array_flip( [ 'tracking', 'message', 'note' ] ) );
		$built = self::build( $order, $template, $extra );
		if ( ! $built ) {
			return new \WP_Error( 'stms_template', __( 'That template no longer exists.', 'store-messages' ) );
		}
		if ( ! is_email( $to ) ) {
			return new \WP_Error( 'stms_to', __( 'Enter a valid email address.', 'store-messages' ) );
		}
		[ $tpl, $ctx, $mail ] = $built;
		if ( $test ) {
			/* translators: %s: email subject */
			$mail['subject'] = sprintf( __( '[Test] %s', 'store-messages' ), $mail['subject'] );
		}

		$headers = self::headers();
		$bcc     = sanitize_email( stms_settings()['bcc'] );
		if ( ! $test && is_email( $bcc ) && $bcc !== $to ) {
			$headers[] = 'Bcc: ' . $bcc;
		}
		$sent = self::deliver( $to, $mail, (array) apply_filters( 'stms_email_headers', $headers, $tpl, $order ) );

		if ( $test || ! $order ) {
			return $sent ? true : new \WP_Error( 'stms_send', self::failure_text() );
		}

		$tracking = (array) ( $ctx['tracking_url'] ? [ 'carrier' => $args['tracking']['carrier'] ?? '', 'name' => $ctx['carrier'], 'number' => $ctx['tracking_number'], 'url' => $ctx['tracking_url'] ] : [] );
		self::record( $order, [
			'template' => is_array( $template ) ? 'custom' : $template,
			'name'     => $tpl['name'] ?? '',
			'subject'  => $mail['subject'],
			'to'       => $to,
			'ok'       => $sent,
			'tracking' => $tracking['url'] ?? '',
			'error'    => $sent ? '' : self::failure_text(),
		] );
		if ( ! $sent ) {
			return new \WP_Error( 'stms_send', self::failure_text() );
		}
		if ( ! empty( $args['tracking']['url'] ) ) {
			Tracking::save( $order, $args['tracking'] );
		}
		if ( ! empty( $args['complete'] ) && ! $order->has_status( 'completed' ) ) {
			self::$completing = true;
			$order->update_status( 'completed', __( 'Marked Completed while sending a message.', 'store-messages' ) );
			self::$completing = false;
		}
		do_action( 'stms_sent', $order, $template, $args, $mail );
		return true;
	}

	/**
	 * Render a template for an order in the language the customer ordered in.
	 *
	 * @param \WC_Order|null $order    The order (null: sample data, store language).
	 * @param string|array   $template Template id, or a template array (editor previews).
	 * @param array          $extra    tracking, message, note.
	 * @param string|null    $layout   Layout HTML; defaults to the saved layout.
	 * @return array|null [template, context, mail], or null when the template does not exist.
	 */
	public static function build( $order, $template, array $extra = [], $layout = null ) {
		$lang = Language::for_order( $order );
		return Language::run( $lang, static function () use ( $order, $template, $extra, $layout, $lang ) {
			// Looked up inside run() so built-in templates come out in the customer's language.
			$tpl = is_array( $template ) ? $template : Templates::get( $template );
			if ( ! $tpl ) {
				return null;
			}
			$ctx          = $order ? Renderer::context( $order, $extra ) : Renderer::sample_context( $extra );
			$mail         = Renderer::render( Language::translate_template( $tpl, $lang ), $ctx, $layout );
			$mail['html'] = Language::translate_html( $mail['html'], $lang );
			return [ $tpl, $ctx, $mail ];
		} );
	}

	/** Content type, From and Reply-To for every Store Messages email. */
	public static function headers() {
		return [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', self::header_name( stms_from_name() ), stms_from_email() ),
			'Reply-To: ' . ( is_email( stms_settings()['reply_to'] ) ? stms_settings()['reply_to'] : stms_from_email() ),
		];
	}

	/**
	 * Hand a rendered email ([subject, html, text]) to wp_mail with its plain-text part.
	 *
	 * @return bool Sent. On failure, last_error() says why.
	 */
	public static function deliver( $to, array $mail, array $headers ) {
		self::$alt_body   = $mail['text'];
		self::$last_error = '';
		add_action( 'phpmailer_init', [ __CLASS__, 'alt_body' ], 1000 );
		add_action( 'wp_mail_failed', [ __CLASS__, 'capture_error' ] );
		$sent = wp_mail( $to, $mail['subject'], $mail['html'], $headers );
		remove_action( 'phpmailer_init', [ __CLASS__, 'alt_body' ], 1000 );
		remove_action( 'wp_mail_failed', [ __CLASS__, 'capture_error' ] );
		self::$alt_body = '';
		return (bool) $sent;
	}

	public static function last_error() {
		return self::failure_text();
	}

	/** Private order note, the order's history, and the store-wide Activity list. */
	private static function record( \WC_Order $order, array $entry ) {
		$entry += [ 'time' => time(), 'user' => get_current_user_id() ];
		$note    = $entry['ok']
			/* translators: 1: email subject, 2: email address */
			? sprintf( __( 'Store Messages: “%1$s” emailed to %2$s.', 'store-messages' ), $entry['subject'], $entry['to'] )
			/* translators: 1: email subject, 2: error */
			: sprintf( __( 'Store Messages: “%1$s” could not be sent (%2$s).', 'store-messages' ), $entry['subject'], $entry['error'] );
		if ( $entry['tracking'] ) {
			$note .= ' ' . __( 'Tracking:', 'store-messages' ) . ' ' . $entry['tracking'];
		}
		$order->add_order_note( $note, 0, (bool) $entry['user'] );

		$log   = (array) $order->get_meta( self::LOG );
		$log[] = $entry;
		$order->update_meta_data( self::LOG, array_slice( $log, -50 ) );
		$order->save_meta_data();

		$activity = (array) get_option( self::ACTIVITY, [] );
		array_unshift( $activity, $entry + [ 'order' => $order->get_id(), 'number' => $order->get_order_number() ] );
		update_option( self::ACTIVITY, array_slice( $activity, 0, 200 ), false );
	}

	public static function history( \WC_Order $order ) {
		return array_reverse( array_filter( (array) $order->get_meta( self::LOG ), 'is_array' ) );
	}

	public static function alt_body( $phpmailer ) {
		if ( self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
	}

	public static function capture_error( $error ) {
		self::$last_error = is_wp_error( $error ) ? $error->get_error_message() : '';
	}

	private static function failure_text() {
		return self::$last_error ?: __( 'The site could not send the email. Check its mail settings (SMTP).', 'store-messages' );
	}

	/** Names with commas or quotes must be quoted in a From header. */
	private static function header_name( $name ) {
		$name = str_replace( [ "\r", "\n", '"' ], '', wp_specialchars_decode( (string) $name, ENT_QUOTES ) );
		return preg_match( '/[,;:<>()]/', $name ) ? '"' . $name . '"' : $name;
	}
}
