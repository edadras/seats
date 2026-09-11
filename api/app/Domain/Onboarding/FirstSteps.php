<?php

namespace App\Domain\Onboarding;

use App\Domain\Rehearsals\Live;
use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventCapacityOverride;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\ExternalOrder;
use App\Models\SeatMapVersion;
use App\Models\Site;
use App\Models\TicketType;
use App\Models\Venue;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleSettings;
use App\Support\Tenancy\TenantContext;

/**
 * The eight things that stand between a new account and its first real sale.
 *
 * An organiser who signs up is handed a panel with thirty-six screens and no indication which of
 * them matters today. Everything they need is there and none of it is in an order, which is the
 * particular way a complete product still fails somebody on their first afternoon: not by missing
 * a feature, but by never saying what to do next.
 *
 * So this reads the account and answers the only question that is actually urgent — what is done,
 * and what is the next thing. Three properties are worth being deliberate about:
 *
 *   **Every step is a fact, not a stored flag.** Nothing here is ticked by visiting a screen; each
 *   step asks the database whether the thing exists. A checklist with its own state drifts from
 *   the account it describes, and then it is worse than no checklist, because it is confidently
 *   wrong. Delete the venue and the first step un-ticks itself.
 *
 *   **The order is the order of dependence.** A night cannot be priced before it exists and cannot
 *   be sold before it is priced, so the list is not a set of suggestions — it is the actual path,
 *   and `next()` is simply the first thing not done.
 *
 *   **It ends at a rehearsal, and then it leaves.** The last two steps are "walk your own
 *   checkout" and "put it on sale", in that order, because the point of {@see \App\Domain\Rehearsals\Rehearsals}
 *   is that nobody's first genuine customer should be the test. And once the account has taken a
 *   real booking, {@see settled()} is true and the whole thing disappears: an established venue
 *   being shown a beginners' checklist is being told the product does not know who it is talking to.
 */
class FirstSteps
{
    /** In order of dependence. The panel renders exactly this, so the order lives in one place. */
    public const STEPS = ['venue', 'plan', 'night', 'prices', 'payment', 'shopfront', 'rehearsal', 'onsale'];

    /** Which screen finishes each step. A checklist that cannot be clicked is a list of homework. */
    private const VIEWS = [
        'venue' => 'venues',
        'plan' => 'maps',
        'night' => 'events',
        'prices' => 'events',
        'payment' => 'modules',
        'shopfront' => 'sites',
        'rehearsal' => 'rehearsal',
        'onsale' => 'events',
    ];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ModuleSettings $settings,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @return array{
     *     done:int, total:int, complete:bool, settled:bool, next:?string,
     *     steps:list<array{key:string, done:bool, view:string}>
     * }
     */
    public function all(): array
    {
        $done = [
            'venue' => $this->hasVenue(),
            'plan' => $this->hasPublishedPlan(),
            'night' => $this->hasNight(),
            'prices' => $this->hasPrices(),
            'payment' => $this->hasWayToBePaid(),
            'shopfront' => $this->hasShopfront(),
            'rehearsal' => $this->hasRehearsed(),
            'onsale' => $this->hasSomethingOnSale(),
        ];

        $steps = array_map(fn (string $key) => [
            'key' => $key,
            'done' => $done[$key],
            'view' => self::VIEWS[$key],
        ], self::STEPS);

        $outstanding = array_values(array_filter(self::STEPS, fn (string $key) => ! $done[$key]));

        return [
            'done' => count(self::STEPS) - count($outstanding),
            'total' => count(self::STEPS),
            'complete' => [] === $outstanding,
            'settled' => $this->settled(),
            // The one thing to do now. Named separately rather than left to be worked out by each
            // reader, because "the first undone step" is a rule that only has to be right once.
            'next' => $outstanding[0] ?? null,
            'steps' => $steps,
        ];
    }

