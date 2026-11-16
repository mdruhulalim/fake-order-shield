<?php
/**
 * WP_List_Table screens: incomplete orders, block list, activity log.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shared helpers.
 */
abstract class OShield_Base_Table extends WP_List_Table {

	/**
	 * Rows per page from screen options.
	 *
	 * @return int
	 */
	protected function per_page() {
		return $this->get_items_per_page( 'oshield_per_page', 20 );
	}

	/**
	 * Current search term.
	 *
	 * @return string
	 */
	protected function search() {
		return isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Human time.
	 *
	 * @param string $mysql Date.
	 * @return string
	 */
	protected function when( $mysql ) {
		$ts  = strtotime( $mysql );
		$now = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$abs = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $mysql );
		if ( $ts && $now - $ts < DAY_IN_SECONDS && $now >= $ts ) {
			/* translators: %s time diff */
			return '<span title="' . esc_attr( $abs ) . '">' . esc_html( sprintf( __( '%s ago', 'order-shield' ), human_time_diff( $ts, $now ) ) ) . '</span>';
		}
		return esc_html( $abs );
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( $item->id ) . '">';
	}

	/**
	 * Default column.
	 *
	 * @param object $item   Row.
	 * @param string $column Column.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item->$column ) ? esc_html( $item->$column ) : '';
	}

	/**
	 * Run a paged query.
	 *
	 * @param string $table   Table.
	 * @param array  $where   SQL where parts (already prepared).
	 * @param string $orderby Order column.
	 */
	protected function query( $table, $where, $orderby = 'id' ) {
		global $wpdb;
		$per   = $this->per_page();
		$page  = $this->get_pagenum();
		$sql_w = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$order = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$sql_w}" ); // phpcs:ignore
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$sql_w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ); // phpcs:ignore
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
	}
}

/**
 * Incomplete orders.
 */
class OShield_Incomplete_Table extends OShield_Base_Table {

