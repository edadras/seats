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

## What it sells

A seat is the start of it, not the end. Around the map:

- **Ticket types and concessions** — adult, child, member, priced per type per event, with minimum
  and maximum quantities the picker enforces before the server has to.
- **Booking fees and VAT**, as their own lines rather than folded into a price, so a buyer can see
  what they are paying and an organiser can reconcile it. **Invoices** carry a VAT number.
- **Discount codes**, percentage or fixed, per event or account-wide, with a cap on uses and a
  record of who spent them.
- **Best available** — "four together" without a buyer hunting for them, scored by price, by how
  central the run is, and by how many orphan seats it would leave behind.
- **Timed entry** — arrival windows with their own capacity, held under the same lock as the seats
  so the window cannot oversell while a basket is open.
- **Multi-date events and seasons**, so a run of nights is one thing to manage and one thing to buy
  from.
- **A waiting list** for a sold-out night, told automatically when seats come back.
- **The counter** — a box office selling at the window, taking cash, and giving seats away as
  comps, with a reason recorded against each.
- **The door list** and its export, for the venue that would rather hold paper than a phone.
- **Attendee questions** at checkout, answered per ticket rather than per booking.
- **Ticket transfer**, so the friend who is actually coming holds a ticket in their own name.
- **Refunds** the buyer can ask for, granted at once inside the terms the organiser wrote and
  queued for a person when they are outside them.
- **Calling a night off, or moving it** — releasing every live basket, settling every booking, and
  telling every buyer the date has changed, with the arrival windows shifted to match.
- **Wallet passes** in Apple Wallet and Google Wallet, signed with the organiser's own credentials,
  because a pass this platform signed would say this platform sold the ticket.
- **Presale codes**, which are not discount codes: one changes what somebody pays, the other
  whether they may buy at all, and a sale that has not opened yet opens for whoever holds one.
- **Add-ons and donations** at the checkout — a programme, a glass of wine, a parking space, and a
  box to give something. Add-ons are a sale and sit inside the fee and the VAT; a donation is a
  gift and sits outside both, because a booking fee on somebody's charity is a complaint.
- **A waiting room** for a sale where thousands arrive at once. Everybody waiting when the doors
  open is given a place *by a draw*, so arriving early buys nothing and refreshing is not a skill;
  people who arrive afterwards join the back in order. An admission is a lease, not a right, and
  the queue moves because people are looking at it — every visitor who checks their place also
  sweeps the leases that lapsed and lets the next people in.
- **Unfinished baskets** — a buyer whose payment never came back is written to once, about their
  own booking, with a link that tries to take the same seats again and a way to say no thank you.
  Never for an address somebody merely typed into a box, and off until the organiser turns it on.
- **Season tickets** — the same seats, every night of a run, bought once. A subscriber picks their
  seats in the ordinary picker on the first night and the rest of the run is held for them, all or
  nothing; what comes out is one ordinary order per night, so the door, the door list and per-event
  revenue never learn that a season exists. The saving is split across the nights so each one still
  adds up on its own, and a flexible pass lets the buyer take any *n* of them.
- **House seats and channel quotas** — the two ways of keeping part of a house back, and neither of
  them is "blocked". A house seat is a blocked seat *with a label saying who it is for*: off public
  sale everywhere, on sale at the window, and named on the clerk's screen so the chair kept for the
  director's mother is not handed to whoever asks. A quota is the other shape of the same wish —
  not *these* seats but *this many*: an agent gets four hundred, the website gets the rest, and
  what each has taken is counted rather than stored, under the same lock the seats are sold under.
  A cancelled booking gives its places back, because the channel did not sell them in the end.
- **Sales pace and conversion** — a rate and a funnel, because "two hundred sold" is not an answer.
  Two hundred out of a thousand people who looked is a pricing problem; two hundred out of two
  hundred and twelve is a marketing one, and the remedies are opposite. A day-by-day chart with the
  quiet days still in it, what the last week's rate says about the doors — hedged, because it is
  arithmetic and a straight line is wrong about a run that fills up in its final three days — and
  looked → basket → checkout → bought. Everything but the looking is derived from rows that already
  exist; the looking is counted per event, per day, per source, and never per visitor, so there is
  no cookie, no identifier and nothing to erase under a subject access request.
