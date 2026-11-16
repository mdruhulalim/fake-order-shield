<?php
/**
 * REST routes: chatbot order lookup and courier webhooks.
 *
 * GET  /wp-json/order-shield/v1/orders?phone=01XXXXXXXXX   (header X-API-Key)
 * POST /wp-json/order-shield/v1/webhook/steadfast          (header Authorization: Bearer TOKEN)
 * POST /wp-json/order-shield/v1/webhook/pathao             (header X-PATHAO-Signature: SECRET)
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_REST {

	const NS = 'order-shield/v1';

	/**
	 * Pathao requires this exact header value in the response to the integration test call.
	 */
	const PATHAO_INTEGRATION_SECRET = 'f3992ecc-59da-4cbe-a049-a13da2018d51';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'orders' ),
				'permission_callback' => array( __CLASS__, 'bot_auth' ),
				'args'                => array(
					'phone' => array( 'required' => true ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/webhook/(?P<courier>steadfast|pathao)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Webhook URL for a courier.
	 *
	 * @param string $courier Courier.
	 * @return string
	 */
	public static function webhook_url( $courier ) {
		return rest_url( self::NS . '/webhook/' . $courier );
	}

	/**
	 * Check the bot API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function bot_auth( $request ) {
		$key = (string) oshield_opt( 'bot_api_key' );
		if ( '' === $key ) {
			return new WP_Error( 'oshield_disabled', __( 'The Fake Order Shield API is disabled.', 'order-shield' ), array( 'status' => 403 ) );
		}
		$given = (string) $request->get_header( 'x_api_key' );
		if ( '' === $given ) {
			$given = (string) $request->get_param( 'api_key' );
		}
		return hash_equals( $key, $given ) ? true : new WP_Error( 'oshield_auth', __( 'Invalid API key.', 'order-shield' ), array( 'status' => 401 ) );
	}

	/**
	 * Orders by phone for chatbots.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function orders( $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_REST_Response( array( 'error' => 'woocommerce_inactive' ), 503 );
		}
		$phone = oshield_normalize_phone( $request->get_param( 'phone' ) );
		if ( '' === $phone ) {
			return new WP_REST_Response( array( 'error' => 'invalid_phone' ), 400 );
		}
		$out = array();
		foreach ( array_slice( oshield_get_order_ids_by_phone( $phone ), 0, 10 ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array(
					'name'     => $item->get_name(),
					'quantity' => $item->get_quantity(),
				);
			}
			$created = $order->get_date_created();
			$out[]   = array(
				'order_id'       => $order->get_order_number(),
				'status'         => $order->get_status(),
				'status_label'   => wc_get_order_status_name( $order->get_status() ),
				'total'          => (float) $order->get_total(),
				'currency'       => $order->get_currency(),
				'date'           => $created ? $created->date( 'c' ) : '',
				'items'          => $items,
				'courier'        => (string) $order->get_meta( '_oshield_courier' ),
				'consignment_id' => (string) $order->get_meta( '_oshield_consignment_id' ),
				'courier_status' => (string) $order->get_meta( '_oshield_courier_status' ),
				'tracking_url'   => (string) $order->get_meta( '_oshield_tracking_url' ),
			);
		}
		return new WP_REST_Response(
			array(
				'phone'  => $phone,
				'count'  => count( $out ),
				'orders' => $out,
			)
		);
	}

	/**
	 * Courier webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook( $request ) {
		$courier = $request['courier'];
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || empty( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$payload = is_array( $payload ) ? $payload : array();

		if ( 'GET' === $request->get_method() ) {
			return new WP_REST_Response( array( 'status' => 'ok', 'message' => 'Fake Order Shield webhook is ready.' ), 200 );
		}

		if ( 'pathao' === $courier ) {
			return self::pathao_webhook( $request, $payload );
		}
		return self::steadfast_webhook( $request, $payload );
	}

	/**
	 * Steadfast webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $payload Payload.
	 * @return WP_REST_Response
	 */
	private static function steadfast_webhook( $request, $payload ) {
		$token = (string) oshield_opt( 'steadfast_webhook_token' );
		$auth  = (string) $request->get_header( 'authorization' );
		$given = trim( preg_replace( '/^Bearer\s+/i', '', $auth ) );
		if ( '' !== $token && ! hash_equals( $token, $given ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Unauthorized' ), 401 );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'WooCommerce inactive' ), 503 );
		}

		$cid     = isset( $payload['consignment_id'] ) ? sanitize_text_field( (string) $payload['consignment_id'] ) : '';
		$invoice = isset( $payload['invoice'] ) ? sanitize_text_field( (string) $payload['invoice'] ) : '';
		$type    = isset( $payload['notification_type'] ) ? sanitize_key( $payload['notification_type'] ) : '';
		$status  = isset( $payload['status'] ) ? sanitize_text_field( (string) $payload['status'] ) : ( isset( $payload['delivery_status'] ) ? sanitize_text_field( (string) $payload['delivery_status'] ) : '' );
		$message = isset( $payload['tracking_message'] ) ? sanitize_text_field( (string) $payload['tracking_message'] ) : '';

		$ship = OShield_Courier::find_shipment( 'steadfast', $cid, $invoice );
		if ( ! $ship ) {
			// Accept so the courier does not keep retrying for parcels created outside this store.
			return new WP_REST_Response( array( 'status' => 'success', 'message' => 'Webhook received (no matching order).' ), 200 );
		}

		if ( 'tracking_update' === $type || '' === $status ) {
			OShield_Courier::apply_status( $ship, $ship->status, $message );
		} else {
			OShield_Courier::apply_status( $ship, $status, $message );
		}
		return new WP_REST_Response( array( 'status' => 'success', 'message' => 'Webhook received successfully.' ), 200 );
	}

	/**
	 * Pathao webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $payload Payload.
	 * @return WP_REST_Response
	 */
	private static function pathao_webhook( $request, $payload ) {
		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';

		$response = new WP_REST_Response( array( 'status' => 'success' ), 202 );
		$response->header( 'X-Pathao-Merchant-Webhook-Integration-Secret', self::PATHAO_INTEGRATION_SECRET );

		if ( 'webhook_integration' === $event ) {
			return $response;
		}

		$secret = (string) oshield_opt( 'pathao_webhook_secret' );
		$given  = (string) $request->get_header( 'x_pathao_signature' );
		if ( '' !== $secret && ! hash_equals( $secret, $given ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Unauthorized' ), 401 );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $response;
		}

		$cid  = isset( $payload['consignment_id'] ) ? sanitize_text_field( (string) $payload['consignment_id'] ) : '';
		$moid = isset( $payload['merchant_order_id'] ) ? sanitize_text_field( (string) $payload['merchant_order_id'] ) : '';
		$ship = OShield_Courier::find_shipment( 'pathao', $cid, $moid );
		if ( $ship && '' !== $event ) {
			$extra = array();
			if ( isset( $payload['collected_amount'] ) ) {
				/* translators: %s amount */
				$extra[] = sprintf( __( 'Collected: %s', 'order-shield' ), sanitize_text_field( (string) $payload['collected_amount'] ) );
			}
			if ( isset( $payload['reason'] ) ) {
				$extra[] = sanitize_text_field( (string) $payload['reason'] );
			}
			OShield_Courier::apply_status( $ship, preg_replace( '/^order\./', '', $event ), implode( ' ', $extra ) );
		}
		return $response;
	}
}