	/**
	 * Tokens that have a recording.
	 *
	 * @var array
	 */
	private $recorded = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'incomplete',
				'plural'   => 'incompletes',
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'customer' => __( 'Customer', 'order-shield' ),
			'address'  => __( 'Address', 'order-shield' ),
			'cart'     => __( 'Cart', 'order-shield' ),
			'status'   => __( 'Status', 'order-shield' ),
			'ratio'    => __( 'Delivery Ratio', 'order-shield' ),
			'updated'  => __( 'Last activity', 'order-shield' ),
		);
	}

	/**
	 * Sortable.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array( 'updated' => array( 'updated_at', true ) );
	}

	/**
	 * Views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = OShield_Incomplete::counts();
		$current = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$labels  = array(
			'pending'   => __( 'Not ordered', 'order-shield' ),
			'recovered' => __( 'Ordered later', 'order-shield' ),
			'converted' => __( 'Converted by you', 'order-shield' ),
			'all'       => __( 'All', 'order-shield' ),
		);
		$views   = array();
		foreach ( $labels as $k => $label ) {
			$url         = add_query_arg( array( 'page' => 'order-shield-incomplete', 'status' => $k ), admin_url( 'admin.php' ) );
			$views[ $k ] = sprintf( '<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url( $url ), $current === $k ? ' class="current" aria-current="page"' : '', esc_html( $label ), number_format_i18n( $counts[ $k ] ) );
		}
		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'convert' => __( 'Create orders', 'order-shield' ),
			'delete'  => __( 'Delete', 'order-shield' ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$where  = array();
		if ( 'all' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		$s = $this->search();
		if ( '' !== $s ) {
			$like    = '%' . $wpdb->esc_like( $s ) . '%';
			$where[] = $wpdb->prepare( '(phone LIKE %s OR name LIKE %s OR email LIKE %s OR address LIKE %s)', $like, $like, $like, $like );
		}
		$orderby = ( isset( $_GET['orderby'] ) && 'updated_at' === $_GET['orderby'] ) ? 'updated_at' : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->query( OShield_Incomplete::table(), $where, $orderby );
		$this->recorded = OShield_Incomplete::has_recordings( wp_list_pluck( $this->items, 'token' ) );
	}

	/**
	 * Customer column with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_customer( $item ) {
		$base    = admin_url( 'admin.php?page=order-shield-incomplete' );
		$actions = array();
		if ( 'converted' !== $item->status ) {
			$actions['convert'] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'convert', 'ids[]' => $item->id ), $base ), 'bulk-incompletes' ) ) . '">' . esc_html__( 'Create order', 'order-shield' ) . '</a>';
		}
		$actions['delete'] = '<a class="submitdelete" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'ids[]' => $item->id ), $base ), 'bulk-incompletes' ) ) . '">' . esc_html__( 'Delete', 'order-shield' ) . '</a>';

		$out  = '<strong>' . esc_html( $item->name ? $item->name : __( '(no name)', 'order-shield' ) ) . '</strong><br>';
		$out .= '<a href="tel:' . esc_attr( $item->phone ) . '">' . esc_html( $item->phone ) . '</a>';
		$out .= ' <a href="' . esc_url( 'https://wa.me/88' . $item->phone ) . '" target="_blank" rel="noopener" class="oshield-muted">WhatsApp</a>';
		if ( $item->email ) {
			$out .= '<br><span class="oshield-muted">' . esc_html( $item->email ) . '</span>';
		}
		if ( ! empty( $this->recorded[ $item->token ] ) ) {
			$out .= '<br><button type="button" class="button button-small oshield-replay" data-id="' . esc_attr( $item->id ) . '"><span class="dashicons dashicons-controls-play"></span> ' . esc_html__( 'Watch session', 'order-shield' ) . '</button>';
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Address.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_address( $item ) {
		$fields = json_decode( (string) $item->fields, true );
		$out    = esc_html( $item->address );
		if ( ! empty( $fields['order_comments'] ) ) {
			$out .= '<br><em class="oshield-muted">“' . esc_html( $fields['order_comments'] ) . '”</em>';
		}
		return $out;
	}

	/**
	 * Cart.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_cart( $item ) {
		$cart = json_decode( (string) $item->cart, true );
		$out  = '';
		if ( ! empty( $cart['items'] ) ) {
			$out .= '<ul class="oshield-cart">';
			foreach ( $cart['items'] as $i ) {
				$out .= '<li>' . esc_html( $i['name'] ) . ' × ' . esc_html( $i['quantity'] ) . '</li>';
			}
			$out .= '</ul>';
		}
		return $out . '<strong>' . wp_kses_post( wc_price( $item->cart_total, array( 'currency' => $item->currency ) ) ) . '</strong>';
	}

	/**
	 * Status.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		$labels = array(
			'pending'   => array( __( 'Not ordered', 'order-shield' ), 'medium' ),
			'recovered' => array( __( 'Ordered later', 'order-shield' ), 'good' ),
			'converted' => array( __( 'Converted', 'order-shield' ), 'good' ),
		);
		list( $label, $class ) = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : array( $item->status, 'new' );
		$out = '<span class="oshield-pill oshield-pill-' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
		if ( $item->order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $item->order_id ) : null;
			if ( $order ) {
				$out .= '<br><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
			}
		}
		return $out;
	}

	/**
	 * Ratio (loaded by AJAX).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_ratio( $item ) {
		return '<div class="oshield-report" data-order="0" data-phone="' . esc_attr( $item->phone ) . '"><button type="button" class="button button-small oshield-load">' . esc_html__( 'Check', 'order-shield' ) . '</button></div>';
	}

	/**
	 * Updated.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_updated( $item ) {
		return $this->when( $item->updated_at ) . '<br><span class="oshield-muted">' . esc_html( $item->ip ) . '</span>';
	}

	/**
	 * Empty message.
	 */
	public function no_items() {
		if ( ! oshield_on( 'incomplete_enabled' ) ) {
			printf(
				/* translators: %s settings link */
				esc_html__( 'Capturing is off. Turn it on in %s.', 'order-shield' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=incomplete' ) ) . '">' . esc_html__( 'Settings → Incomplete Orders', 'order-shield' ) . '</a>'
			);
			return;
		}
		esc_html_e( 'No incomplete orders here. Customers who type a valid phone number at checkout and leave will appear here.', 'order-shield' );
	}
}

