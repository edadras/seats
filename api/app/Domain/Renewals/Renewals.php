<?php

namespace App\Domain\Renewals;

use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Hold;
use App\Models\RenewalOffer;
use App\Models\RenewalOfferSeat;
use App\Models\RenewalRound;
use App\Models\SeasonPass;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Your seats are yours again, until the fourteenth of June."
 *
 * A subscription house sells next season to this season's subscribers before it sells it to
 * anybody else, in the same chairs. That is not a courtesy: it is where most of the money comes
 * from, and doing it by hand — blocking seats on a chart and keeping a list of who they belong to
 * in a spreadsheet — is how a theatre ends up selling somebody's seat of eleven years to a
 * stranger.
 *
 * **An offer is not a booking.** Nothing is charged and no allocation exists until a subscriber
 * accepts and pays like everybody else. While the offer stands, its only effect is that those
 * chairs are not available to anyone else — and that effect is *derived*: availability and the
 * hold service read this table, so the moment the deadline passes the seats are on sale again with
 * nothing swept and nothing forgotten.
 *
 * **There is one arithmetic, and it is the season checkout's.** This class never prices anything.
 * Accepting makes one ordinary hold on the first night with the subscriber's seats in it, puts the
 * pass and the nights in the session, and hands them to the checkout that already sells seasons. A
 * second place that worked out what a season costs would be a second place to get it wrong.
 *
 * **The link needs no sign-in.** A subscriber is somebody who bought tickets, not somebody with an
 * account, and a renewal that requires remembering a password is a renewal that lapses.
 */
