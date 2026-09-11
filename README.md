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

A buyer sees the same room as one continuous map. Far out it is blocks, each drawn in the shape its
own seats make; going into one draws its chairs *and* its neighbours', so the seat at the end of the
next block along can be compared with this one and bought without leaving the block you are in.
Dragging moves across the room, the wheel zooms towards whatever is under the pointer, and one
button gives the whole picker — plan, basket and all — the whole screen.

A plan that already exists somewhere else does not have to be drawn again:

```bash
php artisan chart:import plan.json --tenant=northgate --publish
```

Most export formats — seats.io's among them — write one coordinate pair per seat, so a plan arrives
as thousands of loose dots. Our rows are an anchor, a rotation, a curve and a seat pitch, because
that is what lets an organiser type "17" into a row rather than redraw it. So the import *fits*: for
every row of dots it recovers the line they were sitting on, leaves empty places where the aisles
are, turns each colour into a price category and each traced wall into scenery, and then re-runs our
own geometry over the result and tells you the furthest any chair moved. On the Palais des Congrès
de Paris Grand Amphithéâtre — 3,723 seats in twelve fan-shaped blocks, with 944 traced wall
segments — the answer is 0.05 units, on a chair 18 units across.

Sales are not imported. Which seats were sold, and which the house was holding back, belong to a
performance rather than to a plan of the room; the same room is sold many times.

## What it sells

A seat is the start of it, not the end. Around the map:

- **Ticket types and concessions** — adult, child, member, priced per type per event, with minimum
  and maximum quantities the picker enforces before the server has to.
- **Booking fees and VAT**, as their own lines rather than folded into a price, so a buyer can see
  what they are paying and an organiser can reconcile it. **Invoices** carry a VAT number.
- **Discount codes**, percentage or fixed, per event or account-wide, with a cap on uses and a
  record of who spent them.
- **Chargebacks and blocking** — a chargeback is not a refund and is not recorded as one: a refund is
  the organiser deciding, a chargeback is the bank deciding, usually against them and with a fee.
  Recording one releases the seats and voids the tickets in the same transaction whatever the
  refund policy says, because a booking nobody paid for is not a booking and the code at the door
  has to go red. Barring the buyer is offered and never automatic. A block is about a person, may
  carry a date that lifts it without anybody acting, and is checked where a booking is registered so
  the refusal arrives before the money — with a sentence, not a silent failure.
- **Programme managers** — an administrator for one concert rather than for the account. Somebody
  putting on a run in your venue is given those nights and nothing else: they sell at the window,
  open and close chairs on the plan, void a ticket, read the door and read what their own night
  took — and every other night in the programme answers "cannot be found", including the fact that
  it exists. **The scope is a list of nights, not a level**, checked as middleware on any route that
  names an event, so it holds for the routes nobody has written yet.
- **Sales agents** — the shops, bureaux and agencies that sell an organiser's tickets over their own
  counter. Not promoters: a promoter posts a link and is paid a percentage of what it brings in; an
  agent takes cash from the public, so they carry the two things a promoter does not. **What they
  may sell is a list, not a level** — a bureau is handed the summer festival and not the members'
  evening, and the refusal happens when they open the night rather than after four seats and
  somebody's money. **Credit is money that moved, and everything else is counted**: only a payment
  in, a settlement out, credit taken back and an adjustment somebody signed are written down, while
  what has been sold, refunded and earned in commission is read from the allocations every time — so a refunded
  ticket hands its credit straight back and no two columns can drift apart. A sale that would take
  an agent past their limit is refused before a seat is held, and prepaid is the default: nought
  means they sell what they have paid for and not a ticket more. The commission rate is stamped on
  each booking, so agreeing a new percentage next season leaves last season alone. An agent gets a
  sign-in of their own, sees only their own bookings and only the nights they were given, and has
  their remaining credit in front of them all afternoon, and **a statement of their own** — the same
  figures the organiser reads, from the same derivation, because two sides arguing about a month
  from two different spreadsheets is how a settlement takes a fortnight. The organiser gets the
  account, the ledger and a statement per period. **An agency is not a member of staff**, so they hold `orders.view.own`
  rather than `orders.view`: the wide one also opens the customer directory, the waiting list and
  every abandoned basket, and none of those were sold along with the tickets. The panel is told what
  the caller holds at sign-in and leaves out what they cannot open — hiding a screen is a courtesy,
  the refusal is still the server's — so a bureau signs in to ten rows rather than thirty-eight, and
  the nights they may sell carry no buttons that turn them away.
- **The hall in three dimensions** — a seating plan is a drawing of the floor, and a floor is not
  what anybody is buying. Four numbers turn one into a room: the height of the stage, the rake of
  each block, the height each block starts at and how deep its platform is. The designer has a
  2D/3D icon that lifts the same canvas into that room, with the numbers beside it and the room
  redrawing as they are typed; the buyer's picker has the same icon, offered on exactly the charts
  an organiser has said are rooms, and a chair clicked in it goes into the basket like any other.
  Both draw from one shared projection, so the hall an organiser sets up is the hall a buyer is
  shown, and the arithmetic is pinned to numbers written down by hand — a balcony two units too low
  still looks like a balcony, and the seat somebody bought because the plan said they would see over
  the row in front is the one that finds out. Because publishing a chart deliberately moves no event
  onto it, an event whose chart has been republished says so and takes the new one up on request —
  refused outright if it would leave a sold seat pointing at a chair that no longer exists.