/**
 * Block list.
 */
class OShield_Blocks_Table extends OShield_Base_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'block',
				'plural'   => 'blocks',
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox">',
			'value'   => __( 'Blocked value', 'order-shield' ),
			'type'    => __( 'Type', 'order-shield' ),
			'reason'  => __( 'Reason', 'order-shield' ),
			'hits'    => __( 'Attempts stopped', 'order-shield' ),
			'expires' => __( 'Expires', 'order-shield' ),
			'created' => __( 'Added', 'order-shield' ),
		);
	}

	/**
	 * Views by type.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$views   = array( '' => __( 'All', 'order-shield' ) ) + OShield_Blocklist::types();
		$out     = array();
		foreach ( $views as $k => $label ) {
			$url             = add_query_arg( array( 'page' => 'order-shield-blocklist', 'type' => $k ), admin_url( 'admin.php' ) );
			$out[ $k ? $k : 'all' ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $current === $k ? ' class="current"' : '', esc_html( $label ) );
		}
		return $out;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'enable'  => __( 'Enable', 'order-shield' ),
			'disable' => __( 'Disable', 'order-shield' ),
			'delete'  => __( 'Delete', 'order-shield' ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;
		$where = array();
		$type  = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $type ) {
			$where[] = $wpdb->prepare( 'type = %s', $type );
		}
		$s = $this->search();
		if ( '' !== $s ) {
			$like    = '%' . $wpdb->esc_like( $s ) . '%';
			$alt     = oshield_normalize_phone( $s );
			$where[] = $wpdb->prepare( '(value LIKE %s OR reason LIKE %s OR value = %s)', $like, $like, $alt ? $alt : $s );
		}
		$this->query( OShield_Blocklist::table(), $where );
	}

	/**
	 * Value with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_value( $item ) {
		$base    = admin_url( 'admin.php?page=order-shield-blocklist' );
		$actions = array(
			'toggle' => '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => $item->active ? 'disable' : 'enable', 'ids[]' => $item->id ), $base ), 'bulk-blocks' ) ) . '">' . ( $item->active ? esc_html__( 'Disable', 'order-shield' ) : esc_html__( 'Enable', 'order-shield' ) ) . '</a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'ids[]' => $item->id ), $base ), 'bulk-blocks' ) ) . '">' . esc_html__( 'Delete', 'order-shield' ) . '</a>',
		);
		if ( 'phone' === $item->type ) {
			$actions['check'] = '<a href="' . esc_url( admin_url( 'admin.php?page=order-shield-search&phone=' . rawurlencode( $item->value ) ) ) . '">' . esc_html__( 'Fraud check', 'order-shield' ) . '</a>';
		}
		$expired = $item->expires_at && strtotime( $item->expires_at ) <= current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$state   = '';
		if ( ! $item->active ) {
			$state = ' <span class="oshield-pill">' . esc_html__( 'Disabled', 'order-shield' ) . '</span>';
		} elseif ( $expired ) {
			$state = ' <span class="oshield-pill">' . esc_html__( 'Expired', 'order-shield' ) . '</span>';
		}
		return '<strong><code>' . esc_html( $item->value ) . '</code></strong>' . $state . $this->row_actions( $actions );
	}

	/**
	 * Type.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_type( $item ) {
		$types = OShield_Blocklist::types();
		$src   = array(
			'manual' => __( 'by admin', 'order-shield' ),
			'auto'   => __( 'auto block', 'order-shield' ),
			'order'  => __( 'from order', 'order-shield' ),
			'import' => __( 'imported', 'order-shield' ),
		);
		return esc_html( isset( $types[ $item->type ] ) ? $types[ $item->type ] : $item->type ) . '<br><span class="oshield-muted">' . esc_html( isset( $src[ $item->source ] ) ? $src[ $item->source ] : $item->source ) . '</span>';
	}

	/**
	 * Expiry.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_expires( $item ) {
		return $item->expires_at ? esc_html( mysql2date( get_option( 'date_format' ), $item->expires_at ) ) : esc_html__( 'Never', 'order-shield' );
	}

	/**
	 * Created.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created( $item ) {
		return $this->when( $item->created_at );
	}

	/**
	 * Empty.
	 */
	public function no_items() {
		esc_html_e( 'Nobody is blocked yet.', 'order-shield' );
	}
}

