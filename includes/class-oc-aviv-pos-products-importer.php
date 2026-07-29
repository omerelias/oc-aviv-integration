<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing products from Aviv POS CSV files.
 */
class OC_Aviv_Pos_Products_Importer {

	/**
	 * Option key for storing last import log (legacy, not used).
	 */
	const LAST_IMPORT_LOG_OPTION = 'oc_aviv_pos_last_import_log';

	/**
	 * Get the log file path.
	 *
	 * @return string
	 */
	public static function get_log_file_path(): string {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/oc-aviv-pos-logs';
		
		// Create directory if not exists
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			// Add .htaccess to protect logs
			file_put_contents( $log_dir . '/.htaccess', 'Deny from all' );
		}
		
		return $log_dir . '/last-import.json';
	}

	/**
	 * Get the file path for the account's export file.
	 *
	 * @param string $account_id The account ID.
	 * @return string The full file path.
	 */
	public static function get_import_file_path( string $account_id ): string {
		return ABSPATH . $account_id . '/ExportAllSupply.csv';
	}

	/**
	 * Import products from the account's CSV file.
	 *
	 * @param string $account_id The account ID (used as folder name).
	 * @param bool   $update_price Whether to update prices.
	 * @param bool   $update_stock Whether to update stock.
	 * @return array|\WP_Error
	 */
	public static function import_from_account( string $account_id, bool $update_price = true, bool $update_stock = false ) {
		$file_path = self::get_import_file_path( $account_id );

		if ( ! file_exists( $file_path ) ) {
			$error = new WP_Error( 'file_not_found', sprintf( __( 'Export file not found: %s', 'oc-aviv-pos' ), $file_path ) );
			self::save_import_log( [
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'file_path'  => $file_path,
				'timestamp'  => current_time( 'mysql' ),
			] );
			return $error;
		}

		return self::import_from_file( $file_path, $update_price, $update_stock );
	}

	/**
	 * Import products from file.
	 *
	 * @param string $file_path Path to the file.
	 * @param bool   $update_price Whether to update prices.
	 * @param bool   $update_stock Whether to update stock.
	 * @return array|\WP_Error
	 */
	public static function import_from_file( string $file_path, bool $update_price = true, bool $update_stock = false ) {
		$logger = wc_get_logger();
		$ctx    = [ 'source' => 'oc-aviv-pos-products-import' ];

		$start_time = microtime( true );

		// Parse CSV file
		$data = self::parse_csv_file( $file_path );

		if ( is_wp_error( $data ) ) {
			self::save_import_log( [
				'status'     => 'error',
				'message'    => $data->get_error_message(),
				'file_path'  => $file_path,
				'timestamp'  => current_time( 'mysql' ),
			] );
			return $data;
		}

		if ( empty( $data ) ) {
			$error = new WP_Error( 'empty_file', __( 'File is empty or could not be parsed.', 'oc-aviv-pos' ) );
			self::save_import_log( [
				'status'     => 'error',
				'message'    => $error->get_error_message(),
				'file_path'  => $file_path,
				'timestamp'  => current_time( 'mysql' ),
			] );
			return $error;
		}

		// Process products
		$results = [
			'updated'       => 0,
			'not_found'     => 0,
			'skipped'       => 0,
			'errors'        => [],
			'updated_items' => [],
			'not_found_items' => [],
		];

		foreach ( $data as $row_index => $row ) {
			$barcode = $row['barcode'] ?? '';
			$price   = $row['price'] ?? null;
			$stock   = $row['stock'] ?? null;
			$date    = $row['date'] ?? '';

			if ( empty( $barcode ) ) {
				$results['skipped']++;
				continue;
			}

			// Find product by barcode (SKU)
			$product_id = wc_get_product_id_by_sku( $barcode );
			if ( ! $product_id ) {
				$results['not_found']++;
				$results['not_found_items'][] = [
					'barcode' => $barcode,
					'price'   => $price,
					'stock'   => $stock,
					'row'     => $row_index + 2,
				];
				$logger->warning( sprintf( 'Product with barcode %s not found (row %d)', $barcode, $row_index + 2 ), $ctx );
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$results['not_found']++;
				$results['not_found_items'][] = [
					'barcode' => $barcode,
					'price'   => $price,
					'stock'   => $stock,
					'row'     => $row_index + 2,
				];
				$logger->warning( sprintf( 'Product ID %d not found (barcode: %s, row %d)', $product_id, $barcode, $row_index + 2 ), $ctx );
				continue;
			}

			$old_price = $product->get_regular_price();
			$old_stock = $product->get_stock_quantity();
			$changes   = [];

			// Update price
			if ( $update_price && $price !== null ) {
				$product->set_regular_price( $price );
				$product->set_price( $price );
				if ( (float) $old_price !== (float) $price ) {
					$changes['price'] = [ 'old' => $old_price, 'new' => $price ];
				}
			}

			// Update stock
			if ( $update_stock && $stock !== null ) {
				$product->set_stock_quantity( $stock );
				$product->set_manage_stock( true );
				if ( (int) $old_stock !== (int) $stock ) {
					$changes['stock'] = [ 'old' => $old_stock, 'new' => $stock ];
				}
			}

			// Save product
			$product->save();
			$results['updated']++;

			$results['updated_items'][] = [
				'product_id'   => $product_id,
				'product_name' => $product->get_name(),
				'barcode'      => $barcode,
				'price'        => $price,
				'stock'        => $stock,
				'changes'      => $changes,
				'row'          => $row_index + 2,
			];

			$logger->info( sprintf( 'Updated product %d (barcode: %s) - price: %s, stock: %s', $product_id, $barcode, $price, $stock ), $ctx );
		}

		$elapsed_time = round( microtime( true ) - $start_time, 2 );

		$logger->info( sprintf( 'Import completed: %d updated, %d not found, %d skipped in %ss', $results['updated'], $results['not_found'], $results['skipped'], $elapsed_time ), $ctx );

		// Save import log
		$log_data = [
			'status'           => 'success',
			'file_path'        => $file_path,
			'file_modified'    => date( 'Y-m-d H:i:s', filemtime( $file_path ) ),
			'timestamp'        => current_time( 'mysql' ),
			'elapsed_time'     => $elapsed_time,
			'total_rows'       => count( $data ),
			'updated'          => $results['updated'],
			'not_found'        => $results['not_found'],
			'skipped'          => $results['skipped'],
			'updated_items'    => array_slice( $results['updated_items'], 0, 100 ),
			'not_found_items'  => array_slice( $results['not_found_items'], 0, 50 ),
			'update_price'     => $update_price,
			'update_stock'     => $update_stock,
		];

		self::save_import_log( $log_data );

		return $results;
	}

	/**
	 * Parse CSV file with fixed column positions.
	 * Format: barcode,price,stock,date
	 *
	 * @param string $file_path Path to the file.
	 * @return array|\WP_Error
	 */
	private static function parse_csv_file( string $file_path ) {
		$data   = [];
		$handle = fopen( $file_path, 'r' );

		if ( $handle === false ) {
			return new WP_Error( 'csv_open_error', __( 'Could not open CSV file.', 'oc-aviv-pos' ) );
		}

		// Skip the header row (it may have encoding issues, we use fixed positions)
		fgets( $handle );

		// Read data rows
		$row_num = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			$row_num++;

			// Try to convert from Windows-1255 to UTF-8 if needed
			$line = self::convert_encoding( $line );

			$values = str_getcsv( $line );

			// We expect at least 3 columns: barcode, price, stock
			if ( count( $values ) < 3 ) {
				continue;
			}

			$barcode = isset( $values[0] ) ? trim( (string) $values[0] ) : '';
			$price   = isset( $values[1] ) ? self::parse_price( $values[1] ) : null;
			$stock   = isset( $values[2] ) ? self::parse_stock( $values[2] ) : null;
			$date    = isset( $values[3] ) ? trim( (string) $values[3] ) : '';

			// Skip empty barcodes
			if ( empty( $barcode ) ) {
				continue;
			}

			$data[] = [
				'barcode' => $barcode,
				'price'   => $price,
				'stock'   => $stock,
				'date'    => $date,
			];
		}

		fclose( $handle );

		return $data;
	}

	/**
	 * Convert encoding to UTF-8.
	 *
	 * @param string $string The string to convert.
	 * @return string
	 */
	private static function convert_encoding( string $string ): string {
		// Remove BOM if present
		$string = str_replace( "\xEF\xBB\xBF", '', $string );

		// Try to detect encoding
		$encoding = mb_detect_encoding( $string, [ 'UTF-8', 'Windows-1255', 'ISO-8859-8' ], true );

		if ( $encoding && $encoding !== 'UTF-8' ) {
			$string = mb_convert_encoding( $string, 'UTF-8', $encoding );
		}

		return $string;
	}

	/**
	 * Parse price value.
	 *
	 * @param mixed $value Price value from file.
	 * @return float|null
	 */
	private static function parse_price( $value ): ?float {
		if ( $value === null || $value === '' ) {
			return null;
		}

		// Remove currency symbols and spaces
		$value = str_replace( [ '₪', 'NIS', 'ILS', ' ', ',' ], '', (string) $value );
		$value = trim( $value );

		$price = floatval( $value );
		return $price > 0 ? $price : null;
	}

	/**
	 * Parse stock value.
	 *
	 * @param mixed $value Stock value from file.
	 * @return int|null
	 */
	private static function parse_stock( $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}

		$stock = intval( $value );
		return $stock >= 0 ? $stock : null;
	}

	/**
	 * Save import log to file.
	 *
	 * @param array $log_data The log data to save.
	 */
	public static function save_import_log( array $log_data ): void {
		$file_path = self::get_log_file_path();
		$json = wp_json_encode( $log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		
		$result = file_put_contents( $file_path, $json );
		
		if ( $result === false ) {
			$logger = wc_get_logger();
			$logger->error( 'Failed to save import log to file: ' . $file_path, [ 'source' => 'oc-aviv-pos-products-import' ] );
		}
	}

	/**
	 * Get the last import log.
	 *
	 * @return array|null
	 */
	public static function get_last_import_log(): ?array {
		$file_path = self::get_log_file_path();
		
		if ( ! file_exists( $file_path ) ) {
			return null;
		}
		
		$json = file_get_contents( $file_path );
		if ( $json === false ) {
			return null;
		}
		
		$log = json_decode( $json, true );
		return is_array( $log ) ? $log : null;
	}

	/**
	 * Check if import file exists for account.
	 *
	 * @param string $account_id The account ID.
	 * @return array File info or error.
	 */
	public static function get_file_info( string $account_id ): array {
		$file_path = self::get_import_file_path( $account_id );

		if ( ! file_exists( $file_path ) ) {
			return [
				'exists'   => false,
				'path'     => $file_path,
				'message'  => __( 'File not found', 'oc-aviv-pos' ),
			];
		}

		return [
			'exists'       => true,
			'path'         => $file_path,
			'size'         => filesize( $file_path ),
			'size_human'   => size_format( filesize( $file_path ) ),
			'modified'     => date( 'Y-m-d H:i:s', filemtime( $file_path ) ),
			'modified_ago' => human_time_diff( filemtime( $file_path ) ) . ' ' . __( 'ago', 'oc-aviv-pos' ),
		];
	}
}
