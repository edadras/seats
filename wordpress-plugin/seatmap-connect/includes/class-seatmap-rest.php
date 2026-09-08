<?php
/**
 * Store-side REST routes the seat widget talks to.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

/**
 * The browser never calls the Seatmap API's hold endpoint directly for anything that touches the
 * cart. It calls this store, which then talks to Seatmap and puts the *server's* answer — including
 * the server's prices — into the cart. That is what makes price tampering pointless.
 */
class Seatmap_Rest {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'seatmap/v1',
			'/hold',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_hold' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'event_public_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'seat_ids'        => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			'seatmap/v1',
			'/release',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'release_hold' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);

		register_rest_route(
			'seatmap/v1',
			'/availability/(?P<event>[a-zA-Z0-9_\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'availability' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Nonce check for cart-mutating routes.
	 *
	 * Buyers are usually not logged in, so this cannot require a capability; the nonce is what ties
	 * the request to a session that actually loaded the page.
	 */
	public function check_nonce( WP_REST_Request $request ): bool {
		return (bool) wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	public function create_hold( WP_REST_Request $request ) {
		$seat_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'seat_ids' ) ) ) );

		if ( ! $seat_ids ) {
			return new WP_Error( 'seatmap_no_seats', __( 'Choose at least one seat.', 'seatmap-connect' ), array( 'status' => 400 ) );
		}

		$event_id = (string) $request->get_param( 'event_public_id' );
		$client   = new Seatmap_Client();

		if ( ! $client->is_configured() ) {
			return new WP_Error( 'seatmap_not_configured', __( 'Seat booking is not available right now.', 'seatmap-connect' ), array( 'status' => 503 ) );
		}

		$response = $client->get_public_post(
			'/v1/embed/events/' . rawurlencode( $event_id ) . '/holds',
			array(
				'seat_ids'   => $seat_ids,
				'session_id' => Seatmap_Cart::session_id(),
			)
		);

		if ( is_wp_error( $response ) ) {
			$data = $response->get_error_data();

			return new WP_Error(
				$response->get_error_code(),
				$response->get_error_message(),
				array(
					'status'                 => 'seat_unavailable' === ( $data['code'] ?? '' ) ? 409 : 400,
					'unavailable_seat_ids'   => $data['details']['unavailable_seat_ids'] ?? array(),
				)
			);
		}

		$added = Seatmap_Cart::instance()->add_hold_to_cart( $event_id, $response );

		if ( is_wp_error( $added ) ) {
			// The seats are held but could not be carted. Hand them straight back rather than
			// leaving them locked until the TTL expires.
			$client->get_public_delete( '/v1/embed/holds/' . rawurlencode( $response['hold_token'] ) );

			return $added;
		}

		return rest_ensure_response(
			array(
				'hold_token'   => $response['hold_token'],
				'expires_at'   => $response['expires_at'],
				'total_amount' => $response['total_amount'],
				'currency'     => $response['currency'],
				'seats'        => $response['seats'],
				'cart_url'     => wc_get_cart_url(),
			)
		);
	}

	public function release_hold( WP_REST_Request $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'hold_token' ) );

		if ( $token ) {
			Seatmap_Cart::instance()->remove_hold_from_cart( $token );
			( new Seatmap_Client() )->get_public_delete( '/v1/embed/holds/' . rawurlencode( $token ) );
		}

		return rest_ensure_response( array( 'released' => true ) );
	}

	/**
	 * Availability proxy.
	 *
	 * Proxied rather than fetched from the browser so the API host never needs to be exposed in
	 * page source, and so a shop can cache it at the edge.
	 */
	public function availability( WP_REST_Request $request ) {
		$response = ( new Seatmap_Client() )->get_public(
			'/v1/embed/events/' . rawurlencode( (string) $request->get_param( 'event' ) ) . '/availability',
			array_filter( array( 'since' => $request->get_param( 'since' ) ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response( $response );
	}
}
