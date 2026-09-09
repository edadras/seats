# Seatmap — multi-tenant seating SaaS, event sites, WooCommerce plugin, door scanner

Organisers design a venue map once and sell reserved seats from it. How they sell is their choice:

- **Their existing WordPress shop**, through the plugin. The SaaS owns seating inventory;
  WooCommerce keeps the cart, the payment and the books.
- **A site we host for them**, on their own domain, with their own theme, pages and menus, and the
  same seat picker. Here the shop is ours — the trade that buys is written down in
  [ADR-0003](docs/adr/0003-hosted-event-sites.md).

Either way the tickets are the same tickets, and the same scanner reads them at the door.

```
seats/
├── api/                 Laravel service: tenants, maps, events, holds, tickets, sites, check-in
├── wordpress-plugin/    WooCommerce plugin: widget, cart integration, order lifecycle
├── checkin-app/         Flutter web app: the scanner staff use at the door
├── modules/             What the platform can be extended with, one directory each
├── shared/              The seat picker, shared verbatim by the plugin and the hosted sites
└── docs/                Audit, ADRs, threat model, data model, OpenAPI contract
```

## Start here

| Document | What it answers |
| --- | --- |
| [`docs/adr/0001-saas-woocommerce-boundary.md`](docs/adr/0001-saas-woocommerce-boundary.md) | Who owns what, and why the SaaS never touches payments |
| [`docs/adr/0002-seat-inventory-integrity.md`](docs/adr/0002-seat-inventory-integrity.md) | How a seat is sold exactly once |
| [`docs/adr/0003-hosted-event-sites.md`](docs/adr/0003-hosted-event-sites.md) | Why we build sites ourselves, and what that costs |
| [`docs/adr/0004-module-architecture.md`](docs/adr/0004-module-architecture.md) | What a module may extend, and what it may never touch |
| [`docs/adr/0005-internationalisation.md`](docs/adr/0005-internationalisation.md) | Six languages, right-to-left, and how nothing is left untranslated |
| [`docs/adr/0006-report-engine.md`](docs/adr/0006-report-engine.md) | Why the report builder has no SQL box |
| [`docs/THREAT_MODEL.md`](docs/THREAT_MODEL.md) | Assets, trust boundaries, threats T1–T12 and mitigations |
| [`docs/DATA_MODEL.md`](docs/DATA_MODEL.md) | ERD, invariants, state machines |
| [`docs/openapi.yaml`](docs/openapi.yaml) | The full `/v1` contract |
| [`docs/LEGACY_AUDIT.md`](docs/LEGACY_AUDIT.md) | What was found in the original source and what was reused |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Running it: requirements, secrets, monitoring, backups, incidents |
| [`docs/MODULES.md`](docs/MODULES.md) | Writing a module: the manifest, the extension points, and what a module may never touch |
| [`checkin-app/README.md`](checkin-app/README.md) | The door scanner: what it does, and the four CDNs it refuses to need |

## What the designer can draw

Rows (straight or curved), enterable polygon sections, general admission areas, tables bookable by
the chair or as a whole, booths, shapes, text, images to trace over, and icons — across multiple
floors, on four selection layers, with categories, a focal point and a validation checklist.

## Who may do what

Six built-in roles — owner, administrator, manager, box office, door staff, viewer — over a closed
catalogue of named permissions, and an organiser can invent their own for a job their venue
actually has.

The separation that matters most is money from operations. A door volunteer sees the head count and
not the takings; the same `/events/{id}/stats` endpoint answers both questions and only answers the
second to somebody who may hear it. A box office finds a booking, refunds it and puts a seat back on
sale, and cannot republish the map that seat is on.

Two rules stop an account destroying itself: nobody changes their own membership — without that,
every permission check is advice — and an account always keeps at least one owner who is not
suspended, or nobody can grant anything ever again. Removing someone suspends them rather than
deleting them, so the audit log keeps its names.

The **audit log** now records what changed, not only that something did, taken from what the
database was actually told rather than what the caller believed they asked for. It is read-only by
construction: there is no endpoint that edits or deletes a row, and there will not be one.

## Six languages

Persian, English, Arabic, German, French and Italian, with Persian and Arabic right-to-left.

