<?php
/**
 * Catches up orders whose Seatmap call did not get through.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

/**
 * The safety net for the one failure that actually costs money: payment succeeded here, but the
 * confirm never reached Seatmap. Without this, the buyer has paid and holds no seat.
 *
 * It re-drives the same idempotent calls with the same keys, so re-running it is harmless — and it
 * asks Seatmap for the current state first, so an order that did in fact confirm is simply marked
 * done rather than confirmed twice.
 */
class Seatmap_Reconciler {

	private const BATCH = 25;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'seatmap_reconcile_orders', array( $this, 'run' ) );
	}

	public function run(): void {
		$client = new Seatmap_Client();

		if ( ! $client->is_configured() ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_seatmap_needs_reconciliation',
						'value' => 'yes',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			$this->reconcile( $order, $client );
		}
	}

	private function reconcile( WC_Order $order, Seatmap_Client $client ): void {
		$holds = Seatmap_Cart::holds_in_order( $order );

		if ( ! $holds ) {
			$order->delete_meta_data( '_seatmap_needs_reconciliation' );
			$order->save();

			return;
		}

		$paid       = $order->is_paid();
		$cancelled  = in_array( $order->get_status(), array( 'failed', 'cancelled' ), true );
		$all_settled = true;

		foreach ( array_keys( $holds ) as $hold_token ) {
			$external_id = 'wc-' . $order->get_id() . '-' . substr( md5( $hold_token ), 0, 8 );

			// Ask first. If a previous attempt actually landed, there is nothing to redo — and
			// finding that out is cheaper and safer than blindly re-posting.
			$state = $client->request( 'GET', '/v1/integrations/woocommerce/orders/' . rawurlencode( $external_id ) );

			if ( is_wp_error( $state ) ) {
				$code = $state->get_error_data()['code'] ?? '';

				if ( 'not_found' !== $code ) {
					$all_settled = false;

					continue;
				}

				// Never registered. Re-run registration through the normal path.
				Seatmap_Orders::instance()->register_order( $order );
				$state = $client->request( 'GET', '/v1/integrations/woocommerce/orders/' . rawurlencode( $external_id ) );

				if ( is_wp_error( $state ) ) {
					$all_settled = false;

					continue;
				}
			}

			$status = $state['status'] ?? 'pending';

			if ( $paid && 'pending' === $status ) {
				Seatmap_Orders::instance()->confirm_order( $order );
				$all_settled = 'yes' === $order->get_meta( '_seatmap_confirmed' );

				continue;
			}

			if ( $cancelled && 'pending' === $status ) {
				Seatmap_Orders::instance()->cancel_order( $order );

				continue;
			}

			if ( 'confirmed' === $status && 'yes' !== $order->get_meta( '_seatmap_confirmed' ) ) {
				// The sale went through on a previous attempt whose response was lost. Record that
				// rather than confirming again.
				$order->update_meta_data( '_seatmap_confirmed', 'yes' );
				$order->add_order_note( __( 'Seatmap: reconciliation found this order already confirmed.', 'seatmap-connect' ) );
			}
		}

		if ( $all_settled ) {
			$order->delete_meta_data( '_seatmap_needs_reconciliation' );
		}

		$order->save();
	}
}
