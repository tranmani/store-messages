<?php
/**
 * Campaigns: a marketing email to subscribers, sent in small batches.
 *
 * Starting a campaign fills the queue with everyone in its audience; a
 * background job (Action Scheduler, bundled with WooCommerce) then sends a
 * batch every few minutes so the mail server's limits are respected.
 *
 * Status: draft → scheduled → sending → sent, or cancelled.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Campaigns {

	const HOOK = 'stms_campaign_batch';
	const LOCK = 'stms_campaign_lock';

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
	}

	public static function statuses() {
		return [
			'draft'     => __( 'Draft', 'store-messages' ),
			'scheduled' => __( 'Scheduled', 'store-messages' ),
			'sending'   => __( 'Sending', 'store-messages' ),
			'sent'      => __( 'Sent', 'store-messages' ),
			'cancelled' => __( 'Cancelled', 'store-messages' ),
		];
	}

	/* -------------------------------------------------------------- read -- */

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'campaigns' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['audience'] = array_merge( self::default_audience(), (array) json_decode( (string) $row['audience'], true ) );
		return $row;
	}

	public static function all() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT id, name, subject, status, scheduled_at, started_at, finished_at, total, sent, failed, created_at, updated_at FROM ' . Database::table( 'campaigns' ) . ' ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function default_audience() {
		return [ 'lang' => '', 'buyers' => '' ];
	}

	public static function blank() {
		return [
			'id'        => 0,
			'name'      => '',
			'subject'   => '',
			'heading'   => '',
			'preheader' => '',
			'body'      => "<p style=\"margin:0 0 16px\">{if first_name}Hi {first_name},{else}Hello,{/if}</p>\n\n<p style=\"margin:0 0 16px\">" . __( 'Write your message here.', 'store-messages' ) . "</p>\n\n{bestsellers}\n\n<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:8px 0 8px\">\n  <tr>\n    <td style=\"border-radius:8px;background:{accent}\">\n      <a href=\"{shop_url}\" style=\"display:inline-block;padding:13px 26px;font-weight:600;color:{accent_text};text-decoration:none;border-radius:8px\">" . __( 'Visit the shop', 'store-messages' ) . "</a>\n    </td>\n  </tr>\n</table>\n",
			'audience'  => self::default_audience(),
			'status'    => 'draft',
			'total'     => 0,
			'sent'      => 0,
			'failed'    => 0,
		];
	}

	/* ------------------------------------------------------------- write -- */

	/** Save a draft (insert when id is 0). Returns the id. */
	public static function save( array $data ) {
		global $wpdb;
		$table  = Database::table( 'campaigns' );
		$fields = [
			'name'       => (string) ( $data['name'] ?? '' ),
			'subject'    => (string) ( $data['subject'] ?? '' ),
			'heading'    => (string) ( $data['heading'] ?? '' ),
			'preheader'  => (string) ( $data['preheader'] ?? '' ),
			'body'       => (string) ( $data['body'] ?? '' ),
			'audience'   => wp_json_encode( array_intersect_key( array_merge( self::default_audience(), (array) ( $data['audience'] ?? [] ) ), self::default_audience() ) ),
			'updated_at' => Database::now(),
		];
		$id = (int) ( $data['id'] ?? 0 );
		if ( $id && self::get( $id ) ) {
			$wpdb->update( $table, $fields, [ 'id' => $id ] );
			return $id;
		}
		$wpdb->insert( $table, $fields + [ 'status' => 'draft', 'created_at' => Database::now() ] );
		return (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || in_array( $c['status'], [ 'scheduled', 'sending' ], true ) ) {
			return false;
		}
		$wpdb->delete( Database::table( 'queue' ), [ 'campaign_id' => (int) $id ] );
		return (bool) $wpdb->delete( Database::table( 'campaigns' ), [ 'id' => (int) $id ] );
	}

	/** Copy a campaign into a new draft. */
	public static function duplicate( $id ) {
		$c = self::get( $id );
		if ( ! $c ) {
			return 0;
		}
		/* translators: %s: campaign name */
		return self::save( array_merge( $c, [ 'id' => 0, 'name' => sprintf( __( '%s (copy)', 'store-messages' ), $c['name'] ) ] ) );
	}

	/**
	 * Queue everyone in the audience and start sending (now, or at $when, a UTC timestamp).
	 *
	 * @return int|\WP_Error Number of recipients.
	 */
	public static function start( $id, $when = 0 ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || 'draft' !== $c['status'] ) {
			return new \WP_Error( 'stms_campaign', __( 'Only a draft can be sent.', 'store-messages' ) );
		}
		if ( '' === trim( $c['subject'] ) || '' === trim( wp_strip_all_tags( $c['body'] ) ) ) {
			return new \WP_Error( 'stms_campaign', __( 'Add a subject and a message first.', 'store-messages' ) );
		}
		$ids = Subscribers::audience_ids( $c['audience'] );
		if ( ! $ids ) {
			return new \WP_Error( 'stms_campaign', __( 'Nobody is subscribed in this audience yet.', 'store-messages' ) );
		}
		$queue = Database::table( 'queue' );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$values = implode( ',', array_map( static fn( $sid ) => $wpdb->prepare( '(%d,%d,%s)', $id, $sid, 'pending' ), $chunk ) );
			$wpdb->query( "INSERT IGNORE INTO $queue (campaign_id, subscriber_id, status) VALUES $values" ); // phpcs:ignore WordPress.DB
		}
		$when = (int) $when > time() ? (int) $when : 0;
		$wpdb->update( Database::table( 'campaigns' ), [
			'status'       => $when ? 'scheduled' : 'sending',
			'scheduled_at' => gmdate( 'Y-m-d H:i:s', $when ?: time() ),
			'started_at'   => $when ? null : Database::now(),
			'total'        => count( $ids ),
			'updated_at'   => Database::now(),
		], [ 'id' => (int) $id ] );
		self::schedule( $when ?: time() );
		return count( $ids );
	}

	public static function cancel( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || ! in_array( $c['status'], [ 'scheduled', 'sending' ], true ) ) {
			return false;
		}
		$wpdb->update( Database::table( 'queue' ), [ 'status' => 'skipped' ], [ 'campaign_id' => (int) $id, 'status' => 'pending' ] );
		$wpdb->update( Database::table( 'campaigns' ), [ 'status' => 'cancelled', 'finished_at' => Database::now(), 'updated_at' => Database::now() ], [ 'id' => (int) $id ] );
		return true;
	}

	/** Back to draft after a cancel, keeping who already got it out of a new send. */
	public static function reopen( $id ) {
		global $wpdb;
		$c = self::get( $id );
		if ( ! $c || 'cancelled' !== $c['status'] ) {
			return false;
		}
		$wpdb->delete( Database::table( 'queue' ), [ 'campaign_id' => (int) $id, 'status' => 'skipped' ] );
		$wpdb->update( Database::table( 'campaigns' ), [ 'status' => 'draft', 'finished_at' => null, 'updated_at' => Database::now() ], [ 'id' => (int) $id ] );
		return true;
	}

	/* ----------------------------------------------------------- sending -- */

	/**
	 * Make sure a batch runs at $when or sooner. A later pending batch (another
	 * campaign scheduled for tomorrow) is moved forward. While a batch runs,
	 * Action Scheduler reports `true` for it: the next one is added anyway.
	 */
	private static function schedule( $when ) {
		$when = (int) $when;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$next = as_next_scheduled_action( self::HOOK, [], Marketing::GROUP );
			if ( is_int( $next ) && $next <= $when ) {
				return;
			}
			if ( is_int( $next ) ) {
				as_unschedule_all_actions( self::HOOK, [], Marketing::GROUP );
			}
			as_schedule_single_action( $when, self::HOOK, [], Marketing::GROUP );
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && $next <= $when ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( $when, self::HOOK );
	}

	/** One batch for the oldest campaign that is due; schedules the next batch while work remains. */
	public static function run() {
		global $wpdb;
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		$sending   = 0;
		$scheduled = null;
		try {
			$camps = Database::table( 'campaigns' );
			$queue = Database::table( 'queue' );
			$now   = Database::now();
			$wpdb->query( $wpdb->prepare( "UPDATE $camps SET status = 'sending', started_at = %s WHERE status = 'scheduled' AND scheduled_at <= %s", $now, $now ) ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->get_var( "SELECT id FROM $camps WHERE status = 'sending' ORDER BY started_at, id LIMIT 1" ); // phpcs:ignore WordPress.DB
			if ( $id ) {
				self::batch( self::get( $id ) );
			}
			$sending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $camps WHERE status = 'sending'" ); // phpcs:ignore WordPress.DB
			$scheduled = $wpdb->get_var( "SELECT MIN(scheduled_at) FROM $camps WHERE status = 'scheduled'" ); // phpcs:ignore WordPress.DB
		} finally {
			delete_transient( self::LOCK );
		}
		$interval = max( 1, (int) stms_settings()['sending']['interval'] ) * MINUTE_IN_SECONDS;
		if ( $sending ) {
			self::schedule( time() + $interval );
		} elseif ( $scheduled ) {
			self::schedule( max( time() + 60, strtotime( $scheduled . ' UTC' ) ) );
		}
	}

	private static function batch( array $c ) {
		global $wpdb;
		$queue = Database::table( 'queue' );
		$size  = max( 1, (int) stms_settings()['sending']['batch'] );
		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, subscriber_id FROM $queue WHERE campaign_id = %d AND status = 'pending' ORDER BY id LIMIT %d", $c['id'], $size ) ); // phpcs:ignore WordPress.DB
		$tpl   = array_intersect_key( $c, array_flip( [ 'subject', 'heading', 'preheader', 'body' ] ) );
		foreach ( $items as $item ) {
			$sub = Subscribers::get( $item->subscriber_id );
			if ( ! $sub || Subscribers::SUBSCRIBED !== $sub->status ) {
				$wpdb->update( $queue, [ 'status' => 'skipped' ], [ 'id' => $item->id ] );
				continue;
			}
			$result = Marketing::send( $sub, $tpl );
			$ok     = true === $result;
			$wpdb->update( $queue, [ 'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? '' : substr( $result->get_error_message(), 0, 250 ), 'sent_at' => Database::now() ], [ 'id' => $item->id ] );
		}
		$counts = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM $queue WHERE campaign_id = %d GROUP BY status", $c['id'] ) ) as $r ) { // phpcs:ignore WordPress.DB
			$counts[ $r->status ] = (int) $r->n;
		}
		$done = empty( $counts['pending'] );
		$wpdb->update( Database::table( 'campaigns' ), [
			'sent'        => $counts['sent'] ?? 0,
			'failed'      => $counts['failed'] ?? 0,
			'status'      => $done ? 'sent' : 'sending',
			'finished_at' => $done ? Database::now() : null,
			'updated_at'  => Database::now(),
		], [ 'id' => (int) $c['id'] ] );
		if ( $done ) {
			do_action( 'stms_campaign_sent', (int) $c['id'] );
		}
	}

	/** Failed recipients of a campaign, for the report. */
	public static function failures( $id, $limit = 20 ) {
		global $wpdb;
		$queue = Database::table( 'queue' );
		$subs  = Database::table( 'subscribers' );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.email, q.error FROM $queue q LEFT JOIN $subs s ON s.id = q.subscriber_id WHERE q.campaign_id = %d AND q.status = 'failed' LIMIT %d", $id, $limit ) ); // phpcs:ignore WordPress.DB
	}
}
