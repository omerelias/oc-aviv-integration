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
				'methods'             => [ 'GET', 'POST', 'PUT', 'PATCH' ],
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
		$is_items_update = false;
		
		if ( ! empty( $data['type'] ) ) {
			// Status update
			self::handle_status_update_for_order( $order, $data, $logger );
		} elseif ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			// Items update
			$is_items_update = true;
			$updated_count = self::handle_items_update_for_order( $order, $data, $logger );
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

		// Extra webhook logs around success flag to debug status update flow
		if ( $success ) {
			self::log_webhook_call(
				array_merge(
					$data,
					[
						'_stage'   => 'before_status_update',
						'_success' => true,
					]
				),
				$response_data
			);

			$logger->info(
				sprintf(
					'Aviv POS Webhook: Success=true before updating status for order %s',
					$order->get_order_number()
				),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		} else {
			self::log_webhook_call(
				array_merge(
					$data,
					[
						'_stage'    => 'before_status_update',
						'_success'  => false,
						'_errorMsg' => $error_msg,
					]
				),
				$response_data
			);

			$logger->warning(
				sprintf(
					'Aviv POS Webhook: Success=false before updating status for order %s (error: %s)',
					$order->get_order_number(),
					$error_msg
				),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		}

		// If this was an items update, mark order as completed at the end
		if ( $success ) {
			// Charge the Cardcom J5 token BEFORE completing the order. Marking it completed sends
			// the customer completed-order email, which can fatal inside a third-party plugin
			// (e.g. the units plugin on product-less custom lines) and abort the request before
			// the capture would run. Gateways are also not always instantiated in this REST
			// context, so Cardcom's own status-completed hook may not fire either.
			self::maybe_capture_cardcom( $order, $logger );

			$order->update_status( 'wc-completed', __( 'הזמנה הושלמה לאחר עדכון כמויות מ-Aviv POS', 'oc-aviv-pos' ) );
			$order->save();
			$logger->info( 
				sprintf( 'Aviv POS Webhook: Order %s marked as completed after items update', $order->get_order_number() ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
		}

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
	 * Trigger the Cardcom J5 capture explicitly (gateway hook may not be registered in REST).
	 */
	private static function maybe_capture_cardcom( WC_Order $order, $logger ): void {
		if ( 'cardcom' !== $order->get_payment_method() ) {
			return;
		}
		// Only when a J5 capture is pending (checkout set this to 'no'); order_capture_payment
		// also guards on this and flips it to 'yes', so a later hook will not double-charge.
		if ( 'no' !== (string) $order->get_meta( 'cardcom_charge_captured' ) ) {
			return;
		}
		$gateways = ( function_exists( 'WC' ) && WC()->payment_gateways() ) ? WC()->payment_gateways()->payment_gateways() : [];
		if ( isset( $gateways['cardcom'] ) && method_exists( $gateways['cardcom'], 'order_capture_payment' ) ) {
			$logger->info(
				sprintf( 'Aviv POS Webhook: triggering Cardcom J5 capture for order %s', $order->get_order_number() ),
				[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
			);
			$gateways['cardcom']->order_capture_payment( $order );
		}
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
				if ( $order_obj instanceof WC_Order && (string) $order_obj->get_order_number() === $share_token ) {
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
	 * @return int Number of items updated/added/deleted.
	 */
	private static function handle_items_update_for_order( WC_Order $order, array $data, $logger ): int {
		// Temporarily remove hooks that recalculate prices during our edits.
		$removed_hooks = [];
		if ( has_action( 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price' ) ) {
			remove_action( 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price', 20 );
			$removed_hooks[] = [ 'woocommerce_before_calculate_totals', 'sea2door_add_custom_price', 20 ];
		}

		$items = $data['items'] ?? [];

		// Aviv sends an edited order as DELTA lines: the same SKU may appear several times,
		// counts can be negative (removals/credits), ad-hoc items arrive as TEXT rows with an
		// empty id, and Aviv-only SKUs (e.g. 999xxx / 9000xxxxxx) have no WooCommerce product.
		// `price` is the UNIT price in agorot (per kg/unit); the sign of `count` says whether
		// the line adds or subtracts. Aggregate to the net basket (net qty and net line total).
		$agg = [];
		foreach ( $items as $row ) {
			$rid         = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			$desc        = isset( $row['desc'] ) ? (string) $row['desc'] : '';
			$count       = isset( $row['count'] ) ? floatval( $row['count'] ) : 0.0;
			$unit_agorot = isset( $row['price'] ) ? floatval( $row['price'] ) : 0.0; // per unit
			$type        = isset( $row['itemType'] ) ? (string) $row['itemType'] : 'PRODUCT';

			$is_text = ( $rid === '' || $type === 'TEXT' );
			$key     = $is_text ? ( 'text::' . $desc ) : ( 'sku::' . $rid );

			if ( ! isset( $agg[ $key ] ) ) {
				$agg[ $key ] = [ 'id' => $rid, 'desc' => $desc, 'is_text' => $is_text, 'count' => 0.0, 'total_agorot' => 0.0 ];
			}
			$agg[ $key ]['count']        += $count;
			$agg[ $key ]['total_agorot'] += $unit_agorot * $count; // signed count handles removals
			if ( $desc !== '' ) {
				$agg[ $key ]['desc'] = $desc;
			}
		}

		// Index existing order line items so we can update them in place (keeps weighable meta).
		$existing = [];
		foreach ( $order->get_items() as $lid => $line ) {
			$p   = $line->get_product();
			$sku = $p ? ( $p->get_sku() ?: (string) $p->get_id() ) : '';
			$k   = ( $sku !== '' ) ? ( 'sku::' . $sku ) : ( 'text::' . $line->get_name() );
			$existing[ $k ] = $lid;
		}

		$applied = 0;
		$seen    = [];
		foreach ( $agg as $key => $e ) {
			$seen[ $key ] = true;
			$net_count  = round( (float) $e['count'], 3 );
			$line_total = round( $e['total_agorot'] ) / 100; // shekels; sign already applied
			if ( $line_total < 0 ) {
				$line_total = 0;
			}

			// Netted out -> remove the existing line if present.
			if ( $net_count <= 0 ) {
				if ( isset( $existing[ $key ] ) ) {
					$order->remove_item( $existing[ $key ] );
				}
				continue;
			}

			if ( isset( $existing[ $key ] ) ) {
				// Update the existing line in place.
				$li = $order->get_item( $existing[ $key ] );
				$li->set_quantity( $net_count );
				$li->set_subtotal( $line_total );
				$li->set_total( $line_total );
				$li->set_subtotal_tax( 0 );
				$li->set_total_tax( 0 );
				$li->save();
			} else {
				$product = null;
				if ( ! $e['is_text'] && $e['id'] !== '' ) {
					$product = self::find_product_by_sku_or_id( $e['id'] );
				}
				if ( $product ) {
					$iid = $order->add_product( $product, $net_count );
					$li  = $iid ? $order->get_item( $iid ) : null;
					if ( $li ) {
						$li->set_subtotal( $line_total );
						$li->set_total( $line_total );
						$li->set_subtotal_tax( 0 );
						$li->set_total_tax( 0 );
						$li->save();
					}
				} else {
					// Aviv-only SKU or TEXT row: custom line. Fall back to SKU/generic name when
					// Aviv sends the description as question marks (its Hebrew is not UTF-8).
					$clean_desc = trim( str_replace( '?', '', (string) $e['desc'] ) );
					if ( $clean_desc !== '' ) {
						$name = $e['desc'];
					} elseif ( ! $e['is_text'] && $e['id'] !== '' ) {
						$name = 'מק"ט ' . $e['id'];
					} else {
						$name = 'פריט מאביב';
					}
					$li = new WC_Order_Item_Product();
					$li->set_name( $name );
					$li->set_quantity( $net_count );
					$li->set_subtotal( $line_total );
					$li->set_total( $line_total );
					$li->set_subtotal_tax( 0 );
					$li->set_total_tax( 0 );
					if ( ! $e['is_text'] && $e['id'] !== '' ) {
						$li->add_meta_data( '_aviv_sku', $e['id'], true );
					}
					$order->add_item( $li );
				}
			}
			$applied++;
		}

		// Remove existing lines Aviv did not mention at all in this final basket.
		foreach ( $existing as $k => $lid ) {
			if ( ! isset( $seen[ $k ] ) ) {
				$order->remove_item( $lid );
			}
		}

		$order->calculate_totals( false );

		// WC_Order::calculate_totals can mis-sum weighable (quantity-0) lines; force the order
		// total from the line items we explicitly set so it always matches Aviv's basket.
		// Aviv is authoritative on the final basket total (agorot). Use it directly so the
		// order total always matches the POS regardless of how WooCommerce re-sums weighable
		// (quantity-0) lines. Fall back to the sum of our line items only if Aviv omits it.
		if ( isset( $data['total'] ) && (float) $data['total'] > 0 ) {
			$order->set_total( round( (float) $data['total'] / 100, 2 ) );
		} else {
			$forced_total = 0.0;
			foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $it ) {
				$forced_total += (float) $it->get_total() + (float) $it->get_total_tax();
			}
			$order->set_total( round( $forced_total, 2 ) );
		}
		$order->save();
		$order->add_order_note( sprintf( __( 'פריטים סונכרנו מ-Aviv POS: %d פריטים בסל הסופי', 'oc-aviv-pos' ), $applied ) );

		$logger->info(
			sprintf( 'Aviv POS Webhook: Order %s items synced from Aviv - %d final items, total %s', $order->get_order_number(), $applied, $order->get_total() ),
			[ 'source' => 'oc-aviv-pos-webhook', 'order_id' => $order->get_id() ]
		);

		// Restore removed hooks.
		foreach ( $removed_hooks as $hook ) {
			add_action( $hook[0], $hook[1], $hook[2] );
		}

		return $applied;
	}

	/**
	 * Find product by SKU or ID.
	 *
	 * @param string $sku_or_id Product SKU or ID.
	 * @return WC_Product|null
	 */
	private static function find_product_by_sku_or_id( string $sku_or_id ): ?WC_Product {
		// Try by ID first
		$product = wc_get_product( $sku_or_id );
		if ( $product ) {
			return $product;
		}

		// Try by SKU
		$product_id = wc_get_product_id_by_sku( $sku_or_id );
		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				return $product;
			}
		}

		return null;
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