- **Productions: one show across many venues** — a run in one building was always a list of dates; a
  tour is a list of places. The production carries what an audience recognises — the name, the
  poster and the sentence that says what this is — and every night that says nothing of its own
  shows it, so twelve towns are not twelve copies of one paragraph; a night with something of its
  own to say still wins. Adding a date somewhere else is a copy that knows it is going somewhere
  else: the concessions, the fee and the tax travel, the prices travel only for the categories the
  new chart actually has, and nothing about a room does — the two seats behind the pillar are the
  pillar in the other building. It arrives as a draft in the venue's own clock, because going on
  sale is a decision about a town. The run adds itself up across its cities on every read, with the
  takings withheld as null rather than nought from anybody who may not see money, and the public
  page lists the other dates with the town beside them.
- **Group bookings, deposits and payment plans** — a school, a coach party or a company night out
  asks for forty seats now and pays for them by a date somebody agreed on the telephone. The plan is
  rows rather than a formula, because what gets chased is a date and an amount, and a date moved
  because a treasurer is away is a fact about that booking. The seats go at the deposit and the
  tickets go at the last payment: two different promises, and running them together is how a party
  arrives with forty codes nobody paid for. What is owed is the unpaid instalments and whether it is
  late is a comparison against the clock, so no column goes stale and nothing runs at midnight. Cash
  paid against a plan reaches an open till as a movement, because the sale itself was counted on the
  evening it was made. The party's name travels to the door list, where it is what somebody shouts
  across a foyer.
- **Thermal ticket printing at the window** — paper, a second after the card clears. One list of
  lines, two ways out: the byte stream a roll printer understands, for a local agent to push
  straight at the hardware, and a print view the browser lays out through the operating system's
  own driver — which is the only way to print a Persian event name on a printer bought in Berlin.
  Printing re-mints the code, because a stored ticket cannot reproduce its QR and the honest answer
  is a new one; that is exactly right at a counter, where a reprint is the live ticket and the copy
  somebody says they lost is not. It sits behind the selling permission for the same reason.
- **Sales attribution and promoter links** — a promoter is a person an organiser created on purpose,
  with a name, a link of their own and a percentage, which is what makes it safe to pay against; a
  `utm_source` is a string anybody can type. What was clicked is stamped on the booking once, name
  and rate included, so renaming somebody or agreeing a new percentage next season cannot rewrite
  what was owed for this one. What is owed is worked out from the tickets on every read, so a refund
  takes it back without anything having to remember to.
- **Accessible bookings** — a wheelchair space and the chair beside it are a pair, and the chair is
  never sold on its own. Spaces can be held off general sale until a stated number of hours before
  doors (or for good) while the box office keeps selling them throughout; they are released because
  the hour arrived, not because a job ran. The checkout can ask what a buyer needs to get in and sit
  down: free text, straight to the door list, never to a mailing list, erased with the buyer.
- **Timed price tiers** — cheaper early, dearer late, decided once and dated. A tier moves the zone
  prices for as long as its window lasts; which one is in force is worked out from the clock every
  time a price is read, so there is no job to miss at midnight and no column to go stale. Two tiers
  may not cover one moment, because a ticket cannot have two prices.
- **Price by how much is left, not only by when.** Tiers answered "what does this cost this week";
  they could not answer the question a box office actually asks, which is how the night is going. A
  ladder of rungs keyed on the percentage sold sits on top of them, and the rung in force is the
  highest the night has reached — a floor rather than a band, because bands have to meet exactly at
  every edge and an edge typed one out is a percentage with no price at all. Sold means sold: holds
  are not counted, or a burst of them expiring would ratchet the price up and drop it an hour later.
  Blocked places are not capacity, or a house that held forty back could never reach the top rung.
  A floor and a ceiling hold the result wherever the rungs would take it, which is what makes a
  mistyped rung survivable rather than a night sold at six times the price. It is off until somebody
  turns it on — a price that moves on its own is a decision a house makes deliberately, and some of
  them are forbidden to — and it never moves under a buyer who already has seats in a basket,
  because the hold carries the price it quoted.
- **A rehearsal that costs nothing.** Everything an organiser sets up before an onsale — the prices,
  the fees, the confirmation email, the QR code at the door — used to be first exercised by a
  stranger with a card. A night can be marked as a rehearsal instead: the checkout is handed a
  gateway that is not in the registry and cannot be chosen by a site, so no money can move whatever
  a form asks for; the bookings, tickets and door scans are real rows behaving exactly as they would
  on a live night, which is the only way to find out that they work; and no account-wide figure
  counts any of it — not the takings, the payouts, the platform's own invoice, a report, the customer
  directory, an agency's commission, the drawer at the window or an add-on's stock. Clearing it
  afterwards puts the seats back and hands back what the rehearsal spent out of a discount code, a
  presale code or a gift voucher, because a gift card is somebody else's money. The flag lives on the
  event and nowhere else: a night that has sold something cannot be rehearsed, and a rehearsal cannot
  go back on sale until it is cleared, so "a booking on a rehearsed night" and "a test booking" are
  the same set permanently and no figure has to ask twice. A rehearsed night is listed nowhere — not
  in what's on, not in the sitemap — and still opens by its own address, with a banner on every page
  of the path saying so. The embedded picker refuses to hold seats for one, because what happens
  after that endpoint is somebody else's basket and money this platform neither takes nor can stop:
  the promise is enforced at the edge of what we control rather than quietly broken beyond it.
