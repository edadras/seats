<?php

use App\Http\Controllers\Api\V1\Checkin\CheckinController;
use App\Http\Controllers\Api\V1\Embed\EmbedController;
use App\Http\Controllers\Api\V1\Integrations\WooCommerceController;
use App\Http\Controllers\Api\V1\Management\ApiClientController;
use App\Http\Controllers\Api\V1\Management\AuthController;
use App\Http\Controllers\Api\V1\Management\EventController;
use App\Http\Controllers\Api\V1\Management\SeatMapController;
use App\Http\Controllers\Api\V1\Management\TicketController;
use App\Http\Controllers\Api\V1\Management\VenueController;
use Illuminate\Support\Facades\Route;

/*
| API v1. Every group states its own authentication; there is no route here without one, except
| the embed group, which is public by design and exposes nothing that needs protecting.
*/

Route::prefix('v1')->group(function () {

    // ---- Panel / management -------------------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:240,1'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('idempotency')->group(function () {
            Route::post('venues', [VenueController::class, 'store']);
            Route::post('seat-maps', [SeatMapController::class, 'store']);
            Route::post('seat-maps/{map}/versions', [SeatMapController::class, 'saveVersion']);
            Route::post('seat-maps/{map}/publish', [SeatMapController::class, 'publish']);
            Route::post('events', [EventController::class, 'store']);
            Route::post('api-clients', [ApiClientController::class, 'store']);
            Route::post('api-clients/{client}/keys', [ApiClientController::class, 'rotate']);
        });

        Route::get('venues', [VenueController::class, 'index']);
        Route::get('venues/{venue}', [VenueController::class, 'show']);
        Route::patch('venues/{venue}', [VenueController::class, 'update']);
        Route::delete('venues/{venue}', [VenueController::class, 'destroy']);

        Route::get('seat-maps', [SeatMapController::class, 'index']);
        Route::get('seat-maps/{map}', [SeatMapController::class, 'show']);
        Route::patch('seat-maps/{map}', [SeatMapController::class, 'update']);
        Route::get('seat-maps/{map}/versions', [SeatMapController::class, 'versions']);
        Route::post('seat-maps/{map}/validate', [SeatMapController::class, 'validateGeometry']);

        Route::get('events', [EventController::class, 'index']);
        Route::get('events/{event}', [EventController::class, 'show']);
        Route::patch('events/{event}', [EventController::class, 'update']);
        Route::put('events/{event}/pricing', [EventController::class, 'pricing']);
        Route::get('events/{event}/stats', [EventController::class, 'stats']);
        Route::get('events/{event}/checkins', [EventController::class, 'checkins']);

        Route::get('tickets/{ticket}', [TicketController::class, 'show']);

        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::delete('api-clients/{client}/keys/{keyId}', [ApiClientController::class, 'revoke']);
    });

    // ---- Public widget ------------------------------------------------------------------
    // No authentication: the browser has no secret to hold. Rate limits are per IP, and hold
    // creation is limited harder than reads because it consumes inventory (threat T8).
    Route::prefix('embed')->group(function () {
        Route::middleware('throttle:120,1')->group(function () {
            Route::get('events/{public_id}', [EmbedController::class, 'show']);
            Route::get('events/{public_id}/seat-map', [EmbedController::class, 'seatMap']);
            Route::get('events/{public_id}/availability', [EmbedController::class, 'availability']);
        });

        Route::middleware(['throttle:30,1', 'idempotency'])->group(function () {
            Route::post('events/{public_id}/holds', [EmbedController::class, 'hold']);
        });

        Route::middleware('throttle:60,1')->group(function () {
            Route::patch('holds/{token}/extend', [EmbedController::class, 'extendHold']);
            Route::delete('holds/{token}', [EmbedController::class, 'releaseHold']);
            Route::post('holds/{token}/validate', [EmbedController::class, 'validateHold']);
        });
    });

    // ---- Storefront, server to server ---------------------------------------------------
    Route::prefix('integrations/woocommerce')
        ->middleware(['api.client', 'idempotency', 'throttle:300,1'])
        ->group(function () {
            Route::post('orders', [WooCommerceController::class, 'store']);
            Route::get('orders/{external_order_id}', [WooCommerceController::class, 'show']);
            Route::post('orders/{external_order_id}/confirm', [WooCommerceController::class, 'confirm']);
            Route::post('orders/{external_order_id}/cancel', [WooCommerceController::class, 'cancel']);
            Route::post('orders/{external_order_id}/refund', [WooCommerceController::class, 'refund']);
        });

    // ---- Check-in devices ---------------------------------------------------------------
    Route::prefix('checkin')->group(function () {
        Route::post('auth/token', [CheckinController::class, 'token'])->middleware('throttle:10,1');

        Route::middleware(['auth:sanctum', 'device', 'throttle:600,1'])->group(function () {
            Route::get('events', [CheckinController::class, 'events']);
            Route::post('scan', [CheckinController::class, 'scan']);
            Route::post('sync', [CheckinController::class, 'sync']);
            Route::get('events/{event}/stats', [CheckinController::class, 'stats']);
        });
    });
});
