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
