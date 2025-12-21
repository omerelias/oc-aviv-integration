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
		add_action( 'wp_ajax_oc_aviv_pos_clear_webhook_logs', [ __CLASS__, 'ajax_clear_webhook_logs' ] );
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
		// Load only on settings page.
		if ( strpos( $hook, 'oc-aviv-pos' ) !== false ) {
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
			'comment'  => __( 'מיפויים', 'oc-aviv-pos' ),
			'payments' => __( 'מיפוי תשלומים', 'oc-aviv-pos' ),
			'webhooks' => __( 'לוג Webhook', 'oc-aviv-pos' ),
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
			<?php if ( $tab === 'webhooks' ) : ?>
				<?php self::render_webhooks_tab(); ?>
			<?php else : ?>
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
			<?php endif; ?>
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
				<th scope="row"><label for="base_url"><?php esc_html_e( 'Base URL', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[base_url]" id="base_url" type="url" class="regular-text" value="<?php echo esc_attr( $settings['base_url'] ?? 'http://test.aviv-pos.co.il/api/avivrd' ); ?>" placeholder="http://test.aviv-pos.co.il/api/avivrd" />
					<p class="description"><?php esc_html_e( 'כתובת הבסיס של ה-API. ברירת מחדל: סביבת בדיקות.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="webhook_url"><?php esc_html_e( 'Webhook URL', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<input name="settings[webhook_url]" id="webhook_url" type="url" class="regular-text" value="<?php echo esc_attr( $settings['webhook_url'] ?? '' ); ?>" placeholder="https://yoursite.com/webhook/aviv-pos" />
					<p class="description"><?php esc_html_e( 'כתובת לקבלת עדכונים מ-Aviv POS (לשימוש עתידי).', 'oc-aviv-pos' ); ?></p>
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
		<h2><?php esc_html_e( 'מבנה הערת פריט', 'oc-aviv-pos' ); ?></h2>
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
		<h2><?php esc_html_e( 'סיווג משלוח', 'oc-aviv-pos' ); ?></h2>
		<p class="description"><?php esc_html_e( 'סווג כל שיטת משלוח כמשלוח או איסוף. אם לא מוגדר, ייעשה שימוש בלוגיקה אוטומטית לפי שם השיטה.', 'oc-aviv-pos' ); ?></p>
		<?php
		$shipping_methods = self::get_all_shipping_methods();
		$shipping_mapping = $settings['shipping_mapping'] ?? [];
		?>
		<table class="widefat fixed striped oc-aviv-pos-shipping-mapping">
			<thead>
			<tr>
				<th><?php esc_html_e( 'שיטת משלוח', 'oc-aviv-pos' ); ?></th>
				<th><?php esc_html_e( 'סוג', 'oc-aviv-pos' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php if ( empty( $shipping_methods ) ) : ?>
				<tr>
					<td colspan="2"><?php esc_html_e( 'לא נמצאו שיטות משלוח', 'oc-aviv-pos' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $shipping_methods as $method_key => $method_data ) : ?>
					<?php
					$current_type = $shipping_mapping[ $method_key ] ?? '';
					// Auto-detect default: if method_id contains pickup -> pickup, if contains advanced_shipping -> delivery
					if ( empty( $current_type ) ) {
						if ( strpos( $method_data['method_id'], 'local_pickup' ) !== false || strpos( $method_data['method_id'], 'oc_woo_local_pickup_method' ) !== false ) {
							$current_type = 'pickup';
						} elseif ( strpos( $method_data['method_id'], 'oc_woo_advanced_shipping_method' ) !== false ) {
							$current_type = 'delivery';
						}
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $method_data['title'] ); ?></strong><br />
							<small style="color: #666;"><?php echo esc_html( $method_key ); ?></small>
						</td>
						<td>
							<select name="settings[shipping_mapping][<?php echo esc_attr( $method_key ); ?>]">
								<option value=""><?php esc_html_e( 'אוטומטי (לפי שם השיטה)', 'oc-aviv-pos' ); ?></option>
								<option value="delivery" <?php selected( $current_type, 'delivery' ); ?>><?php esc_html_e( 'משלוח', 'oc-aviv-pos' ); ?></option>
								<option value="pickup" <?php selected( $current_type, 'pickup' ); ?>><?php esc_html_e( 'איסוף', 'oc-aviv-pos' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
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
		$payment_rows[] = [ 'wc' => '', 'pos_code' => '', 'mode' => 'CASH' ]; // template row at end.
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
							<option value="CASH" <?php selected( $row['mode'] ?? '', 'CASH' ); ?>><?php esc_html_e( 'ישולם באתר', 'oc-aviv-pos' ); ?></option>
							<option value="PREPAID" <?php selected( $row['mode'] ?? '', 'PREPAID' ); ?>><?php esc_html_e( 'ישולם בקופה', 'oc-aviv-pos' ); ?></option>
						</select>
					</td>
					<td><a href="#" class="button remove-row"><?php esc_html_e( 'Remove', 'oc-aviv-pos' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( '"ישולם באתר" = תשלום שכבר נגבה באתר, נשלח כ-CASH ללא טוקן. "ישולם בקופה" = תשלום שייגבה בקופה/שליח עם טוקן אשראי.', 'oc-aviv-pos' ); ?></p>
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
		if ( array_key_exists( 'base_url', $data ) ) {
			$sanitized['base_url'] = esc_url_raw( $data['base_url'] );
		}
		if ( array_key_exists( 'webhook_url', $data ) ) {
			$sanitized['webhook_url'] = esc_url_raw( $data['webhook_url'] );
		}
		if ( array_key_exists( 'trigger_statuses', $data ) ) {
			$sanitized['trigger_statuses'] = array_values( array_filter( array_map( 'sanitize_text_field', $data['trigger_statuses'] ?? [] ) ) );
		}

		// Comment/Mappings tab fields.
		if ( array_key_exists( 'comment_mode', $data ) ) {
			$sanitized['comment_mode'] = sanitize_text_field( $data['comment_mode'] );
		}
		if ( array_key_exists( 'general_separator', $data ) ) {
			$sanitized['general_separator'] = sanitize_text_field( $data['general_separator'] );
		}
		if ( array_key_exists( 'variation_separator', $data ) ) {
			$sanitized['variation_separator'] = sanitize_text_field( $data['variation_separator'] );
		}
		if ( array_key_exists( 'shipping_mapping', $data ) ) {
			$sanitized['shipping_mapping'] = [];
			if ( is_array( $data['shipping_mapping'] ) ) {
				foreach ( $data['shipping_mapping'] as $method_key => $type ) {
					if ( empty( $type ) ) {
						continue; // Skip empty (auto-detect).
					}
					if ( in_array( $type, [ 'delivery', 'pickup' ], true ) ) {
						$sanitized['shipping_mapping'][ sanitize_text_field( $method_key ) ] = $type;
					}
				}
			}
		}

		// Payments tab fields.
		if ( array_key_exists( 'payment_mapping', $data ) ) {
			$sanitized['payment_mapping'] = [];
			if ( is_array( $data['payment_mapping'] ) ) {
				foreach ( $data['payment_mapping'] as $row ) {
					if ( empty( $row['wc'] ) || empty( $row['mode'] ) ) {
						continue;
					}
					$mode = in_array( $row['mode'] ?? 'PREPAID', [ 'CASH', 'PREPAID' ], true ) ? $row['mode'] : 'PREPAID';

					// CASH = already paid on site, send as CASH payment type
					// PREPAID = will be charged at POS with token, send as PREPAID payment type
					$pos_code = $mode === 'CASH' ? 'CASH' : 'PREPAID';

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
			'base_url'           => 'http://test.aviv-pos.co.il/api/avivrd',
			'webhook_url'        => '',
			'trigger_statuses'   => [],
			'comment_mode'       => 'variations_and_note',
			'general_separator'  => ' | ',
			'variation_separator'=> ': ',
			'payment_mapping'    => [],
			'shipping_mapping'   => [],
		];

		$saved = get_option( OC_AVIV_POS_OPTION_KEY, [] );

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get all shipping methods from all zones.
	 *
	 * @return array Array of [method_key => ['method_id' => ..., 'instance_id' => ..., 'title' => ...]]
	 */
	private static function get_all_shipping_methods(): array {
		$methods = [];
		$zones   = WC_Shipping_Zones::get_zones();

		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( ! isset( $method->enabled ) || 'yes' !== $method->enabled ) {
					continue;
				}
				$method_key = $method->id . ':' . $method->instance_id;
				$methods[ $method_key ] = [
					'method_id'   => $method->id,
					'instance_id' => $method->instance_id,
					'title'       => $method->get_title() ?: $method->get_method_title(),
				];
			}
		}

		// Also check the "Rest of the World" zone (zone 0).
		$worldwide_zone = WC_Shipping_Zones::get_zone( 0 );
		if ( $worldwide_zone ) {
			foreach ( $worldwide_zone->get_shipping_methods( true ) as $method ) {
				$method_key = $method->id . ':' . $method->instance_id;
				if ( ! isset( $methods[ $method_key ] ) ) {
					$methods[ $method_key ] = [
						'method_id'   => $method->id,
						'instance_id' => $method->instance_id,
						'title'       => $method->get_title() ?: $method->get_method_title(),
					];
				}
			}
		}

		return $methods;
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
		$base_url  = $settings['base_url'] ?? 'http://test.aviv-pos.co.il/api/avivrd';
		$endpoint  = rtrim( apply_filters( 'oc_aviv_pos_base_url', $base_url ), '/' );
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

		// Check if order already exists - this is actually a success case
		if ( is_array( $result ) && ! empty( $result['already_exists'] ) ) {
			$message = $result['message'] ?? __( 'Order already exists in Aviv POS', 'oc-aviv-pos' );
			$logger->info( 'Order already exists in Aviv POS (manual): ' . $message, $ctx );
			$order->add_order_note( __( 'ההזמנה כבר קיימת ב-Aviv POS (ידנית מדיבאג).', 'oc-aviv-pos' ) );
			
			wp_send_json_success(
				[
					'message'      => $message,
					'already_exists' => true,
					'payload'      => $payload,
					'response'     => $result['body'] ?? $result,
				]
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

	/**
	 * Render webhooks debug tab.
	 */
	private static function render_webhooks_tab(): void {
		$logs = OC_Aviv_Pos_Webhook::get_webhook_logs();
		$webhook_url = OC_Aviv_Pos_Webhook::get_webhook_url();
		?>
		<h2><?php esc_html_e( 'Webhook Endpoint', 'oc-aviv-pos' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'oc-aviv-pos' ); ?></th>
				<td>
					<code style="background: #f0f0f1; padding: 8px 12px; display: inline-block; border-radius: 4px;"><?php echo esc_url( $webhook_url ); ?></code>
					<p class="description"><?php esc_html_e( 'קישור זה נשלח ב-checkoutWebhook כאשר יש POSTPAID payment. Aviv POS יקרא לקישור זה עם עדכוני סטטוס.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
		</table>
		<hr />
		<h2><?php esc_html_e( 'לוג עדכוני Webhook', 'oc-aviv-pos' ); ?></h2>
		<p class="description"><?php esc_html_e( 'כל עדכוני הסטטוס שהתקבלו מהקופה (Aviv POS). הלוג שומר את 200 העדכונים האחרונים.', 'oc-aviv-pos' ); ?></p>
		<p>
			<button type="button" class="button" id="oc_aviv_clear_webhook_logs"><?php esc_html_e( 'נקה לוג', 'oc-aviv-pos' ); ?></button>
			<input type="hidden" id="oc_aviv_clear_logs_nonce" value="<?php echo esc_attr( wp_create_nonce( 'oc_aviv_pos_clear_webhook_logs' ) ); ?>" />
		</p>
		<?php if ( empty( $logs ) ) : ?>
			<p><?php esc_html_e( 'אין עדכונים בלוג עדיין.', 'oc-aviv-pos' ); ?></p>
		<?php else : ?>
			<table class="widefat fixed striped oc-aviv-pos-webhook-logs">
				<thead>
				<tr>
					<th style="width: 150px;"><?php esc_html_e( 'תאריך ושעה', 'oc-aviv-pos' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'מספר הזמנה', 'oc-aviv-pos' ); ?></th>
					<th style="width: 120px;"><?php esc_html_e( 'סטטוס Aviv', 'oc-aviv-pos' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'Order ID', 'oc-aviv-pos' ); ?></th>
					<th><?php esc_html_e( 'נתונים', 'oc-aviv-pos' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$data = $log['data'] ?? [];
					$share_token = $data['shareToken'] ?? $data['_shareToken'] ?? '';
					$status_type = $data['type'] ?? '';
					$has_error = ! empty( $data['_error'] );
					$order_data = $data['data'] ?? '';
					$time_status = $data['timeStatus'] ?? '';
					
					// Try to find order
					$order = null;
					$order_id = '';
					if ( $share_token ) {
						$order = wc_get_order( $share_token );
						
						// If not found by ID, try to find by order number
						if ( ! $order ) {
							$orders = wc_get_orders( [
								'limit' => 1,
								'meta_key' => '_order_number',
								'meta_value' => $share_token,
							] );
							if ( ! empty( $orders ) ) {
								$order = $orders[0];
							}
						}
						
						// If still not found, try by order number directly
						if ( ! $order ) {
							$orders = wc_get_orders( [
								'limit' => 100,
								'return' => 'ids',
							] );
							foreach ( $orders as $oid ) {
								$order_obj = wc_get_order( $oid );
								if ( $order_obj && (string) $order_obj->get_order_number() === $share_token ) {
									$order = $order_obj;
									break;
								}
							}
						}
						
						if ( $order ) {
							$order_id = $order->get_id();
						}
					}
					?>
					<tr <?php echo $has_error ? 'style="background: #fef2f2;"' : ''; ?>>
						<td>
							<?php echo esc_html( date_i18n( 'd/m/Y H:i:s', strtotime( $log['timestamp'] ) ) ); ?>
						</td>
						<td>
							<strong><?php echo esc_html( $share_token ?: '-' ); ?></strong>
						</td>
						<td>
							<?php if ( $has_error ) : ?>
								<span class="oc-aviv-status-badge" style="background: #f8d7da; color: #842029;">
									<?php echo esc_html( $data['_error'] ?? 'ERROR' ); ?>
								</span>
							<?php elseif ( $status_type ) : ?>
								<span class="oc-aviv-status-badge oc-aviv-status-<?php echo esc_attr( strtolower( $status_type ) ); ?>">
									<?php echo esc_html( $status_type ); ?>
								</span>
							<?php else : ?>
								<span style="color: #999;">-</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $order_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" target="_blank">
									<?php echo esc_html( $order_id ); ?>
								</a>
							<?php else : ?>
								<span style="color: #999;"><?php esc_html_e( 'לא נמצא', 'oc-aviv-pos' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<details>
								<summary style="cursor: pointer; color: #2271b1;"><?php esc_html_e( 'הצג פרטים', 'oc-aviv-pos' ); ?></summary>
								<div style="margin-top: 10px;">
									<strong style="display: block; margin-bottom: 5px; color: #1d2327;"><?php esc_html_e( 'בקשה מ-Aviv POS:', 'oc-aviv-pos' ); ?></strong>
									<pre style="background: #f0f0f1; padding: 10px; margin-top: 5px; border-radius: 4px; overflow-x: auto; font-size: 12px;"><?php echo esc_html( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
								</div>
								<?php if ( ! empty( $log['response'] ) ) : ?>
									<div style="margin-top: 15px;">
										<strong style="display: block; margin-bottom: 5px; color: #1d2327;"><?php esc_html_e( 'תשובה ל-Aviv POS:', 'oc-aviv-pos' ); ?></strong>
										<pre style="background: #e7f5e7; padding: 10px; margin-top: 5px; border-radius: 4px; overflow-x: auto; font-size: 12px; border: 1px solid #c3e6c3;"><?php echo esc_html( wp_json_encode( $log['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $log['ip'] ) ) : ?>
									<p style="margin: 10px 0 5px 0; font-size: 11px; color: #666;">
										<strong><?php esc_html_e( 'IP:', 'oc-aviv-pos' ); ?></strong> <?php echo esc_html( $log['ip'] ); ?>
									</p>
								<?php endif; ?>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX handler to clear webhook logs.
	 */
	public static function ajax_clear_webhook_logs(): void {
		check_ajax_referer( 'oc_aviv_pos_clear_webhook_logs', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'oc-aviv-pos' ) ], 403 );
		}

		OC_Aviv_Pos_Webhook::clear_webhook_logs();

		wp_send_json_success( [ 'message' => __( 'Webhook logs cleared', 'oc-aviv-pos' ) ] );
	}

}

