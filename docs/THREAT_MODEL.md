# Threat model

Scope: the SaaS API, the tenant panel, the embeddable widget, and the WordPress plugin that
connects a tenant's site to the API. Out of scope: the tenant's payment gateway, the WordPress
core/host security, and the organiser's own staff device security.

## Assets

| Asset | Why it matters |
| --- | --- |
| Seat inventory (holds, allocations) | Double-selling a seat is the product's core failure mode. |
| API client secrets | Grant server-to-server authority to confirm sales for a tenant. |
| Ticket QR tokens | Bearer credentials for venue entry. |
| Tenant data (venues, maps, events, buyer contact) | Confidentiality between competing organisers; personal data. |
| Price snapshots | If forgeable, a buyer sets their own price. |

## Trust boundaries

1. **Browser → SaaS (widget).** Fully untrusted. Holds only a public event id and a short-lived
   hold token. Never holds a secret.
2. **WordPress site → SaaS (server-to-server).** Semi-trusted: authenticated as a tenant's API
   client, but the site itself may be compromised. Authority is scoped to that tenant only.
3. **Tenant panel user → SaaS.** Authenticated session, authorised per tenant and role.
4. **Check-in device → SaaS.** Scoped token, valid for named events only.
5. **Tenant A → Tenant B.** A hard boundary inside one database.

## Threats and mitigations

### T1 — Cross-tenant data access (STRIDE: Information disclosure, Elevation)
An authenticated user or API client of tenant A reads or mutates tenant B's venue, event, seat,
ticket or statistics by guessing an id.

*Mitigations.* `tenant_id` is mandatory on every business table. A global Eloquent scope applies
it to every query for tenant-scoped models; the base model refuses to save without it. Route
model binding resolves through the scope, so a foreign id is a 404 and not a 403 (no existence
oracle). Cache keys, queue payloads, exports and log context are all tenant-prefixed. Policies
are additionally asserted per action. A dedicated test suite (`TenantIsolationTest`) walks every
API route with tenant A's credentials against tenant B's ids and requires 404/403.

### T2 — Double-selling a seat (Tampering, Denial of service)
Concurrent requests, or a retried confirm, allocate one seat twice.

*Mitigations.* Transaction + deterministic row locking + **partial unique indexes** as the final
arbiter (ADR-0002). Confirm is idempotent on `(api_client_id, external_order_id)` and on
`Idempotency-Key`; the stored response is replayed rather than the work being redone. Tested
with 100 concurrent processes against Postgres.

### T3 — Price tampering (Tampering)
A buyer edits the price in the browser, or replays a cheap hold token against an expensive seat.

*Mitigations.* The plugin never reads price from the DOM. `POST /holds` returns a
`price_snapshot` signed (HMAC-SHA256, tenant-scoped key) over
`hold_token | event_id | seat_ids | amounts | currency | map_version | expires_at`. The plugin
re-validates the hold server-side before the order is created, and the SaaS re-verifies the
signature and the hold's live state at confirm time. A signature that does not match the hold's
own stored snapshot is rejected — the signature is a transport integrity check, not the source
of truth.

### T4 — Replay of server-to-server requests (Spoofing, Tampering)
An attacker who captures a signed confirm request replays it, or replays it against a different
endpoint.

*Mitigations.* Every server-to-server request carries `X-Seatmap-Key`, `X-Seatmap-Timestamp`,
`X-Seatmap-Nonce` and `X-Seatmap-Signature`, where the signature covers method, path, timestamp,
nonce and a SHA-256 hash of the raw body. Timestamps outside a ±300 s window are rejected; nonces
are stored in Redis for the window's duration and a repeat is rejected. Signatures are compared
with `hash_equals`. Because the path is signed, a captured signature cannot be redirected to
another endpoint.

### T5 — Secret compromise (Spoofing)
A leaked plugin secret lets an attacker confirm or cancel sales.

*Mitigations.* Secrets are shown once, at creation, and are looked up by a separate non-secret key
id. They are stored **encrypted with the application key**, not hashed: verifying an HMAC signature
requires the secret itself, so hashing would only mean signing with the hash — and a stolen database
would then be enough to forge requests. Encrypted, a database leak alone is not, because the
application key lives outside it. Rotation issues a second active key with an overlap window so a
site can be updated without downtime; revocation is immediate. Every authenticated call records
`api_key_id` in the audit log, so the blast radius of one key is knowable. Secrets are redacted from
logs and exception reports, and never returned by any read endpoint.

