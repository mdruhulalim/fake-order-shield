<?php
/**
 * Admin: menus, assets, form handlers, AJAX and WooCommerce order screen integration.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once OSHIELD_DIR . 'admin/class-oshield-list-tables.php';
require_once OSHIELD_DIR . 'admin/class-oshield-admin-pages.php';
require_once OSHIELD_DIR . 'admin/class-oshield-report-view.php';

class OShield_Admin {

	/**
	 * Our page hook suffixes.
	 *
	 * @var string[]
	 */
	public static $hooks = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notices' ) );

		add_action( 'admin_post_oshield_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_oshield_tools', array( __CLASS__, 'tools' ) );
		add_action( 'admin_post_oshield_preset', array( __CLASS__, 'apply_preset' ) );
		add_action( 'admin_post_oshield_block_add', array( __CLASS__, 'block_add' ) );

		$ajax = array( 'report', 'block_toggle', 'send_courier', 'refresh_courier', 'replay', 'live', 'journey', 'live_events' );
		foreach ( $ajax as $a ) {
			add_action( 'wp_ajax_oshield_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}

		if ( class_exists( 'WooCommerce' ) ) {
			// Orders list columns: legacy posts screen and HPOS screen.
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'order_columns' ), 20 );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'order_column_legacy' ), 20, 2 );
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'order_columns' ), 20 );
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'order_column_hpos' ), 20, 2 );
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
		}
	}

	/**
	 * Capability for day-to-day screens.
	 *
	 * @return string
	 */
	public static function cap() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Admin menu.
	 */
	public static function menu() {
		$cap = self::cap();
		$p   = 'order-shield';

		self::$hooks['dashboard'] = add_menu_page( __( 'Fake Order Shield', 'order-shield' ), __( 'Fake Order Shield', 'order-shield' ), $cap, $p, array( 'OShield_Admin_Pages', 'dashboard' ), 'dashicons-shield', 56 );
		self::$hooks['dashboard'] = add_submenu_page( $p, __( 'Fake Order Shield Dashboard', 'order-shield' ), __( 'Dashboard', 'order-shield' ), $cap, $p, array( 'OShield_Admin_Pages', 'dashboard' ) );
		self::$hooks['live']      = add_submenu_page( $p, __( 'Live Visitors', 'order-shield' ), __( 'Live Visitors', 'order-shield' ), $cap, $p . '-live', array( 'OShield_Admin_Pages', 'live' ) );
		self::$hooks['search']    = add_submenu_page( $p, __( 'Fraud Check', 'order-shield' ), __( 'Fraud Check', 'order-shield' ), $cap, $p . '-search', array( 'OShield_Admin_Pages', 'search' ) );

		$counts = OShield_Incomplete::counts();
		$bubble = $counts['pending'] ? ' <span class="awaiting-mod">' . number_format_i18n( $counts['pending'] ) . '</span>' : '';
		self::$hooks['incomplete'] = add_submenu_page( $p, __( 'Incomplete Orders', 'order-shield' ), __( 'Incomplete Orders', 'order-shield' ) . $bubble, $cap, $p . '-incomplete', array( 'OShield_Admin_Pages', 'incomplete' ) );
		self::$hooks['blocklist']  = add_submenu_page( $p, __( 'Block List', 'order-shield' ), __( 'Block List', 'order-shield' ), $cap, $p . '-blocklist', array( 'OShield_Admin_Pages', 'blocklist' ) );
		self::$hooks['activity']   = add_submenu_page( $p, __( 'Activity Log', 'order-shield' ), __( 'Activity Log', 'order-shield' ), $cap, $p . '-activity', array( 'OShield_Admin_Pages', 'activity' ) );
		self::$hooks['settings']   = add_submenu_page( $p, __( 'Fake Order Shield Settings', 'order-shield' ), __( 'Settings', 'order-shield' ), 'manage_options', $p . '-settings', array( 'OShield_Admin_Pages', 'settings' ) );

		// List table screen options + row/bulk actions run before output.
		foreach ( array( 'incomplete', 'blocklist', 'activity' ) as $k ) {
			add_action( 'load-' . self::$hooks[ $k ], array( 'OShield_Admin_Pages', 'load_' . $k ) );
		}
	}

	/**
	 * Is the current screen a WooCommerce orders screen?
	 *
	 * @return bool
	 */
	private static function is_orders_screen() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, array( 'edit-shop_order', 'shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Enqueue admin assets on our pages and order screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$ours = in_array( $hook, self::$hooks, true );
		if ( ! $ours && ! self::is_orders_screen() ) {
			return;
		}
		wp_enqueue_style( 'oshield-admin', OSHIELD_URL . 'assets/css/admin.css', array(), OSHIELD_VERSION );
		wp_enqueue_script( 'oshield-admin', OSHIELD_URL . 'assets/js/admin.js', array( 'jquery' ), OSHIELD_VERSION, true );
		wp_localize_script(
			'oshield-admin',
			'OShieldAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'oshield_admin' ),
				'autoload'  => oshield_on( 'report_autoload' ),
				'i18n'      => array(
					'loading'      => __( 'Loading…', 'order-shield' ),
					'error'        => __( 'Something went wrong. Please try again.', 'order-shield' ),
					'confirmSend'  => __( 'Send this order to %s?', 'order-shield' ),
					'confirmBlock' => __( 'Block this %s?', 'order-shield' ),
					'sent'         => __( 'Sent!', 'order-shield' ),
					'noReplay'     => __( 'No recording for this visit.', 'order-shield' ),
					'blocked'      => __( 'Blocked', 'order-shield' ),
					'liveWaiting'  => __( 'Connecting to the visitor… the screen appears when they move or the next update arrives (up to 25 seconds).', 'order-shield' ),
					'liveNow'      => __( 'LIVE', 'order-shield' ),
					'liveOffline'  => __( 'Visitor left the site', 'order-shield' ),
					'recommended'  => __( 'Recommended:', 'order-shield' ),
					'useThis'      => __( 'Click to use this value', 'order-shield' ),
					'unsaved'      => __( 'You have unsaved changes', 'order-shield' ),
					'leavePage'    => __( 'You have unsaved changes. Leave this page?', 'order-shield' ),
					'copied'       => __( 'Copied', 'order-shield' ),
					'confirmPreset' => __( 'Apply the "%s" protection level? Your checkout protection settings will change.', 'order-shield' ),
				),
			)
		);
		if ( ( isset( self::$hooks['incomplete'] ) && $hook === self::$hooks['incomplete'] ) || ( isset( self::$hooks['live'] ) && $hook === self::$hooks['live'] ) ) {
			wp_enqueue_style( 'oshield-rrweb-player', OSHIELD_URL . 'assets/vendor/rrweb/rrweb-player.min.css', array(), '2.1.7' );
			wp_enqueue_script( 'oshield-rrweb-player', OSHIELD_URL . 'assets/vendor/rrweb/rrweb-player.min.js', array(), '2.1.7', true );
		}
	}

	/**
	 * Go to the dashboard right after activation.
	 */
	public static function activation_redirect() {
		if ( get_transient( 'oshield_activated' ) && ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'oshield_activated' );
			if ( current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=order-shield' ) );
				exit;
			}
		}
	}

	/**
	 * Warnings for settings that cannot work.
	 */
	public static function setup_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'order-shield' ) ) {
			return;
		}
		if ( in_array( oshield_opt( 'fraud_source' ), array( 'api', 'both' ), true ) && '' === trim( (string) oshield_opt( 'fraud_api_url' ) ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( __( '<strong>Fake Order Shield:</strong> Fraud check is set to use a courier API, but no API URL is entered. Store history is used meanwhile.', 'order-shield' ) ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Form handlers (admin-post)
	 * ------------------------------------------------------------------ */

	/**
	 * Save a settings tab.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'order-shield' ) );
		}
		check_admin_referer( 'oshield_settings' );
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'general';
		$values = isset( $_POST['oshield'] ) && is_array( $_POST['oshield'] ) ? $_POST['oshield'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
		OShield_Settings::save_tab( $tab, $values );
		wp_safe_redirect( admin_url( 'admin.php?page=order-shield-settings&tab=' . $tab . '&updated=1' ) );
		exit;
	}

	/**
	 * Apply a protection level preset.
	 */
	public static function apply_preset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'order-shield' ) );
		}
		check_admin_referer( 'oshield_preset' );
		$id   = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : '';
		$back = isset( $_POST['back'] ) && 'dashboard' === $_POST['back'] ? admin_url( 'admin.php?page=order-shield' ) : admin_url( 'admin.php?page=order-shield-settings&tab=checkout' );
		$ok   = OShield_Settings::apply_preset( $id );
		wp_safe_redirect( add_query_arg( 'oshield_preset', $ok ? $id : 'error', $back ) );
		exit;
	}

	/**
	 * Tools: import/export/repair.
	 */
	public static function tools() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'order-shield' ) );
		}
		check_admin_referer( 'oshield_tools' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : ( isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '' );
		$back = admin_url( 'admin.php?page=order-shield-settings&tab=tools' );
		$msg  = '';

		switch ( $do ) {
			case 'export_blocks':
				nocache_headers();
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=order-shield-blocklist-' . gmdate( 'Y-m-d' ) . '.csv' );
				echo OShield_Blocklist::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;

			case 'import_blocks':
				if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore
					$n   = OShield_Blocklist::import_csv( $_FILES['file']['tmp_name'] ); // phpcs:ignore
					$msg = rawurlencode( sprintf( /* translators: %d count */ _n( '%d block imported.', '%d blocks imported.', $n, 'order-shield' ), $n ) );
				}
				break;

			case 'export_settings':
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=order-shield-settings-' . gmdate( 'Y-m-d' ) . '.json' );
				echo wp_json_encode( get_option( OShield_Settings::OPTION, array() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				exit;

			case 'import_settings':
				if ( ! empty( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore
					$data = json_decode( (string) file_get_contents( $_FILES['file']['tmp_name'] ), true ); // phpcs:ignore
					if ( is_array( $data ) ) {
						$fields = OShield_Settings::fields();
						$clean  = array();
						foreach ( $data as $k => $v ) {
							if ( isset( $fields[ $k ] ) ) {
								$clean[ $k ] = is_array( $v ) ? array_map( 'sanitize_textarea_field', $v ) : sanitize_textarea_field( (string) $v );
							}
						}
						OShield_Settings::update( $clean );
						$msg = rawurlencode( __( 'Settings imported.', 'order-shield' ) );
					} else {
						$msg = rawurlencode( __( 'That file is not a valid settings export.', 'order-shield' ) );
					}
				}
				break;

			case 'repair':
				OShield_Install::create_tables();
				OShield_Install::schedule();
				$msg = rawurlencode( __( 'Database tables checked and repaired.', 'order-shield' ) );
				break;

			case 'clear_cache':
				global $wpdb;
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_oshield\_%' OR option_name LIKE '\_transient\_timeout\_oshield\_%'" ); // phpcs:ignore
				delete_option( 'oshield_pathao_token' );
				$msg = rawurlencode( __( 'Caches cleared.', 'order-shield' ) );
				break;
		}
		wp_safe_redirect( $back . ( $msg ? '&oshield_msg=' . $msg : '' ) );
		exit;
	}

	/**
	 * Add a block from the Block List page.
	 */
	public static function block_add() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'order-shield' ) );
		}
		check_admin_referer( 'oshield_block_add' );
		$type   = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$value  = isset( $_POST['value'] ) ? sanitize_textarea_field( wp_unslash( $_POST['value'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$days   = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 0;
		$added  = 0;
		$errors = array();
		foreach ( preg_split( '/[\r\n,]+/', $value ) as $v ) {
			if ( '' === trim( $v ) ) {
				continue;
			}
			$r = OShield_Blocklist::add( $type, $v, $reason, $days, 'manual' );
			if ( is_wp_error( $r ) ) {
				$errors[] = trim( $v );
			} else {
				++$added;
			}
		}
		$url = admin_url( 'admin.php?page=order-shield-blocklist' );
		$url = add_query_arg( array( 'added' => $added, 'invalid' => rawurlencode( implode( ', ', $errors ) ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Common AJAX guard.
	 */
	private static function ajax_guard() {
		check_ajax_referer( 'oshield_admin', 'nonce' );
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'order-shield' ) ), 403 );
		}
	}

	/**
	 * Fraud report HTML (compact for the list + full for the popup).
	 */
	public static function ajax_report() {
		self::ajax_guard();
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$fresh    = ! empty( $_POST['fresh'] );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order && '' === $phone ) {
			$phone = $order->get_billing_phone();
		}
		$report = OShield_Fraud::report( $phone, $fresh );
		wp_send_json_success(
			array(
				'compact' => OShield_Report_View::compact( $report, $order ),
				'details' => OShield_Report_View::details( $report, $order ),
			)
		);
	}

	/**
	 * Block / unblock from any screen.
	 */
	public static function ajax_block_toggle() {
		self::ajax_guard();
		$type  = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$block = ! empty( $_POST['block'] );
		$hwid  = isset( $_POST['hwid'] ) ? sanitize_text_field( wp_unslash( $_POST['hwid'] ) ) : '';
		$ip    = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		if ( 'device' === $type && '' !== $hwid ) {
			if ( $block ) {
				OShield_Blocklist::block_device( $value, $hwid, $ip, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : __( 'Blocked by admin', 'order-shield' ), 0, 'manual' );
			} else {
				OShield_Blocklist::unblock_device( $value, $hwid );
			}
			wp_send_json_success( array( 'blocked' => $block ) );
		}
		if ( $block ) {
			$res = OShield_Blocklist::add( $type, $value, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : __( 'Blocked by admin', 'order-shield' ), 0, 'manual' );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
		} else {
			OShield_Blocklist::remove( $type, $value );
		}
		wp_send_json_success( array( 'blocked' => $block ) );
	}

	/**
	 * Send an order to a courier.
	 */
	public static function ajax_send_courier() {
		self::ajax_guard();
		$order   = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
		$courier = isset( $_POST['courier'] ) ? sanitize_key( $_POST['courier'] ) : '';
		$note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'order-shield' ) ) );
		}
		$res = OShield_Courier::send( $order, $courier, $note, ! empty( $_POST['force'] ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'html' => OShield_Report_View::courier_cell( wc_get_order( $order->get_id() ) ) ) );
	}

	/**
	 * Refresh courier status.
	 */
	public static function ajax_refresh_courier() {
		self::ajax_guard();
		$id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$res = OShield_Courier::refresh( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success( array( 'html' => OShield_Report_View::courier_cell( wc_get_order( $id ) ) ) );
	}

	/**
	 * Session recording events.
	 */
	public static function ajax_replay() {
		self::ajax_guard();
		if ( ! empty( $_POST['sid'] ) ) {
			$token = OShield_Live::clean_id( sanitize_text_field( wp_unslash( $_POST['sid'] ) ) );
		} else {
			$row   = OShield_Incomplete::get( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
			$token = $row ? $row->token : '';
		}
		if ( '' === $token ) {
			wp_send_json_error();
		}
		wp_send_json_success( array( 'events' => OShield_Incomplete::get_events( $token ) ) );
	}

	/**
	 * Live visitors: numbers, pages and rows (polled every few seconds).
	 */
	public static function ajax_live() {
		self::ajax_guard();
		$view   = isset( $_POST['view'] ) ? sanitize_key( $_POST['view'] ) : 'online';
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		wp_send_json_success(
			array(
				'stats' => OShield_Live::stats(),
				'pages' => OShield_Admin_Pages::live_pages_html(),
				'rows'  => OShield_Admin_Pages::live_rows_html( OShield_Live::visitors( in_array( $view, array( 'online', 'today', 'all' ), true ) ? $view : 'online', $search ) ),
				'time'  => current_time( get_option( 'time_format' ) ),
			)
		);
	}

	/**
	 * Live watching: new recording events since the last call. Also tells the visitor's
	 * browser (through the transient) to send updates every second.
	 */
	public static function ajax_live_events() {
		self::ajax_guard();
		$sid   = OShield_Live::clean_id( isset( $_POST['sid'] ) ? sanitize_text_field( wp_unslash( $_POST['sid'] ) ) : '' );
		$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		if ( '' === $sid ) {
			wp_send_json_error();
		}
		OShield_Live::set_watched( $sid );
		list( $events, $last ) = OShield_Live::events_after( $sid, $after );
		$v      = OShield_Live::get( $sid );
		$online = $v && strtotime( $v->last_seen ) >= strtotime( OShield_Live::online_since() );
		wp_send_json_success(
			array(
				'events' => $events,
				'last'   => $last,
				'online' => $online,
				'page'   => $v ? ( $v->current_title ? $v->current_title : $v->current_url ) : '',
				'url'    => $v ? $v->current_url : '',
			)
		);
	}

	/**
	 * Pages one visitor viewed.
	 */
	public static function ajax_journey() {
		self::ajax_guard();
		$sid = isset( $_POST['sid'] ) ? sanitize_text_field( wp_unslash( $_POST['sid'] ) ) : '';
		wp_send_json_success( array( 'html' => OShield_Admin_Pages::journey_html( $sid ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Orders screen
	 * ------------------------------------------------------------------ */

	/**
	 * Add columns after "Status".
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function order_columns( $columns ) {
		$out = array();
		foreach ( $columns as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				if ( oshield_on( 'report_column' ) ) {
					$out['oshield_report'] = __( 'Delivery Ratio', 'order-shield' );
				}
				if ( OShield_Courier::enabled() ) {
					$out['oshield_courier'] = __( 'Courier', 'order-shield' );
				}
			}
		}
		return $out;
	}

	/**
	 * Legacy screen column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Order id.
	 */
	public static function order_column_legacy( $column, $post_id ) {
		self::order_column( $column, wc_get_order( $post_id ) );
	}

	/**
	 * HPOS screen column content.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function order_column_hpos( $column, $order ) {
		self::order_column( $column, $order );
	}

	/**
	 * Column output.
	 *
	 * @param string        $column Column.
	 * @param WC_Order|bool $order  Order.
	 */
	private static function order_column( $column, $order ) {
		if ( ! $order ) {
			return;
		}
		if ( 'oshield_report' === $column ) {
			$phone = $order->get_billing_phone();
			echo '<div class="oshield-report" data-order="' . esc_attr( $order->get_id() ) . '" data-phone="' . esc_attr( $phone ) . '">';
			echo '<button type="button" class="button button-small oshield-load">' . esc_html__( 'Check', 'order-shield' ) . '</button>';
			echo '</div>';
		} elseif ( 'oshield_courier' === $column ) {
			echo OShield_Report_View::courier_cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Meta box on the edit order screen.
	 */
	public static function meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'oshield-order', __( 'Fake Order Shield', 'order-shield' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'high' );
		}
	}

	/**
	 * Meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or order.
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! $order->get_id() ) {
			return;
		}
		OShield_Report_View::meta_box( $order );
	}
}
