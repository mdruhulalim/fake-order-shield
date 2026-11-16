<?php
/**
 * Admin page renderers. Uses WordPress core markup (wrap, nav-tab, form-table, postbox,
 * WP_List_Table) so the plugin looks like the rest of the dashboard.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Admin_Pages {

	/**
	 * List table instance for the current screen.
	 *
	 * @var WP_List_Table|null
	 */
	private static $table = null;

	/**
	 * Page header.
	 *
	 * @param string $title  Title.
	 * @param string $action Optional "page title action" HTML.
	 */
	private static function header( $title, $action = '' ) {
		echo '<div class="wrap oshield-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( $action ) {
			echo wp_kses_post( $action );
		}
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Admin notice helper.
	 *
	 * @param string $msg  Message.
	 * @param string $type success|error|warning|info.
	 */
	private static function notice( $msg, $type = 'success' ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
	}

	/**
	 * Screen option for rows per page.
	 */
	private static function per_page_option() {
		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'oshield_per_page',
			)
		);
		add_filter(
			'set_screen_option_oshield_per_page',
			function ( $status, $option, $value ) {
				return (int) $value;
			},
			10,
			3
		);
	}

	/**
	 * Read bulk / row action ids.
	 *
	 * @param string $nonce Nonce action.
	 * @return array [action, ids]
	 */
	private static function bulk_request( $nonce ) {
		$action = '';
		foreach ( array( 'action', 'action2' ) as $k ) {
			if ( ! empty( $_REQUEST[ $k ] ) && '-1' !== $_REQUEST[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$action = sanitize_key( $_REQUEST[ $k ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;
			}
		}
		$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) $_REQUEST['ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $action && $ids ) {
			check_admin_referer( $nonce );
			return array( $action, $ids );
		}
		return array( '', array() );
	}

	/**
	 * Redirect after an action, dropping action params.
	 *
	 * @param array $args Query args to add.
	 */
	private static function redirect_back( $args ) {
		$url = remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer', 'done', 'failed', 'msg' ), wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . sanitize_key( $_GET['page'] ) ) ); // phpcs:ignore
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard page.
	 */
	public static function dashboard() {
		global $wpdb;
		$today    = current_time( 'Y-m-d' ) . ' 00:00:00';
		$month    = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$stopped  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}oshield_events WHERE created_at >= %s AND type IN ('blocked','repeat','smart_block','vpn')", $today ) ); // phpcs:ignore
		$stopped30= (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}oshield_events WHERE created_at >= %s AND type IN ('blocked','repeat','smart_block','vpn')", $month ) ); // phpcs:ignore
		$inc      = OShield_Incomplete::counts();
		$shipped  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}oshield_shipments WHERE created_at >= %s", $month ) ); // phpcs:ignore

		self::header( __( 'Order Shield', 'order-shield' ) );
		?>
		<p class="oshield-intro"><?php esc_html_e( 'Stops fake and risky orders before they cost you delivery charges. Here is what is switched on and what happened recently.', 'order-shield' ); ?></p>

		<div class="oshield-cards">
			<?php
			if ( oshield_on( 'live_enabled' ) ) {
				$live = OShield_Live::stats();
				self::card( __( 'Online now', 'order-shield' ), $live['online'], sprintf( /* translators: %d views */ __( '%d page views today', 'order-shield' ), $live['views_today'] ), 'order-shield-live', 'dashicons-groups' );
			}
			self::card( __( 'Stopped today', 'order-shield' ), $stopped, sprintf( /* translators: %d count */ __( '%d in the last 30 days', 'order-shield' ), $stopped30 ), 'order-shield-activity', 'dashicons-shield-alt' );
			self::card( __( 'Blocked customers', 'order-shield' ), OShield_Blocklist::count_active(), __( 'phones, IPs, devices, emails', 'order-shield' ), 'order-shield-blocklist', 'dashicons-dismiss' );
			self::card( __( 'Incomplete orders', 'order-shield' ), $inc['pending'], sprintf( /* translators: %d count */ __( '%d ordered later or converted', 'order-shield' ), $inc['recovered'] + $inc['converted'] ), 'order-shield-incomplete', 'dashicons-cart' );
			self::card( __( 'Sent to courier (30 days)', 'order-shield' ), $shipped, implode( ', ', OShield_Courier::enabled() ) ? implode( ', ', OShield_Courier::enabled() ) : __( 'no courier connected', 'order-shield' ), '', 'dashicons-car' );
			?>
		</div>

		<div class="oshield-columns">
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Protection status', 'order-shield' ); ?></h2></div>
				<div class="inside">
					<table class="widefat striped oshield-status-table">
						<tbody>
						<?php
						$modules = array(
							array( __( 'Phone number validation', 'order-shield' ), oshield_on( 'phone_validation' ), 'checkout' ),
							array( __( 'Block list at checkout', 'order-shield' ), oshield_on( 'blocklist_enabled' ), 'checkout' ),
							array( __( 'Auto block', 'order-shield' ), oshield_on( 'auto_block' ), 'checkout' ),
							array( __( 'Repeat order blocker', 'order-shield' ), oshield_on( 'repeat_block' ), 'checkout' ),
							array( __( 'Smart order filter', 'order-shield' ), oshield_on( 'smart_filter' ), 'fraud' ),
							array( __( 'VPN / country block', 'order-shield' ), OShield_VPN::active(), 'vpn' ),
							array( __( 'Incomplete orders', 'order-shield' ), oshield_on( 'incomplete_enabled' ), 'incomplete' ),
							array( __( 'Live visitors', 'order-shield' ), oshield_on( 'live_enabled' ), 'live' ),
							array( __( 'Session replay', 'order-shield' ), oshield_on( 'replay_enabled' ), 'live' ),
							array( __( 'Steadfast', 'order-shield' ), oshield_on( 'steadfast_enabled' ), 'courier' ),
							array( __( 'Pathao', 'order-shield' ), oshield_on( 'pathao_enabled' ), 'courier' ),
						);
						foreach ( $modules as $m ) {
							echo '<tr><td>' . esc_html( $m[0] ) . '</td><td>';
							echo $m[1] ? '<span class="oshield-pill oshield-pill-good">' . esc_html__( 'On', 'order-shield' ) . '</span>' : '<span class="oshield-pill">' . esc_html__( 'Off', 'order-shield' ) . '</span>';
							echo '</td><td class="oshield-right"><a href="' . esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=' . $m[2] ) ) . '">' . esc_html__( 'Configure', 'order-shield' ) . '</a></td></tr>';
						}
						?>
						</tbody>
					</table>
				</div>
			</div>

			<div>
				<div class="postbox">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Quick fraud check', 'order-shield' ); ?></h2></div>
					<div class="inside">
						<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="oshield-inline-form">
							<input type="hidden" name="page" value="order-shield-search">
							<input type="tel" name="phone" class="regular-text" placeholder="01XXXXXXXXX" required>
							<?php submit_button( __( 'Check', 'order-shield' ), 'primary', '', false ); ?>
						</form>
					</div>
				</div>

				<div class="postbox">
					<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Recently stopped', 'order-shield' ); ?></h2></div>
					<div class="inside">
						<?php
						$events = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}oshield_events ORDER BY id DESC LIMIT 8" ); // phpcs:ignore
						$labels = OShield_Events_Table::type_labels();
						if ( $events ) {
							echo '<ul class="oshield-activity">';
							foreach ( $events as $e ) {
								echo '<li><strong>' . esc_html( isset( $labels[ $e->type ] ) ? $labels[ $e->type ] : $e->type ) . '</strong> · ' . esc_html( $e->phone ? $e->phone : $e->ip ) . '<br><span class="oshield-muted">' . esc_html( $e->reason ) . ' — ' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $e->created_at ) ) . '</span></li>';
							}
							echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=order-shield-activity' ) ) . '">' . esc_html__( 'View all activity', 'order-shield' ) . ' →</a></p>';
						} else {
							echo '<p class="oshield-muted">' . esc_html__( 'Nothing yet.', 'order-shield' ) . '</p>';
						}
						?>
					</div>
				</div>
			</div>
		</div>
		</div>
		<?php
	}

	/**
	 * Dashboard stat card.
	 *
	 * @param string $label Label.
	 * @param int    $value Number.
	 * @param string $sub   Sub text.
	 * @param string $page  Linked page slug.
	 * @param string $icon  Dashicon.
	 */
	private static function card( $label, $value, $sub, $page, $icon ) {
		$tag = $page ? 'a' : 'div';
		$href = $page ? ' href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"' : '';
		echo '<' . $tag . ' class="oshield-card"' . $href . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
		echo '<span class="oshield-card-label">' . esc_html( $label ) . '</span>';
		echo '<span class="oshield-card-value">' . esc_html( number_format_i18n( $value ) ) . '</span>';
		echo '<span class="oshield-card-sub">' . esc_html( $sub ) . '</span>';
		echo '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ------------------------------------------------------------------ *
	 * Live visitors
	 * ------------------------------------------------------------------ */

	/**
	 * Live visitors page. Numbers and the list refresh by themselves.
	 */
	public static function live() {
		self::header( __( 'Live Visitors', 'order-shield' ), '<span class="oshield-live-badge"><span class="oshield-live-dot"></span> ' . esc_html__( 'Live', 'order-shield' ) . ' · <span class="oshield-live-time"></span></span>' );

		if ( ! oshield_on( 'live_enabled' ) ) {
			self::notice( sprintf( /* translators: %s link */ __( 'Visitor tracking is off. %s', 'order-shield' ), '<a href="' . esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=live' ) ) . '">' . esc_html__( 'Turn it on', 'order-shield' ) . '</a>' ), 'warning' );
		}
		$stats = OShield_Live::stats();
		?>
		<div class="oshield-cards oshield-live-cards">
			<div class="oshield-card"><span class="dashicons dashicons-groups"></span><span class="oshield-card-label"><?php esc_html_e( 'Online now', 'order-shield' ); ?></span><span class="oshield-card-value" data-stat="online"><?php echo esc_html( $stats['online'] ); ?></span><span class="oshield-card-sub"><span data-stat="online_mobile"><?php echo esc_html( $stats['online_mobile'] ); ?></span> <?php esc_html_e( 'on mobile', 'order-shield' ); ?></span></div>
			<div class="oshield-card"><span class="dashicons dashicons-cart"></span><span class="oshield-card-label"><?php esc_html_e( 'With items in cart', 'order-shield' ); ?></span><span class="oshield-card-value" data-stat="online_cart"><?php echo esc_html( $stats['online_cart'] ); ?></span><span class="oshield-card-sub"><?php esc_html_e( 'online right now', 'order-shield' ); ?></span></div>
			<div class="oshield-card"><span class="dashicons dashicons-visibility"></span><span class="oshield-card-label"><?php esc_html_e( 'Page views (30 min)', 'order-shield' ); ?></span><span class="oshield-card-value" data-stat="views_30"><?php echo esc_html( $stats['views_30'] ); ?></span><span class="oshield-card-sub"><span data-stat="views_today"><?php echo esc_html( $stats['views_today'] ); ?></span> <?php esc_html_e( 'today', 'order-shield' ); ?></span></div>
			<div class="oshield-card"><span class="dashicons dashicons-chart-bar"></span><span class="oshield-card-label"><?php esc_html_e( 'Visitors today', 'order-shield' ); ?></span><span class="oshield-card-value" data-stat="visitors_today"><?php echo esc_html( $stats['visitors_today'] ); ?></span><span class="oshield-card-sub"><span data-stat="orders_today"><?php echo esc_html( $stats['orders_today'] ); ?></span> <?php esc_html_e( 'placed an order', 'order-shield' ); ?></span></div>
		</div>

		<div class="oshield-live-layout">
			<div class="oshield-live-main">
				<ul class="subsubsub oshield-live-views">
					<li><a href="#" data-view="online" class="current"><?php esc_html_e( 'Online now', 'order-shield' ); ?></a> |</li>
					<li><a href="#" data-view="today"><?php esc_html_e( 'Today', 'order-shield' ); ?></a> |</li>
					<li><a href="#" data-view="all"><?php esc_html_e( 'Recent (all)', 'order-shield' ); ?></a></li>
				</ul>
				<p class="search-box">
					<label class="screen-reader-text" for="oshield-live-search"><?php esc_html_e( 'Search visitors', 'order-shield' ); ?></label>
					<input type="search" id="oshield-live-search" placeholder="<?php esc_attr_e( 'IP, page, phone…', 'order-shield' ); ?>">
				</p>
				<table class="wp-list-table widefat fixed striped oshield-live-table">
					<thead>
						<tr>
							<th class="column-primary"><?php esc_html_e( 'Visitor', 'order-shield' ); ?></th>
							<th><?php esc_html_e( 'Now viewing', 'order-shield' ); ?></th>
							<th class="oshield-col-small"><?php esc_html_e( 'Pages', 'order-shield' ); ?></th>
							<th><?php esc_html_e( 'Came from', 'order-shield' ); ?></th>
							<th><?php esc_html_e( 'Cart', 'order-shield' ); ?></th>
							<th><?php esc_html_e( 'Time on site', 'order-shield' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'order-shield' ); ?></th>
						</tr>
					</thead>
					<tbody id="oshield-live-rows">
						<?php echo self::live_rows_html( OShield_Live::visitors( 'online' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</tbody>
				</table>
			</div>
			<div class="postbox oshield-live-side">
				<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Pages open right now', 'order-shield' ); ?></h2></div>
				<div class="inside" id="oshield-live-pages"><?php echo self::live_pages_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
		</div>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %d seconds */ __( 'Updates every 10 seconds. A visitor counts as online if they were active in the last %d seconds.', 'order-shield' ), OShield_Live::ONLINE_SECONDS ) ); ?></p>

		<div id="oshield-replay-modal" class="oshield-modal" hidden><div class="oshield-modal-box oshield-modal-wide"><button type="button" class="oshield-modal-close" aria-label="<?php esc_attr_e( 'Close', 'order-shield' ); ?>"><span class="dashicons dashicons-no-alt"></span></button><h2><?php esc_html_e( 'Session replay', 'order-shield' ); ?></h2><div class="oshield-replay-target"></div></div></div>
		<div id="oshield-live-modal" class="oshield-modal" hidden><div class="oshield-modal-box oshield-modal-wide"><button type="button" class="oshield-modal-close" aria-label="<?php esc_attr_e( 'Close', 'order-shield' ); ?>"><span class="dashicons dashicons-no-alt"></span></button><h2><?php esc_html_e( 'Watching live', 'order-shield' ); ?> <span class="oshield-live-state"></span></h2><p class="oshield-live-page oshield-muted"></p><div class="oshield-live-target"></div></div></div>
		</div>
		<?php
	}

	/**
	 * Path part of a URL for display.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function short_url( $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$out   = $path ? $path : '/';
		return $query ? $out . '?' . $query : $out;
	}

	/**
	 * "Pages open right now" list.
	 *
	 * @return string
	 */
	public static function live_pages_html() {
		$pages = OShield_Live::top_pages();
		if ( ! $pages ) {
			return '<p class="oshield-muted">' . esc_html__( 'Nobody online right now.', 'order-shield' ) . '</p>';
		}
		$max = max( 1, (int) $pages[0]->c );
		$out = '<ul class="oshield-top-pages">';
		foreach ( $pages as $p ) {
			$label = $p->title ? $p->title : self::short_url( $p->url );
			$out  .= '<li><div class="oshield-top-line"><a href="' . esc_url( $p->url ) . '" target="_blank" rel="noopener" title="' . esc_attr( self::short_url( $p->url ) ) . '">' . esc_html( wp_html_excerpt( $label, 48, '…' ) ) . '</a><strong>' . esc_html( $p->c ) . '</strong></div>';
			$out  .= '<div class="oshield-bar"><span style="width:' . esc_attr( round( $p->c / $max * 100 ) ) . '%"></span></div></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Table rows for the live list.
	 *
	 * @param array $rows Visitor rows.
	 * @return string
	 */
	public static function live_rows_html( $rows ) {
		if ( ! $rows ) {
			return '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__( 'No visitors here yet. Open your website in another browser to see yourself appear.', 'order-shield' ) . '</td></tr>';
		}
		$now    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$online = strtotime( OShield_Live::online_since() );
		$icons  = array(
			'mobile'  => 'dashicons-smartphone',
			'tablet'  => 'dashicons-tablet',
			'desktop' => 'dashicons-desktop',
		);
		$out = '';
		foreach ( $rows as $r ) {
			$last      = strtotime( $r->last_seen );
			$is_online = $last >= $online;
			$secs      = max( 0, $last - strtotime( $r->first_seen ) );
			$duration  = $secs < 60 ? sprintf( /* translators: %d seconds */ __( '%ds', 'order-shield' ), $secs ) : human_time_diff( strtotime( $r->first_seen ), $last );

			// Visitor.
			$who  = '<span class="oshield-presence ' . ( $is_online ? 'is-online' : '' ) . '" title="' . esc_attr( $is_online ? __( 'Online', 'order-shield' ) : __( 'Offline', 'order-shield' ) ) . '"></span>';
			$who .= '<span class="dashicons ' . esc_attr( isset( $icons[ $r->device ] ) ? $icons[ $r->device ] : 'dashicons-desktop' ) . '"></span> ';
			if ( $r->phone ) {
				$who .= '<strong>' . esc_html( $r->name ? $r->name : $r->phone ) . '</strong> <a href="tel:' . esc_attr( $r->phone ) . '">' . esc_html( $r->phone ) . '</a><br>';
			} elseif ( $r->user_id && ( $u = get_userdata( $r->user_id ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found
				$who .= '<strong>' . esc_html( $u->display_name ) . '</strong><br>';
			} else {
				$who .= '<strong>' . esc_html__( 'Guest', 'order-shield' ) . '</strong><br>';
			}
			$who .= '<span class="oshield-muted">' . esc_html( trim( $r->browser . ' · ' . $r->os, ' ·' ) ) . '<br>' . esc_html( $r->ip ) . ( $r->country ? ' · ' . esc_html( $r->country ) : '' ) . '</span>';

			// Page.
			$page  = '<a href="' . esc_url( $r->current_url ) . '" target="_blank" rel="noopener">' . esc_html( wp_html_excerpt( $r->current_title ? $r->current_title : self::short_url( $r->current_url ), 60, '…' ) ) . '</a>';
			$page .= '<br><span class="oshield-muted">' . esc_html( wp_html_excerpt( self::short_url( $r->current_url ), 60, '…' ) ) . '</span>';

			// Source.
			$host   = $r->referrer ? (string) wp_parse_url( $r->referrer, PHP_URL_HOST ) : '';
			$source = $host ? '<span title="' . esc_attr( $r->referrer ) . '">' . esc_html( preg_replace( '/^www\./', '', $host ) ) . '</span>' : '<span class="oshield-muted">' . esc_html__( 'Direct', 'order-shield' ) . '</span>';
			if ( 'Instagram' === $r->browser || false !== stripos( $host, 'instagram' ) ) {
				$source .= ' <span class="oshield-pill oshield-pill-new">Instagram</span>';
			} elseif ( false !== strpos( $r->landing_url, 'fbclid=' ) || false !== stripos( $host, 'facebook' ) || 'Facebook' === $r->browser ) {
				$source .= ' <span class="oshield-pill oshield-pill-new">Facebook</span>';
			}

			// Cart / order.
			if ( $r->order_id && function_exists( 'wc_get_order' ) && ( $o = wc_get_order( $r->order_id ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found
				$cart = '<span class="oshield-pill oshield-pill-good">' . esc_html__( 'Ordered', 'order-shield' ) . '</span> <a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>';
			} elseif ( $r->cart_count > 0 ) {
				$cart = esc_html( sprintf( /* translators: %d items */ _n( '%d item', '%d items', $r->cart_count, 'order-shield' ), $r->cart_count ) ) . '<br><strong>' . wp_kses_post( function_exists( 'wc_price' ) ? wc_price( $r->cart_total ) : $r->cart_total ) . '</strong>';
				if ( $r->incomplete_id ) {
					$cart .= '<br><a href="' . esc_url( admin_url( 'admin.php?page=order-shield-incomplete&status=all&s=' . rawurlencode( $r->phone ) ) ) . '">' . esc_html__( 'At checkout', 'order-shield' ) . '</a>';
				}
			} else {
				$cart = '<span class="oshield-muted">—</span>';
			}

			// Time.
			$time = esc_html( $duration ) . '<br><span class="oshield-muted">' . ( $is_online ? esc_html__( 'active now', 'order-shield' ) : esc_html( sprintf( /* translators: %s time */ __( '%s ago', 'order-shield' ), human_time_diff( $last, $now ) ) ) ) . '</span>';

			// Actions.
			$act = '';
			if ( $is_online && oshield_on( 'replay_enabled' ) ) {
				$act .= '<button type="button" class="button button-small oshield-watch-live" data-sid="' . esc_attr( $r->session_id ) . '"><span class="oshield-live-dot"></span> ' . esc_html__( 'Live', 'order-shield' ) . '</button> ';
			}
			if ( $r->has_recording ) {
				$act .= '<button type="button" class="button button-small oshield-replay" data-sid="' . esc_attr( $r->session_id ) . '"><span class="dashicons dashicons-controls-play"></span> ' . esc_html__( 'Watch', 'order-shield' ) . '</button> ';
			}
			$act .= '<button type="button" class="button button-small oshield-journey" data-sid="' . esc_attr( $r->session_id ) . '">' . esc_html__( 'Pages', 'order-shield' ) . '</button>';
			$act .= '<div class="row-actions visible">';
			$act .= '<a href="#" class="oshield-block-link" data-type="ip" data-value="' . esc_attr( $r->ip ) . '">' . esc_html__( 'Block IP', 'order-shield' ) . '</a>';
			if ( $r->device_id ) {
				$act .= ' | <a href="#" class="oshield-block-link" data-type="device" data-value="' . esc_attr( $r->device_id ) . '" data-hwid="' . esc_attr( $r->hwid ) . '" data-ip="' . esc_attr( $r->ip ) . '">' . esc_html__( 'Block device', 'order-shield' ) . '</a>';
			}
			$act .= '</div>';

			$out .= '<tr data-sid="' . esc_attr( $r->session_id ) . '"><td class="column-primary">' . $who . '</td><td>' . $page . '</td><td class="oshield-col-small">' . esc_html( $r->pages ) . '</td><td>' . $source . '</td><td>' . $cart . '</td><td>' . $time . '</td><td>' . $act . '</td></tr>';
		}
		return $out;
	}

	/**
	 * Journey (pages visited) popup content.
	 *
	 * @param string $sid Session id.
	 * @return string
	 */
	public static function journey_html( $sid ) {
		$v     = OShield_Live::get( $sid );
		$pages = OShield_Live::journey( $sid );
		$out   = '<h2>' . esc_html__( 'Pages visited', 'order-shield' ) . '</h2>';
		if ( $v ) {
			$out .= '<p class="oshield-muted">' . esc_html( trim( $v->browser . ' · ' . $v->os . ' · ' . $v->ip, ' ·' ) ) . ( $v->referrer ? ' · ' . esc_html__( 'from', 'order-shield' ) . ' ' . esc_html( $v->referrer ) : '' ) . '</p>';
		}
		if ( ! $pages ) {
			return $out . '<p>' . esc_html__( 'No pages recorded.', 'order-shield' ) . '</p>';
		}
		$out .= '<ol class="oshield-journey">';
		foreach ( $pages as $p ) {
			$out .= '<li><span class="oshield-muted">' . esc_html( mysql2date( get_option( 'time_format' ), $p->created_at ) ) . '</span> <a href="' . esc_url( $p->url ) . '" target="_blank" rel="noopener">' . esc_html( $p->title ? $p->title : self::short_url( $p->url ) ) . '</a><br><code>' . esc_html( self::short_url( $p->url ) ) . '</code></li>';
		}
		return $out . '</ol>';
	}

	/* ------------------------------------------------------------------ *
	 * Fraud check (phone search)
	 * ------------------------------------------------------------------ */

	/**
	 * Phone search page.
	 */
	public static function search() {
		$phone = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::header( __( 'Fraud Check', 'order-shield' ) );
		?>
		<p><?php esc_html_e( 'Check any customer\'s delivery record by phone number before you confirm or ship an order.', 'order-shield' ); ?></p>
		<form method="get" class="oshield-inline-form oshield-search-form">
			<input type="hidden" name="page" value="order-shield-search">
			<label class="screen-reader-text" for="oshield-phone"><?php esc_html_e( 'Phone number', 'order-shield' ); ?></label>
			<input type="tel" id="oshield-phone" name="phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" placeholder="01XXXXXXXXX" required autofocus>
			<?php submit_button( __( 'Check', 'order-shield' ), 'primary', '', false ); ?>
		</form>
		<?php
		if ( '' !== $phone ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				self::notice( __( 'WooCommerce is not active.', 'order-shield' ), 'error' );
			} else {
				$report = OShield_Fraud::report( $phone, ! empty( $_GET['fresh'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<div class="postbox oshield-search-result"><div class="inside">';
				echo OShield_Report_View::details( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				global $wpdb;
				$inc = $report['phone'] ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}oshield_incomplete WHERE phone = %s ORDER BY id DESC LIMIT 5", $report['phone'] ) ) : array(); // phpcs:ignore
				if ( $inc ) {
					echo '<div class="oshield-section"><h3>' . esc_html__( 'Incomplete checkouts', 'order-shield' ) . '</h3><ul>';
					$labels = array(
						'pending'   => __( 'Not ordered', 'order-shield' ),
						'recovered' => __( 'Ordered later', 'order-shield' ),
						'converted' => __( 'Converted', 'order-shield' ),
					);
					foreach ( $inc as $row ) {
						echo '<li>' . esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->updated_at ) ) . ' — ' . wp_kses_post( wc_price( $row->cart_total ) ) . ' — ' . esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ) . '</li>';
					}
					echo '</ul><a href="' . esc_url( admin_url( 'admin.php?page=order-shield-incomplete&status=all&s=' . rawurlencode( $report['phone'] ) ) ) . '">' . esc_html__( 'Open in Incomplete Orders', 'order-shield' ) . '</a></div>';
				}
				echo '</div></div>';
			}
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Incomplete orders
	 * ------------------------------------------------------------------ */

	/**
	 * Before output: screen options, actions, CSV export.
	 */
	public static function load_incomplete() {
		self::per_page_option();

		if ( isset( $_GET['export'] ) && check_admin_referer( 'oshield_export_incomplete' ) ) {
			global $wpdb;
			$rows = $wpdb->get_results( 'SELECT phone, name, email, address, cart_total, status, ip, created_at, updated_at, cart FROM ' . OShield_Incomplete::table() . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=incomplete-orders-' . gmdate( 'Y-m-d' ) . '.csv' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel (Bangla text).
			fputcsv( $out, array( 'Phone', 'Name', 'Email', 'Address', 'Total', 'Status', 'IP', 'Created', 'Last activity', 'Products' ) );
			foreach ( $rows as $r ) {
				$cart  = json_decode( (string) $r['cart'], true );
				$items = array();
				foreach ( isset( $cart['items'] ) ? $cart['items'] : array() as $i ) {
					$items[] = $i['name'] . ' x' . $i['quantity'];
				}
				$r['cart'] = implode( '; ', $items );
				fputcsv( $out, $r );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			exit;
		}

		list( $action, $ids ) = self::bulk_request( 'bulk-incompletes' );
		if ( 'delete' === $action ) {
			OShield_Incomplete::delete( $ids );
			self::redirect_back( array( 'msg' => 'deleted', 'done' => count( $ids ) ) );
		} elseif ( 'convert' === $action ) {
			$ok   = 0;
			$fail = array();
			foreach ( $ids as $id ) {
				$r = OShield_Incomplete::convert( $id );
				is_wp_error( $r ) ? $fail[] = $r->get_error_message() : $ok++;
			}
			self::redirect_back( array( 'msg' => 'converted', 'done' => $ok, 'failed' => rawurlencode( implode( ' | ', array_unique( $fail ) ) ) ) );
		}
		self::$table = new OShield_Incomplete_Table();
	}

	/**
	 * Incomplete orders page.
	 */
	public static function incomplete() {
		$export = wp_nonce_url( admin_url( 'admin.php?page=order-shield-incomplete&export=1' ), 'oshield_export_incomplete' );
		self::header( __( 'Incomplete Orders', 'order-shield' ), '<a href="' . esc_url( $export ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'order-shield' ) . '</a>' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['msg'] ) ) {
			$done = isset( $_GET['done'] ) ? absint( $_GET['done'] ) : 0;
			if ( 'deleted' === $_GET['msg'] ) {
				self::notice( sprintf( /* translators: %d count */ _n( '%d entry deleted.', '%d entries deleted.', $done, 'order-shield' ), $done ) );
			} elseif ( 'converted' === $_GET['msg'] ) {
				if ( $done ) {
					self::notice( sprintf( /* translators: %d count */ _n( '%d order created.', '%d orders created.', $done, 'order-shield' ), $done ) );
				}
				if ( ! empty( $_GET['failed'] ) ) {
					self::notice( esc_html( sanitize_text_field( wp_unslash( $_GET['failed'] ) ) ), 'error' );
				}
			}
		}
		// phpcs:enable

		$t = self::$table;
		$t->prepare_items();
		$t->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="order-shield-incomplete">';
		if ( isset( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( $_GET['status'] ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$t->search_box( __( 'Search', 'order-shield' ), 'oshield-incomplete' );
		$t->display();
		echo '</form>';
		echo '<div id="oshield-replay-modal" class="oshield-modal" hidden><div class="oshield-modal-box oshield-modal-wide"><button type="button" class="oshield-modal-close" aria-label="' . esc_attr__( 'Close', 'order-shield' ) . '"><span class="dashicons dashicons-no-alt"></span></button><h2>' . esc_html__( 'Session replay', 'order-shield' ) . '</h2><div class="oshield-replay-target"></div></div></div>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Block list
	 * ------------------------------------------------------------------ */

	/**
	 * Before output.
	 */
	public static function load_blocklist() {
		self::per_page_option();
		list( $action, $ids ) = self::bulk_request( 'bulk-blocks' );
		if ( $action ) {
			foreach ( $ids as $id ) {
				if ( 'delete' === $action ) {
					OShield_Blocklist::delete( $id );
				} elseif ( 'enable' === $action || 'disable' === $action ) {
					OShield_Blocklist::set_active( $id, 'enable' === $action );
				}
			}
			self::redirect_back( array( 'msg' => $action, 'done' => count( $ids ) ) );
		}
		self::$table = new OShield_Blocks_Table();
	}

	/**
	 * Block list page.
	 */
	public static function blocklist() {
		self::header( __( 'Block List', 'order-shield' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['added'] ) ) {
			$n = absint( $_GET['added'] );
			if ( $n ) {
				self::notice( sprintf( /* translators: %d count */ _n( '%d entry blocked.', '%d entries blocked.', $n, 'order-shield' ), $n ) );
			}
			if ( ! empty( $_GET['invalid'] ) ) {
				self::notice( sprintf( /* translators: %s values */ __( 'Not valid for this type: %s', 'order-shield' ), esc_html( sanitize_text_field( wp_unslash( $_GET['invalid'] ) ) ) ), 'error' );
			}
		}
		if ( isset( $_GET['msg'] ) ) {
			self::notice( __( 'Block list updated.', 'order-shield' ) );
		}
		// phpcs:enable
		?>
		<div class="postbox oshield-add-block">
			<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Block someone', 'order-shield' ); ?></h2></div>
			<div class="inside">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="oshield_block_add">
					<?php wp_nonce_field( 'oshield_block_add' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="oshield-type"><?php esc_html_e( 'Type', 'order-shield' ); ?></label></th>
							<td>
								<select name="type" id="oshield-type">
									<?php foreach ( array_diff_key( OShield_Blocklist::types(), array( 'hardware' => 1 ) ) as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oshield-value"><?php esc_html_e( 'Value', 'order-shield' ); ?></label></th>
							<td>
								<textarea name="value" id="oshield-value" rows="2" class="large-text code" required placeholder="01XXXXXXXXX"></textarea>
								<p class="description"><?php esc_html_e( 'Add several at once, one per line or separated by commas.', 'order-shield' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="oshield-reason"><?php esc_html_e( 'Reason', 'order-shield' ); ?></label></th>
							<td><input type="text" name="reason" id="oshield-reason" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Fake order, refused parcel', 'order-shield' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="oshield-days"><?php esc_html_e( 'Duration', 'order-shield' ); ?></label></th>
							<td>
								<select name="days" id="oshield-days">
									<option value="0"><?php esc_html_e( 'Permanently', 'order-shield' ); ?></option>
									<option value="1"><?php esc_html_e( '1 day', 'order-shield' ); ?></option>
									<option value="7"><?php esc_html_e( '7 days', 'order-shield' ); ?></option>
									<option value="30"><?php esc_html_e( '30 days', 'order-shield' ); ?></option>
									<option value="90"><?php esc_html_e( '90 days', 'order-shield' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Block', 'order-shield' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
		$t = self::$table;
		$t->prepare_items();
		$t->views();
		echo '<form method="get"><input type="hidden" name="page" value="order-shield-blocklist">';
		$t->search_box( __( 'Search', 'order-shield' ), 'oshield-blocks' );
		$t->display();
		echo '</form>';
		echo '<p class="description">' . wp_kses_post( sprintf( /* translators: %s link */ __( 'Import or export the block list in %s.', 'order-shield' ), '<a href="' . esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=tools' ) ) . '">' . esc_html__( 'Settings → Tools', 'order-shield' ) . '</a>' ) ) . '</p>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Activity log
	 * ------------------------------------------------------------------ */

	/**
	 * Before output.
	 */
	public static function load_activity() {
		self::per_page_option();
		if ( isset( $_GET['clear'] ) && check_admin_referer( 'oshield_clear_events' ) ) {
			global $wpdb;
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}oshield_events" ); // phpcs:ignore
			wp_safe_redirect( admin_url( 'admin.php?page=order-shield-activity' ) );
			exit;
		}
		self::$table = new OShield_Events_Table();
	}

	/**
	 * Activity page.
	 */
	public static function activity() {
		$clear = wp_nonce_url( admin_url( 'admin.php?page=order-shield-activity&clear=1' ), 'oshield_clear_events' );
		self::header( __( 'Activity Log', 'order-shield' ), '<a href="' . esc_url( $clear ) . '" class="page-title-action" onclick="return confirm(\'' . esc_js( __( 'Delete the whole activity log?', 'order-shield' ) ) . '\')">' . esc_html__( 'Clear log', 'order-shield' ) . '</a>' );
		echo '<p>' . esc_html__( 'Every checkout attempt that Order Shield stopped or challenged. Entries older than 60 days are removed automatically.', 'order-shield' ) . '</p>';
		$t = self::$table;
		$t->prepare_items();
		$t->views();
		echo '<form method="get"><input type="hidden" name="page" value="order-shield-activity">';
		$t->search_box( __( 'Search', 'order-shield' ), 'oshield-events' );
		$t->display();
		echo '</form></div>';
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings page with tabs.
	 */
	public static function settings() {
		$schema = OShield_Settings::schema();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $schema[ $tab ] ) && 'tools' !== $tab ) {
			$tab = 'general';
		}

		self::header( __( 'Order Shield Settings', 'order-shield' ) );
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::notice( __( 'Settings saved.', 'order-shield' ) );
		}
		if ( isset( $_GET['oshield_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::notice( esc_html( sanitize_text_field( wp_unslash( $_GET['oshield_msg'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Settings sections', 'order-shield' ) . '">';
		foreach ( $schema as $id => $t ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=' . $id ) ), $tab === $id ? ' nav-tab-active' : '', esc_html( $t['label'] ) );
		}
		printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( admin_url( 'admin.php?page=order-shield-settings&tab=tools' ) ), 'tools' === $tab ? ' nav-tab-active' : '', esc_html__( 'Tools', 'order-shield' ) );
		echo '</nav>';

		if ( 'tools' === $tab ) {
			self::tools_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="oshield-settings">';
		echo '<input type="hidden" name="action" value="oshield_save_settings">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'oshield_settings' );

		foreach ( $schema[ $tab ]['sections'] as $section ) {
			echo '<h2 class="title">' . esc_html( $section['title'] ) . '</h2>';
			if ( ! empty( $section['desc'] ) ) {
				echo '<p>' . esc_html( $section['desc'] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $section['fields'] as $field ) {
				self::field_row( $field );
			}
			echo '</tbody></table>';
		}
		submit_button();
		echo '</form></div>';
	}

	/**
	 * One settings row.
	 *
	 * @param array $f Field.
	 */
	private static function field_row( $f ) {
		$id    = $f['id'];
		$name  = 'oshield[' . $id . ']';
		$value = OShield_Settings::get( $id );
		$html  = 'oshield-' . $id;
		$attrs = ! empty( $f['show_if'] ) ? ' data-show-if="' . esc_attr( $f['show_if'] ) . '"' : '';

		echo '<tr' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( 'checkbox' === $f['type'] ) {
			echo '<th scope="row">' . esc_html( $f['label'] ) . '</th><td>';
			echo '<label for="' . esc_attr( $html ) . '"><input type="checkbox" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( '1', (string) $value, false ) . '> ';
			echo ! empty( $f['desc'] ) ? esc_html( $f['desc'] ) : esc_html__( 'Enable', 'order-shield' );
			echo '</label></td></tr>';
			return;
		}

		echo '<th scope="row"><label for="' . esc_attr( $html ) . '">' . esc_html( $f['label'] ) . '</label></th><td>';
		$class = ! empty( $f['class'] ) ? $f['class'] : 'regular-text';

		switch ( $f['type'] ) {
			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text"%4$s%5$s>',
					esc_attr( $html ),
					esc_attr( $name ),
					esc_attr( $value ),
					isset( $f['min'] ) ? ' min="' . esc_attr( $f['min'] ) . '"' : '',
					isset( $f['max'] ) ? ' max="' . esc_attr( $f['max'] ) . '"' : ''
				);
				break;

			case 'select':
				echo '<select id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" data-oshield-key="' . esc_attr( $id ) . '">';
				foreach ( $f['options'] as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'textarea':
				$tclass = ! empty( $f['class'] ) ? $f['class'] : 'large-text';
				echo '<textarea id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" rows="3" class="' . esc_attr( $tclass ) . '">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'password':
				$has = '' !== (string) $value;
				echo '<input type="password" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $has ? __( 'Saved — leave empty to keep', 'order-shield' ) : '' ) . '">';
				break;

			case 'statuses':
				$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
				echo '<fieldset class="oshield-checklist">';
				echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
				foreach ( $statuses as $k => $label ) {
					echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, (array) $value, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
				}
				echo '</fieldset>';
				break;

			case 'apikey':
				echo '<input type="text" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text code" readonly> ';
				echo '<button type="button" class="button oshield-gen-key" data-target="' . esc_attr( $html ) . '">' . esc_html__( 'Generate new key', 'order-shield' ) . '</button> ';
				echo '<button type="button" class="button-link oshield-clear-key" data-target="' . esc_attr( $html ) . '">' . esc_html__( 'Disable', 'order-shield' ) . '</button>';
				echo '<p class="description"><code>' . esc_html( rest_url( OShield_REST::NS . '/orders?phone=01XXXXXXXXX' ) ) . '</code></p>';
				break;

			case 'webhook':
				$courier = $f['courier'];
				echo '<p><strong>' . esc_html__( 'URL:', 'order-shield' ) . '</strong> <input type="text" readonly class="large-text code oshield-copy" value="' . esc_attr( OShield_REST::webhook_url( $courier ) ) . '"></p>';
				echo '<p><strong>' . esc_html( 'steadfast' === $courier ? __( 'Auth token:', 'order-shield' ) : __( 'Webhook secret:', 'order-shield' ) ) . '</strong> ';
				echo '<input type="text" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text code"> ';
				echo '<button type="button" class="button oshield-gen-key" data-target="' . esc_attr( $html ) . '">' . esc_html__( 'Generate', 'order-shield' ) . '</button></p>';
				break;

			default:
				echo '<input type="text" id="' . esc_attr( $html ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class ) . '"' . ( ! empty( $f['placeholder'] ) ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '' ) . '>';
		}

		if ( ! empty( $f['desc'] ) && ! in_array( $f['type'], array( 'apikey' ), true ) ) {
			echo '<p class="description">' . esc_html( $f['desc'] ) . '</p>';
		} elseif ( 'apikey' === $f['type'] && ! empty( $f['desc'] ) ) {
			echo '<p class="description">' . esc_html( $f['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Tools tab.
	 */
	private static function tools_tab() {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$link = function ( $do ) {
			return esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=oshield_tools&do=' . $do ), 'oshield_tools' ) );
		};
		?>
		<h2 class="title"><?php esc_html_e( 'Block list', 'order-shield' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Export', 'order-shield' ); ?></th>
				<td><a class="button" href="<?php echo $link( 'export_blocks' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Download CSV', 'order-shield' ); ?></a></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Import', 'order-shield' ); ?></th>
				<td>
					<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" enctype="multipart/form-data" class="oshield-inline-form">
						<input type="hidden" name="action" value="oshield_tools">
						<input type="hidden" name="do" value="import_blocks">
						<?php wp_nonce_field( 'oshield_tools' ); ?>
						<input type="file" name="file" accept=".csv,text/csv,text/plain" required>
						<?php submit_button( __( 'Import', 'order-shield' ), 'secondary', 'submit', false ); ?>
					</form>
					<p class="description"><?php esc_html_e( 'CSV columns: type, value, reason (type = phone, ip, device or email). A file with one phone number per line also works.', 'order-shield' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Settings backup', 'order-shield' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Export', 'order-shield' ); ?></th>
				<td><a class="button" href="<?php echo $link( 'export_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Download settings', 'order-shield' ); ?></a>
				<p class="description"><?php esc_html_e( 'Includes API keys — keep the file private.', 'order-shield' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Import', 'order-shield' ); ?></th>
				<td>
					<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" enctype="multipart/form-data" class="oshield-inline-form">
						<input type="hidden" name="action" value="oshield_tools">
						<input type="hidden" name="do" value="import_settings">
						<?php wp_nonce_field( 'oshield_tools' ); ?>
						<input type="file" name="file" accept=".json,application/json" required>
						<?php submit_button( __( 'Import', 'order-shield' ), 'secondary', 'submit', false ); ?>
					</form>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Maintenance', 'order-shield' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Database', 'order-shield' ); ?></th>
				<td>
					<ul class="oshield-health">
					<?php
					foreach ( OShield_Install::health() as $table => $ok ) {
						echo '<li><span class="dashicons ' . ( $ok ? 'dashicons-yes-alt oshield-ok' : 'dashicons-warning oshield-bad' ) . '"></span> <code>' . esc_html( $table ) . '</code></li>';
					}
					?>
					</ul>
					<a class="button" href="<?php echo $link( 'repair' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Check & repair tables', 'order-shield' ); ?></a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cache', 'order-shield' ); ?></th>
				<td><a class="button" href="<?php echo $link( 'clear_cache' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"><?php esc_html_e( 'Clear fraud check & courier caches', 'order-shield' ); ?></a></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Version', 'order-shield' ); ?></th>
				<td><?php echo esc_html( OSHIELD_VERSION ); ?></td>
			</tr>
		</table>
		<?php
	}
}
