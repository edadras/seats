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

*Mitigations.* Secrets are shown once and stored only as a hash (`hash('sha256')`, looked up by
a separate non-secret key id). Rotation issues a second active key with an overlap window so a
site can be updated without downtime; revocation is immediate. Every authenticated call records
`api_key_id` in the audit log, so the blast radius of one key is knowable. Secrets are redacted
from logs and exception reports.

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

### T9 — Widget abuse of the public API (Information disclosure, DoS)
The embed endpoints are unauthenticated by necessity.

*Mitigations.* They expose only a public event id and geometry the venue shows publicly; no buyer
identity, no ticket tokens, no tenant internals. Registered origins are recorded per API client
and sent as CORS headers, with the explicit understanding that CORS is a browser convenience and
**not** the authorisation control. Rate limits are per IP and per event. Availability responses
are cacheable and cursor-based to keep polling cheap.

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

## Assumptions

- TLS is terminated in front of the API and enforced (HSTS); plaintext HTTP is not supported.
- Redis and Postgres are on a private network and not internet-reachable.
- The tenant keeps their WordPress installation patched; a fully compromised site can transact on
  that tenant's behalf until its key is revoked — which is why keys are per-site and revocable.
