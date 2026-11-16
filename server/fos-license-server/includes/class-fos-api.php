<?php
/**
 * Public API the Fake Order Shield plugin calls.
 *
 * POST /wp-json/fos/v1/license  {license_key, domain}  → status of that key for that domain
 * POST /wp-json/fos/v1/trial    {domain}               → trial end (started once per domain)
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FOS_API {

	/**
	 * Register routes.
	 */
	public static function routes() {
		register_rest_route(
			'fos/v1',
			'/license',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'license' ),
				'permission_callback' => '__return_true', // Public: the key itself is the secret.
			)
		);
		register_rest_route(
			'fos/v1',
			'/trial',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'trial' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Slow down key guessing: 60 calls per IP per hour.
	 *
	 * @return bool True when over the limit.
	 */
	private static function limited() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$k  = 'fos_rl_' . md5( $ip );
		$n  = (int) get_transient( $k );
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		return $n >= 60;
	}

	/**
	 * License check / activation.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function license( $r ) {
		if ( self::limited() ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Too many requests. Try again in an hour.' ), 429 );
		}
		$domain = FOS_Store::domain( $r->get_param( 'domain' ) );
		$row    = FOS_Store::by_key( (string) $r->get_param( 'license_key' ) );
		$key = strtoupper( trim( (string) $r->get_param( 'license_key' ) ) );
		if ( ! $row || ! $domain ) {
			return new WP_REST_Response( self::signed( $key, $domain, array( 'status' => 'invalid', 'message' => 'License key not found.' ) ), 200 );
		}
		$status = FOS_Store::status( $row );
		$out    = array(
			'status'     => $status,
			'domain'     => $row->domain,
			'expires_at' => $row->expires_at ? gmdate( 'c', strtotime( $row->expires_at . ' UTC' ) ) : null,
			'months'     => (int) $row->months,
			'trial'      => 'trial' === $row->method,
			'renew_url'  => FOS_Store::account_url(),
		);
		if ( $row->domain !== $domain ) {
			$out['status']  = 'domain_mismatch';
			$out['message'] = 'This key is for ' . $row->domain . '.';
			return new WP_REST_Response( self::signed( $key, $domain, $out ), 200 );
		}
		global $wpdb;
		$wpdb->update( FOS_Store::table(), array( 'last_check' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) ); // phpcs:ignore
		$messages      = array(
			'active'   => 'License active.',
			'expired'  => 'License expired. Please renew.',
			'revoked'  => 'License disabled. Please contact support.',
			'rejected' => 'Order was not approved.',
		);
		$out['message'] = isset( $messages[ $status ] ) ? $messages[ $status ] : '';
		return new WP_REST_Response( self::signed( $key, $domain, $out ), 200 );
	}

	/**
	 * Add ts + sig to a license answer. Signed text: license|KEY|domain|status|expires_unix|ts
	 *
	 * @param string $key    Key as sent.
	 * @param string $domain Domain as sent (normalised).
	 * @param array  $out    Answer.
	 * @return array
	 */
	private static function signed( $key, $domain, $out ) {
		$out['ts']  = time();
		$exp        = ! empty( $out['expires_at'] ) ? strtotime( $out['expires_at'] ) : 0;
		$out['sig'] = FOS_Store::sign( 'license|' . $key . '|' . $domain . '|' . $out['status'] . '|' . $exp . '|' . $out['ts'] );
		return $out;
	}

	/**
	 * Start (once) and report the free trial of a domain.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response
	 */
	public static function trial( $r ) {
		if ( self::limited() ) {
			return new WP_REST_Response( array( 'status' => 'error' ), 429 );
		}
		$domain = FOS_Store::domain( $r->get_param( 'domain' ) );
		if ( ! $domain ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Bad domain.' ), 400 );
		}
		global $wpdb;
		$t = FOS_Store::trials();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (domain, started_at, ip) VALUES (%s, %s, %s)", $domain, current_time( 'mysql', true ), isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) ); // phpcs:ignore
		$started = $wpdb->get_var( $wpdb->prepare( "SELECT started_at FROM $t WHERE domain = %s", $domain ) ); // phpcs:ignore
		$ends    = strtotime( $started . ' UTC' ) + max( 0, (int) FOS_Store::opt( 'trial_days' ) ) * DAY_IN_SECONDS;
		$ts      = time();
		return new WP_REST_Response(
			array(
				'status'     => $ends > $ts ? 'trial' : 'trial_ended',
				'trial_ends' => gmdate( 'c', $ends ),
				'buy_url'    => FOS_Store::account_url(),
				'ts'         => $ts,
				'sig'        => FOS_Store::sign( 'trial|' . $domain . '|' . $ends . '|' . $ts ), // Signed text: trial|domain|ends_unix|ts.
			),
			200
		);
	}
}
