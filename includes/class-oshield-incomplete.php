<?php
/**
 * Incomplete (abandoned) checkout capture, session replay storage, conversion and cleanup.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Incomplete {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_oshield_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'wp_ajax_nopriv_oshield_capture', array( __CLASS__, 'ajax_capture' ) );
		add_action( 'oshield_order_placed', array( __CLASS__, 'on_order_placed' ), 10, 2 );
		add_action( 'oshield_hourly', array( __CLASS__, 'cleanup' ) );
		add_action( 'oshield_daily', array( __CLASS__, 'digest' ) );
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oshield_incomplete';
	}

	/**
	 * Clean token from request.
	 *
	 * @param string $raw Raw.
	 * @return string
	 */
	private static function clean_token( $raw ) {
		return substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $raw ), 0, 64 );
	}

	/**
	 * Current cart as a simple array.
	 *
	 * @return array{items:array,total:float,shipping:array}
	 */
	private static function cart_snapshot() {
		$out = array(
			'items'    => array(),
			'total'    => 0,
			'shipping' => array(),
		);
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		$cart = WC()->cart;
		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$out['items'][] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'variation'    => isset( $item['variation'] ) ? (array) $item['variation'] : array(),
				'quantity'     => (int) $item['quantity'],
				'name'         => $product->get_name(),
				'line_total'   => (float) $item['line_total'],
			);
		}
		$out['total'] = (float) $cart->get_total( 'edit' );

		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		if ( ! empty( $chosen[0] ) ) {
			$out['shipping'] = array(
				'method' => (string) $chosen[0],
				'cost'   => (float) $cart->get_shipping_total(),
			);
		}
		return $out;
	}

	/**
	 * AJAX: save checkout form data.
	 */
	public static function ajax_capture() {
		check_ajax_referer( 'oshield_front', 'nonce' );
		if ( ! oshield_on( 'incomplete_enabled' ) || OShield_Checkout::in_cooldown() ) {
			wp_send_json_success( array( 'skipped' => 'disabled' ) );
		}
		$token = self::clean_token( isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw   = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( strlen( $token ) < 16 ) {
			wp_send_json_error();
		}

		$fields = array();
		foreach ( $raw as $k => $v ) {
			$k = sanitize_key( $k );
			if ( preg_match( '/^(billing|shipping)_|^order_comments$/', $k ) && ! preg_match( '/password|card|cvv|cvc/', $k ) && is_scalar( $v ) ) {
				$fields[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		$phone = oshield_normalize_phone( isset( $fields['billing_phone'] ) ? $fields['billing_phone'] : '' );
		if ( '' === $phone ) {
			wp_send_json_success( array( 'skipped' => 'phone' ) );
		}
		if ( oshield_on( 'incomplete_skip_existing' ) && oshield_get_order_ids_by_phone( $phone, array( 'limit' => 1 ) ) ) {
			wp_send_json_success( array( 'skipped' => 'existing' ) );
		}

		$cart    = self::cart_snapshot();
		$state   = isset( $fields['billing_state'] ) ? $fields['billing_state'] : '';
		$country = isset( $fields['billing_country'] ) ? $fields['billing_country'] : 'BD';
		if ( $state && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			$state  = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		}
		$name    = trim( ( isset( $fields['billing_first_name'] ) ? $fields['billing_first_name'] : '' ) . ' ' . ( isset( $fields['billing_last_name'] ) ? $fields['billing_last_name'] : '' ) );
		$address = implode(
			', ',
			array_unique(
				array_filter(
				array(
					isset( $fields['billing_address_1'] ) ? $fields['billing_address_1'] : '',
					isset( $fields['billing_address_2'] ) ? $fields['billing_address_2'] : '',
					isset( $fields['billing_city'] ) ? $fields['billing_city'] : '',
					$state,
				)
				)
			)
		);
		$data    = array(
			'phone'      => $phone,
			'name'       => substr( $name, 0, 191 ),
			'email'      => isset( $fields['billing_email'] ) ? substr( sanitize_email( $fields['billing_email'] ), 0, 191 ) : '',
			'address'    => $address,
			'fields'     => wp_json_encode( $fields ),
			'cart'       => wp_json_encode( $cart ),
			'cart_total' => $cart['total'],
			'currency'   => get_woocommerce_currency(),
			'ip'         => oshield_get_ip(),
			'device_id'  => oshield_get_device_id(),
			'user_agent' => oshield_get_ua(),
			'page_url'   => isset( $_POST['page'] ) ? substr( esc_url_raw( wp_unslash( $_POST['page'] ) ), 0, 255 ) : '',
			'updated_at' => current_time( 'mysql' ),
		);

		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . self::table() . ' WHERE token = %s', $token ) ); // phpcs:ignore
		$id       = $existing ? (int) $existing->id : 0;
		if ( $existing && 'pending' !== $existing->status ) {
			wp_send_json_success( array( 'skipped' => 'closed' ) );
		}
		if ( $id ) {
			$wpdb->update( self::table(), $data, array( 'id' => $id ) ); // phpcs:ignore
		} else {
			$data['token']      = $token;
			$data['status']     = 'pending';
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( self::table(), $data ); // phpcs:ignore
			$id = (int) $wpdb->insert_id;
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * All recorded events for a token.
	 *
	 * @param string $token Token.
	 * @return array
	 */
	public static function get_events( $token ) {
		global $wpdb;
		$rows   = $wpdb->get_col( $wpdb->prepare( "SELECT events FROM {$wpdb->prefix}oshield_recordings WHERE token = %s ORDER BY chunk ASC, id ASC", $token ) ); // phpcs:ignore
		$events = array();
		foreach ( $rows as $json ) {
			$chunk = json_decode( $json, true );
			if ( is_array( $chunk ) ) {
				$events = array_merge( $events, $chunk );
			}
		}
		return $events;
	}

	/**
	 * Does a token have a recording?
	 *
	 * @param string[] $tokens Tokens.
	 * @return array token => bool
	 */
	public static function has_recordings( $tokens ) {
		global $wpdb;
		$tokens = array_filter( array_map( array( __CLASS__, 'clean_token' ), (array) $tokens ) );
		if ( empty( $tokens ) ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $tokens ), '%s' ) );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT token FROM {$wpdb->prefix}oshield_recordings WHERE token IN ($in)", $tokens ) ); // phpcs:ignore
		return array_fill_keys( $rows, true );
	}

	/**
	 * When an order is placed, remove the entry of this checkout and mark older ones as recovered.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Phone.
	 */
	public static function on_order_placed( $order, $phone ) {
		global $wpdb;
		$token = self::clean_token( isset( $_COOKIE['oshield_itoken'] ) ? wp_unslash( $_COOKIE['oshield_itoken'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $token ) {
			$wpdb->delete( self::table(), array( 'token' => $token, 'status' => 'pending' ) ); // phpcs:ignore
			// The visit's recording is kept: it is also shown under Live Visitors.
			$wpdb->update( $wpdb->prefix . 'oshield_visitors', array( 'order_id' => $order->get_id() ), array( 'session_id' => $token ) ); // phpcs:ignore
			if ( ! headers_sent() ) {
				// Start a fresh token for the next visit.
				setcookie( 'oshield_itoken', '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
			}
		}
		if ( $phone ) {
			$wpdb->query( // phpcs:ignore
				$wpdb->prepare(
					'UPDATE ' . self::table() . " SET status = 'recovered', order_id = %d, updated_at = %s WHERE phone = %s AND status = 'pending'", // phpcs:ignore
					$order->get_id(),
					current_time( 'mysql' ),
					$phone
				)
			);
		}
	}

	/**
	 * Get one row.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Delete rows and their recordings.
	 *
	 * @param int[] $ids Ids.
	 */
	public static function delete( $ids ) {
		global $wpdb;
		foreach ( (array) $ids as $id ) {
			$row = self::get( (int) $id );
			if ( ! $row ) {
				continue;
			}
			$wpdb->delete( $wpdb->prefix . 'oshield_recordings', array( 'token' => $row->token ) ); // phpcs:ignore
			$wpdb->delete( self::table(), array( 'id' => $row->id ) ); // phpcs:ignore
		}
	}

	/**
	 * Create a real WooCommerce order from an incomplete entry.
	 *
	 * @param int $id Entry id.
	 * @return WC_Order|WP_Error
	 */
	public static function convert( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'oshield_missing', __( 'Entry not found.', 'order-shield' ) );
		}
		if ( 'converted' === $row->status && $row->order_id ) {
			return new WP_Error( 'oshield_done', __( 'This entry was already converted.', 'order-shield' ) );
		}
		$cart   = json_decode( (string) $row->cart, true );
		$fields = json_decode( (string) $row->fields, true );
		$fields = is_array( $fields ) ? $fields : array();
		if ( empty( $cart['items'] ) ) {
			return new WP_Error( 'oshield_empty', __( 'This entry has no products in the cart.', 'order-shield' ) );
		}

		$order = wc_create_order( array( 'created_via' => 'order-shield' ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		foreach ( $cart['items'] as $item ) {
			$product = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$order->add_product( $product, max( 1, (int) $item['quantity'] ), array( 'variation' => isset( $item['variation'] ) ? (array) $item['variation'] : array() ) );
		}
		if ( ! count( $order->get_items() ) ) {
			$order->delete( true );
			return new WP_Error( 'oshield_products', __( 'The products in this cart no longer exist.', 'order-shield' ) );
		}

		$billing  = array();
		$shipping = array();
		foreach ( $fields as $k => $v ) {
			if ( 0 === strpos( $k, 'billing_' ) ) {
				$billing[ substr( $k, 8 ) ] = $v;
			} elseif ( 0 === strpos( $k, 'shipping_' ) ) {
				$shipping[ substr( $k, 9 ) ] = $v;
			}
		}
		$billing['phone'] = $row->phone;
		if ( empty( $billing['country'] ) ) {
			$billing['country'] = WC()->countries ? WC()->countries->get_base_country() : 'BD';
		}
		$order->set_address( $billing, 'billing' );
		$order->set_address( array_filter( $shipping ) ? $shipping : $billing, 'shipping' );
		if ( ! empty( $fields['order_comments'] ) ) {
			$order->set_customer_note( $fields['order_comments'] );
		}

		if ( ! empty( $cart['shipping']['method'] ) ) {
			$ship = new WC_Order_Item_Shipping();
			list( $method_id, $instance ) = array_pad( explode( ':', $cart['shipping']['method'] ), 2, 0 );
			$ship->set_method_id( $method_id );
			$ship->set_instance_id( (int) $instance );
			$ship->set_method_title( __( 'Shipping', 'order-shield' ) );
			$ship->set_total( (float) $cart['shipping']['cost'] );
			$order->add_item( $ship );
		}

		$order->update_meta_data( '_oshield_ip', $row->ip );
		$order->update_meta_data( '_oshield_device_id', $row->device_id );
		$order->update_meta_data( '_oshield_from_incomplete', $row->id );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( __( 'Cash on delivery', 'order-shield' ) );
		$order->calculate_totals();
		$order->set_status( 'processing', __( 'Fake Order Shield: created from an incomplete checkout.', 'order-shield' ) );
		$order->save();

		global $wpdb;
		$wpdb->update( // phpcs:ignore
			self::table(),
			array(
				'status'     => 'converted',
				'order_id'   => $order->get_id(),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $row->id )
		);
		return $order;
	}

	/**
	 * Counts by status.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', OBJECT_K ); // phpcs:ignore
		$out  = array( 'pending' => 0, 'recovered' => 0, 'converted' => 0 );
		foreach ( $rows as $status => $r ) {
			$out[ $status ] = (int) $r->c;
		}
		$out['all'] = array_sum( $out );
		return $out;
	}

	/**
	 * Hourly cleanup of old entries, orphan recordings and old logs.
	 */
	public static function cleanup() {
		global $wpdb;
		$days   = max( 1, (int) oshield_opt( 'incomplete_retention' ) );
		$before = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$tokens = $wpdb->get_col( $wpdb->prepare( 'SELECT token FROM ' . self::table() . ' WHERE updated_at < %s', $before ) ); // phpcs:ignore
		foreach ( array_chunk( $tokens, 200 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}oshield_recordings WHERE token IN ($in)", $chunk ) ); // phpcs:ignore
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE updated_at < %s', $before ) ); // phpcs:ignore

		// Recordings without an entry (visitor never typed a valid phone) older than 1 day.
		$day_ago = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$wpdb->query( $wpdb->prepare( "DELETE r FROM {$wpdb->prefix}oshield_recordings r LEFT JOIN " . self::table() . " i ON i.token = r.token LEFT JOIN {$wpdb->prefix}oshield_visitors v ON v.session_id = r.token WHERE i.id IS NULL AND v.id IS NULL AND r.created_at < %s", $day_ago ) ); // phpcs:ignore

		// Activity log: 60 days.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}oshield_events WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 60 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/**
	 * Daily email with new incomplete orders.
	 */
	public static function digest() {
		if ( ! oshield_on( 'incomplete_enabled' ) || 'daily' !== oshield_opt( 'incomplete_digest' ) ) {
			return;
		}
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND created_at >= %s ORDER BY id DESC LIMIT 200", $since ) ); // phpcs:ignore
		if ( empty( $rows ) ) {
			return;
		}
		$to    = trim( (string) oshield_opt( 'incomplete_digest_to' ) );
		$to    = is_email( $to ) ? $to : get_option( 'admin_email' );
		$lines = array();
		foreach ( $rows as $r ) {
			$lines[] = sprintf( '%s — %s — %s — %s', $r->phone, $r->name, wp_strip_all_tags( html_entity_decode( wc_price( $r->cart_total ) ) ), $r->created_at );
		}
		/* translators: 1: count 2: site */
		$subject = sprintf( __( '[%2$s] %1$d incomplete orders in the last 24 hours', 'order-shield' ), count( $rows ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body    = implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=order-shield-incomplete' );
		wp_mail( $to, $subject, $body );
	}
}
