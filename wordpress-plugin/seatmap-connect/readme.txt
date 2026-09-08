=== Seatmap Connect ===
Contributors: seatmap
Tags: woocommerce, tickets, seating, events, reserved seating
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell reserved seats on your own WooCommerce store, backed by the Seatmap seating service.

== Description ==

Your customers pick their seats on your site and pay through your existing WooCommerce checkout.
Seatmap keeps the seating plan and the inventory; WooCommerce keeps the cart, the payment, the tax
and the books.

**What this plugin does not do:** it never sends card details anywhere. Payment stays entirely
inside WooCommerce and your gateway. Seatmap is told only that an order was paid, and which seats it
covers.

= How a sale works =

1. The seat picker loads the plan and current availability.
2. The customer selects seats; your store asks Seatmap to hold them for a few minutes.
3. The held seats are added to the cart **at the price Seatmap returned** — a price edited in the
   browser has no effect, because the store never reads prices from the page.
4. Before checkout completes, the holds are re-checked. Expired seats are removed with a clear
   message rather than being sold twice.
5. On `payment_complete`, the seats are allocated and tickets with QR codes are issued.
6. A failed or cancelled order releases the seats. A refund voids the tickets and applies the
   event's seat policy.

Every call carries an idempotency key derived from the order, so a timeout is safe to retry: it
replays the original result instead of selling the seats twice. Orders that could not reach the API
are retried automatically every five minutes.

== Installation ==

1. Copy the `seatmap-connect` folder into `wp-content/plugins/` and activate it.
2. Create a simple, virtual product to act as the seat line. Its price does not matter — it is
   overwritten per seat from the API.
3. Go to **WooCommerce → Seatmap** and enter the API URL, key ID and secret from your Seatmap panel,
   plus the product ID from step 2.
4. Press **Test connection**. This checks the credentials *and* the clock — signed requests are
   rejected if this server's time is more than five minutes out.
5. Add the **Seat map** block, or `[seatmap_event id="evt_..."]`, to any page.

= Previewing without a WordPress install =

`../tools/preview.html` runs this plugin's own seat picker against a live API, standing in only for
the two store routes WordPress normally provides. Serve the `wordpress-plugin` directory over HTTP
and open `tools/preview.html?api=https://api.example&event=evt_xxxxxxxx`. Holding seats there
creates real holds, so point it at a test tenant.

== Frequently Asked Questions ==

= Can a customer change the price in their browser? =

No. The browser only ever sends seat IDs. Your store asks the API which seats those are and what
they cost, and puts that answer in the cart. The price is re-applied on every cart recalculation.

= What happens if the API is unreachable when someone pays? =

The order is flagged and retried every five minutes with the same idempotency key. When it goes
through, the seats are allocated exactly once. The order screen tells staff which state it is in.

= Can two customers buy the same seat? =

No. Seats are held before they reach a cart, and the seating service settles contention in the
database, not in application code.

= Does it work with HPOS and block-based checkout? =

Yes. The plugin declares compatibility with High-Performance Order Storage and cart/checkout blocks,
and reaches orders only through WooCommerce's CRUD API.

== Changelog ==

= 1.0.0 =
* First release: seat picker block and shortcode, cart and order integration, hold lifecycle,
  refunds, retry and reconciliation.
