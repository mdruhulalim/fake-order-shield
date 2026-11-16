<?php
/**
 * HTML for fraud reports, the courier cell and the order meta box.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Report_View {

	/**
	 * Label + class for a risk level.
	 *
	 * @param string $risk Risk.
	 * @return array [label, class]
	 */
	public static function risk_label( $risk ) {
		$map = array(
			'good'   => array( __( 'Trusted', 'order-shield' ), 'good' ),
			'medium' => array( __( 'Be careful', 'order-shield' ), 'medium' ),
			'risky'  => array( __( 'High risk', 'order-shield' ), 'risky' ),
			'new'    => array( __( 'New customer', 'order-shield' ), 'new' ),
		);
		return isset( $map[ $risk ] ) ? $map[ $risk ] : $map['new'];
	}

	/**
	 * Pill colour for a WooCommerce order status.
	 *
	 * @param string $status Status without wc- prefix.
	 * @return string
	 */
	public static function status_class( $status ) {
		$good = array_map( array( 'OShield_Fraud', 'strip_wc' ), (array) oshield_opt( 'success_statuses' ) );
		$bad  = array_map( array( 'OShield_Fraud', 'strip_wc' ), (array) oshield_opt( 'cancel_statuses' ) );
		if ( in_array( $status, $good, true ) ) {
			return 'good';
		}
		if ( in_array( $status, $bad, true ) ) {
			return 'risky';
		}
		return 'new';
	}

	/**
	 * Compact cell for the orders list.
	 *
	 * @param array         $r     Report.
	 * @param WC_Order|null $order Order.
	 * @return string
	 */
	public static function compact( $r, $order = null ) {
		if ( ! $r['valid'] ) {
			return '<span class="oshield-muted">' . esc_html__( 'No valid phone', 'order-shield' ) . '</span>';
		}
		list( $label, $class ) = self::risk_label( $r['risk'] );
		$blocked               = (bool) OShield_Blocklist::find( 'phone', $r['phone'] );
		$ratio                 = null === $r['ratio'] ? 0 : $r['ratio'];

		ob_start();
		?>
		<div class="oshield-mini oshield-risk-<?php echo esc_attr( $class ); ?>">
			<?php if ( null !== $r['ratio'] ) : ?>
				<div class="oshield-bar" title="<?php echo esc_attr( $label ); ?>"><span style="width:<?php echo esc_attr( $ratio ); ?>%"></span></div>
				<div class="oshield-mini-line">
					<strong><?php echo esc_html( $ratio ); ?>%</strong>
					<span title="<?php esc_attr_e( 'Delivered', 'order-shield' ); ?>" class="oshield-ok">✓<?php echo esc_html( $r['success'] ); ?></span>
					<span title="<?php esc_attr_e( 'Cancelled / returned', 'order-shield' ); ?>" class="oshield-bad">✗<?php echo esc_html( $r['cancel'] ); ?></span>
					<span class="oshield-muted" title="<?php esc_attr_e( 'Total', 'order-shield' ); ?>">/ <?php echo esc_html( $r['total'] ); ?></span>
				</div>
			<?php else : ?>
				<span class="oshield-pill oshield-pill-new"><?php echo esc_html( $label ); ?></span>
				<?php if ( $r['total'] > 1 ) : ?>
					<span class="oshield-muted"><?php echo esc_html( sprintf( /* translators: %d orders */ _n( '%d order', '%d orders', $r['total'], 'order-shield' ), $r['total'] ) ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
			<div class="oshield-mini-actions">
				<?php if ( $blocked ) : ?>
					<span class="oshield-pill oshield-pill-blocked"><?php esc_html_e( 'Blocked', 'order-shield' ); ?></span>
				<?php endif; ?>
				<?php if ( 'api' === $r['used'] ) : ?>
					<span class="oshield-muted" title="<?php esc_attr_e( 'From courier API', 'order-shield' ); ?>">API</span>
				<?php endif; ?>
				<a href="#" class="oshield-details" data-order="<?php echo esc_attr( $order ? $order->get_id() : 0 ); ?>" data-phone="<?php echo esc_attr( $r['phone'] ); ?>"><?php esc_html_e( 'Details', 'order-shield' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Summary numbers block.
	 *
	 * @param array  $d     Counts.
	 * @param string $title Title.
	 */
	private static function stats( $d, $title ) {
		$risk                  = OShield_Fraud::risk( $d );
		list( $label, $class ) = self::risk_label( $risk );
		?>
		<div class="oshield-stats-head">
			<h3><?php echo esc_html( $title ); ?></h3>
			<span class="oshield-pill oshield-pill-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
		</div>
		<div class="oshield-stats">
			<div><span><?php esc_html_e( 'Total', 'order-shield' ); ?></span><strong><?php echo esc_html( $d['total'] ); ?></strong></div>
			<div class="oshield-ok"><span><?php esc_html_e( 'Delivered', 'order-shield' ); ?></span><strong><?php echo esc_html( $d['success'] ); ?></strong></div>
			<div class="oshield-bad"><span><?php esc_html_e( 'Cancelled', 'order-shield' ); ?></span><strong><?php echo esc_html( $d['cancel'] ); ?></strong></div>
			<div><span><?php esc_html_e( 'Success ratio', 'order-shield' ); ?></span><strong><?php echo null === $d['ratio'] ? '—' : esc_html( $d['ratio'] . '%' ); ?></strong></div>
		</div>
		<?php if ( null !== $d['ratio'] ) : ?>
			<div class="oshield-bar oshield-bar-lg oshield-risk-<?php echo esc_attr( $class ); ?>"><span style="width:<?php echo esc_attr( $d['ratio'] ); ?>%"></span></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Block / unblock buttons.
	 *
	 * @param array $who type => value.
	 */
	public static function block_buttons( $who ) {
		$labels = OShield_Blocklist::types();
		$status = OShield_Blocklist::status( $who );
		echo '<p class="oshield-block-buttons">';
		foreach ( $who as $type => $value ) {
			if ( '' === (string) $value || 'hardware' === $type ) {
				continue;
			}
			$blocked = ! empty( $status[ $type ] );
			$extra = '';
			if ( 'device' === $type && ! empty( $who['hardware'] ) ) {
				$extra = ' data-hwid="' . esc_attr( $who['hardware'] ) . '" data-ip="' . esc_attr( isset( $who['ip'] ) ? $who['ip'] : '' ) . '"';
			}
			printf(
				'<button type="button" class="button button-small oshield-block-toggle %1$s" data-type="%2$s" data-value="%3$s" data-blocked="%4$s" data-label-block="%5$s" data-label-unblock="%6$s"' . $extra . '>%7$s</button> ', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$blocked ? 'oshield-is-blocked' : '',
				esc_attr( $type ),
				esc_attr( $value ),
				$blocked ? '1' : '0',
				/* translators: %s type */
				esc_attr( sprintf( __( 'Block %s', 'order-shield' ), $labels[ $type ] ) ),
				/* translators: %s type */
				esc_attr( sprintf( __( 'Unblock %s', 'order-shield' ), $labels[ $type ] ) ),
				/* translators: %s type */
				esc_html( $blocked ? sprintf( __( 'Unblock %s', 'order-shield' ), $labels[ $type ] ) : sprintf( __( 'Block %s', 'order-shield' ), $labels[ $type ] ) )
			);
		}
		echo '</p>';
	}

	/**
	 * Full report (popup and Fraud Check page).
	 *
	 * @param array         $r     Report.
	 * @param WC_Order|null $order Order.
	 * @return string
	 */
	public static function details( $r, $order = null ) {
		ob_start();
		if ( ! $r['valid'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This is not a valid Bangladeshi mobile number.', 'order-shield' ) . '</p></div>';
			return ob_get_clean();
		}
		$wa = 'https://wa.me/88' . $r['phone'];
		?>
		<div class="oshield-details-wrap">
			<p class="oshield-phone-line">
				<span class="dashicons dashicons-phone"></span>
				<strong><?php echo esc_html( $r['phone'] ); ?></strong>
				<a class="button button-small" href="tel:<?php echo esc_attr( $r['phone'] ); ?>"><?php esc_html_e( 'Call', 'order-shield' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( $wa ); ?>" target="_blank" rel="noopener">WhatsApp</a>
				<a href="#" class="oshield-refresh-report" data-order="<?php echo esc_attr( $order ? $order->get_id() : 0 ); ?>" data-phone="<?php echo esc_attr( $r['phone'] ); ?>"><?php esc_html_e( 'Refresh', 'order-shield' ); ?></a>
			</p>

			<?php if ( $r['api'] ) : ?>
				<div class="oshield-section">
					<?php self::stats( $r['api'], __( 'All couriers (API)', 'order-shield' ) ); ?>
					<?php if ( ! empty( $r['api']['couriers'] ) ) : ?>
						<table class="widefat striped oshield-table">
							<thead><tr><th><?php esc_html_e( 'Courier', 'order-shield' ); ?></th><th><?php esc_html_e( 'Total', 'order-shield' ); ?></th><th><?php esc_html_e( 'Delivered', 'order-shield' ); ?></th><th><?php esc_html_e( 'Cancelled', 'order-shield' ); ?></th><th><?php esc_html_e( 'Ratio', 'order-shield' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $r['api']['couriers'] as $c ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $c['name'] ); ?></strong></td>
									<td><?php echo esc_html( $c['total'] ); ?></td>
									<td><?php echo esc_html( $c['success'] ); ?></td>
									<td><?php echo esc_html( $c['cancel'] ); ?></td>
									<td><?php echo null === $c['ratio'] ? '—' : esc_html( $c['ratio'] . '%' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<?php if ( ! empty( $r['api']['cached'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Cached result. Click Refresh for live data.', 'order-shield' ); ?></p>
					<?php endif; ?>
				</div>
			<?php elseif ( $r['api_error'] ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %s error */ __( 'Courier API: %s', 'order-shield' ), $r['api_error'] ) ); ?></p></div>
			<?php endif; ?>

			<?php if ( $r['local'] ) : ?>
				<div class="oshield-section">
					<?php self::stats( $r['local'], __( 'Your store', 'order-shield' ) ); ?>
					<?php if ( ! empty( $r['local']['orders'] ) ) : ?>
						<table class="widefat striped oshield-table">
							<thead><tr><th><?php esc_html_e( 'Order', 'order-shield' ); ?></th><th><?php esc_html_e( 'Date', 'order-shield' ); ?></th><th><?php esc_html_e( 'Status', 'order-shield' ); ?></th><th><?php esc_html_e( 'Total', 'order-shield' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $r['local']['orders'] as $o ) : ?>
								<tr<?php echo ( $order && (int) $o['id'] === (int) $order->get_id() ) ? ' class="oshield-current"' : ''; ?>>
									<td><a href="<?php echo esc_url( $o['url'] ); ?>">#<?php echo esc_html( $o['number'] ); ?></a></td>
									<td><?php echo esc_html( $o['date'] ); ?></td>
									<td><span class="oshield-pill oshield-pill-<?php echo esc_attr( self::status_class( $o['status'] ) ); ?>"><?php echo esc_html( $o['label'] ); ?></span></td>
									<td><?php echo esc_html( $o['total'] ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s amount */ __( 'Total delivered value: %s', 'order-shield' ), $r['local']['spent'] ) ); ?></p>
					<?php else : ?>
						<p class="oshield-muted"><?php esc_html_e( 'No orders from this number yet.', 'order-shield' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			if ( $order ) {
				$same = array_diff( oshield_get_order_ids_by_meta( '_oshield_device_id', (string) $order->get_meta( '_oshield_device_id' ) ), array( $order->get_id() ) );
				if ( $same ) {
					echo '<div class="oshield-section"><h3>' . esc_html__( 'Other orders from the same device', 'order-shield' ) . '</h3><p>';
					foreach ( array_slice( $same, 0, 15 ) as $sid ) {
						$so = wc_get_order( $sid );
						if ( $so ) {
							echo '<a href="' . esc_url( $so->get_edit_order_url() ) . '">#' . esc_html( $so->get_order_number() ) . '</a> (' . esc_html( $so->get_billing_phone() ) . ', ' . esc_html( wc_get_order_status_name( $so->get_status() ) ) . ') &nbsp; ';
						}
					}
					echo '</p></div>';
				}
			}
			$who = array( 'phone' => $r['phone'] );
			if ( $order ) {
				$who['ip']     = (string) $order->get_meta( '_oshield_ip' );
				$who['device'] = (string) $order->get_meta( '_oshield_device_id' );
				$who['hardware'] = (string) $order->get_meta( '_oshield_hwid' );
				$who['email']  = (string) $order->get_billing_email();
			}
			self::block_buttons( $who );
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Courier cell for the orders list.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function courier_cell( $order ) {
		if ( ! $order ) {
			return '';
		}
		$ship = OShield_Courier::get_shipment( $order->get_id() );
		ob_start();
		echo '<div class="oshield-courier" data-order="' . esc_attr( $order->get_id() ) . '">';
		if ( $ship ) {
			$url = (string) $order->get_meta( '_oshield_tracking_url' );
			echo '<strong>' . esc_html( ucfirst( $ship->courier ) ) . '</strong><br>';
			echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $ship->consignment_id ) . '</a>' : esc_html( $ship->consignment_id );
			echo '<br><span class="oshield-pill">' . esc_html( str_replace( '_', ' ', $ship->status ) ) . '</span> ';
			echo '<a href="#" class="oshield-refresh-courier dashicons dashicons-update" title="' . esc_attr__( 'Refresh status', 'order-shield' ) . '"></a>';
		} else {
			foreach ( OShield_Courier::enabled() as $id => $label ) {
				echo '<button type="button" class="button button-small oshield-send-courier" data-courier="' . esc_attr( $id ) . '" data-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</button> ';
			}
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Edit-order meta box.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function meta_box( $order ) {
		$phone = $order->get_billing_phone();
		?>
		<div class="oshield-metabox">
			<div class="oshield-report" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-phone="<?php echo esc_attr( $phone ); ?>" data-autoload="1">
				<span class="spinner is-active"></span>
			</div>

			<ul class="oshield-facts">
				<?php if ( $order->get_meta( '_oshield_ip' ) ) : ?>
					<li><?php esc_html_e( 'IP:', 'order-shield' ); ?> <code><?php echo esc_html( $order->get_meta( '_oshield_ip' ) ); ?></code></li>
				<?php endif; ?>
				<?php if ( $order->get_meta( '_oshield_device_id' ) ) : ?>
					<li><?php esc_html_e( 'Device:', 'order-shield' ); ?> <code title="<?php echo esc_attr( $order->get_meta( '_oshield_ua' ) ); ?>"><?php echo esc_html( substr( $order->get_meta( '_oshield_device_id' ), 0, 13 ) ); ?>…</code></li>
				<?php endif; ?>
				<?php if ( $order->get_meta( '_oshield_from_incomplete' ) ) : ?>
					<li><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Recovered from an incomplete order', 'order-shield' ); ?></li>
				<?php endif; ?>
			</ul>

			<?php if ( OShield_Courier::enabled() ) : ?>
				<hr>
				<h4><?php esc_html_e( 'Courier', 'order-shield' ); ?></h4>
				<?php
				$ship = OShield_Courier::get_shipment( $order->get_id() );
				echo OShield_Report_View::courier_cell( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( $ship ) {
					$events = json_decode( (string) $ship->events, true );
					if ( $events ) {
						echo '<ol class="oshield-timeline">';
						foreach ( array_reverse( $events ) as $e ) {
							echo '<li><strong>' . esc_html( str_replace( '_', ' ', $e['status'] ) ) . '</strong> <span class="oshield-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e['time'] ) ) . '</span>' . ( ! empty( $e['message'] ) ? '<br>' . esc_html( $e['message'] ) : '' ) . '</li>';
						}
						echo '</ol>';
					}
				} else {
					echo '<p><textarea class="widefat oshield-courier-note" rows="2" placeholder="' . esc_attr__( 'Note for the courier (optional)', 'order-shield' ) . '"></textarea></p>';
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}
}
