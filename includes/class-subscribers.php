<?php
/**
 * Newsletter subscribers: who agreed to receive marketing email.
 *
 * Status is one of subscribed, unconfirmed (waiting for the confirmation link
 * when double opt-in is on) or unsubscribed. Every subscriber has a private
 * token for their confirm and unsubscribe links.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Subscribers {

	const SUBSCRIBED   = 'subscribed';
	const UNCONFIRMED  = 'unconfirmed';
	const UNSUBSCRIBED = 'unsubscribed';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
	}

	public static function statuses() {
		return [
			self::SUBSCRIBED   => __( 'Subscribed', 'store-messages' ),
			self::UNCONFIRMED  => __( 'Waiting for confirmation', 'store-messages' ),
			self::UNSUBSCRIBED => __( 'Unsubscribed', 'store-messages' ),
		];
	}

	public static function sources() {
		return [
			'checkout' => __( 'Checkout', 'store-messages' ),
			'popup'    => __( 'Sign-up popup', 'store-messages' ),
			'form'     => __( 'Sign-up form', 'store-messages' ),
			'account'  => __( 'My account', 'store-messages' ),
			'admin'    => __( 'Added by you', 'store-messages' ),
			'import'   => __( 'Imported', 'store-messages' ),
			'brave'    => __( 'Brave Popup', 'store-messages' ),
		];
	}

	public static function source_label( $source ) {
		$base = explode( ':', (string) $source )[0];
		if ( 'mailpoet' === $base ) {
			return __( 'MailPoet', 'store-messages' );
		}
		return self::sources()[ $base ] ?? (string) $source;
	}

	/* -------------------------------------------------------------- read -- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'subscribers' ) . ' WHERE id = %d', $id ) ) ?: null; // phpcs:ignore WordPress.DB
	}

	public static function find( $email ) {
		global $wpdb;
		$email = self::normalise( $email );
		return '' === $email ? null : ( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'subscribers' ) . ' WHERE email = %s', $email ) ) ?: null ); // phpcs:ignore WordPress.DB
	}

	public static function find_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 16 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'subscribers' ) . ' WHERE token = %s', $token ) ); // phpcs:ignore WordPress.DB
		return $row && hash_equals( $row->token, $token ) ? $row : null;
	}

	public static function is_subscribed( $email ) {
		$sub = self::find( $email );
		return $sub && self::SUBSCRIBED === $sub->status;
	}

	/** Counts per status: [status => n]. */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( array_keys( self::statuses() ), 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) n FROM ' . Database::table( 'subscribers' ) . ' GROUP BY status' ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * A page of subscribers.
	 *
	 * @param array $args search, status, page, per_page.
	 * @return array [rows, total]
	 */
	public static function query( array $args = [] ) {
		global $wpdb;
		$table = Database::table( 'subscribers' );
		$where = [ '1=1' ];
		$vals  = [];
		if ( ! empty( $args['status'] ) && isset( self::statuses()[ $args['status'] ] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$per   = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sql   = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql", $vals ) : "SELECT COUNT(*) FROM $table WHERE $sql" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, [ $per, ( $page - 1 ) * $per ] ) ) ); // phpcs:ignore WordPress.DB
		return [ (array) $rows, $total ];
	}

	/**
	 * Ids of subscribed people matching a campaign audience.
	 *
	 * @param array $audience lang ('' = all), buyers ('' | 'customers' | 'new').
	 */
	public static function audience_ids( array $audience ) {
		global $wpdb;
		$table = Database::table( 'subscribers' );
		$sql   = "SELECT id, email FROM $table WHERE status = %s";
		$vals  = [ self::SUBSCRIBED ];
		if ( ! empty( $audience['lang'] ) ) {
			$sql   .= ' AND lang = %s';
			$vals[] = $audience['lang'];
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id', $vals ) ); // phpcs:ignore WordPress.DB
		if ( ! empty( $audience['buyers'] ) ) {
			$buyers = array_flip( self::buyer_emails() );
			$want   = 'customers' === $audience['buyers'];
			$rows   = array_filter( $rows, static fn( $r ) => isset( $buyers[ $r->email ] ) === $want );
		}
		return array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	}

	/** Lower-case emails of everyone with a paid order (from WooCommerce's analytics tables). */
	public static function buyer_emails() {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_customer_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';
		$emails = (array) $wpdb->get_col( "SELECT DISTINCT LOWER(c.email) FROM $lookup c JOIN $stats s ON s.customer_id = c.customer_id WHERE s.status IN ('wc-processing','wc-completed','wc-on-hold') AND c.email <> ''" ); // phpcs:ignore WordPress.DB
		return array_values( array_filter( $emails ) );
	}

	/* ------------------------------------------------------------- write -- */

	public static function normalise( $email ) {
		$email = strtolower( trim( sanitize_email( (string) $email ) ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Someone signs up (or signs up again).
	 *
	 * @param array $data email, first_name, last_name, source, lang, user_id, consent, verified (bool).
	 * @return object|\WP_Error The subscriber row, with ->is_new and ->changed flags.
	 */
	public static function subscribe( array $data ) {
		global $wpdb;
		$email = self::normalise( $data['email'] ?? '' );
		if ( '' === $email ) {
			return new \WP_Error( 'stms_email', __( 'Please enter a valid email address.', 'store-messages' ) );
		}
		$table  = Database::table( 'subscribers' );
		// Double opt-in proves the address; not needed when it is already proven (added by you, a logged-in account, a link from our own email).
		$doi    = ! empty( stms_settings()['signup']['double_optin'] ) && empty( $data['verified'] ) && 'admin' !== ( $data['source'] ?? '' );
		$status = $doi ? self::UNCONFIRMED : self::SUBSCRIBED;
		$now    = Database::now();
		$names  = [
			'first_name' => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
		];
		$existing = self::find( $email );

		if ( $existing ) {
			$changes = array_filter( $names, static fn( $v, $k ) => '' !== $v && '' === $existing->$k, ARRAY_FILTER_USE_BOTH );
			if ( ! $existing->user_id && ! empty( $data['user_id'] ) ) {
				$changes['user_id'] = (int) $data['user_id'];
			}
			$changed = false;
			if ( self::SUBSCRIBED !== $existing->status && ! ( self::UNCONFIRMED === $existing->status && $doi ) ) {
				$changes += [ 'status' => $status, 'unsubscribed_at' => null, 'consent' => self::consent_text( $data ) ];
				if ( self::SUBSCRIBED === $status ) {
					$changes['confirmed_at'] = $now;
				}
				$changed = true;
			}
			if ( $changes ) {
				$wpdb->update( $table, $changes, [ 'id' => $existing->id ] );
			}
			$sub          = self::get( $existing->id );
			$sub->is_new  = false;
			$sub->changed = $changed;
		} else {
			$wpdb->insert( $table, $names + [
				'email'        => $email,
				'status'       => $status,
				'source'       => sanitize_key( (string) ( $data['source'] ?? 'form' ) ),
				'lang'         => sanitize_key( (string) ( $data['lang'] ?? Language::current() ) ),
				'user_id'      => (int) ( $data['user_id'] ?? 0 ),
				'token'        => self::new_token(),
				'consent'      => self::consent_text( $data ),
				'created_at'   => $now,
				'confirmed_at' => self::SUBSCRIBED === $status ? $now : null,
			] );
			if ( ! $wpdb->insert_id ) {
				return new \WP_Error( 'stms_db', __( 'Could not save your sign-up. Please try again.', 'store-messages' ) );
			}
			$sub          = self::get( $wpdb->insert_id );
			$sub->is_new  = true;
			$sub->changed = true;
		}

		if ( $sub->changed ) {
			if ( self::UNCONFIRMED === $sub->status ) {
				Marketing::send_confirmation( $sub );
			} else {
				/**
				 * Someone became a subscriber (new, re-subscribed or confirmed).
				 *
				 * @param object $sub Subscriber row.
				 */
				do_action( 'stms_subscribed', $sub );
			}
		} elseif ( self::UNCONFIRMED === $sub->status && $doi ) {
			Marketing::send_confirmation( $sub ); // signed up again before confirming: resend the link
		}
		return $sub;
	}

	public static function confirm( $sub ) {
		global $wpdb;
		if ( ! $sub || self::UNCONFIRMED !== $sub->status ) {
			return $sub;
		}
		$wpdb->update( Database::table( 'subscribers' ), [ 'status' => self::SUBSCRIBED, 'confirmed_at' => Database::now() ], [ 'id' => $sub->id ] );
		$sub = self::get( $sub->id );
		do_action( 'stms_subscribed', $sub );
		return $sub;
	}

	public static function unsubscribe( $sub ) {
		global $wpdb;
		if ( ! $sub || self::UNSUBSCRIBED === $sub->status ) {
			return $sub;
		}
		$wpdb->update( Database::table( 'subscribers' ), [ 'status' => self::UNSUBSCRIBED, 'unsubscribed_at' => Database::now() ], [ 'id' => $sub->id ] );
		$sub = self::get( $sub->id );
		/**
		 * Someone unsubscribed.
		 *
		 * @param object $sub Subscriber row.
		 */
		do_action( 'stms_unsubscribed', $sub );
		return $sub;
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields = array_intersect_key( $fields, array_flip( [ 'first_name', 'last_name', 'lang', 'coupon', 'user_id', 'welcomed_at' ] ) );
		return $fields ? (bool) $wpdb->update( Database::table( 'subscribers' ), $fields, [ 'id' => (int) $id ] ) : false;
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Database::table( 'queue' ), [ 'subscriber_id' => (int) $id ] );
		return (bool) $wpdb->delete( Database::table( 'subscribers' ), [ 'id' => (int) $id ] );
	}

	private static function new_token() {
		return wp_generate_password( 32, false, false );
	}

	/** What the person agreed to, kept as a record of consent. */
	private static function consent_text( array $data ) {
		$text = trim( (string) ( $data['consent'] ?? '' ) );
		$text = '' !== $text ? $text : self::source_label( $data['source'] ?? '' );
		return substr( wp_strip_all_tags( $text ), 0, 250 );
	}

	/* ----------------------------------------------------------- MailPoet -- */

	public static function mailpoet_available() {
		global $wpdb;
		$table = $wpdb->prefix . 'mailpoet_subscribers';
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/** Subscriber counts in MailPoet's table, by status. */
	public static function mailpoet_counts() {
		global $wpdb;
		if ( ! self::mailpoet_available() ) {
			return [];
		}
		$out = [];
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$wpdb->prefix}mailpoet_subscribers WHERE deleted_at IS NULL GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/**
	 * Copy MailPoet's subscribers. Statuses carry over (bounced and inactive
	 * count as unsubscribed); someone who unsubscribed here stays unsubscribed.
	 * MailPoet's link token is kept, so unsubscribe links in emails MailPoet
	 * already sent keep working after MailPoet is removed.
	 *
	 * @return array [added, updated, skipped]
	 */
	public static function import_mailpoet() {
		global $wpdb;
		$result = [ 'added' => 0, 'updated' => 0, 'skipped' => 0 ];
		if ( ! self::mailpoet_available() ) {
			return $result;
		}
		$table = Database::table( 'subscribers' );
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mailpoet_subscribers WHERE deleted_at IS NULL ORDER BY id" ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			$email = self::normalise( $r->email );
			if ( '' === $email ) {
				++$result['skipped'];
				continue;
			}
			$status = [ 'subscribed' => self::SUBSCRIBED, 'unconfirmed' => self::UNCONFIRMED ][ $r->status ] ?? self::UNSUBSCRIBED;
			$token  = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $r->link_token ?? '' ) );
			$fields = [
				'first_name'      => (string) $r->first_name,
				'last_name'       => (string) $r->last_name,
				'status'          => $status,
				'source'          => substr( 'mailpoet:' . $r->source, 0, 60 ),
				'user_id'         => (int) $r->wp_user_id,
				'created_at'      => $r->created_at ?: Database::now(),
				'confirmed_at'    => self::SUBSCRIBED === $status ? ( ( $r->last_subscribed_at ?? '' ) ?: ( $r->confirmed_at ?: $r->created_at ) ) : null,
				'unsubscribed_at' => self::UNSUBSCRIBED === $status ? ( $r->updated_at ?: Database::now() ) : null,
				'consent'         => substr( sprintf( 'MailPoet (%s)', $r->source ), 0, 250 ),
				'welcomed_at'     => $r->created_at ?: Database::now(), // MailPoet already welcomed them
			];
			$existing = self::find( $email );
			if ( $existing ) {
				if ( self::UNSUBSCRIBED === $existing->status ) {
					unset( $fields['status'], $fields['confirmed_at'], $fields['unsubscribed_at'] );
				}
				// Keep what is already filled in here; fill gaps from MailPoet.
				foreach ( [ 'first_name', 'last_name', 'user_id' ] as $k ) {
					if ( ! empty( $existing->$k ) ) {
						unset( $fields[ $k ] );
					}
				}
				unset( $fields['source'], $fields['created_at'], $fields['consent'], $fields['welcomed_at'] );
				$wpdb->update( $table, $fields, [ 'id' => $existing->id ] );
				++$result['updated'];
				continue;
			}
			$fields += [
				'email' => $email,
				'lang'  => '',
				'token' => strlen( $token ) >= 16 && ! self::find_by_token( $token ) ? $token : self::new_token(),
			];
			$wpdb->insert( $table, $fields );
			$wpdb->insert_id ? ++$result['added'] : ++$result['skipped'];
		}
		return $result;
	}

	/* ------------------------------------------------------------ privacy -- */

	public static function register_exporter( $exporters ) {
		$exporters['store-messages'] = [ 'exporter_friendly_name' => __( 'Newsletter subscription', 'store-messages' ), 'callback' => [ __CLASS__, 'export' ] ];
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['store-messages'] = [ 'eraser_friendly_name' => __( 'Newsletter subscription', 'store-messages' ), 'callback' => [ __CLASS__, 'erase' ] ];
		return $erasers;
	}

	public static function export( $email ) {
		$sub  = self::find( $email );
		$data = [];
		if ( $sub ) {
			foreach ( [ 'email' => __( 'Email', 'store-messages' ), 'first_name' => __( 'First name', 'store-messages' ), 'last_name' => __( 'Last name', 'store-messages' ), 'status' => __( 'Status', 'store-messages' ), 'source' => __( 'Signed up through', 'store-messages' ), 'consent' => __( 'Consent', 'store-messages' ), 'created_at' => __( 'Signed up', 'store-messages' ), 'confirmed_at' => __( 'Confirmed', 'store-messages' ), 'unsubscribed_at' => __( 'Unsubscribed', 'store-messages' ) ] as $k => $label ) {
				if ( '' !== (string) $sub->$k ) {
					$data[] = [ 'name' => $label, 'value' => (string) $sub->$k ];
				}
			}
		}
		return [ 'data' => $sub ? [ [ 'group_id' => 'store-messages', 'group_label' => __( 'Newsletter subscription', 'store-messages' ), 'item_id' => 'stms-' . $sub->id, 'data' => $data ] ] : [], 'done' => true ];
	}

	public static function erase( $email ) {
		$sub = self::find( $email );
		return [ 'items_removed' => $sub ? self::delete( $sub->id ) : false, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}
}
