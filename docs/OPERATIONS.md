# Running this in production

## Installing it on a server

Seven commands and one question. The question is the seventh.

```bash
# 1. The code, and only what production needs.
composer install --no-dev --optimize-autoloader

# 2. Settings. Copy the example and read it — every key in it has a comment saying what
#    breaks when it is wrong, which is faster than finding out.
cp .env.example .env
php artisan key:generate            # APP_KEY. Back it up separately from the database.
php artisan seatmap:generate-signing-key

# 3. The schema.
php artisan migrate --force

# 4. The door scanner. It is a build artefact, not source, so a fresh checkout has none —
#    and without this step /checkin is a 404 and no volunteer can pair a phone.
../checkin-app/build.sh

# 5. Compile the settings, the routes and the views.
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 6. Point the web server's root at api/public, and run these two for ever:
#      php artisan queue:work --queue=default --tries=3
#      * * * * * cd /path/to/api && php artisan schedule:run >/dev/null 2>&1

# 7. Ask whether any of that is actually true.
php artisan seatmap:preflight
```

**There is no asset build and no `storage:link`.** The panel, the designer and the hosted sites are
plain files under `public/`, and nothing is written to a public disk. An operator who assumes a
JavaScript toolchain is involved will spend an afternoon on a step that does not exist.

### The question at the end

`php artisan seatmap:preflight` reads the installation and reports three kinds of thing: a
**failure**, which means something will not work and which sets a non-zero exit status so a deploy
script can stop; a **warning**, which means a decision has not been made and a default has been
taken; and a **note**, which is a fact worth reading once. `--strict` treats the warnings as
failures, for a deployment that wants every decision made deliberately.

It exists because the failures that matter here are all quiet. The application starts, the panel
loads, a seat can be held — and mail is going to a log file, or every buyer is sharing one rate-limit
bucket, or expired holds are never swept. None of that shows up on a request to the home page.

Run it again after a deploy, not only during one: most of what it reads can drift afterwards.

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

## Behind a proxy

Almost everything this platform limits, it limits **per IP address**: signing in, signing up,
claiming an SSO account, how many seats one person may hold, the bot defence on the picker. Every
audit row records one too.

So `SEATMAP_TRUSTED_PROXIES` is not a detail. Behind nginx, Caddy, a load balancer or a CDN with it
empty, `$request->ip()` is the proxy's address on *every* request — a hundred buyers at an on-sale
share one throttle bucket, so the tenth to try is refused on everyone else's behalf, and the audit
log says the same thing on every line. Set it to the proxy's own address (`127.0.0.1,::1` for nginx
on the same host, or the load balancer's range).

The opposite mistake is as bad and less obvious: setting it to `*` on a server that is *also*
reachable directly lets any client put whatever it likes in `X-Forwarded-For` and walk past those
same limits from one machine. Name the proxy rather than wildcarding it.

`X-Forwarded-Host` is deliberately **not** honoured, from any proxy. This application routes by
Host — a hostname is looked up as a tenant's site — so a forwarded Host a client could set would be
one organiser serving their pages on another's domain. If a deployment genuinely needs it, that is a
change to `bootstrap/app.php` made on purpose, with that consequence understood.

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

**`MAIL_MAILER` has no safe default.** Unset, the framework uses `log`, which writes every message
to `storage/logs` and sends nothing: no tickets, no invitations, no password resets, and no error
anywhere. It is the quietest way this installation can be broken, and `seatmap:preflight` treats it
as a failure rather than a warning for that reason.

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