    /**
     * Whether this account is past needing any of this.
     *
     * A single confirmed booking on a night that is not being rehearsed. Not a count and not a
     * threshold: the question is whether the thing has ever worked for real, and once it has, the
     * answer never goes back to no.
     */
    public function settled(): bool
    {
        $query = ExternalOrder::query()->where('status', 'confirmed');

        Live::only($query, 'external_orders.event_id');

        return $query->exists();
    }

    /* ------------------------------------------------------------------------- each step */

    private function hasVenue(): bool
    {
        return Venue::query()->exists();
    }

    /**
     * A *published* version, not a drawing in progress. An event cannot be sold against a draft,
     * so a draft is not this step being done — it is this step being started.
     */
    private function hasPublishedPlan(): bool
    {
        return SeatMapVersion::query()->where('status', 'published')->exists();
    }

    private function hasNight(): bool
    {
        return Event::query()->exists();
    }

    /**
     * A price somebody could actually be charged.
     *
     * All four of the places a price can be written, because a night priced any one of those ways
     * is a night that is priced: a zone, a seat given its own amount for one night, a standing area
     * or table given one, or a flat-rate ticket type. Checking only zones would tell a venue that
     * sells one general-admission room that it has not set a price, which is both wrong and exactly
     * the sort of thing that teaches people to ignore a checklist.
     *
     * Zero is excluded on purpose everywhere: a row created and left at nothing is the
     * half-finished state this step exists to catch.
     */
    private function hasPrices(): bool
    {
        return EventPriceZone::query()->where('amount', '>', 0)->exists()
            || EventSeatOverride::query()->where('amount', '>', 0)->exists()
            || EventCapacityOverride::query()->where('amount', '>', 0)->exists()
            || TicketType::query()->where('kind', 'fixed')->where('value', '>', 0)->exists();
    }

    /**
     * A way to be paid that somebody actually chose.
     *
     * The subtle one. "Pay at the box office" is auto-enabled for every new account so that a
     * brand-new tenant can take a booking without first being sent to a settings screen — which
     * means it is on before the organiser has decided anything, and ticking this step off its mere
     * presence would be the checklist lying on day zero.
     *
     * So an auto-enabled gateway counts only once it has been configured: the box-office module is
     * done when its instructions are written, because writing "transfer to this account and bring
     * the reference" is the decision. Any other payment module being on at all is the decision,
     * since somebody had to go and switch it on.
     */
    private function hasWayToBePaid(): bool
    {
        $tenantId = $this->tenants->id();

        foreach ($this->modules->installed() as $key => $manifest) {
            if (! in_array('payments', $manifest->extends, true) || ! $this->modules->isEnabled($key)) {
                continue;
            }

            if (! $manifest->autoEnable) {
                return true;
            }

            foreach ($this->settings->resolve($manifest, $tenantId) as $value) {
                if (is_string($value) ? '' !== trim($value) : (null !== $value && false !== $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Somewhere for a buyer to arrive.
     *
     * Two shapes, both of them real: a hosted site that is live, or somebody's own WordPress that
     * has signed a request. The second is checked by `last_seen_at` rather than by the key
     * existing, because a key that was generated and pasted nowhere is not a shopfront.
     */
    private function hasShopfront(): bool
    {
        return Site::query()->where('status', 'live')->exists()
            || ApiClient::query()
                ->where('kind', 'external')
                ->where('status', 'active')
                ->whereNotNull('last_seen_at')
                ->exists();
    }

    /**
     * Whether a night has ever been rehearsed.
     *
     * Read from the audit log rather than from the events table, and that is the whole point: a
     * rehearsal ends by being swept away, so by the time it has been done properly there is
     * nothing left to look at. The log is what survives it.
     */
    private function hasRehearsed(): bool
    {
        return AuditLog::query()->where('action', 'rehearsal.started')->exists();
    }

    /** Published, drawn against a real plan, and not yet over. The same night the overview counts. */
    private function hasSomethingOnSale(): bool
    {
        return Event::query()
            ->where('status', 'published')
            ->where('is_rehearsal', false)
            ->whereNotNull('seat_map_version_id')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->exists();
    }
}
