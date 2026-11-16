<?php
/**
 * Delivery success ratio ("fraud check") by phone number.
 *
 * Sources:
 *  - local: the store's own WooCommerce order history.
 *  - api:   any courier-ratio HTTP API configured in settings. The JSON response is parsed
 *           generically (total / success / cancel style keys, per courier and overall).
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Fraud {

	/**
	 * Full report for a phone.
	 *
	 * @param string $phone Phone.
	 * @param bool   $fresh Skip cache.
	 * @return array
	 */
	public static function report( $phone, $fresh = false ) {
		$phone  = oshield_normalize_phone( $phone );
		$source = oshield_opt( 'fraud_source' );
		$report = array(
			'phone'    => $phone,
			'valid'    => '' !== $phone,
			'local'    => null,
			'api'      => null,
			'api_error'=> '',
			'used'     => 'local',
			'total'    => 0,
			'success'  => 0,
			'cancel'   => 0,
			'ratio'    => null,
			'risk'     => 'new',
		);
		if ( '' === $phone ) {
			return $report;
		}

		$report['local'] = self::local( $phone, $fresh );

		if ( in_array( $source, array( 'api', 'both' ), true ) && '' !== trim( (string) oshield_opt( 'fraud_api_url' ) ) ) {
			$api = self::api( $phone, $fresh );
			if ( is_wp_error( $api ) ) {
				$report['api_error'] = $api->get_error_message();
			} else {
				$report['api'] = $api;
			}
		}

		$use = $report['local'];
		if ( $report['api'] && ( 'api' === $source || ( 'both' === $source && $report['api']['total'] > 0 ) ) ) {
			$use            = $report['api'];
			$report['used'] = 'api';
		} elseif ( 'api' === $source && ! $report['api'] ) {
			$report['used'] = 'local';
		}

		$report['total']   = $use['total'];
		$report['success'] = $use['success'];
		$report['cancel']  = $use['cancel'];
		$report['ratio']   = $use['ratio'];
		$report['risk']    = self::risk( $use );

		return $report;
	}

	/**
	 * Risk label from counts.
	 *
	 * @param array $data Counts.
	 * @return string new|good|medium|risky
	 */
	public static function risk( $data ) {
		if ( null === $data['ratio'] ) {
			return 'new';
		}
		$threshold = (float) oshield_opt( 'smart_threshold' );
		if ( $data['ratio'] >= max( 80, $threshold ) ) {
			return 'good';
		}
		if ( $data['ratio'] >= min( 50, $threshold ) ) {
			return 'medium';
		}
		return 'risky';
	}

	/**
	 * Ratio helper.
	 *
	 * @param int $success Delivered.
	 * @param int $cancel  Cancelled.
	 * @return float|null
	 */
	public static function ratio( $success, $cancel ) {
		$finished = (int) $success + (int) $cancel;
		return $finished > 0 ? round( ( (int) $success / $finished ) * 100, 1 ) : null;
	}

	/**
	 * Stats from this store's orders.
	 *
	 * @param string $phone Normalized phone.
	 * @param bool   $fresh Skip cache.
	 * @return array
	 */
	public static function local( $phone, $fresh = false ) {
		$key = 'oshield_local_' . $phone;
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$success_statuses = array_map( array( __CLASS__, 'strip_wc' ), (array) oshield_opt( 'success_statuses' ) );
		$cancel_statuses  = array_map( array( __CLASS__, 'strip_wc' ), (array) oshield_opt( 'cancel_statuses' ) );

		$ids     = oshield_get_order_ids_by_phone( $phone );
		$success = 0;
		$cancel  = 0;
		$orders  = array();
		$spent   = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$status = $order->get_status();
			if ( in_array( $status, $success_statuses, true ) ) {
				++$success;
				$spent += (float) $order->get_total();
			} elseif ( in_array( $status, $cancel_statuses, true ) ) {
				++$cancel;
			}
			if ( count( $orders ) < 20 ) {
				$created  = $order->get_date_created();
				$orders[] = array(
					'id'     => $order->get_id(),
					'number' => $order->get_order_number(),
					'status' => $status,
					'label'  => wc_get_order_status_name( $status ),
					'total'  => wp_strip_all_tags( html_entity_decode( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ),
					'date'   => $created ? $created->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
					'url'    => $order->get_edit_order_url(),
				);
			}
		}

		$data = array(
			'total'    => count( $ids ),
			'success'  => $success,
			'cancel'   => $cancel,
			'ratio'    => self::ratio( $success, $cancel ),
			'spent'    => wp_strip_all_tags( html_entity_decode( wc_price( $spent ) ) ),
			'orders'   => $orders,
			'couriers' => array(),
		);
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Remove "wc-" prefix.
	 *
	 * @param string $s Status.
	 * @return string
	 */
	public static function strip_wc( $s ) {
		return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
	}

	/**
	 * Clear cached local stats for a phone.
	 *
	 * @param string $phone Phone.
	 */
	public static function flush( $phone ) {
		$phone = oshield_normalize_phone( $phone );
		if ( $phone ) {
			delete_transient( 'oshield_local_' . $phone );
		}
	}

	/**
	 * Parse "Key: value" header lines.
	 *
	 * @param string $text Lines.
	 * @return array
	 */
	public static function parse_headers( $text ) {
		$headers = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( strpos( $line, ':' ) === false ) {
				continue;
			}
			list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $k ) {
				$headers[ $k ] = $v;
			}
		}
		return $headers;
	}

	/**
	 * Fetch from the configured courier-ratio API.
	 *
	 * @param string $phone Phone.
	 * @param bool   $fresh Skip cache.
	 * @return array|WP_Error
	 */
	public static function api( $phone, $fresh = false ) {
		$hours = (int) oshield_opt( 'fraud_cache_hours' );
		$key   = 'oshield_api_' . md5( $phone . '|' . oshield_opt( 'fraud_api_url' ) );
		if ( ! $fresh && $hours > 0 ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$url     = str_replace( '{phone}', rawurlencode( $phone ), (string) oshield_opt( 'fraud_api_url' ) );
		$headers = array_merge( array( 'Accept' => 'application/json' ), self::parse_headers( oshield_opt( 'fraud_api_headers' ) ) );
		$args    = array(
			'timeout' => 25,
			'headers' => $headers,
		);

		if ( 'POST' === oshield_opt( 'fraud_api_method' ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( array( 'phone' => $phone ) );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $body ) ) {
			$msg = is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : sprintf( /* translators: %d HTTP code */ __( 'Courier API returned HTTP %d.', 'order-shield' ), $code );
			return new WP_Error( 'oshield_api', $msg );
		}

		$data = self::parse_api( $body );
		if ( $hours > 0 ) {
			set_transient( $key, $data, $hours * HOUR_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * Key aliases used by Bangladeshi courier-ratio APIs.
	 *
	 * @return array
	 */
	private static function aliases() {
		return array(
			'total'   => array( 'total_parcel', 'total_parcels', 'total_order', 'total_orders', 'total_delivery', 'total' ),
			'success' => array( 'success_parcel', 'success_parcels', 'delivered_parcel', 'delivered_parcels', 'success_delivery', 'delivered', 'success', 'success_count' ),
			'cancel'  => array( 'cancelled_parcel', 'cancelled_parcels', 'cancel_parcel', 'returned_parcel', 'return_parcel', 'cancelled', 'cancel', 'returned', 'cancel_count' ),
		);
	}

	/**
	 * Read counts from one node if it looks like a counts object.
	 *
	 * @param mixed $node Node.
	 * @return array|null
	 */
	private static function counts_from( $node ) {
		if ( ! is_array( $node ) ) {
			return null;
		}
		$out = array();
		foreach ( self::aliases() as $field => $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $node[ $k ] ) && is_numeric( $node[ $k ] ) ) {
					$out[ $field ] = (int) $node[ $k ];
					break;
				}
			}
		}
		if ( ! isset( $out['success'] ) && ! isset( $out['total'] ) ) {
			return null;
		}
		$out += array( 'total' => 0, 'success' => 0, 'cancel' => 0 );
		if ( ! $out['total'] ) {
			$out['total'] = $out['success'] + $out['cancel'];
		}
		if ( ! isset( $node['cancel'] ) && ! $out['cancel'] && $out['total'] >= $out['success'] ) {
			$out['cancel'] = $out['total'] - $out['success'];
		}
		return $out;
	}

	/**
	 * Walk any JSON shape and collect per-courier and summary counts.
	 *
	 * @param array $body Decoded JSON.
	 * @return array
	 */
	public static function parse_api( $body ) {
		$couriers = array();
		$summary  = null;

		$walk = function ( $node, $name, $depth ) use ( &$walk, &$couriers, &$summary ) {
			if ( ! is_array( $node ) || $depth > 6 ) {
				return;
			}
			$counts = self::counts_from( $node );
			if ( $counts && null !== $name ) {
				$lname = strtolower( (string) $name );
				if ( in_array( $lname, array( 'summary', 'total', 'overall', 'all', 'totals' ), true ) ) {
					$summary = $counts;
				} elseif ( ! is_numeric( $name ) || isset( $node['name'] ) || isset( $node['courier'] ) ) {
					$label      = isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : ( isset( $node['courier'] ) && is_string( $node['courier'] ) ? $node['courier'] : ucfirst( (string) $name ) );
					$couriers[] = array_merge( $counts, array( 'name' => $label, 'logo' => isset( $node['logo'] ) && is_string( $node['logo'] ) ? $node['logo'] : '' ) );
				}
				return;
			}
			if ( $counts && null === $name ) {
				$summary = $counts;
			}
			foreach ( $node as $k => $child ) {
				$walk( $child, $k, $depth + 1 );
			}
		};
		$walk( $body, null, 0 );

		if ( ! $summary ) {
			$summary = array( 'total' => 0, 'success' => 0, 'cancel' => 0 );
			foreach ( $couriers as $c ) {
				$summary['total']   += $c['total'];
				$summary['success'] += $c['success'];
				$summary['cancel']  += $c['cancel'];
			}
		}
		foreach ( $couriers as &$c ) {
			$c['ratio'] = self::ratio( $c['success'], $c['cancel'] );
		}
		unset( $c );

		return array(
			'total'    => $summary['total'],
			'success'  => $summary['success'],
			'cancel'   => $summary['cancel'],
			'ratio'    => self::ratio( $summary['success'], $summary['cancel'] ),
			'couriers' => $couriers,
			'cached'   => false,
		);
	}
}
