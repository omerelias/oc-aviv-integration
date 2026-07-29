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
		add_action( 'wp_ajax_oc_aviv_pos_import_products', [ __CLASS__, 'ajax_import_products' ] );
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
			'products' => __( 'ייבוא מוצרים', 'oc-aviv-pos' ),
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
						case 'products':
							self::render_products_import_tab( $settings );
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

		// Products import tab fields.
		if ( array_key_exists( 'products_import_enabled', $data ) ) {
			$sanitized['products_import_enabled'] = ! empty( $data['products_import_enabled'] ) ? 'yes' : 'no';
		}
		if ( array_key_exists( 'products_import_update_price', $data ) ) {
			$sanitized['products_import_update_price'] = ! empty( $data['products_import_update_price'] ) ? 'yes' : 'no';
		}
		if ( array_key_exists( 'products_import_update_stock', $data ) ) {
			$sanitized['products_import_update_stock'] = ! empty( $data['products_import_update_stock'] ) ? 'yes' : 'no';
		}
		if ( array_key_exists( 'products_import_file_url', $data ) ) {
			$sanitized['products_import_file_url'] = esc_url_raw( $data['products_import_file_url'] );
		}
		if ( array_key_exists( 'products_import_cron_schedule', $data ) ) {
			$old_schedule = $existing['products_import_cron_schedule'] ?? 'disabled';
			$new_schedule = sanitize_text_field( $data['products_import_cron_schedule'] );
			$sanitized['products_import_cron_schedule'] = $new_schedule;
			
			// Update cron if schedule changed
			if ( $old_schedule !== $new_schedule ) {
				self::update_cron_schedule( $new_schedule );
			}
		}

		update_option( OC_AVIV_POS_OPTION_KEY, $sanitized );

		return $sanitized;
	}

	/**
	 * Update cron schedule for products import.
	 *
	 * @param string $schedule The schedule name or 'disabled'.
	 */
	private static function update_cron_schedule( string $schedule ): void {
		// Clear existing cron
		wp_clear_scheduled_hook( OC_AVIV_POS_CRON_HOOK );
		
		// If disabled, just return
		if ( $schedule === 'disabled' || empty( $schedule ) ) {
			return;
		}
		
		// Valid schedules
		$valid_schedules = [ 'every_5_minutes', 'every_15_minutes', 'every_30_minutes', 'hourly', 'twicedaily', 'daily' ];
		
		if ( ! in_array( $schedule, $valid_schedules, true ) ) {
			return;
		}
		
		// Schedule new cron
		wp_schedule_event( time(), $schedule, OC_AVIV_POS_CRON_HOOK );
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
			'products_import_enabled' => 'no',
			'products_import_update_price' => 'yes',
			'products_import_update_stock' => 'no',
			'products_import_file_url' => '',
			'products_import_cron_schedule' => 'disabled',
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
								if ( $order_obj instanceof WC_Order && (string) $order_obj->get_order_number() === $share_token ) {
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

	/**
	 * Render products import tab.
	 */
	private static function render_products_import_tab( array $settings ): void {
		$enabled      = $settings['products_import_enabled'] ?? 'no';
		$update_price = $settings['products_import_update_price'] ?? 'yes';
		$update_stock = $settings['products_import_update_stock'] ?? 'no';
		$account_id   = $settings['account_id'] ?? '';

		// Get file info
		require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-products-importer.php';
		$file_info  = $account_id ? OC_Aviv_Pos_Products_Importer::get_file_info( $account_id ) : null;
		$last_log   = OC_Aviv_Pos_Products_Importer::get_last_import_log();
		?>
		<h2><?php esc_html_e( 'הגדרות ייבוא מוצרים', 'oc-aviv-pos' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="products_import_enabled"><?php esc_html_e( 'הפעל ייבוא מוצרים', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="settings[products_import_enabled]" id="products_import_enabled" value="yes" <?php checked( $enabled, 'yes' ); ?> />
						<?php esc_html_e( 'הפעל משיכת מוצרים מקובץ', 'oc-aviv-pos' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'כאשר מופעל, התוסף ימשוך מוצרים מקובץ CSV שמועלה על ידי Aviv POS.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="products_import_update_price"><?php esc_html_e( 'עדכן מחיר', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="settings[products_import_update_price]" id="products_import_update_price" value="yes" <?php checked( $update_price, 'yes' ); ?> />
						<?php esc_html_e( 'עדכן מחיר מכירה של מוצרים', 'oc-aviv-pos' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'כאשר מסומן, המחיר מהקובץ יעדכן את מחיר המכירה של המוצר.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="products_import_update_stock"><?php esc_html_e( 'עדכן מלאי', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="settings[products_import_update_stock]" id="products_import_update_stock" value="yes" <?php checked( $update_stock, 'yes' ); ?> />
						<?php esc_html_e( 'עדכן מלאי של מוצרים', 'oc-aviv-pos' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'כאשר מסומן, המלאי מהקובץ יעדכן את המלאי של המוצר.', 'oc-aviv-pos' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="products_import_cron_schedule"><?php esc_html_e( 'תזמון אוטומטי (Cron)', 'oc-aviv-pos' ); ?></label></th>
				<td>
					<?php
					$cron_schedule = $settings['products_import_cron_schedule'] ?? 'disabled';
					$next_run = wp_next_scheduled( OC_AVIV_POS_CRON_HOOK );
					?>
					<select name="settings[products_import_cron_schedule]" id="products_import_cron_schedule">
						<option value="disabled" <?php selected( $cron_schedule, 'disabled' ); ?>><?php esc_html_e( 'מושבת', 'oc-aviv-pos' ); ?></option>
						<option value="every_5_minutes" <?php selected( $cron_schedule, 'every_5_minutes' ); ?>><?php esc_html_e( 'כל 5 דקות', 'oc-aviv-pos' ); ?></option>
						<option value="every_15_minutes" <?php selected( $cron_schedule, 'every_15_minutes' ); ?>><?php esc_html_e( 'כל 15 דקות', 'oc-aviv-pos' ); ?></option>
						<option value="every_30_minutes" <?php selected( $cron_schedule, 'every_30_minutes' ); ?>><?php esc_html_e( 'כל 30 דקות', 'oc-aviv-pos' ); ?></option>
						<option value="hourly" <?php selected( $cron_schedule, 'hourly' ); ?>><?php esc_html_e( 'כל שעה', 'oc-aviv-pos' ); ?></option>
						<option value="twicedaily" <?php selected( $cron_schedule, 'twicedaily' ); ?>><?php esc_html_e( 'פעמיים ביום', 'oc-aviv-pos' ); ?></option>
						<option value="daily" <?php selected( $cron_schedule, 'daily' ); ?>><?php esc_html_e( 'פעם ביום', 'oc-aviv-pos' ); ?></option>
					</select>
					<?php if ( $next_run ) : ?>
						<p class="description" style="color: #00a32a;">
							<?php esc_html_e( 'ריצה הבאה:', 'oc-aviv-pos' ); ?> 
							<strong><?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $next_run ) ); ?></strong>
							(<?php echo esc_html( human_time_diff( $next_run ) ); ?>)
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'הייבוא האוטומטי מושבת.', 'oc-aviv-pos' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<hr />
		<h2><?php esc_html_e( 'מקור קובץ הייבוא', 'oc-aviv-pos' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'נתיב הקובץ', 'oc-aviv-pos' ); ?></th>
				<td>
					<?php if ( $account_id ) : ?>
						<code style="background: #f0f0f1; padding: 8px 12px; display: inline-block; border-radius: 4px; direction: ltr;">
							<?php echo esc_html( '/' . $account_id . '/ExportAllSupply.csv' ); ?>
						</code>
						<p class="description"><?php esc_html_e( 'Aviv POS מעלים את הקובץ לנתיב זה (יחסית ל-ROOT של האתר).', 'oc-aviv-pos' ); ?></p>
					<?php else : ?>
						<p style="color: #d63638;">
							<?php esc_html_e( 'יש להגדיר Account ID בטאב "חיבור API וטריגרים" כדי לדעת מאיפה למשוך את הקובץ.', 'oc-aviv-pos' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $file_info ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'סטטוס קובץ', 'oc-aviv-pos' ); ?></th>
				<td>
					<?php if ( $file_info['exists'] ) : ?>
						<span style="color: #00a32a; font-weight: 600;">&#10003; <?php esc_html_e( 'קובץ קיים', 'oc-aviv-pos' ); ?></span>
						<ul style="margin: 10px 0 0 0; padding: 0; list-style: none;">
							<li><strong><?php esc_html_e( 'גודל:', 'oc-aviv-pos' ); ?></strong> <?php echo esc_html( $file_info['size_human'] ); ?></li>
							<li><strong><?php esc_html_e( 'עודכן לאחרונה:', 'oc-aviv-pos' ); ?></strong> <?php echo esc_html( $file_info['modified'] ); ?> (<?php echo esc_html( $file_info['modified_ago'] ); ?>)</li>
							<li><strong><?php esc_html_e( 'נתיב מלא:', 'oc-aviv-pos' ); ?></strong> <code style="font-size: 11px;"><?php echo esc_html( $file_info['path'] ); ?></code></li>
						</ul>
					<?php else : ?>
						<span style="color: #d63638;">&#10007; <?php esc_html_e( 'קובץ לא נמצא', 'oc-aviv-pos' ); ?></span>
						<p class="description"><?php echo esc_html( $file_info['path'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'פעולה', 'oc-aviv-pos' ); ?></th>
				<td>
					<button type="button" class="button button-primary" id="oc_aviv_import_products" <?php disabled( ! $account_id || ! $file_info || ! $file_info['exists'] ); ?>>
						<?php esc_html_e( 'משוך ועדכן מוצרים עכשיו', 'oc-aviv-pos' ); ?>
					</button>
					<input type="hidden" id="oc_aviv_import_nonce" value="<?php echo esc_attr( wp_create_nonce( 'oc_aviv_pos_import_products' ) ); ?>" />
					<div id="oc_aviv_import_result" style="margin-top: 15px;"></div>
				</td>
			</tr> 
		</table>

		<hr />
		<h2><?php esc_html_e( 'דיבאג - ייבוא אחרון', 'oc-aviv-pos' ); ?></h2>
		
		<!-- DEBUG: Log file info -->
		<details style="margin-bottom: 15px;">
			<summary style="cursor: pointer; color: #666; font-size: 12px;">🔧 Raw Debug Data</summary>
			<div style="background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 11px; margin-top: 10px;">
				<?php 
				$log_file_path = OC_Aviv_Pos_Products_Importer::get_log_file_path();
				echo '<strong>Log file:</strong> ' . esc_html( $log_file_path ) . '<br>';
				echo '<strong>File exists:</strong> ' . ( file_exists( $log_file_path ) ? 'YES' : 'NO' ) . '<br>';
				if ( file_exists( $log_file_path ) ) {
					echo '<strong>File size:</strong> ' . esc_html( size_format( filesize( $log_file_path ) ) ) . '<br>';
					echo '<strong>Last modified:</strong> ' . esc_html( date( 'Y-m-d H:i:s', filemtime( $log_file_path ) ) ) . '<br>';
				}
				?>
			</div>
		</details>

		<?php if ( $last_log ) : ?>
		<div class="oc-aviv-import-log" style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-top: 10px;">
			<div style="display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 15px;">
				<div>
					<strong><?php esc_html_e( 'סטטוס:', 'oc-aviv-pos' ); ?></strong>
					<?php if ( $last_log['status'] === 'success' ) : ?>
						<span style="color: #00a32a; font-weight: 600;">&#10003; <?php esc_html_e( 'הצליח', 'oc-aviv-pos' ); ?></span>
					<?php else : ?>
						<span style="color: #d63638; font-weight: 600;">&#10007; <?php esc_html_e( 'נכשל', 'oc-aviv-pos' ); ?></span>
					<?php endif; ?>
				</div>
				<div>
					<strong><?php esc_html_e( 'תאריך:', 'oc-aviv-pos' ); ?></strong>
					<?php echo esc_html( $last_log['timestamp'] ?? '-' ); ?>
				</div>
				<?php if ( isset( $last_log['elapsed_time'] ) ) : ?>
				<div>
					<strong><?php esc_html_e( 'זמן ריצה:', 'oc-aviv-pos' ); ?></strong>
					<?php echo esc_html( $last_log['elapsed_time'] ); ?>s
				</div>
				<?php endif; ?>
			</div>

			<?php if ( $last_log['status'] === 'error' ) : ?>
				<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 4px; padding: 10px; color: #991b1b;">
					<strong><?php esc_html_e( 'שגיאה:', 'oc-aviv-pos' ); ?></strong> <?php echo esc_html( $last_log['message'] ?? '' ); ?>
				</div>
			<?php else : ?>
				<div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 15px;">
					<div style="background: #e7f5e7; border-radius: 4px; padding: 10px 15px; text-align: center;">
						<div style="font-size: 24px; font-weight: 700; color: #00a32a;"><?php echo esc_html( $last_log['updated'] ?? 0 ); ?></div>
						<div style="font-size: 12px; color: #666;"><?php esc_html_e( 'מוצרים עודכנו', 'oc-aviv-pos' ); ?></div>
					</div>
					<div style="background: #fef3cd; border-radius: 4px; padding: 10px 15px; text-align: center;">
						<div style="font-size: 24px; font-weight: 700; color: #856404;"><?php echo esc_html( $last_log['not_found'] ?? 0 ); ?></div>
						<div style="font-size: 12px; color: #666;"><?php esc_html_e( 'לא נמצאו', 'oc-aviv-pos' ); ?></div>
					</div>
					<div style="background: #f0f0f1; border-radius: 4px; padding: 10px 15px; text-align: center;">
						<div style="font-size: 24px; font-weight: 700; color: #666;"><?php echo esc_html( $last_log['total_rows'] ?? 0 ); ?></div>
						<div style="font-size: 12px; color: #666;"><?php esc_html_e( 'סה"כ שורות', 'oc-aviv-pos' ); ?></div>
					</div>
				</div>

				<div style="display: flex; gap: 10px; margin-bottom: 10px;">
					<span style="background: <?php echo ( $last_log['update_price'] ?? false ) ? '#e7f5e7' : '#f0f0f1'; ?>; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
						<?php echo ( $last_log['update_price'] ?? false ) ? '&#10003;' : '&#10007;'; ?> <?php esc_html_e( 'עדכון מחיר', 'oc-aviv-pos' ); ?>
					</span>
					<span style="background: <?php echo ( $last_log['update_stock'] ?? false ) ? '#e7f5e7' : '#f0f0f1'; ?>; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
						<?php echo ( $last_log['update_stock'] ?? false ) ? '&#10003;' : '&#10007;'; ?> <?php esc_html_e( 'עדכון מלאי', 'oc-aviv-pos' ); ?>
					</span>
				</div>

				<?php if ( ! empty( $last_log['updated_items'] ) ) : ?>
				<details style="margin-top: 15px;">
					<summary style="cursor: pointer; color: #2271b1; font-weight: 600;">
						<?php esc_html_e( 'מוצרים שעודכנו', 'oc-aviv-pos' ); ?> (<?php echo count( $last_log['updated_items'] ); ?>)
					</summary>
					<table class="widefat fixed striped" style="margin-top: 10px; font-size: 12px;">
						<thead>
							<tr>
								<th style="width: 60px;"><?php esc_html_e( 'ID', 'oc-aviv-pos' ); ?></th>
								<th><?php esc_html_e( 'שם מוצר', 'oc-aviv-pos' ); ?></th>
								<th style="width: 120px;"><?php esc_html_e( 'ברקוד', 'oc-aviv-pos' ); ?></th>
								<th style="width: 80px;"><?php esc_html_e( 'מחיר', 'oc-aviv-pos' ); ?></th>
								<th style="width: 60px;"><?php esc_html_e( 'מלאי', 'oc-aviv-pos' ); ?></th>
								<th><?php esc_html_e( 'שינויים', 'oc-aviv-pos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $last_log['updated_items'] as $item ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $item['product_id'] . '&action=edit' ) ); ?>" target="_blank">
										<?php echo esc_html( $item['product_id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $item['product_name'] ?? '-' ); ?></td>
								<td><code><?php echo esc_html( $item['barcode'] ); ?></code></td>
								<td><?php echo esc_html( $item['price'] ?? '-' ); ?></td>
								<td><?php echo esc_html( $item['stock'] ?? '-' ); ?></td>
								<td>
									<?php if ( ! empty( $item['changes'] ) ) : ?>
										<?php foreach ( $item['changes'] as $field => $change ) : ?>
											<span style="background: #e7f5e7; padding: 2px 5px; border-radius: 3px; margin-left: 3px;">
												<?php echo esc_html( $field ); ?>: <?php echo esc_html( $change['old'] ); ?> → <?php echo esc_html( $change['new'] ); ?>
											</span>
										<?php endforeach; ?>
									<?php else : ?>
										<span style="color: #999;"><?php esc_html_e( 'ללא שינוי', 'oc-aviv-pos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endif; ?>

				<?php if ( ! empty( $last_log['not_found_items'] ) ) : ?>
				<details style="margin-top: 10px;">
					<summary style="cursor: pointer; color: #d63638; font-weight: 600;">
						<?php esc_html_e( 'ברקודים שלא נמצאו', 'oc-aviv-pos' ); ?> (<?php echo count( $last_log['not_found_items'] ); ?>)
					</summary>
					<table class="widefat fixed striped" style="margin-top: 10px; font-size: 12px;">
						<thead>
							<tr>
								<th style="width: 60px;"><?php esc_html_e( 'שורה', 'oc-aviv-pos' ); ?></th>
								<th><?php esc_html_e( 'ברקוד', 'oc-aviv-pos' ); ?></th>
								<th style="width: 100px;"><?php esc_html_e( 'מחיר', 'oc-aviv-pos' ); ?></th>
								<th style="width: 80px;"><?php esc_html_e( 'מלאי', 'oc-aviv-pos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $last_log['not_found_items'] as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['row'] ?? '-' ); ?></td>
								<td><code><?php echo esc_html( $item['barcode'] ); ?></code></td>
								<td><?php echo esc_html( $item['price'] ?? '-' ); ?></td>
								<td><?php echo esc_html( $item['stock'] ?? '-' ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * AJAX handler for importing products from file.
	 */
	public static function ajax_import_products(): void {
		check_ajax_referer( 'oc_aviv_pos_import_products', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied', 'oc-aviv-pos' ) ], 403 );
		}

		// Get settings
		$settings   = self::get_settings();
		$account_id = $settings['account_id'] ?? '';

		if ( empty( $account_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Account ID is not configured', 'oc-aviv-pos' ) ], 400 );
		}

		$update_price = ( $settings['products_import_update_price'] ?? 'yes' ) === 'yes';
		$update_stock = ( $settings['products_import_update_stock'] ?? 'no' ) === 'yes';

		// Import products
		require_once OC_AVIV_POS_PATH . 'includes/class-oc-aviv-pos-products-importer.php';
		$result = OC_Aviv_Pos_Products_Importer::import_from_account( $account_id, $update_price, $update_stock );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		// Verify log was saved
		$saved_log = OC_Aviv_Pos_Products_Importer::get_last_import_log();
		
		wp_send_json_success( [
			'updated'   => $result['updated'],
			'not_found' => $result['not_found'],
			'skipped'   => $result['skipped'],
			'log_saved' => $saved_log !== null,
			'message'   => sprintf(
				__( 'הייבוא הושלם: %d עודכנו, %d לא נמצאו, %d דולגו', 'oc-aviv-pos' ),
				$result['updated'],
				$result['not_found'],
				$result['skipped']
			),
		] );
	}

}

