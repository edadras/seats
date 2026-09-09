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
 * The queue for a sold-out night.
 *
 * Every few minutes rather than the instant a refund lands: seats come back in bursts — a party of
 * six cancels, three holds expire while the sweeper runs — and one message per released seat would
 * send the same person six emails in a minute.
 */
Schedule::command('waitlist:notify')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
