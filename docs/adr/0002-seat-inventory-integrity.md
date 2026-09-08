# ADR-0002: Where seat state lives and how double-selling is prevented

- Status: Accepted
- Date: 2026-09-08

## Context

The legacy app stored seat status inside the seat-map JSON *and* in reservation/ticket tables
(see `docs/LEGACY_AUDIT.md` §2.6), and guarded reservation with a row lock that has nothing to
lock when the row does not yet exist (§2.7). Both must be fixed: the acceptance criteria require
that 100 concurrent requests for one seat produce exactly one winner.

## Decision

### 1. Geometry and state are separate

`seat_map_versions.geometry` is an immutable JSONB snapshot describing **where things are**:
coordinates, shapes, labels, stage, aisles. It contains no availability field. Publishing also
explodes the geometry into normalised `sections` / `rows` / `seats` rows, each seat carrying a
UUID that is stable for the life of the map and never reused.

Availability is **derived**, never stored:

```
state(seat) = allocated      if an active allocation exists for (event, seat)
            = held           if an active, unexpired hold exists for (event, seat)
            = blocked        if an event_seat_override blocks it
            = available      otherwise
```

Editing or republishing a map therefore cannot change what has been sold, and an old order keeps
pointing at the exact version it was sold against.

### 2. The database, not the application, is the arbiter

Two partial unique indexes carry the invariant:

```sql
CREATE UNIQUE INDEX allocations_one_active_per_seat
  ON allocations (event_id, seat_id) WHERE status = 'active';

CREATE UNIQUE INDEX hold_items_one_active_per_seat
  ON hold_items (event_id, seat_id) WHERE released_at IS NULL;
```

A hold is created inside a transaction that:

1. takes an advisory/row lock over the event's seat rows being requested, in sorted seat-id order
   (deterministic ordering avoids deadlock between overlapping multi-seat requests);
2. re-checks allocations, overrides and live holds;
3. inserts `hold_items`.

If two transactions still race past step 2, the unique index rejects the loser at insert time.
The service maps that violation to a clean `409 seat_unavailable` naming the seat. **We never
rely on a cache, an application-level mutex, or a `SELECT` that returns no rows to establish
exclusivity.**

Expiry is enforced two ways: every read filters on `expires_at > now()`, and a queued sweeper
marks expired holds released so the index frees the seat. Correctness does not depend on the
sweeper running on time; only the speed of seat return does.

### 3. Confirmation is exactly-once

`POST /v1/integrations/woocommerce/orders/{external_order_id}/confirm` is idempotent on
`(api_client_id, external_order_id)` *and* on the `Idempotency-Key` header. The first call
converts hold items into allocations and issues tickets inside one transaction; every retry
replays the stored response. A duplicate confirm can never mint a second allocation or a second
ticket — enforced by the unique index above plus a unique index on
`allocations (api_client_id, external_order_id, seat_id)`.

## Consequences

- Postgres is required (partial indexes, `FOR UPDATE`, JSONB). SQLite is used only for fast unit
  tests that do not exercise concurrency; the race tests run against Postgres in CI.
- Availability is a computed read. It is served from a materialised per-event summary with a
  monotonic `version` cursor so the widget can poll incrementally rather than refetching the map.
- Seat ids are UUIDs and are never recycled, so a deleted seat in a new map version does not
  resurrect an old sale.
