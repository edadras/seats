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
./vendor/bin/phpunit                       # unit + feature
./vendor/bin/phpunit --testsuite Feature   # includes the 100-way race test
```

The suite runs against PostgreSQL by design — see `phpunit.xml`.

## Installing the plugin

Copy `wordpress-plugin/seatmap-connect/` into `wp-content/plugins/`, activate it, then fill in the
API URL, key id and secret under **WooCommerce → Seatmap**. Use **Test connection** to confirm the
signature and clock are right before going near a real sale.
