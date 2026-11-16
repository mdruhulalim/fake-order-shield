<?php
/**
 * Plugin Name:       FOS License Server
 * Description:       Sells Fake Order Shield licenses: landing page, customer accounts, 1/3/6/12-month packages paid by bKash/Nagad (manual approval) and the license API the plugin talks to.
 * Version:           1.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Branding Solutions BD
 * License:           GPL-2.0-or-later
 * Text Domain:       fos-license-server
 *
 * @package FOSLicenseServer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FOS_LS_VERSION', '1.2.1' );
define( 'FOS_LS_FILE', __FILE__ );
define( 'FOS_LS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOS_LS_URL', plugin_dir_url( __FILE__ ) );

require_once FOS_LS_DIR . 'includes/class-fos-store.php';
require_once FOS_LS_DIR . 'includes/class-fos-api.php';
require_once FOS_LS_DIR . 'includes/class-fos-front.php';
require_once FOS_LS_DIR . 'includes/class-fos-admin.php';

register_activation_hook( __FILE__, array( 'FOS_Store', 'activate' ) );

add_action( 'rest_api_init', array( 'FOS_API', 'routes' ) );
add_action( 'init', array( 'FOS_Front', 'init' ) );
if ( is_admin() ) {
	FOS_Admin::init();
}
