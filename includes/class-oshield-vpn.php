<?php
/**
 * VPN / proxy and country restriction.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_VPN {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ( oshield_on( 'vpn_block' ) || oshield_on( 'country_restrict' ) ) && 'site' === oshield_opt( 'vpn_scope' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'guard_site' ), 1 );
		}
	}

	/**
	 * Is the feature active at all?
	 *
	 * @return bool
	 */
	public static function active() {
		return oshield_on( 'vpn_block' ) || oshield_on( 'country_restrict' );
	}

	/**
	 * Look up an IP (cached).
	 *
	 * @param string $ip IP.
	 * @return array{proxy:bool,type:string,country:string}|null Null when lookup failed.
	 */
	public static function lookup( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return null;
		}
		$key    = 'oshield_ip_' . md5( $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = 'https://proxycheck.io/v2/' . rawurlencode( $ip ) . '?vpn=1&asn=1';
		$k   = trim( (string) oshield_opt( 'proxycheck_key' ) );
		if ( '' !== $k ) {
			$url .= '&key=' . rawurlencode( $k );
		}
		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			set_transient( $key, array(), 10 * MINUTE_IN_SECONDS );
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body[ $ip ] ) ) {
			set_transient( $key, array(), 10 * MINUTE_IN_SECONDS );
			return null;
		}
		$row  = $body[ $ip ];
		$data = array(
			'proxy'   => isset( $row['proxy'] ) && 'yes' === $row['proxy'],
			'type'    => isset( $row['type'] ) ? sanitize_text_field( $row['type'] ) : '',
			'country' => isset( $row['isocode'] ) ? strtoupper( sanitize_text_field( $row['isocode'] ) ) : '',
		);
		set_transient( $key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Decide whether the current visitor must be blocked.
	 *
	 * @return array{blocked:bool,reason:string,country:string}
	 */
	public static function check_current() {
		$out = array(
			'blocked' => false,
			'reason'  => '',
			'country' => '',
		);
		if ( ! self::active() ) {
			return $out;
		}
		$info = self::lookup( oshield_get_ip() );
		if ( empty( $info ) ) {
			return $out; // Fail open.
		}
		$out['country'] = $info['country'];

		if ( oshield_on( 'vpn_block' ) && $info['proxy'] ) {
			$out['blocked'] = true;
			$out['reason']  = 'VPN/Proxy' . ( $info['type'] ? ' (' . $info['type'] . ')' : '' );
			return $out;
		}
		if ( oshield_on( 'country_restrict' ) && '' !== $info['country'] ) {
			$allowed = array_filter( array_map( 'strtoupper', array_map( 'trim', explode( ',', (string) oshield_opt( 'allowed_countries' ) ) ) ) );
			if ( $allowed && ! in_array( $info['country'], $allowed, true ) ) {
				$out['blocked'] = true;
				/* translators: %s country code */
				$out['reason'] = sprintf( __( 'Country not allowed (%s)', 'order-shield' ), $info['country'] );
			}
		}
		return $out;
	}

	/**
	 * Whole-site guard.
	 */
	public static function guard_site() {
		if ( is_user_logged_in() && current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$ua = strtolower( oshield_get_ua() );
		if ( preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegram|lighthouse/', $ua ) ) {
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session && WC()->session->get( 'oshield_vpn_ok' ) ) {
			return;
		}
		$check = self::check_current();
		if ( ! $check['blocked'] ) {
			if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'has_session' ) && WC()->session->has_session() ) {
				WC()->session->set( 'oshield_vpn_ok', 1 );
			}
			return;
		}
		oshield_log_event( 'vpn', $check['reason'], array( 'country' => $check['country'] ) );
		status_header( 403 );
		nocache_headers();
		$title = __( 'Access blocked', 'order-shield' );
		$msg   = esc_html( oshield_opt( 'vpn_message' ) ) . oshield_support_html();
		wp_die( '<h1>' . esc_html( $title ) . '</h1><p>' . wp_kses_post( $msg ) . '</p>', esc_html( $title ), array( 'response' => 403 ) );
	}
}
