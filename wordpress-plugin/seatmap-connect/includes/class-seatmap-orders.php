<?php
/**
 * Drives the Seatmap order lifecycle from WooCommerce order events.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ownership boundary in practice: WooCommerce decides when money moved, Seatmap decides who owns
 * the seat. This class is the translation between the two.
 *
 * Every call is idempotent and every idempotency key is *derived from the order*, not generated per
 * attempt — that is what makes a retry after a timeout safe. A random key per attempt would make
 * the API treat each retry as a new request and could sell the same seats twice.
 */
class Seatmap_Orders {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'register_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'register_order' ), 10, 1 );

		add_action( 'woocommerce_payment_complete', array( $this, 'confirm_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'confirm_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'confirm_order' ), 10, 1 );

		add_action( 'woocommerce_order_status_failed', array( $this, 'cancel_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_order' ), 10, 1 );
		add_action( 'woocommerce_trash_order', array( $this, 'cancel_order' ), 10, 1 );

		add_action( 'woocommerce_order_refunded', array( $this, 'refund_order' ), 10, 2 );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_panel' ) );
	}

	/** Bind the WooCommerce order to the hold that reserved its seats. */
	public function register_order( $order ): void {
		$order = $this->resolve( $order );

		if ( ! $order || $order->get_meta( '_seatmap_registered' ) ) {
			return;
		}

		$holds = Seatmap_Cart::holds_in_order( $order );

		if ( ! $holds ) {
			return; // Nothing seated in this order.
		}

		$client  = new Seatmap_Client();
		$success = true;

		foreach ( array_keys( $holds ) as $hold_token ) {
			$response = $client->request(
				'POST',
				'/v1/integrations/woocommerce/orders',
				array(
					'external_order_id' => $this->external_id( $order, $hold_token ),
					'hold_token'        => $hold_token,
					'buyer'             => $this->buyer( $order ),
					'metadata'          => array(
						'wc_order_id' => $order->get_id(),
						'site'        => home_url(),
					),
				),
				'register-' . $this->external_id( $order, $hold_token )
			);

			if ( is_wp_error( $response ) ) {
				$success = false;
				$this->note( $order, 'register', $response );
			}
		}

		if ( $success ) {
			$order->update_meta_data( '_seatmap_registered', 'yes' );
			$order->save();
		} else {
			// Left unregistered on purpose: the reconciler will retry, and confirm() re-registers
			// before confirming if it finds this flag missing.
			$this->flag_for_reconciliation( $order );
		}
	}

	/**
	 * Payment succeeded — turn the hold into a sale.
	 *
	 * Bound to three hooks because gateways differ in which they fire; the idempotency key makes
	 * the duplicates harmless.
	 */
	public function confirm_order( $order ): void {
		$order = $this->resolve( $order );

		if ( ! $order || 'yes' === $order->get_meta( '_seatmap_confirmed' ) ) {
			return;
		}

		$holds = Seatmap_Cart::holds_in_order( $order );

		if ( ! $holds ) {
			return;
		}

		if ( ! $order->get_meta( '_seatmap_registered' ) ) {
			// Registration failed earlier, or a gateway skipped straight to payment. Either way the
			// order must exist in Seatmap before it can be confirmed.
			$this->register_order( $order );
		}

		$client    = new Seatmap_Client();
		$confirmed = true;
		$tickets   = array();

		foreach ( array_keys( $holds ) as $hold_token ) {
			$external_id = $this->external_id( $order, $hold_token );

			$response = $client->request(
				'POST',
				'/v1/integrations/woocommerce/orders/' . rawurlencode( $external_id ) . '/confirm',
				array(
					'paid_at' => gmdate( 'c' ),
					'buyer'   => $this->buyer( $order ),
				),
				// Derived from the order, so every retry — this minute or from cron tomorrow —
				// replays the same request rather than creating a second sale.
				'confirm-' . $external_id
			);

			if ( is_wp_error( $response ) ) {
				$confirmed = false;
				$this->note( $order, 'confirm', $response );

				continue;
			}

			foreach ( $response['tickets'] ?? array() as $ticket ) {
				if ( ! empty( $ticket['token'] ) ) {
					$tickets[ $ticket['allocation_id'] ] = array(
						'token' => $ticket['token'],
						'seat'  => $ticket['seat'] ?? array(),
					);
				}
			}
		}

		if ( $confirmed ) {
			$order->update_meta_data( '_seatmap_confirmed', 'yes' );
			$order->delete_meta_data( '_seatmap_needs_reconciliation' );

			if ( $tickets ) {
				// Tokens are issued exactly once. If they are not stored now they cannot be
				// retrieved later, and the customer's QR codes are gone.
				$order->update_meta_data( '_seatmap_tickets', $tickets );
			}

			$order->add_order_note( __( 'Seatmap: seats allocated and tickets issued.', 'seatmap-connect' ) );
			$order->save();

			do_action( 'seatmap_order_confirmed', $order, $tickets );
		} else {
			$this->flag_for_reconciliation( $order );

			$order->add_order_note(
				__( 'Seatmap: could not confirm the seats yet. This will be retried automatically.', 'seatmap-connect' )
			);
			$order->save();
		}
	}

	/** Order failed, was cancelled or was trashed — hand the seats back. */
	public function cancel_order( $order ): void {
		$order = $this->resolve( $order );

		if ( ! $order ) {
			return;
		}

		$holds  = Seatmap_Cart::holds_in_order( $order );
		$client = new Seatmap_Client();

		foreach ( array_keys( $holds ) as $hold_token ) {
			$external_id = $this->external_id( $order, $hold_token );

			$response = $client->request(
				'POST',
				'/v1/integrations/woocommerce/orders/' . rawurlencode( $external_id ) . '/cancel',
				array( 'reason' => $this->cancel_reason( $order ) ),
				'cancel-' . $external_id
			);

			if ( is_wp_error( $response ) ) {
				$this->note( $order, 'cancel', $response );
				$this->flag_for_reconciliation( $order );
			}
		}

		$order->save();
	}

	/**
	 * A refund in WooCommerce voids the tickets in Seatmap and applies the event's seat policy.
	 *
	 * A partial refund names the seats it covers, so the remaining seats stay sold.
	 */
	public function refund_order( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order || ! $refund ) {
			return;
		}

		$client = new Seatmap_Client();

		foreach ( Seatmap_Cart::holds_in_order( $order ) as $hold_token => $seat_ids ) {
			$external_id    = $this->external_id( $order, $hold_token );
			$refunded_seats = $this->seats_in_refund( $refund, $seat_ids );

			$body = array( 'reason' => 'woocommerce_refund_' . $refund_id );

			// Omitting seat_ids means "the whole order"; sending them means "only these".
			if ( $refunded_seats && count( $refunded_seats ) < count( $seat_ids ) ) {
				$body['seat_ids'] = $refunded_seats;
			}

			$response = $client->request(
				'POST',
				'/v1/integrations/woocommerce/orders/' . rawurlencode( $external_id ) . '/refund',
				$body,
				'refund-' . $external_id . '-' . $refund_id
			);

			if ( is_wp_error( $response ) ) {
				$this->note( $order, 'refund', $response );
				$this->flag_for_reconciliation( $order );
			}
		}

		$order->save();
	}

	/**
	 * Which seats a partial refund actually covers.
	 *
	 * @param list<string> $all_seat_ids Seats on the order for this hold.
	 * @return list<string>
	 */
	private function seats_in_refund( WC_Order_Refund $refund, array $all_seat_ids ): array {
		$seats = array();

		foreach ( $refund->get_items() as $item ) {
			$seat_id = $item->get_meta( '_seatmap_seat_id', true );

			if ( $seat_id && in_array( $seat_id, $all_seat_ids, true ) ) {
				$seats[] = $seat_id;
			}
		}

		return array_values( array_unique( $seats ) );
	}

	/**
	 * The order's identity in Seatmap.
	 *
	 * Includes the hold token's tail because one WooCommerce order may cover seats held in more
	 * than one hold (a buyer who added seats in two goes), and each hold is a separate Seatmap
	 * order. It is stable for a given order and hold, which is what idempotency depends on.
	 */
	private function external_id( WC_Order $order, string $hold_token ): string {
		return 'wc-' . $order->get_id() . '-' . substr( md5( $hold_token ), 0, 8 );
	}

	private function buyer( WC_Order $order ): array {
		return array_filter(
			array(
				'name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'  => $order->get_billing_email(),
				'phone'  => $order->get_billing_phone(),
				'locale' => get_locale(),
			)
		);
	}

	private function cancel_reason( WC_Order $order ): string {
		return match ( $order->get_status() ) {
			'failed' => 'failed',
			'cancelled' => 'cancelled',
			default => 'deleted',
		};
	}

	private function flag_for_reconciliation( WC_Order $order ): void {
		$order->update_meta_data( '_seatmap_needs_reconciliation', 'yes' );
		$order->save();
	}

	private function note( WC_Order $order, string $stage, WP_Error $error ): void {
		$message = sprintf(
			/* translators: 1: lifecycle stage, 2: error message. */
			__( 'Seatmap %1$s failed: %2$s', 'seatmap-connect' ),
			$stage,
			$error->get_error_message()
		);

		$order->add_order_note( $message );
		Seatmap_Logger::error( $stage, sprintf( 'Order %d: %s', $order->get_id(), $error->get_error_message() ) );
	}

	/**
	 * @param WC_Order|int $order Order or its id, depending on which hook fired.
	 */
	private function resolve( $order ): ?WC_Order {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$resolved = wc_get_order( $order );

		return $resolved instanceof WC_Order ? $resolved : null;
	}

	/** Show the seating state on the order screen, so staff are not left guessing. */
	public function render_admin_panel( WC_Order $order ): void {
		$holds = Seatmap_Cart::holds_in_order( $order );

		if ( ! $holds ) {
			return;
		}

		$confirmed = 'yes' === $order->get_meta( '_seatmap_confirmed' );
		$pending   = 'yes' === $order->get_meta( '_seatmap_needs_reconciliation' );

		echo '<div class="form-field form-field-wide"><h3>' . esc_html__( 'Seatmap', 'seatmap-connect' ) . '</h3><p>';

		if ( $confirmed ) {
			esc_html_e( 'Seats allocated and tickets issued.', 'seatmap-connect' );
		} elseif ( $pending ) {
			esc_html_e( 'Waiting to reach the seating service. This retries automatically every five minutes.', 'seatmap-connect' );
		} else {
			esc_html_e( 'Seats are held, awaiting payment.', 'seatmap-connect' );
		}

		echo '</p></div>';
	}
}
