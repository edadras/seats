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
use App\Http\Controllers\Api\V1\Management\AuthController;
use App\Http\Controllers\Api\V1\Management\EventController;
use App\Http\Controllers\Api\V1\Management\MessagingController;
use App\Http\Controllers\Api\V1\Management\OverviewController;
use App\Http\Controllers\Api\V1\Management\ModuleController;
use App\Http\Controllers\Api\V1\Management\ReportController;
use App\Http\Controllers\Api\V1\Management\ReportPageController;
use App\Http\Controllers\Api\V1\Management\SeatMapController;
use App\Http\Controllers\Api\V1\Management\SeatPriceController;
use App\Http\Controllers\Api\V1\Management\SiteController;
use App\Http\Controllers\Api\V1\Management\SiteThemeController;
use App\Http\Controllers\Api\V1\Management\TeamController;
use App\Http\Controllers\Api\V1\Management\TicketController;
use App\Http\Controllers\Api\V1\Management\VenueController;
use Illuminate\Support\Facades\Route;

/*
| API v1. Every group states its own authentication; there is no route here without one, except
| the embed group, which is public by design and exposes nothing that needs protecting.
*/

Route::prefix('v1')->group(function () {

    // ---- Languages ----------------------------------------------------------------------
    // Public: the sign-in screen has words on it, so the catalogue has to be readable before
    // anyone has signed in. Nothing here is secret.
    Route::get('i18n', [LocaleController::class, 'index'])->middleware('throttle:120,1');
    Route::get('i18n/{locale}', [LocaleController::class, 'show'])->middleware('throttle:120,1');

    // ---- Signing yourself up ------------------------------------------------------------
    // Public by necessity, and throttled per address and per IP inside the controller as well as
    // here: this is the one endpoint that creates accounts and sends email to strangers.
    Route::get('plans', [SignupController::class, 'plans'])->middleware('throttle:60,1');
    Route::post('signup', [SignupController::class, 'register'])->middleware('throttle:10,1');
    Route::post('signup/verify', [SignupController::class, 'verify'])->middleware('throttle:20,1');
    Route::post('signup/resend', [SignupController::class, 'resend'])->middleware('throttle:10,1');

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
        Route::put('events/{event}/pricing', [EventController::class, 'pricing']);
        // Prices for individual seats. Not part of the wholesale PUT above: a hall has twenty
        // thousand seats and a repricing usually touches eight.
        Route::get('events/{event}/seat-prices', [SeatPriceController::class, 'index']);
        Route::put('events/{event}/seat-prices', [SeatPriceController::class, 'update']);
        Route::get('events/{event}/stats', [EventController::class, 'stats']);
        Route::get('events/{event}/checkins', [EventController::class, 'checkins']);

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

        Route::get('report-pages', [ReportPageController::class, 'index']);
        Route::post('report-pages', [ReportPageController::class, 'store']);
        Route::get('report-pages/{page}', [ReportPageController::class, 'show']);
        Route::patch('report-pages/{page}', [ReportPageController::class, 'update']);
        Route::delete('report-pages/{page}', [ReportPageController::class, 'destroy']);

        // The panel's first screen, in one request: six spinners settling at different times is
        // not a first impression. What it may include is decided permission by permission inside.
        Route::get('overview', [OverviewController::class, 'index']);

        // Read-only, by construction. An audit trail an administrator can edit is a diary.
        Route::get('audit', [AuditController::class, 'index']);
        Route::get('audit/facets', [AuditController::class, 'facets']);

        // ---- Messaging -----------------------------------------------------------------
        // The wording is the organiser's; the fallback is ours, translated. The log is here
        // because "did the buyer get their confirmation" is asked with somebody waiting.
        Route::get('messaging', [MessagingController::class, 'index']);
        Route::get('messaging/log', [MessagingController::class, 'log']);
        Route::post('messaging/test', [MessagingController::class, 'test'])->middleware('throttle:20,1');
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
    Route::post('admin/login', [ConsoleAuthController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware(['auth:sanctum', 'platform', 'throttle:120,1'])->prefix('admin')->group(function () {
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
