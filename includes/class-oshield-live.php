<?php
/**
 * Live visitors: page views, who is online now, and site-wide session recording.
 *
 * The visit id is the oshield_itoken session cookie, the same id the incomplete-order capture
 * uses, so a visitor's replay covers the whole visit and also appears under Incomplete Orders.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Live {

	const ONLINE_SECONDS  = 90;
	const PING_SECONDS    = 25;
	const MAX_CHUNK_BYTES = 1500000;
	const MAX_CHUNKS      = 400;
	const MAX_PAGEVIEWS   = 500;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 5 );
		add_action( 'wp_ajax_oshield_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_oshield_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_oshield_record', array( __CLASS__, 'ajax_record' ) );
		add_action( 'wp_ajax_nopriv_oshield_record', array( __CLASS__, 'ajax_record' ) );
		add_action( 'oshield_hourly', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Visitors table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oshield_visitors';
	}

	/**
	 * Should the current request be ignored (bots, shop staff)?
	 *
	 * @return bool
	 */
	public static function excluded() {
		if ( oshield_on( 'live_exclude_admins' ) && is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$ua = strtolower( oshield_get_ua() );
		return '' === $ua || (bool) preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|lighthouse|headlesschrome|pingdom|uptime|curl|wget|python/', $ua );
	}

	/**
	 * Is replay recording wanted on this page?
	 *
	 * @return bool
	 */
	private static function record_here() {
		if ( ! oshield_on( 'replay_enabled' ) ) {
			return false;
		}
		if ( 'checkout' === oshield_opt( 'replay_scope' ) ) {
			return oshield_on( 'incomplete_enabled' ) && OShield_Checkout::is_checkout();
		}
		return oshield_on( 'live_enabled' ) || ( oshield_on( 'incomplete_enabled' ) && OShield_Checkout::is_checkout() );
	}

	/**
	 * Load the tracker on every frontend page.
	 */
	public static function enqueue() {
		$track  = oshield_on( 'live_enabled' );
		$record = self::record_here();
		if ( ( ! $track && ! $record ) || self::excluded() || is_admin() ) {
			return;
		}
		$deps = array( 'oshield-fp' );
		if ( $record ) {
			wp_register_script( 'oshield-rrweb-record', OSHIELD_URL . 'assets/vendor/rrweb/rrweb-record.min.js', array(), '2.1.7', true );
			$deps[] = 'oshield-rrweb-record';
		}
		wp_enqueue_script( 'oshield-tracker', OSHIELD_URL . 'assets/js/tracker.js', $deps, OSHIELD_VERSION, true );
		wp_localize_script(
			'oshield-tracker',
			'OShieldTracker',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'track'     => $track ? 1 : 0,
				'record'    => $record ? 1 : 0,
				'ping'      => self::PING_SECONDS,
				'maxRecord' => max( 30, (int) oshield_opt( 'replay_max_seconds' ) ),
				'maskAll'   => 'all' === oshield_opt( 'replay_mask' ) ? 1 : 0,
			)
		);
	}

	/**
	 * Clean a session id.
	 *
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function clean_id( $raw ) {
		return substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw ), 0, 64 );
	}

	/**
	 * Very small user agent parser.
	 *
	 * @param string $ua User agent.
	 * @return array device, browser, os
	 */
	public static function parse_ua( $ua ) {
		$device = 'desktop';
		if ( preg_match( '/iPad|Tablet|Tab\b|SM-T|Kindle|Silk/i', $ua ) || ( stripos( $ua, 'Android' ) !== false && stripos( $ua, 'Mobile' ) === false ) ) {
			$device = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android|Opera Mini|IEMobile/i', $ua ) ) {
			$device = 'mobile';
		}
		$browsers = array(
			'Facebook'  => '/FBAN|FBAV|FB_IAB/',
			'Instagram' => '/Instagram/',
			'Edge'      => '/Edg\//',
			'Opera'     => '/OPR\/|Opera/',
			'Samsung'   => '/SamsungBrowser/',
			'UC Browser'=> '/UCBrowser/',
			'Firefox'   => '/Firefox|FxiOS/',
			'Chrome'    => '/Chrome|CriOS/',
			'Safari'    => '/Safari/',
		);
		$browser = __( 'Other', 'order-shield' );
		foreach ( $browsers as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}
		$oses = array(
			'Android'  => '/Android/',
			'iOS'      => '/iPhone|iPad|iPod/',
			'Windows'  => '/Windows/',
			'macOS'    => '/Mac OS X|Macintosh/',
			'Linux'    => '/Linux/',
		);
		$os = '';
		foreach ( $oses as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$os = $name;
				break;
			}
		}
		return array( $device, $browser, $os );
	}

	/**
	 * Cart summary for the current visitor.
	 *
	 * @return array [count, total]
	 */
	private static function cart() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return array( (int) WC()->cart->get_cart_contents_count(), (float) WC()->cart->get_total( 'edit' ) );
		}
		return array( 0, 0 );
	}

	/**
	 * Keep a URL short and on this site.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function clean_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return substr( $url, 0, 255 );
	}

	/**
	 * AJAX: page view ("view") or heartbeat ("ping").
	 * No nonce on purpose: pages are often cached, and this only records anonymous analytics.
	 */
	public static function ajax_track() {
		if ( ! oshield_on( 'live_enabled' ) || self::excluded() ) {
			wp_send_json_success( array( 'skip' => 1 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sid  = self::clean_id( isset( $_POST['sid'] ) ? wp_unslash( $_POST['sid'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$type = isset( $_POST['type'] ) && 'view' === $_POST['type'] ? 'view' : 'ping';
		$url  = isset( $_POST['url'] ) ? self::clean_url( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$title= isset( $_POST['title'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['title'] ) ), 0, 255 ) : '';
		$ref  = isset( $_POST['ref'] ) ? self::clean_url( wp_unslash( $_POST['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
		if ( strlen( $sid ) < 16 ) {
			wp_send_json_error();
		}
		// Only count pages of this site.
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $url && wp_parse_url( $url, PHP_URL_HOST ) !== $home ) {
			wp_send_json_error();
		}
		if ( $ref && wp_parse_url( $ref, PHP_URL_HOST ) === $home ) {
			$ref = ''; // Internal navigation is not a source.
		}

		global $wpdb;
		$now          = current_time( 'mysql' );
		list( $count, $total ) = self::cart();
		$row          = $wpdb->get_row( $wpdb->prepare( 'SELECT id, pages FROM ' . self::table() . ' WHERE session_id = %s', $sid ) ); // phpcs:ignore

		if ( ! $row ) {
			$ua                         = oshield_get_ua();
			list( $device, $br, $os )   = self::parse_ua( $ua );
			$country                    = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) ) : '';
			$wpdb->insert( // phpcs:ignore
				self::table(),
				array(
					'session_id'    => $sid,
					'device_id'     => oshield_get_device_id(),
					'hwid'          => oshield_get_hwid(),
					'ip'            => oshield_get_ip(),
					'country'       => 'XX' === $country ? '' : $country,
					'user_agent'    => $ua,
					'device'        => $device,
					'browser'       => $br,
					'os'            => $os,
					'referrer'      => $ref,
					'landing_url'   => $url,
					'current_url'   => $url,
					'current_title' => $title,
					'pages'         => 'view' === $type ? 1 : 0,
					'user_id'       => get_current_user_id(),
					'cart_count'    => $count,
					'cart_total'    => $total,
					'first_seen'    => $now,
					'last_seen'     => $now,
				)
			);
		} else {
			$data = array(
				'last_seen'  => $now,
				'cart_count' => $count,
				'cart_total' => $total,
				'user_id'    => get_current_user_id(),
			);
			if ( oshield_get_hwid() ) {
				$data['hwid'] = oshield_get_hwid();
			}
			if ( $url ) {
				$data['current_url']   = $url;
				$data['current_title'] = $title;
			}
			if ( 'view' === $type ) {
				$data['pages'] = (int) $row->pages + 1;
			}
			$wpdb->update( self::table(), $data, array( 'id' => $row->id ) ); // phpcs:ignore
		}

		if ( 'view' === $type && $url && ( ! $row || (int) $row->pages < self::MAX_PAGEVIEWS ) ) {
			$wpdb->insert( // phpcs:ignore
				$wpdb->prefix . 'oshield_pageviews',
				array(
					'session_id' => $sid,
					'url'        => $url,
					'title'      => $title,
					'created_at' => $now,
				)
			);
		}
		wp_send_json_success( array( 'watch' => self::is_watched( $sid ) ? 1 : 0 ) );
	}

	/**
	 * AJAX: store a chunk of rrweb events.
	 */
	public static function ajax_record() {
		if ( ! oshield_on( 'replay_enabled' ) || self::excluded() ) {
			wp_send_json_success( array( 'skip' => 1 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$sid    = self::clean_id( isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '' );
		$events = isset( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : '';
		// phpcs:enable
		if ( strlen( $sid ) < 16 || '' === $events || strlen( $events ) > self::MAX_CHUNK_BYTES ) {
			wp_send_json_error();
		}
		$decoded = json_decode( $events, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error();
		}
		if ( ! $decoded ) {
			wp_send_json_success( array( 'watch' => self::is_watched( $sid ) ? 1 : 0 ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'oshield_recordings';
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE token = %s", $sid ) ); // phpcs:ignore
		if ( $count >= self::MAX_CHUNKS ) {
			wp_send_json_success( array( 'full' => true ) );
		}
		$wpdb->insert( // phpcs:ignore
			$table,
			array(
				'token'      => $sid,
				'chunk'      => $count,
				'events'     => wp_json_encode( $decoded ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		wp_send_json_success( array( 'watch' => self::is_watched( $sid ) ? 1 : 0 ) );
	}

	/**
	 * Mark a visit as being watched live by an admin (the tracker then sends updates every second).
	 *
	 * @param string $sid Session id.
	 */
	public static function set_watched( $sid ) {
		set_transient( 'oshield_watch_' . md5( $sid ), 1, 20 );
	}

	/**
	 * Is an admin watching this visit live?
	 *
	 * @param string $sid Session id.
	 * @return bool
	 */
	public static function is_watched( $sid ) {
		return (bool) get_transient( 'oshield_watch_' . md5( $sid ) );
	}

	/**
	 * Recording events stored after a given row id (for live watching).
	 *
	 * @param string $sid   Session id.
	 * @param int    $after Last recording row id the admin already has.
	 * @return array [events, last_id]
	 */
	public static function events_after( $sid, $after ) {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, events FROM {$wpdb->prefix}oshield_recordings WHERE token = %s AND id > %d ORDER BY id ASC LIMIT 200", self::clean_id( $sid ), (int) $after ) ); // phpcs:ignore
		$events = array();
		$last   = (int) $after;
		foreach ( $rows as $r ) {
			$chunk = json_decode( $r->events, true );
			if ( is_array( $chunk ) ) {
				$events = array_merge( $events, $chunk );
			}
			$last = (int) $r->id;
		}
		return array( $events, $last );
	}

	/**
	 * Delete old visitor history (and recordings that no incomplete order needs).
	 */
	public static function cleanup() {
		global $wpdb;
		$days   = max( 1, (int) oshield_opt( 'live_retention' ) );
		$before = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}oshield_pageviews WHERE created_at < %s", $before ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "DELETE r FROM {$wpdb->prefix}oshield_recordings r INNER JOIN " . self::table() . " v ON v.session_id = r.token LEFT JOIN {$wpdb->prefix}oshield_incomplete i ON i.token = r.token WHERE i.id IS NULL AND v.last_seen < %s", $before ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE last_seen < %s', $before ) ); // phpcs:ignore

		// Recordings older than the replay retention, unless a pending incomplete order still needs them.
		$rdays = max( 1, (int) oshield_opt( 'replay_retention' ) );
		$rbefore = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $rdays * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$wpdb->query( $wpdb->prepare( "DELETE r FROM {$wpdb->prefix}oshield_recordings r LEFT JOIN {$wpdb->prefix}oshield_incomplete i ON i.token = r.token AND i.status = 'pending' WHERE i.id IS NULL AND r.created_at < %s", $rbefore ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ *
	 * Data for the admin screen
	 * ------------------------------------------------------------------ */

	/**
	 * "Online" cut-off time.
	 *
	 * @return string
	 */
	public static function online_since() {
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ONLINE_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	/**
	 * Headline numbers.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$t      = self::table();
		$pv     = $wpdb->prefix . 'oshield_pageviews';
		$today  = current_time( 'Y-m-d' ) . ' 00:00:00';
		$min30  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$online = self::online_since();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array(
			'online'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s", $online ) ),
			'online_cart'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s AND cart_count > 0", $online ) ),
			'online_mobile'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s AND device = 'mobile'", $online ) ),
			'views_30'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pv} WHERE created_at >= %s", $min30 ) ),
			'views_today'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pv} WHERE created_at >= %s", $today ) ),
			'visitors_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s", $today ) ),
			'orders_today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen >= %s AND order_id > 0", $today ) ),
		);
		// phpcs:enable
	}

	/**
	 * Pages being viewed right now.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function top_pages( $limit = 8 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT current_url url, MAX(current_title) title, COUNT(*) c FROM ' . self::table() . ' WHERE last_seen >= %s GROUP BY current_url ORDER BY c DESC LIMIT %d', self::online_since(), $limit ) ); // phpcs:ignore
	}

	/**
	 * Visitor rows with incomplete-order details joined in.
	 *
	 * @param string $view   online|today|all.
	 * @param string $search Search term.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function visitors( $view = 'online', $search = '', $limit = 200 ) {
		global $wpdb;
		$t     = self::table();
		$where = array();
		if ( 'online' === $view ) {
			$where[] = $wpdb->prepare( 'v.last_seen >= %s', self::online_since() );
		} elseif ( 'today' === $view ) {
			$where[] = $wpdb->prepare( 'v.last_seen >= %s', current_time( 'Y-m-d' ) . ' 00:00:00' );
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = $wpdb->prepare( '(v.ip LIKE %s OR v.current_url LIKE %s OR v.current_title LIKE %s OR i.phone LIKE %s OR i.name LIKE %s)', $like, $like, $like, $like, $like );
		}
		$sql_w = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT v.*, i.id incomplete_id, i.phone, i.name FROM {$t} v LEFT JOIN {$wpdb->prefix}oshield_incomplete i ON i.token = v.session_id {$sql_w} ORDER BY v.last_seen DESC LIMIT %d", $limit ) );
		$rec  = OShield_Incomplete::has_recordings( wp_list_pluck( $rows, 'session_id' ) );
		foreach ( $rows as $r ) {
			$r->has_recording = ! empty( $rec[ $r->session_id ] );
		}
		return $rows;
	}

	/**
	 * Pages one visit went through.
	 *
	 * @param string $sid Session id.
	 * @return array
	 */
	public static function journey( $sid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT url, title, created_at FROM {$wpdb->prefix}oshield_pageviews WHERE session_id = %s ORDER BY id ASC LIMIT %d", self::clean_id( $sid ), self::MAX_PAGEVIEWS ) ); // phpcs:ignore
	}

	/**
	 * One visitor row.
	 *
	 * @param string $sid Session id.
	 * @return object|null
	 */
	public static function get( $sid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE session_id = %s', self::clean_id( $sid ) ) ); // phpcs:ignore
	}
}
