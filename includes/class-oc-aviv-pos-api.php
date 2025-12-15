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
	 *
	 * @return array|\WP_Error
	 */
	public static function send_order( array $payload, string $vendor, string $account ) {
		$endpoint = rtrim( apply_filters( 'oc_aviv_pos_base_url', 'http://test.aviv-pos.co.il/api/avivrd' ), '/' );
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

		return new WP_Error(
			'oc_aviv_pos_http_error',
			sprintf( 'HTTP %s - %s', $code, wp_remote_retrieve_response_message( $response ) ),
			[ 'body' => $body, 'code' => $code ]
		);
	}
}

