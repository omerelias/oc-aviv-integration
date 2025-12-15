<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for Aviv POS integration.
 */
class OC_Aviv_Pos_Admin {  

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_ajax_oc_aviv_pos_debug', [ __CLASS__, 'ajax_debug_payload' ] );
		add_action( 'wp_ajax_oc_aviv_pos_send_order', [ __CLASS__, 'ajax_send_order' ] );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Aviv POS', 'oc-aviv-pos' ),
			__( 'Aviv POS', 'oc-aviv-pos' ),
			'manage_woocommerce',
			'oc-aviv-pos',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'oc-aviv-pos' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'oc-aviv-pos-admin',
			OC_AVIV_POS_URL . 'assets/admin.css',
			[],
			OC_AVIV_POS_VERSION
		);

		wp_enqueue_script(
			'oc-aviv-pos-admin',
			OC_AVIV_POS_URL . 'assets/admin.js',
			[ 'jquery' ],
			OC_AVIV_POS_VERSION,
			true
		);

		wp_localize_script(
			'oc-aviv-pos-admin',
			'ocAvivPosAdmin',
			[
				'addRow'    => __( 'Add mapping', 'oc-aviv-pos' ),
				'removeRow' => __( 'Remove', 'oc-aviv-pos' ),
				'loading'   => __( 'Loading…', 'oc-aviv-pos' ),
			]
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page', 'oc-aviv-pos' ) );
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'api';
		$settings = self::get_settings();

		if ( isset( $_POST['oc_aviv_pos_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['oc_aviv_pos_nonce'] ) ), 'oc_aviv_pos_save' ) ) {
			$settings = self::save_settings( $settings );
			printf( '<div class="updated"><p>%s</p></div>', esc_html__( 'Settings saved.', 'oc-aviv-pos' ) );
		}

		$tabs = [
			'api'      => __( 'חיבור API וטריגרים', 'oc-aviv-pos' ),
			'comment'  => __( 'יצירת הזמנה בקופה', 'oc-aviv-pos' ),
			'payments' => __( 'מיפוי תשלומים', 'oc-aviv-pos' ),
		];

		?>
		<div class="wrap oc-aviv-pos">
			<h1><?php esc_html_e( 'Aviv POS Integration (V1)', 'oc-aviv-pos' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=oc-aviv-pos&tab=' . $tab_key ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<form method="post">
				<?php wp_nonce_field( 'oc_aviv_pos_save', 'oc_aviv_pos_nonce' ); ?>
				<?php
				switch ( $tab ) {
					case 'comment':
						self::render_comment_tab( $settings );
						break;
					case 'payments':
						self::render_payments_tab( $settings );
						break;
					case 'api':
					default:
						self::render_api_tab( $settings );
				}
				?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_api_tab( array $settings ): void {
		$statuses = wc_get_order_statuses();
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="vendor_id"><?php esc_html_e( 'Vendor ID', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[vendor_id]" id="vendor_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['vendor_id'] ?? '' ); ?>" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="account_id"><?php esc_html_e( 'Account ID', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[account_id]" id="account_id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['account_id'] ?? '' ); ?>" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'סטטוס הזמנה לטריגר', 'oc-aviv-pos' ); ?></th>
				<td>
					<select name="settings[trigger_statuses][]" multiple="multiple" style="min-width:240px;">
						<?php foreach ( $statuses as $status_key => $status_label ) : ?>
							<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( in_array( $status_key, $settings['trigger_statuses'] ?? [], true ) ); ?>>
								<?php echo esc_html( $status_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'ההזמנה תישלח ל-Aviv POS כשהיא נעה לאחד מהסטטוסים שנבחרו.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_comment_tab( array $settings ): void {
		$comment_mode = $settings['comment_mode'] ?? 'variations_and_note';
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'מבנה הערת פריט (comment)', 'oc-aviv-pos' ); ?></th>
				<td>
					<select name="settings[comment_mode]" id="comment_mode">
						<option value="variations" <?php selected( $comment_mode, 'variations' ); ?>><?php esc_html_e( 'וריאציות בלבד', 'oc-aviv-pos' ); ?></option>
						<option value="note" <?php selected( $comment_mode, 'note' ); ?>><?php esc_html_e( 'הערה חופשית בלבד', 'oc-aviv-pos' ); ?></option>
						<option value="variations_and_note" <?php selected( $comment_mode, 'variations_and_note' ); ?>><?php esc_html_e( 'וריאציות + הערה', 'oc-aviv-pos' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="general_separator"><?php esc_html_e( 'תו הפרדה (כללי)', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[general_separator]" id="general_separator" type="text" class="regular-text" value="<?php echo esc_attr( $settings['general_separator'] ?? ' | ' ); ?>" placeholder=" | " />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="variation_separator"><?php esc_html_e( 'תו הפרדה (וריאציות)', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[variation_separator]" id="variation_separator" type="text" class="regular-text" value="<?php echo esc_attr( $settings['variation_separator'] ?? ': ' ); ?>" placeholder=": " />
				</td>
			</tr>
		</table>
		<hr />
		<h2><?php esc_html_e( 'כלי דיבאג להזמנה', 'oc-aviv-pos' ); ?></h2>
		<p class="description"><?php esc_html_e( 'בחר הזמנה והצג את ה-payload או הבקשה המלאה בלי לשלוח ל-POS.', 'oc-aviv-pos' ); ?></p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="oc_aviv_debug_order"><?php esc_html_e( 'Order ID', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input type="number" id="oc_aviv_debug_order" min="1" class="regular-text" placeholder="123" />
					<button type="button" class="button" id="oc_aviv_show_payload"><?php esc_html_e( 'Show payload', 'oc-aviv-pos' ); ?></button>
					<button type="button" class="button" id="oc_aviv_show_request"><?php esc_html_e( 'Show full request', 'oc-aviv-pos' ); ?></button>
					<button type="button" class="button button-primary" id="oc_aviv_send_order"><?php esc_html_e( 'Send Order', 'oc-aviv-pos' ); ?></button>
					<input type="hidden" id="oc_aviv_debug_nonce" value="<?php echo esc_attr( wp_create_nonce( 'oc_aviv_pos_debug' ) ); ?>" />
					<input type="hidden" id="oc_aviv_send_nonce" value="<?php echo esc_attr( wp_create_nonce( 'oc_aviv_pos_send_order' ) ); ?>" />
					<div class="oc-aviv-debug-panels">
						<div>
							<strong><?php esc_html_e( 'Payload', 'oc-aviv-pos' ); ?></strong>
							<pre id="oc_aviv_debug_payload"></pre>
						</div>
						<div>
							<strong><?php esc_html_e( 'Request', 'oc-aviv-pos' ); ?></strong>
							<pre id="oc_aviv_debug_request"></pre>
						</div>
						<div>
							<strong><?php esc_html_e( 'Response', 'oc-aviv-pos' ); ?></strong>
							<pre id="oc_aviv_debug_response"></pre>
						</div>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function render_payments_tab( array $settings ): void {
		$gateways       = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
		$payment_rows   = $settings['payment_mapping'] ?? [];
		$payment_rows[] = [ 'wc' => '', 'pos_code' => '', 'mode' => 'PREPAID' ]; // template row at end.
		?>
		<table class="widefat fixed striped oc-aviv-pos-mapping">
			<thead>
			<tr>
				<th><?php esc_html_e( 'WooCommerce Gateway', 'oc-aviv-pos' ); ?></th>
				<th><?php esc_html_e( 'מצב חיוב', 'oc-aviv-pos' ); ?></th>
				<th></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $payment_rows as $index => $row ) : ?>
				<tr>
					<td>
						<select name="settings[payment_mapping][<?php echo esc_attr( $index ); ?>][wc]">
							<option value=""><?php esc_html_e( 'Select…', 'oc-aviv-pos' ); ?></option>
							<?php foreach ( $gateways as $gateway_id => $gateway ) : ?>
								<option value="<?php echo esc_attr( $gateway_id ); ?>" <?php selected( $row['wc'] ?? '', $gateway_id ); ?>>
									<?php echo esc_html( $gateway->get_title() . ' (' . $gateway_id . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="settings[payment_mapping][<?php echo esc_attr( $index ); ?>][mode]">
							<option value="PREPAID" <?php selected( $row['mode'] ?? '', 'PREPAID' ); ?>><?php esc_html_e( 'שולם באתר (אשראי טוקן) - PREPAID', 'oc-aviv-pos' ); ?></option>
							<option value="POSTPAID" <?php selected( $row['mode'] ?? '', 'POSTPAID' ); ?>><?php esc_html_e( 'לא שולם – יחויב בקופה (טוקן אשראי)', 'oc-aviv-pos' ); ?></option>
						</select>
					</td>
					<td><a href="#" class="button remove-row"><?php esc_html_e( 'Remove', 'oc-aviv-pos' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'PREPAID = שולם באתר, נשלח כ-PREPAID (טוקן אם קיים). POSTPAID = לא שולם, נשלח כ-PREPAID עם טוקן לחיוב בקופה.', 'oc-aviv-pos' ); ?></p>
		<p><a href="#" class="button add-row"><?php esc_html_e( 'Add mapping', 'oc-aviv-pos' ); ?></a></p>
		<?php
	}

	private static function save_settings( array $existing ): array {
		$data = wp_unslash( $_POST['settings'] ?? [] );

		// Start with existing settings to avoid wiping fields from other tabs.
		$sanitized = $existing;

		// API tab fields.
		if ( array_key_exists( 'vendor_id', $data ) ) {
			$sanitized['vendor_id'] = sanitize_text_field( $data['vendor_id'] );
		}
		if ( array_key_exists( 'account_id', $data ) ) {
			$sanitized['account_id'] = sanitize_text_field( $data['account_id'] );
		}
		if ( array_key_exists( 'trigger_statuses', $data ) ) {
			$sanitized['trigger_statuses'] = array_values( array_filter( array_map( 'sanitize_text_field', $data['trigger_statuses'] ?? [] ) ) );
		}

		// Comment tab fields.
		if ( array_key_exists( 'comment_mode', $data ) ) {
			$sanitized['comment_mode'] = sanitize_text_field( $data['comment_mode'] );
		}
		if ( array_key_exists( 'general_separator', $data ) ) {
			$sanitized['general_separator'] = sanitize_text_field( $data['general_separator'] );
		}
		if ( array_key_exists( 'variation_separator', $data ) ) {
			$sanitized['variation_separator'] = sanitize_text_field( $data['variation_separator'] );
		}

		// Payments tab fields.
		if ( array_key_exists( 'payment_mapping', $data ) ) {
			$sanitized['payment_mapping'] = [];
			if ( is_array( $data['payment_mapping'] ) ) {
				foreach ( $data['payment_mapping'] as $row ) {
					if ( empty( $row['wc'] ) || empty( $row['mode'] ) ) {
						continue;
					}
					$mode   = in_array( $row['mode'] ?? 'PREPAID', [ 'PREPAID', 'POSTPAID' ], true ) ? $row['mode'] : 'PREPAID';

					// Both modes use PREPAID paymentType; POSTPAID just means charge later with token.
					$pos_code = 'PREPAID';

					$sanitized['payment_mapping'][] = [
						'wc'       => sanitize_text_field( $row['wc'] ),
						'pos_code' => $pos_code,
						'mode'     => $mode,
					];
				}
			}
		}

		update_option( OC_AVIV_POS_OPTION_KEY, $sanitized );

		return $sanitized;
	}

	public static function get_settings(): array {
		$defaults = [
			'vendor_id'          => '',
			'account_id'         => '',
			'trigger_statuses'   => [],
			'comment_mode'       => 'variations_and_note',
			'general_separator'  => ' | ',
			'variation_separator'=> ': ',
			'payment_mapping'    => [],
		];

		$saved = get_option( OC_AVIV_POS_OPTION_KEY, [] );

		return wp_parse_args( $saved, $defaults );
	}

	public static function ajax_debug_payload(): void {
		check_ajax_referer( 'oc_aviv_pos_debug', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Order ID is required', 'oc-aviv-pos' ) ], 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found', 'oc-aviv-pos' ) ], 404 );
		}

		$settings = self::get_settings();
		if ( empty( $settings['vendor_id'] ) || empty( $settings['account_id'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Vendor ID or Account ID missing in settings', 'oc-aviv-pos' ) ], 400 );
		}

		$payload   = OC_Aviv_Pos_Order_Handler::build_payload( $order, $settings );
		$endpoint  = rtrim( apply_filters( 'oc_aviv_pos_base_url', 'http://test.aviv-pos.co.il/api/avivrd' ), '/' );
		$url       = sprintf( '%s/orders/place/%s/%s', $endpoint, rawurlencode( $settings['vendor_id'] ), rawurlencode( $settings['account_id'] ) );
		$request   = [
			'method'  => 'POST',
			'url'     => $url,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => $payload,
		];

		wp_send_json_success(
			[
				'payload' => $payload,
				'request' => $request,
			]
		);
	}

	public static function ajax_send_order(): void {
		check_ajax_referer( 'oc_aviv_pos_send_order', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Order ID is required', 'oc-aviv-pos' ) ], 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found', 'oc-aviv-pos' ) ], 404 );
		}

		$settings = self::get_settings();
		if ( empty( $settings['vendor_id'] ) || empty( $settings['account_id'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Vendor ID or Account ID missing in settings', 'oc-aviv-pos' ) ], 400 );
		}

		$payload = OC_Aviv_Pos_Order_Handler::build_payload( $order, $settings );
		$result  = OC_Aviv_Pos_API::send_order( $payload, $settings['vendor_id'], $settings['account_id'] );

		$logger = wc_get_logger();
		$ctx    = [ 'source' => 'oc-aviv-pos', 'order_id' => $order->get_id() ];

		if ( is_wp_error( $result ) ) {
			$logger->error( 'Failed sending order to Aviv POS (manual): ' . $result->get_error_message(), $ctx );
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'payload' => $payload,
					'response' => null,
				],
				500
			);
		}

		$logger->info( 'Order sent to Aviv POS successfully (manual)', $ctx );
		$order->add_order_note( __( 'נשלחה הזמנה ל-Aviv POS (ידנית מדיבאג).', 'oc-aviv-pos' ) );

		wp_send_json_success(
			[
				'message' => __( 'Order sent successfully', 'oc-aviv-pos' ),
				'payload' => $payload,
				'response' => $result,
			]
		);
	}
}

