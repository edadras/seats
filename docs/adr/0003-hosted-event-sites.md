# ADR-0003: Hosted event sites on the organiser's own domain

- Status: Accepted
- Date: 2026-09-08

## Context

Until now the product sold seats *into someone else's shop*: the organiser ran WooCommerce and
installed the plugin, and ADR-0001 drew the boundary so that money, tax and refunds stayed there
(`docs/adr/0001-saas-woocommerce-boundary.md`).

Organisers without a shop have nowhere to sell from. The requirement is that the platform can also
give them a complete event site on their own domain — pages, menus, a theme they can change, seat
selection, checkout, and ticket management — all administered from the Seatmap panel rather than
from a second admin somewhere else.

Three shapes were considered:

1. **WordPress multisite.** One install, many sites, domain mapping. Cheapest to run and the
   existing plugin works unchanged, but one shared database and one WordPress version for every
   organiser: a bad plugin on one site takes the platform down, and an upgrade is all-or-nothing.
2. **One WordPress per organiser.** Full isolation, per-site backups. Infrastructure cost and
   upgrade work grow linearly with customers, and every site is a second admin surface we do not
   control the quality of.
3. **First-party site builder.** The platform renders the site itself.

## Decision

**The platform renders the site (3).** Themes, pages and menus are ours; the panel is the only
admin. WordPress is not involved in a hosted site.

The WooCommerce plugin is unaffected and stays supported: it remains the path for an organiser who
already has a shop and wants to keep selling through it. ADR-0001 continues to govern that path.
What follows governs the hosted path only.

### Consequences, stated plainly

This decision moves the shop inside our boundary. ADR-0001 said "we never touch money"; for a
hosted site that is no longer true, and the reasoning behind it does not disappear because the
decision changed:

- We now own a cart, an order, a payment and a refund. Payment goes through a **gateway driver**
  behind one interface, so the parts of the system that know about seats never learn about card
  processing. The first driver settles nothing (`offline` — pay at the box office), which is real
  for a great many venues and lets the whole flow be built and tested before any gateway account
  exists.
- We do **not** do tax calculation, invoicing or accounting. A hosted site records what was
  charged; it is not a book of account. An organiser who needs those keeps WooCommerce.

### 1. A request is resolved by its Host, before anything else

`sites` has one or more `site_domains`. A public request is matched, in order:

1. the Host is a panel host from `config('seatmap.panel_hosts')` → the panel;
2. the Host matches a verified `site_domains.hostname` → that site, and therefore that tenant;
3. otherwise → 404.

The resolved site sets the tenant for the rest of the request, exactly as the API's `tenant`
middleware does for an authenticated one. **Resolution is by hostname only.** No header, no query
parameter and no path segment can select a site: a request that could name its own tenant is a
tenant-isolation bug waiting to be found (threat T1 in `docs/THREAT_MODEL.md`).

The lookup is cached by hostname, and the cache is invalidated when a domain row changes. A miss
is cached too, briefly, so an unknown Host cannot be used to hammer the database.

### 2. A domain is not live until it is proven

Adding `tickets.northgate.example` does not serve anything. The organiser is given a token to
publish as a `TXT` record (or a file at a well-known path), and a queued job verifies it. Only a
verified domain is written into the routing table.

Without this, anyone could point a DNS record at the platform and have us serve their traffic on
someone else's certificate — and, worse, an organiser could claim a domain they do not own and
receive its visitors. Verification is what makes "the organiser's own domain" mean anything.

Certificates are issued per verified domain by the edge (ACME HTTP-01), not by the application.
The application's contract is only: a request arriving for a verified hostname resolves to that
site. `docs/OPERATIONS.md` carries the deployment side.

### 3. Content is data, not code

A site is:

- `sites` — the site itself: theme key, brand (logo, colours, typeface), locale, timezone, status.
- `site_pages` — a slug, a title and an ordered list of **blocks**. A block is `{type, …}`:
  `richText`, `heading`, `image`, `eventList`, `eventDetail`, `faq`, `map`, `html`. Rendering is a
  match on `type`; an unknown type renders nothing rather than breaking the page.
