<?php

/*
 | CORS for the public widget endpoints.
 |
 | These are the only routes a browser calls directly. A cross-origin POST carrying JSON triggers a
 | preflight, and the framework's CORS middleware is what answers it — a route-level middleware
 | cannot, because no route matches the OPTIONS request in the first place.
 |
 | This is a browser convenience, not an access control: an attacker with curl ignores CORS
 | entirely. Authorisation for anything that matters lives in the endpoints themselves, and these
 | expose only what a venue already shows publicly (see THREAT_MODEL.md, T9).
 */

return [
    // `v1/i18n/*` is here for the same reason: a widget pasted into somebody's own website asks
    // for its words in the reader's language, and that request is cross-origin too. It is a
    // catalogue of interface strings and it is already public.
    'paths' => ['v1/embed/*', 'v1/i18n/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    // The widget is embedded on tenants' own sites, which we do not enumerate here.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Idempotency-Key', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    // No cookies are involved: the widget carries no session and no credential.
    'supports_credentials' => false,
];
