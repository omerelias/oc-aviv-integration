<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing products from Excel/CSV files.
 */
class OC_Aviv_Pos_Products_Importer {

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

		// Detect file type
		$file_ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Parse file based on extension
		$data = null;
		if ( in_array( $file_ext, [ 'xlsx', 'xls' ], true ) ) {
			$data = self::parse_excel_file( $file_path );
		} elseif ( $file_ext === 'csv' ) {
			$data = self::parse_csv_file( $file_path );
		} else {
			return new WP_Error( 'invalid_file_type', __( 'Unsupported file type. Please use Excel (.xlsx, .xls) or CSV (.csv) files.', 'oc-aviv-pos' ) );
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'empty_file', __( 'File is empty or could not be parsed.', 'oc-aviv-pos' ) );
		}

		// Process products
		$results = [
			'updated' => 0,
			'not_found' => 0,
			'errors' => [],
		];

		foreach ( $data as $row_index => $row ) {
			$barcode = isset( $row['ברקוד'] ) ? trim( (string) $row['ברקוד'] ) : '';
			$price = isset( $row['מחיר מכירה'] ) ? self::parse_price( $row['מחיר מכירה'] ) : null;
			$stock = isset( $row['מלאי'] ) ? self::parse_stock( $row['מלאי'] ) : null;

			if ( empty( $barcode ) ) {
				continue; // Skip rows without barcode
			}

			// Find product by barcode (SKU)
			$product_id = wc_get_product_id_by_sku( $barcode );
			if ( ! $product_id ) {
				$results['not_found']++;
				$logger->warning( sprintf( 'Product with barcode %s not found (row %d)', $barcode, $row_index + 1 ), $ctx );
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$results['not_found']++;
				$logger->warning( sprintf( 'Product ID %d not found (barcode: %s, row %d)', $product_id, $barcode, $row_index + 1 ), $ctx );
				continue;
			}

			// Update price
			if ( $update_price && $price !== null ) {
				$product->set_regular_price( $price );
				$product->set_price( $price );
				$logger->info( sprintf( 'Updated price for product %d (barcode: %s) to %s', $product_id, $barcode, $price ), $ctx );
			}

			// Update stock
			if ( $update_stock && $stock !== null ) {
				$product->set_stock_quantity( $stock );
				$product->set_manage_stock( true );
				$logger->info( sprintf( 'Updated stock for product %d (barcode: %s) to %d', $product_id, $barcode, $stock ), $ctx );
			}

			// Save product
			$product->save();
			$results['updated']++;
		}

		$logger->info( sprintf( 'Import completed: %d updated, %d not found', $results['updated'], $results['not_found'] ), $ctx );

		return $results;
	}

	/**
	 * Parse Excel file (XLSX/XLS).
	 *
	 * @param string $file_path Path to the file.
	 * @return array|\WP_Error
	 */
	private static function parse_excel_file( string $file_path ) {
		// Try to use PhpSpreadsheet if available
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return self::parse_excel_phpspreadsheet( $file_path );
		}

		// Fallback: try to read as CSV if it's actually a CSV file
		// Or return error suggesting to use CSV
		return new WP_Error( 'excel_not_supported', __( 'Excel files require PhpSpreadsheet library. Please convert to CSV or install PhpSpreadsheet.', 'oc-aviv-pos' ) );
	}

	/**
	 * Parse Excel file using PhpSpreadsheet.
	 *
	 * @param string $file_path Path to the file.
	 * @return array|\WP_Error
	 */
	private static function parse_excel_phpspreadsheet( string $file_path ) {
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file_path );
			$worksheet = $spreadsheet->getActiveSheet();
			$data = [];

			// Get headers from first row
			$headers = [];
			$highest_column = $worksheet->getHighestColumn();
			$highest_row = $worksheet->getHighestRow();

			// Read first row as headers
			for ( $col = 'A'; $col <= $highest_column; $col++ ) {
				$cell_value = $worksheet->getCell( $col . '1' )->getValue();
				$headers[ $col ] = $cell_value ? trim( (string) $cell_value ) : '';
			}

			// Read data rows
			for ( $row = 2; $row <= $highest_row; $row++ ) {
				$row_data = [];
				foreach ( $headers as $col => $header ) {
					if ( empty( $header ) ) {
						continue;
					}
					$cell_value = $worksheet->getCell( $col . $row )->getValue();
					$row_data[ $header ] = $cell_value ? trim( (string) $cell_value ) : '';
				}
				if ( ! empty( $row_data ) ) {
					$data[] = $row_data;
				}
			}

			return $data;
		} catch ( Exception $e ) {
			return new WP_Error( 'excel_parse_error', sprintf( __( 'Error parsing Excel file: %s', 'oc-aviv-pos' ), $e->getMessage() ) );
		}
	}

	/**
	 * Parse CSV file.
	 *
	 * @param string $file_path Path to the file.
	 * @return array|\WP_Error
	 */
	private static function parse_csv_file( string $file_path ) {
		$data = [];
		$handle = fopen( $file_path, 'r' );

		if ( $handle === false ) {
			return new WP_Error( 'csv_open_error', __( 'Could not open CSV file.', 'oc-aviv-pos' ) );
		}

		// Detect encoding and BOM
		$first_line = fgets( $handle );
		rewind( $handle );

		// Remove BOM if present
		if ( substr( $first_line, 0, 3 ) === "\xEF\xBB\xBF" ) {
			// UTF-8 BOM detected
			$encoding = 'UTF-8';
		} else {
			// Try to detect encoding
			$encoding = mb_detect_encoding( $first_line, [ 'UTF-8', 'Windows-1255', 'ISO-8859-8' ], true );
			if ( ! $encoding ) {
				$encoding = 'UTF-8'; // Default
			}
		}

		// Read headers from first row
		$headers_line = fgets( $handle );
		if ( $headers_line === false ) {
			fclose( $handle );
			return new WP_Error( 'csv_empty', __( 'CSV file is empty.', 'oc-aviv-pos' ) );
		}

		// Remove BOM if present
		$headers_line = str_replace( "\xEF\xBB\xBF", '', $headers_line );

		// Convert encoding if needed
		if ( $encoding !== 'UTF-8' ) {
			$headers_line = mb_convert_encoding( $headers_line, 'UTF-8', $encoding );
		}

		$headers = str_getcsv( $headers_line );
		$headers = array_map( 'trim', $headers );

		// Read data rows
		while ( ( $row = fgets( $handle ) ) !== false ) {
			// Convert encoding if needed
			if ( $encoding !== 'UTF-8' ) {
				$row = mb_convert_encoding( $row, 'UTF-8', $encoding );
			}

			$values = str_getcsv( $row );
			if ( count( $values ) !== count( $headers ) ) {
				continue; // Skip malformed rows
			}

			$row_data = [];
			foreach ( $headers as $index => $header ) {
				$row_data[ $header ] = isset( $values[ $index ] ) ? trim( $values[ $index ] ) : '';
			}

			if ( ! empty( $row_data ) ) {
				$data[] = $row_data;
			}
		}

		fclose( $handle );

		return $data;
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
}
