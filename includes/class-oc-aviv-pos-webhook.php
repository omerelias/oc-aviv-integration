<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles webhook callbacks from Aviv POS for order status updates.
 */
class OC_Aviv_Pos_Webhook {

	/**
	 * Initialize webhook endpoint.
	 */
	public static function init(): void {
		// Register REST API endpoint
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		
		// Also register as query var for compatibility
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_webhook_request' ] );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'ocaviv/v1',
			'/getstatus',
			[
				'methods'             => [ 'GET', 'POST' ],
				'callback'            => [ __CLASS__, 'handle_status_update' ],
				'permission_callback' => '__return_true', // Public endpoint, Aviv POS will call it
			]
		);
	}

	/**
	 * Add rewrite rules for webhook URL.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^ocaviv/getstatus/?$', 'index.php?oc_aviv_webhook=1', 'top' );
	}

	/**
	 * Add query vars.
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'oc_aviv_webhook';
		return $vars;
	}

	/**
	 * Handle webhook request via query var (fallback).
	 */
	public static function handle_webhook_request(): void {
		if ( get_query_var( 'oc_aviv_webhook' ) ) {
			self::handle_status_update();
			exit;
		}
	}

	/**
	 * Get webhook URL.
	 *
	 * @return string
	 */
	public static function get_webhook_url(): string {
		// Try REST API first
		$rest_url = rest_url( 'ocaviv/v1/getstatus' );
		
		// Fallback to rewrite rule URL
		$rewrite_url = home_url( '/ocaviv/getstatus' );
		
		// Prefer REST API if available
		return $rest_url;
	}

	/**
	 * Handle status update from Aviv POS.
	 *
	 * @param WP_REST_Request|null $request REST request object (if called via REST API).
	 * @return WP_REST_Response|void
	 */
	public static function handle_status_update( $request = null ) {
		// Get JSON body
		$json = file_get_contents( 'php://input' );
		$data = json_decode( $json, true );

		// If no JSON body, try GET params or request body
		if ( empty( $data ) && $request instanceof WP_REST_Request ) {
			$data = $request->get_json_params() ?: $request->get_body_params();
		}

		// If still empty, try $_POST
		if ( empty( $data ) && ! empty( $_POST ) ) {
			$data = $_POST;
		}

		// Log the incoming request
		$logger = wc_get_logger();
		$logger->info( 'Aviv POS Webhook received: ' . wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), [ 'source' => 'oc-aviv-pos-webhook' ] );

		// Save webhook call to debug log
		self::log_webhook_call( $data );

		// Validate required fields
		if ( empty( $data['shareToken'] ) || empty( $data['type'] ) ) {
			$logger->warning( 'Aviv POS Webhook: Missing required fields (shareToken or type)', [ 'source' => 'oc-aviv-pos-webhook' ] );
			
			// Log error call
			$error_data = array_merge( $data, [ '_error' => 'Missing required fields' ] );
			self::log_webhook_call( $error_data );
			
			if ( $request instanceof WP_REST_Request ) {
				return new WP_REST_Response( [ 'error' => 'Missing required fields' ], 400 );
			}
			wp_send_json_error( [ 'message' => 'Missing required fields' ], 400 );
			return;
		}

		$share_token = sanitize_text_field( $data['shareToken'] );
		$status_type = sanitize_text_field( $data['type'] );
		$order_data  = isset( $data['data'] ) ? sanitize_text_field( $data['data'] ) : '';
		$time_status = isset( $data['timeStatus'] ) ? sanitize_text_field( $data['timeStatus'] ) : '';

		// Find order by order number (shareToken)
		$order = wc_get_order( $share_token );
		
		// If not found by ID, try to find by order number
		if ( ! $order ) {
			$orders = wc_get_orders(
				[
					'meta_key'   => '_order_number',
					'meta_value' => $share_token,
					'limit'      => 1,
				]
			);
			if ( ! empty( $orders ) ) {
				$order = $orders[0];
			}
		}

		// If still not found, try by order number directly (some plugins store it differently)
		if ( ! $order ) {
			$orders = wc_get_orders(
				[
					'limit'      => 100,
					'return'     => 'ids',
				]
			);
			foreach ( $orders as $order_id ) {
				$order_obj = wc_get_order( $order_id );
				if ( $order_obj && (string) $order_obj->get_order_number() === $share_token ) {
					$order = $order_obj;
					break;
				}
			}
		}

		if ( ! $order ) {
			$logger->warning( 'Aviv POS Webhook: Order not found for shareToken: ' . $share_token, [ 'source' => 'oc-aviv-pos-webhook' ] );
			
			// Log error call
			$error_data = array_merge( $data, [ '_error' => 'Order not found', '_shareToken' => $share_token ] );
			self::log_webhook_call( $error_data );
			
			if ( $request instanceof WP_REST_Request ) {
				return new WP_REST_Response( [ 'error' => 'Order not found' ], 404 );
			}
			wp_send_json_error( [ 'message' => 'Order not found' ], 404 );
			return;
		}

		// Map Aviv POS status to WooCommerce status
		$wc_status = self::map_aviv_status_to_wc( $status_type );

		if ( $wc_status ) {
			// Update order status
			$order->update_status( $wc_status, sprintf( __( 'סטטוס עודכן מ-Aviv POS: %s', 'oc-aviv-pos' ), $status_type ) );
			
			// Store Aviv POS data
			$order->update_meta_data( '_oc_aviv_pos_status', $status_type );
			$order->update_meta_data( '_oc_aviv_pos_data', $order_data );
			$order->update_meta_data( '_oc_aviv_pos_time_status', $time_status );
			$order->save();

			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s status updated to %s (Aviv: %s)', $share_token, $wc_status, $status_type ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		} else {
			// Status not mapped, just log it
			$order->update_meta_data( '_oc_aviv_pos_status', $status_type );
			$order->update_meta_data( '_oc_aviv_pos_data', $order_data );
			$order->update_meta_data( '_oc_aviv_pos_time_status', $time_status );
			$order->save();

			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s received status %s (not mapped to WC status)', $share_token, $status_type ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		}

		// Return success response
		if ( $request instanceof WP_REST_Request ) {
			return new WP_REST_Response( [ 'success' => true, 'order_id' => $order->get_id() ], 200 );
		}
		wp_send_json_success( [ 'order_id' => $order->get_id() ], 200 );
	}

	/**
	 * Map Aviv POS status to WooCommerce status.
	 *
	 * @param string $aviv_status Aviv POS status (e.g., "ACCEPTED", "OPEN", "COMMITTED").
	 * @return string|null WooCommerce status or null if not mapped.
	 */
	private static function map_aviv_status_to_wc( string $aviv_status ): ?string {
		$mapping = [
			'OPEN'      => 'wc-processing', // Order is open in POS
			'ACCEPTED'  => 'wc-processing', // Order accepted by POS
			'COMMITTED' => 'wc-completed',  // Order completed/delivered
			'CANCELLED' => 'wc-cancelled',  // Order cancelled
		];

		return $mapping[ $aviv_status ] ?? null;
	}

	/**
	 * Log webhook call for debugging.
	 *
	 * @param array $data Webhook data.
	 */
	private static function log_webhook_call( array $data ): void {
		$logs = get_option( 'oc_aviv_pos_webhook_logs', [] );
		
		$log_entry = [
			'timestamp'  => current_time( 'mysql' ),
			'timestamp_gmt' => current_time( 'mysql', true ),
			'data'       => $data,
			'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		// Add to beginning of array
		array_unshift( $logs, $log_entry );

		// Keep only last 200 entries
		$logs = array_slice( $logs, 0, 200 );

		update_option( 'oc_aviv_pos_webhook_logs', $logs );
	}

	/**
	 * Get webhook logs.
	 *
	 * @return array
	 */
	public static function get_webhook_logs(): array {
		return get_option( 'oc_aviv_pos_webhook_logs', [] );
	}

	/**
	 * Clear webhook logs.
	 */
	public static function clear_webhook_logs(): void {
		delete_option( 'oc_aviv_pos_webhook_logs' );
	}
}

