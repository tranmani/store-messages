<?php
/**
 * Ways to subscribe, and the subscriber's own pages.
 *
 *   Checkout      a checkbox on the (classic) checkout page
 *   My account    a checkbox on Account details
 *   Popup         a small sign-up popup, shown after a delay or some scrolling
 *   Shortcode     [store_messages_signup] anywhere
 *   Pages         confirm / unsubscribe / subscribe again, from the links in emails
 *
 * Forms post to a REST endpoint without a nonce, so they keep working on
 * pages served from a cache. Bots are kept out by a hidden field and a rate
 * limit per IP address.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Signup {

	const ROUTE = 'stms/v1';

	private static $assets = false;

	public static function init() {
		$s = self::settings();
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'admin_post_nopriv_stms_subscription', [ __CLASS__, 'page' ] );
		add_action( 'admin_post_stms_subscription', [ __CLASS__, 'page' ] );
		add_action( 'init', [ __CLASS__, 'mailpoet_links' ], 1 );
		add_shortcode( 'store_messages_signup', [ __CLASS__, 'shortcode' ] );

		if ( ! empty( $s['checkout'] ) ) {
			add_action( 'top' === $s['checkout_position'] ? 'woocommerce_checkout_before_terms_and_conditions' : 'woocommerce_review_order_before_submit', [ __CLASS__, 'checkout_field' ] );
			add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'checkout_save' ], 10, 3 );
		}
		if ( ! empty( $s['account'] ) ) {
			add_action( 'woocommerce_edit_account_form', [ __CLASS__, 'account_field' ] );
			add_action( 'woocommerce_save_account_details', [ __CLASS__, 'account_save' ] );
		}
		if ( ! empty( $s['popup'] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'popup' ], 5 ); // before footer scripts print (20), so its script is queued in time
		}
	}

	public static function settings() {
		return stms_settings()['signup'];
	}

	/** A setting's text in the visitor's language (Overlay Multilingual's dictionary, if active). */
	private static function t( $key ) {
		return Language::translate_text( (string) ( self::settings()[ $key ] ?? '' ), Language::current() );
	}

	/* ------------------------------------------------------------ checkout -- */

	public static function checkout_field() {
		$user = wp_get_current_user();
		if ( $user->exists() && Subscribers::is_subscribed( $user->user_email ) ) {
			return;
		}
		echo '<div class="stms-optin">';
		woocommerce_form_field( 'stms_optin', [ 'type' => 'checkbox', 'class' => [ 'form-row-wide' ], 'label' => esc_html( self::t( 'checkout_label' ) ) ], 0 );
		echo '</div>';
	}

	public static function checkout_save( $order_id, $posted = [], $order = null ) {
		if ( empty( $_POST['stms_optin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the checkout nonce
			return;
		}
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$lang = (string) $order->get_meta( '_ovml_lang' );
		Subscribers::subscribe( [
			'email'      => $order->get_billing_email(),
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'source'     => 'checkout',
			'lang'       => '' !== $lang ? $lang : Language::current(),
			'user_id'    => $order->get_customer_id(),
			'consent'    => self::settings()['checkout_label'],
		] );
		$order->update_meta_data( '_stms_optin', 1 );
		$order->save_meta_data();
	}

	/* ---------------------------------------------------------- my account -- */

	public static function account_field() {
		$user = wp_get_current_user();
		?>
		<p class="woocommerce-form-row form-row form-row-wide stms-optin">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox">
				<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="stms_optin" value="1" <?php checked( Subscribers::is_subscribed( $user->user_email ) ); ?>>
				<span><?php echo esc_html( self::t( 'account_label' ) ); ?></span>
			</label>
			<input type="hidden" name="stms_optin_shown" value="1">
		</p>
		<?php
	}

	public static function account_save( $user_id ) {
		if ( empty( $_POST['stms_optin_shown'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified the account form nonce
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$sub = Subscribers::find( $user->user_email );
		if ( ! empty( $_POST['stms_optin'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Subscribers::subscribe( [ 'email' => $user->user_email, 'first_name' => $user->first_name, 'last_name' => $user->last_name, 'source' => 'account', 'user_id' => $user_id, 'verified' => true, 'consent' => self::settings()['account_label'] ] );
		} elseif ( $sub ) {
			Subscribers::unsubscribe( $sub );
		}
	}

	/* ---------------------------------------------------------------- REST -- */

	public static function routes() {
		register_rest_route( self::ROUTE, '/subscribe', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_subscribe' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function rest_subscribe( \WP_REST_Request $req ) {
		$ok = static fn( $msg ) => new \WP_REST_Response( [ 'ok' => true, 'message' => $msg ], 200 );
		if ( '' !== trim( (string) $req->get_param( 'website' ) ) ) {
			return $ok( self::t( 'success' ) ); // the hidden field was filled in: a bot, pretend all is well
		}
		$ip  = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'stms_rl_' . md5( $ip . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			return new \WP_REST_Response( [ 'ok' => false, 'message' => __( 'Too many attempts. Please try again later.', 'store-messages' ) ], 429 );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$name  = trim( sanitize_text_field( (string) $req->get_param( 'name' ) ) );
		$parts = preg_split( '/\s+/', $name, 2 );
		$lang  = sanitize_key( substr( (string) $req->get_param( 'lang' ), 0, 2 ) );
		$sub   = Subscribers::subscribe( [
			'email'      => (string) $req->get_param( 'email' ),
			'first_name' => $parts[0] ?? '',
			'last_name'  => $parts[1] ?? '',
			'source'     => 'popup' === $req->get_param( 'source' ) ? 'popup' : 'form',
			'lang'       => isset( Language::all()[ $lang ] ) ? $lang : Language::current(),
			'consent'    => self::settings()['popup_note'] ?: self::settings()['popup_heading'],
		] );
		if ( is_wp_error( $sub ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'message' => Language::translate_text( $sub->get_error_message(), $lang ) ], 400 );
		}
		$msg = Subscribers::UNCONFIRMED === $sub->status ? __( 'Almost done: check your inbox and click the link to confirm.', 'store-messages' ) : self::settings()['success'];
		return $ok( Language::translate_text( $msg, $lang ) );
	}

	/* ------------------------------------------------------- popup + form -- */

	private static function enqueue() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		wp_enqueue_style( 'stms-signup', STMS_URL . 'assets/signup.css', [], STMS_VERSION );
		wp_enqueue_script( 'stms-signup', STMS_URL . 'assets/signup.js', [], STMS_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_localize_script( 'stms-signup', 'stmsSignup', [
			'rest'  => esc_url_raw( rest_url( self::ROUTE . '/subscribe' ) ),
			'error' => Language::translate_text( __( 'Something went wrong. Please try again.', 'store-messages' ), Language::current() ),
			'email' => Language::translate_text( __( 'Please enter a valid email address.', 'store-messages' ), Language::current() ),
		] );
	}

	/** Brand colours for the form, as CSS custom properties. */
	private static function style_vars() {
		$accent = stms_brand()['accent'];
		return '--stms-accent:' . esc_attr( $accent ) . ';--stms-on-accent:' . esc_attr( stms_contrast( $accent ) );
	}

	/** The form used by the popup and the shortcode. */
	public static function form( $source, array $args = [] ) {
		$s    = self::settings();
		$id   = 'stms-f' . wp_rand( 1000, 9999 );
		$args = array_merge( [ 'button' => self::t( 'popup_button' ), 'note' => self::t( 'popup_note' ), 'name' => ! empty( $s['popup_name'] ) ], $args );
		ob_start();
		?>
		<form class="stms-signup" data-stms-signup data-source="<?php echo esc_attr( $source ); ?>" novalidate>
			<?php if ( $args['name'] ) : ?>
				<?php $label = Language::translate_text( __( 'First name', 'store-messages' ), Language::current() ); ?>
				<label class="stms-signup__label" for="<?php echo esc_attr( $id ); ?>-name"><?php echo esc_html( $label ); ?></label>
				<input class="stms-signup__input" id="<?php echo esc_attr( $id ); ?>-name" type="text" name="name" autocomplete="given-name" placeholder="<?php echo esc_attr( $label ); ?>">
			<?php endif; ?>
			<?php $label = Language::translate_text( __( 'Email address', 'store-messages' ), Language::current() ); ?>
			<label class="stms-signup__label" for="<?php echo esc_attr( $id ); ?>-email"><?php echo esc_html( $label ); ?></label>
			<input class="stms-signup__input" id="<?php echo esc_attr( $id ); ?>-email" type="email" name="email" autocomplete="email" inputmode="email" required placeholder="<?php echo esc_attr( $label ); ?>">
			<span class="stms-signup__hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
			<button class="stms-signup__button" type="submit"><?php echo esc_html( $args['button'] ); ?></button>
			<?php if ( '' !== trim( (string) $args['note'] ) ) : ?>
				<p class="stms-signup__note"><?php echo wp_kses( $args['note'], [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></p>
			<?php endif; ?>
			<p class="stms-signup__msg" role="status" aria-live="polite"></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'heading' => '', 'text' => '', 'button' => '', 'name' => '' ], (array) $atts, 'store_messages_signup' );
		self::enqueue();
		$args = array_filter( [ 'button' => $atts['button'] ] );
		if ( '' !== $atts['name'] ) {
			$args['name'] = in_array( strtolower( $atts['name'] ), [ '1', 'yes', 'true' ], true );
		}
		return '<div class="stms-signup-box" style="' . self::style_vars() . '">'
			. ( '' !== $atts['heading'] ? '<h3 class="stms-signup-box__title">' . esc_html( $atts['heading'] ) . '</h3>' : '' )
			. ( '' !== $atts['text'] ? '<p class="stms-signup-box__text">' . esc_html( $atts['text'] ) . '</p>' : '' )
			. self::form( 'form', $args ) . '</div>';
	}

	/** Should the popup be on this page? */
	public static function popup_here() {
		$s = self::settings();
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() || is_account_page() ) ) ) {
			return false;
		}
		$langs = array_filter( array_map( 'trim', explode( ',', (string) $s['popup_langs'] ) ) );
		if ( $langs && ! in_array( Language::current(), $langs, true ) ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( $user->exists() && Subscribers::is_subscribed( $user->user_email ) ) {
			return false;
		}
		$where = (string) $s['popup_pages'];
		$here  = 'all' === $where
			|| ( 'home' === $where && is_front_page() )
			|| ( 'shop' === $where && ( is_front_page() || ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) ) );
		return (bool) apply_filters( 'stms_show_popup', $here );
	}

	public static function popup() {
		if ( ! self::popup_here() ) {
			return;
		}
		self::enqueue();
		echo self::popup_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built
	}

	/** Cookie banners the popup waits for, so it never covers the consent choice. */
	private static function consent_selectors() {
		$selectors = [ '#wpconsent-root', '#wpconsent-container', '#cookie-law-info-bar', '.cky-consent-container', '#cmplz-cookiebanner-container', '#CybotCookiebotDialog', '#onetrust-banner-sdk', '#moove_gdpr_cookie_info_bar', '#cookie-notice', '.cc-window', '#BorlabsCookieBox', '#usercentrics-root' ];
		return implode( ',', (array) apply_filters( 'stms_popup_wait_for', $selectors ) );
	}

	/** Popup markup; also used for the preview on the Sign-up tab. */
	public static function popup_html( $preview = false ) {
		$s     = self::settings();
		$image = (string) $s['popup_image'];
		ob_start();
		?>
		<div class="stms-pop<?php echo $preview ? ' is-preview is-open' : ''; ?>" <?php echo $preview ? '' : 'hidden'; ?> data-stms-popup data-delay="<?php echo (int) $s['popup_delay']; ?>" data-scroll="<?php echo (int) $s['popup_scroll']; ?>" data-days="<?php echo (int) $s['popup_days']; ?>" data-wait-for="<?php echo esc_attr( self::consent_selectors() ); ?>" style="<?php echo self::style_vars(); // phpcs:ignore ?>">
			<div class="stms-pop__backdrop" data-stms-close></div>
			<div class="stms-pop__dialog" role="dialog" aria-modal="true" aria-labelledby="stms-pop-title" tabindex="-1">
				<button type="button" class="stms-pop__close" data-stms-close aria-label="<?php echo esc_attr( Language::translate_text( __( 'Close', 'store-messages' ), Language::current() ) ); ?>">&times;</button>
				<?php if ( '' !== $image ) : ?>
					<img class="stms-pop__image" src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy" decoding="async">
				<?php endif; ?>
				<div class="stms-pop__body">
					<h2 class="stms-pop__title" id="stms-pop-title"><?php echo esc_html( self::t( 'popup_heading' ) ); ?></h2>
					<?php if ( '' !== trim( (string) $s['popup_text'] ) ) : ?>
						<p class="stms-pop__text"><?php echo esc_html( self::t( 'popup_text' ) ); ?></p>
					<?php endif; ?>
					<?php echo self::form( 'popup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------- subscription pages -- */

	/** admin-post.php?action=stms_subscription&do=confirm|unsubscribe|resubscribe&t=token */
	public static function page() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		$do   = sanitize_key( $_REQUEST['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- the token is the authorisation
		$sub  = Subscribers::find_by_token( (string) wp_unslash( $_REQUEST['t'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
		if ( ! $sub ) {
			self::render_page( '', __( 'This link is not valid any more', 'store-messages' ), '<p style="margin:0 0 16px">' . esc_html__( 'It may be old or incomplete. If you want to stop receiving our emails, use the unsubscribe link in the latest one, or simply reply to it.', 'store-messages' ) . '</p>' );
		}
		$lang = (string) $sub->lang;
		if ( 'confirm' === $do ) {
			$sub = Subscribers::confirm( $sub );
			self::render_page( $lang, __( 'You are subscribed', 'store-messages' ), '<p style="margin:0 0 16px">' . esc_html__( 'Thank you for confirming. You will hear from us soon.', 'store-messages' ) . '</p>' . self::shop_button() );
		}
		if ( 'resubscribe' === $do && $post ) {
			Subscribers::subscribe( [ 'email' => $sub->email, 'source' => $sub->source, 'verified' => true, 'consent' => __( 'Subscribed again from the unsubscribe page', 'store-messages' ) ] );
			self::render_page( $lang, __( 'Welcome back', 'store-messages' ), '<p style="margin:0 0 16px">' . esc_html__( 'You are subscribed again.', 'store-messages' ) . '</p>' . self::shop_button() );
		}
		if ( 'unsubscribe' === $do && $post ) {
			// Also RFC 8058 one-click: email apps POST "List-Unsubscribe=One-Click" here.
			Subscribers::unsubscribe( $sub );
			self::render_page( $lang, __( 'You are unsubscribed', 'store-messages' ), '<p style="margin:0 0 16px">' . sprintf( /* translators: %s: email address */ esc_html__( 'We will not send newsletters to %s any more. Emails about your orders still arrive as usual.', 'store-messages' ), '<strong>' . esc_html( $sub->email ) . '</strong>' ) . '</p>' . self::button_form( 'resubscribe', __( 'Subscribe again', 'store-messages' ), $sub, true ) );
		}
		if ( Subscribers::UNSUBSCRIBED === $sub->status ) {
			self::render_page( $lang, __( 'You are unsubscribed', 'store-messages' ), '<p style="margin:0 0 16px">' . sprintf( esc_html__( 'We do not send newsletters to %s.', 'store-messages' ), '<strong>' . esc_html( $sub->email ) . '</strong>' ) . '</p>' . self::button_form( 'resubscribe', __( 'Subscribe again', 'store-messages' ), $sub, true ) );
		}
		// A link was opened: ask first, so link checkers in mail apps cannot unsubscribe anyone.
		self::render_page( $lang, __( 'Unsubscribe?', 'store-messages' ), '<p style="margin:0 0 20px">' . sprintf( esc_html__( 'Stop sending newsletters to %s?', 'store-messages' ), '<strong>' . esc_html( $sub->email ) . '</strong>' ) . '</p>' . self::button_form( 'unsubscribe', __( 'Unsubscribe', 'store-messages' ), $sub ) );
	}

	private static function button_form( $do, $label, $sub, $secondary = false ) {
		$accent = stms_brand()['accent'];
		$style  = $secondary
			? 'padding:12px 22px;border:1px solid #d4d4d8;border-radius:8px;background:#fff;color:#27272a;font:600 15px/1.2 inherit;cursor:pointer'
			: 'padding:13px 26px;border:0;border-radius:8px;background:' . esc_attr( $accent ) . ';color:' . esc_attr( stms_contrast( $accent ) ) . ';font:600 15px/1.2 inherit;cursor:pointer';
		return '<form method="post" action="' . esc_url( Marketing::link( $sub, $do ) ) . '" style="margin:0"><button type="submit" style="' . $style . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function shop_button() {
		$accent = stms_brand()['accent'];
		$url    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return '<p style="margin:8px 0 0"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 26px;border-radius:8px;background:' . esc_attr( $accent ) . ';color:' . esc_attr( stms_contrast( $accent ) ) . ';font-weight:600;text-decoration:none">' . esc_html__( 'Visit the shop', 'store-messages' ) . '</a></p>';
	}

	/** A page in the email layout, in the subscriber's language. Ends the request. */
	private static function render_page( $lang, $heading, $content ) {
		$html = Language::run( $lang, static function () use ( $heading, $content ) {
			[ $before, $after ] = Email_Style::shell( $heading );
			return $before . $content . $after;
		} );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo Language::translate_html( $html, $lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts
		exit;
	}

	/**
	 * Links in emails MailPoet sent before it was removed:
	 * ?mailpoet_page=subscriptions&mailpoet_router&endpoint=subscription&action=…&data=…
	 * The subscriber is found by email and MailPoet's token (imported with them).
	 */
	public static function mailpoet_links() {
		if ( defined( 'MAILPOET_VERSION' ) || ! isset( $_GET['mailpoet_router'], $_GET['data'] ) || 'subscription' !== ( $_GET['endpoint'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$data = json_decode( (string) base64_decode( strtr( (string) wp_unslash( $_GET['data'] ), ' ', '+' ) ), true ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.PHP.DiscouragedPHPFunctions
		$sub  = is_array( $data ) ? Subscribers::find( (string) ( $data['email'] ?? '' ) ) : null;
		$ok   = $sub && ! empty( $data['token'] ) && hash_equals( (string) $sub->token, (string) $data['token'] );
		nocache_headers();
		$action = sanitize_key( $_GET['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( $ok ? Marketing::link( $sub, 'confirm' === $action ? 'confirm' : 'unsubscribe' ) : add_query_arg( [ 'action' => 'stms_subscription', 'do' => 'unsubscribe', 't' => 'invalid' ], admin_url( 'admin-post.php' ) ) );
		exit;
	}
}
