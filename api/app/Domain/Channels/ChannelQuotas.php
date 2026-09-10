<?php

namespace App\Domain\Channels;

use App\Exceptions\ApiException;
use App\Models\ApiClient;
use App\Models\ChannelQuota;
use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * "This many, and no more" — per night, per channel.
 *
 * The other half of holding a house back. House seats say *which* places are kept; a quota says
 * *how many* a channel may take, and lets the organiser leave the choosing to the buyer. An agent
 * gets four hundred, the website gets the rest, and neither can take the other's.
 *
 * What a channel has taken is never stored. It is counted — live holds plus live allocations — and
 * counted inside the same advisory lock on the event that the seats themselves are sold under,
 * because a quota kept in a column is a quota two simultaneous baskets both fit inside. That is the
 * same rule as a seat's availability, an add-on's stock and a voucher's balance, and it is the same
 * rule for the same reason.
 *
 * A channel with no row has no limit. That is not an oversight: almost every event sells through
 * one channel and putting a number on it would be inventing a promise nobody made.
 */
class ChannelQuotas
{
    /**
     * What this channel is allowed, or null where it is allowed everything.
     */
    public function limitFor(Event $event, ?string $apiClientId): ?int
    {
        if (! $apiClientId) {
            return null;
        }

        $quota = ChannelQuota::where('event_id', $event->id)
            ->where('api_client_id', $apiClientId)
            ->first();

        return $quota ? (int) $quota->places : null;
    }

    /**
     * How many places this channel is holding or has sold on this night.
     *
     * Live holds and live allocations, in places rather than rows: a standing area sold four at a
     * time is four places, and a quota that counted it as one would let an agent sell a stadium.
     *
     * A cancelled or refunded booking gives its places back, because the channel did not sell
     * them in the end — the agent who returns two hundred unsold seats gets their allowance back
     * with them.
     */
    public function taken(Event $event, string $apiClientId): int
    {
        $held = (int) DB::table('hold_items as i')
            ->join('holds as h', 'h.id', '=', 'i.hold_id')
            ->where('i.event_id', $event->id)
            ->where('h.api_client_id', $apiClientId)
            ->whereNull('i.released_at')
            ->where('h.status', 'active')
            ->where('h.expires_at', '>', now())
            ->selectRaw('COALESCE(SUM(COALESCE(i.quantity, 1)), 0) as places')
            ->value('places');

        $sold = (int) DB::table('allocations')
            ->where('event_id', $event->id)
            ->where('api_client_id', $apiClientId)
            ->where('status', 'active')
            ->selectRaw('COALESCE(SUM(COALESCE(quantity, 1)), 0) as places')
            ->value('places');

        return $held + $sold;
    }

    /**
     * Refuse a basket that would take a channel past its allowance.
     *
     * Called from inside the hold's own transaction, after the advisory lock on the event has been
     * taken — so the count it reads is the count that will still be true when the hold is written.
     * Called before it, this would be a suggestion.
     *
     * @throws ApiException naming what is left, because "no" without a number is a support email
     */
    public function assertWithin(Event $event, ?string $apiClientId, int $wanted): void
    {
        $limit = $this->limitFor($event, $apiClientId);

        if (null === $limit || $wanted < 1) {
            return;
        }

        $left = max(0, $limit - $this->taken($event, (string) $apiClientId));

        if ($wanted > $left) {
            /*
             * Two sentences, one code.
             *
             * "Nothing left" and "four left" are different things to be told: the first ends the
             * conversation and the second is an invitation to take four. A caller branching on the
             * code sees one refusal; a person reading it gets the one that helps.
             */
            $key = 0 === $left ? 'errors.channel_quota_gone' : 'errors.channel_quota_reached';

            throw new ApiException(
                'channel_quota_reached',
                0 === $left
                    ? 'This sales channel has sold its whole allocation for this event.'
                    : sprintf('This sales channel has %d places left for this event.', $left),
                409,
                ['limit' => $limit, 'left' => $left, 'wanted' => $wanted],
                $key,
                ['count' => $left],
            );
        }
    }

    /**
     * Every channel's promise and progress on one night, for the screen that sets them.
     *
     * Channels with no quota are listed too, with a null limit: an organiser deciding whether to
     * promise an agent four hundred needs to see what the website is already doing.
     *
     * @return list<array<string, mixed>>
     */
    public function forEvent(Event $event): array
    {
        $quotas = ChannelQuota::where('event_id', $event->id)->get()->keyBy('api_client_id');

        return ApiClient::orderBy('name')->get()->map(function (ApiClient $client) use ($event, $quotas) {
            $quota = $quotas->get($client->id);
            $taken = $this->taken($event, $client->id);
            $limit = $quota ? (int) $quota->places : null;

            return [
                'api_client_id' => $client->id,
                'name' => $client->name,
                'kind' => $client->kind,
                'places' => $limit,
                'note' => $quota?->note,
                // Counted on every read. There is no column to take this off.
                'taken' => $taken,
                'left' => null === $limit ? null : max(0, $limit - $taken),
            ];
        })->values()->all();
    }
}
