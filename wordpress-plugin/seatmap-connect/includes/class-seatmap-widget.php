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
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
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
