<?php
/**
 * Database tables, cron events and upgrades.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Install {

	/**
	 * Table names without prefix.
	 *
	 * @var string[]
	 */
	public static $tables = array( 'oshield_blocks', 'oshield_events', 'oshield_incomplete', 'oshield_recordings', 'oshield_shipments', 'oshield_visitors', 'oshield_pageviews' );

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::schedule();
		if ( ! get_option( OShield_Settings::OPTION ) ) {
			add_option( OShield_Settings::OPTION, array() );
		}
		update_option( 'oshield_db_version', OSHIELD_DB_VERSION );
		set_transient( 'oshield_activated', 1, 60 );
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'oshield_hourly' );
		wp_clear_scheduled_hook( 'oshield_daily' );
	}

	/**
	 * Run upgrades when the DB version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'oshield_db_version' ) !== OSHIELD_DB_VERSION ) {
			self::create_tables();
			update_option( 'oshield_db_version', OSHIELD_DB_VERSION );
		}
		self::schedule();
	}

	/**
	 * Make sure cron events exist.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'oshield_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'oshield_hourly' );
		}
		if ( ! wp_next_scheduled( 'oshield_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 09:00:00' ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ), 'daily', 'oshield_daily' );
		}
	}

	/**
	 * Create / update all tables with dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$p = $wpdb->prefix;

		dbDelta(
			"CREATE TABLE {$p}oshield_blocks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			value varchar(191) NOT NULL,
			reason text NULL,
			source varchar(20) NOT NULL DEFAULT 'manual',
			active tinyint(1) NOT NULL DEFAULT 1,
			hits int(11) NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_value (type,value),
			KEY active (active)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(30) NOT NULL,
			phone varchar(32) NOT NULL DEFAULT '',
			ip varchar(64) NOT NULL DEFAULT '',
			device_id varchar(64) NOT NULL DEFAULT '',
			country varchar(5) NOT NULL DEFAULT '',
			reason text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type (type),
			KEY created_at (created_at)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_incomplete (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			phone varchar(32) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			address text NULL,
			fields longtext NULL,
			cart longtext NULL,
			cart_total decimal(18,2) NOT NULL DEFAULT 0,
			currency varchar(10) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip varchar(64) NOT NULL DEFAULT '',
			device_id varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			page_url varchar(255) NOT NULL DEFAULT '',
			note text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY phone (phone),
			KEY status (status),
			KEY updated_at (updated_at)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_recordings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token varchar(64) NOT NULL,
			chunk int(11) NOT NULL DEFAULT 0,
			events longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY token (token)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_shipments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			courier varchar(20) NOT NULL,
			consignment_id varchar(64) NOT NULL DEFAULT '',
			tracking_code varchar(64) NOT NULL DEFAULT '',
			invoice varchar(64) NOT NULL DEFAULT '',
			status varchar(64) NOT NULL DEFAULT '',
			payload longtext NULL,
			events longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY consignment_id (consignment_id),
			KEY invoice (invoice)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_visitors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			device_id varchar(64) NOT NULL DEFAULT '',
			hwid varchar(40) NOT NULL DEFAULT '',
			ip varchar(64) NOT NULL DEFAULT '',
			country varchar(5) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			device varchar(10) NOT NULL DEFAULT '',
			browser varchar(30) NOT NULL DEFAULT '',
			os varchar(30) NOT NULL DEFAULT '',
			referrer varchar(255) NOT NULL DEFAULT '',
			landing_url varchar(255) NOT NULL DEFAULT '',
			current_url varchar(255) NOT NULL DEFAULT '',
			current_title varchar(255) NOT NULL DEFAULT '',
			pages int(11) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			cart_count int(11) NOT NULL DEFAULT 0,
			cart_total decimal(18,2) NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY last_seen (last_seen),
			KEY first_seen (first_seen)
			) $c;"
		);

		dbDelta(
			"CREATE TABLE {$p}oshield_pageviews (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			url varchar(255) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY created_at (created_at)
			) $c;"
		);
	}

	/**
	 * Check every table exists.
	 *
	 * @return array table => bool
	 */
	public static function health() {
		global $wpdb;
		$out = array();
		foreach ( self::$tables as $t ) {
			$name        = $wpdb->prefix . $t;
			$out[ $name ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $out;
	}
}
