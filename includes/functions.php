<?php
/**
 * Shared helper functions.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a single setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function oshield_opt( $key ) {
	return OShield_Settings::get( $key );
}

/**
 * Whether a checkbox-style setting is on.
 *
 * @param string $key Setting key.
 * @return bool
 */
function oshield_on( $key ) {
	return '1' === (string) OShield_Settings::get( $key );
}

/**
 * Convert Bangla digits to English digits and strip everything except digits and a leading plus.
 *
 * @param string $value Raw input.
 * @return string
 */
function oshield_clean_digits( $value ) {
	$value = (string) $value;
	$bn    = array( '০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯' );
	$value = str_replace( $bn, array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), $value );
	return preg_replace( '/[^0-9]/', '', $value );
}

/**
 * Normalize a Bangladeshi mobile number to the local 11 digit format (01XXXXXXXXX).
 * Returns an empty string when the number is not a valid BD mobile number.
 *
 * @param string $phone Raw phone.
 * @return string
 */
function oshield_normalize_phone( $phone ) {
	$digits = oshield_clean_digits( $phone );

	if ( 0 === strpos( $digits, '0088' ) ) {
		$digits = substr( $digits, 4 );
	} elseif ( 0 === strpos( $digits, '88' ) && strlen( $digits ) === 13 ) {
		$digits = substr( $digits, 2 );
	} elseif ( strlen( $digits ) === 10 && '1' === $digits[0] ) {
		$digits = '0' . $digits;
	}

	if ( preg_match( '/^01[3-9][0-9]{8}$/', $digits ) ) {
		return $digits;
	}
	return '';
}

/**
 * All formats a phone may have been saved in on an order.
 *
 * @param string $phone Normalized phone (01XXXXXXXXX).
 * @return string[]
 */
function oshield_phone_variations( $phone ) {
	$phone = oshield_normalize_phone( $phone );
	if ( '' === $phone ) {
		return array();
	}
	$tail = substr( $phone, 1 );
	return array_values(
		array_unique(
			array(
				$phone,
				'+88' . $phone,
				'88' . $phone,
				$tail,
				'+880' . $tail,
				'880' . $tail,
				substr( $phone, 0, 5 ) . '-' . substr( $phone, 5 ),
			)
		)
	);
}

/**
 * Best guess of the visitor's IP address.
 *
 * @return string
 */
function oshield_get_ip() {
	$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
	foreach ( $candidates as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		foreach ( explode( ',', $raw ) as $ip ) {
			$ip = trim( $ip );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

/**
 * Current visitor's device id (long lived cookie set by the frontend script or server).
 *
 * @return string
 */
function oshield_get_device_id() {
	if ( ! empty( $_COOKIE['oshield_did'] ) ) {
		$did = preg_replace( '/[^a-zA-Z0-9\-]/', '', wp_unslash( $_COOKIE['oshield_did'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return substr( $did, 0, 64 );
	}
	return '';
}

/**
 * Hardware fingerprint of the current visitor (same in every browser on one device), set by fp.js.
 *
 * @return string
 */
function oshield_get_hwid() {
	if ( ! empty( $_COOKIE['oshield_hw'] ) ) {
		$hw = preg_replace( '/[^a-z0-9\-]/', '', strtolower( wp_unslash( $_COOKIE['oshield_hw'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return 0 === strpos( $hw, 'hw-' ) ? substr( $hw, 0, 40 ) : '';
	}
	return '';
}

/**
 * Network part of an IP (first 3 parts of IPv4, first 4 groups of IPv6).
 *
 * @param string $ip IP.
 * @return string
 */
function oshield_net_prefix( $ip ) {
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		return implode( '.', array_slice( explode( '.', $ip ), 0, 3 ) );
	}
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$full = inet_ntop( inet_pton( $ip ) );
		$exp  = explode( ':', str_replace( '::', str_repeat( ':0', 8 - substr_count( $full, ':' ) ) . ':', $full ) );
		return implode( ':', array_slice( array_filter( $exp, 'strlen' ), 0, 4 ) );
	}
	return '';
}

/**
 * Visitor user agent.
 *
 * @return string
 */
function oshield_get_ua() {
	return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
}

/**
 * Find order IDs whose billing phone matches any variation of the given phone.
 *
 * @param string $phone   Phone number.
 * @param array  $args    Extra wc_get_orders args.
 * @return int[]
 */
function oshield_get_order_ids_by_phone( $phone, $args = array() ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$ids = array();
	foreach ( oshield_phone_variations( $phone ) as $variation ) {
		$found = wc_get_orders(
			array_merge(
				array(
					'billing_phone' => $variation,
					'limit'         => -1,
					'return'        => 'ids',
					'type'          => 'shop_order',
					'status'        => array_keys( wc_get_order_statuses() ),
				),
				$args
			)
		);
		$ids = array_merge( $ids, array_map( 'intval', (array) $found ) );
	}
	$ids = array_values( array_unique( $ids ) );
	rsort( $ids );
	return $ids;
}

/**
 * Order IDs placed from the same device id.
 *
 * @param string $device_id Device id.
 * @return int[]
 */
function oshield_get_order_ids_by_meta( $key, $value ) {
	if ( '' === (string) $value || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}
	$ids = wc_get_orders(
		array(
			'limit'      => 50,
			'return'     => 'ids',
			'type'       => 'shop_order',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => $key,
					'value' => $value,
				),
			),
		)
	);
	return array_map( 'intval', (array) $ids );
}

/**
 * Replace {placeholders} in a template.
 *
 * @param string $template Template.
 * @param array  $vars     key => value.
 * @return string
 */
function oshield_fill( $template, $vars ) {
	$search  = array();
	$replace = array();
	foreach ( $vars as $k => $v ) {
		$search[]  = '{' . $k . '}';
		$replace[] = (string) $v;
	}
	return str_replace( $search, $replace, (string) $template );
}

/**
 * Log an activity event (blocked attempt, VPN detection, etc).
 *
 * @param string $type   Event type.
 * @param string $reason Human readable reason.
 * @param array  $data   phone, ip, device_id, country.
 */
function oshield_log_event( $type, $reason, $data = array() ) {
	global $wpdb;
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->prefix . 'oshield_events',
		array(
			'type'       => substr( $type, 0, 30 ),
			'phone'      => isset( $data['phone'] ) ? substr( (string) $data['phone'], 0, 32 ) : '',
			'ip'         => isset( $data['ip'] ) ? (string) $data['ip'] : oshield_get_ip(),
			'device_id'  => isset( $data['device_id'] ) ? (string) $data['device_id'] : oshield_get_device_id(),
			'country'    => isset( $data['country'] ) ? substr( (string) $data['country'], 0, 5 ) : '',
			'reason'     => (string) $reason,
			'created_at' => current_time( 'mysql' ),
		)
	);
}

/**
 * Support number link HTML for customer facing messages.
 *
 * @return string
 */
function oshield_support_html() {
	$num = trim( (string) oshield_opt( 'support_number' ) );
	if ( '' === $num ) {
		return '';
	}
	return ' <a class="oshield-call" href="tel:' . esc_attr( $num ) . '">' . esc_html( sprintf( /* translators: %s phone */ __( 'Call: %s', 'order-shield' ), $num ) ) . '</a>';
}
