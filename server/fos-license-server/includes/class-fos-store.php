<?php
/**
 * Tables, settings and license rules.
 *
 * One row in fos_licenses = one order. An approved row is a license for one domain.
 * A renewal order points at its license with renewal_of; approving it extends that license.
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FOS_Store {

	const OPTION = 'fos_ls_settings';

	/**
	 * Licenses table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fos_licenses';
	}

	/**
	 * Trials table name.
	 *
	 * @return string
	 */
	public static function trials() {
		global $wpdb;
		return $wpdb->prefix . 'fos_trials';
	}

	/**
	 * Create tables and the two pages.
	 */
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			license_key varchar(40) NOT NULL DEFAULT '',
			months smallint(5) unsigned NOT NULL DEFAULT 1,
			amount decimal(10,2) NOT NULL DEFAULT 0,
			domain varchar(190) NOT NULL DEFAULT '',
			method varchar(20) NOT NULL DEFAULT '',
			sender varchar(30) NOT NULL DEFAULT '',
			trx_id varchar(60) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			renewal_of bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			approved_at datetime NULL,
			expires_at datetime NULL,
			last_check datetime NULL,
			PRIMARY KEY  (id),
			KEY license_key (license_key),
			KEY user_id (user_id),
			KEY status (status)
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::trials() . " (
			domain varchar(190) NOT NULL,
			started_at datetime NOT NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (domain)
			) $c;"
		);

		if ( ! get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		self::keys();

		// Landing page as the front page + an account page. Created once.
		$pages = get_option( 'fos_ls_pages', array() );
		foreach ( array(
			'home'    => array( 'Fake Order Shield', '[fos_landing]' ),
			'account' => array( 'My Account', '[fos_account]' ),
		) as $k => $p ) {
			if ( empty( $pages[ $k ] ) || ! get_post( $pages[ $k ] ) ) {
				$pages[ $k ] = wp_insert_post(
					array(
						'post_title'   => $p[0],
						'post_content' => $p[1],
						'post_status'  => 'publish',
						'post_type'    => 'page',
						'post_name'    => 'home' === $k ? 'home' : 'account',
					)
				);
			}
		}
		update_option( 'fos_ls_pages', $pages );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $pages['home'] );
	}

	/**
	 * Default settings. Prices are placeholders the owner changes in Settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'price_1'      => '300',
			'price_3'      => '800',
			'price_6'      => '1500',
			'price_12'     => '2500',
			'bkash'        => '01621275768',
			'nagad'        => '01621275768',
			'support'      => '01911080393',
			'trial_days'   => '3',
			'download_url' => '',
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	public static function opt( $k ) {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return isset( $s[ $k ] ) ? (string) $s[ $k ] : '';
	}

	/**
	 * Packages: months => price.
	 *
	 * @return array
	 */
	public static function packages() {
		$out = array();
		foreach ( array( 1, 3, 6, 12 ) as $m ) {
			$out[ $m ] = (float) self::opt( 'price_' . $m );
		}
		return $out;
	}

	/**
	 * "https://www.Shop.com/path" => "shop.com".
	 *
	 * @param string $d Domain or URL.
	 * @return string
	 */
	public static function domain( $d ) {
		$d = strtolower( trim( (string) $d ) );
		if ( false === strpos( $d, '://' ) ) {
			$d = 'http://' . $d;
		}
		$host = (string) wp_parse_url( $d, PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', $host );
		return preg_match( '/^[a-z0-9.-]+$/', $host ) ? $host : '';
	}

	/**
	 * New random license key, e.g. FOS-7K2M-9QXA-P3LD-W8NE.
	 *
	 * @return string
	 */
	public static function new_key() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$parts = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$p = '';
			for ( $j = 0; $j < 4; $j++ ) {
				$p .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
			$parts[] = $p;
		}
		return 'FOS-' . implode( '-', $parts );
	}

	/**
	 * Get a row.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * License row by key.
	 *
	 * @param string $key Key.
	 * @return object|null
	 */
	public static function by_key( $key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE license_key = %s AND renewal_of = 0 AND status <> 'pending' ORDER BY id DESC LIMIT 1", strtoupper( trim( $key ) ) ) ); // phpcs:ignore
	}

	/**
	 * Rows of a customer, newest first.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY id DESC', $user_id ) ); // phpcs:ignore
	}

	/**
	 * Save a new order. Returns the row id or WP_Error.
	 *
	 * @param array $d user_id, months, domain, method, sender, trx_id, renewal_of.
	 * @return int|WP_Error
	 */
	public static function create_order( $d ) {
		global $wpdb;
		$packages = self::packages();
		$months   = (int) $d['months'];
		if ( ! isset( $packages[ $months ] ) ) {
			return new WP_Error( 'package', __( 'Please choose a package.', 'fos-license-server' ) );
		}
		$renewal = 0;
		if ( ! empty( $d['renewal_of'] ) ) {
			$lic = self::get( (int) $d['renewal_of'] );
			if ( ! $lic || (int) $lic->user_id !== (int) $d['user_id'] || $lic->renewal_of || in_array( $lic->status, array( 'pending', 'rejected' ), true ) ) {
				return new WP_Error( 'renew', __( 'That license cannot be renewed.', 'fos-license-server' ) );
			}
			$renewal     = (int) $lic->id;
			$d['domain'] = $lic->domain;
		}
		$domain = self::domain( $d['domain'] );
		if ( ! $domain || false === strpos( $domain, '.' ) ) {
			return new WP_Error( 'domain', __( 'Please enter your website domain, e.g. myshop.com', 'fos-license-server' ) );
		}
		if ( ! in_array( $d['method'], array( 'bkash', 'nagad' ), true ) ) {
			return new WP_Error( 'method', __( 'Please choose bKash or Nagad.', 'fos-license-server' ) );
		}
		$trx = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $d['trx_id'] ) );
		if ( strlen( $trx ) < 6 ) {
			return new WP_Error( 'trx', __( 'Please enter the Transaction ID (TrxID) from your payment SMS.', 'fos-license-server' ) );
		}
		$used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE trx_id = %s AND status <> 'rejected'", $trx ) ); // phpcs:ignore
		if ( $used ) {
			return new WP_Error( 'trx', __( 'This Transaction ID was already used.', 'fos-license-server' ) );
		}
		$sender = preg_replace( '/[^0-9]/', '', (string) $d['sender'] );
		if ( strlen( $sender ) < 11 ) {
			return new WP_Error( 'sender', __( 'Please enter the number you paid from.', 'fos-license-server' ) );
		}

		$wpdb->insert( // phpcs:ignore
			self::table(),
			array(
				'user_id'     => (int) $d['user_id'],
				'license_key' => $renewal ? '' : self::new_key(),
				'months'      => $months,
				'amount'      => $packages[ $months ],
				'domain'      => $domain,
				'method'      => $d['method'],
				'sender'      => $sender,
				'trx_id'      => $trx,
				'status'      => 'pending',
				'renewal_of'  => $renewal,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;

		$admin = get_option( 'admin_email' );
		/* translators: 1: months 2: domain 3: method 4: trx */
		wp_mail( $admin, 'New Fake Order Shield order #' . $id, sprintf( "%d month(s) for %s\nPaid by %s, TrxID %s, from %s\n\nApprove: %s", $months, $domain, $d['method'], $trx, $sender, admin_url( 'admin.php?page=fos-licenses' ) ) );
		return $id;
	}

	/**
	 * Free trial license: one per customer and one per domain, active right away.
	 *
	 * @param int    $user_id User.
	 * @param string $domain  Domain or URL.
	 * @return int|WP_Error Row id.
	 */
	public static function start_trial( $user_id, $domain ) {
		global $wpdb;
		$domain = self::domain( $domain );
		if ( ! $domain || false === strpos( $domain, '.' ) ) {
			return new WP_Error( 'domain', __( 'Please enter your website domain, e.g. myshop.com', 'fos-license-server' ) );
		}
		$t = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE user_id = %d AND method = 'trial'", $user_id ) ) ) { // phpcs:ignore
			return new WP_Error( 'trial', __( 'You have already used your free trial.', 'fos-license-server' ) );
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE domain = %s AND status <> 'rejected'", $domain ) ) ) { // phpcs:ignore
			return new WP_Error( 'trial', __( 'This domain already had a trial or a license.', 'fos-license-server' ) );
		}
		$now = time();
		$wpdb->insert( // phpcs:ignore
			$t,
			array(
				'user_id'     => (int) $user_id,
				'license_key' => self::new_key(),
				'months'      => 0,
				'amount'      => 0,
				'domain'      => $domain,
				'method'      => 'trial',
				'status'      => 'active',
				'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
				'approved_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', $now + max( 1, (int) self::opt( 'trial_days' ) ) * DAY_IN_SECONDS ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Approve a pending order: start or extend the license.
	 *
	 * @param int $id Order id.
	 * @return bool
	 */
	public static function approve( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || 'pending' !== $row->status ) {
			return false;
		}
		$now    = current_time( 'mysql', true );
		$target = $row->renewal_of ? self::get( $row->renewal_of ) : $row;
		if ( ! $target ) {
			return false;
		}
		// Extend from the later of now and the current expiry, so early renewals keep paid days.
		$base    = ( $target->expires_at && strtotime( $target->expires_at . ' UTC' ) > time() ) ? strtotime( $target->expires_at . ' UTC' ) : time();
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( '+' . (int) $row->months . ' months', $base ) );

		$data = array( 'status' => 'active', 'expires_at' => $expires, 'approved_at' => $target->approved_at ? $target->approved_at : $now );
		if ( 'trial' === $target->method ) {
			// First payment on a trial key: the key becomes a normal paid license.
			$data = array_merge( $data, array( 'method' => $row->method, 'months' => $row->months, 'amount' => $row->amount, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+' . (int) $row->months . ' months' ) ) ) );
			$expires = $data['expires_at'];
		}
		$wpdb->update( self::table(), $data, array( 'id' => $target->id ) ); // phpcs:ignore
		if ( $row->renewal_of ) {
			$wpdb->update( self::table(), array( 'status' => 'applied', 'approved_at' => $now, 'expires_at' => $expires ), array( 'id' => $row->id ) ); // phpcs:ignore
		}

		return true;
	}

	/**
	 * Ed25519 key pair, made once on this server. Only the public key ever leaves it.
	 *
	 * @return array [secret, public] raw bytes.
	 */
	public static function keys() {
		$k = get_option( 'fos_ls_keypair' );
		if ( ! is_array( $k ) || empty( $k['secret'] ) ) {
			$pair = sodium_crypto_sign_keypair();
			$k    = array(
				'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
			add_option( 'fos_ls_keypair', $k, '', false );
		}
		return $k;
	}

	/**
	 * Sign a message so the plugin can tell a real answer from a fake server.
	 *
	 * @param string $msg Message.
	 * @return string Base64 signature.
	 */
	public static function sign( $msg ) {
		return base64_encode( sodium_crypto_sign_detached( $msg, base64_decode( self::keys()['secret'] ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Account page URL.
	 *
	 * @return string
	 */
	public static function account_url() {
		$pages = get_option( 'fos_ls_pages', array() );
		return ! empty( $pages['account'] ) ? get_permalink( $pages['account'] ) : home_url( '/account/' );
	}

	/**
	 * Status of a license row for the API and the account page.
	 *
	 * @param object $row Row.
	 * @return string active|expired|pending|revoked|rejected
	 */
	public static function status( $row ) {
		if ( 'active' === $row->status && $row->expires_at && strtotime( $row->expires_at . ' UTC' ) < time() ) {
			return 'expired';
		}
		return $row->status;
	}
}
