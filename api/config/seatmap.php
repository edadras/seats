<?php

return [
    /*
     | Defaults for new events. Each event stores its own copy so changing this never alters the
     | terms an in-flight sale was made under.
     */
    'hold' => [
        'ttl_seconds' => (int) env('SEATMAP_HOLD_TTL_SECONDS', 600),
        'max_extends' => (int) env('SEATMAP_HOLD_MAX_EXTENDS', 2),
        'max_seats' => (int) env('SEATMAP_MAX_SEATS_PER_HOLD', 20),
        // Cap on concurrent live holds per browser session, to blunt inventory-denial attacks.
        'max_active_per_session' => (int) env('SEATMAP_MAX_HOLDS_PER_SESSION', 3),
    ],

    'hmac_window_seconds' => (int) env('SEATMAP_HMAC_WINDOW_SECONDS', 300),

    /*
     | Key for signing price snapshots handed to storefronts. Rotating it invalidates signatures on
     | holds already in flight; those holds still work server-side because the server trusts its own
     | stored snapshot, not the client's copy.
     */
    'signing_key' => env('SEATMAP_SIGNING_KEY', ''),

    /*
     | Ceilings applied when validating a seat map, before anything is persisted. These stop a
     | crafted or accidental upload from exhausting memory (threat T11).
     */
    'limits' => [
        'max_seats_per_map' => (int) env('SEATMAP_MAX_SEATS_PER_MAP', 50000),
        'max_sections_per_map' => (int) env('SEATMAP_MAX_SECTIONS', 500),
        'max_rows_per_section' => (int) env('SEATMAP_MAX_ROWS_PER_SECTION', 500),
        'max_geometry_bytes' => (int) env('SEATMAP_MAX_GEOMETRY_BYTES', 8 * 1024 * 1024),
        'max_shapes' => (int) env('SEATMAP_MAX_SHAPES', 2000),
        'max_texts' => (int) env('SEATMAP_MAX_TEXTS', 2000),
        'canvas_max' => 20000,
    ],

    'webhooks' => [
        // Exponential backoff, in seconds, one entry per attempt.
        'retry_delays' => [10, 60, 300, 1800, 7200, 21600],
        'timeout_seconds' => 10,
        'dead_after_failures' => 20,
    ],

    /*
     | Hosted event sites (ADR-0003).
     |
     | `panel_hosts` is the allow-list for the control panel. Every other Host is looked up as a
     | site domain, so a hostname that is neither is a 404 rather than a panel someone was not
     | meant to reach.
     */
    'sites' => [
        'panel_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SEATMAP_PANEL_HOSTS', ''))
        ))),

        // Scheme used when building canonical URLs and links in email.
        'scheme' => env('SEATMAP_SITE_SCHEME', 'https'),

        // Subdomain every new site gets for free, before a custom domain is verified.
        'default_domain' => env('SEATMAP_SITES_DOMAIN', ''),

        // How long a hostname lookup is cached. A miss is cached too, so an unknown Host cannot be
        // used to hammer the database.
        'resolution_ttl_seconds' => (int) env('SEATMAP_SITE_RESOLUTION_TTL', 300),
        'miss_ttl_seconds' => (int) env('SEATMAP_SITE_MISS_TTL', 30),

        'limits' => [
            'max_pages' => (int) env('SEATMAP_SITE_MAX_PAGES', 200),
            'max_blocks_per_page' => (int) env('SEATMAP_SITE_MAX_BLOCKS', 100),
            'max_menu_items' => (int) env('SEATMAP_SITE_MAX_MENU_ITEMS', 60),
            'max_domains' => (int) env('SEATMAP_SITE_MAX_DOMAINS', 5),
        ],
    ],

    /*
     | Signing a buyer in.
     |
     | One Google client for the whole platform, with one redirect URI: a customer's own domain
     | cannot be registered in our Google project, and asking every organiser to create their own
     | Google Cloud project would mean nobody turns this on. So the round trip to Google happens on
     | the platform's own host and hands the buyer back to their site with a one-time token.
     |
     | Unset credentials mean the feature does not exist: no button on any site, and the panel says
     | why rather than offering a switch that cannot work.
     */
    'signin' => [
        'google' => [
            'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => (string) env('GOOGLE_CLIENT_SECRET', ''),
            // Defaults to <app.url>/auth/google/callback, which is what to register with Google.
            'redirect' => (string) env('GOOGLE_REDIRECT_URL', ''),
        ],
    ],

    /*
     | Modules (ADR-0004).
     |
     | `path` is where installed modules live. What is installed is a property of the deployment —
     | an operator decides it by deploying — so it is a path, not a table an admin panel could edit.
     |
     | `max_failures` is how many times a module may throw inside `failure_window_hours` before the
     | platform switches it off for that tenant and says why. Failing silently forever is the one
     | outcome worse than being off.
     */
    'modules' => [
        'path' => env('SEATMAP_MODULES_PATH', base_path('../modules')),
        'max_failures' => (int) env('SEATMAP_MODULE_MAX_FAILURES', 20),
        'failure_window_hours' => (int) env('SEATMAP_MODULE_FAILURE_WINDOW', 24),
    ],

    /*
     | How long a link keeps the credit for a sale.
     |
     | Thirty days is the convention rather than a fact, which is why it is here: a house that
     | sells a season six months out will want longer, and one selling a club night will want less.
     */
    'attribution' => [
        'window_days' => (int) env('SEATMAP_ATTRIBUTION_WINDOW_DAYS', 30),
    ],

    /*
     | The platform's own money (ADR-0001 drew the boundary the other way round: this is the one
     | place the platform is the merchant rather than the organiser).
     |
     | `mode` decides how an invoice gets paid:
     |
     |   invoice — the platform raises it and somebody pays by transfer. An operator marks it paid
     |             in the console. This is the default, and the only mode a self-hosted deployment
     |             needs: it needs no credentials and invents no card vault.
     |   card    — a card the organiser put on file is charged off-session through the *platform's*
     |             own Stripe account. Not a tenant's module: those hold an organiser's credentials
     |             and take money into an organiser's account, which is the opposite of this.
     |
     | `suspend_after_days` is deliberately null. Cutting a venue off over an unpaid invoice is a
     | decision with a box office and a full house on the other end of it, so a deployment has to
     | ask for it: without it, billing marks an account past due, says so loudly on every screen,
     | tells the people who run the platform, and stops there.
     */
    'billing' => [
        'mode' => env('SEATMAP_BILLING_MODE', 'invoice'),
        'currency' => mb_strtoupper((string) env('SEATMAP_BILLING_CURRENCY', 'EUR')),

        // What the platform adds to its own invoices, in basis points. 2000 is 20%.
        'vat_rate' => (int) env('SEATMAP_BILLING_VAT_RATE', 0),
        'vat_number' => (string) env('SEATMAP_BILLING_VAT_NUMBER', ''),

        // Days after an invoice is issued before it is due, and the retry ladder after that —
        // days from the due date, one entry per attempt.
        'terms_days' => (int) env('SEATMAP_BILLING_TERMS_DAYS', 14),
        'retry_days' => [0, 3, 7, 14],

        'suspend_after_days' => null === env('SEATMAP_BILLING_SUSPEND_AFTER_DAYS')
            ? null
            : (int) env('SEATMAP_BILLING_SUSPEND_AFTER_DAYS'),

        // The platform's own Stripe account, used only in `card` mode. Empty means no card can be
        // put on file, and the panel says so rather than offering a button that cannot work.
        'stripe' => [
            'secret_key' => (string) env('SEATMAP_BILLING_STRIPE_SECRET', ''),
        ],
    ],

    'checkin' => [
        'pairing_code_ttl_minutes' => 30,
        'max_batch_scans' => 500,
    ],
];
