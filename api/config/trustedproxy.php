<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Which proxies may speak for a client
    |---------------------------------------------------------------------------
    |
    | This file exists for one reason, and it is not cosmetic: almost everything
    | this platform rate-limits, it rate-limits *per IP address*. Signing in,
    | signing up, claiming an SSO account, how many seats one person may hold,
    | the bot defence on the picker — all of them key on `$request->ip()`, and
    | every audit row records it.
    |
    | Behind a reverse proxy with nothing trusted, `$request->ip()` is the
    | proxy's address for every request on the server. The effects are quiet and
    | bad: a hundred buyers at an on-sale share one throttle bucket, so the
    | tenth one to try is refused on everyone else's behalf; and every line in
    | the audit log says the same thing, which is worth nothing the day somebody
    | asks who cancelled an order.
    |
    | So it must be set on any deployment that sits behind nginx, Caddy, a load
    | balancer or a CDN. It must *not* be set to a catch-all on a deployment
    | that is directly exposed, because then any client can put whatever it
    | likes in `X-Forwarded-For` and walk past the same limits from one machine.
    | There is no default that is right for both, which is why this is empty and
    | `php artisan seatmap:preflight` asks about it out loud rather than
    | guessing.
    |
    | Read through `config()` rather than `env()` on purpose: a cached
    | configuration means the `.env` file is never loaded, so an `env()` call in
    | `bootstrap/app.php` would return null on exactly the production machines
    | that run `config:cache` — the failure would appear only after a deploy
    | optimisation, which is the worst possible time to discover it.
    |
    | Accepts a comma-separated list of addresses or CIDR blocks, the literal
    | `REMOTE_ADDR` (trust whatever is immediately in front of us), or `*`.
    |
    |   SEATMAP_TRUSTED_PROXIES=127.0.0.1,::1      # nginx on the same host
    |   SEATMAP_TRUSTED_PROXIES=10.0.0.0/8        # a load balancer in a VPC
    |
    | Which headers are believed is *not* configurable here, and is narrowed in
    | `bootstrap/app.php` — see the note there about `X-Forwarded-Host`.
    |
    */

    'proxies' => env('SEATMAP_TRUSTED_PROXIES'),

];
