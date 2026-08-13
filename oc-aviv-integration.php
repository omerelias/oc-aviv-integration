<?php
/**
 * Plugin Name: OC Aviv POS Integration
 * Description: Sends WooCommerce orders to Aviv POS. V1: one-way order creation on selected statuses.
 * Author: Original Concepts
 * Version: 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OC_AVIV_POS_VERSION', '0.1.0' );
define( 'OC_AVIV_POS_PATH', plugin_dir_path( __FILE__ ) );
define( 'OC_AVIV_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'OC_AVIV_POS_OPTION_KEY', 'oc_aviv_pos_settings' );
define( 'OC_AVIV_POS_CRON_HOOK', 'oc_aviv_pos_import_products_cron' );

require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-admin.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-api.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-order-handler.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-webhook.php';
require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-products-importer.php';

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
		OC_Aviv_Pos_Webhook::init();
	}
);

// Register cron schedules.
add_filter( 'cron_schedules', static function ( $schedules ) {
	$schedules['every_5_minutes'] = [
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'כל 5 דקות', 'oc-aviv-pos' ),
	];
	$schedules['every_15_minutes'] = [
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'כל 15 דקות', 'oc-aviv-pos' ),
	];
	$schedules['every_30_minutes'] = [
		'interval' => 30 * MINUTE_IN_SECONDS,
		'display'  => __( 'כל 30 דקות', 'oc-aviv-pos' ),
	];
	return $schedules;
} );

// Cron job handler.
add_action( OC_AVIV_POS_CRON_HOOK, static function () {
	$settings = get_option( OC_AVIV_POS_OPTION_KEY, [] );
	
	// Check if import is enabled
	if ( ( $settings['products_import_enabled'] ?? 'no' ) !== 'yes' ) {
		return;
	}

	$account_id   = $settings['account_id'] ?? '';
	$update_price = ( $settings['products_import_update_price'] ?? 'yes' ) === 'yes';
	$update_stock = ( $settings['products_import_update_stock'] ?? 'no' ) === 'yes';
	
	if ( empty( $account_id ) ) {
		return;
	}
	
	OC_Aviv_Pos_Products_Importer::import_from_account( $account_id, $update_price, $update_stock );
} );

// Flush rewrite rules on activation.
register_activation_hook( __FILE__, static function () {
	OC_Aviv_Pos_Webhook::add_rewrite_rules();
	flush_rewrite_rules();
} );

// Flush rewrite rules on deactivation.
register_deactivation_hook( __FILE__, static function () {
	// Clear cron
	wp_clear_scheduled_hook( OC_AVIV_POS_CRON_HOOK );
	flush_rewrite_rules();
} );

