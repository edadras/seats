<?php

namespace App\Support\Plans;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Subscription;
use App\Models\Venue;
use App\Support\Tenancy\TenantContext;

/**
 * What a plan actually stops you doing.
 *
 * Every key here is checked somewhere. A plan that promises a limit nothing enforces is a plan
 * that gets sold and then argued about, and a limit that exists only in a marketing table is worse
 * than no limit at all — it makes the price list a work of fiction.
 *
 * Three, deliberately. Venues and events are countable before the fact and refusing is harmless.
 * Seats per map is enforced where a map is published. Scans are *not* limited: cutting off a door
 * on a busy night because a counter passed a number would be the platform breaking the one thing
 * a venue cannot recover from.
 */
class PlanLimits
{
    public const KEYS = ['max_venues', 'max_events', 'max_seats_per_map'];

    public function __construct(private readonly TenantContext $tenants) {}

    /** The tenant's current plan limit for a key, or null when there is no limit. */
    public function limit(string $key): ?int
    {
        $subscription = Subscription::with('plan')->latest('created_at')->first();

        return $subscription?->plan?->limit($key);
    }

    public function assertCanAddVenue(): void
    {
        $this->assert('max_venues', Venue::count(), 'venue_limit_reached',
            'Your plan includes :limit venues. Ask us to move you up a plan.');
    }

    public function assertCanAddEvent(): void
    {
        $this->assert('max_events', Event::count(), 'event_limit_reached',
            'Your plan includes :limit events. Ask us to move you up a plan.');
    }

    private function assert(string $key, int $current, string $code, string $message): void
    {
        $limit = $this->limit($key);

        if (null === $limit || $current < $limit) {
            return;
        }

        throw ApiException::conflict($code, str_replace(':limit', (string) $limit, $message), [
            'limit' => $limit,
            'current' => $current,
        ]);
    }
}
