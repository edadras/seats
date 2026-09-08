# Legacy source audit (`app.zip`)

Audit of the Laravel source supplied as `app.zip` (687 files, 622 PHP), performed before any
code was written. This document records what exists, what is broken or missing, and what is
worth carrying into the new SaaS.

## 1. What the legacy app is

A single-tenant, monolithic Iranian event-ticketing site. It owns the whole funnel itself:
event catalogue, seat map, seat selection, order, payment (manual receipts, PayPal, Revolut,
NOWPayments), ticket PDF/QR, sales representatives with credit accounting, Telegram/WhatsApp
notifications, support tickets, and an extensive admin panel.

It is **not** a SaaS and it is **not** an API product. Ownership of the customer, the cart, the
payment and the ticket all live in the same codebase.

## 2. Defects found

### 2.1 Duplicate and conflicting migrations

`database/migrations/` contains 96 files with repeated `create` migrations for the same tables:

| Table | Duplicate `create` migrations |
| --- | --- |
| `users` | `2014_10_12_000000`, `2024_01_01_000004` |
| `events` | `2024_01_01_000004`, `2024_01_01_000005`, `2024_01_01_000008` |
| `orders` | `2024_01_01_000006`, `2024_01_01_000007`, `2024_01_01_000011` |
| `tickets` | `2024_01_01_000007`, `2024_01_01_000008`, `2024_01_01_000012` |
| `event_sections` | `2024_01_01_000006`, `2024_01_01_000009`, `2024_01_01_000010` |
| `event_seats` | `2024_01_01_000010`, `2024_01_01_000011` |
| `user_favorites` | `2024_01_01_000008`, `2024_01_01_000009`, `2024_01_01_000013` |
| `booking_sessions` | `2024_01_01_000012`, `2024_01_01_000014` |

A clean `php artisan migrate` on an empty database cannot be relied upon. Schema truth has
drifted into the production database rather than the repository.

### 2.2 Missing table definition

`seat_reservations` — the table the whole booking flow depends on — has **no `create`
migration**. Only `2025_11_10_000002_update_order_id_nullable_in_seat_reservations.php`
exists, which alters a table nothing in the repo creates. The same applies to several tables
touched only by `add_missing_*` migrations (`2024_01_22_000001_add_missing_order_fields`,
`2024_01_23_000001_add_missing_common_fields`), which are themselves a symptom of the drift.

### 2.3 Missing front-end source

`asset/` is empty. The seat-map editor scripts referenced by the admin views
(`canvas.js`, `main.js`, `modals.js`) are absent from the archive, so the editor cannot be
built or reviewed. `components/ui/*.tsx` (50 shadcn files) and `lib/` are present but belong to
a different, unrelated front-end and are not wired to the Laravel views.

**Consequence:** the editor is re-implemented from scratch rather than guessed at.

### 2.4 No multi-tenancy

No `tenant_id` anywhere. Isolation is by admin role and per-event access grants
(`AdminEventAccessController`). Two organisers on one install would share `users`, `settings`,
`exchange_rates`, `payment_methods` and all reporting. There is no scope, policy or global
query filter that could be repurposed.

### 2.5 Booking flow is web routes, not API

`routes/web.php` (22 KB) carries the reservation endpoints; `routes/api.php` is 48 lines and
exposes only chat, support tickets and the check-in app. There is no versioning, no
machine-consumable contract, no idempotency and no server-to-server authentication. Nothing
here can be consumed by a WordPress plugin as-is.

### 2.6 Seat status stored in two places

Seat status lives **both** in the seat-map JSON (`status: free|reserved|sold` inside each seat
object, see `SeatMapNormalizer::normalize()`) **and** in `seat_reservations` / `tickets`. Two
writable sources for one fact; they can and do disagree. Editing a map can silently change
sold state.

### 2.7 Concurrency is close but not airtight

`EventController::reserveSeat()` does use `DB::transaction` + `lockForUpdate`, which is the
right instinct. But:

- the lock is taken on the *reservation* rows, so when no row exists yet there is nothing to
  lock — two concurrent first-time reservations for the same seat can both find "no active
  reservation" and both insert;
- there is no unique constraint backing it up at the database level;
- expiry is enforced by comparison in application queries plus ad-hoc cleanup in
  `DashboardController`, not by a reliable sweeper.

The new system keeps the transaction, and adds a **partial unique index** so the database is
the final arbiter (see ADR-0002).

### 2.8 Other observations

- `routes/web2.php` (6 KB) is dead/parallel routing left in the tree.
- `routes/admin.php` is 54 KB — routing and authorisation are entangled.
- `sql/` holds 8 raw `.sql` files, plus a `.gz` and two `.zip` dumps committed into source.
- QR payloads and ticket lookup are keyed on guessable identifiers in places.
- Check-in (`CheckInController::scanTicket`) reads then writes without an atomic claim, so two
  devices scanning the same QR simultaneously can both be told "valid".

## 3. Logic worth keeping (adapted, not copied)

| Legacy source | What is reused |
| --- | --- |
| `SeatMapNormalizer` | Row clustering by Y with RANSAC line fitting, `A, B, C…` row lettering, left-to-right renumbering, unique-ID enforcement. Adapted into the editor's auto-numbering and into `SeatMapValidator`. |
| `SeatMapService` | Canvas JSON shape (seats, sections, shapes, texts, lines, `canvasSize`) — kept as the geometry vocabulary of `seat_map_versions.geometry`, minus any status field. |
| `EventController::reserveSeat` | Transaction + row-lock reservation pattern, TTL and "extend my own reservation" behaviour. Hardened with a DB constraint. |
| `CheckInController` | Scan result vocabulary and per-event operator scoping. Re-implemented atomically. |
| `TicketPdfService`, QR issuance | Ticket token issuance and rendering approach. Token replaced with an opaque random value. |
| Reporting queries | Shape of the availability/sales/check-in aggregates behind `/v1/events/{event}/stats`. |

## 4. What is deliberately dropped from scope

Sales representatives and credit accounting, manual payment receipts and OCR
(`ReceiptVisionAiService`), exchange rates, Telegram/WhatsApp bots, support tickets, ticket
transfers, order splitting/archiving, AI seat-map generation (`EventSeatMapAIController`) and
the site-facing catalogue. These belong to the organiser's own storefront — in the new
architecture that is WordPress/WooCommerce, not the SaaS (ADR-0001).