- **A site written in more than one language** — the events on a hosted site have been translatable
  for a while and the platform's own chrome speaks six languages; what stayed in one language was
  everything the organiser wrote themselves, which on a Persian venue's site is the half a visitor
  actually reads. A translation is an *overlay* on the page rather than a second copy of it — a
  title, the SEO lines, and the prose of individual blocks keyed by block id — because a copied
  block tree drifts the moment somebody adds a section to one language and not the other. A field
  nobody translated falls back to the original, field by field, so a half-translated page has some
  of the original language on it rather than holes. And the switcher offers the languages the site
  is actually published in: it used to list all six whatever had been written, so a visitor could
  choose Italian and be handed a Persian page with English furniture.
- **Purchase limits, and a bot defence that costs a buyer nothing** — a cap per basket stops
  nothing, because four at a time six times over is twenty-four. The limit that means anything is
  counted across everything one address already holds for that night, refunded tickets excluded, and
  it is checked before the money and never after it: a booking refused at confirmation is money
  taken for tickets nobody has. The counter is exempt, because the person is standing in front of
  the clerk. Against scripts there are two cheap questions — a field a person cannot see and a form
  sent faster than a person can fill one in — and deliberately no CAPTCHA, no third-party scoring and
  no cookie, because those cost a blind buyer their evening and send somebody's behaviour elsewhere
  to be judged.
- **The till** — a shift is a person and a drawer between two times, and at eleven o'clock it
  answers the question every venue asks: is the money in the drawer the money that should be in the
  drawer? Only cash counts, because a card is money that never touched it; a taxi paid for out of
  the till is written down with its reason, because nothing else knows about it; and the difference
  at the close is the finding rather than a mistake to be corrected — a till four over is as
  interesting as one four short, and neither can be edited afterwards. What the drawer should hold
  is counted on every read while the shift is open, and photographed once at the close, so a refund
  granted the next morning cannot rewrite last night's discrepancy into agreement.
- **Saved audiences** — "everybody who came last season and has not booked this one", which is the
  audience an organiser actually wants and is two clauses rather than one. Named and saved, so it
  can be asked again next season: bought any of these nights and none of those, in these categories,
  between these dates, at least this many times, at least this much spent in one named currency, or
  actually turned up and was scanned in. The vocabulary is closed on purpose — a saved query
  language over buyer data is a way to write, by accident, both the query that takes an hour and the
  one that reaches somewhere nobody meant to expose. A segment holds rules and never people: it is
  resolved every time it is used, and no endpoint anywhere returns the addresses it describes.
- **Gift vouchers and account credit**, which are not discount codes either: a discount changes
  what a booking cost and so changes the tax on it, while a voucher changes how an unchanged cost
  was settled. Applied last, to the amount payable, and a booking a voucher covers outright
  finishes with no gateway involved at all. The balance is the sum of the movements, never a
  column — and a refund can be taken as credit rather than back to a card.
- **Settlement** — what was taken, what was handed back, what the platform's commission was, per
  period or per event, as a statement somebody can send to an accountant.
- **Reports** built by dragging fields, with no SQL box — [ADR-0006](docs/adr/0006-report-engine.md)
  says why.
- **Two-step sign-in** for staff, and **GDPR export and erasure** for buyers.

Every one of these is behind a named permission, translated into all six languages, and driven by a
browser check in `api/smoke.sh`.

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

Nothing may be left behind, and that is enforced rather than asked for. Four checks run in CI on
every push, and each one exists because the thing it catches is invisible to the others:

- `tools/i18n-check.mjs` — a missing key, a stale key left behind by a rename, and a placeholder
  that appears in one translation of a string and not another. A translation that quietly drops
  `:max` tells a buyer they may select *up to seats*.
