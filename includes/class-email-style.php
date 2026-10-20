<?php
/**
 * Puts the site's other emails in the Store Messages layout, so every email a
 * customer gets looks the same:
 *
 *   WooCommerce emails  header, footer and styles are replaced; the content
 *                       (order table, addresses, wording) stays WooCommerce's.
 *   WordPress emails    plain-text emails (password reset, new user, email
 *                       change…) are turned into HTML and wrapped. Emails that
 *                       already choose a content type are left alone.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Email_Style {

	const MARK = '<!--stms-content-->';
	const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

	/** Layout halves still to be printed by footer(), one per email being rendered. */
	private static $after = [];

	/** Accent used for WooCommerce's buttons while an email renders. */
	private static $accent = '';

	/** [md5 of the HTML body, plain-text original] for the WordPress email being sent. */
	private static $alt = null;

	public static function init() {
		$s = stms_settings();
		if ( ! empty( $s['style_wc_emails'] ) ) {
			add_action( 'woocommerce_email', [ __CLASS__, 'take_over' ], 100 );
			if ( did_action( 'woocommerce_email' ) && function_exists( 'WC' ) ) {
				self::take_over( WC()->mailer() );
			}
			add_filter( 'woocommerce_email_styles', [ __CLASS__, 'styles' ], 999 );
			add_filter( 'woocommerce_email_order_items_args', [ __CLASS__, 'item_images' ] );
		}
		if ( ! empty( $s['style_wp_emails'] ) ) {
			add_filter( 'wp_mail', [ __CLASS__, 'wrap_plain' ], 20 );
		}
	}

	/* -------------------------------------------------------- WooCommerce -- */

	/**
	 * Swap WooCommerce's header and footer for ours. MailPoet's "use the MailPoet
	 * editor for WooCommerce emails" does the same swap with closures, which
	 * would print a second header, so those are removed too.
	 */
	public static function take_over( $mailer ) {
		if ( is_object( $mailer ) ) {
			remove_action( 'woocommerce_email_header', [ $mailer, 'email_header' ] );
			remove_action( 'woocommerce_email_footer', [ $mailer, 'email_footer' ] );
		}
		foreach ( [ 'woocommerce_email_header', 'woocommerce_email_footer' ] as $hook ) {
			self::remove_closures_from( $hook, '/mailpoet/' );
		}
		add_action( 'woocommerce_email_header', [ __CLASS__, 'header' ], 10, 2 );
		add_action( 'woocommerce_email_footer', [ __CLASS__, 'footer' ], 10, 1 );
	}

	private static function remove_closures_from( $hook, $path ) {
		global $wp_filter;
		if ( empty( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( $cb['function'] instanceof \Closure && false !== strpos( (string) ( new \ReflectionFunction( $cb['function'] ) )->getFileName(), $path ) ) {
					remove_action( $hook, $cb['function'], $priority );
				}
			}
		}
	}

	public static function header( $heading = '', $email = null ) {
		$order = is_object( $email ) && ( $email->object ?? null ) instanceof \WC_Order ? $email->object : null;
		[ $before, $after ] = self::shell( wp_strip_all_tags( (string) $heading ), $order, is_object( $email ) ? (string) $email->get_subject() : '' );
		self::$after[] = $after;
		// WooCommerce's button (new account, password reset, invoice) takes its colour from this option.
		self::$accent = stms_brand()['accent'];
		add_filter( 'pre_option_woocommerce_email_base_color', [ __CLASS__, 'accent' ] );
		echo $before . '<div id="stms-woo">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Renderer, placeholders escaped there
	}

	public static function footer( $email = null ) {
		if ( ! self::$after ) {
			return;
		}
		remove_filter( 'pre_option_woocommerce_email_base_color', [ __CLASS__, 'accent' ] );
		echo '</div>' . array_pop( self::$after ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function accent() {
		return self::$accent;
	}

	/** The layout split around its content: [before, after]. */
	public static function shell( $heading, $order = null, $subject = '' ) {
		$ctx   = $order ? Renderer::context( $order ) : Renderer::context();
		$mail  = Renderer::render( [ 'subject' => '' !== $subject ? $subject : $heading, 'heading' => $heading, 'preheader' => '', 'body' => self::MARK ], $ctx );
		$parts = explode( self::MARK, $mail['html'], 2 );
		return [ $parts[0], $parts[1] ?? '' ];
	}

	/** Product images in the order table, the same size as in Store Messages' own emails. */
	public static function item_images( $args ) {
		if ( empty( $args['plain_text'] ) ) {
			$args['show_image'] = true;
			$args['image_size'] = [ 48, 48 ];
		}
		return $args;
	}

	/**
	 * Styles for WooCommerce's content inside the layout. WooCommerce inlines
	 * them; its own styles target its own wrapper, which is no longer there.
	 */
	public static function styles( $css = '' ) {
		$b      = stms_brand();
		$text   = $b['text'];
		$accent = $b['accent'];
		$on     = stms_contrast( $accent );
		$muted  = '#71717a';
		$line   = '#f0f0f1';
		$border = '#e4e4e7';
		$font   = self::FONT;
		$css    = <<<CSS
#stms-woo, #stms-woo .font-family, #stms-woo td, #stms-woo th { font-family: $font; }
#stms-woo p { margin: 0 0 16px; }
#stms-woo a { color: $accent; }
#stms-woo h2 { margin: 28px 0 10px; font-size: 15px; line-height: 1.4; font-weight: 700; color: $text; }
#stms-woo h2 a, #stms-woo h2 .link { color: $text; text-decoration: none; }
#stms-woo h3 { margin: 20px 0 8px; font-size: 15px; font-weight: 600; color: $text; }
#stms-woo ul { margin: 0 0 16px; padding: 0 0 0 18px; }
#stms-woo hr { border: 0 !important; border-top: 1px solid $line !important; margin: 4px 0 0 !important; }
#stms-woo table.td { width: 100%; border: 0 !important; border-collapse: collapse; margin: 0 0 24px; font-size: 14px; color: $text; }
#stms-woo .td { border: 0 !important; border-top: 1px solid $line !important; padding: 10px 0 !important; color: $text; vertical-align: middle; text-align: left; }
#stms-woo thead .td { border-top: 0 !important; padding: 0 0 8px !important; font-size: 12px; font-weight: 400; letter-spacing: .06em; text-transform: uppercase; color: $muted; }
#stms-woo .text-align-right { text-align: right; }
#stms-woo thead .td + .td, #stms-woo .order_item .td + .td { text-align: right; padding-left: 12px !important; white-space: nowrap; }
#stms-woo .order_item img { float: left; width: 48px; height: 48px; margin: 0 12px 0 0; border: 0; border-radius: 8px; }
#stms-woo .order-item-data td { padding: 0; border: 0; vertical-align: middle; }
#stms-woo .wc-product-name { margin: 0; font-size: 14px; font-weight: 400; }
#stms-woo .wc-item-meta { margin: 4px 0 0; padding: 0; list-style: none; font-size: 12px; color: $muted; }
#stms-woo .wc-item-meta li, #stms-woo .wc-item-meta p { margin: 0; }
#stms-woo .wc-item-meta-label { float: none !important; margin: 0 4px 0 0 !important; font-weight: 400; }
#stms-woo .order-totals th.td, #stms-woo tfoot th.td { font-weight: 400; text-align: left; }
#stms-woo .order-totals td.td, #stms-woo tfoot td.td { text-align: right; padding-left: 12px !important; }
#stms-woo .order-totals:first-child .td { border-top: 0 !important; }
#stms-woo .order-totals-total .td, #stms-woo tfoot tr:last-child .td { font-size: 16px; font-weight: 700; }
#stms-woo #addresses { width: 100%; margin: 0 0 8px !important; }
#stms-woo #addresses td { padding: 0 6px 0 0 !important; vertical-align: top; }
#stms-woo #addresses td + td { padding: 0 0 0 6px !important; }
#stms-woo #addresses h2, #stms-woo .address-title { display: block; margin: 0 0 6px; font-size: 12px; font-weight: 400; letter-spacing: .06em; text-transform: uppercase; color: $muted; }
#stms-woo .address { padding: 14px 16px; border: 1px solid $border; border-radius: 12px; font-size: 14px; font-style: normal; line-height: 1.5; color: $text; }
#stms-woo a.button { display: inline-block !important; padding: 13px 26px !important; border: 0 !important; border-radius: 8px !important; box-shadow: none !important; background: $accent !important; color: $on !important; font-weight: 600 !important; text-decoration: none !important; }
#stms-woo a[style*="padding:16px 32px"] { padding: 13px 26px !important; border-radius: 8px !important; font-weight: 600 !important; }
@media (max-width: 620px) {
  #stms-woo #addresses td, #stms-woo #addresses td + td { display: block !important; width: 100% !important; padding: 0 0 12px !important; }
}
CSS;
		/**
		 * Change the styles used for WooCommerce's emails.
		 *
		 * @param string $css CSS, inlined by WooCommerce.
		 */
		return (string) apply_filters( 'stms_wc_email_styles', $css );
	}

	/* ---------------------------------------------------------- WordPress -- */

	/**
	 * A plain-text email: send it as HTML in the layout, with the original
	 * text as the plain-text part.
	 */
	public static function wrap_plain( $atts ) {
		self::$alt = null;
		$message   = $atts['message'] ?? '';
		if ( ! is_string( $message ) || '' === trim( $message ) || ! self::is_plain( $atts ) ) {
			return $atts;
		}
		/**
		 * Whether to put this plain-text email in the Store Messages layout.
		 *
		 * @param bool  $wrap Wrap it.
		 * @param array $atts wp_mail() arguments.
		 */
		if ( ! apply_filters( 'stms_style_wp_email', true, $atts ) ) {
			return $atts;
		}
		$subject = (string) ( $atts['subject'] ?? '' );
		$heading = trim( (string) preg_replace( '/^\[[^\]]*\]\s*/', '', $subject ) );
		[ $before, $after ] = self::shell( '' !== $heading ? $heading : $subject );
		$html = $before . self::text_to_html( $message ) . $after;

		$headers   = $atts['headers'] ?? [];
		$type      = 'Content-Type: text/html; charset=UTF-8';
		$headers   = is_array( $headers ) ? array_merge( $headers, [ $type ] ) : ( '' === trim( (string) $headers ) ? [ $type ] : rtrim( (string) $headers ) . "\n" . $type );
		self::$alt = [ md5( $html ), $message ];
		add_action( 'phpmailer_init', [ __CLASS__, 'alt_body' ], 1001 );
		return array_merge( $atts, [ 'message' => $html, 'headers' => $headers ] );
	}

	/** No content type chosen by the sender, and the text has no HTML in it. */
	private static function is_plain( array $atts ) {
		$headers = $atts['headers'] ?? [];
		$lines   = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*content-type\s*:/i', (string) $line ) ) {
				return false;
			}
		}
		if ( 'text/plain' !== apply_filters( 'wp_mail_content_type', 'text/plain' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core hook
			return false;
		}
		return ! preg_match( '/<(html|body|table|div|p|br|a)\b/i', (string) $atts['message'] );
	}

	public static function alt_body( $phpmailer ) {
		remove_action( 'phpmailer_init', [ __CLASS__, 'alt_body' ], 1001 );
		// Only the email we wrapped: another plugin may have stopped it before PHPMailer.
		if ( self::$alt && md5( (string) $phpmailer->Body ) === self::$alt[0] ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->AltBody = self::$alt[1]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		self::$alt = null;
	}

	/** Plain text → paragraphs; links become clickable and wrap on small screens. */
	public static function text_to_html( $text ) {
		$text = str_replace( [ "\r\n", "\r" ], "\n", trim( (string) $text ) );
		$text  = (string) preg_replace( '#<(https?://[^>\s]+)>#', '$1', $text ); // older "<https://…>" style
		$style = 'color:' . esc_attr( stms_brand()['accent'] ) . ';word-break:break-all';
		$html  = '';
		// Odd entries are URLs. Built by hand: make_clickable() on escaped text double-encodes "&" in reset links.
		foreach ( preg_split( '#(https?://[^\s<>"]+)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE ) as $i => $part ) {
			if ( $i % 2 ) {
				$url   = rtrim( $part, '.,;:!?)' );
				$html .= '<a href="' . esc_url( $url ) . '" style="' . $style . '">' . esc_html( $url ) . '</a>' . esc_html( substr( $part, strlen( $url ) ) );
			} else {
				$html .= esc_html( $part );
			}
		}
		return str_replace( '<p>', '<p style="margin:0 0 14px">', wpautop( $html ) );
	}
}
