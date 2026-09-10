<?php

namespace App\Domain\Addons;

use App\Exceptions\ApiException;
use App\Models\Addon;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\OrderAddon;
use Illuminate\Support\Facades\DB;

/**
 * What is offered beside the tickets, what a buyer chose, and whether there is any left.
 *
 * Stock is a sum against a limit — how many have been sold, against how many exist — so it is
 * counted rather than decremented, and recomputed under an advisory lock keyed on the add-on at
 * the moment it is sold. A column that was decremented would be a read-modify-write and would
 * oversell the last programme to two buyers in the same second, exactly as a seat would.
 *
 * Unlike a seat, an add-on is not held: nobody reserves a programme while they choose where to
 * sit. It is sold when the booking is placed, and that is the only moment its stock moves. A buyer
 * who is beaten to the last one is told at that moment, and their seats are not lost with it.
 */
class Addons
{
    /**
     * What this event offers, in the order the organiser put them in.
     *
     * @return \Illuminate\Support\Collection<int, Addon>
     */
    public function forEvent(Event $event, bool $visibleOnly = true): \Illuminate\Support\Collection
    {
        return Addon::query()
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $event->id))
            ->where('currency', $event->currency)
            ->when($visibleOnly, fn ($q) => $q->where('visible', true))
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * What a buyer would see: each add-on with what is left of it.
     *
     * `remaining` is null where there is no limit, which is the ordinary case. A sold-out add-on
     * is still listed, greyed rather than hidden — an organiser wants to know the programmes went,
     * and so does the person who came for one.
     *
     * @param  int  $places  how many tickets, for the ones priced per ticket
     * @return list<array<string, mixed>>
     */
    public function offer(Event $event, int $places): array
    {
        $addons = $this->forEvent($event);
        $sold = $this->sold($addons->pluck('id')->all());

        return $addons->map(function (Addon $addon) use ($sold, $places) {
            $remaining = null === $addon->stock
                ? null
                : max(0, $addon->stock - (int) ($sold[$addon->id] ?? 0));

            return [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'price' => $addon->price,
                'currency' => $addon->currency,
                'per' => $addon->per,
                // A per-ticket add-on is not a choice: it is one each, and the buyer is told the
                // number rather than asked for it.
                'quantity' => $addon->isPerTicket() ? $places : null,
                'max' => $addon->isPerTicket()
                    ? $places
                    : min($addon->max_per_order, $remaining ?? $addon->max_per_order),
                'remaining' => $remaining,
                'sold_out' => null !== $remaining && 0 === $remaining,
            ];
        })->values()->all();
    }

    /**
     * Turn what a buyer chose into priced lines, refusing anything that does not add up.
     *
     * Priced here, from the organiser's own row, and never from the browser: a price the browser
     * could name is a price the browser could argue with. Nothing is written — this is the answer
     * the checkout summary shows, and the same answer `attach` writes when the booking is placed.
     *
     * @param  array<string, int>  $chosen  addon id => quantity
     * @return list<array{addon: Addon, quantity: int, unit_price: int, amount: int}>
     */
    public function price(Event $event, array $chosen, int $places): array
    {
        $offered = $this->forEvent($event)->keyBy('id');
        $lines = [];

        foreach ($this->forEvent($event) as $addon) {
            // A per-ticket add-on is compulsory and its number is the ticket count, whatever the
            // browser said. Sending one of these as a quantity is not how it is bought.
            $quantity = $addon->isPerTicket() ? $places : (int) ($chosen[$addon->id] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            if (! $addon->isPerTicket() && $quantity > $addon->max_per_order) {
                throw ApiException::unprocessable(
                    'addon_too_many',
                    sprintf('At most %d of "%s" may be bought at once.', $addon->max_per_order, $addon->name),
                    ['addon_id' => $addon->id, 'max_per_order' => $addon->max_per_order],
                    ['count' => $addon->max_per_order, 'name' => $addon->name],
                );
            }

            $lines[] = [
                'addon' => $addon,
                'quantity' => $quantity,
                'unit_price' => $addon->price,
                'amount' => $addon->price * $quantity,
            ];
        }

        // Anything named that is not on offer is a request this event cannot honour. Silently
        // dropping it would charge a buyer for seats and hand them no programme.
        foreach (array_keys($chosen) as $id) {
            if ((int) ($chosen[$id] ?? 0) > 0 && ! $offered->has($id)) {
                throw ApiException::unprocessable(
                    'unknown_addon',
                    'That is not something this event sells.',
                    ['addon_id' => $id],
                );
            }
        }

        return $lines;
    }

    /** What the chosen lines come to, for a summary that has not written anything yet. */
    public function total(array $lines): int
    {
        return array_sum(array_map(fn (array $line) => $line['amount'], $lines));
    }

    /**
     * Write the lines onto a booking, under the lock that keeps the stock honest.
     *
     * Called inside the caller's transaction. Idempotent per booking: the unique index refuses a
     * second line for the same add-on, so a retried submit re-states rather than doubling.
     *
     * @param  list<array{addon: Addon, quantity: int, unit_price: int, amount: int}>  $lines
     *
     * @throws ApiException when the last of something went while the buyer was paying
     */
    public function attach(ExternalOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            /** @var Addon $addon */
            $addon = $line['addon'];

            if (null !== $addon->stock) {
                $this->lock($addon);

                if ($this->remaining($addon) < $line['quantity']) {
                    throw ApiException::conflict(
                        'addon_sold_out',
                        sprintf('There are not that many of "%s" left.', $addon->name),
                        ['addon_id' => $addon->id, 'remaining' => $this->remaining($addon)],
                        ['name' => $addon->name],
                    );
                }
            }

            OrderAddon::updateOrCreate(
                ['external_order_row_id' => $order->id, 'addon_id' => $addon->id],
                [
                    'tenant_id' => $order->tenant_id,
                    'name' => $addon->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'currency' => $addon->currency,
                ],
            );
        }
    }

    /** How many of one add-on are left. Counted, never read off a column. */
    public function remaining(Addon $addon): int
    {
        if (null === $addon->stock) {
            return PHP_INT_MAX;
        }

        return max(0, $addon->stock - (int) ($this->sold([$addon->id])[$addon->id] ?? 0));
    }

    /**
     * How many of each have been sold.
     *
     * A cancelled or refunded booking gives its programmes back — the organiser did not hand one
     * over — so only live bookings count. A line refunded on its own says so with `refunded_at`.
     *
     * @param  list<string>  $addonIds
     * @return array<string, int>
     */
    public function sold(array $addonIds): array
    {
        if ([] === $addonIds) {
            return [];
        }

        return DB::table('order_addons as l')
            ->join('external_orders as o', 'o.id', '=', 'l.external_order_row_id')
            ->whereIn('l.addon_id', $addonIds)
            ->whereNull('l.refunded_at')
            ->whereIn('o.status', ['pending', 'confirmed', 'partially_refunded'])
            ->groupBy('l.addon_id')
            ->selectRaw('l.addon_id, SUM(l.quantity) as taken')
            ->pluck('taken', 'addon_id')
            ->map(fn ($taken) => (int) $taken)
            ->all();
    }

    /**
     * Serialise every buyer of one add-on behind the others.
     *
     * Keyed on the add-on alone, so a rush on the last programme never delays somebody buying a
     * parking space.
     */
    private function lock(Addon $addon): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['addon:'.$addon->id],
        );
    }
}
