<?php
/**
 * License + free trial, checked against fakeordershield.com.
 *
 * Front-end requests only read the saved state (no HTTP). The server is asked
 * when an admin opens WordPress (at most every 12 hours) or presses Activate.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_License {

	const OPTION = 'oshield_license';

	/**
	 * API base URL. Define OSHIELD_LICENSE_API in wp-config.php to point elsewhere (testing).
	 *
	 * @return string
	 */
	public static function api() {
		return defined( 'OSHIELD_LICENSE_API' ) ? OSHIELD_LICENSE_API : 'https://fakeordershield.com/wp-json/fos/v1/';
	}

	/**
	 * Saved state: key, status, expires (unix), trial_ends (unix), message, checked (unix).
	 *
	 * @return array
	 */
	public static function data() {
		$d = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $d ) ? $d : array(),
			array(
				'key'        => '',
				'status'     => '',
				'expires'    => 0,
				'trial_ends' => 0,
				'message'    => '',
				'checked'    => 0,
			)
		);
	}

	/**
	 * Save part of the state.
	 *
	 * @param array $changes Changes.
	 */
	private static function save( $changes ) {
		update_option( self::OPTION, array_merge( self::data(), $changes ), true );
	}

	/**
	 * This site's domain as the server stores it.
	 *
	 * @return string
	 */
	public static function domain() {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	/**
	 * Licensed and not expired.
	 *
	 * @return bool
	 */
	public static function licensed() {
		$d = self::data();
		return 'active' === $d['status'] && $d['expires'] > time();
	}

	/**
	 * Seconds of trial left (0 when over).
	 *
	 * @return int
	 */
	public static function trial_left() {
		$d    = self::data();
		$ends = $d['trial_ends'] ? (int) $d['trial_ends'] : self::local_trial_end();
		return max( 0, $ends - time() );
	}

	/**
	 * Fallback trial end before the server has answered: 3 days from first use.
	 *
	 * @return int
	 */
	private static function local_trial_end() {
		$first = (int) get_option( 'oshield_first_use' );
		if ( ! $first ) {
			$first = time();
			add_option( 'oshield_first_use', $first );
		}
		return $first + 3 * DAY_IN_SECONDS;
	}

	/**
	 * Features may run: licensed or still in trial.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::licensed() || self::trial_left() > 0;
	}

	/**
	 * POST to the license server.
	 *
	 * @param string $path Endpoint.
	 * @param array  $body Body.
	 * @return array|WP_Error Decoded JSON.
	 */
	private static function call( $path, $body ) {
		$r = wp_remote_post( self::api() . $path, array( 'timeout' => 10, 'body' => $body ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$json = json_decode( wp_remote_retrieve_body( $r ), true );
		return is_array( $json ) ? $json : new WP_Error( 'oshield_license', __( 'The license server sent an unexpected answer.', 'order-shield' ) );
	}

	/**
	 * Ask the server about the trial (starts it on first call for this domain).
	 */
	public static function sync_trial() {
		$r = self::call( 'trial', array( 'domain' => self::domain() ) );
		if ( ! is_wp_error( $r ) && ! empty( $r['trial_ends'] ) ) {
			self::save( array( 'trial_ends' => strtotime( $r['trial_ends'] ) ) );
		}
	}

	/**
	 * Check (or activate) a key with the server and save the answer.
	 *
	 * @param string $key Key; empty = the saved one.
	 * @return array|WP_Error Server answer.
	 */
	public static function check( $key = '' ) {
		$key = strtoupper( trim( $key ? $key : self::data()['key'] ) );
		if ( '' === $key ) {
			return new WP_Error( 'oshield_license', __( 'Please enter a license key.', 'order-shield' ) );
		}
		$r = self::call( 'license', array( 'license_key' => $key, 'domain' => self::domain() ) );
		if ( is_wp_error( $r ) ) {
			// Keep the last known state; expiry still applies locally.
			self::save( array( 'checked' => time() ) );
			return $r;
		}
		self::save(
			array(
				'key'     => $key,
				'status'  => isset( $r['status'] ) ? sanitize_key( $r['status'] ) : 'invalid',
				'expires' => ! empty( $r['expires_at'] ) ? strtotime( $r['expires_at'] ) : 0,
				'message' => isset( $r['message'] ) ? sanitize_text_field( $r['message'] ) : '',
				'checked' => time(),
			)
		);
		return $r;
	}

	/**
	 * Forget the key on this site.
	 */
	public static function deactivate() {
		self::save( array( 'key' => '', 'status' => '', 'expires' => 0, 'message' => '' ) );
	}

	/**
	 * Twice a day, when an admin is in wp-admin: refresh trial and license.
	 */
	public static function maybe_refresh() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || get_transient( 'oshield_license_fresh' ) ) {
			return;
		}
		set_transient( 'oshield_license_fresh', 1, 12 * HOUR_IN_SECONDS );
		if ( ! self::data()['trial_ends'] ) {
			self::sync_trial();
		}
		if ( self::data()['key'] ) {
			self::check();
		}
	}

	/**
	 * Short status for the header pill: [tone, text].
	 *
	 * @return array
	 */
	public static function badge() {
		$d = self::data();
		if ( self::licensed() ) {
			$days = (int) ceil( ( $d['expires'] - time() ) / DAY_IN_SECONDS );
			/* translators: %s date */
			return array( $days <= 7 ? 'medium' : 'good', sprintf( __( 'Licensed until %s', 'order-shield' ), date_i18n( get_option( 'date_format' ), $d['expires'] ) ) );
		}
		$left = self::trial_left();
		if ( $left > 0 ) {
			$days = (int) ceil( $left / DAY_IN_SECONDS );
			/* translators: %d days */
			return array( 'medium', sprintf( _n( 'Free trial: %d day left', 'Free trial: %d days left', $days, 'order-shield' ), $days ) );
		}
		return array( 'risky', __( 'Protection off — activate license', 'order-shield' ) );
	}
}
