<?php
/**
 * Plugin Name:       Seatmap Connect
 * Plugin URI:        https://github.com/edadras/seats
 * Description:       Sell reserved seats on your own WooCommerce store, backed by the Seatmap seating service.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * WC tested up to:   9.4
 * Text Domain:       seatmap-connect
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

define( 'SEATMAP_CONNECT_VERSION', '1.0.0' );
define( 'SEATMAP_CONNECT_FILE', __FILE__ );
define( 'SEATMAP_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEATMAP_CONNECT_URL', plugin_dir_url( __FILE__ ) );

require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-client.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-settings.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-widget.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-cart.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-orders.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-rest.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-reconciler.php';
require_once SEATMAP_CONNECT_PATH . 'includes/class-seatmap-logger.php';

/**
 * Boot the plugin once WooCommerce is known to be present.
 *
 * Everything this plugin does depends on WooCommerce's cart and order objects, so loading without
 * it would only produce fatals. A notice is far more useful than a white screen.
 */
function seatmap_connect_bootstrap(): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Seatmap Connect needs WooCommerce to be installed and active.', 'seatmap-connect' )
				);
			}
		);

		return;
	}

	Seatmap_Settings::instance();
	Seatmap_Widget::instance();
	Seatmap_Cart::instance();
	Seatmap_Orders::instance();
	Seatmap_Rest::instance();
	Seatmap_Reconciler::instance();
}
add_action( 'plugins_loaded', 'seatmap_connect_bootstrap' );

/**
 * Declare compatibility with High-Performance Order Storage.
 *
 * The plugin only ever reaches orders through the CRUD API, never through post meta directly, so
 * HPOS is safe.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SEATMAP_CONNECT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SEATMAP_CONNECT_FILE, true );
		}
	}
);

register_activation_hook(
	SEATMAP_CONNECT_FILE,
	static function (): void {
		// Retries are the plugin's safety net when the API is briefly unreachable; without this
		// schedule a failed confirm would never be re-driven.
		if ( ! wp_next_scheduled( 'seatmap_reconcile_orders' ) ) {
			wp_schedule_event( time() + 300, 'seatmap_five_minutes', 'seatmap_reconcile_orders' );
		}
	}
);

register_deactivation_hook(
	SEATMAP_CONNECT_FILE,
	static function (): void {
		wp_clear_scheduled_hook( 'seatmap_reconcile_orders' );
	}
);

add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	static function ( array $schedules ): array {
		$schedules['seatmap_five_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Every five minutes (Seatmap)', 'seatmap-connect' ),
		);

		return $schedules;
	}
);

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'seatmap-connect', false, dirname( plugin_basename( SEATMAP_CONNECT_FILE ) ) . '/languages' );
	}
);
