# ADR-0001: Domain boundary between the SaaS and WooCommerce

- Status: Accepted
- Date: 2026-09-08

## Context

Organisers already sell on their own WordPress/WooCommerce sites. They have a payment gateway,
a tax and coupon configuration, a customer base and an accounting workflow. What they lack is
seating: a venue map, per-seat inventory, and a way to stop two buyers taking seat A-12.

We must decide which system owns which facts. Getting this wrong produces either a second,
divergent order system inside the SaaS, or seat inventory that cannot be trusted.

## Decision

The SaaS is an **inventory service**, not a shop. Ownership splits as follows and is not
negotiable per tenant:

**The SaaS owns:** venue, seat map and its immutable published versions, event, price zones and
seat overrides, availability, holds, allocations (the definitive record of "seat X is sold for
event Y"), tickets and their QR tokens, check-in, and seat-level analytics.

**WooCommerce owns:** the customer identity, cart, checkout, order, payment gateway, tax,
coupons, refunds, invoices and the site's financial reporting.

Consequences of the split:

1. **No cardholder data ever reaches the SaaS.** The SaaS is out of PCI scope. It never sees a
   PAN, a gateway token, or a billing address it did not ask for.
2. **The order is a foreign key, not a record.** The SaaS stores `external_order_id` (and the
   originating `api_client_id`) on an allocation. It does not model line items, totals, tax or
   discounts.
3. **Money is WooCommerce's answer.** The SaaS returns a *signed price snapshot* per seat so the
   plugin can price the cart line without trusting the browser, but the SaaS is not the
   authority on what the customer finally paid — coupons and tax may change it. The snapshot
   exists to stop tampering, not to do accounting.
4. **The SaaS is the authority on seat state.** WooCommerce may not mark a seat sold; it can
   only ask the SaaS to confirm a hold. If the SaaS says no, the sale does not include that seat.
5. **Every state transition is driven by WooCommerce order events** (`created`,
   `payment_complete`, `cancelled`, `failed`, `refunded`, `deleted`) and is idempotent, because
   those events are delivered over an unreliable network by a plugin that may retry.

## Alternatives rejected

- **SaaS owns checkout and payment.** Rejected: duplicates the organiser's existing gateway
  relationship, drags the SaaS into PCI scope and into per-country payment/tax rules, and forces
  the buyer through a second checkout on a different domain.
- **WooCommerce owns seat inventory, SaaS only draws maps.** Rejected: seat inventory must be
  correct under concurrency and consistent across an organiser's sites. WordPress has no
  suitable locking primitive, and a tenant with two sites would have two disagreeing inventories.
- **Two-phase commit between the systems.** Rejected: not available across HTTP to a WordPress
  plugin. Replaced by hold → confirm with idempotency keys and a reconciliation job, which
  degrades safely (a lost confirm leaves a hold that expires, never a double sale).

## Consequences

- The plugin must be resilient: retry with the same `Idempotency-Key`, never invent a second
  order, and reconcile on a schedule (`wp-cron`) rather than assuming a request succeeded.
- A refund in WooCommerce cannot silently free a seat: it calls the SaaS, which voids the ticket
  and releases the seat according to the event's refund policy.
- Seat-level revenue reporting in the SaaS is indicative (based on price snapshots), and the
  documentation says so. The site's books are WooCommerce's.
