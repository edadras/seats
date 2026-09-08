<?php

use App\Http\Controllers\CheckinAppController;
use App\Http\Controllers\FrontDoorController;
use App\Http\Controllers\Site\CheckoutController;
use App\Http\Controllers\Site\SitePageController;
use App\Http\Controllers\Site\StoreController;
use Illuminate\Support\Facades\Route;

/*
| Two things are served from here — the control panel and organisers' event sites — and which one a
| request gets is decided by its Host and nothing else (ADR-0003 §1).
|
| The site's own routes come first because they are specific. Everything else falls through to
| FrontDoorController, which is where the panel-or-site decision is actually made; the comment on
| that class explains why it cannot be made by the router.
*/

Route::middleware('site')->group(function () {
    // What the seat picker talks to. Same shapes as the WordPress plugin's store routes, because it
    // is one shared picker that must not know which kind of shop it sits in.
    Route::prefix('_store')->group(function () {
        Route::get('availability/{event}', [StoreController::class, 'availability'])
            ->middleware('throttle:120,1');
        Route::post('hold', [StoreController::class, 'hold'])->middleware('throttle:30,1');
        Route::post('release', [StoreController::class, 'release'])->middleware('throttle:60,1');
    });

    Route::get('checkout', [CheckoutController::class, 'show']);
    Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:20,1');
    Route::get('order/{reference}', [CheckoutController::class, 'confirmation']);

    Route::get('events/{event}', [SitePageController::class, 'event']);
});

// The door scanner. Ahead of the front door because /checkin belongs to the platform on every
// host: a site is a place to buy a ticket, never a place that answers for scanning one.
Route::get('checkin', CheckinAppController::class);
Route::get('checkin/{path}', CheckinAppController::class)->where('path', '.*');

Route::get('/', FrontDoorController::class);
Route::get('{path}', FrontDoorController::class)
    ->where('path', '^(?!v1|up|storage|site|checkin).*$');
