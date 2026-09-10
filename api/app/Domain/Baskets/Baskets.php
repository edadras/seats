<?php

namespace App\Domain\Baskets;

use App\Domain\Inventory\Basket;
use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Models\BasketRecovery;
use App\Models\ExternalOrder;
use App\Models\Hold;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Buyers who got as far as their own name, and what can honestly be done about it.
 *
 * The line this feature refuses to cross is worth stating before the code: nothing here is written
 * for somebody who merely typed into an email box. A recovery exists only for a buyer who
 * *submitted the checkout* — who gave their address in order to buy tickets and whose payment then
 * never came back. That is a service message about their own unfinished purchase. Writing to
 * everyone who ever opened a checkout would be a different feature with a different name.
 *
 * The second honest thing is about the seats. They are gone. A hold lasts minutes and this runs an
 * hour later, so `resume` does not restore a basket — it reads what was in one and tries to take
 * the same seats again, and refuses plainly when it cannot. A link that quietly seated somebody
 * somewhere else would be worse than a link that fails.
 */
class Baskets
{
    /** A purchase that has not moved in this long is not about to move on its own. */
    public const AFTER_MINUTES = 60;

    /** And one older than this is a memory, not a sale to rescue. */
    public const GIVE_UP_HOURS = 72;

    public function __construct(private readonly HoldService $holds) {}

    /**
     * Unfinished purchases worth writing about, for one account.
     *
     * A pending hosted order, old enough that the gateway is plainly not coming back, young enough
     * to be worth a message, with an address to write to and an event that has not happened yet.
     * Orders that already have a recovery are excluded by the join rather than filtered afterwards,
     * because "already written to" is the condition that matters most and the one a later `LIMIT`
     * would otherwise silently break.
     *
     * @return Collection<int, ExternalOrder>
     */
    public function abandoned(int $afterMinutes = self::AFTER_MINUTES, int $limit = 200): Collection
    {
        return ExternalOrder::query()
            ->with(['event', 'hold'])
            ->where('status', 'pending')
            // Zero is a real answer and means "now": an operator draining a backlog by hand, and
            // the tests, both need to ask without waiting an hour for the clock.
            ->where('created_at', '<=', now()->subMinutes(max(0, $afterMinutes)))
            ->where('created_at', '>=', now()->subHours(self::GIVE_UP_HOURS))
            // Only what this platform sold. An order registered over the integration API belongs
            // to a shop that owns its own basket and its own emails.
            ->whereRaw("coalesce(metadata->>'source', '') = 'hosted_site'")
            ->whereRaw("coalesce(buyer->>'email', '') <> ''")
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('basket_recoveries')
                ->whereColumn('basket_recoveries.external_order_row_id', 'external_orders.id'))
            ->whereHas('event', fn ($query) => $query
                ->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>', now())))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Note that a purchase was left unfinished. Once per order, whatever happens.
     *
     * The unique index is what makes that true rather than a check-then-insert, because two runs of
     * the sweeper overlapping is exactly how a buyer gets written to twice — and being written to
     * twice about the same basket is how a buyer unsubscribes.
     */
    public function open(ExternalOrder $order): ?BasketRecovery
    {
        $email = trim((string) ($order->buyer['email'] ?? ''));

        if ('' === $email) {
            return null;
        }

        $basket = $order->hold ? Basket::of($order->hold) : null;

        try {
            return BasketRecovery::create([
                'tenant_id' => $order->tenant_id,
                'site_id' => $order->metadata['site_id'] ?? null,
                'event_id' => $order->event_id,
                'external_order_row_id' => $order->id,
                'email' => $email,
                'name' => $order->buyer['name'] ?? null,
                'locale' => $order->buyer['locale'] ?? null,
                'currency' => $order->currency,
                'total_amount' => (int) $order->total_amount,
                'seats' => $basket?->places() ?? 0,
                'token' => BasketRecovery::newToken(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Somebody else's run got there first. That is the index doing its job, not an error.
            return null;
        }
    }

    /**
     * Take the same seats again.
     *
     * Every refusal here is named, because each one means something different to the person who
     * followed the link: their basket is unreadable, the night is over, they already came back, or
     * — the common one — somebody else is sitting there now.
     *
     * @throws ApiException
     */
    public function resume(BasketRecovery $recovery, string $sessionId, ?string $ip = null): Hold
    {
        if (! $recovery->isOpen()) {
            throw ApiException::conflict(
                'basket_already_settled',
                'That basket has already been dealt with.'
            );
        }

        $order = $recovery->order;
        $event = $recovery->event;

        if (! $order || ! $event) {
            throw ApiException::notFound('There is nothing left of that basket.', 'basket_gone');
        }

        if (! $event->isSellable()) {
            throw ApiException::conflict(
                'basket_event_closed',
                sprintf('"%s" is no longer on sale.', $event->name),
                ['event_id' => $event->id],
                ['name' => $event->name],
            );
        }

        // Read from the order's own hold, which still exists as a released row with its signed
        // snapshot on it: the seats went back on sale, but the record of what was chosen did not.
        $basket = $order->hold ? Basket::of($order->hold) : null;

        if (! $basket || $basket->isEmpty()) {
            throw ApiException::notFound('There is nothing left of that basket.', 'basket_gone');
        }

        try {
            return $this->holds->create(
                $event,
                $basket->seats,
                $sessionId,
                null,
                $ip,
                $basket->capacity,
                $basket->seatTypes,
                $basket->areaTypes,
            );
        } catch (ApiException $e) {
            throw ApiException::conflict(
                'basket_seats_gone',
                'Somebody else has taken those seats since. Choose again — the rest of the hall is still there.',
                ['event_id' => $event->id, 'because' => $e->errorCode()],
            );
        }
    }

    /** They came back and bought. Recorded against the recovery so the screen can say it worked. */
    public function recovered(BasketRecovery $recovery, ExternalOrder $order): void
    {
        if ('recovered' === $recovery->status) {
            return;
        }

        $recovery->forceFill([
            'status' => 'recovered',
            'recovered_at' => now(),
            'recovered_order_id' => $order->id,
        ])->save();
    }

    /** No thank you. Never written to about this basket again. */
    public function decline(BasketRecovery $recovery): void
    {
        if ($recovery->isOpen()) {
            $recovery->forceFill(['status' => 'declined'])->save();
        }
    }

    /**
     * Close the ones there is no longer anything to be done about.
     *
     * A night that has been, or a purchase that turned out to have settled after all. Left open
     * they would sit in the organiser's list for ever, looking like sales still in play.
     */
    public function expire(): int
    {
        $stale = BasketRecovery::whereIn('status', ['waiting', 'sent'])
            ->with(['order', 'event'])
            ->get()
            ->filter(fn (BasketRecovery $recovery) => 'pending' !== $recovery->order?->status
                || ! $recovery->event
                || ($recovery->event->starts_at && $recovery->event->starts_at->isPast()));

        foreach ($stale as $recovery) {
            // A purchase that settled after all is a recovery, not an expiry — the buyer came back
            // through the gateway rather than through the link, and the count should say so.
            $recovery->forceFill([
                'status' => 'confirmed' === $recovery->order?->status ? 'recovered' : 'expired',
                'recovered_at' => 'confirmed' === $recovery->order?->status
                    ? ($recovery->recovered_at ?? now())
                    : null,
                'recovered_order_id' => 'confirmed' === $recovery->order?->status
                    ? ($recovery->recovered_order_id ?? $recovery->external_order_row_id)
                    : null,
            ])->save();
        }

        return $stale->count();
    }
}
