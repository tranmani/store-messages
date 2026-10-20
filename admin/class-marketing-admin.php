<?php
/**
 * Admin tabs for marketing: Campaigns, Subscribers and Sign-up.
 *
 * @package StoreMessages
 */

namespace StoreMessages\Admin;

use StoreMessages\Campaigns;
use StoreMessages\Integrations;
use StoreMessages\Language;
use StoreMessages\Marketing;
use StoreMessages\Signup;
use StoreMessages\Subscribers;
use StoreMessages\Templates;

defined( 'ABSPATH' ) || exit;

class Marketing_Admin {

	public static function init() {
		add_action( 'wp_ajax_stms_campaign_preview', [ __CLASS__, 'ajax_preview' ] );
		add_action( 'wp_ajax_stms_campaign_test', [ __CLASS__, 'ajax_test' ] );
		add_action( 'wp_ajax_stms_audience_count', [ __CLASS__, 'ajax_audience' ] );
		add_action( 'admin_post_stms_export_subscribers', [ __CLASS__, 'export' ] );
	}

	private static function url( $tab, $args = [] ) {
		return Admin::url( $tab, $args );
	}

	private static function date( $mysql_gmt ) {
		return $mysql_gmt ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $mysql_gmt . ' UTC' ) ) : '—';
	}

	private static function status_pill( $status, array $labels ) {
		$kind = [ 'subscribed' => 'ok', 'sent' => 'ok', 'sending' => 'accent', 'scheduled' => 'accent', 'unconfirmed' => 'warn', 'draft' => 'off', 'unsubscribed' => 'off', 'cancelled' => 'off' ][ $status ] ?? 'off';
		return '<span class="stms-pill stms-pill--' . esc_attr( $kind ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function audience_label( array $audience ) {
		$parts = [ __( 'All subscribers', 'store-messages' ) ];
		if ( 'customers' === $audience['buyers'] ) {
			$parts = [ __( 'Subscribers who have ordered', 'store-messages' ) ];
		} elseif ( 'new' === $audience['buyers'] ) {
			$parts = [ __( 'Subscribers who have not ordered yet', 'store-messages' ) ];
		}
		if ( '' !== $audience['lang'] ) {
			$parts[] = strtoupper( $audience['lang'] );
		}
		return implode( ' · ', $parts );
	}

	/* ---------------------------------------------------------- campaigns -- */

	public static function tab_campaigns() {
		$edit = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $edit ) {
			self::campaign_editor( 'new' === $edit ? 0 : absint( $edit ) );
			return;
		}
		$camps  = Campaigns::all();
		$counts = Subscribers::counts();
		?>
		<div class="stms-toolbar">
			<p class="stms-hint stms-hint--inline"><?php printf( esc_html( _n( 'Newsletters to your subscribers. %s person is subscribed.', 'Newsletters to your subscribers. %s people are subscribed.', $counts['subscribed'], 'store-messages' ) ), '<strong>' . (int) $counts['subscribed'] . '</strong>' ); ?></p>
			<span class="spacer"></span>
			<a class="button button-primary" href="<?php echo esc_url( self::url( 'campaigns', [ 'edit' => 'new' ] ) ); ?>"><?php esc_html_e( 'New campaign', 'store-messages' ); ?></a>
		</div>
		<?php if ( ! $camps ) : ?>
			<div class="stms-card stms-empty"><strong><?php esc_html_e( 'No campaigns yet', 'store-messages' ); ?></strong><?php esc_html_e( 'Write a newsletter in the same layout as your other emails, send yourself a test, then send it to everyone or schedule it.', 'store-messages' ); ?></div>
			<?php
			return;
		endif;
		?>
		<table class="stms-table">
			<thead><tr><th><?php esc_html_e( 'Campaign', 'store-messages' ); ?></th><th><?php esc_html_e( 'Status', 'store-messages' ); ?></th><th><?php esc_html_e( 'Sent', 'store-messages' ); ?></th><th><?php esc_html_e( 'Date', 'store-messages' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $camps as $c ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( 'campaigns', [ 'edit' => $c['id'] ] ) ); ?>"><strong><?php echo esc_html( $c['name'] ?: __( '(no name)', 'store-messages' ) ); ?></strong></a><br><span class="stms-muted"><?php echo esc_html( $c['subject'] ); ?></span></td>
					<td><?php echo self::status_pill( $c['status'], Campaigns::statuses() ); // phpcs:ignore ?></td>
					<td><?php echo 'draft' === $c['status'] ? '—' : esc_html( sprintf( '%d / %d', $c['sent'], $c['total'] ) ) . ( $c['failed'] ? ' <span class="stms-pill stms-pill--warn">' . esc_html( sprintf( _n( '%d failed', '%d failed', $c['failed'], 'store-messages' ), $c['failed'] ) ) . '</span>' : '' ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( self::date( $c['finished_at'] ?: ( $c['scheduled_at'] ?: $c['updated_at'] ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function campaign_editor( $id ) {
		$c = $id ? Campaigns::get( $id ) : Campaigns::blank();
		if ( ! $c ) {
			echo '<div class="stms-card stms-empty"><strong>' . esc_html__( 'Campaign not found', 'store-messages' ) . '</strong><a href="' . esc_url( self::url( 'campaigns' ) ) . '">' . esc_html__( 'Back to campaigns', 'store-messages' ) . '</a></div>';
			return;
		}
		$locked = 'draft' !== $c['status'];
		$count  = count( Subscribers::audience_ids( $c['audience'] ) );
		$langs  = Language::all();
		?>
		<p class="stms-back"><a href="<?php echo esc_url( self::url( 'campaigns' ) ); ?>">← <?php esc_html_e( 'All campaigns', 'store-messages' ); ?></a></p>
		<?php if ( $locked ) : ?>
			<div class="stms-card">
				<div class="stms-card-row">
					<div>
						<h2><?php echo esc_html( $c['name'] ); ?> <?php echo self::status_pill( $c['status'], Campaigns::statuses() ); // phpcs:ignore ?></h2>
						<p class="stms-hint" style="margin:0"><?php echo esc_html( self::audience_label( $c['audience'] ) ); ?> · <?php printf( esc_html__( '%1$d of %2$d sent', 'store-messages' ), (int) $c['sent'], (int) $c['total'] ); ?><?php echo $c['failed'] ? ' · ' . esc_html( sprintf( _n( '%d failed', '%d failed', $c['failed'], 'store-messages' ), $c['failed'] ) ) : ''; ?></p>
						<p class="stms-muted" style="margin:6px 0 0">
							<?php if ( 'scheduled' === $c['status'] ) : ?>
								<?php printf( esc_html__( 'Starts %s.', 'store-messages' ), esc_html( self::date( $c['scheduled_at'] ) ) ); ?>
							<?php elseif ( 'sending' === $c['status'] ) : ?>
								<?php printf( esc_html__( 'Sending %1$d emails every %2$d minutes.', 'store-messages' ), (int) stms_settings()['sending']['batch'], (int) stms_settings()['sending']['interval'] ); ?>
							<?php elseif ( $c['finished_at'] ) : ?>
								<?php printf( esc_html__( 'Finished %s.', 'store-messages' ), esc_html( self::date( $c['finished_at'] ) ) ); ?>
							<?php endif; ?>
						</p>
					</div>
					<form method="post" class="stms-actions">
						<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
						<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
						<?php if ( in_array( $c['status'], [ 'scheduled', 'sending' ], true ) ) : ?>
							<button class="button" name="stms_action" value="cancel_campaign" data-stms-confirm="cancel"><?php esc_html_e( 'Stop sending', 'store-messages' ); ?></button>
						<?php endif; ?>
						<?php if ( 'cancelled' === $c['status'] ) : ?>
							<button class="button" name="stms_action" value="reopen_campaign"><?php esc_html_e( 'Back to draft', 'store-messages' ); ?></button>
						<?php endif; ?>
						<button class="button" name="stms_action" value="duplicate_campaign"><?php esc_html_e( 'Duplicate', 'store-messages' ); ?></button>
						<?php if ( in_array( $c['status'], [ 'sent', 'cancelled' ], true ) ) : ?>
							<button class="button-link stms-danger" name="stms_action" value="delete_campaign" data-stms-confirm="delete"><?php esc_html_e( 'Delete', 'store-messages' ); ?></button>
						<?php endif; ?>
					</form>
				</div>
				<?php foreach ( Campaigns::failures( $c['id'] ) as $f ) : ?>
					<p class="stms-muted" style="margin:8px 0 0"><?php echo esc_html( $f->email . ': ' . $f->error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<form method="post" class="stms-editor" data-stms-editor="campaign" data-stms-preview-action="stms_campaign_preview" data-stms-test-action="stms_campaign_test">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_campaign">
			<input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
			<div class="stms-editor__main">
				<fieldset class="stms-card" <?php disabled( $locked ); ?>>
					<div class="stms-row">
						<div class="stms-field">
							<label for="stms-c-name"><?php esc_html_e( 'Name', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(only you see this)', 'store-messages' ); ?></span></label>
							<input type="text" id="stms-c-name" name="name" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Spring offer', 'store-messages' ); ?>">
						</div>
						<div class="stms-field">
							<label for="stms-c-subject"><?php esc_html_e( 'Subject', 'store-messages' ); ?></label>
							<input type="text" id="stms-c-subject" name="subject" value="<?php echo esc_attr( $c['subject'] ); ?>" data-stms-live required>
						</div>
					</div>
					<div class="stms-row">
						<div class="stms-field">
							<label for="stms-c-heading"><?php esc_html_e( 'Heading', 'store-messages' ); ?></label>
							<input type="text" id="stms-c-heading" name="heading" value="<?php echo esc_attr( $c['heading'] ); ?>" data-stms-live>
						</div>
						<div class="stms-field">
							<label for="stms-c-preheader"><?php esc_html_e( 'Preview text', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(shown after the subject in the inbox)', 'store-messages' ); ?></span></label>
							<input type="text" id="stms-c-preheader" name="preheader" value="<?php echo esc_attr( $c['preheader'] ); ?>" data-stms-live>
						</div>
					</div>
					<div class="stms-field">
						<label for="stms-c-body"><?php esc_html_e( 'Message (HTML)', 'store-messages' ); ?></label>
						<textarea id="stms-c-body" name="body" rows="22" class="code" data-stms-code data-stms-live><?php echo esc_textarea( $c['body'] ); ?></textarea>
						<p class="description"><?php echo wp_kses_post( __( 'Goes inside your layout. <code>{bestsellers}</code> or <code>{products:12,34}</code> add product cards; <code>{coupon_code}</code> gives each subscriber their personal code. An unsubscribe link is added to the footer automatically.', 'store-messages' ) ); ?></p>
					</div>
					<div class="stms-row">
						<div class="stms-field">
							<label for="stms-c-buyers"><?php esc_html_e( 'Send to', 'store-messages' ); ?></label>
							<select id="stms-c-buyers" name="audience[buyers]" data-stms-audience>
								<option value="" <?php selected( $c['audience']['buyers'], '' ); ?>><?php esc_html_e( 'All subscribers', 'store-messages' ); ?></option>
								<option value="customers" <?php selected( $c['audience']['buyers'], 'customers' ); ?>><?php esc_html_e( 'Subscribers who have ordered', 'store-messages' ); ?></option>
								<option value="new" <?php selected( $c['audience']['buyers'], 'new' ); ?>><?php esc_html_e( 'Subscribers who have not ordered yet', 'store-messages' ); ?></option>
							</select>
						</div>
						<div class="stms-field">
							<label for="stms-c-lang"><?php esc_html_e( 'Language', 'store-messages' ); ?></label>
							<select id="stms-c-lang" name="audience[lang]" data-stms-audience>
								<option value=""><?php esc_html_e( 'All languages', 'store-messages' ); ?></option>
								<?php foreach ( $langs as $code ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $c['audience']['lang'], $code ); ?>><?php echo esc_html( strtoupper( $code ) ); ?></option><?php endforeach; ?>
							</select>
						</div>
					</div>
					<p class="stms-muted" data-stms-audience-count><?php printf( esc_html( _n( '%d subscriber will receive it.', '%d subscribers will receive it.', $count, 'store-messages' ) ), (int) $count ); ?></p>
					<?php if ( ! $locked ) : ?>
						<div class="stms-actions">
							<button type="submit" class="button"><?php esc_html_e( 'Save draft', 'store-messages' ); ?></button>
							<span class="spacer"></span>
							<label class="screen-reader-text" for="stms-c-when"><?php esc_html_e( 'Send at', 'store-messages' ); ?></label>
							<input type="datetime-local" id="stms-c-when" name="when" min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>">
							<button type="submit" class="button" name="stms_action" value="schedule_campaign" data-stms-confirm="schedule"><?php esc_html_e( 'Schedule', 'store-messages' ); ?></button>
							<button type="submit" class="button button-primary" name="stms_action" value="send_campaign" data-stms-confirm="campaign"><?php esc_html_e( 'Send now', 'store-messages' ); ?></button>
						</div>
					<?php endif; ?>
				</fieldset>
				<?php Admin::reference(); ?>
			</div>
			<aside class="stms-editor__side"><?php Admin::preview_panel( true, false ); ?></aside>
		</form>
		<?php
	}

	/* -------------------------------------------------------- subscribers -- */

	public static function tab_subscribers() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		[ $rows, $total ] = Subscribers::query( [ 'status' => $status, 'search' => $search, 'page' => $paged, 'per_page' => 50 ] );
		$counts   = Subscribers::counts();
		$statuses = Subscribers::statuses();
		$mailpoet = Subscribers::mailpoet_counts();
		?>
		<div class="stms-toolbar">
			<a href="<?php echo esc_url( self::url( 'subscribers' ) ); ?>" class="stms-filter<?php echo '' === $status ? ' is-active' : ''; ?>"><?php esc_html_e( 'All', 'store-messages' ); ?> <span><?php echo (int) array_sum( $counts ); ?></span></a>
			<?php foreach ( $statuses as $key => $label ) : ?>
				<a href="<?php echo esc_url( self::url( 'subscribers', [ 'status' => $key ] ) ); ?>" class="stms-filter<?php echo $status === $key ? ' is-active' : ''; ?>"><?php echo esc_html( $label ); ?> <span><?php echo (int) $counts[ $key ]; ?></span></a>
			<?php endforeach; ?>
			<span class="spacer"></span>
			<form method="get" class="stms-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>">
				<input type="hidden" name="tab" value="subscribers">
				<label class="screen-reader-text" for="stms-s"><?php esc_html_e( 'Search subscribers', 'store-messages' ); ?></label>
				<input type="search" id="stms-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Email or name', 'store-messages' ); ?>">
				<button class="button"><?php esc_html_e( 'Search', 'store-messages' ); ?></button>
			</form>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stms_export_subscribers' ), 'stms_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'store-messages' ); ?></a>
		</div>

		<?php if ( $rows ) : ?>
			<table class="stms-table">
				<thead><tr><th><?php esc_html_e( 'Email', 'store-messages' ); ?></th><th><?php esc_html_e( 'Name', 'store-messages' ); ?></th><th><?php esc_html_e( 'Status', 'store-messages' ); ?></th><th><?php esc_html_e( 'Signed up through', 'store-messages' ); ?></th><th><?php esc_html_e( 'Language', 'store-messages' ); ?></th><th><?php esc_html_e( 'Added', 'store-messages' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'store-messages' ); ?></span></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->email ); ?><?php echo '' !== $r->coupon ? '<br><code class="stms-muted">' . esc_html( $r->coupon ) . '</code>' : ''; ?></td>
						<td><?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ?: '—' ); ?></td>
						<td><?php echo self::status_pill( $r->status, $statuses ); // phpcs:ignore ?></td>
						<td><?php echo esc_html( Subscribers::source_label( $r->source ) ); ?></td>
						<td><?php echo esc_html( $r->lang ? strtoupper( $r->lang ) : '—' ); ?></td>
						<td><?php echo esc_html( self::date( $r->created_at ) ); ?></td>
						<td>
							<form method="post" class="stms-row-actions">
								<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
								<?php if ( Subscribers::SUBSCRIBED === $r->status ) : ?>
									<button class="button-link" name="stms_action" value="unsubscribe_subscriber"><?php esc_html_e( 'Unsubscribe', 'store-messages' ); ?></button>
								<?php else : ?>
									<button class="button-link" name="stms_action" value="resubscribe_subscriber" data-stms-confirm="resubscribe"><?php esc_html_e( 'Subscribe', 'store-messages' ); ?></button>
								<?php endif; ?>
								<button class="button-link stms-danger" name="stms_action" value="delete_subscriber" data-stms-confirm="delete"><?php esc_html_e( 'Delete', 'store-messages' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / 50 );
			if ( $pages > 1 ) {
				echo '<p class="stms-pages">' . wp_kses_post( paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ] ) ) . '</p>';
			}
			?>
		<?php else : ?>
			<div class="stms-card stms-empty"><strong><?php esc_html_e( 'No subscribers here yet', 'store-messages' ); ?></strong><?php esc_html_e( 'Switch on the checkout checkbox or the sign-up popup on the Sign-up tab, or add someone below.', 'store-messages' ); ?></div>
		<?php endif; ?>

		<div class="stms-row stms-row--top">
			<form method="post" class="stms-card">
				<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
				<input type="hidden" name="stms_action" value="add_subscriber">
				<h2><?php esc_html_e( 'Add a subscriber', 'store-messages' ); ?></h2>
				<p class="stms-hint"><?php esc_html_e( 'Only add people who agreed to receive your newsletter. No welcome email is sent.', 'store-messages' ); ?></p>
				<div class="stms-field"><label for="stms-add-email"><?php esc_html_e( 'Email', 'store-messages' ); ?></label><input type="email" id="stms-add-email" name="email" required></div>
				<div class="stms-row">
					<div class="stms-field"><label for="stms-add-first"><?php esc_html_e( 'First name', 'store-messages' ); ?></label><input type="text" id="stms-add-first" name="first_name"></div>
					<div class="stms-field"><label for="stms-add-last"><?php esc_html_e( 'Last name', 'store-messages' ); ?></label><input type="text" id="stms-add-last" name="last_name"></div>
				</div>
				<button class="button button-primary"><?php esc_html_e( 'Add', 'store-messages' ); ?></button>
			</form>
			<?php if ( $mailpoet ) : ?>
				<form method="post" class="stms-card">
					<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
					<input type="hidden" name="stms_action" value="import_mailpoet">
					<h2><?php esc_html_e( 'Import from MailPoet', 'store-messages' ); ?></h2>
					<p class="stms-hint"><?php echo esc_html( sprintf( __( 'MailPoet has %s. Their status carries over and their old unsubscribe links keep working. Running it again only fills in what is missing; nobody who unsubscribed here is subscribed again.', 'store-messages' ), implode( ', ', array_map( static fn( $k, $n ) => $n . ' ' . $k, array_keys( $mailpoet ), $mailpoet ) ) ) ); ?></p>
					<button class="button"><?php esc_html_e( 'Import subscribers', 'store-messages' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function export() {
		if ( ! current_user_can( Admin::CAP ) || ! check_admin_referer( 'stms_export' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'store-messages' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename=subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'email', 'first_name', 'last_name', 'status', 'source', 'lang', 'consent', 'created_at', 'confirmed_at', 'unsubscribed_at' ] );
		$page = 1;
		do {
			[ $rows ] = Subscribers::query( [ 'page' => $page++, 'per_page' => 500 ] );
			foreach ( $rows as $r ) {
				// A leading = + - @ would make spreadsheet apps run it as a formula.
				$safe = static fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : (string) $v;
				fputcsv( $out, array_map( $safe, [ $r->email, $r->first_name, $r->last_name, $r->status, $r->source, $r->lang, $r->consent, $r->created_at, $r->confirmed_at, $r->unsubscribed_at ] ) );
			}
		} while ( count( $rows ) === 500 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------------------------------------ sign-up -- */

	public static function tab_signup() {
		$s = stms_settings()['signup'];
		$c = stms_settings()['coupon'];
		$q = stms_settings()['sending'];
		$text = static function ( $key, $label, $hint = '' ) use ( $s ) {
			echo '<div class="stms-field"><label for="stms-su-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="text" id="stms-su-' . esc_attr( $key ) . '" name="signup[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[ $key ] ) . '">' . ( $hint ? '<p class="description">' . esc_html( $hint ) . '</p>' : '' ) . '</div>';
		};
		$check = static function ( $name, $on, $title, $hint ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . '> <span><strong>' . esc_html( $title ) . '</strong><br><span class="stms-muted">' . wp_kses_post( $hint ) . '</span></span></label>';
		};
		?>
		<form method="post" class="stms-editor stms-editor--signup">
			<?php wp_nonce_field( 'stms_save', 'stms_nonce' ); ?>
			<input type="hidden" name="stms_action" value="save_signup">
			<div class="stms-editor__main">
				<div class="stms-card">
					<h2><?php esc_html_e( 'Ways to subscribe', 'store-messages' ); ?></h2>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[checkout]', ! empty( $s['checkout'] ), __( 'Checkbox at checkout', 'store-messages' ), __( 'Unticked by default. Hidden for logged-in customers who are already subscribed. (Classic checkout page.)', 'store-messages' ) ); ?>
					</div>
					<?php $text( 'checkout_label', __( 'Checkout checkbox text', 'store-messages' ) ); ?>
					<div class="stms-field">
						<label for="stms-su-pos"><?php esc_html_e( 'Checkout checkbox position', 'store-messages' ); ?></label>
						<select id="stms-su-pos" name="signup[checkout_position]">
							<option value="top" <?php selected( $s['checkout_position'], 'top' ); ?>><?php esc_html_e( 'Before the terms and conditions', 'store-messages' ); ?></option>
							<option value="bottom" <?php selected( $s['checkout_position'], 'bottom' ); ?>><?php esc_html_e( 'Just above the order button', 'store-messages' ); ?></option>
						</select>
					</div>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[account]', ! empty( $s['account'] ), __( 'Checkbox in My account', 'store-messages' ), __( 'On Account details, so customers can subscribe or unsubscribe themselves.', 'store-messages' ) ); ?>
					</div>
					<?php $text( 'account_label', __( 'My account checkbox text', 'store-messages' ) ); ?>
					<p class="stms-hint"><?php echo wp_kses_post( __( 'A sign-up form anywhere: <code>[store_messages_signup heading="Our newsletter" button="Subscribe"]</code>', 'store-messages' ) ); ?></p>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[double_optin]', ! empty( $s['double_optin'] ), __( 'Double opt-in', 'store-messages' ), __( 'New subscribers get the “Confirm subscription” email first and count as subscribed once they click its link. Recommended for EU customers.', 'store-messages' ) ); ?>
					</div>
				</div>

				<div class="stms-card">
					<h2><?php esc_html_e( 'Sign-up popup', 'store-messages' ); ?></h2>
					<p class="stms-hint"><?php esc_html_e( 'Small and fast: a bottom sheet on phones, a centred card on computers. Never shown on cart, checkout or My account, nor to someone who subscribed or closed it recently.', 'store-messages' ); ?></p>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[popup]', ! empty( $s['popup'] ), __( 'Show the popup', 'store-messages' ), __( 'Preview it on the right before switching it on.', 'store-messages' ) ); ?>
					</div>
					<?php $text( 'popup_heading', __( 'Heading', 'store-messages' ) ); ?>
					<?php $text( 'popup_text', __( 'Text', 'store-messages' ) ); ?>
					<div class="stms-row">
						<?php $text( 'popup_button', __( 'Button', 'store-messages' ) ); ?>
						<?php $text( 'popup_note', __( 'Small print under the button', 'store-messages' ) ); ?>
					</div>
					<?php $text( 'success', __( 'Thank-you message', 'store-messages' ) ); ?>
					<div class="stms-field">
						<label for="stms-su-popup_image"><?php esc_html_e( 'Image', 'store-messages' ); ?> <span class="stms-optional"><?php esc_html_e( '(optional)', 'store-messages' ); ?></span></label>
						<div class="stms-copy"><input type="url" id="stms-su-popup_image" name="signup[popup_image]" value="<?php echo esc_attr( $s['popup_image'] ); ?>"><button type="button" class="button" data-stms-media="#stms-su-popup_image"><?php esc_html_e( 'Choose image', 'store-messages' ); ?></button></div>
					</div>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[popup_name]', ! empty( $s['popup_name'] ), __( 'Ask for the first name', 'store-messages' ), __( 'Optional for the visitor; used for “Hi {first_name}”.', 'store-messages' ) ); ?>
					</div>
					<div class="stms-row stms-row--4">
						<div class="stms-field"><label for="stms-su-delay"><?php esc_html_e( 'After (seconds)', 'store-messages' ); ?></label><input type="number" min="0" max="600" id="stms-su-delay" name="signup[popup_delay]" value="<?php echo (int) $s['popup_delay']; ?>"></div>
						<div class="stms-field"><label for="stms-su-scroll"><?php esc_html_e( 'or after scrolling (%)', 'store-messages' ); ?></label><input type="number" min="0" max="100" id="stms-su-scroll" name="signup[popup_scroll]" value="<?php echo (int) $s['popup_scroll']; ?>"></div>
						<div class="stms-field"><label for="stms-su-days"><?php esc_html_e( 'Closed: ask again after (days)', 'store-messages' ); ?></label><input type="number" min="0" max="365" id="stms-su-days" name="signup[popup_days]" value="<?php echo (int) $s['popup_days']; ?>"></div>
						<div class="stms-field">
							<label for="stms-su-pages"><?php esc_html_e( 'Show on', 'store-messages' ); ?></label>
							<select id="stms-su-pages" name="signup[popup_pages]">
								<option value="all" <?php selected( $s['popup_pages'], 'all' ); ?>><?php esc_html_e( 'All pages', 'store-messages' ); ?></option>
								<option value="shop" <?php selected( $s['popup_pages'], 'shop' ); ?>><?php esc_html_e( 'Home and shop pages', 'store-messages' ); ?></option>
								<option value="home" <?php selected( $s['popup_pages'], 'home' ); ?>><?php esc_html_e( 'Home page only', 'store-messages' ); ?></option>
							</select>
						</div>
					</div>
					<?php if ( count( Language::all() ) > 1 ) : ?>
						<?php $text( 'popup_langs', __( 'Only in these languages', 'store-messages' ), sprintf( __( 'Comma-separated codes (%s). Empty: every language. Texts are translated through your Overlay Multilingual dictionary.', 'store-messages' ), implode( ', ', Language::all() ) ) ); ?>
					<?php endif; ?>
				</div>

				<?php if ( Integrations::brave_active() ) : ?>
					<?php $popups = Integrations::brave_popups(); $connected = Integrations::brave_connected(); ?>
					<div class="stms-card">
						<h2><?php esc_html_e( 'Brave Popup', 'store-messages' ); ?></h2>
						<p class="stms-hint"><?php esc_html_e( 'Keep a popup designed in Brave: its form adds the visitor here, and they get the welcome email and code. Brave still shows its own thank-you message.', 'store-messages' ); ?></p>
						<?php if ( ! $popups ) : ?>
							<p class="stms-muted"><?php esc_html_e( 'No Brave popup has a form yet.', 'store-messages' ); ?></p>
						<?php else : ?>
							<input type="hidden" name="signup[brave][]" value="0">
							<div class="stms-field stms-switches">
								<?php foreach ( $popups as $pid => $p ) : ?>
									<?php
									$on   = in_array( $pid, $connected, true );
									$hint = 'publish' === $p['status'] ? __( 'Live', 'store-messages' ) : __( 'Not published in Brave', 'store-messages' );
									if ( $on && $p['newsletter'] ) {
										/* translators: %s: newsletter service, e.g. mailpoet */
										$hint .= ' · <strong>' . esc_html( sprintf( __( 'Also sends to %s: turn off the form’s newsletter action in Brave, or visitors may see “Subscription failed” once that service is gone.', 'store-messages' ), $p['newsletter'] ) ) . '</strong>';
									}
									?>
									<label><input type="checkbox" name="signup[brave][]" value="<?php echo (int) $pid; ?>" <?php checked( $on ); ?>> <span><strong><?php echo esc_html( $p['title'] ); ?></strong> <span class="stms-muted">#<?php echo (int) $pid; ?></span><br><span class="stms-muted"><?php echo wp_kses_post( $hint ); ?></span></span></label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<div class="stms-card">
					<h2><?php esc_html_e( 'Welcome email and code', 'store-messages' ); ?></h2>
					<div class="stms-field stms-switches">
						<?php $check( 'signup[welcome]', ! empty( $s['welcome'] ), __( 'Send a welcome email', 'store-messages' ), sprintf( __( 'Uses the %s template. Sent once per person, even if they unsubscribe and sign up again.', 'store-messages' ), '<a href="' . esc_url( Admin::url( 'templates', [ 'edit' => 'welcome' ] ) ) . '">' . esc_html__( 'Welcome', 'store-messages' ) . '</a>' ) ); ?>
					</div>
					<div class="stms-row stms-row--4">
						<div class="stms-field"><label for="stms-su-wdelay"><?php esc_html_e( 'Send after (minutes)', 'store-messages' ); ?></label><input type="number" min="0" max="1440" id="stms-su-wdelay" name="signup[welcome_delay]" value="<?php echo (int) $s['welcome_delay']; ?>"></div>
					</div>
					<div class="stms-field stms-switches">
						<?php $check( 'coupon[enabled]', ! empty( $c['enabled'] ), __( 'Personal code', 'store-messages' ), __( 'Where an email shows {coupon_code}, each subscriber gets their own single-use code.', 'store-messages' ) ); ?>
					</div>
					<div class="stms-row stms-row--4">
						<div class="stms-field"><label for="stms-cp-amount"><?php esc_html_e( 'Discount', 'store-messages' ); ?></label><input type="number" min="0" step="0.01" id="stms-cp-amount" name="coupon[amount]" value="<?php echo esc_attr( $c['amount'] ); ?>"></div>
						<div class="stms-field">
							<label for="stms-cp-type"><?php esc_html_e( 'Type', 'store-messages' ); ?></label>
							<select id="stms-cp-type" name="coupon[type]">
								<option value="percent" <?php selected( $c['type'], 'percent' ); ?>><?php esc_html_e( 'Percent', 'store-messages' ); ?></option>
								<option value="fixed_cart" <?php selected( $c['type'], 'fixed_cart' ); ?>><?php echo esc_html( sprintf( __( 'Amount (%s)', 'store-messages' ), get_woocommerce_currency() ) ); ?></option>
							</select>
						</div>
						<div class="stms-field"><label for="stms-cp-days"><?php esc_html_e( 'Valid for (days)', 'store-messages' ); ?></label><input type="number" min="0" max="3650" id="stms-cp-days" name="coupon[days]" value="<?php echo (int) $c['days']; ?>"></div>
					</div>
					<div class="stms-field stms-switches">
						<?php $check( 'coupon[first_order]', ! empty( $c['first_order'] ), __( 'First order only', 'store-messages' ), __( 'Refused when the email address or account already has a paid order.', 'store-messages' ) ); ?>
						<?php $check( 'coupon[individual_use]', ! empty( $c['individual_use'] ), __( 'Not combined with other codes', 'store-messages' ), '' ); ?>
						<?php $check( 'coupon[restrict_email]', ! empty( $c['restrict_email'] ), __( 'Only for the subscriber’s email address', 'store-messages' ), __( 'Stops the code being shared, but refuses it when the customer checks out with another address.', 'store-messages' ) ); ?>
					</div>
				</div>

				<div class="stms-card">
					<h2><?php esc_html_e( 'Sending speed', 'store-messages' ); ?></h2>
					<p class="stms-hint"><?php esc_html_e( 'Campaigns go out in batches so your mail server’s hourly limit is respected.', 'store-messages' ); ?></p>
					<div class="stms-row stms-row--4">
						<div class="stms-field"><label for="stms-sd-batch"><?php esc_html_e( 'Emails per batch', 'store-messages' ); ?></label><input type="number" min="1" max="500" id="stms-sd-batch" name="sending[batch]" value="<?php echo (int) $q['batch']; ?>"></div>
						<div class="stms-field"><label for="stms-sd-int"><?php esc_html_e( 'Minutes between batches', 'store-messages' ); ?></label><input type="number" min="1" max="120" id="stms-sd-int" name="sending[interval]" value="<?php echo (int) $q['interval']; ?>"></div>
					</div>
				</div>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'store-messages' ); ?></button></p>
			</div>
			<aside class="stms-editor__side">
				<p class="stms-label"><?php esc_html_e( 'Popup preview (saved settings)', 'store-messages' ); ?></p>
				<div class="stms-popup-preview"><?php echo Signup::popup_html( true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</aside>
		</form>
		<?php
	}

	/* -------------------------------------------------------------- saving -- */

	/** Handles this class's form actions. Returns [tab, args] or null when the action is not ours. */
	public static function handle( $action, array $post ) {
		$id = absint( $post['id'] ?? 0 );
		switch ( $action ) {
			case 'save_campaign':
			case 'send_campaign':
			case 'schedule_campaign':
				$cid = Campaigns::save( self::campaign_from_post( $post ) );
				if ( 'save_campaign' === $action ) {
					Admin::flash( __( 'Draft saved.', 'store-messages' ) );
					return [ 'campaigns', [ 'edit' => $cid ] ];
				}
				$when = 0;
				if ( 'schedule_campaign' === $action ) {
					$local = sanitize_text_field( $post['when'] ?? '' );
					$when  = $local ? strtotime( get_gmt_from_date( str_replace( 'T', ' ', $local ) . ':00' ) . ' UTC' ) : 0;
					if ( $when <= time() ) {
						Admin::flash( __( 'Pick a date and time in the future to schedule.', 'store-messages' ), 'error' );
						return [ 'campaigns', [ 'edit' => $cid ] ];
					}
				}
				$result = Campaigns::start( $cid, $when );
				if ( is_wp_error( $result ) ) {
					Admin::flash( $result->get_error_message(), 'error' );
				} else {
					Admin::flash( $when
						/* translators: 1: number of subscribers, 2: date */
						? sprintf( _n( 'Scheduled for %2$s: %1$d subscriber.', 'Scheduled for %2$s: %1$d subscribers.', $result, 'store-messages' ), $result, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) )
						/* translators: %d: number of subscribers */
						: sprintf( _n( 'Sending to %d subscriber now.', 'Sending to %d subscribers now.', $result, 'store-messages' ), $result ) );
				}
				return [ 'campaigns', [ 'edit' => $cid ] ];

			case 'cancel_campaign':
				Campaigns::cancel( $id ) && Admin::flash( __( 'Sending stopped. Nobody else will receive it.', 'store-messages' ) );
				return [ 'campaigns', [ 'edit' => $id ] ];

			case 'reopen_campaign':
				Campaigns::reopen( $id ) && Admin::flash( __( 'Back to draft. Sending it again skips everyone who already got it.', 'store-messages' ) );
				return [ 'campaigns', [ 'edit' => $id ] ];

			case 'duplicate_campaign':
				$new = Campaigns::duplicate( $id );
				Admin::flash( __( 'Campaign duplicated. You are now editing the copy.', 'store-messages' ) );
				return [ 'campaigns', [ 'edit' => $new ] ];

			case 'delete_campaign':
				Campaigns::delete( $id ) ? Admin::flash( __( 'Campaign deleted.', 'store-messages' ) ) : Admin::flash( __( 'Stop sending before deleting.', 'store-messages' ), 'error' );
				return [ 'campaigns', [] ];

			case 'add_subscriber':
				$sub = Subscribers::subscribe( [ 'email' => $post['email'] ?? '', 'first_name' => $post['first_name'] ?? '', 'last_name' => $post['last_name'] ?? '', 'source' => 'admin', 'lang' => '', 'consent' => __( 'Added by the store', 'store-messages' ) ] );
				if ( is_wp_error( $sub ) ) {
					Admin::flash( $sub->get_error_message(), 'error' );
				} else {
					Subscribers::update( $sub->id, [ 'welcomed_at' => \StoreMessages\Database::now() ] );
					Admin::flash( $sub->is_new ? __( 'Subscriber added.', 'store-messages' ) : __( 'Already in the list; now subscribed.', 'store-messages' ) );
				}
				return [ 'subscribers', [] ];

			case 'unsubscribe_subscriber':
				Subscribers::unsubscribe( Subscribers::get( $id ) );
				Admin::flash( __( 'Unsubscribed.', 'store-messages' ) );
				return [ 'subscribers', [] ];

			case 'resubscribe_subscriber':
				$sub = Subscribers::get( $id );
				if ( $sub ) {
					Subscribers::subscribe( [ 'email' => $sub->email, 'source' => 'admin', 'consent' => __( 'Subscribed again by the store', 'store-messages' ) ] );
					Admin::flash( __( 'Subscribed.', 'store-messages' ) );
				}
				return [ 'subscribers', [] ];

			case 'delete_subscriber':
				Subscribers::delete( $id );
				Admin::flash( __( 'Subscriber deleted.', 'store-messages' ) );
				return [ 'subscribers', [] ];

			case 'import_mailpoet':
				$r = Subscribers::import_mailpoet();
				/* translators: 1: added, 2: updated, 3: skipped */
				Admin::flash( sprintf( __( 'MailPoet import: %1$d added, %2$d updated, %3$d skipped.', 'store-messages' ), $r['added'], $r['updated'], $r['skipped'] ) );
				return [ 'subscribers', [] ];

			case 'save_signup':
				self::save_signup( $post );
				Admin::flash( __( 'Sign-up settings saved.', 'store-messages' ) );
				return [ 'signup', [] ];
		}
		return null;
	}

	private static function campaign_from_post( array $post ) {
		$aud = (array) ( $post['audience'] ?? [] );
		return [
			'id'        => absint( $post['id'] ?? 0 ),
			'name'      => sanitize_text_field( $post['name'] ?? '' ),
			'subject'   => sanitize_text_field( $post['subject'] ?? '' ),
			'heading'   => trim( wp_strip_all_tags( (string) ( $post['heading'] ?? '' ) ) ),
			'preheader' => sanitize_text_field( $post['preheader'] ?? '' ),
			'body'      => Admin::clean_html( $post['body'] ?? '' ),
			'audience'  => [
				'lang'   => isset( Language::all()[ $aud['lang'] ?? '' ] ) ? $aud['lang'] : '',
				'buyers' => in_array( $aud['buyers'] ?? '', [ 'customers', 'new' ], true ) ? $aud['buyers'] : '',
			],
		];
	}

	private static function save_signup( array $post ) {
		$in       = (array) ( $post['signup'] ?? [] );
		$defaults = stms_default_settings();
		$signup   = [];
		foreach ( $defaults['signup'] as $key => $default ) {
			if ( is_int( $default ) ) {
				$signup[ $key ] = max( 0, absint( $in[ $key ] ?? 0 ) );
			} elseif ( 'popup_image' === $key ) {
				$signup[ $key ] = esc_url_raw( $in[ $key ] ?? '' );
			} elseif ( 'brave' === $key ) {
				$ids            = isset( $in[ $key ] ) ? (array) $in[ $key ] : Integrations::brave_connected(); // card not shown: keep the choice
				$signup[ $key ] = implode( ',', array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			} elseif ( 'popup_note' === $key ) {
				$signup[ $key ] = wp_kses( (string) ( $in[ $key ] ?? '' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] );
			} else {
				$signup[ $key ] = sanitize_text_field( $in[ $key ] ?? '' );
			}
		}
		$signup['checkout_position'] = 'bottom' === $signup['checkout_position'] ? 'bottom' : 'top';
		$signup['popup_pages']       = in_array( $signup['popup_pages'], [ 'all', 'home', 'shop' ], true ) ? $signup['popup_pages'] : 'all';
		$signup['popup_langs']       = implode( ',', array_intersect( array_map( 'sanitize_key', explode( ',', $signup['popup_langs'] ) ), Language::all() ) );
		foreach ( [ 'checkout_label', 'account_label', 'popup_heading', 'popup_button', 'success' ] as $key ) {
			if ( '' === $signup[ $key ] ) {
				$signup[ $key ] = $defaults['signup'][ $key ]; // these must never be empty
			}
		}
		$cin    = (array) ( $post['coupon'] ?? [] );
		$coupon = [
			'enabled'        => empty( $cin['enabled'] ) ? 0 : 1,
			'type'           => 'fixed_cart' === ( $cin['type'] ?? '' ) ? 'fixed_cart' : 'percent',
			'amount'         => max( 0, (float) ( $cin['amount'] ?? 0 ) ),
			'days'           => absint( $cin['days'] ?? 0 ),
			'first_order'    => empty( $cin['first_order'] ) ? 0 : 1,
			'individual_use' => empty( $cin['individual_use'] ) ? 0 : 1,
			'restrict_email' => empty( $cin['restrict_email'] ) ? 0 : 1,
		];
		$sin = (array) ( $post['sending'] ?? [] );
		stms_update_settings( [
			'signup'  => $signup,
			'coupon'  => $coupon,
			'sending' => [ 'batch' => max( 1, min( 500, absint( $sin['batch'] ?? 25 ) ) ), 'interval' => max( 1, min( 120, absint( $sin['interval'] ?? 5 ) ) ) ],
		] );
	}

	/* --------------------------------------------------------------- ajax -- */

	private static function check() {
		check_ajax_referer( 'stms_ajax', 'nonce' );
		if ( ! current_user_can( Admin::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Sorry, you are not allowed to do that.', 'store-messages' ) ], 403 );
		}
	}

	/** A campaign from the editor, or a marketing template by id. */
	private static function request_template( array $req ) {
		if ( isset( $req['body'] ) ) {
			return self::campaign_from_post( $req );
		}
		if ( ! empty( $req['id'] ) && Campaigns::get( absint( $req['id'] ) ) ) {
			return Campaigns::get( absint( $req['id'] ) ); // a sent campaign: its fields are read-only, so not posted
		}
		return Templates::get( sanitize_key( $req['template'] ?? '' ) ) ?: [];
	}

	public static function ajax_preview() {
		self::check();
		$req  = wp_unslash( $_POST );
		$mail = Marketing::render( Marketing::sample_subscriber(), self::request_template( $req ), true );
		wp_send_json_success( [ 'subject' => $mail['subject'], 'html' => $mail['html'] ] );
	}

	public static function ajax_test() {
		self::check();
		$req    = wp_unslash( $_POST );
		$to     = sanitize_email( $req['to'] ?? '' );
		$result = Marketing::send( Marketing::sample_subscriber( $to ), self::request_template( $req ), [ 'test' => true, 'to' => $to ] );
		is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( [ 'message' => sprintf( __( 'Test sent to %s', 'store-messages' ), $to ) ] );
	}

	public static function ajax_audience() {
		self::check();
		$c = self::campaign_from_post( wp_unslash( $_POST ) );
		$n = count( Subscribers::audience_ids( $c['audience'] ) );
		wp_send_json_success( [ 'message' => sprintf( _n( '%d subscriber will receive it.', '%d subscribers will receive it.', $n, 'store-messages' ), $n ) ] );
	}

	/** Marketing templates on the Templates tab preview with a subscriber, not an order. */
	public static function is_marketing_template( array $req ) {
		if ( isset( $req['layout'] ) ) {
			return false; // the Layout tab previews unsaved layout and brand edits with sample order data
		}
		$tpl = Templates::get( sanitize_key( $req['id'] ?? ( $req['template'] ?? '' ) ) );
		return $tpl && ! empty( $tpl['marketing'] );
	}

	public static function preview_marketing_template( array $req ) {
		$tpl = isset( $req['body'] ) ? array_merge( Templates::get( sanitize_key( $req['id'] ?? '' ) ) ?: [], array_intersect_key( $req, array_flip( [ 'subject', 'heading', 'preheader', 'body' ] ) ) ) : Templates::get( sanitize_key( $req['template'] ?? '' ) );
		$tpl['body'] = Admin::clean_html( $tpl['body'] ?? '' );
		return Marketing::render( Marketing::sample_subscriber(), (array) $tpl, true );
	}
}
