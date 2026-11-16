<?php
/**
 * Plugin Name:       Fake Order Shield
 * Plugin URI:        https://brandingsolutionsbd.com/
 * Description:       Fake order protection for WooCommerce: fraud check by phone, block list (phone, IP, device), phone validation, repeat-order blocker, VPN/country block, incomplete orders, live visitors with session replay and Steadfast &amp; Pathao courier.
 * Version:           1.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Branding Solutions BD
 * Author URI:        https://brandingsolutionsbd.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       order-shield
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OSHIELD_VERSION', '1.3.1' );
define( 'OSHIELD_DB_VERSION', '1.2.0' );
define( 'OSHIELD_FILE', __FILE__ );
define( 'OSHIELD_DIR', plugin_dir_path( __FILE__ ) );
define( 'OSHIELD_URL', plugin_dir_url( __FILE__ ) );

require_once OSHIELD_DIR . 'includes/functions.php';
require_once OSHIELD_DIR . 'includes/class-oshield-settings.php';
require_once OSHIELD_DIR . 'includes/class-oshield-install.php';
require_once OSHIELD_DIR . 'includes/class-oshield-blocklist.php';
require_once OSHIELD_DIR . 'includes/class-oshield-fraud.php';
require_once OSHIELD_DIR . 'includes/class-oshield-vpn.php';
require_once OSHIELD_DIR . 'includes/class-oshield-checkout.php';
require_once OSHIELD_DIR . 'includes/class-oshield-incomplete.php';
require_once OSHIELD_DIR . 'includes/class-oshield-live.php';
require_once OSHIELD_DIR . 'includes/class-oshield-courier.php';
require_once OSHIELD_DIR . 'includes/class-oshield-rest.php';

register_activation_hook( __FILE__, array( 'OShield_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OShield_Install', 'deactivate' ) );

// Declare WooCommerce HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin.
 */
function oshield_boot() {
	load_plugin_textdomain( 'order-shield', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	OShield_Install::maybe_upgrade();
	OShield_REST::init();

	if ( is_admin() ) {
		require_once OSHIELD_DIR . 'admin/class-oshield-admin.php';
		OShield_Admin::init();
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'oshield_missing_wc_notice' );
		return;
	}

	OShield_Courier::init();
	OShield_VPN::init();
	OShield_Checkout::init();
	OShield_Incomplete::init();
	OShield_Live::init();
}
// Boot on init (not plugins_loaded) so translated setting labels are never loaded too early.
add_action( 'init', 'oshield_boot', 1 );

/**
 * Admin notice when WooCommerce is missing.
 */
function oshield_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	esc_html_e( 'Fake Order Shield needs WooCommerce to be installed and active. Most features are paused until WooCommerce is active.', 'order-shield' );
	echo '</p></div>';
}

/**
 * Settings link on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url = admin_url( 'admin.php?page=order-shield-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'order-shield' ) . '</a>' );
		return $links;
	}
);
