<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to order status transitions and pushes orders to Aviv POS.
 */
class OC_Aviv_Pos_Order_Handler {

	public static function init(): void {
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'maybe_send_order' ], 20, 4 );
	}

	public static function maybe_send_order( $order_id, $old_status, $new_status, $order ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$settings = OC_Aviv_Pos_Admin::get_settings();

		if ( empty( $settings['vendor_id'] ) || empty( $settings['account_id'] ) ) {
			return;
		}

		$selected_statuses = $settings['trigger_statuses'] ?? [];
		$status_key        = 'wc-' . ltrim( $new_status, 'wc-' );

		if ( empty( $selected_statuses ) || ! in_array( $status_key, $selected_statuses, true ) ) {
			return;
		}

		$payload = self::build_payload( $order, $settings );
		$result  = OC_Aviv_Pos_API::send_order( $payload, $settings['vendor_id'], $settings['account_id'] );

		$logger = wc_get_logger();
		$ctx    = [ 'source' => 'oc-aviv-pos', 'order_id' => $order->get_id() ];

		if ( is_wp_error( $result ) ) {
			$logger->error( 'Failed sending order to Aviv POS: ' . $result->get_error_message(), $ctx );
		} else {
			$logger->info( 'Order sent to Aviv POS successfully', $ctx );
			$order->update_meta_data( '_oc_aviv_pos_sent', 'yes' );
			$order->update_meta_data( '_oc_aviv_pos_sent_at', current_time( 'mysql' ) );
			$order->add_order_note( __( 'נשלחה הזמנה ל-Aviv POS.', 'oc-aviv-pos' ) );
			$order->save();
		}
	}

	public static function build_payload( WC_Order $order, array $settings ): array {
		$items = [];

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$id      = $product ? ( $product->get_sku() ?: (string) $product->get_id() ) : (string) $item->get_product_id();

			// Extract cutting-shape variation and append to desc.
			$cutting_shape = self::get_cutting_shape_variation( $item );
			$desc = $item->get_name();
			if ( $cutting_shape ) {
				$desc .= ' ' . $cutting_shape;
			}

			$items[] = [
				'id'            => $id,
				'desc'          => $desc,
				'price'         => (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 ),
				'itemType'      => 'PRODUCT',
				'variations'    => [], // not used in V1; we flatten to comment.
				'comment'       => self::build_item_comment( $item, $settings ),
				'count'         => $item->get_quantity(),
				'orderedCount'  => $item->get_quantity(),
			];
		}

		$payments = self::map_payments( $order, $settings );

		// Parse coordinates from billing_address_coords if available (format: "(lat, lng)").
		$coords_str = $order->get_meta( '_billing_address_coords' );
		$lat = '';
		$lng = '';
		if ( $coords_str && preg_match( '/\(([\d.]+),\s*([\d.]+)\)/', $coords_str, $matches ) ) {
			$lat = $matches[1];
			$lng = $matches[2];
		}

		$address = [
			'country'     => $order->get_billing_country() ?: $order->get_meta( '_billing_country' ),
			'city'        => $order->get_meta( '_billing_city_name' ) ?: $order->get_meta( '_billing_city' ) ?: $order->get_billing_city(),
			'street'      => $order->get_meta( '_billing_street' ) ?: $order->get_billing_address_1(),
			'number'      => $order->get_meta( '_billing_house_num' ) ?: $order->get_billing_address_2(),
			'apt'         => $order->get_meta( '_billing_apartment' ) ?: '',
			'floor'       => $order->get_meta( '_billing_floor' ) ?: '',
			'entrance'    => $order->get_meta( '_billing_enter_code' ) ?: '',
			'comment'     => $order->get_customer_note(),
			'lat'         => $lat,
			'lng'         => $lng,
			'postalCode'  => $order->get_billing_postcode() ?: $order->get_meta( '_billing_postcode' ),
		];

		$delivery_mode = self::get_delivery_mode( $order );
		$delivery_type = $delivery_mode === 'pickup' ? 'PICKUP' : 'DELIVERY';
		$serving_type  = $delivery_mode === 'pickup' ? 'PICKUP' : 'DELIVERY';
		$webhook_url   = $settings['webhook_url'] ?? '';

		return [
			'shareToken'        => (string) $order->get_order_number(),
			'items'             => $items,
			'charges'           => [],
			'comment'           => '',
			'formatCreatedDate' => current_time( 'c' ),
			'formatDeliveryDate'=> self::build_delivery_datetime( $order ),
			'status'            => 'OPEN',
			'contact'           => [
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
				'email'     => $order->get_billing_email(),
				'phone'     => $order->get_billing_phone(),
				'fax'       => '',
			],
			'deliveryType'      => $delivery_type,
			'servingType'       => $serving_type,
			'address'           => $address, 
			'payments'          => $payments,
			'takeoutSets'       => 0,
			'tblNo'             => 0,
			'webhook'           => $webhook_url,
			'checkoutWebhook'   => $webhook_url, // Use same webhook for checkout if provided
			'deliveryCharge'    => (int) round( $order->get_shipping_total() * 100 ),
			'tip'               => 0,
		];
	}

	private static function build_item_comment( WC_Order_Item_Product $item, array $settings ): string {
		$general_sep   = $settings['general_separator'] ?? ' | ';
		$variation_sep = $settings['variation_separator'] ?? ': ';
		$mode          = $settings['comment_mode'] ?? 'variations_and_note';

		$parts = [];
		$note_keys = [
			'Note',
			__( 'Customer notes about the order', 'woocommerce' ),
			'Customer notes about the order',
			'הערות לקוח',
			'הערות לקוח אודות ההזמנה',
		];

		// Keys that identify cutting-shape variation (to exclude from comment).
		$cutting_shape_keys = [
			'pa_cutting-shape',
			'cutting-shape',
			'pa_צורת-חיתוך',
			'צורת-חיתוך',
			'צורת חיתוך',
		];

		// Add quantity info from sale units plugin (like "2 יח, 500 גרם").
		$quantity_in_units = $item->get_meta( '_ocwsu_quantity_in_units', true );
		$unit_weight = $item->get_meta( '_ocwsu_unit_weight', true );
		$quantity = $item->get_quantity();
		
		if ( $quantity_in_units ) {
			// Get unit_weight from item meta, or fallback to product meta.
			if ( ! $unit_weight ) {
				$product_id = $item->get_product_id();
				$_product = wc_get_product( $product_id );
				if ( $_product instanceof WC_Product_Variation ) {
					$product_id = $_product->get_parent_id();
				}
				$unit_weight = get_post_meta( $product_id, '_ocwsu_unit_weight', true );
			}
			
			// Display unit_weight as-is (no calculation).
			if ( $unit_weight ) {
				// unit_weight is stored in kg. Display it directly.
				$qty_display = floatval( $unit_weight );
				$qty_suffix = 'קג';
				if ( $qty_display < 1 ) {
					$qty_display = $qty_display * 1000;
					$qty_suffix = 'גרם';
				}
				// Format: "2 יח, 500 גרם" (unit weight as-is)
				$parts[] = sprintf( '%s יח, %s %s', $quantity_in_units, $qty_display, $qty_suffix );
			} else {
				// Fallback: just show units without weight.
				$parts[] = sprintf( '%s יח', $quantity_in_units );
			}
		} elseif ( $quantity ) {
			// If no units but has weight quantity, check if product is weighable.
			$product_id = $item->get_product_id();
			$_product = wc_get_product( $product_id );
			if ( $_product instanceof WC_Product_Variation ) {
				$product_id = $_product->get_parent_id();
			}
			$weighable = get_post_meta( $product_id, '_ocwsu_weighable', true );
			if ( $weighable === 'yes' ) {
				$qty_display = $quantity;
				$qty_suffix = 'קג';
				if ( $qty_display < 1 ) {
					$qty_display = $qty_display * 1000;
					$qty_suffix = 'גרם';
				}
				$parts[] = sprintf( '%s %s', $qty_display, $qty_suffix );
			}
		}

		if ( in_array( $mode, [ 'variations', 'variations_and_note' ], true ) ) {
			$variation_parts = [];
			$attributes      = $item->get_formatted_meta_data();

			if ( $attributes ) {
				foreach ( $attributes as $meta ) {
					$display_key = wp_strip_all_tags( $meta->display_key ?? '' );
					$meta_key    = $meta->key ?? '';

					// Skip note keys.
					if ( in_array( $display_key, $note_keys, true ) ) {
						continue;
					}

					// Skip cutting-shape variation (it's added to desc instead).
					if ( in_array( $meta_key, $cutting_shape_keys, true ) || in_array( $display_key, $cutting_shape_keys, true ) ) {
						continue;
					}

					$variation_parts[] = sprintf( '%s%s%s', $display_key, $variation_sep, wp_strip_all_tags( $meta->display_value ?? '' ) );
				}
			}

			if ( ! empty( $variation_parts ) ) {
				$parts[] = implode( $general_sep, $variation_parts );
			}
		}

		if ( in_array( $mode, [ 'note', 'variations_and_note' ], true ) ) {
			$note = $item->get_meta( 'Note' );
			if ( ! $note ) {
				$note = $item->get_meta( __( 'Customer notes about the order', 'woocommerce' ) );
			}
			if ( ! $note ) {
				$note = $item->get_meta( 'הערות לקוח' );
			}
			if ( ! $note ) {
				$note = $item->get_meta( 'הערות לקוח אודות ההזמנה' );
			}
			if ( $note ) {
				$parts[] = $note;
			}
		}

		$parts = array_filter( array_unique( $parts ) );

		return trim( implode( $general_sep, $parts ) );
	}

	private static function map_payments( WC_Order $order, array $settings ): array {
		$payment_method = $order->get_payment_method();
		$mappings       = $settings['payment_mapping'] ?? [];
		$mapping        = null;

		foreach ( $mappings as $row ) {
			if ( isset( $row['wc'] ) && $row['wc'] === $payment_method ) {
				$mapping = $row;
				break;
			}
		}

		if ( ! $mapping ) {
			return [];
		}

		$amount = (int) round( $order->get_total() * 100 );
		$mode   = $mapping['mode'] ?? 'PREPAID';

		// CASH mode: already paid on site, send as CASH payment type (no card token).
		if ( $mode === 'CASH' ) {
			return [
				[
					'paymentType' => 'CASH',
					'amount'      => $amount,
				],
			];
		}

		// PREPAID mode: will be charged at POS with token, send as PREPAID payment type.
		$token = $order->get_meta( '_CardcomToken' ) ?: $order->get_meta( '_CardcomTokenId' );
		$card  = $token ? [ 'token' => $token ] : null;

		return [
			[
				'paymentType' => 'PREPAID',
				'amount'      => $amount,
				'card'        => $card,
				'prepaid'     => false, // Will be charged at POS
			],
		];
	}

	/**
	 * Build delivery date-time from shipping meta if available.
	 */
	private static function build_delivery_datetime( WC_Order $order ): string {
		$date_sortable = $order->get_meta( '_ocws_shipping_info_date_sortable' ) ?: $order->get_meta( 'ocws_shipping_info_date_sortable' ); // e.g. 2025/12/16
		$slot_start    = $order->get_meta( '_ocws_shipping_info_slot_start' ) ?: $order->get_meta( 'ocws_shipping_info_slot_start' );    // e.g. 12:00

		if ( $date_sortable ) {
			$date_sortable = str_replace( '/', '-', $date_sortable );
		}

		$dt = null;
		if ( $date_sortable && $slot_start ) {
			$dt = date_create_immutable( $date_sortable . ' ' . $slot_start, wp_timezone() );
		} elseif ( $date_sortable ) {
			$dt = date_create_immutable( $date_sortable . ' 00:00', wp_timezone() );
		}

		if ( $dt ) {
			return $dt->format( DateTime::ATOM );
		}

		return current_time( 'c' );
	}

	/**
	 * Decide delivery/pickup by shipping method id, using settings mapping if available.
	 */
	private static function get_delivery_mode( WC_Order $order ): string {
		$settings = OC_Aviv_Pos_Admin::get_settings();
		$shipping_mapping = $settings['shipping_mapping'] ?? [];
		
		$shipping_items = $order->get_items( 'shipping' );
		foreach ( $shipping_items as $item ) {
			$method_id     = $item->get_method_id();
			$instance_id   = $item->get_instance_id();
			$method_key    = $method_id . ':' . $instance_id;
			
			// Check if there's a manual mapping for this method.
			if ( isset( $shipping_mapping[ $method_key ] ) ) {
				return $shipping_mapping[ $method_key ]; // 'delivery' or 'pickup'
			}
			
			// Fallback to auto-detect by method_id (original logic).
			if ( strpos( $method_id, 'oc_woo_local_pickup_method' ) !== false || strpos( $method_id, 'local_pickup' ) !== false ) {
				return 'pickup';
			}
			if ( strpos( $method_id, 'oc_woo_advanced_shipping_method' ) !== false ) {
				return 'delivery';
			}
		}
		
		// Fallback: if no shipping item, use address presence.
		if ( $order->has_shipping_address() ) {
			return 'delivery';
		}
		return 'pickup';
	}

	/**
	 * Extract cutting-shape variation value from item meta.
	 * Returns the value if found, empty string otherwise.
	 */
	private static function get_cutting_shape_variation( WC_Order_Item_Product $item ): string {
		$cutting_shape_keys = [
			'pa_cutting-shape',
			'cutting-shape',
			'pa_צורת-חיתוך',
			'צורת-חיתוך',
			'צורת חיתוך',
		];

		$attributes = $item->get_formatted_meta_data();
		if ( $attributes ) {
			foreach ( $attributes as $meta ) {
				$meta_key = $meta->key ?? '';
				$display_key = wp_strip_all_tags( $meta->display_key ?? '' );

				// Check if this is a cutting-shape variation.
				if ( in_array( $meta_key, $cutting_shape_keys, true ) || in_array( $display_key, $cutting_shape_keys, true ) ) {
					return wp_strip_all_tags( $meta->display_value ?? '' );
				}
			}
		}

		// Also check direct meta (fallback).
		foreach ( $cutting_shape_keys as $key ) {
			$value = $item->get_meta( $key );
			if ( $value ) {
				return wp_strip_all_tags( $value );
			}
		}

		return '';
	}
}

