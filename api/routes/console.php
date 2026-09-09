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
