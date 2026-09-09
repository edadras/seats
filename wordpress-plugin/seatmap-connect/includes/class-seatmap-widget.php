<?php
/**
 * Shortcode, Gutenberg block and front-end assets for the seat picker.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

class Seatmap_Widget {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_shortcode( 'seatmap_event', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		// Registration goes on `init`, not `wp_enqueue_scripts`. A block theme renders the page's
		// content before `wp_enqueue_scripts` fires, and `wp_add_inline_script` silently does
		// nothing when its handle is not registered yet — which left the picker on every block
		// theme with a script but no configuration to boot from.
		add_action( 'init', array( $this, 'register_assets' ) );
	}

	public function register_assets(): void {
		wp_register_style(
			'seatmap-widget',
			SEATMAP_CONNECT_URL . 'assets/css/widget.css',
			array(),
			SEATMAP_CONNECT_VERSION
		);

		wp_register_script(
			'seatmap-widget',
			SEATMAP_CONNECT_URL . 'assets/js/widget.js',
			array(),
			SEATMAP_CONNECT_VERSION,
			true
		);
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'seatmap/event',
			array(
				'api_version'     => 3,
				'title'           => __( 'Seat map', 'seatmap-connect' ),
				'category'        => 'woocommerce',
				'icon'            => 'tickets-alt',
				'description'     => __( 'Let customers pick their seats for a Seatmap event.', 'seatmap-connect' ),
				'attributes'      => array(
					'eventPublicId' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => function ( array $attributes ): string {
					return $this->render( (string) ( $attributes['eventPublicId'] ?? '' ) );
				},
				'editor_script'   => 'seatmap-block-editor',
			)
		);

		wp_register_script(
			'seatmap-block-editor',
			SEATMAP_CONNECT_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			SEATMAP_CONNECT_VERSION,
			true
		);

		// A `.mo` is for PHP; a script wants its strings as JSON, named after the md5 of its own
		// path. Without this the block's own panel is English no matter what the rest of the admin
		// is set to, and it fails silently — which is why it went unnoticed until it was looked for.
		wp_set_script_translations(
			'seatmap-block-editor',
			'seatmap-connect',
			SEATMAP_CONNECT_PATH . 'languages'
		);
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '' ), (array) $atts, 'seatmap_event' );

		return $this->render( (string) $atts['id'] );
	}

	private function render( string $event_public_id ): string {
		$event_public_id = sanitize_text_field( $event_public_id );

		if ( '' === $event_public_id ) {
			return $this->notice( __( 'No event was selected for this seat map.', 'seatmap-connect' ) );
		}

		$client = new Seatmap_Client();

		if ( ! $client->is_configured() ) {
			return $this->notice( __( 'Seat booking is not available right now.', 'seatmap-connect' ) );
		}

		$event = $client->get_public( '/v1/embed/events/' . rawurlencode( $event_public_id ) );

		if ( is_wp_error( $event ) ) {
			Seatmap_Logger::error( 'widget', sprintf( 'Event %s: %s', $event_public_id, $event->get_error_message() ) );

			return $this->notice( __( 'This event could not be loaded.', 'seatmap-connect' ) );
		}

		$map = $client->get_public( '/v1/embed/events/' . rawurlencode( $event_public_id ) . '/seat-map' );

		if ( is_wp_error( $map ) ) {
			return $this->notice( __( 'The seating plan for this event is not published yet.', 'seatmap-connect' ) );
		}

		// Idempotent: wp_register_* leaves an existing handle alone. This is here because the
		// inline configuration below is dropped without a word if the handle is not registered,
		// and a page that renders its content unusually early must not lose it.
		$this->register_assets();

		wp_enqueue_style( 'seatmap-widget' );
		wp_enqueue_script( 'seatmap-widget' );

		$container_id = 'seatmap-' . wp_generate_password( 8, false, false );

		wp_add_inline_script(
			'seatmap-widget',
			sprintf(
				'window.seatmapBoot = window.seatmapBoot || []; window.seatmapBoot.push(%s);',
				wp_json_encode(
					array(
						'containerId'   => $container_id,
						'eventPublicId' => $event_public_id,
						'event'         => $event,
						'geometry'      => $map['geometry'] ?? array(),
						'restUrl'       => esc_url_raw( rest_url( 'seatmap/v1' ) ),
						'nonce'         => wp_create_nonce( 'wp_rest' ),
						'currency'      => array(
							'code'     => $event['currency'] ?? get_woocommerce_currency(),
							'symbol'   => html_entity_decode( get_woocommerce_currency_symbol( $event['currency'] ?? null ) ),
							'decimals' => wc_get_price_decimals(),
							'position' => get_option( 'woocommerce_currency_pos', 'left' ),
						),
						'isRtl'         => is_rtl(),
						'i18n'          => array(
							'selectSeats'    => __( 'Select your seats', 'seatmap-connect' ),
							'available'      => __( 'Available', 'seatmap-connect' ),
							'unavailable'    => __( 'Unavailable', 'seatmap-connect' ),
							'selected'       => __( 'Selected', 'seatmap-connect' ),
							'yourSelection'  => __( 'Your selection', 'seatmap-connect' ),
							'noneSelected'   => __( 'No seats selected yet.', 'seatmap-connect' ),
							'total'          => __( 'Total', 'seatmap-connect' ),
							'addToCart'      => __( 'Reserve and add to cart', 'seatmap-connect' ),
							'working'        => __( 'Reserving…', 'seatmap-connect' ),
							'seatTaken'      => __( 'Sorry, one of those seats was just taken. It has been removed from your selection.', 'seatmap-connect' ),
							'genericError'   => __( 'Something went wrong. Please try again.', 'seatmap-connect' ),
							'maxSeats'       => __( 'You can select up to %d seats.', 'seatmap-connect' ),
							'seatLabel'      => __( '%1$s, row %2$s, seat %3$s — %4$s', 'seatmap-connect' ),
							'seatUnavailable' => __( '%1$s, row %2$s, seat %3$s — unavailable', 'seatmap-connect' ),
							'zoomIn'         => __( 'Zoom in', 'seatmap-connect' ),
							'zoomOut'        => __( 'Zoom out', 'seatmap-connect' ),
							'resetView'      => __( 'Reset view', 'seatmap-connect' ),
							'held'           => __( 'Seats held until %s', 'seatmap-connect' ),
							'expired'        => __( 'Your reservation expired. Please choose your seats again.', 'seatmap-connect' ),
							'stage'          => __( 'Stage', 'seatmap-connect' ),
							'floors'         => __( 'Floor', 'seatmap-connect' ),
							'standingAreas'  => __( 'Standing and tables', 'seatmap-connect' ),
							/* translators: %d: number of places still available. */
							'placesLeft'     => __( '%d left', 'seatmap-connect' ),
							'soldOut'        => __( 'Sold out', 'seatmap-connect' ),
							/* translators: %s: name of the standing area. */
							'addOne'         => __( 'Add one place in %s', 'seatmap-connect' ),
							/* translators: %s: name of the standing area. */
							'removeOne'      => __( 'Remove one place in %s', 'seatmap-connect' ),
							'areaFull'       => __( 'That area filled up while you were choosing. Please pick a different number of places.', 'seatmap-connect' ),
							// The block view: the plan of areas a buyer sees before zooming into one.
							'chooseSection'  => __( 'Choose an area', 'seatmap-connect' ),
							'backToPlan'     => __( 'Back to the whole venue', 'seatmap-connect' ),
							/* translators: %s: the price of the cheapest seat in the area. */
							'sectionFrom'    => __( 'From %s', 'seatmap-connect' ),
							/* translators: %d: number of seats still available in the area. */
							'sectionSeatsLeft' => __( '%d seats left', 'seatmap-connect' ),
							'sectionSoldOut' => __( 'Sold out', 'seatmap-connect' ),
							/* translators: %s: name of the area. */
							'openSection'    => __( 'Show seats in %s', 'seatmap-connect' ),
							/* translators: %s: name of the area. */
							'inSection'      => __( 'In %s', 'seatmap-connect' ),
							// A room sold by the head: areas with a capacity and no chair to click.
							'chooseTickets'  => __( 'Choose your tickets', 'seatmap-connect' ),
							'ticketTypes'    => __( 'Tickets', 'seatmap-connect' ),
							'ticketTypeFor'  => __( 'Who is this ticket for?', 'seatmap-connect' ),
							'noneChosen'     => __( 'Nothing chosen yet.', 'seatmap-connect' ),
							'reserveTickets' => __( 'Reserve and add to cart', 'seatmap-connect' ),
							/* translators: %d: the largest number of tickets one order may hold. */
							'maxTickets'     => __( 'You can take up to %d tickets.', 'seatmap-connect' ),
							/* translators: %s: the seat or area being taken off the order. */
							'removeLine'     => __( 'Remove %s', 'seatmap-connect' ),
							// The disclosure the chairs are folded into; the plan is where they are picked.
							'seatList'       => __( 'Seat list', 'seatmap-connect' ),
							// Timed entry: a window a buyer has to choose before anything can be
							// reserved, on an event whose limit is the room rather than the chair.
							'arrivalTime'    => __( 'Arrival time', 'seatmap-connect' ),
							'chooseArrival'  => __( 'Choose when you will arrive', 'seatmap-connect' ),
							'arrivalFull'    => __( 'That arrival time filled up while you were choosing. Please pick another.', 'seatmap-connect' ),
							'arrivalNeeded'  => __( 'Choose an arrival time before reserving.', 'seatmap-connect' ),
						),
					)
				)
			)
		);

		return sprintf(
			'<div class="seatmap-widget" id="%s" data-event="%s"><noscript>%s</noscript></div>',
			esc_attr( $container_id ),
			esc_attr( $event_public_id ),
			esc_html__( 'Choosing seats needs JavaScript. Please enable it, or contact the box office.', 'seatmap-connect' )
		);
	}

	private function notice( string $message ): string {
		return sprintf( '<div class="seatmap-widget seatmap-widget--notice">%s</div>', esc_html( $message ) );
	}
}
