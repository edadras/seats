# Running this in production

## What has to be true

| Requirement | Why it is not optional |
| --- | --- |
| PostgreSQL 14+ | Seat exclusivity is enforced by partial unique indexes and `SELECT … FOR UPDATE`. On any other engine the system is not correct, only usually correct. |
| Redis | Backs the HMAC nonce store. If Redis is unavailable the API returns `503 replay_check_unavailable` rather than accepting unprotected requests — replay protection fails closed by design. |
| A queue worker | Hold sweeping, webhook delivery and retries. Sales still work without one; availability goes stale and webhooks stop. |
| The scheduler | `php artisan schedule:run` every minute, for the hold sweep and idempotency pruning. |
| Accurate clock (NTP) | Signed requests outside a ±300 s window are rejected. Clock drift on a tenant's WordPress host is the most common cause of `invalid_signature` reports. |
| TLS everywhere | Every server-to-server request carries a credential. The plugin refuses to store a non-HTTPS API URL. |

```bash
php artisan queue:work --queue=default --tries=3
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

## Secrets

- `APP_KEY` encrypts API client secrets and webhook signing secrets at rest. **Losing it makes
  every connected storefront unable to authenticate**, and there is no recovery beyond reissuing
  every key. Back it up separately from the database — storing it alongside would defeat the point
  of encrypting rather than hashing (ADR: see `THREAT_MODEL.md` T5).
- `SEATMAP_SIGNING_KEY` signs price snapshots. Rotating it invalidates the signature on holds
  already in flight; those holds still complete, because the server trusts its own stored snapshot
  rather than the client's copy. Rotate outside a sales window anyway.

## What to watch

The four that actually indicate customer harm:

1. **Confirms failing.** `order.confirm` returning non-2xx means someone has paid and holds no
   seat. Alert immediately, at any rate above zero.
2. **Holds expiring at an unusual rate**, or a hold-to-confirm ratio that collapses — a checkout
   that has started failing, or inventory-denial abuse (threat T8).
3. **Queue depth and worker liveness.** A stalled worker shows as availability that never frees up.
4. **Webhook deliveries going dead.** A tenant's site has stopped hearing about sales. An endpoint
   we switched off ourselves carries `disabled_reason`, and the organiser sees it on the Connections
   screen — but a whole account's worth going quiet at once is ours, not theirs.

`webhooks:retry` runs every ten minutes. It exists because a retry is a *delayed job*, and a delayed
job lives in the queue rather than in the database: a worker restarted at the wrong second, or a
Redis flushed by hand, and a delivery sits `pending` with its moment in the past for ever. The sweep
is what makes the delivery table rather than the queue the record of what is still owed to somebody
else's server. It also prunes the log (`SEATMAP_WEBHOOK_LOG_DAYS`, 30 by default).

**`SEATMAP_SENDER_DOMAINS`** is the list of domains this installation may put in an email's From
*address* — the ones whose SPF lists this server and whose DKIM key it holds. Adding a domain here
is a statement that the DNS is in place; adding one that is not is how a venue's confirmations start
going to spam folders, and the organiser has no way to tell. Leave it empty and every venue still
gets its own name in the From line and its own address in Reply-To, which costs nothing.

**`SEATMAP_WEBHOOK_VERIFY_DESTINATION` must be `true` in production.** Off, a webhook address is not
resolved and plain `http` is accepted — which suits a development machine and undoes threat T13's
mitigation entirely on a real one.

Also worth graphing: `409 seat_unavailable` (normal in bursts at on-sale, suspicious when constant),
`401 stale_timestamp` grouped by API key (a tenant's clock), and p99 on `POST /holds`.

Health check: `GET /up`. It does not prove the queue is running — check worker heartbeat separately.

## Backups

Nightly `pg_dump` plus WAL archiving. **A backup nobody has restored is a hypothesis**, so restore
into a scratch database monthly and confirm the integrity indexes survived:

```sql
SELECT indexname FROM pg_indexes
WHERE indexname IN (
  'allocations_one_active_per_seat',
  'allocations_unique_per_external_order_seat',
  'hold_items_one_active_per_seat'
);
-- Three rows. Fewer means the restored database can double-sell seats.
```

## Common incidents

**"Invalid signature" from one tenant's site.** Almost always clock drift or a key that was rotated
in the panel but not updated in the plugin. Have them press **Test connection** — it reports the
two causes separately.

**A buyer paid but has no seats.** Look for the order in `external_orders`. If it is `pending`, the
confirm never landed; the plugin's reconciler retries every five minutes, or the tenant can
re-trigger it. If the hold expired first, the seats are genuinely gone and the sale must be
refunded — the system will not quietly sell seats it no longer holds.

**Seats appear stuck as held.** Check the queue worker. Seats are still bookable regardless —
`HoldService` reclaims expired items inside the next hold's own transaction — so this is an
availability-display problem, not lost inventory.

**A tenant reports a double sale.** This should be impossible; treat it as a serious defect.
Check the three indexes exist first (a restore is the usual culprit), then look for two `active`
allocations on one `(event_id, seat_id)` — the index makes that unrepresentable, so if you find it,
the index was missing at the time of writing.

## Service workers on buyers' devices

Every hosted site installs a service worker at `/sw.js` with root scope. Two things follow from
that, and both are worth knowing before a deploy goes wrong.

**The build stamp is what retires an old shell.** `/sw.js` is served with a `SEATMAP_BUILD` line
prepended, hashed from the modification times of the files it precaches (`site.css`, the site's
theme, the picker's CSS and JS, and `/offline`). A deploy that rewrites those files changes the
stamp, the browser sees a byte-different worker, installs it, and the old cache is dropped on
activation. A deploy that somehow preserved their modification times would not — so if a stylesheet
change is not reaching browsers, check that first. `touch`ing the file is a valid fix.

**Ticket pages are cached on purpose, and separately.** The `seatmap-tickets` cache is *not*
versioned with the build: a ticket outlives a stylesheet, and throwing every opened order page away
because the CSS changed would do it in exactly the week it matters. It is emptied when somebody
signs out, and never holds anything under `/checkout`, `/_store/`, `/pay/`, `/account`, `/season/`
or `/queue`.

**Backing out.** `/sw.js` is served `Cache-Control: no-cache`, so a worker is never revalidated from
a stale copy, and browsers re-check it at least daily regardless. To remove the worker from devices
entirely, serve a `/sw.js` whose body is `self.registration.unregister()` and leave it up for a few
days — the routes are ordinary Laravel routes, so that is a one-line change, not a client rollout.

## Data retention

Buyer contact data arrives with orders and appears on tickets. Purge it on a schedule after each
event; `tickets.holder_name` and `external_orders.buyer` are the two places it lives. `audit_logs`
and `webhook_deliveries` grow steadily and should be partitioned or pruned — neither is needed once
the event has passed and any dispute window has closed.
