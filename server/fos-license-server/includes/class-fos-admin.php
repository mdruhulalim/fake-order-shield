<?php
/**
 * Admin: orders/licenses list with approve, edit, settings.
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FOS_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_fos_ls_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_fos_ls_save', array( __CLASS__, 'save' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		global $wpdb;
		$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FOS_Store::table() . " WHERE status = 'pending'" ); // phpcs:ignore
		$bubble  = $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '';
		add_menu_page( 'FOS Licenses', 'FOS Licenses' . $bubble, 'manage_options', 'fos-licenses', array( __CLASS__, 'page' ), 'dashicons-shield', 3 );
		add_submenu_page( 'fos-licenses', 'Licenses', 'Licenses', 'manage_options', 'fos-licenses', array( __CLASS__, 'page' ) );
		add_submenu_page( 'fos-licenses', 'FOS Settings', 'Settings', 'manage_options', 'fos-settings', array( __CLASS__, 'settings' ) );
	}

	/**
	 * Approve / reject / revoke / edit.
	 */
	public static function action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'fos_ls_action' );
		global $wpdb;
		$id  = absint( $_REQUEST['id'] ?? 0 );
		$do  = sanitize_key( $_REQUEST['do'] ?? '' );
		$row = FOS_Store::get( $id );
		$msg = 'error';
		if ( $row ) {
			if ( 'approve' === $do ) {
				$msg = FOS_Store::approve( $id ) ? 'approved' : 'error';
			} elseif ( 'reject' === $do && 'pending' === $row->status ) {
				$wpdb->update( FOS_Store::table(), array( 'status' => 'rejected' ), array( 'id' => $id ) ); // phpcs:ignore
				$msg = 'rejected';
			} elseif ( 'revoke' === $do || 'restore' === $do ) {
				$wpdb->update( FOS_Store::table(), array( 'status' => 'revoke' === $do ? 'revoked' : 'active' ), array( 'id' => $id ) ); // phpcs:ignore
				$msg = 'updated';
			} elseif ( 'edit' === $do ) {
				$domain  = FOS_Store::domain( sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ) );
				$expires = sanitize_text_field( wp_unslash( $_POST['expires'] ?? '' ) );
				$data    = array();
				if ( $domain ) {
					$data['domain'] = $domain;
				}
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ) {
					$data['expires_at'] = get_gmt_from_date( $expires . ' 23:59:59' );
				}
				if ( $data ) {
					$wpdb->update( FOS_Store::table(), $data, array( 'id' => $id ) ); // phpcs:ignore
				}
				$msg = 'updated';
			}
		}
		wp_safe_redirect( add_query_arg( 'msg', $msg, wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=fos-licenses' ) ) );
		exit;
	}

	/**
	 * Action link.
	 *
	 * @param int    $id    Row id.
	 * @param string $do    Action.
	 * @param string $label Label.
	 * @param string $class Button class.
	 * @return string
	 */
	private static function link( $id, $do, $label, $class = 'button button-small' ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=fos_ls_action&do=' . $do . '&id=' . $id ), 'fos_ls_action' );
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
	}

	/**
	 * Licenses page.
	 */
	public static function page() {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$s      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$edit   = isset( $_GET['edit'] ) ? FOS_Store::get( absint( $_GET['edit'] ) ) : null;
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
		// phpcs:enable
		$t     = FOS_Store::table();
		$where = '1=1';
		$args  = array();
		if ( $status ) {
			$where .= ' AND status = %s';
			$args[] = $status;
		}
		if ( $s ) {
			$like   = '%' . $wpdb->esc_like( $s ) . '%';
			$where .= ' AND (domain LIKE %s OR license_key LIKE %s OR trx_id LIKE %s OR sender LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$sql  = "SELECT * FROM $t WHERE $where ORDER BY (status = 'pending') DESC, id DESC LIMIT 200";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore

		echo '<div class="wrap"><h1 class="wp-heading-inline">Fake Order Shield Licenses</h1><hr class="wp-header-end">';
		$notes = array(
			'approved' => 'Approved. The customer sees the key in their account.',
			'rejected' => 'Order rejected.',
			'updated'  => 'Saved.',
			'error'    => 'Could not do that.',
		);
		if ( isset( $notes[ $msg ] ) ) {
			echo '<div class="notice notice-' . ( 'error' === $msg ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $notes[ $msg ] ) . '</p></div>';
		}

		if ( $edit ) {
			echo '<div class="postbox" style="max-width:640px;padding:12px 16px"><h2>Edit license #' . esc_html( $edit->id ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'fos_ls_action' );
			echo '<input type="hidden" name="action" value="fos_ls_action"><input type="hidden" name="do" value="edit"><input type="hidden" name="id" value="' . esc_attr( $edit->id ) . '">';
			echo '<p><label>Domain<br><input type="text" name="domain" class="regular-text" value="' . esc_attr( $edit->domain ) . '"></label></p>';
			echo '<p><label>Expires (date)<br><input type="date" name="expires" value="' . esc_attr( $edit->expires_at ? get_date_from_gmt( $edit->expires_at, 'Y-m-d' ) : '' ) . '"></label></p>';
			submit_button( 'Save', 'primary', 'submit', false );
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=fos-licenses' ) ) . '">Cancel</a></form></div>';
		}

		echo '<ul class="subsubsub">';
		foreach ( array( '' => 'All', 'pending' => 'Pending', 'active' => 'Active', 'revoked' => 'Revoked', 'rejected' => 'Rejected' ) as $k => $label ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=fos-licenses' . ( $k ? '&status=' . $k : '' ) ) ) . '"' . ( $status === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a> | </li>';
		}
		echo '</ul><form method="get" class="search-box"><input type="hidden" name="page" value="fos-licenses"><input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="Domain, key, TrxID, phone"> <button class="button">Search</button></form>';

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:50px">#</th><th>Customer</th><th>Domain</th><th>Package</th><th>Payment</th><th>Status</th><th>Expires</th><th>Key</th><th style="width:200px">Actions</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">Nothing here yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$u  = get_userdata( $r->user_id );
			$st = FOS_Store::status( $r );
			echo '<tr><td>' . esc_html( $r->id ) . '</td>';
			echo '<td>' . ( $u ? esc_html( $u->display_name ) . '<br><small>' . esc_html( $u->user_email ) . '<br>' . esc_html( get_user_meta( $u->ID, 'fos_phone', true ) ) . '</small>' : '—' ) . '</td>';
			echo '<td>' . esc_html( $r->domain ) . ( $r->renewal_of ? '<br><small>Renewal of #' . esc_html( $r->renewal_of ) . '</small>' : '' ) . '</td>';
			if ( 'trial' === $r->method ) {
				echo '<td>Free trial</td><td>—</td>';
			} else {
				echo '<td>' . esc_html( $r->months ) . ' mo · ৳' . esc_html( number_format_i18n( $r->amount ) ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $r->method ) ) . '<br><code>' . esc_html( $r->trx_id ) . '</code><br><small>' . esc_html( $r->sender ) . '</small></td>';
			}
			echo '<td><strong>' . esc_html( ucfirst( $st ) ) . '</strong>' . ( $r->last_check ? '<br><small>checked ' . esc_html( human_time_diff( strtotime( $r->last_check . ' UTC' ) ) ) . ' ago</small>' : '' ) . '</td>';
			echo '<td>' . ( $r->expires_at ? esc_html( get_date_from_gmt( $r->expires_at, 'd M Y' ) ) : '—' ) . '</td>';
			echo '<td><code>' . esc_html( $r->license_key ) . '</code></td><td>';
			if ( 'pending' === $r->status ) {
				echo self::link( $r->id, 'approve', 'Approve', 'button button-primary button-small' ) . self::link( $r->id, 'reject', 'Reject' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( ! $r->renewal_of && 'revoked' === $r->status ) {
				echo self::link( $r->id, 'restore', 'Restore' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} elseif ( ! $r->renewal_of && 'active' === $r->status ) {
				echo self::link( $r->id, 'revoke', 'Revoke' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			if ( ! $r->renewal_of && in_array( $r->status, array( 'active', 'revoked' ), true ) ) {
				echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=fos-licenses&edit=' . $r->id ) ) . '">Edit</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">Approve only after you see the TrxID and amount in your bKash/Nagad app. Approving starts (or, for a renewal, extends) the license; the customer sees the key on their account page.</p></div>';
	}

	/**
	 * Save settings.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'fos_ls_save' );
		$in  = isset( $_POST['fos'] ) && is_array( $_POST['fos'] ) ? wp_unslash( $_POST['fos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$out = array();
		foreach ( FOS_Store::defaults() as $k => $d ) {
			$v         = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $d;
			$out[ $k ] = ( 0 === strpos( $k, 'price_' ) || 'trial_days' === $k ) ? (string) max( 0, (float) $v ) : ( 'download_url' === $k ? esc_url_raw( $v ) : $v );
		}
		update_option( FOS_Store::OPTION, $out );
		wp_safe_redirect( admin_url( 'admin.php?page=fos-settings&saved=1' ) );
		exit;
	}

	/**
	 * Settings page.
	 */
	public static function settings() {
		$fields = array(
			'price_1'      => 'Price — 1 month (৳)',
			'price_3'      => 'Price — 3 months (৳)',
			'price_6'      => 'Price — 6 months (৳)',
			'price_12'     => 'Price — 12 months (৳)',
			'bkash'        => 'bKash number (Send Money)',
			'nagad'        => 'Nagad number (Send Money)',
			'support'      => 'Support number (plugin install / license help, shown on the account page)',
			'trial_days'   => 'Free trial (days)',
			'download_url' => 'Plugin ZIP download URL (upload the ZIP to Media and paste its link)',
		);
		echo '<div class="wrap"><h1>FOS Settings</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="fos_ls_save">';
		wp_nonce_field( 'fos_ls_save' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $k => $label ) {
			echo '<tr><th><label for="fos-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input id="fos-' . esc_attr( $k ) . '" type="text" class="regular-text" name="fos[' . esc_attr( $k ) . ']" value="' . esc_attr( FOS_Store::opt( $k ) ) . '"></td></tr>';
		}
		echo '</table>';
		submit_button();
		echo '</form><p class="description">API for the plugin: <code>' . esc_html( rest_url( 'fos/v1/license' ) ) . '</code></p>';
		echo '<p class="description">Signing public key (built into the plugin; never share the secret key): <code>' . esc_html( FOS_Store::keys()['public'] ) . '</code></p></div>';
	}
}