- **An organiser can take their data and leave.** A platform nobody can leave is a platform nobody
  should arrive at. One button makes an archive of everything the account holds — every table that
  carries a tenant id, one comma-separated file each, plus every published seating plan as the
  geometry it is drawn from, which is the only part that is portable in the sense that matters.
  Inclusion is the default and the exclusions are four, named with reasons: a promise to hand
  somebody their data cannot be kept by a list that has to be extended each time a feature is added,
  so the archive asks the database what tables exist. Anything that was a credential is replaced by
  a marker in its own column rather than dropped, so the row still says what was set up without
  handing over the key. The download link is signed rather than authenticated, because it has to
  keep working after the account is closed — which is exactly when it is needed. Closing stops
  selling at once: sites down, keys off, schedules paused, and every session revoked except those of
  colleagues who also work for another venue. It is refused while any night that has not happened
  still has a live ticket on it — nobody vanishes while strangers are holding tickets — and the
  refusal names the nights so an organiser can cancel them, which refunds everybody through the
  ordinary path. A closed account is recoverable for thirty days and then erased: one `DELETE`
  against one row, which every table cascades from — and the staff go with it, except anybody who
  also works for another venue, because leaving their names behind would make the promise a
  half-measure.
- **Sign in the way a large venue already does, and scope a key to what it is for.** Two halves of
  one idea: who may do what, said once rather than implied. An account can point at its own OpenID
  Connect provider — the directory where its people are joined on their first day and removed on
  their last — and staff sign in there instead of holding a hundred passwords on somebody else's
  platform. The issuer's published configuration is read when the settings are saved, so an
  organiser types one address rather than three and a typo is caught while they are looking at it.
  No identity token is ever parsed: the code is exchanged on the back channel with the client
  secret and PKCE, and the person is read from the issuer's own userinfo endpoint, which is the same
  choice the buyer-facing Google sign-in made and for the same reason. Signing in creates nobody —
  an address the provider vouches for gets in only if somebody here already invited it, or a
  directory of forty thousand students would be forty thousand box office logins. With "required"
  on, a password is not a way in at all, including for the owner; the way back from a misconfigured
  provider is the platform switching it off, because a break-glass password is precisely what an
  attacker goes looking for. And an API key can now be narrowed to reading bookings, selling, or
  refunding: a shop's key should be able to sell a ticket without being able to hand money back.
  A key with no scopes may still do everything, because narrowing live keys silently would have
  taken working shops off sale on the day it was deployed.
- **A reason to come back: points and tiers.** Everything else here is about one night; this is the
  first thing about the years either side of it. Points are earned from the seats somebody still
  holds rather than from an order's total, so a refund takes them back with the money, half a refund
  takes back half, and a chargeback takes them all — one method settles a booking's points whatever
  has happened to it, which is why there is no separate "take them back" path that could disagree
  with the giving one. A comp earns nothing. One currency, because a single pool fed by two is
  arithmetic nobody can explain at a counter. The balance is the sum of the ledger, never a column,
  like every other running total on this platform. Tiers are read from points earned in a rolling
  window, so a standing can be lost — which is what makes it mean anything — and spending points
  never costs somebody their tier. Points turn into ordinary credit on the buyer's own address,
  which the checkout already knows how to spend: a second kind of money at the checkout would be a
  second set of edge cases at the one place where an edge case costs somebody their evening. And a
  tier does one thing a label cannot: during a presale, somebody signed in at or above the named
  rung is let in without a code.
- **Best available** — "four together" without a buyer hunting for them, scored by price, by how
  central the run is, and by how many orphan seats it would leave behind.
- **Timed entry** — arrival windows with their own capacity, held under the same lock as the seats
  so the window cannot oversell while a basket is open.
- **Multi-date events and seasons**, so a run of nights is one thing to manage and one thing to buy
  from.
- **A waiting list** for a sold-out night, told automatically when seats come back — and a queue
  whose states actually move. First asked, first told, each person with a window to buy in. A window
  that closes without a sale puts them back in the queue behind anybody who has not had a turn, and
  after three unanswered turns the platform stops writing to them: the row stays and the organiser
  can see why it went quiet, because an email every two hours until the doors open is not a waiting
  list. Buying takes somebody off the queue without their having to say so, matched on the address
  they joined with, whichever counter or website sold the seat.
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
- **Consent, and the line between service and news** — a confirmation of a booking somebody made
  and an advertisement for next season are different acts, and this platform now treats them
  differently. A message about a booking they hold reaches everybody who bought; a message about
  something they have not bought reaches only the people who said yes, and carries a link that lets
  them stop it without signing in to anything. Silence is not consent: the box at the checkout
  starts empty, nobody who has never been asked is written to, and there is no setting that changes
  that. The log of who agreed, when, and what they were shown is append-only — because what an
  audit asks a year later is not what the answer is but how you know — and an erasure deletes it
  outright rather than redacting it, since keeping "this person once said no" would be keeping a
  record of somebody in order to honour their wish not to be on one.