- `site_menus` / `site_menu_items` — a tree of links, each pointing at a page, an event, or a URL.

Themes are first-party Blade layouts plus a token set, chosen by key. A theme cannot execute
organiser-supplied code, which is the whole reason for not being WordPress. The one escape hatch,
the `html` block, is sanitised and is available only to a tenant whose plan allows it.

Every page has a **draft** and a **published** revision. Editing does not change what visitors see
until publish — the same discipline the seat maps already have, and for the same reason: a venue
should not be able to break its own storefront in the middle of an on-sale.

### 4. The hosted storefront is a client of the same order lifecycle

It would be a mistake to write a second order path. The seat-selling code — holds with a signed
price snapshot, register/confirm/cancel/refund, exactly-once ticket issuance, the partial unique
indexes — is the part of this system that has been tested hardest, and it is reached through
`OrderService` with an `ApiClient`.

So a site gets its own `api_clients` row, of kind `storefront`, created with the site. The hosted
checkout calls the same service methods, in-process, with the same idempotency rules. It has no
API key: the credential exists so that an *external* caller can prove who it is, and there is no
external caller here. Nothing in `OrderService` needs to know which kind of client it is serving.

This means a hosted sale and a WooCommerce sale produce the same allocations, the same tickets and
the same check-in behaviour, and are reported together.

### 5. The buyer's cart lives in a signed cookie, not in a table

A hold already *is* the reservation, with a server-set price and a signature over it. A cart table
would be a second, weaker copy of that. The site keeps the hold token in a signed, http-only
cookie; the price the buyer is charged is read from the hold on the server at checkout, never from
anything the browser sent.

## Alternatives rejected

- **Rendering the site from the panel's single-page app.** An event site has to be indexable and
  fast on a phone on venue Wi-Fi; a blank page that fetches JSON is neither.
- **A general page builder with arbitrary HTML for everyone.** Organiser-supplied markup on a
  domain we serve is a stored-XSS surface across tenants. Blocks are typed; raw HTML is gated.
- **Letting the organiser upload themes.** That is the WordPress problem we chose not to have.

## Amendment, 2026-09: themes an organiser writes

Organisers asked for their own look, and "pick one of six" was not going to be the whole answer.
The line is the same one as before, drawn in a different place: **a theme is tokens and a
stylesheet, and neither can run.**

- **Tokens** are a closed table (`App\Domain\Sites\ThemeTokens`): each is either a hex colour or a
  key into a list of values written in that file, and each names the CSS custom property it sets.
  A `font-family` an organiser typed never reaches a `<style>` block, because a font-family
  somebody typed is an injection into a stylesheet. An unknown token is dropped, not rejected — a
  site that is selling should lose one control rather than a save.

- **The stylesheet** is the escape hatch, and it is treated as hostile input
  (`App\Domain\Sites\ThemeCss`). Angle brackets never survive, so nothing can close the element it
  lands in; `@import` never survives, so a page cannot fetch a third party's CSS from the visitor's
  browser at render time; `expression(`, `behavior:` and `-moz-binding:` never survive; and a
  `url()` that is not plainly a picture or a page is replaced with `url(about:blank)` rather than
  deleted, so the author can see what happened. It is not a CSS parser and does not pretend to be
  one: a rule it does not understand is a rule the browser ignores.

- **Every save keeps what it replaced.** A stylesheet is the one thing an organiser can change that
  breaks every page of a live site at once, so "put it back" is a click.

The rejected alternative stands, and this is not it: uploading a theme — a bundle of files that
runs on our server — remains the WordPress problem we chose not to have.

## Amendment, 2026-09: gateways that redirect

The offline gateway settles inline, so the first version of the hosted checkout never needed the
half that matters with a real gateway: the buyer leaves, money moves elsewhere, and they come back
— or they never do.

