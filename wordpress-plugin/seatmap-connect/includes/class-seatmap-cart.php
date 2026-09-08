<?php
/**
 * Cart integration: seats as cart lines, priced from the server.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

class Seatmap_Cart {

	private const ITEM_KEY = 'seatmap';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_server_price' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'copy_to_order_item' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'revalidate_holds' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'release_on_removal' ), 10, 2 );
	}

	/**
	 * A stable per-browser identifier, so a buyer who reloads keeps their own holds and the API can
	 * rate-limit sensibly. It is not an identity and is never used for authorisation.
	 */
	public static function session_id(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return 'anon-' . wp_generate_uuid4();
		}

		$id = WC()->session->get( 'seatmap_session_id' );

		if ( ! $id ) {
			$id = 'wc-' . wp_generate_uuid4();
			WC()->session->set( 'seatmap_session_id', $id );
		}

		return (string) $id;
	}

	/**
	 * Put every seat of a hold into the cart as its own line.
	 *
	 * One line per seat rather than one line per hold: the buyer can then remove a single seat, and
	 * WooCommerce's own quantity and tax handling stays untouched.
	 *
	 * @param array $hold The API response — the only source of prices.
	 * @return true|WP_Error
	 */
	public function add_hold_to_cart( string $event_public_id, array $hold ) {
		$product_id = (int) get_option( 'seatmap_seat_product_id', 0 );

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			Seatmap_Logger::error( 'cart', 'Seat product is not configured; cannot add seats to the cart.' );

			return new WP_Error(
				'seatmap_no_product',
				__( 'This store is not finished setting up seat sales yet.', 'seatmap-connect' ),
				array( 'status' => 503 )
			);
		}

		$common = array(
			'event_public_id'    => $event_public_id,
			'hold_token'         => $hold['hold_token'],
			'expires_at'         => $hold['expires_at'],
			'currency'           => $hold['currency'],
			// Kept so the price can be shown to have come from the server, and so support can
			// verify a disputed charge after the fact.
			'snapshot_payload'   => $hold['price_snapshot']['payload'] ?? '',
			'snapshot_signature' => $hold['price_snapshot']['signature'] ?? '',
		);

		foreach ( $hold['seats'] as $seat ) {
			$added = WC()->cart->add_to_cart(
				$product_id,
				1,
				0,
				array(),
				array(
					self::ITEM_KEY => $common + array(
						'kind'     => 'seat',
						'seat_id'  => $seat['seat_id'],
						'section'  => $seat['section'],
						'row'      => $seat['row'],
						'label'    => $seat['label'],
						'amount'   => (int) $seat['amount'],
						'quantity' => 1,
					),
				)
			);

			if ( ! $added ) {
				return $this->cartFailure();
			}
		}

		// Standing room is one cart line for the whole quantity rather than one line per place: a
		// buyer thinks of it as "four in the pit", and WooCommerce's own quantity control then
		// behaves the way they expect.
		foreach ( $hold['areas'] ?? array() as $area ) {
			$added = WC()->cart->add_to_cart(
				$product_id,
				(int) $area['quantity'],
				0,
				array(),
				array(
					self::ITEM_KEY => $common + array(
						'kind'               => 'area',
						'capacity_object_id' => $area['capacity_object_id'],
						'section'            => $area['label'],
						'row'                => '',
						'label'              => $area['label'],
						// The signed amount is for the whole line, so the per-place price is what
						// WooCommerce needs for a line of this quantity.
						'amount'             => (int) round( $area['amount'] / max( 1, (int) $area['quantity'] ) ),
						'quantity'           => (int) $area['quantity'],
					),
				)
			);

			if ( ! $added ) {
				return $this->cartFailure();
			}
		}

		return true;
	}

	private function cartFailure(): WP_Error {
		return new WP_Error(
			'seatmap_cart_failed',
			__( 'Your seats could not be added to your cart.', 'seatmap-connect' ),
			array( 'status' => 500 )
		);
	}

	public function remove_hold_from_cart( string $hold_token ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ( $item[ self::ITEM_KEY ]['hold_token'] ?? '' ) === $hold_token ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}

	/**
	 * Force each seat line to the price the API returned.
	 *
	 * This is the hinge of the anti-tampering design (threat T3): whatever the product costs, and
	 * whatever a crafted request may have suggested, the line is set to the server's amount on
	 * every recalculation.
	 */
	public function apply_server_price( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			$seat = $item[ self::ITEM_KEY ] ?? null;

			if ( ! $seat ) {
				continue;
			}

			// Amounts travel as minor units, so a currency with two decimals divides by 100. Using
			// integers end to end avoids the rounding drift floats would introduce.
			$item['data']->set_price( $this->to_major_units( (int) $seat['amount'] ) );
		}
	}

	private function to_major_units( int $minor ): float {
		$decimals = wc_get_price_decimals();

		return $minor / ( 10 ** max( 0, $decimals ) );
	}

	/** How a line reads to the buyer: a named seat, or a number of places in an area. */
	private function describe( array $seat ): string {
		if ( 'area' === ( $seat['kind'] ?? 'seat' ) ) {
			return sprintf(
				/* translators: 1: number of places, 2: area name. */
				_n( '%1$d place in %2$s', '%1$d places in %2$s', (int) $seat['quantity'], 'seatmap-connect' ),
				(int) $seat['quantity'],
				$seat['section']
			);
		}

		return sprintf(
			/* translators: 1: section name, 2: row name, 3: seat label. */
			__( '%1$s, row %2$s, seat %3$s', 'seatmap-connect' ),
			$seat['section'],
			$seat['row'],
			$seat['label']
		);
	}

	/** Show section/row/seat in the cart and at checkout. */
	public function display_item_data( array $item_data, array $cart_item ): array {
		$seat = $cart_item[ self::ITEM_KEY ] ?? null;

		if ( ! $seat ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'     => 'area' === ( $seat['kind'] ?? 'seat' )
				? __( 'Standing', 'seatmap-connect' )
				: __( 'Seat', 'seatmap-connect' ),
			'value'   => $this->describe( $seat ),
			'display' => '',
		);

		return $item_data;
	}

	/**
	 * Copy the seat onto the order line so it survives into the order, the emails and the admin
	 * screen — and so refunds can name the exact seat.
	 */
	public function copy_to_order_item( $item, string $cart_item_key, array $values, $order ): void {
		$seat = $values[ self::ITEM_KEY ] ?? null;

		if ( ! $seat ) {
			return;
		}

		$item->add_meta_data(
			'area' === ( $seat['kind'] ?? 'seat' ) ? __( 'Standing', 'seatmap-connect' ) : __( 'Seat', 'seatmap-connect' ),
			$this->describe( $seat ),
			true
		);

		// Hidden keys (leading underscore) carry what the integration needs later.
		$item->add_meta_data( '_seatmap_seat_id', $seat['seat_id'] ?? '', true );
		$item->add_meta_data( '_seatmap_capacity_object_id', $seat['capacity_object_id'] ?? '', true );
		$item->add_meta_data( '_seatmap_hold_token', $seat['hold_token'], true );
		$item->add_meta_data( '_seatmap_event_public_id', $seat['event_public_id'], true );
	}

	/**
	 * Re-check every hold before checkout can proceed.
	 *
	 * Without this, a buyer who left the tab open through lunch would pay for seats that returned
	 * to sale twenty minutes ago — and would only find out afterwards.
	 */
	public function revalidate_holds(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$client   = new Seatmap_Client();
		$checked  = array();
		$expired  = array();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$seat = $item[ self::ITEM_KEY ] ?? null;

			if ( ! $seat || isset( $checked[ $seat['hold_token'] ] ) ) {
				continue;
			}

			$checked[ $seat['hold_token'] ] = true;

			$response = $client->get_public_post(
				'/v1/embed/holds/' . rawurlencode( $seat['hold_token'] ) . '/validate',
				array()
			);

			if ( is_wp_error( $response ) || empty( $response['valid'] ) ) {
				$expired[] = $seat['hold_token'];
			}
		}

		foreach ( $expired as $token ) {
			$this->remove_hold_from_cart( $token );
		}

		if ( $expired ) {
			wc_add_notice(
				__( 'Your seat reservation ran out and the seats were released. Please choose your seats again.', 'seatmap-connect' ),
				'error'
			);
		}
	}

	/**
	 * Give a seat back as soon as the buyer removes it, rather than holding it hostage until the
	 * TTL expires.
	 */
	public function release_on_removal( string $cart_item_key, $cart ): void {
		$item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		$seat = $item[ self::ITEM_KEY ] ?? null;

		if ( ! $seat ) {
			return;
		}

		// Other seats from the same hold may still be in the cart; releasing the hold would drop
		// those too.
		foreach ( $cart->get_cart() as $remaining ) {
			if ( ( $remaining[ self::ITEM_KEY ]['hold_token'] ?? '' ) === $seat['hold_token'] ) {
				return;
			}
		}

		( new Seatmap_Client() )->get_public_delete( '/v1/embed/holds/' . rawurlencode( $seat['hold_token'] ) );
	}

	/**
	 * Every distinct hold token represented in an order, with its seats.
	 *
	 * @return array<string, list<string>> hold token => seat ids
	 */
	public static function holds_in_order( WC_Order $order ): array {
		$holds = array();

		foreach ( $order->get_items() as $item ) {
			$token   = $item->get_meta( '_seatmap_hold_token', true );
			$seat_id = $item->get_meta( '_seatmap_seat_id', true );

			if ( $token ) {
				$holds[ $token ][] = $seat_id;
			}
		}

		return $holds;
	}
}
