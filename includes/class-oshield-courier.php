<?php
/**
 * Steadfast and Pathao integration, custom order statuses and courier webhooks.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Courier {

	const STEADFAST_API = 'https://portal.packzy.com/api/v1';
	const PATHAO_LIVE   = 'https://api-hermes.pathao.com';
	const PATHAO_SANDBOX= 'https://courier-api-sandbox.pathao.com';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'report_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );

		foreach ( array( 'edit-shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/**
	 * Couriers that are switched on.
	 *
	 * @return array id => label
	 */
	public static function enabled() {
		$out = array();
		if ( oshield_on( 'steadfast_enabled' ) ) {
			$out['steadfast'] = 'Steadfast';
		}
		if ( oshield_on( 'pathao_enabled' ) ) {
			$out['pathao'] = 'Pathao';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Custom statuses
	 * ------------------------------------------------------------------ */

	/**
	 * Register "In Transit" and "Returned".
	 */
	public static function register_statuses() {
		register_post_status(
			'wc-in-transit',
			array(
				'label'                     => _x( 'In Transit', 'Order status', 'order-shield' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s count */
				'label_count'               => _n_noop( 'In Transit <span class="count">(%s)</span>', 'In Transit <span class="count">(%s)</span>', 'order-shield' ),
			)
		);
		register_post_status(
			'wc-returned',
			array(
				'label'                     => _x( 'Returned', 'Order status', 'order-shield' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s count */
				'label_count'               => _n_noop( 'Returned <span class="count">(%s)</span>', 'Returned <span class="count">(%s)</span>', 'order-shield' ),
			)
		);
	}

	/**
	 * Add statuses to WooCommerce's list (after Processing).
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function add_statuses( $statuses ) {
		$out = array();
		foreach ( $statuses as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				$out['wc-in-transit'] = _x( 'In Transit', 'Order status', 'order-shield' );
			}
		}
		if ( ! isset( $out['wc-in-transit'] ) ) {
			$out['wc-in-transit'] = _x( 'In Transit', 'Order status', 'order-shield' );
		}
		$out['wc-returned'] = _x( 'Returned', 'Order status', 'order-shield' );
		return $out;
	}

	/**
	 * Include In Transit in sales reports.
	 *
	 * @param array|false $statuses Statuses.
	 * @return array|false
	 */
	public static function report_statuses( $statuses ) {
		if ( is_array( $statuses ) && in_array( 'processing', $statuses, true ) ) {
			$statuses[] = 'in-transit';
		}
		return $statuses;
	}

	/**
	 * In Transit counts as paid-ish (stock reduced etc).
	 *
	 * @param array $statuses Statuses.
	 * @return array
	 */
	public static function paid_statuses( $statuses ) {
		$statuses[] = 'in-transit';
		return $statuses;
	}

	/* ------------------------------------------------------------------ *
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Recipient address in one line.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function address( $order ) {
		$parts = array(
			$order->get_shipping_address_1() ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
			$order->get_shipping_address_1() ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
			$order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
		);
		$state   = $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state();
		$country = $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country();
		if ( $state && WC()->countries ) {
			$states  = WC()->countries->get_states( $country );
			$parts[] = isset( $states[ $state ] ) ? $states[ $state ] : $state;
		}
		return trim( implode( ', ', array_unique( array_filter( array_map( 'trim', $parts ) ) ) ) );
	}

	/**
	 * Short description of the items.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function items_text( $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			$parts[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		return substr( implode( ', ', $parts ), 0, 250 );
	}

	/**
	 * Amount to collect (0 for already paid online orders).
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private static function cod_amount( $order ) {
		$amount = 'cod' === $order->get_payment_method() || ! $order->is_paid() ? (float) $order->get_total() : 0;
		return (int) round( apply_filters( 'oshield_courier_cod_amount', $amount, $order ) );
	}

	/**
	 * Shipment already created for this order?
	 *
	 * @param int $order_id Order id.
	 * @return object|null
	 */
	public static function get_shipment( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}oshield_shipments WHERE order_id = %d ORDER BY id DESC LIMIT 1", $order_id ) ); // phpcs:ignore
	}

	/**
	 * Send an order to a courier.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $courier steadfast|pathao.
	 * @param string   $note    Note for the rider.
	 * @param bool     $force   Send again even if already sent.
	 * @return array|WP_Error Shipment data.
	 */
	public static function send( $order, $courier, $note = '', $force = false ) {
		if ( ! isset( self::enabled()[ $courier ] ) ) {
			return new WP_Error( 'oshield_courier_off', __( 'This courier is not enabled in settings.', 'order-shield' ) );
		}
		if ( ! $force && self::get_shipment( $order->get_id() ) ) {
			return new WP_Error( 'oshield_already_sent', __( 'Already sent to a courier.', 'order-shield' ) );
		}
		$phone = oshield_normalize_phone( $order->get_billing_phone() );
		if ( '' === $phone ) {
			return new WP_Error( 'oshield_phone', __( 'The order has no valid Bangladeshi phone number.', 'order-shield' ) );
		}
		$note   = '' !== trim( $note ) ? $note : $order->get_customer_note();
		$result = 'pathao' === $courier ? self::send_pathao( $order, $phone, $note ) : self::send_steadfast( $order, $phone, $note );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf( /* translators: 1: courier 2: error */ __( 'Order Shield: sending to %1$s failed — %2$s', 'order-shield' ), ucfirst( $courier ), $result->get_error_message() ) );
			return $result;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore
			$wpdb->prefix . 'oshield_shipments',
			array(
				'order_id'       => $order->get_id(),
				'courier'        => $courier,
				'consignment_id' => (string) $result['consignment_id'],
				'tracking_code'  => (string) $result['tracking_code'],
				'invoice'        => (string) $result['invoice'],
				'status'         => (string) $result['status'],
				'payload'        => wp_json_encode( $result['raw'] ),
				'events'         => wp_json_encode(
					array(
						array(
							'time'    => $now,
							'status'  => $result['status'],
							/* translators: %s user */
							'message' => sprintf( __( 'Sent by %s', 'order-shield' ), wp_get_current_user()->display_name ? wp_get_current_user()->display_name : __( 'system', 'order-shield' ) ),
						),
					)
				),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$order->update_meta_data( '_oshield_courier', 'pathao' === $courier ? 'Pathao' : 'Steadfast' );
		$order->update_meta_data( '_oshield_consignment_id', $result['consignment_id'] );
		$order->update_meta_data( '_oshield_tracking_code', $result['tracking_code'] );
		$order->update_meta_data( '_oshield_tracking_url', $result['tracking_url'] );
		$order->update_meta_data( '_oshield_courier_status', $result['status'] );
		$order->save();

		/* translators: 1: courier 2: consignment id */
		$note_text = sprintf( __( 'Order Shield: sent to %1$s. Consignment ID: %2$s', 'order-shield' ), ucfirst( $courier ), $result['consignment_id'] );
		$to        = self::strip( oshield_opt( 'courier_status_sent' ) );
		if ( $to && $order->get_status() !== $to ) {
			$order->update_status( $to, $note_text );
		} else {
			$order->add_order_note( $note_text );
		}
		return $result;
	}

	/**
	 * Strip wc- prefix.
	 *
	 * @param string $s Status.
	 * @return string
	 */
	private static function strip( $s ) {
		return 0 === strpos( (string) $s, 'wc-' ) ? substr( $s, 3 ) : (string) $s;
	}

	/**
	 * Steadfast create order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Phone.
	 * @param string   $note  Note.
	 * @return array|WP_Error
	 */
	private static function send_steadfast( $order, $phone, $note ) {
		$key    = trim( (string) oshield_opt( 'steadfast_api_key' ) );
		$secret = trim( (string) oshield_opt( 'steadfast_secret_key' ) );
		if ( '' === $key || '' === $secret ) {
			return new WP_Error( 'oshield_sf_keys', __( 'Add your Steadfast API key and secret key in settings.', 'order-shield' ) );
		}
		$invoice = $order->get_order_number() . '-' . wp_rand( 100, 999 );
		$payload = array(
			'invoice'           => $invoice,
			'recipient_name'    => substr( trim( $order->get_formatted_billing_full_name() ), 0, 100 ),
			'recipient_phone'   => $phone,
			'recipient_address' => substr( self::address( $order ), 0, 250 ),
			'cod_amount'        => self::cod_amount( $order ),
			'note'              => substr( (string) $note, 0, 250 ),
			'item_description'  => self::items_text( $order ),
		);
		if ( $order->get_billing_email() ) {
			$payload['recipient_email'] = $order->get_billing_email();
		}
		$payload = apply_filters( 'oshield_steadfast_payload', $payload, $order );

		$response = wp_remote_post(
			self::STEADFAST_API . '/create_order',
			array(
				'timeout' => 30,
				'headers' => array(
					'Api-Key'      => $key,
					'Secret-Key'   => $secret,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$data     = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['consignment']['consignment_id'] ) ) {
			return new WP_Error( 'oshield_sf', self::error_text( $data ) );
		}
		$c = $data['consignment'];
		return array(
			'consignment_id' => (string) $c['consignment_id'],
			'tracking_code'  => isset( $c['tracking_code'] ) ? (string) $c['tracking_code'] : '',
			'invoice'        => isset( $c['invoice'] ) ? (string) $c['invoice'] : $invoice,
			'status'         => isset( $c['status'] ) ? (string) $c['status'] : 'in_review',
			'tracking_url'   => ! empty( $c['tracking_code'] ) ? 'https://steadfast.com.bd/t/' . rawurlencode( $c['tracking_code'] ) : '',
			'raw'            => $data,
		);
	}

	/**
	 * Pathao base URL.
	 *
	 * @return string
	 */
	private static function pathao_base() {
		return 'sandbox' === oshield_opt( 'pathao_env' ) ? self::PATHAO_SANDBOX : self::PATHAO_LIVE;
	}

	/**
	 * Get (and cache) a Pathao access token.
	 *
	 * @param bool $fresh Force new token.
	 * @return string|WP_Error
	 */
	public static function pathao_token( $fresh = false ) {
		$cache = get_option( 'oshield_pathao_token', array() );
		$sig   = md5( oshield_opt( 'pathao_client_id' ) . oshield_opt( 'pathao_username' ) . oshield_opt( 'pathao_env' ) );
		if ( ! $fresh && is_array( $cache ) && ! empty( $cache['token'] ) && $cache['sig'] === $sig && $cache['expires'] > time() + 300 ) {
			return $cache['token'];
		}
		foreach ( array( 'pathao_client_id', 'pathao_client_secret', 'pathao_username', 'pathao_password' ) as $k ) {
			if ( '' === trim( (string) oshield_opt( $k ) ) ) {
				return new WP_Error( 'oshield_pathao_keys', __( 'Add your Pathao client ID, client secret, merchant email and password in settings.', 'order-shield' ) );
			}
		}
		$body = array(
			'client_id'     => oshield_opt( 'pathao_client_id' ),
			'client_secret' => oshield_opt( 'pathao_client_secret' ),
			'grant_type'    => 'password',
			'username'      => oshield_opt( 'pathao_username' ),
			'password'      => oshield_opt( 'pathao_password' ),
		);
		if ( ! $fresh && is_array( $cache ) && ! empty( $cache['refresh'] ) && $cache['sig'] === $sig ) {
			$body = array(
				'client_id'     => oshield_opt( 'pathao_client_id' ),
				'client_secret' => oshield_opt( 'pathao_client_secret' ),
				'grant_type'    => 'refresh_token',
				'refresh_token' => $cache['refresh'],
			);
		}
		$response = wp_remote_post(
			self::pathao_base() . '/aladdin/api/v1/issue-token',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		$data     = self::decode( $response );
		if ( is_wp_error( $data ) || empty( $data['access_token'] ) ) {
			if ( 'refresh_token' === $body['grant_type'] ) {
				delete_option( 'oshield_pathao_token' );
				return self::pathao_token( true );
			}
			return is_wp_error( $data ) ? $data : new WP_Error( 'oshield_pathao_token', self::error_text( $data ) );
		}
		update_option(
			'oshield_pathao_token',
			array(
				'token'   => $data['access_token'],
				'refresh' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
				'expires' => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : DAY_IN_SECONDS ),
				'sig'     => $sig,
			),
			false
		);
		return $data['access_token'];
	}

	/**
	 * Pathao request.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $path   Path.
	 * @param array|null $body   Body.
	 * @return array|WP_Error
	 */
	private static function pathao_request( $method, $path, $body = null ) {
		$token = self::pathao_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::pathao_base() . $path, $args );
		if ( ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			delete_option( 'oshield_pathao_token' );
		}
		return self::decode( $response );
	}

	/**
	 * Pathao store id (setting or first store).
	 *
	 * @return int|WP_Error
	 */
	private static function pathao_store_id() {
		$id = (int) oshield_opt( 'pathao_store_id' );
		if ( $id ) {
			return $id;
		}
		$cached = (int) get_transient( 'oshield_pathao_store' );
		if ( $cached ) {
			return $cached;
		}
		$data = self::pathao_request( 'GET', '/aladdin/api/v1/stores' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$stores = isset( $data['data']['data'] ) ? $data['data']['data'] : ( isset( $data['data'] ) ? $data['data'] : array() );
		if ( empty( $stores[0]['store_id'] ) ) {
			return new WP_Error( 'oshield_pathao_store', __( 'No Pathao store found. Create a store in your Pathao merchant panel or enter the Store ID in settings.', 'order-shield' ) );
		}
		set_transient( 'oshield_pathao_store', (int) $stores[0]['store_id'], DAY_IN_SECONDS );
		return (int) $stores[0]['store_id'];
	}

	/**
	 * Pathao create order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Phone.
	 * @param string   $note  Note.
	 * @return array|WP_Error
	 */
	private static function send_pathao( $order, $phone, $note ) {
		$store = self::pathao_store_id();
		if ( is_wp_error( $store ) ) {
			return $store;
		}
		$qty = 0;
		foreach ( $order->get_items() as $item ) {
			$qty += (int) $item->get_quantity();
		}
		$payload = array(
			'store_id'            => $store,
			'merchant_order_id'   => (string) $order->get_order_number(),
			'recipient_name'      => substr( trim( $order->get_formatted_billing_full_name() ), 0, 100 ),
			'recipient_phone'     => $phone,
			'recipient_address'   => substr( self::address( $order ), 0, 220 ),
			'delivery_type'       => 48,
			'item_type'           => 2,
			'special_instruction' => substr( (string) $note, 0, 250 ),
			'item_quantity'       => max( 1, $qty ),
			'item_weight'         => (float) oshield_opt( 'pathao_weight' ) > 0 ? (string) (float) oshield_opt( 'pathao_weight' ) : '0.5',
			'item_description'    => self::items_text( $order ),
			'amount_to_collect'   => self::cod_amount( $order ),
		);
		$payload = apply_filters( 'oshield_pathao_payload', $payload, $order );
		$data    = self::pathao_request( 'POST', '/aladdin/api/v1/orders', $payload );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['data']['consignment_id'] ) ) {
			return new WP_Error( 'oshield_pathao', self::error_text( $data ) );
		}
		$c = $data['data'];
		return array(
			'consignment_id' => (string) $c['consignment_id'],
			'tracking_code'  => (string) $c['consignment_id'],
			'invoice'        => isset( $c['merchant_order_id'] ) ? (string) $c['merchant_order_id'] : (string) $order->get_order_number(),
			'status'         => isset( $c['order_status'] ) ? (string) $c['order_status'] : 'Pending',
			'tracking_url'   => 'https://merchant.pathao.com/tracking?consignment_id=' . rawurlencode( $c['consignment_id'] ) . '&phone=' . rawurlencode( $phone ),
			'raw'            => $data,
		);
	}

	/**
	 * Decode a JSON response.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'oshield_http', sprintf( 'HTTP %d: %s', $code, wp_trim_words( wp_strip_all_tags( $body ), 25 ) ) );
		}
		if ( $code >= 400 ) {
			return new WP_Error( 'oshield_http', self::error_text( $data ) . ' (HTTP ' . $code . ')' );
		}
		return $data;
	}

	/**
	 * Find a readable error in a courier response.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	private static function error_text( $data ) {
		$parts = array();
		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			$parts[] = $data['message'];
		}
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			array_walk_recursive(
				$data['errors'],
				function ( $v ) use ( &$parts ) {
					if ( is_string( $v ) ) {
						$parts[] = $v;
					}
				}
			);
		}
		return $parts ? implode( ' ', array_unique( $parts ) ) : __( 'Unexpected response from courier.', 'order-shield' );
	}

	/* ------------------------------------------------------------------ *
	 * Status updates
	 * ------------------------------------------------------------------ */

	/**
	 * Ask the courier for the latest status.
	 *
	 * @param int $order_id Order id.
	 * @return string|WP_Error New status.
	 */
	public static function refresh( $order_id ) {
		$ship = self::get_shipment( $order_id );
		if ( ! $ship ) {
			return new WP_Error( 'oshield_no_ship', __( 'This order has not been sent to a courier.', 'order-shield' ) );
		}
		if ( 'pathao' === $ship->courier ) {
			$data = self::pathao_request( 'GET', '/aladdin/api/v1/orders/' . rawurlencode( $ship->consignment_id ) . '/info' );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$status = isset( $data['data']['order_status'] ) ? (string) $data['data']['order_status'] : '';
		} else {
			$response = wp_remote_get(
				self::STEADFAST_API . '/status_by_cid/' . rawurlencode( $ship->consignment_id ),
				array(
					'timeout' => 20,
					'headers' => array(
						'Api-Key'    => oshield_opt( 'steadfast_api_key' ),
						'Secret-Key' => oshield_opt( 'steadfast_secret_key' ),
					),
				)
			);
			$data     = self::decode( $response );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$status = isset( $data['delivery_status'] ) ? (string) $data['delivery_status'] : '';
		}
		if ( '' === $status ) {
			return new WP_Error( 'oshield_status', __( 'The courier did not return a status.', 'order-shield' ) );
		}
		self::apply_status( $ship, $status, __( 'Status refreshed manually', 'order-shield' ) );
		return $status;
	}

	/**
	 * Normalize courier status text to a key.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_key( $status ) {
		$s = strtolower( trim( (string) $status ) );
		$s = preg_replace( '/^order\./', '', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', '_', $s ), '_' );
	}

	/**
	 * Which WooCommerce status (if any) a courier status maps to.
	 *
	 * @param string $key Status key.
	 * @return string WC status without prefix, or ''.
	 */
	public static function map_status( $key ) {
		$groups = array(
			'courier_status_delivered' => array( 'delivered', 'delivered_approval_pending' ),
			'courier_status_partial'   => array( 'partial_delivered', 'partial_delivery', 'partial_delivered_approval_pending' ),
			'courier_status_cancelled' => array( 'cancelled', 'cancelled_approval_pending', 'returned', 'return', 'returned_to_merchant', 'paid_return', 'exchange_returned' ),
		);
		foreach ( $groups as $opt => $keys ) {
			if ( in_array( $key, $keys, true ) ) {
				return self::strip( oshield_opt( $opt ) );
			}
		}
		return '';
	}

	/**
	 * Save a new courier status on the shipment and order, and move the order status.
	 *
	 * @param object $ship    Shipment row.
	 * @param string $status  Courier status.
	 * @param string $message Extra info.
	 */
	public static function apply_status( $ship, $status, $message = '' ) {
		global $wpdb;
		$events   = json_decode( (string) $ship->events, true );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'time'    => current_time( 'mysql' ),
			'status'  => $status,
			'message' => $message,
		);
		$wpdb->update( // phpcs:ignore
			$wpdb->prefix . 'oshield_shipments',
			array(
				'status'     => substr( $status, 0, 64 ),
				'events'     => wp_json_encode( array_slice( $events, -50 ) ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $ship->id )
		);

		$order = wc_get_order( $ship->order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( '_oshield_courier_status', $status );
		$order->save_meta_data();

		$to = self::map_status( self::status_key( $status ) );
		/* translators: 1: courier 2: status 3: message */
		$note = trim( sprintf( __( 'Order Shield: %1$s status → %2$s. %3$s', 'order-shield' ), ucfirst( $ship->courier ), $status, $message ) );
		if ( $to && $order->get_status() !== $to ) {
			$order->update_status( $to, $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Find a shipment by consignment id or invoice / merchant order id.
	 *
	 * @param string $courier        Courier.
	 * @param string $consignment_id Consignment id.
	 * @param string $invoice        Invoice.
	 * @return object|null
	 */
	public static function find_shipment( $courier, $consignment_id, $invoice ) {
		global $wpdb;
		$t = $wpdb->prefix . 'oshield_shipments';
		if ( '' !== (string) $consignment_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE courier = %s AND consignment_id = %s ORDER BY id DESC LIMIT 1", $courier, $consignment_id ) ); // phpcs:ignore
			if ( $row ) {
				return $row;
			}
		}
		if ( '' !== (string) $invoice ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE courier = %s AND invoice = %s ORDER BY id DESC LIMIT 1", $courier, $invoice ) ); // phpcs:ignore
		}
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Bulk actions
	 * ------------------------------------------------------------------ */

	/**
	 * Add bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		foreach ( self::enabled() as $id => $label ) {
			/* translators: %s courier */
			$actions[ 'oshield_send_' . $id ] = sprintf( __( 'Send to %s', 'order-shield' ), $label );
		}
		$actions['mark_in-transit'] = __( 'Change status to In Transit', 'order-shield' );
		return $actions;
	}

	/**
	 * Handle bulk send.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Order ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'oshield_send_' ) || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$courier = substr( $action, strlen( 'oshield_send_' ) );
		$ok      = 0;
		$errors  = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$res = self::send( $order, $courier );
			if ( is_wp_error( $res ) ) {
				$errors[] = '#' . $order->get_order_number() . ': ' . $res->get_error_message();
			} else {
				++$ok;
			}
		}
		set_transient( 'oshield_bulk_' . get_current_user_id(), array( 'ok' => $ok, 'errors' => $errors, 'courier' => $courier ), 120 );
		return add_query_arg( 'oshield_bulk', 1, $redirect );
	}

	/**
	 * Show bulk result.
	 */
	public static function bulk_notice() {
		if ( empty( $_GET['oshield_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$res = get_transient( 'oshield_bulk_' . get_current_user_id() );
		if ( ! $res ) {
			return;
		}
		delete_transient( 'oshield_bulk_' . get_current_user_id() );
		if ( $res['ok'] ) {
			/* translators: 1: count 2: courier */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%1$d order sent to %2$s.', '%1$d orders sent to %2$s.', $res['ok'], 'order-shield' ), $res['ok'], ucfirst( $res['courier'] ) ) ) . '</p></div>';
		}
		if ( $res['errors'] ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'Some orders were not sent:', 'order-shield' ) . '</strong><br>' . implode( '<br>', array_map( 'esc_html', $res['errors'] ) ) . '</p></div>';
		}
	}
}
