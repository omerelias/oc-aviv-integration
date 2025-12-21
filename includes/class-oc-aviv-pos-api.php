<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles HTTP calls to Aviv POS.
 */
class OC_Aviv_Pos_API {

	/**
	 * Send order payload to Aviv POS.
	 *
	 * @param array $payload
	 * @param string $vendor
	 * @param string $account
	 * @param string|null $base_url Optional base URL. If not provided, will use settings or default.
	 *
	 * @return array|\WP_Error
	 */
	public static function send_order( array $payload, string $vendor, string $account, ?string $base_url = null ) {
		if ( null === $base_url ) {
			$settings = OC_Aviv_Pos_Admin::get_settings();
			$base_url = $settings['base_url'] ?? 'http://test.aviv-pos.co.il/api/avivrd';
		}
		$endpoint = rtrim( apply_filters( 'oc_aviv_pos_base_url', $base_url ), '/' );
		$url      = sprintf( '%s/orders/place/%s/%s', $endpoint, rawurlencode( $vendor ), rawurlencode( $account ) );
		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'timeout' => 20,
			'body'    => wp_json_encode( $payload ),
		];

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return $body ?: [];
		}

		// Check if this is "Order already exists" error - this is actually a success case
		if ( $code === 400 && is_array( $body ) ) {
			$error_message = $body['apierror']['message'] ?? $body['apierror']['debugMessage'] ?? '';
			if ( stripos( $error_message, 'already exists' ) !== false || stripos( $error_message, 'already exist' ) !== false ) {
				// Return special success indicator for duplicate order
				return [
					'already_exists' => true,
					'message'        => $error_message,
					'body'           => $body,
				];
			}
		}

		return new WP_Error(
			'oc_aviv_pos_http_error',
			sprintf( 'HTTP %s - %s', $code, wp_remote_retrieve_response_message( $response ) ),
			[ 'body' => $body, 'code' => $code ]
		);
	}
}

