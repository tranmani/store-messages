<?php
/**
 * "Send a message" box on the WooCommerce order screen.
 *
 * @package StoreMessages
 */

namespace StoreMessages\Admin;

use StoreMessages\Carriers;
use StoreMessages\Mailer;
use StoreMessages\Templates;
use StoreMessages\Tracking;

defined( 'ABSPATH' ) || exit;

class Order_Box {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ], 30 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_ajax_stms_send', [ __CLASS__, 'ajax_send' ] );
	}

	/** Order edit screen id for both storage modes (HPOS and posts). */
	private static function screens() {
		$screens = [ 'shop_order' ];
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_unique( $screens );
	}

	public static function register() {
		foreach ( self::screens() as $screen ) {
			add_meta_box( 'stms-order-box', __( 'Send a message', 'store-messages' ), [ __CLASS__, 'render' ], $screen, 'side', 'high' );
		}
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, self::screens(), true ) && current_user_can( Admin::CAP ) ) {
			Admin::enqueue_common();
		}
	}

	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order || ! current_user_can( Admin::CAP ) ) {
			return;
		}
		$templates = Templates::for_orders();
		$tracking  = Tracking::get( $order ) ?: [];
		$carriers  = Carriers::all();
		$carrier   = $tracking['carrier'] ?? self::guess_carrier( $order );
		$suggested = self::suggest( $order, $templates );
		?>
		<div class="stms stms-box" data-stms-box data-order="<?php echo (int) $order->get_id(); ?>">
			<p class="stms-box__field">
				<label for="stms-box-template"><?php esc_html_e( 'Message', 'store-messages' ); ?></label>
				<select id="stms-box-template" data-stms-box-template>
					<?php foreach ( $templates as $id => $tpl ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" data-tracking="<?php echo Templates::uses_tracking( $tpl ) ? 1 : 0; ?>" data-message="<?php echo Templates::uses_message( $tpl ) ? 1 : 0; ?>" data-complete="<?php echo 'shipped' === $id || 'ready_pickup' === $id ? 1 : 0; ?>" title="<?php echo esc_attr( $tpl['description'] ); ?>" <?php selected( $suggested, $id ); ?>><?php echo esc_html( $tpl['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<div data-stms-box-tracking>
				<p class="stms-box__field">
					<label for="stms-box-tracking"><?php esc_html_e( 'Tracking number or link', 'store-messages' ); ?></label>
					<input type="text" id="stms-box-tracking" data-stms-box-tracking-input value="<?php echo esc_attr( $tracking['url'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Paste the link or type the number', 'store-messages' ); ?>" autocomplete="off">
				</p>
				<p class="stms-box__field" data-stms-box-carrier-row>
					<label for="stms-box-carrier"><?php esc_html_e( 'Carrier', 'store-messages' ); ?></label>
					<select id="stms-box-carrier" data-stms-box-carrier>
						<?php foreach ( $carriers as $id => $c ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" data-url="<?php echo esc_attr( $c['url'] ); ?>" <?php selected( $carrier, $id ); ?>><?php echo esc_html( $c['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>
			<p class="stms-box__field" data-stms-box-message-row>
				<label for="stms-box-message"><?php esc_html_e( 'Personal note', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(optional)', 'store-messages' ); ?></span></label>
				<textarea id="stms-box-message" rows="3" data-stms-box-message></textarea>
			</p>
			<p class="stms-box__field">
				<label for="stms-box-to"><?php esc_html_e( 'To', 'store-messages' ); ?></label>
				<input type="email" id="stms-box-to" data-stms-box-to value="<?php echo esc_attr( $order->get_billing_email() ); ?>">
			</p>
			<?php if ( ! $order->has_status( 'completed' ) ) : ?>
				<p class="stms-box__check">
					<label><input type="checkbox" data-stms-box-complete> <?php esc_html_e( 'Also mark the order Completed', 'store-messages' ); ?></label>
				</p>
			<?php endif; ?>
			<div class="stms-box__actions">
				<button type="button" class="button" data-stms-box-preview><?php esc_html_e( 'Preview', 'store-messages' ); ?></button>
				<button type="button" class="button button-primary" data-stms-box-send><?php esc_html_e( 'Send email', 'store-messages' ); ?></button>
			</div>
			<p class="stms-status" data-stms-box-status aria-live="polite"></p>
			<?php self::history( $order ); ?>
			<p class="stms-box__foot"><a href="<?php echo esc_url( Admin::url() ); ?>"><?php esc_html_e( 'Edit templates', 'store-messages' ); ?></a></p>
		</div>
		<?php
	}

	public static function history( \WC_Order $order ) {
		$log = Mailer::history( $order );
		echo '<div class="stms-box__history" data-stms-box-history>';
		if ( $log ) {
			echo '<p class="stms-box__label">' . esc_html__( 'Sent from here', 'store-messages' ) . '</p><ul>';
			foreach ( array_slice( $log, 0, 6 ) as $row ) {
				printf(
					'<li class="%s"><strong>%s</strong><span>%s · %s</span></li>',
					! empty( $row['ok'] ) ? '' : 'is-failed',
					esc_html( $row['name'] ?: $row['subject'] ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ),
					! empty( $row['ok'] ) ? esc_html( $row['to'] ) : esc_html__( 'failed', 'store-messages' )
				);
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** Suggest the likely next message from the order's state. */
	private static function suggest( \WC_Order $order, array $templates ) {
		$sent = wp_list_pluck( Mailer::history( $order ), 'template' );
		if ( $order->has_status( [ 'pending', 'failed', 'on-hold' ] ) && isset( $templates['payment_reminder'] ) && $order->needs_payment() ) {
			return 'payment_reminder';
		}
		if ( $order->has_status( 'processing' ) && $order->has_shipping_method( 'local_pickup' ) && isset( $templates['ready_pickup'] ) ) {
			return 'ready_pickup';
		}
		if ( ! in_array( 'shipped', $sent, true ) && isset( $templates['shipped'] ) ) {
			return 'shipped';
		}
		return isset( $templates['custom'] ) ? 'custom' : (string) array_key_first( $templates );
	}

	/** The carrier last used for this shipping country, else the first one. */
	private static function guess_carrier( \WC_Order $order ) {
		$by_country = (array) get_option( 'stms_carrier_by_country', [] );
		$country    = $order->get_shipping_country() ?: $order->get_billing_country();
		$carrier    = $by_country[ $country ] ?? ( $by_country['*'] ?? '' );
		return $carrier && Carriers::get( $carrier ) ? $carrier : (string) array_key_first( Carriers::all() );
	}

	public static function ajax_send() {
		check_ajax_referer( 'stms_ajax', 'nonce' );
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Sorry, you are not allowed to do that.', 'store-messages' ) ], 403 );
		}
		$req   = wp_unslash( $_POST );
		$order = wc_get_order( absint( $req['order'] ?? 0 ) );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'store-messages' ) ] );
		}
		$template = sanitize_key( $req['template'] ?? '' );
		$tpl      = Templates::get( $template );
		$args     = [
			'to'       => sanitize_email( $req['to'] ?? '' ),
			'message'  => wp_kses_post( $req['message'] ?? '' ),
			'complete' => ! empty( $req['complete'] ),
		];
		if ( $tpl && Templates::uses_tracking( $tpl ) ) {
			$tracking = Carriers::resolve( sanitize_text_field( $req['tracking'] ?? '' ), sanitize_key( $req['carrier'] ?? '' ) );
			if ( '' === $tracking['url'] ) {
				wp_send_json_error( [ 'message' => __( 'Add the tracking number or link first.', 'store-messages' ) ] );
			}
			$args['tracking'] = $tracking;
		}
		$result = Mailer::send( $order, $template, $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		if ( ! empty( $args['tracking']['carrier'] ) ) {
			$by_country = (array) get_option( 'stms_carrier_by_country', [] );
			$by_country[ $order->get_shipping_country() ?: '*' ] = $args['tracking']['carrier'];
			update_option( 'stms_carrier_by_country', $by_country, false );
		}
		ob_start();
		self::history( wc_get_order( $order->get_id() ) );
		wp_send_json_success( [
			/* translators: %s: email address */
			'message' => sprintf( __( 'Sent to %s', 'store-messages' ), $args['to'] ),
			'history' => ob_get_clean(),
			'reload'  => $args['complete'],
		] );
	}
}
