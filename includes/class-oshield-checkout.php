<?php
/**
 * Checkout protection pipeline: phone validation, VPN/country, block list, repeat orders,
 * smart filter and auto block.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Checkout {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'ensure_device_cookie' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_fingerprint' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );

		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'require_phone' ), 20 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'normalize_posted_phone' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 20, 2 );

		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_on_status' ), 5, 4 );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_oshield_block_customer', array( __CLASS__, 'action_block_customer' ) );
	}

	/**
	 * Is this a checkout page (WooCommerce or CartFlows)?
	 *
	 * @return bool
	 */
	public static function is_checkout() {
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			return true;
		}
		if ( function_exists( '_is_wcf_checkout_type' ) && _is_wcf_checkout_type() ) {
			return true;
		}
		return (bool) apply_filters( 'oshield_is_checkout', false );
	}

	/**
	 * Give every visitor a long-lived device id.
	 */
	public static function ensure_device_cookie() {
		if ( is_admin() || headers_sent() || ! empty( $_COOKIE['oshield_did'] ) ) {
			return;
		}
		$id = wp_generate_uuid4();
		setcookie( 'oshield_did', $id, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		$_COOKIE['oshield_did'] = $id;
	}

	/**
	 * Hardware fingerprint script on every page (tiny, loads in the head so the cookie is ready early).
	 */
	public static function enqueue_fingerprint() {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script( 'oshield-fp', OSHIELD_URL . 'assets/js/fp.js', array(), OSHIELD_VERSION, false );
	}

	/**
	 * Frontend assets on checkout.
	 */
	public static function enqueue() {
		if ( ! self::is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'oshield-front', OSHIELD_URL . 'assets/css/frontend.css', array(), OSHIELD_VERSION );

		// tracker.js (site-wide) sets the visitor cookies and records the session; load it first when active.
		$deps = wp_script_is( 'oshield-tracker', 'enqueued' ) ? array( 'jquery', 'oshield-tracker' ) : array( 'jquery' );
		wp_enqueue_script( 'oshield-checkout', OSHIELD_URL . 'assets/js/checkout.js', $deps, OSHIELD_VERSION, true );

		wp_localize_script(
			'oshield-checkout',
			'OShieldCheckout',
			array(
				'ajax'           => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'oshield_front' ),
				'validatePhone'  => oshield_on( 'phone_validation' ),
				'phoneMessage'   => oshield_opt( 'phone_message' ),
				'incomplete'     => oshield_on( 'incomplete_enabled' ) && ! self::in_cooldown(),
				'support'        => (string) oshield_opt( 'support_number' ),
				'i18n'           => array(
					'blocked'    => __( 'Order not accepted', 'order-shield' ),
					'call'       => __( 'Call us', 'order-shield' ),
					'close'      => __( 'Close', 'order-shield' ),
				),
			)
		);
	}

	/**
	 * Is the visitor in the post-order cooldown window?
	 *
	 * @return bool
	 */
	public static function in_cooldown() {
		return ! empty( $_COOKIE['oshield_cooldown'] );
	}

	/**
	 * Make billing phone required whenever we validate it.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	public static function require_phone( $fields ) {
		if ( isset( $fields['billing_phone'] ) && ( oshield_on( 'phone_validation' ) || oshield_on( 'smart_filter' ) ) ) {
			$fields['billing_phone']['required'] = true;
		}
		return $fields;
	}

	/**
	 * Save the phone in 01XXXXXXXXX format.
	 *
	 * @param array $data Posted data.
	 * @return array
	 */
	public static function normalize_posted_phone( $data ) {
		if ( oshield_on( 'phone_validation' ) && ! empty( $data['billing_phone'] ) ) {
			$n = oshield_normalize_phone( $data['billing_phone'] );
			if ( '' !== $n ) {
				$data['billing_phone'] = $n;
			}
		}
		return $data;
	}

	/**
	 * Run every check. Returns null when the order may continue.
	 *
	 * @param array $who phone (raw), email.
	 * @return array|null [code, message, extra]
	 */
	public static function run_checks( $who ) {
		$raw   = isset( $who['phone'] ) ? (string) $who['phone'] : '';
		$phone = oshield_normalize_phone( $raw );
		$ctx   = array(
			'phone'     => $phone ? $phone : $raw,
			'ip'        => oshield_get_ip(),
			'device_id' => oshield_get_device_id(),
		);
		$hwid  = oshield_get_hwid();

		// 1. Phone format.
		if ( oshield_on( 'phone_validation' ) && '' === $phone ) {
			return array( 'phone', oshield_opt( 'phone_message' ) );
		}

		// 2. VPN / country.
		if ( OShield_VPN::active() ) {
			$vpn = OShield_VPN::check_current();
			if ( $vpn['blocked'] ) {
				oshield_log_event( 'vpn', $vpn['reason'], $ctx + array( 'country' => $vpn['country'] ) );
				return array( 'block', oshield_opt( 'vpn_message' ) );
			}
		}

		// 3. Block list.
		if ( oshield_on( 'blocklist_enabled' ) ) {
			$hit = OShield_Blocklist::match(
				array(
					'phone'  => $phone,
					'ip'     => $ctx['ip'],
					'device'   => $ctx['device_id'],
					'hardware' => $hwid,
					'email'    => isset( $who['email'] ) ? $who['email'] : '',
				)
			);
			if ( $hit ) {
				$label = isset( OShield_Blocklist::types()[ $hit->type ] ) ? OShield_Blocklist::types()[ $hit->type ] : $hit->type;
				/* translators: 1: block type 2: value */
				oshield_log_event( 'blocked', sprintf( __( 'Blocked %1$s: %2$s', 'order-shield' ), $label, $hit->value ), $ctx );
				return array( 'block', oshield_opt( 'block_message' ) );
			}
		}

		if ( '' === $phone ) {
			return null;
		}

		// 4. Repeat order.
		if ( oshield_on( 'repeat_block' ) ) {
			$recent = self::recent_order( $phone, (int) oshield_opt( 'repeat_hours' ) );
			if ( $recent ) {
				$msg = oshield_fill(
					oshield_opt( 'repeat_message' ),
					array(
						'hours'    => $recent['hours_left'],
						'order_id' => $recent['number'],
					)
				);
				oshield_log_event( 'repeat', sprintf( /* translators: %s order */ __( 'Repeat order within limit (previous #%s)', 'order-shield' ), $recent['number'] ), $ctx );
				return array( 'block', $msg );
			}
		}

		// 5. Smart filter.
		if ( oshield_on( 'smart_filter' ) ) {
			$report   = OShield_Fraud::report( $phone );
			$finished = (int) $report['success'] + (int) $report['cancel'];
			if ( null !== $report['ratio'] && $finished >= (int) oshield_opt( 'smart_min_orders' ) && $report['ratio'] < (float) oshield_opt( 'smart_threshold' ) ) {
				oshield_log_event( 'smart_block', sprintf( /* translators: %s ratio */ __( 'Low delivery ratio: %s%%', 'order-shield' ), $report['ratio'] ), $ctx );
				return array( 'block', oshield_opt( 'smart_message' ) );
			}
		}

		return null;
	}

	/**
	 * Latest order for a phone within N hours.
	 *
	 * @param string $phone Phone.
	 * @param int    $hours Hours.
	 * @return array|null
	 */
	public static function recent_order( $phone, $hours ) {
		$since = time() - max( 1, $hours ) * HOUR_IN_SECONDS;
		$ids   = oshield_get_order_ids_by_phone(
			$phone,
			array(
				'date_created' => '>' . $since,
				'status'       => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-checkout-draft' ) ),
			)
		);
		if ( empty( $ids ) ) {
			return null;
		}
		$order = wc_get_order( $ids[0] );
		if ( ! $order || ! $order->get_date_created() ) {
			return null;
		}
		$left = (int) ceil( ( $order->get_date_created()->getTimestamp() + $hours * HOUR_IN_SECONDS - time() ) / HOUR_IN_SECONDS );
		return array(
			'id'         => $order->get_id(),
			'number'     => $order->get_order_number(),
			'hours_left' => max( 1, $left ),
		);
	}

	/**
	 * Build the customer-facing error HTML with a marker the checkout script understands.
	 *
	 * @param array $result run_checks result.
	 * @return string
	 */
	private static function error_html( $result ) {
		list( $code, $message ) = $result;
		$phone                  = isset( $result[2] ) ? $result[2] : '';
		$html                   = '<span class="oshield-flag" data-oshield="' . esc_attr( $code ) . '" data-phone="' . esc_attr( $phone ) . '"></span>' . esc_html( $message );
		if ( 'block' === $code ) {
			$html .= oshield_support_html();
		}
		return $html;
	}

	/**
	 * Classic checkout validation.
	 *
	 * @param array    $data   Posted data.
	 * @param WP_Error $errors Errors.
	 */
	public static function validate_classic( $data, $errors ) {
		if ( $errors->has_errors() ) {
			return; // Let WooCommerce show its own field errors first.
		}
		$result = self::run_checks(
			array(
				'phone'     => isset( $data['billing_phone'] ) ? $data['billing_phone'] : '',
				'email'     => isset( $data['billing_email'] ) ? $data['billing_email'] : '',
			)
		);
		if ( $result ) {
			$errors->add( 'oshield_' . $result[0], self::error_html( $result ) );
		}
	}

	/**
	 * Block checkout (Store API) validation.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function validate_blocks( $order, $request ) {
		$result = self::run_checks(
			array(
				'phone'     => $order->get_billing_phone(),
				'email'     => $order->get_billing_email(),
			)
		);
		if ( $result ) {
			$msg = $result[1];
			if ( 'block' === $result[0] && oshield_opt( 'support_number' ) ) {
				$msg .= ' ' . sprintf( /* translators: %s phone */ __( 'Call: %s', 'order-shield' ), oshield_opt( 'support_number' ) );
			}
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'oshield_' . esc_attr( $result[0] ), esc_html( $msg ), 400 );
			}
		}
		if ( oshield_on( 'phone_validation' ) ) {
			$n = oshield_normalize_phone( $order->get_billing_phone() );
			if ( $n ) {
				$order->set_billing_phone( $n );
			}
		}
	}

	/**
	 * After an order is placed: save fingerprint, auto block, cooldown.
	 *
	 * @param int|WC_Order $order_or_id Order.
	 */
	public static function after_order( $order_or_id ) {
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order ) {
			return;
		}
		$phone = oshield_normalize_phone( $order->get_billing_phone() );

		$order->update_meta_data( '_oshield_ip', oshield_get_ip() );
		$order->update_meta_data( '_oshield_device_id', oshield_get_device_id() );
		$order->update_meta_data( '_oshield_hwid', oshield_get_hwid() );
		$order->update_meta_data( '_oshield_ua', oshield_get_ua() );
		$order->save();

		OShield_Fraud::flush( $phone );

		$cool = (int) oshield_opt( 'incomplete_cooldown' );
		if ( $cool > 0 && ! headers_sent() ) {
			setcookie( 'oshield_cooldown', '1', time() + $cool * MINUTE_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}

		if ( oshield_on( 'auto_block' ) && $phone ) {
			self::maybe_auto_block( $order, $phone );
		}

		do_action( 'oshield_order_placed', $order, $phone );
	}

	/**
	 * Auto block when too many orders come from one phone in the time window.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Phone.
	 */
	private static function maybe_auto_block( $order, $phone ) {
		$hours = max( 1, (int) oshield_opt( 'auto_block_hours' ) );
		$limit = max( 1, (int) oshield_opt( 'auto_block_limit' ) );
		$ids   = oshield_get_order_ids_by_phone( $phone, array( 'date_created' => '>' . ( time() - $hours * HOUR_IN_SECONDS ) ) );
		if ( count( $ids ) < $limit ) {
			return;
		}
		$days = (int) oshield_opt( 'auto_block_days' );
		/* translators: 1: orders 2: hours */
		$reason = sprintf( __( 'Auto block: %1$d orders in %2$d hours', 'order-shield' ), count( $ids ), $hours );
		OShield_Blocklist::add( 'phone', $phone, $reason, $days, 'auto' );
		if ( $order->get_meta( '_oshield_ip' ) ) {
			OShield_Blocklist::add( 'ip', $order->get_meta( '_oshield_ip' ), $reason, $days, 'auto' );
		}
		OShield_Blocklist::block_device( (string) $order->get_meta( '_oshield_device_id' ), (string) $order->get_meta( '_oshield_hwid' ), (string) $order->get_meta( '_oshield_ip' ), $reason, $days, 'auto' );
		oshield_log_event( 'auto_block', $reason, array( 'phone' => $phone ) );
		$order->add_order_note( 'Fake Order Shield: ' . $reason );
	}

	/**
	 * Clear cached ratio when an order status changes.
	 *
	 * @param int      $id    Order id.
	 * @param string   $from  From.
	 * @param string   $to    To.
	 * @param WC_Order $order Order.
	 */
	public static function flush_on_status( $id, $from, $to, $order ) {
		if ( $order instanceof WC_Order ) {
			OShield_Fraud::flush( $order->get_billing_phone() );
		}
	}

	/**
	 * "Block this customer" in the order actions box.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function order_actions( $actions ) {
		$actions['oshield_block_customer'] = __( 'Block this customer (phone, IP, device)', 'order-shield' );
		return $actions;
	}

	/**
	 * Handle the order action.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_block_customer( $order ) {
		$reason = sprintf( /* translators: %s order number */ __( 'Blocked from order #%s', 'order-shield' ), $order->get_order_number() );
		$done   = array();
		if ( true === OShield_Blocklist::add( 'phone', $order->get_billing_phone(), $reason, 0, 'order' ) ) {
			$done[] = __( 'phone', 'order-shield' );
		}
		if ( $order->get_meta( '_oshield_ip' ) && true === OShield_Blocklist::add( 'ip', $order->get_meta( '_oshield_ip' ), $reason, 0, 'order' ) ) {
			$done[] = 'IP';
		}
		if ( OShield_Blocklist::block_device( (string) $order->get_meta( '_oshield_device_id' ), (string) $order->get_meta( '_oshield_hwid' ), (string) $order->get_meta( '_oshield_ip' ), $reason, 0, 'order' ) ) {
			$done[] = __( 'device', 'order-shield' );
		}
		$order->add_order_note( sprintf( /* translators: %s list */ __( 'Fake Order Shield: customer blocked (%s).', 'order-shield' ), implode( ', ', $done ) ) );
	}
}
