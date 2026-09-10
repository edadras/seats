<?php

namespace App\Domain\Insights;

use App\Domain\Availability\AvailabilityService;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * How fast a night is selling, and what happens to the people who look at it.
 *
 * Two questions an organiser asks a fortnight before the doors, and until now this platform could
 * answer neither. "Two hundred sold" is not an answer: two hundred out of a thousand people who
 * looked is a pricing problem, two hundred out of two hundred and twelve is a marketing one, and
 * the remedies are opposite. So this reports a rate and a funnel rather than a total.
 *
 * Everything here is computed from rows that already exist — allocations, holds, orders — with one
 * exception: how many people looked, which nothing could reconstruct after the fact and which is
 * counted as it happens (see `record`, and the `event_views` migration for why it is a counter and
 * not a log).
 *
 * Two honesties are worth stating, because a number nobody can explain is worse than no number:
 *
 * - The curve counts seats that are *still* sold. A refund a fortnight later quietly takes a seat
 *   back out of the day it was bought on. That is the right way round for "how full is this night
 *   going to be", which is what the curve is read for.
 * - The projection is arithmetic, not a forecast. It extends the last week's rate in a straight
 *   line, which is exactly wrong for a run that sells out in the final three days — and every
 *   organiser knows that about their own audience. It is offered as "at this rate", never as a
 *   promise, and the screen says so in those words.
 */