### T6 — Ticket forgery or theft (Spoofing)
An attacker guesses or crafts a QR that scans as valid.

*Mitigations.* Ticket tokens are 32 bytes from a CSPRNG, base32-encoded, stored hashed, and
carry **no personal data and no meaning** — a scan is a database lookup, not a signature check,
so a forged token cannot be constructed offline. Tokens are scoped to one event; scanning at the
wrong event returns `wrong_event`. Voided/refunded tickets return `cancelled`/`refunded`.

### T7 — Double entry on one ticket (Tampering)
Two operators scan the same QR at two doors simultaneously and both are told "valid".

*Mitigations.* The scan performs an atomic conditional claim (`UPDATE … WHERE checked_in_at IS
NULL RETURNING …`) inside a transaction; exactly one row is affected. The loser receives
`already_used` **with the winning scan's timestamp, device and operator**, which is also what the
door staff need to resolve the dispute. Offline batch sync resolves by earliest scan timestamp
and is idempotent per `(ticket, device, client_scan_id)`.

### T8 — Hold exhaustion / denial of inventory (Denial of service)
An attacker holds every seat repeatedly so nobody can buy.

*Mitigations.* Short TTL (default 10 min, tenant-configurable), per-IP and per-session rate
limits on hold creation, a cap on seats per hold and on concurrent holds per session, and a
bounded number of extends per hold. Abnormal hold-to-confirm ratios are surfaced to the tenant.

*The per-IP half of that depends on a deployment decision.* Signing in, signing up, claiming an SSO
account, the per-person seat cap and the picker's bot defence all key on `$request->ip()`, so
`SEATMAP_TRUSTED_PROXIES` decides whether they work at all. Left empty behind a reverse proxy, every
request arrives as the proxy and the limits become one global bucket — which is not only useless
against an attacker but actively harmful, because the first hundred honest buyers at an on-sale
exhaust it for everyone. Set to a catch-all on a directly exposed server, any client can vary
`X-Forwarded-For` per attempt and evade the same limits from one machine. `seatmap:preflight` reports
which of the two the installation is currently in.

`X-Forwarded-Host` is not honoured even from a trusted proxy (`bootstrap/app.php`). This application
resolves a tenant's site from the request's hostname, so a forwarded Host a client could set is a
cross-tenant takeover: one organiser's pages served, and one organiser's checkout answered, on
another's domain. `BehindAProxyTest` pins it.

### T9 — Widget abuse of the public API (Information disclosure, DoS)
The embed endpoints are unauthenticated by necessity.

*Mitigations.* They expose only a public event id and geometry the venue shows publicly; no buyer
identity, no ticket tokens, no tenant internals. Rate limits are per IP and per event. Availability
responses are cacheable and cursor-based to keep polling cheap.

*And the snippet is no longer portable.* The embed carries no key — that is what makes it usable by
somebody with a page and no toolchain, and it is also why anybody who viewed a venue's booking page
could copy the two tags onto their own site and open that venue's hall there, holding seats out of
their real inventory. Each tenant now keeps a list of the websites their halls may be drawn on
(`embed_origins`), and `AllowEmbedOrigin` refuses anything else with `embed_origin_not_allowed`.
The list is seeded from verified hosted-site domains and registered API client origins, so the
default is deny without taking working embeds offline on the morning it ships.

**What that is and is not.** `Origin` is a fact a browser states and will not let a page lie about,
which is exactly what defeats copy-and-paste: a copied snippet runs in a browser, so every instance
of the threat arrives *with* an origin. It is *not* proof about a person: curl, a scraper or a
server-side proxy can send whatever they like, and a determined thief will proxy. For that reason a
request stating **no** origin is let through rather than refused — anything able to omit the header
is equally able to forge a permitted one, so refusing there would stop only a proxy whose author
could not be bothered, while breaking every honest integration that is not a browser. That is acceptable
**here and nowhere else on this platform**, because what is behind these endpoints is a public
programme and a chart the venue already shows the world — so what is being defended is the venue's
brand on somebody else's page and their inventory's rate limits, not a secret. Every endpoint that
guards something secret authenticates properly and does not rely on this.

