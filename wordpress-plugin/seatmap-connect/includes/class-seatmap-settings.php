<?php
/**
 * Settings screen and connection test.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

class Seatmap_Settings {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_seatmap_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Seatmap', 'seatmap-connect' ),
			__( 'Seatmap', 'seatmap-connect' ),
			'manage_woocommerce',
			'seatmap-connect',
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'seatmap_connect',
			'seatmap_api_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_url' ),
				'default'           => '',
			)
		);

		register_setting(
			'seatmap_connect',
			'seatmap_api_key_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'seatmap_connect',
			'seatmap_api_secret',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_secret' ),
				'default'           => '',
			)
		);

		register_setting(
			'seatmap_connect',
			'seatmap_seat_product_id',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/** Reject a plaintext endpoint outright: every request carries a credential. */
	public function sanitize_url( $value ): string {
		$value = esc_url_raw( trim( (string) $value ) );

		if ( '' !== $value && ! str_starts_with( $value, 'https://' ) && ! $this->is_local( $value ) ) {
			add_settings_error(
				'seatmap_connect',
				'insecure_url',
				__( 'The API URL must use HTTPS. Requests carry your API credentials.', 'seatmap-connect' )
			);

			return (string) get_option( 'seatmap_api_url', '' );
		}

		return untrailingslashit( $value );
	}

	/**
	 * Keep the stored secret when the field is submitted empty or masked.
	 *
	 * The form never renders the real secret back, so an admin saving an unrelated setting must not
	 * silently wipe the credential.
	 */
	public function sanitize_secret( $value ): string {
		$value    = trim( (string) $value );
		$existing = (string) get_option( 'seatmap_api_secret', '' );

		if ( '' === $value || str_contains( $value, '•' ) ) {
			return $existing;
		}

		return sanitize_text_field( $value );
	}

	private function is_local( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'seatmap-connect' ) );
		}

		$secret = (string) get_option( 'seatmap_api_secret', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Seatmap Connect', 'seatmap-connect' ); ?></h1>
			<p><?php esc_html_e( 'Connect this store to your Seatmap account. Seats, prices and availability come from Seatmap; the cart, payment and refunds stay here in WooCommerce.', 'seatmap-connect' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'seatmap_connect' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="seatmap_api_url"><?php esc_html_e( 'API URL', 'seatmap-connect' ); ?></label></th>
						<td>
							<input name="seatmap_api_url" id="seatmap_api_url" type="url" class="regular-text"
								value="<?php echo esc_attr( get_option( 'seatmap_api_url', '' ) ); ?>"
								placeholder="https://api.seatmap.example" />
							<p class="description"><?php esc_html_e( 'Without the /v1 suffix.', 'seatmap-connect' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seatmap_api_key_id"><?php esc_html_e( 'Key ID', 'seatmap-connect' ); ?></label></th>
						<td>
							<input name="seatmap_api_key_id" id="seatmap_api_key_id" type="text" class="regular-text"
								value="<?php echo esc_attr( get_option( 'seatmap_api_key_id', '' ) ); ?>" placeholder="ak_..." />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seatmap_api_secret"><?php esc_html_e( 'Secret', 'seatmap-connect' ); ?></label></th>
						<td>
							<input name="seatmap_api_secret" id="seatmap_api_secret" type="password" class="regular-text"
								autocomplete="new-password"
								value="<?php echo '' === $secret ? '' : esc_attr( str_repeat( '•', 12 ) . substr( $secret, -4 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Shown once by Seatmap when the key was created. Leave the masked value alone to keep the current secret.', 'seatmap-connect' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seatmap_seat_product_id"><?php esc_html_e( 'Seat product', 'seatmap-connect' ); ?></label></th>
						<td>
							<input name="seatmap_seat_product_id" id="seatmap_seat_product_id" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( (string) get_option( 'seatmap_seat_product_id', 0 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'A simple, virtual product used as the cart line for a seat. Its own price is ignored — the price always comes from the signed Seatmap response.', 'seatmap-connect' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'seatmap-connect' ); ?></h2>
			<p><?php esc_html_e( 'Checks the credentials and the clock. Signed requests are rejected if this server\'s time is more than five minutes from the API\'s.', 'seatmap-connect' ); ?></p>
			<p>
				<button class="button button-secondary" id="seatmap-test-connection"><?php esc_html_e( 'Test connection', 'seatmap-connect' ); ?></button>
				<span id="seatmap-test-result" style="margin-inline-start:12px"></span>
			</p>

			<script>
			document.getElementById( 'seatmap-test-connection' ).addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var out = document.getElementById( 'seatmap-test-result' );
				out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'seatmap-connect' ) ); ?>;

				var body = new FormData();
				body.append( 'action', 'seatmap_test_connection' );
				body.append( '_wpnonce', <?php echo wp_json_encode( wp_create_nonce( 'seatmap_test_connection' ) ); ?> );

				fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( r ) {
						out.textContent = r.data.message;
						out.style.color = r.success ? '#1a7f37' : '#b32d2e';
					} )
					.catch( function () {
						out.textContent = <?php echo wp_json_encode( __( 'The test request itself failed.', 'seatmap-connect' ) ); ?>;
						out.style.color = '#b32d2e';
					} );
			} );
			</script>
		</div>
		<?php
	}

	/**
	 * Verifies credentials, and separately reports clock skew.
	 *
	 * Skew is the single most common cause of "invalid signature" in the field, and it is invisible
	 * unless something goes looking for it.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'seatmap_test_connection' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not permitted.', 'seatmap-connect' ) ), 403 );
		}

		$client = new Seatmap_Client();

		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Fill in the API URL, key ID and secret first.', 'seatmap-connect' ) ) );
		}

		// A signed read against an order id that cannot exist: 404 means the signature was accepted,
		// which is exactly what we want to prove without touching real data.
		$probe    = 'connection-test-' . wp_generate_password( 8, false );
		$response = $client->request( 'GET', '/v1/integrations/woocommerce/orders/' . $probe );

		if ( is_wp_error( $response ) ) {
			$code = $response->get_error_data()['code'] ?? '';

			if ( 'not_found' === $code ) {
				wp_send_json_success( array( 'message' => __( 'Connected. Credentials and clock are good.', 'seatmap-connect' ) ) );
			}

			$hint = '';

			if ( in_array( $code, array( 'stale_timestamp' ), true ) ) {
				$hint = ' ' . __( 'This server\'s clock is out of step with the API. Fix NTP on this host.', 'seatmap-connect' );
			} elseif ( in_array( $code, array( 'invalid_signature', 'invalid_key', 'client_disabled' ), true ) ) {
				$hint = ' ' . __( 'Check the key ID and secret, and that the key has not been revoked.', 'seatmap-connect' );
			}

			wp_send_json_error( array( 'message' => $response->get_error_message() . $hint ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connected.', 'seatmap-connect' ) ) );
	}
}
