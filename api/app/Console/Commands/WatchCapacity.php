<?php

namespace App\Console\Commands;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Notifications\Notifier;
use App\Models\Event;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Notice when an event sells out.
 *
 * Hourly rather than on every sale, and that is deliberate: the honest answer to "is this sold
 * out" is App\Domain\Availability\AvailabilityService, which walks every seat in the house, and
 * walking a twenty-thousand-seat hall on each confirmed order would make selling slower for
 * everyone to deliver a notice an hour earlier. A second, cheaper implementation of what "sold
 * out" means is the alternative, and two implementations is how a hall is declared full while
 * seats are still on sale.
 *
 * Raised once per event, ever, because it is news exactly once.
 */
class WatchCapacity extends Command
{
    protected $signature = 'events:watch-capacity';

    protected $description = 'Tell organisers when an upcoming event has sold out';

    public function handle(TenantContext $tenants, AvailabilityService $availability, Notifier $notifier): int
    {
        $found = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use ($availability, $notifier, &$found) {
                $events = Event::where('status', 'published')
                    ->where('starts_at', '>', now())
                    ->whereNotNull('seat_map_version_id')
                    ->limit(200)
                    ->get();

                foreach ($events as $event) {
                    $summary = $availability->summaryForEvent($event);

                    if ($summary['seats_total'] < 1 || $summary['available'] > 0) {
                        continue;
                    }

                    // A year, not an hour: "sold out" is news once, and a seat released and resold
                    // must not make it news again every hour until the doors open.
                    $raised = $notifier->raiseOnce(
                        'event.sold_out',
                        'event:'.$event->id,
                        ['event' => $event->name, 'seats' => $summary['allocated']],
                        seconds: 31536000,
                    );

                    $found += $raised ? 1 : 0;
                }
            });
        }

        $this->info($found ? "{$found} event(s) have sold out." : 'Nothing has just sold out.');

        return self::SUCCESS;
    }
}