- **Exchange and resale** — the two answers to "I cannot come" that are better for everybody than
  a refund. An exchange is a move to another night, and the order of it is the whole feature: the
  new seats are held first, the old ones are given back only at the checkout, and what they were
  worth arrives as credit that pays for the new booking in the same breath — so nobody is ever left
  holding neither, which is exactly what "we will refund you, then buy again" costs a buyer. A fee
  may be kept for the work of it, and it comes out of what the old seats were worth rather than
  being charged separately. Resale is offering a seat back to the public at **face value**, and
  there is no field for a price anywhere in it, because a seller who could name their own would
  make this a touting platform with better paperwork. A listing moves no inventory: the seat is
  merely offered while it stands, so a buyer who lists a ticket and then finds they can come after
  all has lost nothing, and the swap — release, void, pay the seller, allocate — happens in one
  transaction at the moment somebody else pays. The seller is paid in credit by default, because a
  refund to a card charged eleven months ago fails often enough that promising it is dishonest. The
  box office can take a listing down for somebody who rang up, and has no way at all to put one up:
  listing a ticket is the ticket holder's decision about their own property.
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
- **Refunds that reach the card.** A refund used to be bookkeeping here: the seats went back on
  sale, the tickets were voided, every report agreed, and nobody's card was ever credited — somebody
  had to open the gateway's own dashboard afterwards and do it from memory. Now the money goes back
  *first*, through the gateway that took it, and the seats move only if it did: a booking cancelled
  while the money stayed put is the worst outcome available, because the buyer has neither their
  seat nor their money and the organiser hears about it weeks later from a complaint. A gateway that
  refuses stops everything and says why. Money that no gateway took — cash at the window, a transfer,
  a school's invoice — is recorded as owed in person, which is what a box office does anyway; what is
  new is that it is written down. Every attempt is kept, the refused ones included, with the
  reference the buyer's bank will want.
- **Settlement** — what was taken, what was handed back, what the platform's commission was, per
  period or per event, as a statement somebody can send to an accountant.
- **The platform collects its own money.** Everything else about money here is the organiser's:
  their gateways, their refunds, their settlement, their payouts. The platform's own side was a
  price list and a `subscriptions` row with a `current_period_end` that nothing ever looked at — an
  account signed up, a period passed, and not one thing happened. Now a finished period becomes an
  invoice: the plan fee at the price it carried that day, plus the commission on what they sold,
  taken from the same settlement arithmetic the organiser reads so the two can be compared line by
  line. Numbered sequentially per year, frozen when raised, and one per account per period by an
  exclusion constraint rather than a check. It is collected from a card on file — through the
  platform's own account, off-session, keyed on the invoice so a retry cannot charge twice — or, by
  default, by an invoice somebody pays by transfer, which is a complete answer and the only one a
  self-hosted deployment needs. A refused card climbs a retry ladder with the gateway's own reason
  on each rung, and an account that runs out of rungs is marked past due **and keeps working**:
  taking a venue's box office down on the night of a show over an unpaid invoice is a decision with
  a full house on the other end of it, so it belongs to a person in the console, not to a scheduled
  command.
- **Payouts: a period settled once.** The settlement report would tell anybody who asked what a
  window was worth, and told nobody whether it had been paid — so the same month could go out twice,
  a fortnight could fall between two payouts nobody lined up, and a refund in March quietly rewrote
  what February had appeared to be worth long after the money left. A payout closes a period: the
  figures are frozen as they stood and never recomputed, the per-event breakdown goes with them so
  the statement reprints rather than recalculates, and the days can only be settled once — an
  exclusion constraint in the database, not a check in a controller, because two operators clicking
  at the same moment arrive as two inserts and neither can see the other. A mistake is voided with a
  reason, which frees the days and keeps the row: what was sent and what is true stay separately
  findable. The organiser reads every payout on their own settlement screen; only the platform's
  operators can make one.
- **Reports** built by dragging fields, with no SQL box — [ADR-0006](docs/adr/0006-report-engine.md)
  says why — **and reports that arrive rather than waiting to be opened.** The builder could always
  answer any question somebody thought to ask it, which was the whole of the problem: somebody had
  to think to ask, and the reports nobody opened were the ones worth reading. A schedule is a timer
  on a definition and never a cache: it is re-run at the moment it is sent, so it cannot hand
  anybody numbers that have since been corrected. The email carries the first rows as text and a
  signed link to the spreadsheet, good for a week and openable without signing in — because the
  people who want Monday's figures are often a board member or an agency with no account here.
  Scheduling something needs the permission of the report's own source, so it is never a way to be
  sent a report you may not open, and the check is made when the list is read as well as when the
  schedule is set, because roles change.
- **Two-step sign-in** for staff, and **GDPR export and erasure** for buyers.
- **One hall, both sides of the glass.** The box office does not draw a seating plan of its own: it
  runs the buyer's picker — the same file, the same plan, the same zoom, the same room in three
  dimensions — pointed at the counter's own availability. A clerk on the telephone and the caller
  with the website open are looking at the same room. The window keeps the two things that make it
  a window: a house seat is on sale here and blocked online, with the name it is being kept under on
  the chair, and the sale happens in one movement with no cart and no hold left behind.

Every one of these is behind a named permission, translated into all six languages, and driven by a
browser check in `api/smoke.sh`.

## Who may do what

Eight built-in roles — owner, administrator, manager, box office, door staff, sales agent,
programme manager, viewer — over a closed catalogue of named permissions, and an organiser can
invent their own for a job their venue actually has.

