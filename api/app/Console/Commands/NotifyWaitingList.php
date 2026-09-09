<?php

namespace App\Console\Commands;

use App\Domain\Waitlist\WaitingList;
use App\Models\Event;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\WaitingListEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Tell the next people in the queue that a seat has come back.
 *
 * On a schedule rather than the moment a refund lands, and deliberately. Seats return in bursts —
 * a party of six cancels, a hold expires while the sweeper is running — and writing to somebody
 * per released seat would send one person six emails in a minute. A few minutes' delay costs a
 * waiting buyer nothing and makes each message mean something.
 *
 * Only events that somebody is actually waiting for are examined, so an account with no waiting
 * lists does no work at all.
 */
class NotifyWaitingList extends Command
{
    protected $signature = 'waitlist:notify';

    protected $description = 'Tell people waiting for a sold-out event that places have come back';

    public function handle(TenantContext $tenants, WaitingList $list): int
    {
        $told = 0;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $tenants->runAs($tenant, function () use ($list, &$told) {
                $site = Site::where('status', 'live')->orderBy('created_at')->first();

                if (! $site) {
                    // Nowhere to send anybody: the link in the message is a page on a live site,
                    // and a message with nowhere to go is worse than no message.
                    return;
                }

                $eventIds = WaitingListEntry::where('status', 'waiting')
                    ->distinct()
                    ->pluck('event_id');

                $events = Event::with('venue')
                    ->whereIn('id', $eventIds)
                    ->whereIn('status', ['published'])
                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '>', now()))
                    ->get();

                foreach ($events as $event) {
                    $told += $list->notify($event, $site);
                }
            });
        }

        $this->info($told ? "Told {$told} people a seat came free." : 'Nobody to tell.');

        return self::SUCCESS;
    }
}