CORS remains `*` and remains a browser convenience rather than the control: the refusal above is a
403 with a body, and a widget that cannot read that body cannot tell the organiser what to fix.

### T10 — Repudiation of state changes (Repudiation)
A tenant disputes that a seat was blocked or a ticket voided.

*Mitigations.* `audit_logs` records actor (user, api key or system), tenant, action, subject,
request id, IP and a diff, for every mutating action. Webhook deliveries are logged with request,
response, status and attempt count.

### T11 — Malicious or huge seat-map upload (DoS)
A crafted map with a million seats or deep nesting exhausts memory.

*Mitigations.* Hard caps on seats per map, sections, rows, geometry byte size and nesting depth,
enforced in `SeatMapValidator` before persistence; publish runs in a queued job with a memory
ceiling; plan limits cap seats per tenant.

### T12 — Personal data exposure (Information disclosure)
Buyer name/email arrive with orders and appear in tickets and exports.

*Mitigations.* Minimum-necessary collection (the plugin sends only what the ticket needs), no
personal data in QR payloads, per-tenant export and delete endpoints, and a retention policy that
purges buyer contact data on a configurable schedule after the event.

### T13 — Server-side request forgery through an organiser-supplied address (Elevation, Information disclosure)

Two screens take a URL from an organiser and make *this server* open it: a webhook endpoint, and an
identity provider's discovery document. A server that fetches whatever it is told to fetch can be
pointed inwards — at a cloud metadata service on `169.254.169.254`, at a database on the private
network, at something bound only to localhost — and the reply comes back to the person who asked
for it. Nothing in the original code checked this at all; the webhook screen made it obvious, and
the SSO issuer was carrying the same hole.

*Mitigations.* `App\Support\Http\OutboundUrl` is the one judgement: `https` only, a hostname
rather than a bare IP address, and every address that name resolves to has to be on the public
internet — v4 and v6, including an IPv4 loopback wearing an IPv6 mapping, which is the usual way
past such a check. A bare address is refused even when it is public, because no legitimate
integration needs one and it removes a whole class of near-miss.

What this does not close is DNS rebinding: a name that answers publicly here and privately a moment
later when the request is actually made. Closing that means connecting to the address rather than to
the name, which cannot be done without giving up TLS verification of the name. The operator's egress
rules are where that is closed properly, and `SEATMAP_WEBHOOK_VERIFY_DESTINATION` must be on in
production — off, the whole check is relaxed to suit a development machine.

### T14 — A file served from the venue's own domain (Elevation, Information disclosure)

An organiser uploads a poster, and this platform serves it back from the same origin the venue's
staff sign in to and buyers pay on. That makes an upload field the one place somebody outside this
codebase hands the server a file and the server hands it to everybody else — and a file that is
*not* a picture, served there, is script on the venue's own domain.

*Mitigations.* `App\Domain\Media\MediaLibrary` decides the type from the bytes, never from the
request's `Content-Type` or the file's extension, and checks it against a closed list by exact
match — a "starts with `image/`" test is precisely how an SVG gets in. SVG is refused outright: it
is a document that can carry script, and there is no version of allowing it that is worth what it
costs. Pictures that can be re-encoded are, which destroys anything hidden in the container as a
side effect of dropping metadata and capping the size. What is served carries
`X-Content-Type-Options: nosniff` and `Content-Security-Policy: default-src 'none'; sandbox`, so a
mistake in any of the above is a broken image rather than a script.

What this does not pretend to do is keep one account's files secret from another's. An identifier
is not a credential here, and the route serves any file to anybody who has its address: these are
the pictures on a public ticket shop's front page. What keeps a library private is that nothing
lists it — the endpoint that does is scoped to the account and behind a permission.

## Assumptions

- TLS is terminated in front of the API and enforced (HSTS); plaintext HTTP is not supported.
- Redis and Postgres are on a private network and not internet-reachable.
- The tenant keeps their WordPress installation patched; a fully compromised site can transact on
  that tenant's behalf until its key is revoked — which is why keys are per-site and revocable.