- `tools/panel-strings-check.mjs` — the panel and its catalogue, in both directions. `t()` never
  throws: a mistyped key renders its own last segment on the screen, and a catalogue entry nobody
  looks up is six translations of a string that is never shown. Both are invisible above, because
  the locales agree with each other either way.
- `tools/picker-strings-check.mjs` — every host provides every string the shared seat picker asks
  for, so a WordPress shop cannot render a blank where the hosted site renders a sentence.
- `tools/error-strings-check.php` — every refusal the API can give has a sentence in the catalogue,
  and every sentence belongs to a refusal. `ApiException` falls back to the English literal the
  call site wrote, which is a safety net for the minute between writing a refusal and translating
  it; eighty-four codes once lived in that net, because a fallback and a translation look identical
  to anybody reading English.

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

`php artisan migrate --seed` creates two demo organisers with a hosted site each, four priced
events across two published maps — a theatre with named chairs and a warehouse sold by the head,
because half the events on this platform are the second kind — and a connected API client. It
prints the credentials you need for the plugin, and a platform-console operator.

## Tests

```bash
cd api
./vendor/bin/phpunit                        # 685 unit, feature and module tests
./vendor/bin/phpunit --group concurrency    # the races, as real parallel processes
node --test tests/js/chart.test.cjs         # 33 chart model tests

cd ../checkin-app && flutter test           # the scanner, including its offline queue

cd ..
node tools/i18n-check.mjs                   # every locale complete
node tools/panel-strings-check.mjs          # the panel and its catalogue agree, both ways
node tools/picker-strings-check.mjs         # every host provides what the picker asks for
php tools/error-strings-check.php           # every refusal has a sentence, and vice versa
node tools/route-contract-check.mjs         # every /v1 route is in the contract, and vice versa
```

The PHP suite runs against PostgreSQL by design — see `phpunit.xml`. The concurrency tests spawn
independent OS processes, because sharing a connection would not exercise what the guarantee
actually rests on.

A few further checks are run by hand against a live instance rather than in CI, since they need a
server and a browser:

```bash
cd api
php artisan serve --port=8123 &
(cd ../wordpress-plugin && python3 -m http.server 8200 --bind 127.0.0.1 &)

./smoke.sh                    # all thirty-six, in order
./smoke.sh editor_smoke       # or just the one you are working on

php ../wordpress-plugin/tools/roundtrip-check.php KEY SECRET EVENT   # the plugin's signing code
```

`smoke.sh` re-seeds and empties the rate limiter between checks, which is not decoration: a dozen
of them signing in as the same owner trips `throttle:20,1,login` on `/v1/auth/login`, and everything
after that fails with a timeout that says nothing about why.