The separation that matters most is money from operations. A door volunteer sees the head count and
not the takings; the same `/events/{id}/stats` endpoint answers both questions and only answers the
second to somebody who may hear it. A box office finds a booking, refunds it and puts a seat back on
sale, and cannot republish the map that seat is on. A sales agent is not staff at all, so their
lookup is narrowed to their own book by a permission of its own.

A **programme manager** is the one role shaped differently, and the difference is the point. Every
other role answers one question — what may this person do. A promoter putting on four nights in
somebody else's venue needs the other half of the sentence, *to which nights*, and the two are
multiplied rather than added: holding `tickets.release` and being given the Tuesday means you may
void a Tuesday ticket, and says nothing whatever about Wednesday. They get everything the site's own
administrator has for their own concerts — the window, the plan, opening and closing chairs on it,
the door, the tickets, what the night took — and every other night answers "cannot be found",
including the fact that it exists. The scope is middleware on any route with a bound event rather
than a check at each call site, because a scope applied seventy times is a scope forgotten once, and
the one that is forgotten is the one somebody finds. What is theirs stops at the account: the
customer directory, the season's settlement, the report builder and the productions belong to the
organiser, and a promoter is told so rather than shown a quarter of them.

The panel is told what the caller holds — at sign-in, and again from `GET /v1/auth/me` on every boot,
because a role can be narrowed while somebody has the tab open — and leaves out the screens and the
buttons they cannot use. That is a courtesy rather than a control: every endpoint refuses on its own,
and the panel only stops offering doors that would close in somebody's face.

Somebody new gets in by invitation. Issuing one mints a token, keeps only its hash and hands the
plaintext over exactly once, so it can go in an email; following the link opens a screen that already
says who invited them and as what, and ends with a membership and a signed-in session. An address
that already has an account sends that account's password — the invitation says the address may
join, the password says it is them holding the link, and both are required. One link is worth one
membership: spent, expired and never-real are told apart, because whoever is holding it was sent it.

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
deliberately turned it on for their account. The one thing a draft is allowed that a published page
is not is a half-written list row: the editor's "add a picture" has to leave a row on the screen to
type into, and publishing is where an unfinished one is dropped.

Sixteen kinds of block, and the five that matter most to a venue selling a night are the ones a
theatre's own home page is actually made of:

| Module | What it is for |
| --- | --- |
| Slideshow | The room, in the organiser's own photographs. A scroll-snapping track first, so it swipes on a phone and works with no JavaScript at all; the arrows, the dots and the optional autoplay are added on top, and the autoplay never starts for a reader who has asked their system for less motion. |
| Video | A trailer from YouTube or Vimeo, or a file the browser plays itself. The address is resolved to a provider and an id **on the way in**, so nothing an organiser typed is ever interpolated into an `iframe` src — and the player is not loaded until somebody presses play, because a page about buying a ticket should not hand every visitor to a third party first. |
| The details | Doors, running time, interval, age limit — label and value, because somebody is looking for one row rather than for the paragraph it would be buried in. |
| Terms | The conditions of sale, folded by default. A `details` element rather than a script: it opens without JavaScript, prints open, and the browser's own find reaches inside it. |
| Buy | The night, its from-price and a button, from anywhere on the site. It reads the same sale state the event's own page reads, so a button saying "on sale" and a page saying "not yet" cannot both exist; where the sale is shut it gives the event's own sentence instead of a link to nothing. |

Configure `SEATMAP_PANEL_HOSTS` in production. It is the allow-list for the control panel; every
other `Host` is looked up as a site. With it unset, one host serves both — which is what you want
in development and never in production.

## Telling your own systems what happened

An organiser's box office is rarely the only system they run. Webhooks are how the others find out:
nine event types — a booking confirmed, cancelled, refunded or disputed; a night published, cancelled
or moved; somebody walking through the door; seats offered to the waiting list — posted to endpoints
the organiser manages on the Connections screen, beside the API keys, because it is the same job.

Every delivery carries `X-Seatmap-Signature`: an HMAC-SHA256 over the method, path, timestamp, nonce
and body hash, joined by newlines — the same construction this API requires of signed calls coming
the other way, so a receiver verifies us with the code they already wrote.

Three things make it a feature rather than a mechanism, and all three are on the screen: whether an
endpoint is working, what was actually sent, and a way to send it again. A receiver that has been
down for hours is switched off with a reason an organiser can read, and switching it back on forgives
the count that switched it off. A replay is a **new** delivery, never a reset of the old one: what
was tried and what came back is the record somebody reads to settle an argument with their own
developer.

A webhook address is opened by *our* server on an organiser's instruction, which is a server-side
request forgery surface (threat T13). `https` only, a hostname rather than a bare IP, and every
address it resolves to has to be public. The same guard now covers the SSO issuer URL, which was
carrying the identical hole.

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
./vendor/bin/phpunit                        # 716 unit, feature and module tests
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