- `GET|POST /pay/{gateway}/return/{reference}` on the site's own host is where a gateway sends the
  buyer. It believes nothing in the request: settlement asks the gateway, over its own API, using
  the reference written onto the order when the payment began. The gateway must also be one the
  site actually offers, or the return path would be a way to ask any installed module to settle
  anybody's order.

- `payments:reconcile`, every ten minutes, asks about pending orders whose buyer never came back.
  Their seats are protected either way — the hold expires — but their money has moved and their
  order has not, and learning that from a support email is not good enough. `settle()` is
  idempotent by contract, which is what makes asking again safe.

Webhooks are **not** implemented, and the reconciliation job is why that is a delay rather than a
hole: every gateway here is settled by asking it, not by being told.

## Amendment, 2026-09: the platform's own console

Somebody runs this platform, and they need to see every organiser on it. That is a different
application from the panel, and it is built as one.

- **Operators are not tenant users.** A `platform_admins` table, checked by its own middleware. A
  role inside an account is never a way in: if it were, every organiser's data would be one bug in
  the permission catalogue away from every other organiser's.
- **The console reads unscoped, once, in one place.** The middleware wraps the request in
  `runUnscoped()` rather than scattering `withoutGlobalScope` through controllers, where one
  forgotten call is a screen that quietly shows nothing.
- **Its own login.** The panel's asks which organiser somebody belongs to; an operator belongs to
  none. One message for every failure, so the endpoint cannot be used to find out who runs the
  platform.
- **Two levels.** Support can look, an operator can change — enforced in the controllers, not by
  hiding buttons.
- **Its own log.** `platform_audit_logs` answers "who at the platform touched my account", which is
  a different question from the one an organiser's own audit log answers, and usually a more
  pointed one. Signing in is recorded, not just changes.
- **Impersonation is time-boxed and doubly recorded.** An hour, expiring by itself, written to the
  platform's log *and* to the organiser's own — it is their account that was entered.
- **A plan may only promise what the platform enforces.** The console offers exactly
  `PlanLimits::KEYS`. Scans are deliberately not limited: cutting off a door on a busy night
  because a counter passed a number would be the platform breaking the one thing a venue cannot
  recover from.

## Amendment, 2026-09: buyers signing in, and why the round trip happens on our host

Organisers asked for their buyers to be able to sign in with Google and find what they had bought.
Two things about the design are forced rather than chosen.

**The redirect URI is ours, not theirs.** Google will only send somebody back to a URI registered
in advance in the project that owns the client. Sites here live on organisers' own domains, which
this platform learns about after the fact and cannot register; and asking every organiser to create
a Google Cloud project of their own means nobody turns the feature on. So there is one client for
the whole platform and one redirect URI on the platform's own host, and the buyer is handed back to
their site with a token that is good for sixty seconds and one use. Nothing about the buyer travels
in that URL, and the `state` Google carries is a random nonce — what it *means* is kept here,
because a state that says which site to return to is a state an attacker can write.

**There is no buyer account table.** Signing in with Google proves an email address, and orders
already carry the address they were bought with, so the address is the account. A `buyer_accounts`
table would be a second copy of every buyer's name and address, sitting beside the orders and
drifting from them — the same argument that keeps the customer directory derived (ADR-0006's
amendment). What the session holds after signing in is an address Google said it had verified, and
a display name to greet them with.

Consequences worth stating:

- **An address Google has not verified is refused.** `email_verified: false` means Google knows an
  account claims that address and has not checked it. This platform matches orders by address;
  accepting an unverified one would hand somebody else's tickets to whoever claimed their email.
- **Getting a ticket back means replacing it.** The platform keeps a hash of the code it emailed
  and cannot recover the code itself, so "send me my tickets again" is necessarily "issue new
  codes and stop the old ones working". That is a real consequence for a buyer who has passed a
  ticket to a friend, so it is a button with a warning beside it rather than something that
  happens when the page loads, and it is a POST so that no prefetch can invalidate a ticket.
- **It is off until an organiser turns it on**, and unavailable entirely where the platform has no
  Google credentials — in which case the panel says so instead of offering a switch that would
  lead to a Google error page.
