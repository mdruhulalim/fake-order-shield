<?php
/**
 * License key (paid or free trial), checked against fakeordershield.com.
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
	 * License server. Fixed on purpose: answers must also carry a signature from its key.
	 */
	const API = 'https://fakeordershield.com/wp-json/fos/v1/';

	/**
	 * fakeordershield.com signing public key (Ed25519, base64).
	 */
	const PUBLIC_KEY = 'oJEiT5E+13bSwMtPkvhTopLVdu41ashtBqpgaZuuyQA=';

	/**
	 * Saved state: key, status, expires (unix), trial (bool), message, checked (unix).
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
				'trial'      => false,
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
	 * Features may run: a paid or free-trial key is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return self::licensed();
	}

	/**
	 * POST to the license server.
	 *
	 * @param string $path Endpoint.
	 * @param array  $body Body.
	 * @return array|WP_Error Decoded JSON.
	 */
	private static function call( $path, $body ) {
		$r = wp_remote_post( self::API . $path, array( 'timeout' => 10, 'body' => $body ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$json = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $json ) || ! self::verified( $body, $json ) ) {
			return new WP_Error( 'oshield_license', __( 'Could not verify the answer from the license server.', 'order-shield' ) );
		}
		return $json;
	}

	/**
	 * Is this answer really from fakeordershield.com, about this request, and recent?
	 *
	 * @param array $body What we sent.
	 * @param array $r    Answer.
	 * @return bool
	 */
	private static function verified( $body, $r ) {
		if ( empty( $r['sig'] ) || empty( $r['ts'] ) || abs( time() - (int) $r['ts'] ) > DAY_IN_SECONDS ) {
			return false;
		}
		$exp = ! empty( $r['expires_at'] ) ? strtotime( $r['expires_at'] ) : 0;
		$msg = 'license|' . $body['license_key'] . '|' . $body['domain'] . '|' . ( $r['status'] ?? '' ) . '|' . $exp . '|' . (int) $r['ts'];
		$sig = base64_decode( (string) $r['sig'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$pub = base64_decode( self::PUBLIC_KEY, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $sig || false === $pub || 64 !== strlen( $sig ) || 32 !== strlen( $pub ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $msg, $pub );
		} catch ( Exception $e ) {
			return false;
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
				'trial'   => ! empty( $r['trial'] ),
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
	 * Twice a day, when an admin is in wp-admin: refresh the license.
	 */
	public static function maybe_refresh() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || get_transient( 'oshield_license_fresh' ) || ! self::data()['key'] ) {
			return;
		}
		set_transient( 'oshield_license_fresh', 1, 12 * HOUR_IN_SECONDS );
		self::check();
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
			if ( $d['trial'] ) {
				/* translators: %d days */
				return array( 'medium', sprintf( _n( 'Free trial: %d day left', 'Free trial: %d days left', $days, 'order-shield' ), $days ) );
			}
			/* translators: %s date */
			return array( $days <= 7 ? 'medium' : 'good', sprintf( __( 'Licensed until %s', 'order-shield' ), date_i18n( get_option( 'date_format' ), $d['expires'] ) ) );
		}
		return array( 'risky', $d['key'] ? __( 'Protection off — renew license', 'order-shield' ) : __( 'Protection off — activate license', 'order-shield' ) );
	}
}
