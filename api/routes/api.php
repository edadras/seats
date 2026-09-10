<?php

use App\Http\Controllers\Api\V1\Checkin\CheckinController;
use App\Http\Controllers\Api\V1\Embed\EmbedController;
use App\Http\Controllers\Api\V1\Integrations\WooCommerceController;
use App\Http\Controllers\Api\V1\Admin\AuthController as ConsoleAuthController;
use App\Http\Controllers\Api\V1\Admin\ConsoleController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\Management\ApiClientController;
use App\Http\Controllers\Api\V1\Management\AuditController;
use App\Http\Controllers\Api\V1\Management\BoxOfficeController;
use App\Http\Controllers\Api\V1\Management\AuthController;
use App\Http\Controllers\Api\V1\Management\CustomerController;
use App\Http\Controllers\Api\V1\Management\AccessCodeController;
use App\Http\Controllers\Api\V1\Management\BasketRecoveryController;
use App\Http\Controllers\Api\V1\Management\ChannelQuotaController;
use App\Http\Controllers\Api\V1\Management\SeasonPassController;
use App\Http\Controllers\Api\V1\Management\VoucherController;
use App\Http\Controllers\Api\V1\Management\WaitingRoomController;
use App\Http\Controllers\Api\V1\Management\AddonController;
use App\Http\Controllers\Api\V1\Management\DiscountController;
use App\Http\Controllers\Api\V1\Management\DoorListController;
use App\Http\Controllers\Api\V1\Management\EntrySlotController;
use App\Http\Controllers\Api\V1\Management\EventController;
use App\Http\Controllers\Api\V1\Management\EventQuestionController;
use App\Http\Controllers\Api\V1\Management\MessagingController;
use App\Http\Controllers\Api\V1\Management\OverviewController;
use App\Http\Controllers\Api\V1\Management\ModuleController;
use App\Http\Controllers\Api\V1\Management\NotificationController;
use App\Http\Controllers\Api\V1\Management\OrderController;
use App\Http\Controllers\Api\V1\Management\RefundRequestController;
use App\Http\Controllers\Api\V1\Management\ReportController;
use App\Http\Controllers\Api\V1\Management\ReportPageController;
use App\Http\Controllers\Api\V1\Management\SeatMapController;
use App\Http\Controllers\Api\V1\Management\SeatPriceController;
use App\Http\Controllers\Api\V1\Management\SettlementController;
use App\Http\Controllers\Api\V1\Management\SiteController;
use App\Http\Controllers\Api\V1\Management\SiteThemeController;
use App\Http\Controllers\Api\V1\Management\TeamController;
use App\Http\Controllers\Api\V1\Management\TicketController;
use App\Http\Controllers\Api\V1\Management\TicketTypeController;
use App\Http\Controllers\Api\V1\Management\TwoFactorController;
use App\Http\Controllers\Api\V1\Management\WaitingListController as ManagementWaitingList;
use App\Http\Controllers\Api\V1\Management\VenueController;
use App\Http\Controllers\Api\V1\Management\WalletController;
use Illuminate\Support\Facades\Route;

/*
| API v1. Every group states its own authentication; there is no route here without one, except
| the embed group, which is public by design and exposes nothing that needs protecting.
*/

/*
 * A note about every `throttle:` below, and its third argument.
 *
 * Laravel keys an unnamed throttle on the signed-in user, or — for a guest — on the route's domain
 * and the caller's IP. Never on the route. Without a third argument every rationed route therefore
 * shares one counter, and the smallest limit anywhere becomes the limit everywhere: a panel group
 * allowing 240 a minute and one route inside it allowing 10 gave the whole panel 10, and a group
 * throttle and a route throttle both incremented that same counter, so the real ceiling was lower
 * still.
 *
 * The third argument is a prefix on the key. Giving each rationed thing its own word gives it its
 * own counter, which is what every one of these limits was written believing it had.
 */
