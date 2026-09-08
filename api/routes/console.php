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
