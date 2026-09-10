<?php

use App\Http\Controllers\CheckinAppController;
use App\Http\Controllers\FrontDoorController;
use App\Http\Controllers\GoogleSignInController;
use App\Http\Controllers\Site\BuyerAccountController;
use App\Http\Controllers\Site\CheckoutController;
use App\Http\Controllers\Site\SiteFilesController;
use App\Http\Controllers\Site\SitePageController;
use App\Http\Controllers\Site\StoreController;
use App\Http\Controllers\Site\WaitingListController;
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
        // Throttled hard, like the discount box and for a sharper reason: a presale code is worth
        // guessing, and guessing is cheap unless it is rationed.
        Route::post('unlock', [StoreController::class, 'unlock'])->middleware('throttle:8,1');
    });

    // Where a picker embedded on somebody else's website sends the buyer to pay. It carries a
    // hold token this application issued and nothing else.
    Route::get('checkout/resume', [CheckoutController::class, 'resume'])->middleware('throttle:30,1');
    Route::get('checkout', [CheckoutController::class, 'show']);
    Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:20,1');
    // Throttled harder than the rest of checkout: a discount box is a place to guess codes, and
    // guessing is cheap unless it is rationed.
    Route::post('checkout/discount', [CheckoutController::class, 'applyDiscount'])
        ->middleware('throttle:10,1');
    Route::post('checkout/discount/remove', [CheckoutController::class, 'removeDiscount'])
        ->middleware('throttle:20,1');
    // What the booking would come to with these extras. A keystroke, so it is throttled loosely
    // and it writes nothing.
    Route::post('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:60,1');
    Route::get('order/{reference}', [CheckoutController::class, 'confirmation']);
    // The same tickets, laid out for paper and for the browser's own "Save as PDF".
    Route::get('order/{reference}/tickets', [CheckoutController::class, 'tickets']);
    // Straight into a phone, while the codes still exist in this session.
    Route::get('order/{reference}/wallet/{platform}', [CheckoutController::class, 'wallet'])
        ->whereIn('platform', ['apple', 'google'])
        ->middleware('throttle:30,1');
    // The document the buyer's accounts department will want. Numbered on the first ask, and only
    // for a booking that was actually paid for.
    Route::get('order/{reference}/invoice', [CheckoutController::class, 'invoice'])
        ->middleware('throttle:20,1');

    // Where a redirect gateway sends the buyer back to. Both verbs, because gateways disagree
    // about which one a return is, and the handler settles by asking the gateway rather than by
    // believing anything in the request.
    Route::match(['get', 'post'], 'pay/{gateway}/return/{reference}', [CheckoutController::class, 'paymentReturn'])
        ->middleware('throttle:60,1')
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

    Route::get('events/{event}', [SitePageController::class, 'event']);
    // A file a calendar will take, because a ticket bought in September is for a night in November.
    Route::get('events/{event}/calendar.ics', [SitePageController::class, 'calendar']);

    // The queue for a sold-out night, and the way out of it. Leaving is a GET because that is what
    // a mail client will follow, and repeating it changes nothing.
    Route::post('events/{event}/waiting-list', [WaitingListController::class, 'join'])
        ->middleware('throttle:10,1');
    Route::get('waiting-list/{token}/leave', [WaitingListController::class, 'leave'])
        ->middleware('throttle:30,1');

    /*
     * A buyer's own page. `/account` renders for anybody — signed in it lists their orders,
     * signed out it offers the button — and the rest of these 404 on a site that does not offer
     * signing in at all.
     */
    Route::get('account', [BuyerAccountController::class, 'show']);
    Route::get('account/google', [BuyerAccountController::class, 'start'])->middleware('throttle:20,1');
    Route::get('account/google/finish', [BuyerAccountController::class, 'finish'])
        ->middleware('throttle:20,1');
    Route::post('account/sign-out', [BuyerAccountController::class, 'signOut']);
    // A POST because it mints new codes and kills the old ones: a link a browser can prefetch
    // must never be able to invalidate somebody's ticket.
    Route::post('account/orders/{reference}/tickets', [BuyerAccountController::class, 'tickets'])
        ->middleware('throttle:10,1');
    // Handing one ticket to somebody else. A POST, and signed in: it kills a working code and
    // mints another, which is not something a link a mail client can prefetch should be able to do.
    // "Can I have my money back?" — granted at once inside the organiser's own terms, and put in
    // front of the box office outside them.
    // A reissue, like the PDF beside it: the old codes stop working, and the button says so.
    Route::post('account/orders/{reference}/wallet/{platform}', [BuyerAccountController::class, 'wallet'])
        ->whereIn('platform', ['apple', 'google'])
        ->middleware('throttle:20,1');
    Route::post('account/orders/{reference}/refund', [BuyerAccountController::class, 'refund'])
        ->middleware('throttle:10,1');
    Route::post('account/orders/{reference}/transfer', [BuyerAccountController::class, 'transfer'])
        ->middleware('throttle:10,1');
});

/*
 * The one redirect URI registered with Google, for every site on this platform. Ahead of the front
 * door because it belongs to the platform on every host, and outside the site group because there
 * is no site in its Host — the site it belongs to is named by the state it carries.
 */
Route::get('auth/google/callback', GoogleSignInController::class)->middleware('throttle:60,1');

/*
 * The two files written for machines. Outside the site group and ahead of the front door, because
 * they exist on every host this application answers on — including the panel, which is not a thing
 * to index. `public/robots.txt` used to answer for all of them with one file, which was the wrong
 * answer for at least one.
 */
Route::get('robots.txt', [SiteFilesController::class, 'robots']);
Route::get('sitemap.xml', [SiteFilesController::class, 'sitemap']);

/*
 * The platform's own console. Ahead of the front door, and a separate page from the panel: they
 * share a stylesheet and nothing else. What it shows is decided by the API, which checks
 * membership of `platform_admins` — this route serves the shell to anybody, and the shell can do
 * nothing without a token that passes that check.
 */
Route::get('console', fn () => view('console'));

// The door scanner. Ahead of the front door because /checkin belongs to the platform on every
// host: a site is a place to buy a ticket, never a place that answers for scanning one.
Route::get('checkin', CheckinAppController::class);
Route::get('checkin/{path}', CheckinAppController::class)->where('path', '.*');

Route::get('/', FrontDoorController::class);
Route::get('{path}', FrontDoorController::class)
    ->where('path', '^(?!v1|up|storage|site|checkin|console).*$');
