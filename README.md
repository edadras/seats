# Seatmap — multi-tenant seating SaaS + WooCommerce plugin

Organisers design a venue map once, connect their WordPress shop, and sell reserved seats from
their own site. The SaaS owns seating inventory; WooCommerce keeps the cart, the payment and the
books.

```
seats/
├── api/                 Laravel service: tenants, maps, events, holds, tickets, check-in
├── wordpress-plugin/    WooCommerce plugin: widget, cart integration, order lifecycle
└── docs/                Audit, ADRs, threat model, data model, OpenAPI contract
```

## Start here

| Document | What it answers |
| --- | --- |
| [`docs/adr/0001-saas-woocommerce-boundary.md`](docs/adr/0001-saas-woocommerce-boundary.md) | Who owns what, and why the SaaS never touches payments |
| [`docs/adr/0002-seat-inventory-integrity.md`](docs/adr/0002-seat-inventory-integrity.md) | How a seat is sold exactly once |
| [`docs/THREAT_MODEL.md`](docs/THREAT_MODEL.md) | Assets, trust boundaries, threats T1–T12 and mitigations |
| [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) | ERD, invariants, state machines |
| [`docs/openapi.yaml`](docs/openapi.yaml) | The full `/v1` contract |
| [`docs/LEGACY_AUDIT.md`](docs/LEGACY_AUDIT.md) | What was found in the original source and what was reused |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Running it: requirements, secrets, monitoring, backups, incidents |

## What the designer can draw

Rows (straight or curved), enterable polygon sections, general admission areas, tables bookable by
the chair or as a whole, booths, shapes, text, images to trace over, and icons — across multiple
floors, on four selection layers, with categories, a focal point and a validation checklist.

## Two rules that explain most of the design

**Seat state is derived, never stored.** `seat_map_versions.geometry` describes where chairs are;
it has no `status` field. Whether a seat is free is computed from overrides, live holds and
allocations. This is why republishing a map cannot disturb existing sales.

**The database is the arbiter of exclusivity.** Application code locks and re-checks, but the
guarantee lives in two partial unique indexes. If they were dropped, `SeatConcurrencyTest` fails
immediately.

## Running the API locally

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan seatmap:generate-signing-key
createdb seatmap && createdb seatmap_test
php artisan migrate --seed
php artisan serve
```

Requires PHP 8.3+, PostgreSQL 14+ and Redis. Postgres is not optional: the correctness guarantees
use partial unique indexes and `SELECT … FOR UPDATE`.

`php artisan migrate --seed` creates a demo tenant, a published 150-seat map, a priced event and a
connected API client, and prints the credentials you need for the plugin.

## Tests

```bash
cd api
./vendor/bin/phpunit                        # 102 unit + feature tests
./vendor/bin/phpunit --group concurrency    # the races, as real parallel processes
node --test tests/js/chart.test.cjs         # 33 chart model tests
```

The PHP suite runs against PostgreSQL by design — see `phpunit.xml`. The concurrency tests spawn
independent OS processes, because sharing a connection would not exercise what the guarantee
actually rests on.

Three further checks are run by hand against a live instance rather than in CI, since they need a
server and a browser:

```bash
php artisan migrate:fresh --seed --force
php artisan serve --port=8123 &
(cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)

node api/editor_smoke.mjs                                        # drives the designer in Chromium
node api/a11y_check.mjs                                          # contrast and keyboard paths
php wordpress-plugin/tools/roundtrip-check.php KEY SECRET EVENT  # the plugin's exact signing code
```

`a11y_check.mjs` measures the design tokens rather than any one screen: contrast is a property of
the palette, so checking the pairs once in each theme covers every screen built from them. It also
walks the panel by keyboard and chooses a seat in the picker without a mouse.

## What is covered

Every acceptance criterion has a test that would fail if the behaviour regressed:

| Criterion | Where |
| --- | --- |
| 100 concurrent requests, exactly one winner | `SeatConcurrencyTest` |
| A seat returns to sale the moment its TTL passes | `PurchaseFlowTest` |
| Retried confirm makes one allocation and one ticket | `OrderLifecycleTest` |
| A refund releases seats and voids tickets | `OrderLifecycleTest` |
| Tenant A cannot reach tenant B's anything | `TenantIsolationTest` |
| Browser-set prices are ignored | `ApiSecurityTest` |
| Replay, tampering and key rotation | `ApiSecurityTest` |
| A second scan reports who got in, and when | `CheckinTest` |
| Republishing a map cannot break old orders | `SeatMapVersioningTest` |
| Standing room never oversells, even under contention | `GeneralAdmissionTest`, `SeatConcurrencyTest` |
| The PHP and JavaScript seat maths agree exactly | `RowGeometryTest` |
| Webhooks retry, die honestly, and stay tenant-scoped | `WebhookDeliveryTest` |

## Installing the plugin

Copy `wordpress-plugin/seatmap-connect/` into `wp-content/plugins/`, activate it, then fill in the
API URL, key id and secret under **WooCommerce → Seatmap**. Use **Test connection** to confirm the
signature and clock are right before going near a real sale.
