<?php

use App\Jobs\ReleaseExpiredHolds;
use Illuminate\Support\Facades\Schedule;

// Frequent, cheap, and bounded. Seats are already reclaimed on demand by HoldService; this keeps
// availability responses honest for browsers that are only watching.
Schedule::job(new ReleaseExpiredHolds)->everyMinute()->withoutOverlapping();

// Idempotency records are only useful for as long as a client might retry.
Schedule::call(function () {
    \App\Models\IdempotencyKey::where('expires_at', '<', now())->delete();
})->hourly()->name('prune-idempotency-keys');

/*
 * A buyer who pays and closes the tab never comes back through the return URL. Their seats are
 * protected by the hold either way, but their money has moved and their order has not — and
 * finding that out from a support email is not good enough.
 *
 * `settle()` is idempotent by contract, which is what makes asking again safe.
 */
Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping()->runInBackground();

/*
 * Messages that could not be sent, and the reminder the night before.
 *
 * Retries are only for a provider that could not be reached — a refusal stays refused, because
 * asking a provider that already said no is how a platform gets rate-limited for nothing.
 */
Schedule::command('messages:retry')->everyFifteenMinutes()->withoutOverlapping()->runInBackground();

/*
 * The webhook deliveries the queue lost.
 *
 * Not a substitute for the delayed jobs, which do the ordinary retrying: this is the insurance that
 * makes the delivery table rather than the queue the record of what is still owed to somebody
 * else's server. It also prunes the log, which is the only thing that stops it growing for ever.
 */
Schedule::command('webhooks:retry')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('messages:remind')->hourly()->withoutOverlapping()->runInBackground();

/*
 * Announcements too long to finish in the request that sent them. The first batch goes out inline,
 * so most are already done by the time this runs; this is what carries a message to four thousand
 * people the rest of the way.
 */
Schedule::command('messages:announce')->everyMinute()->withoutOverlapping()->runInBackground();

/*
 * "Sold out" is worth knowing and expensive to work out, so it is asked hourly rather than on every
 * sale — see the command for why that trade is the right way round.
 */
Schedule::command('events:watch-capacity')->hourly()->withoutOverlapping()->runInBackground();

/*
 * Reports that arrive rather than waiting to be opened.
 *
 * Hourly, which is as fine as a schedule goes: each one knows the timezone it was made in, so
 * "eight in the morning" means eight where the venue is. A report that could fire every ten minutes
 * would not be a report, it would be an alert.
 */
Schedule::command('reports:send')->hourly()->withoutOverlapping()->runInBackground();

/*
 * The platform's own bill.
 *
 * Daily, because an account's period ends on the day it signed up: on any given day a few are due
 * and most are not. Monthly would invoice everybody on the first for periods that ended on the
 * eleventh. Early, so a failed card has the whole working day to be noticed and fixed.
 */
Schedule::command('billing:run')->dailyAt('06:15')->withoutOverlapping()->runInBackground();

/*
 * Accounts that have been closed for long enough, and archives nobody fetched.
 *
 * Once a day and no oftener. The window between closing an account and erasing it is measured in
 * days, so a command that ran hourly would spend twenty-three of every twenty-four asking a
 * question whose answer cannot have changed — and the one time it matters, erasing a few hours
 * late is nothing, while erasing a few hours early is somebody's season gone.
 */
Schedule::command('accounts:erase')->dailyAt('03:40')->withoutOverlapping()->runInBackground();

/*
 * Points that have gone quiet for as long as their scheme allows.
 *
 * Daily, and at an hour when nothing else is running: it reads every account's ledger, and the one
 * thing worse than points expiring is points expiring while somebody is at the checkout spending
 * them. Accounts whose scheme says points never expire are skipped without being read at all.
 */
Schedule::command('loyalty:expire')->dailyAt('04:20')->withoutOverlapping()->runInBackground();

/*
 * The queue for a sold-out night.
 *
 * Every few minutes rather than the instant a refund lands: seats come back in bursts — a party of
 * six cancels, three holds expire while the sweeper runs — and one message per released seat would
 * send the same person six emails in a minute.
 */
Schedule::command('waitlist:notify')->everyFiveMinutes()->withoutOverlapping()->runInBackground();

/*
 * Buyers whose payment never finished, written to once.
 *
 * Hourly, and never sooner: this runs behind `payments:reconcile`, which has by then asked the
 * gateway several times. Telling somebody their booking is unfinished when their card has already
 * been charged is the worst message this platform could send, and the delay is what prevents it.
 */
Schedule::command('baskets:recover')->hourly()->withoutOverlapping()->runInBackground();
