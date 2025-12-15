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
			$order->add_order_note( __( 'נשלחה הזמנה ל-Aviv POS.', 'oc-aviv-pos' ) );
		}
	}

	public static function build_payload( WC_Order $order, array $settings ): array {
		$items = [];

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$id      = $product ? ( $product->get_sku() ?: (string) $product->get_id() ) : (string) $item->get_product_id();

			$items[] = [
				'id'            => $id,
				'desc'          => $item->get_name(),
				'price'         => (int) round( ( $item->get_total() + $item->get_total_tax() ) * 100 ),
				'itemType'      => 'PRODUCT',
				'variations'    => [], // not used in V1; we flatten to comment.
				'comment'       => self::build_item_comment( $item, $settings ),
				'count'         => (int) $item->get_quantity(),
				'orderedCount'  => (int) $item->get_quantity(),
			];
		}

		$payments = self::map_payments( $order, $settings );

		$address = [
			'country'     => $order->get_billing_country(),
			'city'        => $order->get_billing_city(),
			'street'      => $order->get_billing_address_1(),
			'number'      => $order->get_billing_address_2(),
			'apt'         => '',
			'floor'       => '',
			'entrance'    => '',
			'comment'     => $order->get_customer_note(),
			'lat'         => '',
			'lng'         => '',
			'postalCode'  => $order->get_billing_postcode(),
		];

		return [
			'shareToken'       => (string) $order->get_order_number(),
			'items'            => $items,
			'charges'          => [],
			'comment'          => '',
			'formatCreatedDate'=> current_time( 'c' ),
			'formatDeliveryDate'=> current_time( 'c' ),
			'status'           => 'OPEN',
			'contact'          => [
				'firstName' => $order->get_billing_first_name(),
				'lastName'  => $order->get_billing_last_name(),
				'email'     => $order->get_billing_email(),
				'phone'     => $order->get_billing_phone(),
				'fax'       => '',
			],
			'deliveryType'     => $order->has_shipping_address() ? 'DELIVERY' : 'PICKUP',
			'servingType'      => $order->has_shipping_address() ? 'DELIVERY' : 'TAKEAWAY',
			'address'          => $address, 
			'payments'         => $payments,
			'takeoutSets'      => 0,
			'tblNo'            => 0,
			'webhook'          => '',
			'checkoutWebhook'  => '',
			'deliveryCharge'   => (int) round( $order->get_shipping_total() * 100 ),
			'tip'              => 0,
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

		if ( in_array( $mode, [ 'variations', 'variations_and_note' ], true ) ) {
			$variation_parts = [];
			$attributes      = $item->get_formatted_meta_data();

			if ( $attributes ) {
				foreach ( $attributes as $meta ) {
					if ( in_array( wp_strip_all_tags( $meta->display_key ), $note_keys, true ) ) {
						continue;
					}
					$variation_parts[] = sprintf( '%s%s%s', wp_strip_all_tags( $meta->display_key ), $variation_sep, wp_strip_all_tags( $meta->display_value ) );
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
				$parts[] = sprintf( '%s%s%s', __( 'הערות לקוח', 'oc-aviv-pos' ), $variation_sep, $note );
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

		return [
			[
				'paymentType' => $mapping['pos_code'],
				'amount'      => $amount,
				'card'        => null,
				'prepaid'     => $mapping['mode'] === 'PREPAID',
			],
		];
	}
}

