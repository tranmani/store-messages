<?php
/**
 * Tables for subscribers, campaigns and the sending queue.
 *
 * @package StoreMessages
 */

namespace StoreMessages;

defined( 'ABSPATH' ) || exit;

class Database {

	const VERSION = '1';
	const OPTION  = 'stms_db_version';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'stms_' . $name;
	}

	/** Create or upgrade the tables when the stored version differs (one option read per request). */
	public static function maybe_install() {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$subs    = self::table( 'subscribers' );
		$camps   = self::table( 'campaigns' );
		$queue   = self::table( 'queue' );
		dbDelta( "CREATE TABLE $subs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(190) NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'subscribed',
  source varchar(60) NOT NULL DEFAULT '',
  lang varchar(10) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  token varchar(64) NOT NULL,
  coupon varchar(60) NOT NULL DEFAULT '',
  consent varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  confirmed_at datetime NULL,
  unsubscribed_at datetime NULL,
  welcomed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY status (status),
  KEY token (token)
) $charset;" );
		dbDelta( "CREATE TABLE $camps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  heading varchar(255) NOT NULL DEFAULT '',
  preheader varchar(255) NOT NULL DEFAULT '',
  body longtext NOT NULL,
  audience text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  scheduled_at datetime NULL,
  started_at datetime NULL,
  finished_at datetime NULL,
  total int(10) unsigned NOT NULL DEFAULT 0,
  sent int(10) unsigned NOT NULL DEFAULT 0,
  failed int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;" );
		dbDelta( "CREATE TABLE $queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  campaign_id bigint(20) unsigned NOT NULL,
  subscriber_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  error varchar(255) NOT NULL DEFAULT '',
  sent_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY campaign_subscriber (campaign_id,subscriber_id),
  KEY campaign_status (campaign_id,status)
) $charset;" );
		update_option( self::OPTION, self::VERSION );
	}

	public static function drop() {
		global $wpdb;
		foreach ( [ 'subscribers', 'campaigns', 'queue' ] as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB
		}
		delete_option( self::OPTION );
	}

	public static function now() {
		return current_time( 'mysql', true );
	}
}
