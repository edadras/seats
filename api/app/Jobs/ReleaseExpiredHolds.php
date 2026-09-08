<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Hold;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping sweep for expired holds.
 *
 * Correctness does not depend on this job: `HoldService` reclaims expired items for exactly the
 * seats it is asked about, inside the creating transaction, so a seat is bookable the moment its
 * TTL passes even if this never runs. What the sweep buys is accurate availability responses
 * (a seat shows as free without someone first trying to book it) and a bounded table.
 */
class ReleaseExpiredHolds implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $batchSize = 500) {}

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->runUnscoped(function () {
            $expired = Hold::query()
                ->where('status', 'active')
                ->where('expires_at', '<=', now())
                ->limit($this->batchSize)
                ->pluck('id', 'event_id');

            if ($expired->isEmpty()) {
                return;
            }

            $ids = $expired->values()->all();

            DB::transaction(function () use ($ids) {
                DB::table('hold_items')
                    ->whereIn('hold_id', $ids)
                    ->whereNull('released_at')
                    ->update(['released_at' => now(), 'updated_at' => now()]);

                DB::table('holds')
                    ->whereIn('id', $ids)
                    ->update(['status' => 'expired', 'released_at' => now(), 'updated_at' => now()]);
            });

            // Move each affected event's cursor so open widgets notice the seats came back.
            DB::table('events')
                ->whereIn('id', $expired->keys()->all())
                ->increment('availability_version');
        });
    }
}
