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
				<th><?php esc_html_e( 'POS paymentType code', 'oc-aviv-pos' ); ?></th>
				<th><?php esc_html_e( 'Type (PREPAID/POSTPAID)', 'oc-aviv-pos' ); ?></th>
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
					<td><input type="text" name="settings[payment_mapping][<?php echo esc_attr( $index ); ?>][pos_code]" value="<?php echo esc_attr( $row['pos_code'] ?? '' ); ?>" placeholder="PREPAID / CASH / BANK_TRANSFER…" /></td>
					<td>
						<select name="settings[payment_mapping][<?php echo esc_attr( $index ); ?>][mode]">
							<option value="PREPAID" <?php selected( $row['mode'] ?? '', 'PREPAID' ); ?>>PREPAID</option>
							<option value="POSTPAID" <?php selected( $row['mode'] ?? '', 'POSTPAID' ); ?>>POSTPAID</option>
						</select>
					</td>
					<td><a href="#" class="button remove-row"><?php esc_html_e( 'Remove', 'oc-aviv-pos' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'מפה כל שיטת תשלום של החנות לקוד התשלום והסוג ב-Aviv POS.', 'oc-aviv-pos' ); ?></p>
		<p><a href="#" class="button add-row"><?php esc_html_e( 'Add mapping', 'oc-aviv-pos' ); ?></a></p>
		<?php
	}

	private static function save_settings( array $existing ): array {
		$data = wp_unslash( $_POST['settings'] ?? [] );

		$sanitized = [
			'vendor_id'          => isset( $data['vendor_id'] ) ? sanitize_text_field( $data['vendor_id'] ) : '',
			'account_id'         => isset( $data['account_id'] ) ? sanitize_text_field( $data['account_id'] ) : '',
			'trigger_statuses'   => array_values( array_filter( array_map( 'sanitize_text_field', $data['trigger_statuses'] ?? [] ) ) ),
			'comment_mode'       => isset( $data['comment_mode'] ) ? sanitize_text_field( $data['comment_mode'] ) : 'variations_and_note',
			'general_separator'  => isset( $data['general_separator'] ) ? sanitize_text_field( $data['general_separator'] ) : ' | ',
			'variation_separator'=> isset( $data['variation_separator'] ) ? sanitize_text_field( $data['variation_separator'] ) : ': ',
			'payment_mapping'    => [],
		];

		if ( ! empty( $data['payment_mapping'] ) && is_array( $data['payment_mapping'] ) ) {
			foreach ( $data['payment_mapping'] as $row ) {
				if ( empty( $row['wc'] ) || empty( $row['pos_code'] ) ) {
					continue;
				}
				$sanitized['payment_mapping'][] = [
					'wc'       => sanitize_text_field( $row['wc'] ),
					'pos_code' => sanitize_text_field( $row['pos_code'] ),
					'mode'     => in_array( $row['mode'] ?? 'PREPAID', [ 'PREPAID', 'POSTPAID' ], true ) ? $row['mode'] : 'PREPAID',
				];
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
}

