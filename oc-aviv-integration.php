<?php
/**
 * Plugin Name: OC Aviv POS Integration
 * Description: Sends WooCommerce orders to Aviv POS. V1: one-way order creation on selected statuses.
 * Author: OpenCode
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OC_AVIV_POS_VERSION', '0.1.0' );
define( 'OC_AVIV_POS_PATH', plugin_dir_path( __FILE__ ) );
define( 'OC_AVIV_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'OC_AVIV_POS_OPTION_KEY', 'oc_aviv_pos_settings' );

require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-admin.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-api.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-order-handler.php';

// Bootstrap.
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( is_admin() ) {
			OC_Aviv_Pos_Admin::init();
		}

		OC_Aviv_Pos_Order_Handler::init();
	}
);

