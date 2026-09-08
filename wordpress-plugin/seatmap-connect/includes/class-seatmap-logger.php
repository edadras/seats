<?php
/**
 * Thin wrapper over WooCommerce logging.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

class Seatmap_Logger {

	/**
	 * Record something worth finding later.
	 *
	 * When an order's seats do not arrive, the shop owner's first question is "what did the API
	 * say?" — so failures are logged with the order reference, and never with the secret.
	 */
	public static function log( string $context, string $message, string $level = 'info' ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log(
			$level,
			sprintf( '[%s] %s', $context, $message ),
			array( 'source' => 'seatmap-connect' )
		);
	}

	public static function error( string $context, string $message ): void {
		self::log( $context, $message, 'error' );
	}
}
