<?php
/**
 * Block list storage: phone, IP, device and email blocks.
 *
 * @package OrderShield
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OShield_Blocklist {

	/**
	 * Allowed block types.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'phone'  => __( 'Phone', 'order-shield' ),
			'ip'     => __( 'IP address', 'order-shield' ),
			'device'   => __( 'Device (this browser)', 'order-shield' ),
			'hardware' => __( 'Device (all browsers)', 'order-shield' ),
			'email'    => __( 'Email', 'order-shield' ),
		);
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'oshield_blocks';
	}

	/**
	 * Normalize a value for its type. Returns '' if invalid.
	 *
	 * @param string $type  Type.
	 * @param string $value Value.
	 * @return string
	 */
	public static function normalize( $type, $value ) {
		$value = trim( (string) $value );
		switch ( $type ) {
			case 'phone':
				return oshield_normalize_phone( $value );
			case 'ip':
				return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
			case 'email':
				return is_email( $value ) ? strtolower( $value ) : '';
			case 'device':
				return substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $value ), 0, 64 );
			case 'hardware':
				// "hw-xxxx" or "hw-xxxx@network".
				$value = strtolower( $value );
				return preg_match( '/^hw-[a-f0-9]{6,32}(@[a-f0-9.:]{3,45})?$/', $value ) ? $value : '';
		}
		return '';
	}

	/**
	 * Add (or re-activate) a block.
	 *
	 * @param string $type   Type.
	 * @param string $value  Value.
	 * @param string $reason Reason.
	 * @param int    $days   0 = permanent.
	 * @param string $source manual|auto|order|import.
	 * @return true|WP_Error
	 */
	public static function add( $type, $value, $reason = '', $days = 0, $source = 'manual' ) {
		global $wpdb;
		if ( ! isset( self::types()[ $type ] ) ) {
			return new WP_Error( 'oshield_type', __( 'Unknown block type.', 'order-shield' ) );
		}
		$value = self::normalize( $type, $value );
		if ( '' === $value ) {
			return new WP_Error( 'oshield_value', __( 'This value is not valid for the selected type.', 'order-shield' ) );
		}
		$expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + (int) $days * DAY_IN_SECONDS ) : null; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE type = %s AND value = %s', $type, $value ) ); // phpcs:ignore
		$data     = array(
			'reason'     => sanitize_text_field( $reason ),
			'source'     => $source,
			'active'     => 1,
			'expires_at' => $expires,
		);
		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'id' => $existing ) ); // phpcs:ignore
		} else {
			$wpdb->insert( // phpcs:ignore
				self::table(),
				array_merge(
					$data,
					array(
						'type'       => $type,
						'value'      => $value,
						'created_by' => get_current_user_id(),
						'created_at' => current_time( 'mysql' ),
					)
				)
			);
		}
		return true;
	}

	/**
	 * Block a device in every browser: the browser id plus the hardware fingerprint.
	 * In "same network" mode (default) the fingerprint is tied to the network the device used,
	 * so other people who own the identical phone model are not blocked.
	 *
	 * @param string $device_id Browser device id.
	 * @param string $hwid      Hardware fingerprint.
	 * @param string $ip        IP the device used.
	 * @param string $reason    Reason.
	 * @param int    $days      Days (0 = permanent).
	 * @param string $source    Source.
	 * @return bool Whether anything was blocked.
	 */
	public static function block_device( $device_id, $hwid, $ip, $reason = '', $days = 0, $source = 'manual' ) {
		$done = false;
		if ( '' !== (string) $device_id ) {
			$done = true === self::add( 'device', $device_id, $reason, $days, $source );
		}
		if ( '' !== (string) $hwid ) {
			$net   = oshield_net_prefix( $ip );
			$value = $hwid . ( $net ? '@' . $net : '' );
			$done  = ( true === self::add( 'hardware', $value, $reason, $days, $source ) ) || $done;
		}
		return $done;
	}

	/**
	 * Unblock a device in every browser.
	 *
	 * @param string $device_id Browser device id.
	 * @param string $hwid      Hardware fingerprint.
	 */
	public static function unblock_device( $device_id, $hwid ) {
		global $wpdb;
		if ( '' !== (string) $device_id ) {
			self::remove( 'device', $device_id );
		}
		$hwid = self::normalize( 'hardware', $hwid );
		if ( '' !== $hwid ) {
			$base = strtok( $hwid, '@' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE type = 'hardware' AND ( value = %s OR value LIKE %s )", $base, $wpdb->esc_like( $base . '@' ) . '%' ) ); // phpcs:ignore
		}
	}

	/**
	 * Active hardware block matching this fingerprint + IP.
	 *
	 * @param string $hwid Fingerprint.
	 * @param string $ip   IP.
	 * @return object|null
	 */
	public static function find_hardware( $hwid, $ip ) {
		global $wpdb;
		$hwid = self::normalize( 'hardware', $hwid );
		if ( '' === $hwid ) {
			return null;
		}
		$base  = strtok( $hwid, '@' );
		$net   = oshield_net_prefix( $ip );
		$where = 'value = %s OR value = %s';
		$args  = array( $base, $base . '@' . $net );
		if ( 'any' === oshield_opt( 'device_match' ) ) {
			$where .= ' OR value LIKE %s';
			$args[] = $wpdb->esc_like( $base . '@' ) . '%';
		}
		$args[] = current_time( 'mysql' );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE type = 'hardware' AND ( {$where} ) AND active = 1 AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1", $args ) ); // phpcs:ignore
	}

	/**
	 * Delete a block by id.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Delete by type + value.
	 *
	 * @param string $type  Type.
	 * @param string $value Value.
	 */
	public static function remove( $type, $value ) {
		global $wpdb;
		$value = self::normalize( $type, $value );
		if ( '' !== $value ) {
			$wpdb->delete( self::table(), array( 'type' => $type, 'value' => $value ) ); // phpcs:ignore
		}
	}

	/**
	 * Enable / disable a block.
	 *
	 * @param int  $id     Id.
	 * @param bool $active Active.
	 */
	public static function set_active( $id, $active ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'active' => $active ? 1 : 0 ), array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Find an active, unexpired block.
	 *
	 * @param string $type  Type.
	 * @param string $value Value.
	 * @return object|null
	 */
	public static function find( $type, $value ) {
		global $wpdb;
		$value = self::normalize( $type, $value );
		if ( '' === $value ) {
			return null;
		}
		return $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE type = %s AND value = %s AND active = 1 AND ( expires_at IS NULL OR expires_at > %s )', // phpcs:ignore
				$type,
				$value,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Check the current customer against every block type.
	 *
	 * @param array $who phone, ip, device, email.
	 * @return object|null Matching block row.
	 */
	public static function match( $who ) {
		global $wpdb;
		foreach ( array( 'phone', 'ip', 'device', 'hardware', 'email' ) as $type ) {
			if ( empty( $who[ $type ] ) ) {
				continue;
			}
			$row = 'hardware' === $type ? self::find_hardware( $who['hardware'], isset( $who['ip'] ) ? $who['ip'] : '' ) : self::find( $type, $who[ $type ] );
			if ( $row ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1 WHERE id = %d', $row->id ) ); // phpcs:ignore
				return $row;
			}
		}
		return null;
	}

	/**
	 * Block status of several values at once (for admin screens).
	 *
	 * @param array $who type => value.
	 * @return array type => bool
	 */
	public static function status( $who ) {
		$out = array();
		foreach ( $who as $type => $value ) {
			$out[ $type ] = ( '' !== (string) $value ) && (bool) self::find( $type, $value );
		}
		// A device counts as blocked if its hardware fingerprint is blocked anywhere.
		if ( isset( $who['device'], $who['hardware'] ) && empty( $out['device'] ) && '' !== (string) $who['hardware'] ) {
			global $wpdb;
			$base          = strtok( (string) $who['hardware'], '@' );
			$out['device'] = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE type = 'hardware' AND active = 1 AND ( value = %s OR value LIKE %s ) LIMIT 1", $base, $wpdb->esc_like( $base . '@' ) . '%' ) ); // phpcs:ignore
		}
		return $out;
	}

	/**
	 * Count active blocks.
	 *
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE active = 1 AND ( expires_at IS NULL OR expires_at > %s )', current_time( 'mysql' ) ) ); // phpcs:ignore
	}

	/**
	 * Export all blocks as CSV string.
	 *
	 * @return string
	 */
	public static function export_csv() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT type, value, reason, source, active, expires_at, created_at FROM ' . self::table() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore
		$fh   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $fh, array( 'type', 'value', 'reason', 'source', 'active', 'expires_at', 'created_at' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, $r );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	/**
	 * Import blocks from a CSV file. Accepts our export format or simple "type,value[,reason]" rows.
	 * A single column file is treated as a list of phone numbers.
	 *
	 * @param string $path File path.
	 * @return int Number imported.
	 */
	public static function import_csv( $path ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return 0;
		}
		$count = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$row = array_map( 'trim', $row );
			if ( empty( $row[0] ) || 'type' === strtolower( $row[0] ) ) {
				continue;
			}
			if ( count( $row ) === 1 || ! isset( self::types()[ strtolower( $row[0] ) ] ) ) {
				$type   = 'phone';
				$value  = $row[0];
				$reason = isset( $row[1] ) ? $row[1] : '';
			} else {
				$type   = strtolower( $row[0] );
				$value  = isset( $row[1] ) ? $row[1] : '';
				$reason = isset( $row[2] ) ? $row[2] : '';
			}
			$days = 0;
			if ( ! empty( $row[5] ) && strtotime( $row[5] ) ) {
				$days = max( 0, (int) ceil( ( strtotime( $row[5] ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
				if ( 0 === $days ) {
					continue; // Already expired.
				}
			}
			if ( true === self::add( $type, $value, $reason, $days, 'import' ) ) {
				++$count;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}
}