The language is chosen from the nearest person outwards: an explicit `?lang=`, then a choice
already made this session, then the signed-in user's preference, then the site's own language, then
the organiser's, then `Accept-Language`. A buyer on a Persian site gets Persian without asking, and
a German-speaking member of that organiser's staff gets German in the panel at the same moment.

Money and dates follow the **reader**; the currency and the event follow the **event**. A Persian
reader looking at a Berlin show sees euros, in Persian digits, on the Persian calendar — not
tomans, and not the German way of writing a euro. That is `Money::format()` and `Dates::longWhen()`
and never string concatenation, because the alternative misstates a price.

Nothing may be left behind, and that is enforced rather than asked for:

```bash
node tools/i18n-check.mjs     # runs in CI on every push
```

It fails the build on a missing key, on a stale key left behind by a rename, and on a placeholder
that appears in one translation of a string and not another — a translation that quietly drops
`:max` tells a buyer they may select *up to seats*.

## Modules

Payment gateways, messaging channels, report sources, page blocks, themes and panel screens all
arrive as modules — the offline gateway the platform ships with included. It is not privileged: if
the module system could not express the gateway we wrote ourselves, it would not be good enough to
offer to anybody else.

Two questions that look like one and are not:

- **installed** is a property of the deployment, discovered from `modules/`. An operator decides it
  by deploying. There is no upload-and-run path, and there will not be one: a multi-tenant platform
  that executes customer-supplied PHP has no tenant boundary left worth the name.
- **enabled** is a property of an organiser, decided in the panel, with settings of their own.

A module contributes through typed extension points and nothing else. It never touches `seats`,
`holds`, `allocations` or `tickets` — `ModuleBoundaryTest` fails the build if a shipped module so
much as names one. Secrets go in encrypted and never come back out; the panel is told "set" or "not
set". A module that throws is caught, recorded, and shown as its own health, and one that keeps
failing is switched off with a reason an organiser can read — because a messaging module that has
quietly stopped sending tickets looks exactly like one that is working.

## Hosted event sites

An organiser who has no WordPress can have a site instead: pages built from typed blocks, a theme,
menus, and a domain of their own.

Which site a request gets is decided by its `Host` header and nothing else — never a header, query
parameter or path prefix, because any of those would let one visitor ask for another organiser's
site. A hostname is claimed but not served until a TXT record proves the organiser owns it.

Each site serves its own `sitemap.xml` and `robots.txt`, puts schema.org `Event` data and a share
image on every event page, offers an `.ics` file for the buyer's calendar, and lets a visitor
search the programme by name, venue or category — all server-rendered, so the results are an
address somebody can send to a friend.

Pages have drafts and a published copy, the same discipline seat maps have. Blocks are normalised
once, on the way in; nothing downstream re-validates, and raw HTML is off unless an organiser has
deliberately turned it on for their account.

Configure `SEATMAP_PANEL_HOSTS` in production. It is the allow-list for the control panel; every
other `Host` is looked up as a site. With it unset, one host serves both — which is what you want
in development and never in production.

## Selling from somebody else's website

Three ways to sell, and the same seat picker in all of them:

1. **A hosted site** on the organiser's own domain, above.
2. **WordPress and WooCommerce**, through the plugin — the shop keeps its own cart, tax and
   coupons, and the platform keeps the seats.
3. **Any other website at all**, with two lines pasted into a page:

```html
<div data-seatmap-event="evt_xxxxxxxx"></div>
<script src="https://api.example.com/embed/v1/seatmap.js" async></script>
```

That third path is for somebody with a page and no toolchain. There is no key in it, because there
is nothing in the public embed API worth protecting: it reads what a venue already shows publicly
and holds seats, rate-limited per address. There is no payment on that page either — the widget
holds the seats against the API and hands the buyer to the organiser's own hosted checkout, so a
site that pastes this in never sees a card or a price it could argue with.

`docs/embed-example.html` is a complete working page; `api/embed_smoke.mjs` drives it from a
separate origin, all the way to a priced checkout. The panel's Connections screen shows the exact
snippet with the event already filled in.

## The door

`checkin-app/` is a Flutter web app. Staff open a URL, type a single-use pairing code once, and
scan. It admits or refuses in one glance, tells you who got in first when a ticket is scanned
twice, keeps working with no signal by queueing scans and sending them in one deduplicated batch,
and needs nothing from any CDN — see its README for why that last one is not a detail.