./smoke.sh                    # all fifty-eight, in order
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
| The money goes back through the gateway that took it | `RefundToCardTest`, `StripeGatewayTest`, `PayPalGatewayTest` |
| A gateway that refuses leaves the seats sold and says why | `RefundToCardTest` |
| A gateway that does not answer at all is a refusal, not permission | `RefundToCardTest` |
| Seat-by-seat refunds add up to exactly what was charged | `RefundToCardTest` |
| Money taken at the window is recorded as owed in person | `RefundToCardTest` |
| Credit instead of money never touches the gateway | `RefundToCardTest` |
| A payment the gateway has already refunded is not refunded twice | `RefundToCardTest`, `StripeGatewayTest` |
| A buyer's granted request sends money, and a refused one stays pending | `RefundToCardTest` |
| A scheduled report is re-run when it is sent, never a remembered copy | `ScheduledReportTest` |
| The link in the email works from an inbox, and stops working after a week | `ScheduledReportTest` |
| A link nobody signed is not found rather than refused | `ScheduledReportTest` |
| Scheduling is not a way to be sent a report you may not open | `ScheduledReportTest` |
| The hour is read in the schedule's own clock, frozen when it was made | `ScheduledReportTest` |
| A schedule that will not run says why and moves its timer on | `ScheduledReportTest` |
| A monthly report never lands on a day some months do not have | `ScheduledReportTest` |
| A finished period becomes an invoice; an unfinished one does not | `PlatformBillingTest` |
| The commission on what they sold is on the same invoice, with what it was taken of | `PlatformBillingTest` |
| Four missed months are four invoices, numbered per year and never reused | `PlatformBillingTest` |
| An account in its trial is not billed | `PlatformBillingTest` |
| An account that pays by transfer is never told a payment failed | `PlatformBillingTest` |
| A card on file is charged off-session, keyed so a retry cannot charge twice | `PlatformBillingTest` |
| A refused card climbs a ladder; a ladder that runs out marks past due and stops there | `PlatformBillingTest` |
| Paying brings an account back into good standing on its own | `PlatformBillingTest` |
| A box office manager is not shown the account's card | `PlatformBillingTest` |
| A period is settled at the figures it had, and a later refund does not rewrite them | `PayoutTest` |
| The same days cannot be settled twice, nor two periods that merely touch | `PayoutTest` |
| The database itself refuses an overlap, past every check in the code | `PayoutTest` |
| The same days in another currency are a different period | `PayoutTest` |
| Voiding one frees its days and keeps the row | `PayoutTest` |
| A period with nothing in it is refused rather than recorded as a zero | `PayoutTest` |
| Support may look at payouts and not send money | `PayoutTest` |
| An organiser reads their own payouts and nobody else's | `PayoutTest`, `console_smoke` |
| A refunded booking says on its own screen where the money went | `refund_smoke` |
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
| First asked, first told, and a promised place is not offered twice | `WaitingListTest` |
| A turn that runs out puts somebody back in the queue, not out of it | `WaitingListTest`, `waitlist_smoke` |
| Three unanswered turns and the platform stops writing; asking again resets it | `WaitingListTest` |
| Buying takes somebody off the queue without their saying so | `WaitingListTest`, `waitlist_smoke` |
| The scheduled round reaches a queue where everybody has already been told | `WaitingListTest` |
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
| Nobody who has never been asked is sent marketing | `MarketingConsentTest` |
| A message about a booking somebody holds needs no permission | `MarketingConsentTest` |
| Leaving needs no sign-in, and a wrong signature says nothing at all | `MarketingConsentTest` |
| The stored answer is the log folded, and can be proved so | `MarketingConsentTest` |
| A translation cannot invent a block, a field, or a second page shape | `SitePageTranslationTest` |
| The switcher offers what the site is written in, and the site's own language always | `SitePageTranslationTest` |
| A listed seat is offered to the public without leaving its owner | `ResaleTest` |
| The seller is paid at face value the moment somebody else pays | `ResaleTest` |
| Staff may take a listing down and have no way at all to put one up | `ResaleTest` |
| A seat cannot be taken off sale while somebody is at the checkout with it | `ResaleTest` |
| Walking in on a listed ticket takes the seat off the public map | `ResaleTest` |
| The old seats are still theirs until the new ones are paid for | `ExchangeTest` |
| An exchange fee comes out of what the old seats were worth | `ExchangeTest` |
| Somebody else's reference is not a way to spend their exchange | `ExchangeTest` |
| Last season's seats are held for the people who sat in them, and freed the moment the deadline passes | `RenewalTest` |
| A renewal invitation is signed, needs no password, and cannot be guessed | `RenewalTest` |
| Rows fitted from a foreign export put every chair back where it was | `ChartImporterTest`, `ChartImportTest` |
| An aisle in an imported row becomes empty places, not a shorter row | `ChartImporterTest` |
| Scenery has a ceiling, and it is enforced rather than merely configured | `SeatMapValidatorTest` |
| Inside one block, the blocks either side of it are drawn too, and can be bought from | `import_smoke` |
| A block is the shape its own seats make, and its name fits inside it | `import_smoke` |
| The plan fills the screen with the summary still on it | `picker_smoke` |
| Today's price is today's, with nothing running at midnight to make it so | `PriceTierTest` |
| The number on the plan and the number in the basket are the same number | `PriceTierTest`, `tiers_smoke` |
| Two price tiers cannot cover one moment | `PriceTierTest` |
| The price climbs as the house fills, and the tier is adjusted rather than replaced | `DemandPricingTest` |
| The floor and the ceiling hold whatever the ladder does | `DemandPricingTest` |
| Holds are not sales, and blocked seats are not capacity | `DemandPricingTest` |
| A price that moves does not move under somebody already holding seats | `DemandPricingTest` |
| Nothing moves until somebody turns it on, and an explicit seat price never moves | `DemandPricingTest` |
| Rails the wrong way round are refused rather than resolved | `DemandPricingTest` |
| A rehearsal's checkout never reaches a real gateway, whatever the form asks for | `RehearsalTest`, `rehearsal_smoke` |
| A night that has sold something cannot be rehearsed, and a rehearsal cannot go back on sale uncleared | `RehearsalTest`, `rehearsal_smoke` |
| No account-wide figure counts a rehearsal — takings, reports, customers, add-on stock | `RehearsalTest` |
| Clearing puts the seats back and takes the tickets and the scans with them | `RehearsalTest`, `rehearsal_smoke` |
| Clearing hands back what a discount code and a gift voucher spent | `RehearsalTest` |
| A rehearsal is listed nowhere and opens by its own address, saying what it is | `RehearsalTest`, `rehearsal_smoke` |
| Somebody else's shop cannot hold seats on a rehearsed night, though it can still read the plan | `RehearsalTest` |
| An archive holds every tenant-scoped table, including the one added last month | `LeavingTest`, `leaving_smoke` |
| A credential is replaced in its own column rather than exported or dropped | `LeavingTest` |
| The download link needs no password, and a tampered one is nothing | `LeavingTest`, `leaving_smoke` |
| Nobody can close an account while people are holding tickets for nights that have not happened | `LeavingTest`, `leaving_smoke` |
| Closing stops the sites, the keys and the sessions at once — except a colleague's at another venue | `LeavingTest`, `leaving_smoke` |
| The link still works after the door is shut, which is when it is needed | `LeavingTest`, `leaving_smoke` |
| A closed account is erased when its window runs out, and not before | `LeavingTest` |
| The staff go with the account, unless they also work for somebody else | `LeavingTest` |
| Saving a provider reads its published endpoints, and a wrong address is refused there and then | `SingleSignOnTest`, `sso_smoke` |
| A client secret has no way out, and an empty box keeps the stored one | `SingleSignOnTest` |
| Somebody the provider vouches for who was never invited does not get in | `SingleSignOnTest` |
| A sign-in that came back twice works once | `SingleSignOnTest` |
| With single sign-on required, a password is refused — and a wrong password still answers the wrong-password way | `SingleSignOnTest` |
| Only the platform can let a locked-out account back in | `SingleSignOnTest` |
| A key that may sell may not refund, and a key from before scopes may still do everything | `SingleSignOnTest`, `sso_smoke` |
| Points are counted from the seats somebody still holds, so half a refund takes back half | `LoyaltyTest`, `loyalty_smoke` |
| A chargeback takes the points with the money, and a comp never earned any | `LoyaltyTest` |
| Settling a booking's points twice gives nothing twice | `LoyaltyTest` |
| A tier is the highest rung the window reaches, and spending points does not cost it | `LoyaltyTest` |
| Points turn into credit the checkout already knows how to spend, and the remainder stays theirs | `LoyaltyTest`, `loyalty_smoke` |
| A tier walks past the presale queue; a standing too low is still asked for a code | `LoyaltyTest` |
| Points that have gone quiet go, unless the scheme promised they never would | `LoyaltyTest` |
| A video address becomes a provider and an id, and anything else is not a video | `SiteModulesTest`, `modules_smoke` |
| The player is absent until a visitor presses play, and carries the address the server resolved | `SiteModulesTest`, `modules_smoke` |
| A slideshow is a scroller before its script runs, and a carousel after | `modules_smoke` |
| A row somebody is still typing into survives the save and does not survive publishing | `SiteModulesTest`, `modules_smoke` |
| A buy button says what the event says, and a presale is not advertised to somebody without a code | `SiteModulesTest` |
| A buy block cannot name another organiser's night | `SiteModulesTest` |
| What we post is signed the way we require of calls coming the other way | `WebhookTest`, `webhooks_smoke` |
| Every event the picker offers is one something actually sends | `WebhookTest` |
| A receiver that is down is switched off with a reason, and switching it on forgives the count | `WebhookTest`, `webhooks_smoke` |
| A replay is a new delivery, so what happened the first time survives | `WebhookTest`, `webhooks_smoke` |
| A retry the queue lost is picked up by the sweep rather than owed for ever | `WebhookTest` |
| An address an organiser types can never point this server at a private network | `WebhookTest` |
| The chair beside a wheelchair space is never sold on its own | `AccessibleBookingTest` |
| Held-back spaces are off the public plan and still at the counter | `AccessibleBookingTest` |
| They go on sale because the hour arrived, with nothing run to release them | `AccessibleBookingTest` |
| What a buyer needs reaches the door and leaves with them | `AccessibleBookingTest` |
| Renaming a promoter does not rewrite what was owed last month | `PromoterAttributionTest` |
| A refund takes the commission back with it | `PromoterAttributionTest` |
| A link older than the window has stopped selling | `PromoterAttributionTest` |
| A promoter who has sold something is switched off rather than deleted | `PromoterAttributionTest` |
| A chargeback puts the seats back and stops the ticket at the door | `ChargebackTest`, `chargeback_smoke` |
| The same dispute reported twice is one chargeback | `ChargebackTest` |
| A chargeback is told apart from a refund in the takings | `ChargebackTest` |
| A block lapses on its date without anybody acting | `ChargebackTest` |
| A blocked buyer is refused before the money, and told to ring | `ChargebackTest`, `chargeback_smoke` |
| A ticket prints what somebody is holding the paper for | `TicketPrintingTest`, `printing_smoke` |
| Printing re-mints the code, so the copy that was lost stops working | `TicketPrintingTest`, `printing_smoke` |
| The bytes and the print view say the same thing about the same seat | `TicketPrintingTest`, `printing_smoke` |
| A script the printer cannot set is dropped rather than printed as questions | `TicketPrintingTest` |
| Only somebody who may sell may print | `TicketPrintingTest` |
| The seats go at the deposit and the tickets at the last payment | `PaymentPlanTest`, `plans_smoke` |
| A plan has to add up to what the booking costs | `PaymentPlanTest` |
| A booking is overdue because the date arrived, not because a job ran | `PaymentPlanTest` |
| A payment recorded twice by two clerks is one payment | `PaymentPlanTest` |
| A date can be moved and an amount cannot quietly change the total | `PaymentPlanTest` |
| Reading a plan is not being able to take money for one | `PaymentPlanTest` |
| The party's name reaches the door, not just the person who signed | `PaymentPlanTest`, `plans_smoke` |
| A tour date copies the show and nothing about the room | `ProductionTest`, `productions_smoke` |
| A price for a category the new hall has never heard of is left behind | `ProductionTest`, `productions_smoke` |
| A chart from another building, or one never published, is refused | `ProductionTest` |
| A night shows the production's words until it has its own | `ProductionTest` |
| The run adds up across its towns, and the takings are withheld from who may not see them | `ProductionTest` |
| A production with dates is not deleted by accident | `ProductionTest` |
| The other dates of a tour say which town they are in | `ProductionTest`, `productions_smoke` |
| The rake, the stage height and the balcony's height are what the numbers say | `tools/hall3d-check.mjs` |
| The room opens from behind the audience, whichever way the chart is drawn | `tools/hall3d-check.mjs` |
| A chart with no room set up offers no 3D button and is not guessed at | `HallInThreeDimensionsTest`, `hall3d_smoke` |
| The heights survive validation, publishing and the journey to a browser | `HallInThreeDimensionsTest` |
| A night stays on its own chart until somebody says otherwise | `HallInThreeDimensionsTest`, `hall3d_smoke` |
| A chart that has lost a sold seat is refused | `HallInThreeDimensionsTest` |
| A chair clicked in the room lands in the basket | `hall3d_smoke` |
| An agent sells what they were given and nothing else | `SalesAgentTest`, `agents_smoke` |
| An agent cannot sell more than they have paid for | `SalesAgentTest`, `agents_smoke` |
| What they owe is counted from the seats, so a refund returns the credit | `SalesAgentTest` |
| The rate agreed today does not rewrite what was owed last season | `SalesAgentTest` |
| A top-up is money in whichever way its sign was typed | `SalesAgentTest` |
| Credit can be taken back as well as paid in, and counted apart from a settlement | `SalesAgentTest` |
| An agent sees their own bookings and nobody else's | `SalesAgentTest` |
| The statement bounds the sales and never the balance | `SalesAgentTest`, `agents_smoke` |
| An agent who has sold something is switched off rather than deleted | `SalesAgentTest` |
| A comp costs an agent nothing and is still theirs | `SalesAgentTest` |
| The window is handed the same hall the buyer is looking at | `BoxOfficeCounterTest`, `counter_smoke` |
| The counter's availability sees the house seat and says whose it is | `BoxOfficeCounterTest` |
| Four side by side is a suggestion at a window and a hold on a website | `together_smoke` |
| A manager runs their own night the way the organiser would | `ProgrammeManagerTest`, `managers_smoke` |
| A night they were not given cannot be found, by any route | `ProgrammeManagerTest`, `managers_smoke` |
| A manager with no nights reaches nothing rather than everything | `ProgrammeManagerTest` |
| The account's own screens are not a manager's | `ProgrammeManagerTest` |
| Appointing one takes both authorities | `ProgrammeManagerTest` |
| An agency is never handed the organiser's audience | `SalesAgentTest`, `agents_smoke` |
| The narrow permission shows nothing to somebody who sells for nobody | `SalesAgentTest` |
| An agency reads its own statement and reaches no others | `SalesAgentTest`, `agents_smoke` |
| Somebody who sells for nobody is told so rather than refused | `SalesAgentTest` |
| Signing in says what this person may do | `AccessControlTest` |
| A role narrowed mid-session is the role the panel is told about | `AccessControlTest` |
| Every permission and every role has a sentence in every language | `AccessControlTest` |
| An invitation token exists once and is stored hashed | `AccessControlTest` |
| The link says who invited them and as what before it asks for anything | `TeamInvitationTest`, `invite_smoke` |
| Somebody new joins and lands where they were invited | `TeamInvitationTest`, `invite_smoke` |
| A link is worth one membership and no more | `TeamInvitationTest`, `invite_smoke` |
| An address that already has an account proves it is them | `TeamInvitationTest` |
| A role deleted since the invitation went out is refused rather than guessed at | `TeamInvitationTest` |

## Installing the plugin

Copy `wordpress-plugin/seatmap-connect/` into `wp-content/plugins/`, activate it, then fill in the
API URL, key id and secret under **WooCommerce → Seatmap**. Use **Test connection** to confirm the
signature and clock are right before going near a real sale.
