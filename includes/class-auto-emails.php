<?php
/**
 * Automatic emails: the emails WooCommerce and WordPress send on their own
 * (order received, refund, password reset…) can use a Store Messages template
 * instead of their built-in text.
 *
 * WooCommerce still decides when an email is sent, to whom and with which
 * attachments; only the subject and the content are replaced, in the language
 * the customer ordered in. Each template is switched on separately
 * (setting auto_emails); a switched-off one leaves the email as it was.
 *
 * WordPress emails are recognised by the filter WordPress runs just before
 * sending them, and replaced when they reach wp_mail().
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Auto_Emails {

	/** WordPress emails a template can replace: [key => filter that announces it]. */
	const WP = [
		'retrieve_password' => 'retrieve_password_notification_email',
		'new_user'          => 'wp_new_user_notification_email',
		'password_change'   => 'password_change_email',
		'email_change'      => 'email_change_email',
		'new_user_email'    => 'new_user_email_content',
		'user_request'      => 'user_request_action_email_content',
		'privacy_export'    => 'wp_privacy_personal_data_email_content',
		'privacy_erased'    => 'user_erasure_fulfillment_email_content',
	];

	/** True while a template renders WooCommerce's order blocks (our own tracking line is then left out). */
	public static $rendering = false;

	/** The WooCommerce email being replaced: object id, template id, entry for the order history. */
	private static $woo = null;

	/** A WordPress email announced by its filter, waiting for wp_mail(): to, template, vars, lang. */
	private static $wp = null;

	public static function init() {
		add_filter( 'woocommerce_mail_callback_params', [ __CLASS__, 'woo_swap' ], 20, 2 );
		add_action( 'woocommerce_email_sent', [ __CLASS__, 'woo_sent' ], 10, 3 );

		add_filter( 'retrieve_password_notification_email', [ __CLASS__, 'arm_retrieve_password' ], 99, 4 );
		add_filter( 'wp_new_user_notification_email', [ __CLASS__, 'arm_new_user' ], 99, 2 );
		add_filter( 'password_change_email', [ __CLASS__, 'arm_password_change' ], 99, 2 );
		add_filter( 'email_change_email', [ __CLASS__, 'arm_email_change' ], 99, 3 );
		add_filter( 'new_user_email_content', [ __CLASS__, 'arm_new_user_email' ], 99, 2 );
		add_filter( 'user_request_action_email_content', [ __CLASS__, 'arm_user_request' ], 99, 2 );
		add_filter( 'wp_privacy_personal_data_email_content', [ __CLASS__, 'arm_privacy_export' ], 99, 3 );
		add_filter( 'user_erasure_fulfillment_email_content', [ __CLASS__, 'arm_privacy_erased' ], 99, 2 );
		// Before Email_Style::wrap_plain (20), which leaves an email alone once it is HTML.
		add_filter( 'wp_mail', [ __CLASS__, 'wp_swap' ], 15 );
	}

	/* ------------------------------------------------------------ lookup -- */

	/** Is this automatic template switched on? */
	public static function enabled( $template_id ) {
		return in_array( (string) $template_id, (array) stms_settings()['auto_emails'], true );
	}

	/** The template that can replace a WooCommerce email id or a WordPress email key, or null. */
	public static function template_for( $source, $key ) {
		foreach ( Templates::all() as $id => $tpl ) {
			if ( in_array( $key, (array) ( $tpl[ $source ] ?? [] ), true ) ) {
				return $id;
			}
		}
		return null;
	}

	/** Titles of the emails a template replaces, as WooCommerce / WordPress name them. */
	public static function replaces( array $tpl ) {
		$names = [];
		if ( ! empty( $tpl['woo'] ) && function_exists( 'WC' ) ) {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				if ( in_array( $email->id, (array) $tpl['woo'], true ) ) {
					$names[] = $email->get_title();
				}
			}
		}
		$wp = [
			'retrieve_password' => __( 'Password reset (WordPress login page)', 'store-messages' ),
			'new_user'          => __( 'New user (account created by an admin)', 'store-messages' ),
			'password_change'   => __( 'Password changed', 'store-messages' ),
			'email_change'      => __( 'Email address changed', 'store-messages' ),
			'new_user_email'    => __( 'Confirm new email address', 'store-messages' ),
			'user_request'      => __( 'Confirm a privacy request', 'store-messages' ),
			'privacy_export'    => __( 'Personal data export ready', 'store-messages' ),
			'privacy_erased'    => __( 'Personal data erased', 'store-messages' ),
		];
		foreach ( (array) ( $tpl['wp'] ?? [] ) as $key ) {
			$names[] = $wp[ $key ] ?? $key;
		}
		return $names;
	}

	/** A WooCommerce email object by id (a copy, so previews never change the real one). */
	public static function woo_email( $id ) {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( $email->id === $id ) {
				return clone $email;
			}
		}
		return null;
	}

	/* ------------------------------------------------------- WooCommerce -- */

	/**
	 * WooCommerce is about to send: swap in our subject and content.
	 *
	 * @param array     $params [to, subject, message, headers, attachments].
	 * @param \WC_Email $email  The email.
	 */
	public static function woo_swap( $params, $email ) {
		self::$woo = null;
		if ( ! $email instanceof \WC_Email ) {
			return $params;
		}
		$id = self::template_for( 'woo', $email->id );
		if ( ! $id || ! self::enabled( $id ) ) {
			return $params;
		}
		$order = $email->object instanceof \WC_Order ? $email->object : null;
		$extra = self::woo_extra( $email );
		try {
			$built = Mailer::build( $order, $id, $extra );
		} catch ( \Throwable $e ) {
			// Never lose an email: send WooCommerce's own text instead.
			return $params;
		}
		if ( ! $built ) {
			return $params;
		}
		[ $tpl, , $mail ] = $built;

		$params[1] = $mail['subject'];
		if ( 'plain' === $email->get_email_type() ) {
			$params[2] = $mail['text'];
		} else {
			$params[2] = $mail['html'];
			Email_Style::queue_alt( $mail['html'], $mail['text'] );
		}
		if ( $order && $email->is_customer_email() ) {
			self::$woo = [
				'object' => spl_object_id( $email ),
				'order'  => $order,
				'mail'   => [ 'subject' => $mail['subject'], 'html' => $mail['html'] ],
				'entry'  => [ 'template' => $id, 'name' => $tpl['name'] ?? '', 'subject' => $mail['subject'], 'to' => is_array( $params[0] ) ? implode( ', ', $params[0] ) : (string) $params[0] ],
			];
		}
		return $params;
	}

	/** What a WooCommerce email knows that the template can use. */
	public static function woo_extra( \WC_Email $email ) {
		$extra = [ 'wc_email' => $email, 'live' => true ];
		if ( ! $email->is_customer_email() ) {
			$extra['lang'] = ''; // emails to the store: the store's language
		}
		if ( ! empty( $email->refund ) && $email->refund instanceof \WC_Order_Refund ) {
			$extra['refund'] = $email->refund;
		}
		if ( property_exists( $email, 'partial_refund' ) ) {
			$extra['partial_refund'] = (bool) $email->partial_refund;
		}
		if ( $email->object instanceof \WP_User ) {
			$user          = $email->object;
			$extra['lang'] = Language::for_user( $user );
			$vars          = self::user_vars( $user );
			if ( ! empty( $email->reset_key ) ) {
				$vars['reset_url'] = add_query_arg(
					[ 'key' => $email->reset_key, 'id' => $user->ID, 'login' => rawurlencode( $user->user_login ) ],
					wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) )
				);
			}
			if ( ! empty( $email->password_generated ) && ! empty( $email->set_password_url ) ) {
				$vars['set_password_url'] = $email->set_password_url;
			}
			if ( ! empty( $email->verify_url ) ) {
				$vars['confirm_url'] = $email->verify_url;
			}
			if ( ! empty( $email->user_email ) ) {
				$vars['customer_email'] = $email->user_email;
			}
			$extra['vars'] = $vars;
		}
		return $extra;
	}

	public static function woo_sent( $ok, $id, $email ) {
		$sent      = self::$woo;
		self::$woo = null;
		if ( ! $sent || ! is_object( $email ) || spl_object_id( $email ) !== $sent['object'] ) {
			return;
		}
		// WooCommerce writes its own order note; this adds the copy to the order's history.
		Mailer::log( $sent['order'], $sent['mail'], $sent['entry'] + [ 'ok' => (bool) $ok, 'tracking' => '', 'error' => $ok ? '' : Mailer::last_error() ], false );
	}

	/**
	 * WooCommerce's order table (with totals, payment instructions and
	 * downloads) and the customer's details, as WooCommerce prints them, for
	 * {order_details} and {customer_details}. Styles are inlined the way
	 * WooCommerce does it for its own emails.
	 *
	 * @param \WC_Order      $order The order.
	 * @param \WC_Email|null $email The email being sent; a copy of the invoice email for previews.
	 */
	public static function order_blocks( \WC_Order $order, $email = null ) {
		$email  = $email instanceof \WC_Email ? $email : self::woo_email( 'customer_invoice' );
		$admin  = $email ? ! $email->is_customer_email() : false;
		$blocks = [ 'order_details' => '', 'customer_details' => '' ];
		if ( ! $email ) {
			return $blocks;
		}
		$added = ! has_filter( 'woocommerce_email_styles', [ Email_Style::class, 'styles' ] );
		if ( $added ) {
			add_filter( 'woocommerce_email_styles', [ Email_Style::class, 'styles' ], 999 );
		}
		self::$rendering = true;
		try {
			ob_start();
			do_action( 'woocommerce_email_order_details', $order, $admin, false, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce hook
			do_action( 'woocommerce_email_order_meta', $order, $admin, false, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			$blocks['order_details'] = self::styled( (string) ob_get_clean(), $email );
			ob_start();
			do_action( 'woocommerce_email_customer_details', $order, $admin, false, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			$blocks['customer_details'] = self::styled( (string) ob_get_clean(), $email );
		} finally {
			self::$rendering = false;
			if ( $added ) {
				remove_filter( 'woocommerce_email_styles', [ Email_Style::class, 'styles' ], 999 );
			}
		}
		return $blocks;
	}

	/** A block of WooCommerce email HTML with WooCommerce's (and our) styles inlined. */
	private static function styled( $html, \WC_Email $email ) {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$html = '<div id="stms-woo">' . $html . '</div>';
		try {
			$doc = $email->style_inline( $html );
		} catch ( \Throwable $e ) {
			return $html;
		}
		// The inliner returns a whole document; keep what is inside <body>.
		return preg_match( '#<body[^>]*>(.*)</body>#is', (string) $doc, $m ) ? trim( $m[1] ) : (string) $doc;
	}

	/** Sample values for the account placeholders, for previews and tests. */
	public static function sample_vars() {
		$account = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		return [
			'user_login'         => 'alex.martin',
			'reset_url'          => add_query_arg( [ 'key' => 'sample', 'login' => 'alex.martin' ], wc_get_endpoint_url( 'lost-password', '', $account ) ),
			'set_password_url'   => add_query_arg( [ 'key' => 'sample', 'login' => 'alex.martin' ], wc_get_endpoint_url( 'lost-password', '', $account ) ),
			'login_url'          => $account,
			'new_email'          => 'alex.new@example.com',
			'request_type'       => __( 'Export Personal Data', 'default' ),
			'download_url'       => home_url( '/' ),
			'expires_date'       => wp_date( get_option( 'date_format' ), time() + 3 * DAY_IN_SECONDS ),
			'privacy_policy_url' => get_privacy_policy_url(),
		];
	}

	/**
	 * Built-in automatic templates, merged into Templates::defaults().
	 * The helpers are the ones the other built-in templates are made with.
	 */
	public static function defaults( callable $p, $sign, callable $button, $tracking ) {
		$hi      = $p( '{if first_name}' . __( 'Hi {first_name},', 'store-messages' ) . '{else}' . __( 'Hello,', 'store-messages' ) . '{/if}' );
		$small   = static fn( $text ) => $p( $text, 'margin:0;font-size:13px;color:#71717a' );
		$details = "\n{order_details}\n{customer_details}\n";
		$auto    = [ 'system' => true, 'auto' => true ];

		$customer = [
			'wc_processing' => [
				'name'        => __( 'Order received (processing)', 'store-messages' ),
				'description' => __( 'Sent when an order is paid and being prepared.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} order #{order_number} has been received', 'store-messages' ),
				'heading'     => __( 'Thank you for your order', 'store-messages' ),
				'preheader'   => __( 'We have received your order and are getting it ready.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'We have received your order <strong>#{order_number}</strong> and are now getting it ready. We will let you know as soon as it is on its way.', 'store-messages' ), 'margin:0 0 20px' )
					. $details . $sign,
				'woo'         => [ 'customer_processing_order' ],
			],
			'wc_on_hold' => [
				'name'        => __( 'Order on hold (awaiting payment)', 'store-messages' ),
				'description' => __( 'Sent when an order waits for payment, e.g. a bank transfer.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} order #{order_number} has been received', 'store-messages' ),
				'heading'     => __( 'Thank you for your order', 'store-messages' ),
				'preheader'   => __( 'Your order is on hold until we receive your payment.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Thank you for your order <strong>#{order_number}</strong>. It is on hold until we confirm that your payment has been received.', 'store-messages' ), 'margin:0 0 20px' )
					. $details . $sign,
				'woo'         => [ 'customer_on_hold_order' ],
			],
			'wc_completed' => [
				'name'        => __( 'Order complete', 'store-messages' ),
				'description' => __( 'Sent when an order is marked Completed.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} order #{order_number} is complete', 'store-messages' ),
				'heading'     => __( 'Your order is complete', 'store-messages' ),
				'preheader'   => '{if tracking_url}' . __( 'Track your package: {carrier} {tracking_number}', 'store-messages' ) . '{else}' . __( 'We have finished processing your order.', 'store-messages' ) . '{/if}',
				'body'        => $hi
					. $p( __( 'We have finished processing your order <strong>#{order_number}</strong>.', 'store-messages' ), 'margin:0 0 20px' )
					. $tracking
					. $details . $sign,
				'woo'         => [ 'customer_completed_order', 'customer_pos_completed_order' ],
			],
			'wc_refunded' => [
				'name'        => __( 'Order refunded (full or partial)', 'store-messages' ),
				'description' => __( 'Sent when you refund an order, in full or in part.', 'store-messages' ),
				'subject'     => '{if partial_refund}' . __( 'Your {site_name} order #{order_number} has been partially refunded', 'store-messages' ) . '{else}' . __( 'Your {site_name} order #{order_number} has been refunded', 'store-messages' ) . '{/if}',
				'heading'     => '{if partial_refund}' . __( 'Partial refund issued', 'store-messages' ) . '{else}' . __( 'Your order has been refunded', 'store-messages' ) . '{/if}',
				'preheader'   => __( 'Refund of {refund_amount} for order #{order_number}.', 'store-messages' ),
				'body'        => $hi
					. '{if partial_refund}' . $p( __( 'We have refunded <strong>{refund_amount}</strong> of your order <strong>#{order_number}</strong>.', 'store-messages' ) )
					. '{else}' . $p( __( 'Your order <strong>#{order_number}</strong> has been refunded in full: <strong>{refund_amount}</strong>.', 'store-messages' ) ) . "{/if}\n"
					. '{if refund_reason}' . $p( __( 'Reason: {refund_reason}', 'store-messages' ) ) . "{/if}\n"
					. $p( __( 'The money goes back to your original payment method. It can take a few business days to appear on your statement.', 'store-messages' ), 'margin:0 0 20px' )
					. $details . $sign,
				'woo'         => [ 'customer_refunded_order', 'customer_partially_refunded_order', 'customer_pos_refunded_order' ],
			],
			'wc_failed' => [
				'name'        => __( 'Payment failed', 'store-messages' ),
				'description' => __( 'Sent to the customer when the payment of an order fails.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} order #{order_number} was unsuccessful', 'store-messages' ),
				'heading'     => __( 'Your payment did not go through', 'store-messages' ),
				'preheader'   => __( 'You can try again with another payment method.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Unfortunately, we could not complete your order <strong>#{order_number}</strong> because of a problem with the payment.', 'store-messages' ) )
					. $p( __( 'If you would still like to place it, you can pay again, with another payment method if you like.', 'store-messages' ), 'margin:0 0 20px' )
					. '{if needs_payment}' . $button( '{payment_url}', __( 'Pay for this order', 'store-messages' ) ) . "{/if}\n"
					. $details . $sign,
				'woo'         => [ 'customer_failed_order' ],
			],
			'wc_cancelled' => [
				'name'        => __( 'Order cancelled (to the customer)', 'store-messages' ),
				'description' => __( 'Sent to the customer when an order is cancelled.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} order #{order_number} has been cancelled', 'store-messages' ),
				'heading'     => __( 'Your order has been cancelled', 'store-messages' ),
				'preheader'   => __( 'Order #{order_number} has been cancelled.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'We are writing to let you know that your order <strong>#{order_number}</strong> has been cancelled.', 'store-messages' ), 'margin:0 0 20px' )
					. $details . $sign,
				'woo'         => [ 'customer_cancelled_order' ],
			],
			'wc_invoice' => [
				'name'        => __( 'Order details / invoice', 'store-messages' ),
				'description' => __( 'Sent when you email the order details to the customer from the order screen, with a payment link if unpaid.', 'store-messages' ),
				'subject'     => '{if needs_payment}' . __( 'Invoice for order #{order_number} at {site_name}', 'store-messages' ) . '{else}' . __( 'Details of your order #{order_number}', 'store-messages' ) . '{/if}',
				'heading'     => '{if needs_payment}' . __( 'Your invoice', 'store-messages' ) . '{else}' . __( 'Your order details', 'store-messages' ) . '{/if}',
				'preheader'   => __( 'Order #{order_number} · {order_total}', 'store-messages' ),
				'body'        => $hi
					. '{if needs_payment}' . $p( __( 'An order has been created for you at {site_name}. You can pay for it with the button below.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{payment_url}', __( 'Pay for this order', 'store-messages' ) )
					. '{else}' . $p( __( 'Here are the details of your order <strong>#{order_number}</strong>, placed on {order_date}.', 'store-messages' ), 'margin:0 0 20px' ) . "{/if}\n"
					. $details . $sign,
				'woo'         => [ 'customer_invoice' ],
			],
		];

		$account = [
			'wc_reset_password' => [
				'name'        => __( 'Password reset', 'store-messages' ),
				'description' => __( 'The link to choose a new password, from My account or the WordPress login page.', 'store-messages' ),
				'subject'     => __( 'Reset your {site_name} password', 'store-messages' ),
				'heading'     => __( 'Reset your password', 'store-messages' ),
				'preheader'   => __( 'Someone asked for a new password for your account.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Someone asked for a new password for the account <strong>{user_login}</strong> at {site_name}.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{reset_url}', __( 'Choose a new password', 'store-messages' ) )
					. $small( __( 'If you did not ask for this, simply ignore this email: your password stays the same.', 'store-messages' ) ),
				'woo'         => [ 'customer_reset_password' ],
				'wp'          => [ 'retrieve_password' ],
			],
			'wc_new_account' => [
				'name'        => __( 'New account', 'store-messages' ),
				'description' => __( 'Sent when a customer account is created, at checkout, on My account or by you.', 'store-messages' ),
				'subject'     => __( 'Your account at {site_name} has been created', 'store-messages' ),
				'heading'     => __( 'Welcome to {site_name}', 'store-messages' ),
				'preheader'   => __( 'Your account is ready.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Thank you for creating an account at {site_name}. Your username is <strong>{user_login}</strong>.', 'store-messages' ), 'margin:0 0 20px' )
					. '{if set_password_url}' . $p( __( 'Choose your password with the button below to start using your account.', 'store-messages' ) )
					. $button( '{set_password_url}', __( 'Choose your password', 'store-messages' ) )
					. '{else}' . $p( __( 'In your account you can see your orders, change your addresses and your password.', 'store-messages' ) )
					. $button( '{account_url}', __( 'Go to my account', 'store-messages' ) ) . "{/if}\n"
					. $sign,
				'woo'         => [ 'customer_new_account' ],
				'wp'          => [ 'new_user' ],
			],
			'wc_verify_email' => [
				'name'        => __( 'Confirm email address', 'store-messages' ),
				'description' => __( 'The link a customer uses to confirm their email address, so past guest orders join their account.', 'store-messages' ),
				'subject'     => __( 'Confirm your email address for {site_name}', 'store-messages' ),
				'heading'     => __( 'Confirm your email address', 'store-messages' ),
				'preheader'   => __( 'One click to link your past orders to your account.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Please confirm that <strong>{customer_email}</strong> is your email address. Orders you placed with it as a guest will then appear in your account.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{confirm_url}', __( 'Confirm email address', 'store-messages' ) )
					. $small( __( 'If you did not ask for this, simply ignore this email.', 'store-messages' ) ),
				'woo'         => [ 'customer_verify_email' ],
			],
			'wp_password_change' => [
				'name'        => __( 'Password changed', 'store-messages' ),
				'description' => __( 'Confirms that the password of an account was changed.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} password was changed', 'store-messages' ),
				'heading'     => __( 'Your password was changed', 'store-messages' ),
				'preheader'   => __( 'A confirmation for your security.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'This is a confirmation that the password of your account <strong>{user_login}</strong> at {site_name} was just changed.', 'store-messages' ) )
					. $p( __( 'If you did not change it, please contact us right away at {store_email}.', 'store-messages' ) )
					. $sign,
				'wp'          => [ 'password_change' ],
			],
			'wp_email_change' => [
				'name'        => __( 'Email address changed', 'store-messages' ),
				'description' => __( 'Sent to the old address when the email address of an account changes.', 'store-messages' ),
				'subject'     => __( 'Your {site_name} email address was changed', 'store-messages' ),
				'heading'     => __( 'Your email address was changed', 'store-messages' ),
				'preheader'   => __( 'A confirmation for your security.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'This is a confirmation that the email address of your account at {site_name} was changed to <strong>{new_email}</strong>.', 'store-messages' ) )
					. $p( __( 'If you did not change it, please contact us right away at {store_email}.', 'store-messages' ) )
					. $sign,
				'wp'          => [ 'email_change' ],
			],
			'wp_new_user_email' => [
				'name'        => __( 'Confirm new email address', 'store-messages' ),
				'description' => __( 'The link that confirms a new email address changed on the WordPress profile page.', 'store-messages' ),
				'subject'     => __( 'Confirm your new email address for {site_name}', 'store-messages' ),
				'heading'     => __( 'Confirm your new email address', 'store-messages' ),
				'preheader'   => __( 'One click to confirm your new address.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'You asked to change the email address of your account at {site_name} to <strong>{new_email}</strong>. Please confirm it with the button below.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{confirm_url}', __( 'Confirm new email address', 'store-messages' ) )
					. $small( __( 'If you did not ask for this, simply ignore this email.', 'store-messages' ) ),
				'wp'          => [ 'new_user_email' ],
			],
			'wp_user_request' => [
				'name'        => __( 'Confirm a privacy request', 'store-messages' ),
				'description' => __( 'The link that confirms a request to export or erase personal data.', 'store-messages' ),
				'subject'     => __( 'Please confirm your request: {request_type}', 'store-messages' ),
				'heading'     => __( 'Please confirm your request', 'store-messages' ),
				'preheader'   => '{request_type}',
				'body'        => $hi
					. $p( __( 'We received a request to do the following with your personal data at {site_name}: <strong>{request_type}</strong>.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{confirm_url}', __( 'Confirm request', 'store-messages' ) )
					. $small( __( 'If you did not ask for this, simply ignore this email.', 'store-messages' ) ),
				'wp'          => [ 'user_request' ],
			],
			'wp_privacy_export' => [
				'name'        => __( 'Personal data export ready', 'store-messages' ),
				'description' => __( 'The download link of a personal data export.', 'store-messages' ),
				'subject'     => __( 'Your personal data from {site_name}', 'store-messages' ),
				'heading'     => __( 'Your personal data is ready', 'store-messages' ),
				'preheader'   => __( 'Download it before {expires_date}.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'The export of your personal data you asked for is ready.', 'store-messages' ), 'margin:0 0 20px' )
					. $button( '{download_url}', __( 'Download your data', 'store-messages' ) )
					. '{if expires_date}' . $small( __( 'For your privacy, the link works until {expires_date}.', 'store-messages' ) ) . '{/if}',
				'wp'          => [ 'privacy_export' ],
			],
			'wp_privacy_erased' => [
				'name'        => __( 'Personal data erased', 'store-messages' ),
				'description' => __( 'Confirms that a request to erase personal data was carried out.', 'store-messages' ),
				'subject'     => __( 'Your personal data at {site_name} has been erased', 'store-messages' ),
				'heading'     => __( 'Your request is complete', 'store-messages' ),
				'preheader'   => __( 'We have erased your personal data.', 'store-messages' ),
				'body'        => $hi
					. $p( __( 'Your request to erase your personal data at {site_name} has been carried out.', 'store-messages' ) )
					. '{if privacy_policy_url}' . $p( __( 'You can read more about how we handle personal data in our <a href="{privacy_policy_url}">privacy policy</a>.', 'store-messages' ) ) . "{/if}\n"
					. $sign,
				'wp'          => [ 'privacy_erased' ],
			],
		];

		// Emails to the store: the store's language, no greeting.
		$store = [
			'wc_new_order' => [
				'name'        => __( 'New order (to you)', 'store-messages' ),
				'description' => __( 'Sent to the store when an order comes in.', 'store-messages' ),
				'subject'     => __( '[{site_name}] New order #{order_number}', 'store-messages' ),
				'heading'     => __( 'New order #{order_number}', 'store-messages' ),
				'preheader'   => __( '{customer_name} · {order_total}', 'store-messages' ),
				'body'        => $p( __( 'You have received a new order from <strong>{customer_name}</strong>.', 'store-messages' ), 'margin:0 0 20px' ) . $details,
				'woo'         => [ 'new_order' ],
				'store'       => true,
			],
			'wc_cancelled_admin' => [
				'name'        => __( 'Order cancelled (to you)', 'store-messages' ),
				'description' => __( 'Sent to the store when an order is cancelled.', 'store-messages' ),
				'subject'     => __( '[{site_name}] Order #{order_number} has been cancelled', 'store-messages' ),
				'heading'     => __( 'Order cancelled: #{order_number}', 'store-messages' ),
				'preheader'   => __( '{customer_name} · {order_total}', 'store-messages' ),
				'body'        => $p( __( 'Order <strong>#{order_number}</strong> from {customer_name} has been cancelled.', 'store-messages' ), 'margin:0 0 20px' ) . $details,
				'woo'         => [ 'cancelled_order' ],
				'store'       => true,
			],
			'wc_failed_admin' => [
				'name'        => __( 'Payment failed (to you)', 'store-messages' ),
				'description' => __( 'Sent to the store when the payment of an order fails.', 'store-messages' ),
				'subject'     => __( '[{site_name}] Payment failed for order #{order_number}', 'store-messages' ),
				'heading'     => __( 'Payment failed: #{order_number}', 'store-messages' ),
				'preheader'   => __( '{customer_name} · {order_total}', 'store-messages' ),
				'body'        => $p( __( 'The payment for order <strong>#{order_number}</strong> from {customer_name} failed. The order was:', 'store-messages' ), 'margin:0 0 20px' ) . $details,
				'woo'         => [ 'failed_order' ],
				'store'       => true,
			],
		];

		return array_map( static fn( $t ) => $t + $auto, $customer + $account + $store );
	}

	/* --------------------------------------------------------- WordPress -- */

	/** Values every account email can use. */
	public static function user_vars( $user ) {
		$user = $user instanceof \WP_User ? $user : null;
		if ( ! $user ) {
			return [];
		}
		$first = (string) get_user_meta( $user->ID, 'billing_first_name', true ) ?: (string) $user->first_name;
		return [
			'user_login'     => $user->user_login,
			'first_name'     => $first,
			'last_name'      => (string) $user->last_name,
			'customer_name'  => trim( $first . ' ' . $user->last_name ) ?: $user->display_name,
			'customer_email' => $user->user_email,
			'login_url'      => wp_login_url(),
		];
	}

	private static function arm( $key, $to, array $vars, $lang ) {
		$id       = self::template_for( 'wp', $key );
		self::$wp = $id && self::enabled( $id ) ? [ 'to' => strtolower( trim( (string) $to ) ), 'template' => $id, 'vars' => $vars, 'lang' => $lang ] : null;
	}

	public static function arm_retrieve_password( $mail, $key, $user_login, $user ) {
		if ( $user instanceof \WP_User ) {
			$url = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user_login ), 'login' );
			if ( ! is_user_logged_in() ) {
				$url = add_query_arg( 'wp_lang', get_user_locale( $user ), $url );
			}
			self::arm( 'retrieve_password', $mail['to'] ?? $user->user_email, self::user_vars( $user ) + [ 'reset_url' => $url ], Language::for_user( $user ) );
		}
		return $mail;
	}

	public static function arm_new_user( $mail, $user ) {
		if ( $user instanceof \WP_User && preg_match( '#https?://\S*action=rp\S*#', (string) ( $mail['message'] ?? '' ), $m ) ) {
			self::arm( 'new_user', $mail['to'] ?? $user->user_email, self::user_vars( $user ) + [ 'set_password_url' => rtrim( $m[0], '>' ) ], Language::for_user( $user ) );
		}
		return $mail;
	}

	public static function arm_password_change( $mail, $user ) {
		$account = get_user_by( 'id', (int) ( $user['ID'] ?? 0 ) );
		if ( $account ) {
			self::arm( 'password_change', $mail['to'] ?? $user['user_email'], self::user_vars( $account ) + [ 'customer_email' => $user['user_email'] ], Language::for_user( $account ) );
		}
		return $mail;
	}

	public static function arm_email_change( $mail, $user, $userdata ) {
		$account = get_user_by( 'id', (int) ( $user['ID'] ?? 0 ) );
		if ( $account ) {
			self::arm( 'email_change', $mail['to'] ?? $user['user_email'], self::user_vars( $account ) + [ 'customer_email' => $user['user_email'], 'new_email' => (string) ( $userdata['user_email'] ?? '' ) ], Language::for_user( $account ) );
		}
		return $mail;
	}

	public static function arm_new_user_email( $text, $new ) {
		$user = wp_get_current_user();
		if ( $user->exists() && ! empty( $new['newemail'] ) ) {
			self::arm( 'new_user_email', $new['newemail'], self::user_vars( $user ) + [ 'new_email' => $new['newemail'], 'confirm_url' => admin_url( 'profile.php?newuseremail=' . $new['hash'] ) ], Language::for_user( $user ) );
		}
		return $text;
	}

	public static function arm_user_request( $text, $data ) {
		$user = get_user_by( 'email', (string) ( $data['email'] ?? '' ) );
		self::arm( 'user_request', $data['email'] ?? '', self::user_vars( $user ) + [
			'customer_email' => (string) ( $data['email'] ?? '' ),
			'request_type'   => (string) ( $data['description'] ?? '' ),
			'confirm_url'    => (string) ( $data['confirm_url'] ?? '' ),
		], $user ? Language::for_user( $user ) : '' );
		return $text;
	}

	public static function arm_privacy_export( $text, $request_id, $data = [] ) {
		$to   = (string) ( $data['message_recipient'] ?? '' );
		$user = get_user_by( 'email', $to );
		self::arm( 'privacy_export', $to, self::user_vars( $user ) + [
			'customer_email' => $to,
			'download_url'   => (string) ( $data['export_file_url'] ?? '' ),
			'expires_date'   => (string) ( $data['expiration_date'] ?? '' ),
		], $user ? Language::for_user( $user ) : '' );
		return $text;
	}

	public static function arm_privacy_erased( $text, $data ) {
		$to   = (string) ( $data['message_recipient'] ?? '' );
		$user = get_user_by( 'email', $to );
		self::arm( 'privacy_erased', $to, self::user_vars( $user ) + [
			'customer_email'     => $to,
			'privacy_policy_url' => (string) ( $data['privacy_policy_url'] ?? '' ),
		], $user ? Language::for_user( $user ) : '' );
		return $text;
	}

	/** The announced WordPress email reaches wp_mail(): send our template instead. */
	public static function wp_swap( $atts ) {
		$armed    = self::$wp;
		self::$wp = null;
		if ( ! $armed ) {
			return $atts;
		}
		$to = is_array( $atts['to'] ?? '' ) ? implode( ',', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
		if ( false === strpos( strtolower( $to ), $armed['to'] ) ) {
			return $atts; // a different email went out first; leave both alone
		}
		try {
			$built = Mailer::build( null, $armed['template'], [ 'live' => true, 'vars' => $armed['vars'], 'lang' => $armed['lang'] ] );
		} catch ( \Throwable $e ) {
			return $atts;
		}
		if ( ! $built ) {
			return $atts;
		}
		$mail    = $built[2];
		$headers = $atts['headers'] ?? [];
		$lines   = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		$lines   = array_filter( $lines, static fn( $l ) => '' !== trim( (string) $l ) && ! preg_match( '/^\s*content-type\s*:/i', (string) $l ) );
		$lines[] = 'Content-Type: text/html; charset=UTF-8';
		Email_Style::queue_alt( $mail['html'], $mail['text'] );
		return array_merge( $atts, [ 'subject' => $mail['subject'], 'message' => $mail['html'], 'headers' => array_values( $lines ) ] );
	}
}
