# Data model and state machines

## ERD

```mermaid
erDiagram
    TENANTS ||--o{ TENANT_USERS : "has members"
    TENANTS ||--o{ SUBSCRIPTIONS : "subscribes"
    PLANS   ||--o{ SUBSCRIPTIONS : "sold as"
    TENANTS ||--o{ API_CLIENTS : "connects sites"
    API_CLIENTS ||--o{ API_KEYS : "rotates"
    API_CLIENTS ||--o{ WEBHOOK_ENDPOINTS : "notifies"

    TENANTS ||--o{ VENUES : owns
    VENUES  ||--o{ SEAT_MAPS : "has"
    SEAT_MAPS ||--o{ SEAT_MAP_VERSIONS : "versioned as"
    SEAT_MAP_VERSIONS ||--o{ SECTIONS : "explodes into"
    SECTIONS ||--o{ ROWS : contains
    ROWS ||--o{ SEATS : contains

    TENANTS ||--o{ EVENTS : schedules
    SEAT_MAP_VERSIONS ||--o{ EVENTS : "published to"
    EVENTS ||--o{ EVENT_PRICE_ZONES : prices
    EVENTS ||--o{ EVENT_SEAT_OVERRIDES : adjusts
    SEATS  ||--o{ EVENT_SEAT_OVERRIDES : "targeted by"

    EVENTS ||--o{ HOLDS : "reserved by"
    HOLDS  ||--o{ HOLD_ITEMS : "covers"
    SEATS  ||--o{ HOLD_ITEMS : "locks"

    EVENTS ||--o{ ALLOCATIONS : "sold as"
    SEATS  ||--o{ ALLOCATIONS : "sold as"
    HOLDS  ||--o{ ALLOCATIONS : "converted from"
    ALLOCATIONS ||--|| TICKETS : "issues"

    EVENTS ||--o{ CHECKIN_DEVICES : "scanned by"
    TICKETS ||--o{ CHECKINS : "scanned as"
    CHECKIN_OPERATORS ||--o{ CHECKINS : performs

    API_CLIENTS ||--o{ IDEMPOTENCY_KEYS : "replays via"
    WEBHOOK_ENDPOINTS ||--o{ WEBHOOK_DELIVERIES : "attempts"
    TENANTS ||--o{ AUDIT_LOGS : records
```

## Key invariants

| Invariant | Enforced by |
| --- | --- |
| Every business row belongs to exactly one tenant | `tenant_id` NOT NULL + global scope + `BelongsToTenant` guard on save |
| At most one **active allocation** per (event, seat) | `UNIQUE (event_id, seat_id) WHERE status='active'` |
| At most one **active hold item** per (event, seat) | `UNIQUE (event_id, seat_id) WHERE released_at IS NULL` |
| One allocation per (api_client, external_order, seat) | `UNIQUE (api_client_id, external_order_id, seat_id)` |
| Seat ids are stable and never reused | UUID primary keys; publish copies ids forward by `(section_key,row_key,seat_key)` |
| A published version is immutable | `published_at` set → writes rejected at the model layer; edits create a new draft version |
| Price/currency/version frozen at hold time | `holds.price_snapshot` JSONB + `seat_map_version_id` on the hold |
| Availability is derived, never stored on geometry | `geometry` JSONB has no status field; `SeatAvailability` computes from overrides+holds+allocations |

## State machines

### Seat (per event — derived, not a column)

```mermaid
stateDiagram-v2
    [*] --> available: map version published
    available --> held: hold created
    held --> available: hold expired / released / cancelled
    held --> allocated: order confirmed
    allocated --> available: refunded (policy=release)
    allocated --> blocked: refunded (policy=hold_back)
    available --> blocked: organiser blocks seat / override
    blocked --> available: organiser unblocks
```

### Hold

```mermaid
stateDiagram-v2
    [*] --> active: POST /holds
    active --> active: PATCH /extend (bounded count)
    active --> expired: expires_at reached (lazy read + sweeper)
    active --> released: DELETE /holds
    active --> converted: order confirmed
    expired --> [*]
    released --> [*]
    converted --> [*]
```

`active` is `released_at IS NULL AND expires_at > now()`. Only `active → converted` produces
allocations; a confirm against an `expired` hold fails with `hold_expired` and the plugin is
expected to surface a re-selection, never to sell anyway.

### External order (SaaS-side mirror of a WooCommerce order)

```mermaid
stateDiagram-v2
    [*] --> pending: POST /orders (order created in Woo)
    pending --> confirmed: /confirm (payment_complete)
    pending --> cancelled: /cancel (failed, cancelled, deleted, or hold expiry)
    confirmed --> refunded: /refund (full)
    confirmed --> partially_refunded: /refund (partial)
    partially_refunded --> refunded: remaining seats refunded
    cancelled --> [*]
    refunded --> [*]
```

Every transition is idempotent on `(api_client_id, external_order_id)`; repeating a transition
that already happened returns the same representation with `200`, not an error. A transition that
contradicts the current state (e.g. confirm after refund) returns `409 invalid_transition`.

### Allocation

```mermaid
stateDiagram-v2
    [*] --> active: confirm
    active --> released: refund with policy release
    active --> void: order deleted / chargeback
    released --> [*]
    void --> [*]
```

Only `active` allocations occupy a seat (partial unique index). Released and void rows are kept
for audit and reconciliation.

### Ticket

```mermaid
stateDiagram-v2
    [*] --> issued: allocation created
    issued --> used: first successful scan
    issued --> void: allocation released/void
    used --> void: refunded after entry (recorded, entry already happened)
    void --> [*]
```

### Check-in scan

```mermaid
stateDiagram-v2
    [*] --> lookup
    lookup --> invalid: token unknown
    lookup --> wrong_event: token belongs to another event
    lookup --> cancelled: ticket void
    lookup --> refunded: ticket refunded
    lookup --> claim: ticket issued
    claim --> valid: atomic UPDATE affected 1 row
    claim --> already_used: 0 rows — returns winning time/device/operator
```