class SalesPace
{
    /** How many days of the last week are used to work out the current rate. */
    private const RECENT_DAYS = 7;

    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * Somebody opened this event's page.
     *
     * One statement, raising a counter in place, so two people looking at the same moment cannot
     * lose one of the two to a read-modify-write. It is called from a page render, so it must never
     * be the reason a page fails: a count is worth less than the page it is counting, and the
     * caller is not asked to remember that.
     */
    public function record(Event $event, string $source): void
    {
        try {
            /*
             * Inside its own transaction — a savepoint, where a caller already had one open.
             *
             * Swallowing the exception is not enough on PostgreSQL: a statement that fails inside
             * somebody else's transaction poisons it, and every query after it is refused. The
             * page would then fail *because* of the catch rather than despite it, which is the
             * opposite of what this guard is for.
             */
            DB::transaction(fn () => DB::statement(<<<'SQL'
                INSERT INTO event_views (id, tenant_id, event_id, day, source, views, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON CONFLICT (event_id, day, source)
                DO UPDATE SET views = event_views.views + 1, updated_at = NOW()
            SQL, [
                (string) Str::uuid(),
                $event->tenant_id,
                $event->id,
                $this->today($event)->toDateString(),
                $source,
            ]));
        } catch (\Throwable) {
            // Counted if it can be. Never at the cost of the page somebody came to read.
        }
    }

    /**
     * The whole picture for one night: the curve, the rate, and the funnel.
     *
     * @param  bool  $withMoney  whether the caller may see the takings, which is a permission the
     *                           head count is not — see EventStats for the same separation
     */
    public function forEvent(Event $event, int $days = 30, bool $withMoney = true): array
    {
        $days = max(1, min(180, $days));
        $from = $this->today($event)->subDays($days - 1);

        $curve = $this->curve($event, $from, $withMoney);

        return [
            'currency' => $withMoney ? $event->currency : null,
            'timezone' => $this->zone($event),
            'from' => $from->toDateString(),
            'to' => $this->today($event)->toDateString(),
            'curve' => $curve,
            'pace' => $this->pace($event, $curve),
            'funnel' => $this->funnel($event, $from, $withMoney),
        ];
    }

    /**
     * A dense day-by-day series: no gaps, because a chart with the quiet days missing is a chart
     * that reads as busier than the week was.
     *
     * @return list<array<string, mixed>>
     */
    private function curve(Event $event, Carbon $from, bool $withMoney): array
    {
        $zone = $this->zone($event);

        $sold = collect(DB::select(<<<'SQL'
            SELECT (allocated_at AT TIME ZONE ?)::date AS day,
                   COALESCE(SUM(COALESCE(quantity, 1)), 0) AS places,
                   COALESCE(SUM(amount), 0) AS amount
            FROM allocations
            WHERE event_id = ?
              AND status = 'active'
              AND (allocated_at AT TIME ZONE ?)::date >= ?
            GROUP BY 1
        SQL, [$zone, $event->id, $zone, $from->toDateString()]))->keyBy('day');

        $looked = collect(DB::select(<<<'SQL'
            SELECT day::date AS day, source, views
            FROM event_views
            WHERE event_id = ? AND day >= ?
        SQL, [$event->id, $from->toDateString()]))->groupBy('day');

        // Everything sold before the window opened, so the running total is a running total of the
        // night and not of the fortnight somebody happens to be looking at.
        $before = (int) DB::table('allocations')
            ->where('event_id', $event->id)
            ->where('status', 'active')
            ->whereRaw('(allocated_at AT TIME ZONE ?)::date < ?', [$zone, $from->toDateString()])
            ->selectRaw('COALESCE(SUM(COALESCE(quantity, 1)), 0) as places')
            ->value('places');

        $rows = [];
        $running = $before;
        $day = $from->copy();
        $today = $this->today($event);

        while ($day->lessThanOrEqualTo($today)) {
            $key = $day->toDateString();
            $places = (int) ($sold[$key]->places ?? 0);
            $running += $places;

            $views = $looked->get($key, collect());

            $rows[] = [
                'day' => $key,
                'places' => $places,
                'sold_so_far' => $running,
                'views' => (int) $views->sum('views'),
                'views_by_source' => $views->pluck('views', 'source')->map(fn ($n) => (int) $n)->all(),
            ] + ($withMoney ? ['amount' => (int) ($sold[$key]->amount ?? 0)] : []);

            $day->addDay();
        }

        return $rows;
    }

    /**
     * The rate, and where it points.
     *
     * @param  list<array<string, mixed>>  $curve
     */
    private function pace(Event $event, array $curve): array
    {
        $summary = $this->availability->summaryForEvent($event);
        $capacity = max(0, $summary['seats_total'] - $summary['blocked']);
        $sold = $summary['allocated'];
        $remaining = max(0, $capacity - $sold);

        // The last week of the window, or all of it where the window is shorter than a week.
        $recent = array_slice($curve, -self::RECENT_DAYS);
        $window = max(1, count($recent));
        $lately = array_sum(array_column($recent, 'places'));
        $rate = $lately / $window;

        $today = $this->today($event);
        $doors = $event->starts_at
            ? $event->starts_at->copy()->setTimezone($this->zone($event))->startOfDay()
            : null;
        // Whole days, as an integer: Carbon answers in fractions, and "6.9 days to the doors" is
        // not a sentence anybody says.
        $daysToDoors = $doors ? (int) max(0, floor($today->diffInDays($doors, false))) : null;

        /*
         * "At this rate, on the 14th."
         *
         * Null where the rate is zero — a night selling nothing sells out on no date, and a very
         * large number would only look like an answer — and null where the date falls after the
         * doors, which is the ordinary case and means the honest answer is "it will not".
         */
        $sellOut = null;

        if ($rate > 0 && $remaining > 0) {
            $needs = (int) ceil($remaining / $rate);

            if (null === $daysToDoors || $needs <= $daysToDoors) {
                $sellOut = $today->copy()->addDays($needs)->toDateString();
            }
        }

        return [
            'capacity' => $capacity,
            'sold' => $sold,
            'remaining' => $remaining,
            'sold_share' => $capacity > 0 ? round($sold / $capacity, 4) : 0.0,
            // Places a day, to one decimal: "eleven point four a day" is a sentence somebody can
            // hold in their head, and rounding it to eleven loses a fifth of a slow week.
            'daily' => round($rate, 1),
            'days_counted' => $window,
            'days_to_doors' => $daysToDoors,
            'sells_out_on' => $sellOut,
            // Where the straight line lands on the night itself, never above the house.
            'projected_sold' => null === $daysToDoors
                ? null
                : (int) min($capacity, $sold + (int) round($rate * $daysToDoors)),
            'sold_out' => 0 === $remaining && $capacity > 0,
        ];
    }

    /**
     * Looked → basket → checkout → bought, over the window.
     *
     * Each step is counted where it can be seen, and the steps are deliberately not all the same
     * kind of thing: a look is a page, a basket is a hold, a checkout is an order registered, a
     * purchase is one confirmed. That is the truth of the shape — a buyer who reloads the page
     * three times looked three times — and it is said in the strings rather than smoothed over
     * with a session identifier this platform does not keep.
     */
    private function funnel(Event $event, Carbon $from, bool $withMoney): array
    {
        $zone = $this->zone($event);
        $since = $from->copy()->startOfDay()->setTimezone($zone)->utc();

        $looked = (int) DB::table('event_views')
            ->where('event_id', $event->id)
            ->where('day', '>=', $from->toDateString())
            ->sum('views');

        $baskets = (int) DB::table('holds')
            ->where('event_id', $event->id)
            ->where('created_at', '>=', $since)
            ->count();

        $checkouts = (int) DB::table('external_orders')
            ->where('event_id', $event->id)
            ->where('created_at', '>=', $since)
            ->count();

        $bought = DB::table('external_orders')
            ->where('event_id', $event->id)
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $since)
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(total_amount), 0) as amount')
            ->first();

        $orders = (int) $bought->orders;

        return [
            'looked' => $looked,
            'baskets' => $baskets,
            'checkouts' => $checkouts,
            'bought' => $orders,
            // Rates rather than percentages: one place to decide how a share is written, and it is
            // not here. A step with nothing above it has no rate at all, which is not zero.
            'basket_rate' => $looked > 0 ? round($baskets / $looked, 4) : null,
            'checkout_rate' => $baskets > 0 ? round($checkouts / $baskets, 4) : null,
            'buy_rate' => $checkouts > 0 ? round($orders / $checkouts, 4) : null,
            'overall_rate' => $looked > 0 ? round($orders / $looked, 4) : null,
        ] + ($withMoney ? ['amount' => (int) $bought->amount] : []);
    }

    private function zone(Event $event): string
    {
        return $event->timezone ?: config('app.timezone', 'UTC');
    }

    /** Today in the night's own timezone: an organiser's Tuesday, not one that ends at midnight UTC. */
    private function today(Event $event): Carbon
    {
        return Carbon::now($this->zone($event))->startOfDay();
    }
}