/**
 * Activity log.
 */
class OShield_Events_Table extends OShield_Base_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
			)
		);
	}

	/**
	 * Labels for event types.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'blocked'     => __( 'Block list', 'order-shield' ),
			'repeat'      => __( 'Repeat order', 'order-shield' ),
			'smart_block' => __( 'Smart filter: blocked', 'order-shield' ),
			'vpn'         => __( 'VPN / country', 'order-shield' ),
			'auto_block'  => __( 'Auto block', 'order-shield' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'type'    => __( 'What happened', 'order-shield' ),
			'phone'   => __( 'Phone', 'order-shield' ),
			'ip'      => __( 'IP / Country', 'order-shield' ),
			'reason'  => __( 'Details', 'order-shield' ),
			'created' => __( 'When', 'order-shield' ),
		);
	}

	/**
	 * Views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$current = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$out     = array();
		foreach ( array( '' => __( 'All', 'order-shield' ) ) + self::type_labels() as $k => $label ) {
			$url                    = add_query_arg( array( 'page' => 'order-shield-activity', 'type' => $k ), admin_url( 'admin.php' ) );
			$out[ $k ? $k : 'all' ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( $url ), $current === $k ? ' class="current"' : '', esc_html( $label ) );
		}
		return $out;
	}

	/**
	 * Load rows.
	 */
	public function prepare_items() {
		global $wpdb;
		$where = array();
		$type  = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $type ) {
			$where[] = $wpdb->prepare( 'type = %s', $type );
		}
		$s = $this->search();
		if ( '' !== $s ) {
			$like    = '%' . $wpdb->esc_like( $s ) . '%';
			$where[] = $wpdb->prepare( '(phone LIKE %s OR ip LIKE %s OR reason LIKE %s)', $like, $like, $like );
		}
		$this->query( $wpdb->prefix . 'oshield_events', $where );
	}

	/**
	 * Type.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_type( $item ) {
		$labels = self::type_labels();
		return '<strong>' . esc_html( isset( $labels[ $item->type ] ) ? $labels[ $item->type ] : $item->type ) . '</strong>';
	}

	/**
	 * Phone.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_phone( $item ) {
		if ( ! $item->phone ) {
			return '—';
		}
		return '<a href="' . esc_url( admin_url( 'admin.php?page=order-shield-search&phone=' . rawurlencode( $item->phone ) ) ) . '">' . esc_html( $item->phone ) . '</a>';
	}

	/**
	 * IP.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_ip( $item ) {
		return esc_html( $item->ip ) . ( $item->country ? ' <span class="oshield-pill">' . esc_html( $item->country ) . '</span>' : '' );
	}

	/**
	 * Created.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created( $item ) {
		return $this->when( $item->created_at );
	}

	/**
	 * Empty.
	 */
	public function no_items() {
		esc_html_e( 'Nothing stopped yet. Blocked checkout attempts will be listed here.', 'order-shield' );
	}
}
