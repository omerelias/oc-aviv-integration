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

		// Ensure $data is an array
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		// Log the incoming request
		$logger = wc_get_logger();
		$logger->info( 'Aviv POS Webhook received: ' . wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), [ 'source' => 'oc-aviv-pos-webhook' ] );

		// Prepare response data (will be set later)
		$response_data = null;

		// Validate required fields
		if ( empty( $data['shareToken'] ) ) {
			$logger->warning( 'Aviv POS Webhook: Missing required field (shareToken)', [ 'source' => 'oc-aviv-pos-webhook' ] );
			
			$error_data = array_merge( $data, [ '_error' => 'Missing required field: shareToken' ] );
			
			// Build error response in Aviv POS format - exact format as specified
			$error_response = [
				'error'         => (int) 1, // Ensure integer type
				'errorMsg'      => (string) 'Missing required field: shareToken', // Ensure string type
				'shareToken'    => (string) '', // Ensure string type
				'amount'        => (int) 0, // Ensure integer type
				'proofToken'    => (string) '', // Ensure string type
				'checkoutPayment' => [
					'checkoutType' => (string) 'CASH', // Ensure string type
				],
			];
			
			// Log error call with response
			self::log_webhook_call( $error_data, $error_response );
			
			if ( $request instanceof WP_REST_Request ) {
				return new WP_REST_Response( $error_response, 400 );
			}
			
			// For query var fallback, output JSON directly
			header( 'Content-Type: application/json' );
			http_response_code( 400 );
			echo wp_json_encode( $error_response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}

		$share_token = sanitize_text_field( $data['shareToken'] );

		// Find order by order number (shareToken)
		$order = self::find_order_by_share_token( $share_token );

		if ( ! $order ) {
			$logger->warning( 'Aviv POS Webhook: Order not found for shareToken: ' . $share_token, [ 'source' => 'oc-aviv-pos-webhook' ] );
			
			$error_data = array_merge( $data, [ '_error' => 'Order not found', '_shareToken' => $share_token ] );
			
			// Build error response in Aviv POS format - exact format as specified
			$error_response = [
				'error'         => (int) 1, // Ensure integer type
				'errorMsg'      => (string) 'Order not found', // Ensure string type
				'shareToken'    => (string) $share_token, // Ensure string type
				'amount'        => (int) 0, // Ensure integer type
				'proofToken'    => (string) '', // Ensure string type
				'checkoutPayment' => [
					'checkoutType' => (string) 'CASH', // Ensure string type
				],
			];
			
			// Log error call with response
			self::log_webhook_call( $error_data, $error_response );
			
			if ( $request instanceof WP_REST_Request ) {
				return new WP_REST_Response( $error_response, 404 );
			}
			
			// For query var fallback, output JSON directly
			header( 'Content-Type: application/json' );
			http_response_code( 404 );
			echo wp_json_encode( $error_response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			exit;
		}

		// Check if this is a status update (has 'type') or items update (has 'items')
		$success = true;
		$error_msg = '';
		$updated_count = 0;
		
		if ( ! empty( $data['type'] ) ) {
			// Status update
			self::handle_status_update_for_order( $order, $data, $logger );
		} elseif ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			// Items update
			$updated_count = self::handle_items_update_for_order( $order, $data, $logger );
			
			// Mark order as completed after items update
			$order->update_status( 'wc-completed', __( 'הזמנה הושלמה לאחר עדכון כמויות מ-Aviv POS', 'oc-aviv-pos' ) );
			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s marked as completed after items update', $order->get_order_number() ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		} else {
			$logger->warning( 'Aviv POS Webhook: Unknown webhook type (no type or items)', [ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ] );
			$success = false;
			$error_msg = 'Unknown webhook type';
		}

		// Build response in Aviv POS format - exact format as specified
		$settings = OC_Aviv_Pos_Admin::get_settings();
		$payments = OC_Aviv_Pos_Order_Handler::map_payments( $order, $settings );
		$checkout_type = 'CASH'; // Default
		
		// Determine checkout type from payment
		if ( ! empty( $payments ) && isset( $payments[0]['paymentType'] ) ) {
			$checkout_type = $payments[0]['paymentType']; // CASH or POSTPAID
		}
		
		$share_token = (string) $order->get_order_number();
		$amount = (int) round( $order->get_total() * 100 ); // Amount in agorot
		
		// Get proofToken if available (e.g., CardcomToken)
		$proof_token = get_post_meta( $order->get_id(), 'CardcomToken', true ) ?: '';
		
		// Build response in exact format as specified by Aviv POS
		// Order matters - error, errorMsg, shareToken, amount, proofToken, checkoutPayment
		$response_data = [
			'error'         => (int) ( $success ? 0 : 1 ), // Ensure integer type
			'errorMsg'      => 'sadaasdsa', // Ensure string type
			'shareToken'    => (string) $share_token, // Ensure string type
			'amount'        => (int) $amount, // Ensure integer type (in agorot)
			'proofToken'    => (string) $proof_token, // Ensure string type
			'checkoutPayment' => [
//				'checkoutType' => (string) $checkout_type, // Ensure string type (CASH or POSTPAID)
				'checkoutType' => (string) 'CREDIT_CARD', // Ensure string type (CASH or POSTPAID)
			],
		];

		// Save webhook call to debug log with response
		self::log_webhook_call( $data, $response_data );

		// Return response
		if ( $request instanceof WP_REST_Request ) {
			$status_code = $success ? 200 : 400;
			return new WP_REST_Response( $response_data, $status_code );
		}
		
		// For query var fallback, output JSON directly
		header( 'Content-Type: application/json' );
		$status_code = $success ? 200 : 400;
		http_response_code( $status_code );
		echo wp_json_encode( $response_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Find order by share token (order number).
	 *
	 * @param string $share_token Order number.
	 * @return WC_Order|null
	 */
	private static function find_order_by_share_token( string $share_token ): ?WC_Order {
		// Try by ID first
		$order = wc_get_order( $share_token );
		
		// If not found by ID, try to find by order number meta
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

		return $order ?: null;
	}

	/**
	 * Handle status update for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Webhook data.
	 * @param WC_Log_Handler_Interface $logger Logger instance.
	 */
	private static function handle_status_update_for_order( WC_Order $order, array $data, $logger ): void {
		$status_type = sanitize_text_field( $data['type'] );
		$order_data  = isset( $data['data'] ) ? sanitize_text_field( $data['data'] ) : '';
		$time_status = isset( $data['timeStatus'] ) ? sanitize_text_field( $data['timeStatus'] ) : '';

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
				sprintf( 'Aviv POS Webhook: Order %s status updated to %s (Aviv: %s)', $order->get_order_number(), $wc_status, $status_type ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		} else {
			// Status not mapped, just log it
			$order->update_meta_data( '_oc_aviv_pos_status', $status_type );
			$order->update_meta_data( '_oc_aviv_pos_data', $order_data );
			$order->update_meta_data( '_oc_aviv_pos_time_status', $time_status );
			$order->save();

			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s received status %s (not mapped to WC status)', $order->get_order_number(), $status_type ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		}
	}

	/**
	 * Handle items update for an order.
	 *
	 * @param WC_Order $order Order object.
	 * @param array    $data  Webhook data with items.
	 * @param WC_Log_Handler_Interface $logger Logger instance.
	 * @return int Number of items updated.
	 */
	private static function handle_items_update_for_order( WC_Order $order, array $data, $logger ): int {
		// Temporarily remove hooks that might recalculate prices
		// This prevents theme/plugin hooks from interfering with our price updates
		$removed_hooks = [];
		if ( has_action( 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price' ) ) {
			remove_action( 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price', 20 );
			$removed_hooks[] = [ 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price', 20 ];
		}
		
		$items = $data['items'] ?? [];
		$updated_count = 0;

		foreach ( $items as $item_data ) {
			$item_id = $item_data['id'] ?? '';
			$new_count = isset( $item_data['count'] ) ? floatval( $item_data['count'] ) : null;
			$item_price_agorot = isset( $item_data['price'] ) ? floatval( $item_data['price'] ) : null; // Price in agorot

			if ( empty( $item_id ) || $new_count === null ) {
				continue;
			}

			// Find matching line item in order
			$found_item = null;
			foreach ( $order->get_items() as $line_item ) {
				$product = $line_item->get_product();
				if ( ! $product ) {
					continue;
				}

				// Match by SKU or product ID
				$product_id = $product->get_sku() ?: (string) $product->get_id();
				if ( (string) $product_id === (string) $item_id ) {
					$found_item = $line_item;
					break;
				}
			}

			if ( ! $found_item ) {
				$logger->warning( 
					sprintf( 'Aviv POS Webhook: Item %s not found in order %s', $item_id, $order->get_order_number() ),
					[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
				);
				continue;
			}

			$current_quantity = $found_item->get_quantity();
			
			// Get current values before update
			$current_subtotal = $found_item->get_subtotal();
			$current_subtotal_tax = $found_item->get_subtotal_tax();
			$current_total_with_tax = $current_subtotal + $current_subtotal_tax;
			
			// Check if quantity or price changed
			$quantity_changed = abs( $current_quantity - $new_count ) > 0.001; // Use small epsilon for float comparison
			$price_changed = false;
			
			if ( $item_price_agorot !== null ) {
				// Price in agorot is the total line price (subtotal + tax), same format as we send in build_payload
				// Convert to shekels
				$new_total_with_tax = $item_price_agorot / 100;
				
				$price_changed = abs( $current_total_with_tax - $new_total_with_tax ) > 0.01;
			}
			
			if ( $quantity_changed || $price_changed ) {
				// Calculate price per unit BEFORE updating anything
				// This preserves the original unit price
				$price_per_unit_subtotal = $current_subtotal / max( $current_quantity, 0.001 );
				$price_per_unit_tax = $current_subtotal_tax / max( $current_quantity, 0.001 );
				
				// Calculate new subtotal and tax based on new quantity
				// This ensures the unit price stays the same
				if ( $item_price_agorot !== null ) {
					// Price in agorot is the total line price (subtotal + tax)
					// Convert to shekels
					$new_total_with_tax = $item_price_agorot / 100;
					
					// Calculate tax rate from original item to split the new total
					$tax_rate = 0;
					if ( $current_subtotal > 0 && $current_subtotal_tax > 0 ) {
						$tax_rate = ( $current_subtotal_tax / $current_subtotal ) * 100;
					}
					
					// Split new total between subtotal and tax
					if ( $tax_rate > 0 ) {
						// Calculate subtotal from total (total = subtotal * (1 + tax_rate/100))
						$new_subtotal = $new_total_with_tax / ( 1 + ( $tax_rate / 100 ) );
						$new_tax = $new_total_with_tax - $new_subtotal;
					} else {
						// No tax
						$new_subtotal = $new_total_with_tax;
						$new_tax = 0;
					}
				} elseif ( $quantity_changed ) {
					// Only quantity changed - multiply original unit price by new quantity
					// This ensures the total increases proportionally, not the unit price decreases
					$new_subtotal = $price_per_unit_subtotal * $new_count;
					$new_tax = $price_per_unit_tax * $new_count;
				} else {
					// No changes needed
					continue;
				}
				
				// CRITICAL: We need to update subtotal BEFORE quantity to preserve unit price
				// WooCommerce calculates unit price as subtotal/quantity
				// If quantity is 1 and subtotal is 1, unit price = 1
				// If we change quantity to 6, we need subtotal to be 6 BEFORE setting quantity
				// So: set subtotal to 6, then set quantity to 6, then unit price = 6/6 = 1 (preserved!)
				
				// First, update subtotal and tax (this sets the total line price)
				$found_item->set_subtotal( $new_subtotal );
				$found_item->set_total( $new_subtotal );
				$found_item->set_subtotal_tax( $new_tax );
				$found_item->set_total_tax( $new_tax );
				
				// Then update quantity (WooCommerce will calculate unit price = new_subtotal / new_quantity)
				$found_item->set_quantity( $new_count );
				
				// Save the item - this commits both changes together
				$found_item->save();
				
				// Verify the unit price is correct after save
				// If WooCommerce recalculated it incorrectly, fix it
				$saved_subtotal = $found_item->get_subtotal();
				$saved_quantity = $found_item->get_quantity();
				$actual_unit_price = $saved_quantity > 0 ? $saved_subtotal / $saved_quantity : 0;
				$expected_unit_price = $price_per_unit_subtotal;
				
				// If unit price was recalculated incorrectly, fix it
				if ( abs( $actual_unit_price - $expected_unit_price ) > 0.01 ) {
					// Recalculate subtotal based on expected unit price
					$corrected_subtotal = $expected_unit_price * $saved_quantity;
					$corrected_tax = $price_per_unit_tax * $saved_quantity;
					
					$found_item->set_subtotal( $corrected_subtotal );
					$found_item->set_total( $corrected_subtotal );
					$found_item->set_subtotal_tax( $corrected_tax );
					$found_item->set_total_tax( $corrected_tax );
					$found_item->save();
					
					$logger->warning( 
						sprintf( 'Aviv POS Webhook: Corrected unit price for item %s in order %s (was %s, should be %s)', 
							$item_id, 
							$order->get_order_number(),
							$actual_unit_price,
							$expected_unit_price
						),
						[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
					);
				}
				
				$updated_count++;
				
				$logger->info( 
					sprintf( 'Aviv POS Webhook: Order %s item %s updated - quantity: %s → %s%s', 
						$order->get_order_number(), 
						$item_id, 
						$current_quantity, 
						$new_count,
						$price_changed ? ' (price also updated)' : ''
					),
					[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
				);
			}
		}

		if ( $updated_count > 0 ) {
			// Don't call calculate_totals() here - it will recalculate unit prices
			// We've already set the correct subtotals and taxes above
			// Just update the order totals manually
			$order_total = 0;
			$order_subtotal = 0;
			$order_tax = 0;
			
			foreach ( $order->get_items() as $item ) {
				$order_subtotal += $item->get_subtotal();
				$order_tax += $item->get_subtotal_tax();
			}
			
			$order_total = $order_subtotal + $order_tax + $order->get_shipping_total() - $order->get_total_discount();
			
//			$order->set_subtotal( $order_subtotal );
//			$order->set_total( $order_total );
//			$order->set_total_tax( $order_tax );
			
			$order->add_order_note( 
				sprintf( __( 'עודכנו %d פריטים מ-Aviv POS', 'oc-aviv-pos' ), $updated_count )
			);
			$order->save();

			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s items updated (%d items changed)', $order->get_order_number(), $updated_count ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		}
		
		// Restore removed hooks
		foreach ( $removed_hooks as $hook ) {
			add_action( $hook[0], $hook[1], $hook[2] );
		}
		
		return $updated_count;
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
	 * @param array      $data     Webhook data (incoming request).
	 * @param array|null $response Response data (sent back to client).
	 */
	private static function log_webhook_call( array $data, ?array $response = null ): void {
		$logs = get_option( 'oc_aviv_pos_webhook_logs', [] );
		
		$log_entry = [
			'timestamp'  => current_time( 'mysql' ),
			'timestamp_gmt' => current_time( 'mysql', true ),
			'data'       => $data,
			'response'   => $response,
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