class Renewals
{
    public function __construct(
        private readonly HoldService $holds,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Open a round and work out who is in it.
     *
     * @return array{round: RenewalRound, offered: int, seats: int, skipped: array<string, int>}
     */
    public function open(
        EventSeries $from,
        EventSeries $to,
        SeasonPass $pass,
        Carbon $deadline,
        string $name,
        ?string $by = null,
    ): array {
        if ($from->id === $to->id) {
            throw ApiException::unprocessable(
                'renewal_same_run',
                'A run cannot be renewed into itself.',
            );
        }

        if ($pass->series_id !== $to->id) {
            throw ApiException::unprocessable(
                'renewal_pass_elsewhere',
                'That season ticket belongs to a different run.',
            );
        }

        if ($deadline->isPast()) {
            throw ApiException::unprocessable(
                'renewal_deadline_past',
                'A renewal has to close in the future to be an offer at all.',
            );
        }

        if (RenewalRound::where('to_series_id', $to->id)->where('state', 'open')->exists()) {
            throw ApiException::conflict(
                'renewal_round_open',
                'That run already has a renewal open. Close it before opening another.',
            );
        }

        return DB::transaction(function () use ($from, $to, $pass, $deadline, $name, $by) {
            $round = RenewalRound::create([
                'tenant_id' => $this->tenants->id(),
                'from_series_id' => $from->id,
                'to_series_id' => $to->id,
                'season_pass_id' => $pass->id,
                'name' => $name,
                'deadline' => $deadline,
                'state' => 'open',
                'created_by' => $by,
            ]);

            return array_merge(['round' => $round], $this->build($round));
        });
    }

    /**
     * Who sat where last season, offered the same chairs in the new one.
     *
     * Every reason a seat cannot be carried over is counted and named rather than swallowed: an
     * organiser who is told "412 offered" and not told that 38 chairs no longer exist in the new
     * plan will find out from the subscribers whose seats vanished.
     *
     * @return array{offered: int, seats: int, skipped: array<string, int>}
     */
    public function build(RenewalRound $round): array
    {
        $lastSeason = Event::where('series_id', $round->from_series_id)->pluck('id');
        $sellable = $this->seatsSellableIn($round->to_series_id);

        $skipped = ['no_such_seat' => 0, 'already_claimed' => 0, 'no_email' => 0];
        $claimed = [];
        $offers = 0;
        $seats = 0;

        // Ordered so that the earliest booking wins a chair two people somehow both sat in — a
        // rebooked seat after a refund, most often. Somebody has to win, and "whoever bought it
        // first" is the only answer that does not depend on the order rows come back in.
        $rows = Allocation::query()
            ->whereIn('event_id', $lastSeason)
            ->where('status', 'active')
            ->whereNotNull('seat_id')
            ->orderBy('allocated_at')
            ->with('order')
            ->get();

        $people = [];

        foreach ($rows as $allocation) {
            $email = mb_strtolower(trim((string) ($allocation->order?->buyer['email'] ?? '')));

            if ('' === $email) {
                $skipped['no_email']++;

                continue;
            }

            $people[$email] ??= [
                'name' => $allocation->order?->buyer['name'] ?? null,
                'seats' => [],
            ];

            $seatId = (string) $allocation->seat_id;

            if (! isset($sellable[$seatId])) {
                // The chair is not in the new run's plan: a hall relaid, a section taken out, or a
                // series whose events use a different map altogether.
                $skipped['no_such_seat']++;

                continue;
            }

            if (isset($claimed[$seatId])) {
                $skipped['already_claimed']++;

                continue;
            }

            $claimed[$seatId] = true;
            $people[$email]['seats'][$seatId] = true;
        }

        foreach ($people as $email => $person) {
            if ([] === $person['seats']) {
                continue;
            }

            $offer = RenewalOffer::firstOrCreate(
                ['round_id' => $round->id, 'email' => $email],
                [
                    'tenant_id' => $round->tenant_id,
                    'name' => $person['name'],
                    'state' => 'offered',
                ],
            );

            foreach (array_keys($person['seats']) as $seatId) {
                RenewalOfferSeat::firstOrCreate(
                    ['offer_id' => $offer->id, 'seat_id' => $seatId],
                    ['tenant_id' => $round->tenant_id, 'round_id' => $round->id],
                );
                $seats++;
            }

            $offers++;
        }

        return ['offered' => $offers, 'seats' => $seats, 'skipped' => $skipped];
    }

    /**
     * Take them: hold the seats and hand the subscriber to the season checkout.
     *
     * The state change and the hold are one transaction on purpose. Marking the offer accepted
     * stops this round holding those chairs, which is what lets the hold below succeed — and if
     * the hold fails for any reason, the rollback puts the offer back exactly as it was rather
     * than leaving a subscriber with neither an offer nor a basket. Another buyer's hold attempt
     * cannot slip in between: the accepted state is invisible to them until this commits.
     *
     * @return array{hold: Hold, nights: list<string>}
     */
    public function accept(RenewalOffer $offer, string $sessionId, ?string $ip = null, ?string $channelId = null): array
    {
        // Loaded here rather than assumed: lazy loading is off across the application, so a
        // relation the caller did not ask for is null rather than a query, and a null round here
        // would read as "closed" and refuse a renewal that was perfectly open.
        $offer->loadMissing(['round', 'seats']);

        $round = $offer->round;

        if (! $round || ! $round->isLive()) {
            throw ApiException::conflict('renewal_closed', 'That renewal has closed.');
        }

        if (! $offer->isOpen()) {
            throw ApiException::conflict('renewal_answered', 'That renewal has already been answered.');
        }

        $nights = $this->nights($round);

        if ($nights->isEmpty()) {
            throw ApiException::conflict('renewal_no_nights', 'That run has no nights on sale yet.');
        }

        $seatIds = $offer->seats->pluck('seat_id')->all();

        if ([] === $seatIds) {
            throw ApiException::conflict('renewal_no_seats', 'That renewal has no seats on it.');
        }

        return DB::transaction(function () use ($offer, $nights, $seatIds, $sessionId, $ip, $channelId) {
            $offer->forceFill(['state' => 'accepted', 'responded_at' => now()])->save();

            $hold = $this->holds->create($nights->first(), $seatIds, $sessionId, $channelId, $ip);

            return ['hold' => $hold, 'nights' => $nights->pluck('id')->all()];
        });
    }

    /** No thank you: the chairs go on general sale now rather than at the deadline. */
    public function decline(RenewalOffer $offer): RenewalOffer
    {
        if (! $offer->isOpen()) {
            throw ApiException::conflict('renewal_answered', 'That renewal has already been answered.');
        }

        $offer->forceFill(['state' => 'declined', 'responded_at' => now()])->save();
        $offer->loadMissing('round');
        $this->bumpNights($offer->round);

        return $offer;
    }

    /**
     * Close the round: everybody who did not answer has lapsed.
     *
     * Written down rather than left implied, because "did not answer" is a thing the box office
     * will be asked about in October. The seats were already back on sale at the deadline whether
     * or not anybody pressed this — see the class note.
     */
    public function close(RenewalRound $round): RenewalRound
    {
        if ('open' !== $round->state) {
            throw ApiException::conflict('renewal_round_closed', 'That renewal is already closed.');
        }

        DB::transaction(function () use ($round) {
            RenewalOffer::where('round_id', $round->id)
                ->where('state', 'offered')
                ->update(['state' => 'lapsed', 'updated_at' => now()]);

            $round->forceFill(['state' => 'closed', 'closed_at' => now()])->save();
        });

        $this->bumpNights($round);

        return $round;
    }

    /**
     * What the box office sees: every subscriber, their chairs, and whether they have answered.
     *
     * @return array<string, mixed>
     */
    public function forRound(RenewalRound $round): array
    {
        $offers = RenewalOffer::with(['seats.seat.row', 'seats.seat.section'])
            ->where('round_id', $round->id)
            ->orderBy('email')
            ->get();

        $counts = ['offered' => 0, 'accepted' => 0, 'declined' => 0, 'lapsed' => 0];

        foreach ($offers as $offer) {
            $counts[$offer->state] = ($counts[$offer->state] ?? 0) + 1;
        }

        return [
            'id' => $round->id,
            'name' => $round->name,
            'state' => $round->state,
            'deadline' => $round->deadline?->toIso8601String(),
            'live' => $round->isLive(),
            'counts' => $counts,
            'data' => $offers->map(fn (RenewalOffer $offer) => [
                'id' => $offer->id,
                'email' => $offer->email,
                'name' => $offer->name,
                'state' => $offer->state,
                'invited_at' => $offer->invited_at?->toIso8601String(),
                'responded_at' => $offer->responded_at?->toIso8601String(),
                'seats' => $offer->seats->map(fn (RenewalOfferSeat $row) => trim(
                    ($row->seat?->section?->name ?? '').' '.
                    ($row->seat?->row?->name ?? '').' '.
                    ($row->seat?->label ?? '')
                ))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * The nights of the run being offered, in order.
     *
     * @return \Illuminate\Support\Collection<int, Event>
     */
    public function nights(RenewalRound $round): \Illuminate\Support\Collection
    {
        return Event::where('series_id', $round->to_series_id)
            ->whereNull('deleted_at')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Event $event) => $event->isSellable())
            ->values();
    }

    /** The link a subscriber follows. No sign-in: see the class note. */
    public function linkFor(RenewalOffer $offer, string $base = ''): string
    {
        return rtrim($base, '/').'/renewals/'.$offer->id.'/'.$this->tokenFor($offer);
    }

    /**
     * Half of a SHA-256, which is 128 bits of signature.
     *
     * Shortened for the same reason the unsubscribe token is: this is read out of an email, and a
     * sixty-four character tail wraps in every mail client there is. It is bound to the offer's own
     * id and to the address it was written to, so a token cannot be moved to somebody else's offer.
     */
    public function tokenFor(RenewalOffer $offer): string
    {
        return substr(hash_hmac(
            'sha256',
            $offer->id.'|'.mb_strtolower(trim((string) $offer->email)),
            (string) config('app.key'),
        ), 0, 32);
    }

    /** Constant-time, because a check that leaks its answer by timing leaks the token. */
    public function tokenIsGood(RenewalOffer $offer, string $token): bool
    {
        return hash_equals($this->tokenFor($offer), $token);
    }

    /**
     * The seats the new run can actually sell, by id.
     *
     * @return array<string, true>
     */
    private function seatsSellableIn(string $seriesId): array
    {
        $versions = Event::where('series_id', $seriesId)
            ->whereNotNull('seat_map_version_id')
            ->pluck('seat_map_version_id')
            ->unique()
            ->all();

        if ([] === $versions) {
            return [];
        }

        $ids = DB::table('seat_placements')
            ->whereIn('seat_map_version_id', $versions)
            ->distinct()
            ->pluck('seat_id');

        $sellable = [];

        foreach ($ids as $id) {
            $sellable[(string) $id] = true;
        }

        return $sellable;
    }

    /**
     * Tell every night of the run that its map has changed.
     *
     * A picker polling "what changed since N" has to hear about seats coming back, and a renewal
     * that ends is seats coming back on every night at once.
     */
    private function bumpNights(?RenewalRound $round): void
    {
        if (! $round) {
            return;
        }

        foreach (Event::where('series_id', $round->to_series_id)->get() as $night) {
            $night->bumpAvailabilityVersion();
        }
    }
}
