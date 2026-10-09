<?php
/**
 * Admin screen: WooCommerce → Messages.
 *
 * @package StoreMessages
 */

namespace StoreMessages\Admin;

use StoreMessages\Carriers;
use StoreMessages\Mailer;
use StoreMessages\Renderer;
use StoreMessages\Templates;
use StoreMessages\Updater;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'store-messages';
	const CAP  = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 60 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_post' ] );
		add_action( 'wp_ajax_stms_preview', [ __CLASS__, 'ajax_preview' ] );
		add_action( 'wp_ajax_stms_test', [ __CLASS__, 'ajax_test' ] );
		add_action( 'wp_ajax_stms_check_updates', [ __CLASS__, 'ajax_check_updates' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( STMS_FILE ), [ __CLASS__, 'action_links' ] );
		add_filter( 'plugin_row_meta', [ __CLASS__, 'row_meta' ], 10, 2 );
	}

	public static function url( $tab = 'templates', $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Store Messages', 'store-messages' ), __( 'Messages', 'store-messages' ), self::CAP, self::SLUG, [ __CLASS__, 'page' ] );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'store-messages' ) . '</a>' );
		return $links;
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( STMS_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( 'https://github.com/' . Updater::repository() ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on GitHub', 'store-messages' ) . '</a>';
			$links[] = '<a href="' . esc_url( 'https://github.com/' . Updater::repository() . '/issues' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Report an issue', 'store-messages' ) . '</a>';
		}
		return $links;
	}

	/** Shared by the Messages screen and the order screen's box. */
	public static function enqueue_common() {
		wp_enqueue_style( 'stms-admin', STMS_URL . 'assets/admin.css', [], STMS_VERSION );
		wp_enqueue_script( 'stms-admin', STMS_URL . 'assets/admin.js', [], STMS_VERSION, true );
		wp_localize_script( 'stms-admin', 'stmsAdmin', [
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'stms_ajax' ),
			'i18n'  => [
				'sending'       => __( 'Sending…', 'store-messages' ),
				'sent'          => __( 'Sent to %s', 'store-messages' ),
				'error'         => __( 'Something went wrong. Check your connection and try again.', 'store-messages' ),
				'confirmSend'   => __( 'Send “%1$s” to %2$s?', 'store-messages' ),
				'confirmDelete' => __( 'Delete this template?', 'store-messages' ),
				'confirmReset'  => __( 'Replace your edits with the original text?', 'store-messages' ),
				'checking'      => __( 'Checking…', 'store-messages' ),
				'preview'       => __( 'Preview', 'store-messages' ),
				'close'         => __( 'Close', 'store-messages' ),
				'subject'       => __( 'Subject', 'store-messages' ),
				'reloading'     => __( 'Sent. Reloading…', 'store-messages' ),
			],
		] );
	}

	public static function assets( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'templates' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $tab, [ 'templates', 'layout' ], true ) ) {
			$editor = wp_enqueue_code_editor( [
				'type'       => 'text/html',
				'codemirror' => [ 'lineWrapping' => true, 'indentUnit' => 2, 'tabSize' => 2 ],
				// Email HTML is indented with spaces; WordPress's default rule only accepts tabs.
				'htmlhint'   => [ 'space-tab-mixed-disabled' => false ],
			] );
			wp_add_inline_script( 'code-editor', 'window.stmsCodeEditor = ' . wp_json_encode( $editor ) . ';' );
		}
		if ( 'layout' === $tab ) {
			wp_enqueue_media();
		}
		self::enqueue_common();
	}

	/* ------------------------------------------------------------- layout -- */

	public static function tabs() {
		return [
			'templates' => __( 'Templates', 'store-messages' ),
			'layout'    => __( 'Layout', 'store-messages' ),
			'carriers'  => __( 'Carriers', 'store-messages' ),
			'activity'  => __( 'Activity', 'store-messages' ),
			'settings'  => __( 'Settings', 'store-messages' ),
			'about'     => __( 'About', 'store-messages' ),
		];
	}

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'], $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'templates'; // phpcs:ignore WordPress.Security.NonceVerification
		$s    = stms_settings();
		?>
		<div class="stms">
			<div class="stms-header">
				<h1><?php esc_html_e( 'Store Messages', 'store-messages' ); ?></h1>
				<?php if ( ! empty( $s['note_emails'] ) ) : ?>
					<span class="stms-pill stms-pill--ok"><?php esc_html_e( 'Customer notes use this layout', 'store-messages' ); ?></span>
				<?php else : ?>
					<span class="stms-pill stms-pill--off"><?php esc_html_e( 'Customer notes use WooCommerce’s email', 'store-messages' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="stms-sub"><?php esc_html_e( 'Good-looking emails to your customers, sent from the order screen: tracking links, pickup notices, refunds, delays and personal messages.', 'store-messages' ); ?></p>
			<?php self::notices(); ?>
			<nav class="stms-tabs" aria-label="<?php esc_attr_e( 'Sections', 'store-messages' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $tab === $key ? 'is-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php call_user_func( [ __CLASS__, 'tab_' . $tab ] ); ?>
		</div>
		<?php
	}

	private static function notices() {
		$notice = get_transient( 'stms_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'stms_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
		}
	}

	private static function flash( $text, $type = 'success' ) {
		set_transient( 'stms_notice_' . get_current_user_id(), [ 'text' => $text, 'type' => $type ], 60 );
	}

	/** Recent orders for "Preview with" pickers: [id => label]. */
	private static function recent_orders( $limit = 15 ) {
		$out = [];
		foreach ( wc_get_orders( [ 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ) ] ) as $order ) {
			$out[ $order->get_id() ] = sprintf( '#%s · %s · %s', $order->get_order_number(), trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email(), wc_get_order_status_name( $order->get_status() ) );
		}
		return $out;
	}

	/** Preview panel: order picker, device toggle, test send, iframe. */
	private static function preview_panel( $with_test = true ) {
		$orders = self::recent_orders();
		$user   = wp_get_current_user();
		?>
		<div class="stms-preview" data-stms-preview>
			<div class="stms-preview__bar">
				<label class="screen-reader-text" for="stms-preview-order"><?php esc_html_e( 'Preview with', 'store-messages' ); ?></label>
				<select id="stms-preview-order" data-stms-preview-order>
					<option value="0"><?php esc_html_e( 'Sample order', 'store-messages' ); ?></option>
					<?php foreach ( $orders as $id => $label ) : ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
				</select>
				<div class="stms-segmented stms-segmented--small" role="group" aria-label="<?php esc_attr_e( 'Preview width', 'store-messages' ); ?>">
					<label><input type="radio" name="stms_device" value="desktop" checked data-stms-device><span><?php esc_html_e( 'Desktop', 'store-messages' ); ?></span></label>
					<label><input type="radio" name="stms_device" value="mobile" data-stms-device><span><?php esc_html_e( 'Mobile', 'store-messages' ); ?></span></label>
				</div>
			</div>
			<p class="stms-preview__subject"><span><?php esc_html_e( 'Subject', 'store-messages' ); ?></span> <strong data-stms-preview-subject></strong></p>
			<div class="stms-preview__frame"><iframe title="<?php esc_attr_e( 'Email preview', 'store-messages' ); ?>" data-stms-preview-frame sandbox="allow-same-origin"></iframe></div>
			<?php if ( $with_test ) : ?>
				<div class="stms-preview__test">
					<input type="email" data-stms-test-to value="<?php echo esc_attr( $user->user_email ); ?>" aria-label="<?php esc_attr_e( 'Send a test to', 'store-messages' ); ?>">
					<button type="button" class="button" data-stms-test><?php esc_html_e( 'Send test', 'store-messages' ); ?></button>
					<span class="stms-status" data-stms-test-status aria-live="polite"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Clickable placeholder reference. */
	private static function reference() {
		?>
		<details class="stms-card stms-reference">
			<summary><h2><?php esc_html_e( 'Placeholders', 'store-messages' ); ?></h2></summary>
			<p class="stms-hint"><?php echo wp_kses_post( __( 'Click one to insert it. Show something only when a value exists with <code>{if tracking_url}…{/if}</code>, add <code>{else}…</code> for an alternative, or use <code>{if !message}…{/if}</code> for “when empty”.', 'store-messages' ) ); ?></p>
			<?php foreach ( Renderer::reference() as $group => $items ) : ?>
				<p class="stms-sub stms-reference__group"><?php echo esc_html( $group ); ?></p>
				<div class="stms-tokens">
					<?php foreach ( $items as $key => $label ) : ?>
						<button type="button" class="stms-token" data-stms-insert="<?php echo esc_attr( '{' . $key . '}' ); ?>" title="<?php echo esc_attr( $label ); ?>"><code>{<?php echo esc_html( $key ); ?>}</code><span><?php echo esc_html( $label ); ?></span></button>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</details>
		<?php
	}

	/* ---------------------------------------------------------- templates -- */

	public static function tab_templates() {
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $edit ) {
			self::template_editor( 'new' === $edit ? null : $edit );
			return;
		}
		$templates = Templates::all();
		$saved     = (array) get_option( Templates::OPTION, [] );
		$deleted   = array_filter( $saved, static fn( $t ) => ! empty( $t['deleted'] ) );
		?>
		<div class="stms-toolbar">
			<p class="stms-hint stms-hint--inline"><?php esc_html_e( 'Send these from any order: open the order and use the “Send a message” box. Every template is plain HTML you can change.', 'store-messages' ); ?></p>
			<span class="spacer"></span>
			<a class="button button-primary" href="<?php echo esc_url( self::url( 'templates', [ 'edit' => 'new' ] ) ); ?>"><?php esc_html_e( 'New template', 'store-messages' ); ?></a>
		</div>
		<div class="stms-grid">
			<?php foreach ( $templates as $id => $tpl ) : ?>
				<a class="stms-card stms-template" href="<?php echo esc_url( self::url( 'templates', [ 'edit' => $id ] ) ); ?>">
					<span class="stms-template__head">
						<strong><?php echo esc_html( $tpl['name'] ); ?></strong>
						<?php if ( ! empty( $tpl['system'] ) ) : ?>
							<span class="stms-pill stms-pill--accent"><?php esc_html_e( 'Automatic', 'store-messages' ); ?></span>
						<?php elseif ( empty( $tpl['builtin'] ) ) : ?>
							<span class="stms-pill stms-pill--ok"><?php esc_html_e( 'Yours', 'store-messages' ); ?></span>
						<?php elseif ( isset( $saved[ $id ] ) ) : ?>
							<span class="stms-pill stms-pill--warn"><?php esc_html_e( 'Edited', 'store-messages' ); ?></span>
						<?php endif; ?>
					</span>
					<span class="stms-template__desc"><?php echo esc_html( $tpl['description'] ); ?></span>
					<span class="stms-template__subject"><?php echo esc_html( wp_strip_all_tags( Renderer::text( $tpl['subject'], Renderer::sample_context(), true ) ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php if ( $deleted ) : ?>
			<form method="post" class="stms-after-grid">
				<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
				<input type="hidden" name="stms_action" value="restore_templates">
				<button class="button-link"><?php printf( esc_html( _n( 'Restore %d deleted built-in template', 'Restore %d deleted built-in templates', count( $deleted ), 'store-messages' ) ), count( $deleted ) ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	private static function template_editor( $id ) {
		$tpl = $id ? Templates::get( $id ) : null;
		if ( $id && ! $tpl ) {
			echo '<div class="stms-card stms-empty"><strong>' . esc_html__( 'Template not found', 'store-messages' ) . '</strong><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to templates', 'store-messages' ) . '</a></div>';
			return;
		}
		$tpl    = $tpl ?: [ 'name' => '', 'description' => '', 'subject' => __( 'A message about your order #{order_number}', 'store-messages' ), 'heading' => '', 'preheader' => '', 'body' => "<p style=\"margin:0 0 16px\">Hi {first_name},</p>\n\n{message}\n\n<p style=\"margin:24px 0 0\">{site_name}</p>", 'builtin' => false ];
		$edited = $id && isset( ( (array) get_option( Templates::OPTION, [] ) )[ $id ] );
		?>
		<p class="stms-back"><a href="<?php echo esc_url( self::url() ); ?>">← <?php esc_html_e( 'All templates', 'store-messages' ); ?></a></p>
		<form method="post" class="stms-editor" data-stms-editor="template">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_template">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<div class="stms-editor__main">
				<div class="stms-card">
					<?php if ( ! empty( $tpl['system'] ) ) : ?>
						<p class="stms-callout"><?php esc_html_e( 'Sent automatically when you add a “Note to customer” on an order (if enabled on the Settings tab). {note} is the text you typed; a tracking link in the note fills the tracking placeholders and is shown as a button.', 'store-messages' ); ?></p>
					<?php endif; ?>
					<div class="stms-row">
						<div class="stms-field">
							<label for="stms-name"><?php esc_html_e( 'Name', 'store-messages' ); ?></label>
							<input type="text" id="stms-name" name="name" value="<?php echo esc_attr( $tpl['name'] ); ?>" required>
						</div>
						<div class="stms-field">
							<label for="stms-description"><?php esc_html_e( 'Description', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(only you see this)', 'store-messages' ); ?></span></label>
							<input type="text" id="stms-description" name="description" value="<?php echo esc_attr( $tpl['description'] ); ?>">
						</div>
					</div>
					<div class="stms-field">
						<label for="stms-subject"><?php esc_html_e( 'Subject', 'store-messages' ); ?></label>
						<input type="text" id="stms-subject" name="subject" value="<?php echo esc_attr( $tpl['subject'] ); ?>" data-stms-live required>
					</div>
					<div class="stms-row">
						<div class="stms-field">
							<label for="stms-heading"><?php esc_html_e( 'Heading', 'store-messages' ); ?></label>
							<input type="text" id="stms-heading" name="heading" value="<?php echo esc_attr( $tpl['heading'] ); ?>" data-stms-live>
						</div>
						<div class="stms-field">
							<label for="stms-preheader"><?php esc_html_e( 'Preview text', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(shown after the subject in the inbox)', 'store-messages' ); ?></span></label>
							<input type="text" id="stms-preheader" name="preheader" value="<?php echo esc_attr( $tpl['preheader'] ); ?>" data-stms-live>
						</div>
					</div>
					<div class="stms-field">
						<label for="stms-body"><?php esc_html_e( 'Body (HTML)', 'store-messages' ); ?></label>
						<textarea id="stms-body" name="body" rows="22" class="code" data-stms-code data-stms-live><?php echo esc_textarea( $tpl['body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Goes inside the layout (Layout tab), under the heading. Use inline styles: many email apps ignore <style> blocks.', 'store-messages' ); ?></p>
					</div>
					<div class="stms-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save template', 'store-messages' ); ?></button>
						<?php if ( $id ) : ?>
							<button type="submit" class="button" name="stms_action" value="duplicate_template"><?php esc_html_e( 'Duplicate', 'store-messages' ); ?></button>
						<?php endif; ?>
						<span class="spacer"></span>
						<?php if ( $id && ! empty( $tpl['builtin'] ) && $edited ) : ?>
							<button type="submit" class="button-link" name="stms_action" value="reset_template" data-stms-confirm="reset"><?php esc_html_e( 'Reset to original', 'store-messages' ); ?></button>
						<?php endif; ?>
						<?php if ( $id && empty( $tpl['system'] ) ) : ?>
							<button type="submit" class="button-link stms-danger" name="stms_action" value="delete_template" data-stms-confirm="delete" formnovalidate><?php esc_html_e( 'Delete', 'store-messages' ); ?></button>
						<?php endif; ?>
					</div>
				</div>
				<?php self::reference(); ?>
			</div>
			<aside class="stms-editor__side"><?php self::preview_panel(); ?></aside>
		</form>
		<?php
	}

	/* ------------------------------------------------------------- layout -- */

	public static function tab_layout() {
		$s     = stms_settings();
		$b     = $s['brand'];
		$brand = stms_brand();
		$tpls  = Templates::all();
		?>
		<form method="post" class="stms-editor" data-stms-editor="layout">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_layout">
			<div class="stms-editor__main">
				<div class="stms-card">
					<h2><?php esc_html_e( 'Brand', 'store-messages' ); ?></h2>
					<p class="stms-hint"><?php esc_html_e( 'Used by the layout below through {logo}, {accent}, {background}, {surface} and {text}. Empty fields use your WooCommerce email settings.', 'store-messages' ); ?></p>
					<div class="stms-field">
						<label for="stms-logo"><?php esc_html_e( 'Logo', 'store-messages' ); ?></label>
						<div class="stms-copy">
							<input type="url" id="stms-logo" name="brand[logo]" value="<?php echo esc_attr( $b['logo'] ); ?>" placeholder="<?php echo esc_attr( $brand['logo'] ); ?>" data-stms-live>
							<button type="button" class="button" data-stms-media="#stms-logo"><?php esc_html_e( 'Choose image', 'store-messages' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'PNG or JPG work in every email app (Outlook does not show WebP or SVG).', 'store-messages' ); ?></p>
					</div>
					<div class="stms-row stms-row--5">
						<div class="stms-field">
							<label for="stms-logo-width"><?php esc_html_e( 'Logo width (px)', 'store-messages' ); ?></label>
							<input type="number" id="stms-logo-width" name="brand[logo_width]" min="40" max="560" value="<?php echo esc_attr( $b['logo_width'] ); ?>" data-stms-live>
						</div>
						<?php
						foreach ( [
							'accent'     => __( 'Accent', 'store-messages' ),
							'background' => __( 'Background', 'store-messages' ),
							'surface'    => __( 'Card', 'store-messages' ),
							'text'       => __( 'Text', 'store-messages' ),
						] as $key => $label ) :
							?>
							<div class="stms-field">
								<label for="stms-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
								<span class="stms-color"><input type="color" id="stms-<?php echo esc_attr( $key ); ?>" name="brand[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $brand[ $key ] ); ?>" data-stms-live><code><?php echo esc_html( $brand[ $key ] ); ?></code></span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="stms-field">
						<label for="stms-footer"><?php esc_html_e( 'Footer (HTML)', 'store-messages' ); ?></label>
						<textarea id="stms-footer" name="brand[footer]" rows="3" class="code large-text" placeholder="<?php echo esc_attr( Templates::default_footer() ); ?>" data-stms-live><?php echo esc_textarea( $b['footer'] ); ?></textarea>
					</div>
				</div>
				<div class="stms-card">
					<h2><?php esc_html_e( 'Layout (HTML)', 'store-messages' ); ?></h2>
					<p class="stms-hint"><?php echo wp_kses_post( __( 'The full email document every message is wrapped in. <code>{content}</code> is where the template body goes; <code>{heading}</code>, <code>{preheader}</code>, <code>{footer}</code> and every placeholder work here too.', 'store-messages' ) ); ?></p>
					<textarea name="layout" rows="30" class="code" data-stms-code data-stms-live><?php echo esc_textarea( Templates::layout() ); ?></textarea>
					<div class="stms-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save layout', 'store-messages' ); ?></button>
						<span class="spacer"></span>
						<?php if ( '' !== trim( (string) $s['layout'] ) ) : ?>
							<button type="submit" class="button-link" name="stms_action" value="reset_layout" data-stms-confirm="reset"><?php esc_html_e( 'Reset to the built-in layout', 'store-messages' ); ?></button>
						<?php endif; ?>
					</div>
				</div>
				<?php self::reference(); ?>
			</div>
			<aside class="stms-editor__side">
				<div class="stms-field">
					<label for="stms-layout-template"><?php esc_html_e( 'Preview with template', 'store-messages' ); ?></label>
					<select id="stms-layout-template" data-stms-preview-template>
						<?php foreach ( $tpls as $tid => $tpl ) : ?><option value="<?php echo esc_attr( $tid ); ?>"><?php echo esc_html( $tpl['name'] ); ?></option><?php endforeach; ?>
					</select>
				</div>
				<?php self::preview_panel(); ?>
			</aside>
		</form>
		<?php
	}

	/* ----------------------------------------------------------- carriers -- */

	public static function tab_carriers() {
		$carriers = Carriers::all();
		?>
		<form method="post">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_carriers">
			<div class="stms-card">
				<h2><?php esc_html_e( 'Carriers', 'store-messages' ); ?></h2>
				<p class="stms-hint"><?php echo wp_kses_post( __( 'Put <code>{number}</code> where the tracking number goes. On the order screen, pick a carrier and type the number, or just paste the full tracking link: the carrier is recognised from it.', 'store-messages' ) ); ?></p>
				<div class="stms-carriers" data-stms-rows>
					<div class="stms-carrier stms-carrier--head"><span><?php esc_html_e( 'Name', 'store-messages' ); ?></span><span><?php esc_html_e( 'Tracking link', 'store-messages' ); ?></span><span></span></div>
					<?php foreach ( array_values( $carriers ) as $i => $c ) : ?>
						<div class="stms-carrier" data-stms-row>
							<input type="text" name="carriers[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" aria-label="<?php esc_attr_e( 'Name', 'store-messages' ); ?>">
							<input type="url" name="carriers[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $c['url'] ); ?>" aria-label="<?php esc_attr_e( 'Tracking link', 'store-messages' ); ?>">
							<input type="hidden" name="carriers[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) array_keys( $carriers )[ $i ] ); ?>">
							<button type="button" class="button-link stms-danger" data-stms-remove-row aria-label="<?php esc_attr_e( 'Remove', 'store-messages' ); ?>">✕</button>
						</div>
					<?php endforeach; ?>
				</div>
				<template data-stms-row-template>
					<div class="stms-carrier" data-stms-row>
						<input type="text" name="carriers[__i__][name]" placeholder="<?php esc_attr_e( 'Carrier name', 'store-messages' ); ?>">
						<input type="url" name="carriers[__i__][url]" placeholder="https://example.com/track?number={number}">
						<input type="hidden" name="carriers[__i__][id]" value="">
						<button type="button" class="button-link stms-danger" data-stms-remove-row aria-label="<?php esc_attr_e( 'Remove', 'store-messages' ); ?>">✕</button>
					</div>
				</template>
				<div class="stms-actions">
					<button type="button" class="button" data-stms-add-row><?php esc_html_e( 'Add carrier', 'store-messages' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save carriers', 'store-messages' ); ?></button>
					<span class="spacer"></span>
					<button type="submit" class="button-link" name="stms_action" value="reset_carriers" data-stms-confirm="reset"><?php esc_html_e( 'Restore the built-in list', 'store-messages' ); ?></button>
				</div>
			</div>
		</form>
		<?php
	}

	/* ----------------------------------------------------------- activity -- */

	public static function tab_activity() {
		$activity  = (array) get_option( Mailer::ACTIVITY, [] );
		$templates = Templates::all();
		if ( ! $activity ) {
			echo '<div class="stms-card stms-empty"><strong>' . esc_html__( 'No messages sent yet', 'store-messages' ) . '</strong>' . esc_html__( 'Open an order and use the “Send a message” box, or add a “Note to customer”.', 'store-messages' ) . '</div>';
			return;
		}
		?>
		<table class="stms-table">
			<thead><tr><th><?php esc_html_e( 'Sent', 'store-messages' ); ?></th><th><?php esc_html_e( 'Order', 'store-messages' ); ?></th><th><?php esc_html_e( 'Message', 'store-messages' ); ?></th><th><?php esc_html_e( 'To', 'store-messages' ); ?></th><th><?php esc_html_e( 'Status', 'store-messages' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $activity as $row ) : ?>
				<?php $order = wc_get_order( $row['order'] ?? 0 ); ?>
				<tr>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ); ?></td>
					<td><?php if ( $order ) : ?><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $row['number'] ?? $row['order'] ); ?></a><?php else : ?>#<?php echo esc_html( (string) ( $row['number'] ?? '' ) ); ?><?php endif; ?></td>
					<td><strong><?php echo esc_html( $templates[ $row['template'] ]['name'] ?? ( $row['name'] ?? $row['template'] ) ); ?></strong><br><span class="stms-muted"><?php echo esc_html( $row['subject'] ); ?></span><?php if ( ! empty( $row['tracking'] ) ) : ?><br><a href="<?php echo esc_url( $row['tracking'] ); ?>" target="_blank" rel="noopener" class="stms-muted"><?php esc_html_e( 'Tracking link', 'store-messages' ); ?> ↗</a><?php endif; ?></td>
					<td><?php echo esc_html( $row['to'] ); ?></td>
					<td><?php echo ! empty( $row['ok'] ) ? '<span class="stms-pill stms-pill--ok">' . esc_html__( 'Sent', 'store-messages' ) . '</span>' : '<span class="stms-pill stms-pill--warn" title="' . esc_attr( $row['error'] ?? '' ) . '">' . esc_html__( 'Failed', 'store-messages' ) . '</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ----------------------------------------------------------- settings -- */

	public static function tab_settings() {
		$s = stms_settings();
		?>
		<form method="post">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_settings">
			<div class="stms-card">
				<h2><?php esc_html_e( 'Sender', 'store-messages' ); ?></h2>
				<p class="stms-hint"><?php esc_html_e( 'Leave empty to use WooCommerce’s email settings. Messages are sent through WordPress, so your SMTP or email-delivery plugin applies.', 'store-messages' ); ?></p>
				<div class="stms-row">
					<div class="stms-field"><label for="stms-from-name"><?php esc_html_e( 'From name', 'store-messages' ); ?></label><input type="text" id="stms-from-name" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( stms_from_name() ); ?>"></div>
					<div class="stms-field"><label for="stms-from-email"><?php esc_html_e( 'From address', 'store-messages' ); ?></label><input type="email" id="stms-from-email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( stms_from_email() ); ?>"></div>
				</div>
				<div class="stms-row">
					<div class="stms-field"><label for="stms-reply-to"><?php esc_html_e( 'Replies go to', 'store-messages' ); ?></label><input type="email" id="stms-reply-to" name="reply_to" value="<?php echo esc_attr( $s['reply_to'] ); ?>" placeholder="<?php echo esc_attr( stms_from_email() ); ?>"></div>
					<div class="stms-field"><label for="stms-bcc"><?php esc_html_e( 'Send a copy to', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(optional)', 'store-messages' ); ?></span></label><input type="email" id="stms-bcc" name="bcc" value="<?php echo esc_attr( $s['bcc'] ); ?>"></div>
				</div>
			</div>
			<div class="stms-card">
				<h2><?php esc_html_e( 'WooCommerce', 'store-messages' ); ?></h2>
				<div class="stms-field stms-switches">
					<label><input type="checkbox" name="note_emails" value="1" <?php checked( ! empty( $s['note_emails'] ) ); ?>> <span><strong><?php esc_html_e( 'Send “Note to customer” with this layout', 'store-messages' ); ?></strong><br><span class="stms-muted"><?php esc_html_e( 'Replaces WooCommerce’s note email. A tracking link pasted in the note becomes a “Track your package” button. Edit the wording in the “Note to customer” template.', 'store-messages' ); ?></span></span></label>
					<label><input type="checkbox" name="skip_completed" value="1" <?php checked( ! empty( $s['skip_completed'] ) ); ?>> <span><strong><?php esc_html_e( 'One email instead of two', 'store-messages' ); ?></strong><br><span class="stms-muted"><?php esc_html_e( 'When a message also marks the order Completed, don’t send WooCommerce’s “Order complete” email on top of it.', 'store-messages' ); ?></span></span></label>
					<label><input type="checkbox" name="show_tracking" value="1" <?php checked( ! empty( $s['show_tracking'] ) ); ?>> <span><strong><?php esc_html_e( 'Show tracking to the customer', 'store-messages' ); ?></strong><br><span class="stms-muted"><?php esc_html_e( 'On the order page in My account, and in WooCommerce’s own customer emails.', 'store-messages' ); ?></span></span></label>
				</div>
				<div class="stms-field">
					<label for="stms-review"><?php esc_html_e( 'Review link', 'store-messages' ); ?></label>
					<input type="url" id="stms-review" name="review_url" value="<?php echo esc_attr( $s['review_url'] ); ?>" placeholder="https://g.page/r/…/review" class="regular-text">
					<p class="description"><?php esc_html_e( 'Where {review_url} points, for example your Google review page. The Review request template shows its button only when this is set.', 'store-messages' ); ?></p>
				</div>
			</div>
			<div class="stms-card">
				<h2><?php esc_html_e( 'Updates', 'store-messages' ); ?></h2>
				<div class="stms-field stms-switches">
					<label><input type="checkbox" name="updates" value="1" <?php checked( ! empty( $s['updates'] ) ); ?>> <span><strong><?php esc_html_e( 'Check GitHub for new versions', 'store-messages' ); ?></strong><br><span class="stms-muted"><?php esc_html_e( 'New releases appear on Dashboard → Updates with the usual “Update now” button.', 'store-messages' ); ?></span></span></label>
				</div>
				<p class="stms-card-row">
					<span><?php printf( esc_html__( 'Installed: %s', 'store-messages' ), '<strong>' . esc_html( STMS_VERSION ) . '</strong>' ); ?> <span data-stms-update-status class="stms-muted"><?php $c = Updater::cached(); echo $c ? esc_html( sprintf( __( 'Latest release: %s', 'store-messages' ), $c['version'] ) ) : ''; ?></span></span>
					<button type="button" class="button" data-stms-check-updates><?php esc_html_e( 'Check now', 'store-messages' ); ?></button>
				</p>
			</div>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'store-messages' ); ?></button></p>
		</form>
		<?php
	}

	/* -------------------------------------------------------------- about -- */

	public static function tab_about() {
		$repo = 'https://github.com/' . Updater::repository();
		?>
		<div class="stms-card">
			<div class="stms-about-hero">
				<span class="dashicons dashicons-email-alt stms-about-icon" aria-hidden="true"></span>
				<div>
					<h2><?php esc_html_e( 'Store Messages', 'store-messages' ); ?> <?php echo esc_html( STMS_VERSION ); ?></h2>
					<p class="stms-hint"><?php esc_html_e( 'Free and open source (GPL-2.0-or-later). No accounts, no tracking pixels, no upsells.', 'store-messages' ); ?></p>
				</div>
			</div>
			<ul class="stms-list">
				<li><?php esc_html_e( 'Send a message from any order: pick a template, add tracking or a personal note, preview, send.', 'store-messages' ); ?></li>
				<li><?php esc_html_e( 'Every send is recorded as a private order note and on the Activity tab.', 'store-messages' ); ?></li>
				<li><?php esc_html_e( 'Templates and layout are plain HTML with placeholders and {if} blocks; nothing is locked.', 'store-messages' ); ?></li>
				<li><?php esc_html_e( 'Developers: filters stms_placeholders, stms_email_html, stms_email_headers and stms_update_repository; action stms_sent.', 'store-messages' ); ?></li>
			</ul>
			<p class="stms-actions"><a class="button" href="<?php echo esc_url( $repo ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub', 'store-messages' ); ?></a> <a class="button" href="<?php echo esc_url( $repo . '/issues' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Report an issue', 'store-messages' ); ?></a></p>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- saving -- */

	public static function handle_post() {
		if ( empty( $_POST['stms_action'] ) || ! isset( $_POST['stms_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! wp_verify_nonce( sanitize_key( $_POST['stms_nonce'] ), 'stms_save' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'store-messages' ) );
		}
		$post   = wp_unslash( $_POST );
		$action = sanitize_key( $post['stms_action'] );
		$id     = sanitize_key( $post['id'] ?? '' );
		$tab    = 'templates';
		$args   = [];

		switch ( $action ) {
			case 'save_template':
			case 'duplicate_template':
				$data = self::template_from_post( $post );
				if ( 'duplicate_template' === $action || '' === $id ) {
					$data['name'] = 'duplicate_template' === $action ? sprintf( __( '%s (copy)', 'store-messages' ), $data['name'] ) : $data['name'];
					$id           = Templates::new_id( $data['name'] );
				}
				Templates::save( $id, $data );
				$args = [ 'edit' => $id ];
				self::flash( 'duplicate_template' === $action ? __( 'Template duplicated. You are now editing the copy.', 'store-messages' ) : __( 'Template saved.', 'store-messages' ) );
				break;

			case 'reset_template':
				Templates::reset( $id );
				$args = [ 'edit' => $id ];
				self::flash( __( 'Template reset to the original text.', 'store-messages' ) );
				break;

			case 'delete_template':
				Templates::delete( $id ) ? self::flash( __( 'Template deleted.', 'store-messages' ) ) : self::flash( __( 'This template cannot be deleted.', 'store-messages' ), 'error' );
				break;

			case 'restore_templates':
				Templates::restore_deleted();
				self::flash( __( 'Built-in templates restored.', 'store-messages' ) );
				break;

			case 'save_layout':
				$layout = (string) ( $post['layout'] ?? '' );
				stms_update_settings( [
					'brand'  => self::brand_from_post( $post ),
					// Saving the untouched built-in layout keeps following future improvements to it.
					'layout' => self::same_html( $layout, Templates::default_layout() ) ? '' : self::clean_html( $layout ),
				] );
				$tab = 'layout';
				self::flash( false === strpos( $layout, '{content}' ) ? __( 'Layout saved, but it has no {content} placeholder: messages will be added at the end.', 'store-messages' ) : __( 'Layout saved.', 'store-messages' ), false === strpos( $layout, '{content}' ) ? 'warning' : 'success' );
				break;

			case 'reset_layout':
				stms_update_settings( [ 'layout' => '' ] );
				$tab = 'layout';
				self::flash( __( 'Built-in layout restored.', 'store-messages' ) );
				break;

			case 'save_carriers':
				$carriers = [];
				foreach ( (array) ( $post['carriers'] ?? [] ) as $row ) {
					$name = sanitize_text_field( $row['name'] ?? '' );
					$url  = esc_url_raw( str_replace( '{number}', 'STMSNUMBER', (string) ( $row['url'] ?? '' ) ) );
					if ( '' === $name || '' === $url ) {
						continue;
					}
					$cid              = sanitize_key( $row['id'] ?? '' ) ?: sanitize_key( sanitize_title( $name ) );
					$carriers[ $cid ] = [ 'name' => $name, 'url' => str_replace( 'STMSNUMBER', '{number}', $url ) ];
				}
				Carriers::save( $carriers );
				$tab = 'carriers';
				self::flash( __( 'Carriers saved.', 'store-messages' ) );
				break;

			case 'reset_carriers':
				delete_option( Carriers::OPTION );
				$tab = 'carriers';
				self::flash( __( 'Built-in carrier list restored.', 'store-messages' ) );
				break;

			case 'save_settings':
				stms_update_settings( [
					'from_name'      => sanitize_text_field( $post['from_name'] ?? '' ),
					'from_email'     => sanitize_email( $post['from_email'] ?? '' ),
					'reply_to'       => sanitize_email( $post['reply_to'] ?? '' ),
					'bcc'            => sanitize_email( $post['bcc'] ?? '' ),
					'note_emails'    => empty( $post['note_emails'] ) ? 0 : 1,
					'skip_completed' => empty( $post['skip_completed'] ) ? 0 : 1,
					'show_tracking'  => empty( $post['show_tracking'] ) ? 0 : 1,
					'review_url'     => esc_url_raw( $post['review_url'] ?? '' ),
					'updates'        => empty( $post['updates'] ) ? 0 : 1,
				] );
				$tab = 'settings';
				self::flash( __( 'Settings saved.', 'store-messages' ) );
				break;
		}
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	private static function template_from_post( array $post ) {
		return [
			'name'        => sanitize_text_field( $post['name'] ?? '' ) ?: __( 'Untitled message', 'store-messages' ),
			'description' => sanitize_text_field( $post['description'] ?? '' ),
			'subject'     => sanitize_text_field( $post['subject'] ?? '' ),
			'heading'     => self::clean_inline( $post['heading'] ?? '' ),
			'preheader'   => sanitize_text_field( $post['preheader'] ?? '' ),
			'body'        => self::clean_html( $post['body'] ?? '' ),
		];
	}

	/** {if}/{else} in one-line fields would be mangled by sanitize_text_field's tag stripping; keep text only. */
	private static function clean_inline( $text ) {
		return trim( wp_strip_all_tags( (string) $text ) );
	}

	/**
	 * Template HTML is the store's own: administrators (unfiltered_html) keep it
	 * exactly; other shop managers are limited to what posts allow plus email layout tags.
	 */
	public static function clean_html( $html ) {
		$html = (string) $html;
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $html;
		}
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( [ 'html' => [ 'lang' => 1 ], 'head' => [], 'body' => [ 'style' => 1 ], 'meta' => [ 'charset' => 1, 'name' => 1, 'content' => 1 ], 'title' => [], 'style' => [], 'center' => [] ] as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? [], $attrs );
		}
		foreach ( [ 'table', 'td', 'th', 'tr', 'img', 'a', 'div', 'span', 'p', 'h1', 'h2', 'h3' ] as $tag ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? [], [ 'style' => 1, 'class' => 1, 'role' => 1, 'width' => 1, 'height' => 1, 'align' => 1, 'valign' => 1, 'bgcolor' => 1, 'cellpadding' => 1, 'cellspacing' => 1, 'border' => 1 ] );
		}
		return wp_kses( $html, $allowed );
	}

	private static function same_html( $a, $b ) {
		$norm = static fn( $s ) => preg_replace( '/\s+/', ' ', trim( str_replace( "\r", '', (string) $s ) ) );
		return $norm( $a ) === $norm( $b );
	}

	private static function brand_from_post( array $post ) {
		$in       = (array) ( $post['brand'] ?? [] );
		$defaults = stms_default_settings()['brand'];
		$woo      = stms_brand();
		$color    = static fn( $k ) => sanitize_hex_color( $in[ $k ] ?? '' ) ?: $defaults[ $k ];
		$accent   = sanitize_hex_color( $in['accent'] ?? '' ) ?: '';
		return [
			'logo'       => esc_url_raw( $in['logo'] ?? '' ),
			'logo_width' => max( 40, min( 560, absint( $in['logo_width'] ?? 160 ) ) ),
			// Unchanged from WooCommerce's colour: keep following it.
			'accent'     => strtolower( $accent ) === strtolower( (string) get_option( 'woocommerce_email_base_color' ) ) ? '' : $accent,
			'background' => $color( 'background' ),
			'surface'    => $color( 'surface' ),
			'text'       => $color( 'text' ),
			'footer'     => self::clean_html( $in['footer'] ?? '' ),
		];
	}

	/* --------------------------------------------------------------- ajax -- */

	private static function check_ajax() {
		check_ajax_referer( 'stms_ajax', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Sorry, you are not allowed to do that.', 'store-messages' ) ], 403 );
		}
	}

	/**
	 * Template and context from an AJAX request: either the editor's unsaved
	 * fields, or a saved template id; an order id or sample data.
	 */
	private static function request_mail( array $req ) {
		if ( isset( $req['body'] ) ) {
			$tpl = self::template_from_post( $req );
		} else {
			$tpl = Templates::get( sanitize_key( $req['template'] ?? '' ) ) ?: [];
		}
		$extra = [];
		if ( isset( $req['tracking'] ) ) {
			$extra['tracking'] = \StoreMessages\Carriers::resolve( sanitize_text_field( $req['tracking'] ), sanitize_key( $req['carrier'] ?? '' ) );
		}
		if ( isset( $req['message'] ) ) {
			$extra['message'] = wp_kses_post( $req['message'] );
		}
		if ( ! empty( $tpl['system'] ) || ( isset( $req['body'] ) && false !== strpos( (string) $req['body'], '{note}' ) ) ) {
			$extra['note'] = __( 'Hi Alex, you can track your package using this link.', 'store-messages' );
		}
		$order = wc_get_order( absint( $req['order'] ?? 0 ) ) ?: null;
		$editing = isset( $req['body'] ) || isset( $req['layout'] );
		if ( $editing && ! isset( $extra['tracking'] ) && ( ! $order || ! \StoreMessages\Tracking::get( $order ) ) ) {
			// Editors preview a real order before it ships: show sample tracking so the tracking block is visible.
			$extra['tracking'] = \StoreMessages\Carriers::resolve( '996011988401390957', (string) array_key_first( \StoreMessages\Carriers::all() ) );
		}
		return [ $tpl, $order, $extra ];
	}

	public static function ajax_preview() {
		self::check_ajax();
		$req = wp_unslash( $_POST );
		[ $tpl, $order, $extra ] = self::request_mail( $req );
		if ( isset( $req['brand'] ) ) {
			// Live brand edits on the Layout tab: preview them without saving.
			$brand = self::brand_from_post( $req );
			add_filter( 'option_' . STMS_SETTINGS, static function ( $v ) use ( $brand ) {
				$v          = is_array( $v ) ? $v : [];
				$v['brand'] = $brand;
				return $v;
			} );
			add_filter( 'default_option_' . STMS_SETTINGS, static fn() => [ 'brand' => $brand ] );
			stms_settings( true );
		}
		$ctx  = $order ? Renderer::context( $order, $extra ) : Renderer::sample_context( $extra );
		$mail = Renderer::render( $tpl, $ctx, isset( $req['layout'] ) ? (string) $req['layout'] : null );
		wp_send_json_success( [ 'subject' => $mail['subject'], 'html' => $mail['html'] ] );
	}

	public static function ajax_test() {
		self::check_ajax();
		$req = wp_unslash( $_POST );
		[ $tpl, $order, $extra ] = self::request_mail( $req );
		$result = Mailer::send( $order, $tpl, $extra + [ 'to' => sanitize_email( $req['to'] ?? '' ), 'test' => true ] );
		is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( [ 'message' => sprintf( __( 'Test sent to %s', 'store-messages' ), sanitize_email( $req['to'] ?? '' ) ) ] );
	}

	public static function ajax_check_updates() {
		self::check_ajax();
		$latest = Updater::latest( true );
		delete_site_transient( 'update_plugins' );
		if ( ! $latest ) {
			wp_send_json_error( [ 'message' => __( 'Could not reach GitHub. Try again later.', 'store-messages' ) ] );
		}
		$newer = version_compare( $latest['version'], STMS_VERSION, '>' );
		wp_send_json_success( [
			'message' => $newer ? sprintf( __( 'Version %s is available.', 'store-messages' ), $latest['version'] ) : __( 'You have the latest version.', 'store-messages' ),
			'update'  => $newer ? Updater::update_url() : '',
		] );
	}
}