```bash
checkin-app/build.sh     # analyse, test, build, install into api/public/checkin
```

It is then served at `/checkin` on every host the platform answers to. The build is an artefact
and is not committed.

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
./vendor/bin/phpunit                        # 163 unit, feature and module tests
./vendor/bin/phpunit --group concurrency    # the races, as real parallel processes
node --test tests/js/chart.test.cjs         # 33 chart model tests

cd ../checkin-app && flutter test           # 13 scanner tests

cd .. && node tools/i18n-check.mjs          # every locale complete
```

The PHP suite runs against PostgreSQL by design — see `phpunit.xml`. The concurrency tests spawn
independent OS processes, because sharing a connection would not exercise what the guarantee
actually rests on.

A few further checks are run by hand against a live instance rather than in CI, since they need a
server and a browser:

```bash
php artisan migrate:fresh --seed --force
php artisan serve --port=8123 &
(cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)

node api/editor_smoke.mjs                                        # drives the designer in Chromium
node api/a11y_check.mjs                                          # contrast and keyboard paths
node api/reports_smoke.mjs                                       # builds a report by dragging, then a page
node api/customers_smoke.mjs                                     # the customer directory and its CSV
node api/messaging_smoke.mjs                                     # an announcement, its deliveries, the notice bell
node api/embed_smoke.mjs                                         # the picker on a third-party page, through to checkout
node api/site_smoke.mjs                                          # searching the programme, the sitemap, the .ics
node api/discount_smoke.mjs                                      # a code made in the panel, then spent at a checkout
node api/ticket_types_smoke.mjs                                  # a concession priced in the panel, chosen by a buyer
node api/counter_smoke.mjs                                       # a window sale, seats to comp, from the panel
php wordpress-plugin/tools/roundtrip-check.php KEY SECRET EVENT  # the plugin's exact signing code
```

And one against a real WordPress, which stands itself up on SQLite and needs no database server:

```bash
wordpress-plugin/tools/wordpress-setup.sh --api http://127.0.0.1:8123 --key KEY --secret SECRET
(cd /tmp/seatmap-wordpress/wordpress && php -S 127.0.0.1:8300 &)
node wordpress-plugin/tools/wordpress-check.mjs --event evt_…
```

It buys a seat: picks two, reserves them, checks that they reach the cart with their labels, completes
checkout, and confirms the order came back registered and confirmed with a ticket per seat. Nothing
else covers the seam between the plugin and WordPress itself, and that is where its bugs have been.

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
| A second scan reports who got in, and when | `CheckinTest`, `checkin-app/test` |
| A hosted site serves only its own tenant's events | `HostedSiteTest` |
| A refusal is written in the language of whoever was refused | `LocalisationTest` |
| A module cannot reach the seating inventory | `ModuleBoundaryTest` |
| A module secret is never readable from the panel | `ModuleSystemTest` |
| A broken module does not take a request with it, and does not fail silently | `ModuleSystemTest` |
| A door volunteer sees the head count and not the takings | `AccessControlTest` |
| An account cannot lose its last owner, and nobody edits their own role | `AccessControlTest` |
| One organiser never sees another's staff or audit log | `AccessControlTest` |
| A rial price is not divided by a hundred | `LocalisationTest` |
| An Iranian reader gets the Persian calendar, an Arabic one does not | `LocalisationTest` |
| A hosted purchase makes allocations, tickets and a server-priced order | `HostedSiteTest` |
| An unverified or unknown hostname is a 404, not somebody's site | `HostedSiteTest` |
| Republishing a map cannot break old orders | `SeatMapVersioningTest` |
| Standing room never oversells, even under contention | `GeneralAdmissionTest`, `SeatConcurrencyTest` |
| The PHP and JavaScript seat maths agree exactly | `RowGeometryTest` |
| Webhooks retry, die honestly, and stay tenant-scoped | `WebhookDeliveryTest` |

## Installing the plugin

Copy `wordpress-plugin/seatmap-connect/` into `wp-content/plugins/`, activate it, then fill in the
API URL, key id and secret under **WooCommerce → Seatmap**. Use **Test connection** to confirm the
signature and clock are right before going near a real sale.