That third argument on every `throttle:` in `routes/` is load-bearing. Laravel keys an unnamed
throttle on the signed-in user, or for a guest on the route's domain and IP — never on the route —
so without it every rationed route shares one counter and the smallest limit anywhere becomes the
limit everywhere. The prefix gives each rationed thing the counter its limit was written believing
it had. Each check names what it drives at
the top of its own file — the designer, the picker, a discount code spent at a checkout, tonight's
door list, a wallet pass signed with a real certificate — and prints a line per assertion.

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
| A concession's minimum and maximum are enforced by the server, not the picker | `TicketTypeTest` |
| A booking fee and its VAT are their own lines, and the total is the sum | `FeesAndTaxTest`, `InvoiceTest` |
| A discount cannot be spent past its cap, and expires without anybody touching it | `DiscountCodeTest` |
| "Four together" is really together, and prefers not to orphan a seat | `BestAvailableTest` |
| An arrival window cannot oversell while a basket is open | `TimedEntryTest` |
| Calling a night off releases every live basket and tells every buyer | `EventCancellationTest` |
| A refund inside the organiser's terms is granted at once; outside them a person answers | `RefundRequestTest` |
| A wallet pass is a signed zip whose manifest matches its files | `WalletPassTest` |
| No wallet button is offered where nothing can be signed | `WalletPassTest` |
| A transferred ticket leaves the old holder and reaches the new one | `TicketTransferTest` |
| Settlement adds up: taken, returned, commission, per currency | `SettlementTest` |
| A buyer's data can be handed over and erased without breaking the books | `PersonalDataTest` |
| Two-step sign-in cannot be turned off from a borrowed tab | `TwoFactorTest` |
| A presale code opens a sale that has not opened, and gives its use back when a basket dies | `AccessCodeTest` |
| A programme is taxed and a donation is not | `AddonTest` |
| The last programme cannot be sold twice | `AddonTest` |
| A voucher moves what is payable and leaves the VAT exactly where it was | `VoucherTest` |
| A voucher that covers a booking finishes it with no gateway, and gives the change back | `VoucherTest` |
| A settlement says which part of its takings never reached a bank | `VoucherTest`, `SettlementTest` |
| A season saving splits across the nights and sums to exactly itself | `SeasonTest` |
| A night that cannot be matched takes the whole subscription with it, and frees the rest | `SeasonTest` |
| A subscription is an ordinary order, allocation and ticket per night | `SeasonTest` |
| Only a submitted checkout becomes an abandoned basket, and only once | `BasketRecoveryTest` |
| A recovery link takes the same seats again, or says plainly that it cannot | `BasketRecoveryTest` |
| Nothing is written to a buyer until the organiser switches the message on | `BasketRecoveryTest` |
| Arriving early at a queue buys nothing: the waiting are drawn, not sorted | `WaitingRoomTest` |
| The door is on the hold, not only on the page | `WaitingRoomTest` |
| A lapsed admission gives its place away | `WaitingRoomTest` |
| A house seat is off public sale and the counter can still sell it | `ChannelQuotaTest` |
| A seat blocked without a label is sellable by nobody, at any window | `ChannelQuotaTest` |
| A channel's allowance counts live baskets, not only completed sales | `ChannelQuotaTest` |
| One channel running out does not touch another channel's allocation | `ChannelQuotaTest` |
| Two people looking at once are both counted, and the page outlives the counter | `SalesPaceTest` |
| A curve keeps its quiet days, and its running total predates the window | `SalesPaceTest` |
| A night selling nothing sells out on no date, and a rate past the doors is not one | `SalesPaceTest` |
| A funnel step with nothing above it has no rate at all | `SalesPaceTest` |
| "Came last season and has not booked this one" is two clauses and one answer | `SegmentTest` |
| A saved audience says how many and never who | `SegmentTest` |
| A clause this version cannot read is dropped rather than obeyed | `SegmentTest` |
| An unknown audience is refused rather than widened to everybody | `SegmentTest` |
| Deleting a list does not delete what was already said to it | `SegmentTest` |
| Only cash reaches the drawer; a card never touched it | `TillTest` |
| One open till per person, in the database as well as the application | `TillTest` |
| A count is a photograph: tomorrow's refund cannot rewrite tonight's discrepancy | `TillTest` |
| A closed till cannot be reopened, recounted or reached into by a manager | `TillTest` |
| A per-person limit counts across baskets, and forgets refunded tickets | `PurchaseLimitTest` |
| The limit is applied before the money and never after it | `PurchaseLimitTest` |
| A field only a script fills, and a form sent faster than a person fills one | `PurchaseLimitTest` |
| A page is read in the visitor's language, field by field | `SitePageTranslationTest` |
| A translation cannot invent a block, a field, or a second page shape | `SitePageTranslationTest` |
| The switcher offers what the site is written in, and the site's own language always | `SitePageTranslationTest` |

## Installing the plugin

Copy `wordpress-plugin/seatmap-connect/` into `wp-content/plugins/`, activate it, then fill in the
API URL, key id and secret under **WooCommerce → Seatmap**. Use **Test connection** to confirm the
signature and clock are right before going near a real sale.