Route::prefix('v1')->group(function () {

    // ---- Languages ----------------------------------------------------------------------
    // Public: the sign-in screen has words on it, so the catalogue has to be readable before
    // anyone has signed in. Nothing here is secret.
    Route::get('i18n', [LocaleController::class, 'index'])->middleware('throttle:120,1,i18n');
    Route::get('i18n/{locale}', [LocaleController::class, 'show'])->middleware('throttle:120,1,i18n');

    // ---- Signing yourself up ------------------------------------------------------------
    // Public by necessity, and throttled per address and per IP inside the controller as well as
    // here: this is the one endpoint that creates accounts and sends email to strangers.
    Route::get('plans', [SignupController::class, 'plans'])->middleware('throttle:60,1,plans');
    Route::post('signup', [SignupController::class, 'register'])->middleware('throttle:10,1,signup');
    Route::post('signup/verify', [SignupController::class, 'verify'])->middleware('throttle:20,1,signup-verify');
    Route::post('signup/resend', [SignupController::class, 'resend'])->middleware('throttle:10,1,signup-resend');

    // ---- Panel / management -------------------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1,login');
    // The second half of a sign-in. Its own route because the first half returns no token: what it
    // hands back is a challenge that is worth nothing on its own.
    Route::post('auth/login/two-factor', [AuthController::class, 'twoFactor'])
        ->middleware('throttle:20,1,login-2fa');

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:240,1,panel'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::middleware('idempotency')->group(function () {
            Route::post('venues', [VenueController::class, 'store']);
            Route::post('seat-maps', [SeatMapController::class, 'store']);
            Route::post('seat-maps/{map}/versions', [SeatMapController::class, 'saveVersion']);
            Route::post('seat-maps/{map}/publish', [SeatMapController::class, 'publish']);
            Route::post('events', [EventController::class, 'store']);
            Route::post('api-clients', [ApiClientController::class, 'store']);
            Route::post('api-clients/{client}/keys', [ApiClientController::class, 'rotate']);
            Route::post('sites', [SiteController::class, 'store']);
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
        // Put the same production on again on other nights. The copies are drafts.
        Route::post('events/{event}/repeat', [EventController::class, 'repeat']);
        // The night that is off, and the night that moved. One refunds everything and voids every
        // ticket; the other keeps them all and says so.
        // What the event is called, in each of the six languages the rest of the platform speaks.
        Route::get('events/{event}/translations', [EventController::class, 'translations']);
        Route::put('events/{event}/translations', [EventController::class, 'saveTranslations']);
        Route::post('events/{event}/cancel', [EventController::class, 'cancel']);
        Route::post('events/{event}/reschedule', [EventController::class, 'reschedule']);
        Route::put('events/{event}/pricing', [EventController::class, 'pricing']);
        // Who the tickets are for. Beside pricing because that is what a concession is: an
        // adjustment to the price the seat already has.
        // What the checkout asks. Beside the ticket types because both are decisions about what a
        // buyer is put through, saved as a whole list for the same reason.
        Route::get('events/{event}/questions', [EventQuestionController::class, 'index']);
        Route::put('events/{event}/questions', [EventQuestionController::class, 'replace']);
        // When people may come in, on an event whose limit is the room rather than the chair.
        // "Four together, please" — the commonest request at a window, answered by the same code
        // that answers it on the website.
        Route::get('events/{event}/best-available', [BoxOfficeController::class, 'suggest']);
        Route::get('events/{event}/entry-slots', [EntrySlotController::class, 'index']);
        Route::put('events/{event}/entry-slots', [EntrySlotController::class, 'replace']);
        Route::post('events/{event}/entry-slots/generate', [EntrySlotController::class, 'generate']);
        Route::get('events/{event}/ticket-types', [TicketTypeController::class, 'index']);
        Route::put('events/{event}/ticket-types', [TicketTypeController::class, 'replace']);
        // What is sold beside the tickets, and whether this night asks for a donation. One screen,
        // because "what else can somebody give you money for" is one question to an organiser.
        Route::get('events/{event}/addons', [AddonController::class, 'index']);
        Route::put('events/{event}/addons', [AddonController::class, 'replace']);
        // Prices for individual seats. Not part of the wholesale PUT above: a hall has twenty
        // thousand seats and a repricing usually touches eight.
        Route::get('events/{event}/seat-prices', [SeatPriceController::class, 'index']);
        Route::put('events/{event}/seat-prices', [SeatPriceController::class, 'update']);
        Route::get('events/{event}/stats', [EventController::class, 'stats']);
        Route::get('events/{event}/checkins', [EventController::class, 'checkins']);

        // ---- Who bought ------------------------------------------------------------------
        // Derived from the orders rather than stored beside them, so the list cannot drift from
        // what was actually sold. `export` is declared before `{customer}` on purpose: otherwise
        // the word "export" is a customer key that matches nobody.
        Route::get('customers', [CustomerController::class, 'index']);
        Route::get('customers/export', [CustomerController::class, 'export']);
        Route::get('customers/{customer}', [CustomerController::class, 'show']);
        /*
         * The two things a person may ask for about themselves.
         *
         * Behind `account.manage` rather than `orders.view`: finding a booking and being handed
         * somebody's whole history are different things to be trusted with. Erasure takes the
         * person out of the record and leaves the record — an organiser still has to be able to
         * tell a tax authority what last March came to.
         */
        Route::get('customers/{customer}/personal-data', [CustomerController::class, 'personalData'])
            ->middleware('throttle:20,1,personal-data');
        Route::post('customers/{customer}/erase', [CustomerController::class, 'erase'])
            ->middleware('throttle:10,1,erase');

        // ---- The box office ---------------------------------------------------------------
        // Refunding was reachable only over the signed integration API, which is the right answer
        // for a shop that owns the money and no answer at all for an organiser selling from their
        // own site.
        // The buyers waiting for an answer about their money, and the record of who asked.
        Route::get('refund-requests', [RefundRequestController::class, 'index']);
        Route::post('refund-requests/{refundRequest}/grant', [RefundRequestController::class, 'grant']);
        Route::post('refund-requests/{refundRequest}/decline', [RefundRequestController::class, 'decline']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/refund', [OrderController::class, 'refund']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{order}/resend', [OrderController::class, 'resend'])
            ->middleware('throttle:20,1,resend');

        // ---- Discount codes ---------------------------------------------------------------
        // `suggest` before `{discount}`, or the word "suggest" is a code id that matches nothing.
        Route::get('discounts', [DiscountController::class, 'index']);
        Route::get('discounts/suggest', [DiscountController::class, 'suggest']);
        Route::post('discounts', [DiscountController::class, 'store']);
        Route::get('discounts/{discount}', [DiscountController::class, 'show']);
        Route::patch('discounts/{discount}', [DiscountController::class, 'update']);
        Route::delete('discounts/{discount}', [DiscountController::class, 'destroy']);

        /*
         * How much of one night each channel may sell.
         *
         * A PUT of the whole set, like the prices and the ticket types: partial edits across a set
         * of limits are where "the agent still has last season's allocation" comes from.
         */
        Route::get('events/{event}/quotas', [ChannelQuotaController::class, 'index']);
        Route::put('events/{event}/quotas', [ChannelQuotaController::class, 'update']);

        // The door on a big sale, live. A read that also turns the handle — see the controller.
        Route::get('events/{event}/queue', [WaitingRoomController::class, 'show'])
            ->middleware('throttle:120,1,queue-watch');

        // ---- Unfinished baskets -----------------------------------------------------------------
        // Purchases somebody started and did not finish, and what came of writing to them.
        Route::get('baskets', [BasketRecoveryController::class, 'index']);
        Route::post('baskets/{basketRecovery}/send', [BasketRecoveryController::class, 'send'])
            ->middleware('throttle:30,1,basket-send');

        // ---- Season tickets -------------------------------------------------------------------
        // What a whole run costs bought at once. `series` before `{seasonPass}`, because the word
        // "series" is not a pass id and would match nothing.
        Route::get('season-passes', [SeasonPassController::class, 'index']);
        Route::get('season-passes/series', [SeasonPassController::class, 'series']);
        Route::post('season-passes', [SeasonPassController::class, 'store']);
        Route::get('season-passes/{seasonPass}', [SeasonPassController::class, 'show']);
        Route::patch('season-passes/{seasonPass}', [SeasonPassController::class, 'update']);
        Route::delete('season-passes/{seasonPass}', [SeasonPassController::class, 'destroy']);

        // ---- Vouchers ----------------------------------------------------------------------
        // Money the organiser owes somebody, as against a code that changes a price. Behind its
        // own permission: issuing one is issuing money, which is not the same authority as
        // running a promotion.
        Route::get('vouchers', [VoucherController::class, 'index']);
        Route::get('vouchers/suggest', [VoucherController::class, 'suggest']);
        Route::post('vouchers', [VoucherController::class, 'store']);
        Route::get('vouchers/{voucher}', [VoucherController::class, 'show']);
        Route::post('vouchers/{voucher}/void', [VoucherController::class, 'void']);

        // The other kind of code: not what a buyer pays, but whether they may buy at all.
        Route::get('access-codes', [AccessCodeController::class, 'index']);
        Route::get('access-codes/suggest', [AccessCodeController::class, 'suggest']);
        Route::post('access-codes', [AccessCodeController::class, 'store']);
        Route::get('access-codes/{accessCode}', [AccessCodeController::class, 'show']);
        Route::patch('access-codes/{accessCode}', [AccessCodeController::class, 'update']);
        Route::delete('access-codes/{accessCode}', [AccessCodeController::class, 'destroy']);

        // ---- The counter -------------------------------------------------------------------
        // Selling to the person in front of you: cash, an invoice to a school, or a comp.
        Route::get('events/{event}/counter', [BoxOfficeController::class, 'counter']);
        Route::post('events/{event}/sell', [BoxOfficeController::class, 'sell'])
            ->middleware('throttle:60,1,sell');

        // ---- The door ------------------------------------------------------------------------
        // Who is expected tonight. `export` before nothing, because it is a verb and not an id.
        Route::get('events/{event}/door-list', [DoorListController::class, 'index']);
        Route::get('events/{event}/door-list/export', [DoorListController::class, 'export']);

        // ---- The waiting list -----------------------------------------------------------------
        Route::get('events/{event}/waiting-list', [ManagementWaitingList::class, 'index']);
        Route::post('events/{event}/waiting-list/notify', [ManagementWaitingList::class, 'notify'])
            ->middleware('throttle:10,1,waiting-notify');

        // ---- A second step at the door --------------------------------------------------------
        // Always the signed-in person's own account: there is no way to set up, inspect or remove
        // somebody else's, because an administrator who could is an administrator who could sign
        // in as them.
        Route::get('auth/two-factor', [TwoFactorController::class, 'show']);
        Route::post('auth/two-factor', [TwoFactorController::class, 'begin'])
            ->middleware('throttle:10,1,2fa');
        Route::post('auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('throttle:10,1,2fa-confirm');
        Route::post('auth/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->middleware('throttle:10,1,2fa-codes');
        Route::delete('auth/two-factor', [TwoFactorController::class, 'disable'])
            ->middleware('throttle:10,1,2fa-off');
        Route::post('account/two-factor-requirement', [TwoFactorController::class, 'require']);

        Route::get('tickets', [TicketController::class, 'index']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show']);
        Route::post('tickets/{ticket}/release', [TicketController::class, 'release']);

        // ---- Team, roles and the audit log ----------------------------------------------
        Route::get('team', [TeamController::class, 'index']);
        Route::patch('team/members/{member}', [TeamController::class, 'updateMember']);
        Route::post('team/invitations', [TeamController::class, 'invite']);
        Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation']);

        Route::get('roles', [TeamController::class, 'roles']);
        Route::post('roles', [TeamController::class, 'storeRole']);
        Route::patch('roles/{role}', [TeamController::class, 'updateRole']);
        Route::delete('roles/{role}', [TeamController::class, 'destroyRole']);

        // ---- Reports (ADR-0006) --------------------------------------------------------
        // There is no query box here and there will not be one: a definition names fields a
        // source declared, and anything else is refused before a query is built.
        Route::get('reports/sources', [ReportController::class, 'sources']);
        Route::post('reports/run', [ReportController::class, 'run']);
        Route::get('reports', [ReportController::class, 'index']);
        Route::post('reports', [ReportController::class, 'store']);
        Route::get('reports/{report}', [ReportController::class, 'show']);
        Route::patch('reports/{report}', [ReportController::class, 'update']);
        Route::delete('reports/{report}', [ReportController::class, 'destroy']);
        Route::get('reports/{report}/run', [ReportController::class, 'runSaved']);
        Route::get('reports/{report}/export', [ReportController::class, 'export']);

        // What the organiser is owed, and what the platform kept. Its own screen rather than a
        // report definition: the arithmetic of a refund and a commission is not a sum over a
        // column, and a builder that could express it would be a query box.
        Route::get('settlement', [SettlementController::class, 'index']);
        Route::get('settlement/export', [SettlementController::class, 'export']);
        Route::get('settlement/statement', [SettlementController::class, 'statement']);

        Route::get('report-pages', [ReportPageController::class, 'index']);
        Route::post('report-pages', [ReportPageController::class, 'store']);
        Route::get('report-pages/{page}', [ReportPageController::class, 'show']);
        Route::patch('report-pages/{page}', [ReportPageController::class, 'update']);
        Route::delete('report-pages/{page}', [ReportPageController::class, 'destroy']);

        // The panel's first screen, in one request: six spinners settling at different times is
        // not a first impression. What it may include is decided permission by permission inside.
        Route::get('overview', [OverviewController::class, 'index']);

        // Where an organiser puts their own Apple and Google credentials. Nothing secret comes
        // back out: the screen says whether each half is configured, never what it holds.
        Route::get('wallet', [WalletController::class, 'show']);
        Route::put('wallet', [WalletController::class, 'update']);
        Route::post('wallet/test', [WalletController::class, 'test']);

        // Read-only, by construction. An audit trail an administrator can edit is a diary.
        Route::get('audit', [AuditController::class, 'index']);
        Route::get('audit/facets', [AuditController::class, 'facets']);

        // ---- Messaging -----------------------------------------------------------------
        // The wording is the organiser's; the fallback is ours, translated. The log is here
        // because "did the buyer get their confirmation" is asked with somebody waiting.
        // ---- What the platform is telling this account ---------------------------------
        // Every member may ask; which notices they are shown is decided by the permission each
        // kind is governed by, on the way out.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read']);

        Route::get('messaging', [MessagingController::class, 'index']);
        // Ahead of the {kind} routes below, which would otherwise match the word "announcements".
        Route::get('messaging/announcements', [MessagingController::class, 'announcements']);
        Route::get('messaging/announcements/audience', [MessagingController::class, 'audience']);
        Route::post('messaging/announcements', [MessagingController::class, 'announce'])
            ->middleware('throttle:10,1,announce');
        Route::get('messaging/log', [MessagingController::class, 'log']);
        Route::post('messaging/test', [MessagingController::class, 'test'])->middleware('throttle:20,1,messaging-test');
        Route::put('messaging/{kind}/channels/{channel}', [MessagingController::class, 'setChannel']);
        Route::get('messaging/{kind}/{channel}/{locale}', [MessagingController::class, 'template']);
        Route::put('messaging/{kind}/{channel}/{locale}', [MessagingController::class, 'saveTemplate']);
        Route::delete('messaging/{kind}/{channel}/{locale}', [MessagingController::class, 'resetTemplate']);

        // ---- Modules (ADR-0004) --------------------------------------------------------
        // There is no install endpoint and there will not be one: what code runs on a server is
        // an operator's decision, made by deploying.
        Route::get('modules', [ModuleController::class, 'index']);
        Route::get('modules/{key}', [ModuleController::class, 'show']);
        Route::patch('modules/{key}', [ModuleController::class, 'update']);

        // ---- Hosted event sites (ADR-0003) ---------------------------------------------
        Route::get('site-themes', [SiteController::class, 'themes']);

        // Themes an organiser writes: tokens and a stylesheet, never code (ADR-0003).
        Route::get('themes', [SiteThemeController::class, 'index']);
        Route::post('themes', [SiteThemeController::class, 'store']);
        Route::get('themes/{theme}', [SiteThemeController::class, 'show']);
        Route::patch('themes/{theme}', [SiteThemeController::class, 'update']);
        Route::post('themes/{theme}/versions/{version}/revert', [SiteThemeController::class, 'revert']);
        Route::delete('themes/{theme}', [SiteThemeController::class, 'destroy']);

        Route::get('sites', [SiteController::class, 'index']);
        Route::get('sites/{site}', [SiteController::class, 'show']);
        Route::patch('sites/{site}', [SiteController::class, 'update']);

        Route::post('sites/{site}/pages', [SiteController::class, 'storePage']);
        Route::patch('sites/{site}/pages/{page}', [SiteController::class, 'updatePage']);
        Route::post('sites/{site}/pages/{page}/publish', [SiteController::class, 'publishPage']);
        Route::delete('sites/{site}/pages/{page}', [SiteController::class, 'destroyPage']);

        Route::put('sites/{site}/menus/{key}', [SiteController::class, 'updateMenu']);

        Route::post('sites/{site}/domains', [SiteController::class, 'storeDomain']);
        Route::post('sites/{site}/domains/{domain}/verify', [SiteController::class, 'verifyDomain']);
        Route::post('sites/{site}/domains/{domain}/primary', [SiteController::class, 'makeDomainPrimary']);
        Route::delete('sites/{site}/domains/{domain}', [SiteController::class, 'destroyDomain']);

        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::delete('api-clients/{client}/keys/{keyId}', [ApiClientController::class, 'revoke']);
    });

    // ---- The platform's own console -------------------------------------------------------
    // Its own middleware, its own table of operators, its own audit log. It shares no route and no
    // permission with an organiser's panel: the moment it did, a bug in one would be a bug in the
    // other, and the blast radius would be everybody.
    // An operator belongs to no organiser, so the panel's login cannot serve them: it answers
    // "which account is this person a member of", and the answer here is none.
    Route::post('admin/login', [ConsoleAuthController::class, 'login'])->middleware('throttle:20,1,console-login');

    Route::middleware(['auth:sanctum', 'platform', 'throttle:120,1,console'])->prefix('admin')->group(function () {
        Route::get('overview', [ConsoleController::class, 'overview']);
        Route::get('tenants', [ConsoleController::class, 'tenants']);
        Route::get('tenants/{tenant}', [ConsoleController::class, 'tenant']);
        Route::patch('tenants/{tenant}', [ConsoleController::class, 'updateTenant']);
        Route::post('tenants/{tenant}/impersonate', [ConsoleController::class, 'impersonate']);
        Route::get('sites', [ConsoleController::class, 'sites']);
        Route::get('audit', [ConsoleController::class, 'audit']);

        Route::get('plans', [PlanController::class, 'index']);
        Route::post('plans', [PlanController::class, 'store']);
        Route::patch('plans/{plan}', [PlanController::class, 'update']);
        Route::delete('plans/{plan}', [PlanController::class, 'destroy']);
    });

    // ---- Public widget ------------------------------------------------------------------
    // No authentication: the browser has no secret to hold. Rate limits are per IP, and hold
    // creation is limited harder than reads because it consumes inventory (threat T8).
    Route::prefix('embed')->group(function () {
        Route::middleware('throttle:120,1,embed-read')->group(function () {
            Route::get('events/{public_id}', [EmbedController::class, 'show']);
            Route::get('events/{public_id}/seat-map', [EmbedController::class, 'seatMap']);
            Route::get('events/{public_id}/availability', [EmbedController::class, 'availability']);
        });

        Route::middleware(['throttle:30,1,embed-hold', 'idempotency'])->group(function () {
            Route::post('events/{public_id}/holds', [EmbedController::class, 'hold']);
        });

        Route::middleware('throttle:60,1,embed-holds')->group(function () {
            Route::patch('holds/{token}/extend', [EmbedController::class, 'extendHold']);
            Route::delete('holds/{token}', [EmbedController::class, 'releaseHold']);
            Route::post('holds/{token}/validate', [EmbedController::class, 'validateHold']);
        });
    });

    // ---- Storefront, server to server ---------------------------------------------------
    Route::prefix('integrations/woocommerce')
        ->middleware(['api.client', 'idempotency', 'throttle:300,1,woocommerce'])
        ->group(function () {
            Route::post('orders', [WooCommerceController::class, 'store']);
            Route::get('orders/{external_order_id}', [WooCommerceController::class, 'show']);
            Route::post('orders/{external_order_id}/confirm', [WooCommerceController::class, 'confirm']);
            Route::post('orders/{external_order_id}/cancel', [WooCommerceController::class, 'cancel']);
            Route::post('orders/{external_order_id}/refund', [WooCommerceController::class, 'refund']);
        });

    // ---- Check-in devices ---------------------------------------------------------------
    Route::prefix('checkin')->group(function () {
        Route::post('auth/token', [CheckinController::class, 'token'])->middleware('throttle:10,1,device-pair');

        Route::middleware(['auth:sanctum', 'device', 'throttle:600,1,door'])->group(function () {
            Route::get('events', [CheckinController::class, 'events']);
            Route::post('scan', [CheckinController::class, 'scan']);
            Route::post('sync', [CheckinController::class, 'sync']);
            Route::get('events/{event}/stats', [CheckinController::class, 'stats']);
        });
    });
});
