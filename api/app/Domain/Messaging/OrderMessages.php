<?php

namespace App\Domain\Messaging;

use App\Models\ExternalOrder;
use App\Support\Locale\Dates;
use App\Support\Locale\Locales;
use App\Support\Locale\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a buyer is told about their own order.
 *
 * The variables come from the order and its event, never from the request that caused the send, so
 * a message says what the platform believes rather than what a form claimed. The buyer's own
 * language is used when they have one and the site's when they do not — the same rule the rest of
 * the platform follows (ADR-0005 §3).
 *
 * Every failure is swallowed and logged. Seats are sold and tickets are issued by the time this
 * runs; rolling a completed purchase back over an SMS provider being slow would be a worse answer
 * than a confirmation that arrives late.
 */
class OrderMessages
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function confirmed(ExternalOrder $order): void
    {
        $this->announce('order.confirmed', $order);
    }

    public function cancelled(ExternalOrder $order): void
    {
        $this->announce('order.cancelled', $order);
    }

    /**
     * The night is off, and here is why.
     *
     * The organiser's own sentence rather than a template's: "cancelled" on its own is the start
     * of an argument rather than the end of one.
     */
    public function eventCancelled(ExternalOrder $order, string $reason): void
    {
        $this->announce('event.cancelled', $order, ['reason' => $reason]);
    }

    /** The night has moved, and their ticket still works — which is the part people doubt. */
    public function eventMoved(ExternalOrder $order, \DateTimeInterface $was, string $reason): void
    {
        $this->announce('event.moved', $order, [
            'reason' => $reason,
            'was' => Dates::longWhen($was, Locales::normalise($order->buyer['locale'] ?? app()->getLocale())),
        ]);
    }

    /** @return array<string, string> */
    public function variables(ExternalOrder $order, string $locale): array
    {
        $event = $order->event;
        $seats = $order->allocations
            ->map(fn ($allocation) => trim(implode(' ', array_filter([
                $allocation->section_name, $allocation->row_name, $allocation->seat_label,
            ]))))
            ->filter()
            ->all();

        return [
            'buyer' => (string) ($order->buyer['name'] ?? ''),
            // The buyer's own language, which is the language this whole message is being
            // written in — an event called one thing in the subject line and another in the body
            // would read as two different events.
            'event' => (string) ($event?->nameFor($locale) ?? ''),
            'venue' => (string) ($event?->venue?->name ?? ''),
            'starts' => $event?->starts_at
                ? Dates::longWhen($event->starts_at, $locale)
                : '',
            'seats' => $seats ? implode(', ', $seats) : (string) $order->allocations->count(),
            'total' => Money::format((int) $order->total_amount, (string) $order->currency, $locale),
            'reference' => (string) $order->external_order_id,
            'site' => (string) ($order->apiClient?->site_url ?? ''),
        ];
    }

    /** @param  array<string, string>  $extra  variables this kind has that a booking does not */
    private function announce(string $kind, ExternalOrder $order, array $extra = []): void
    {
        try {
            $order->loadMissing(['event.venue', 'allocations', 'apiClient']);

            // The buyer's own language when the checkout recorded one, otherwise whatever this
            // request resolved to — which on a hosted site is the language they were reading in.
            $locale = Locales::normalise($order->buyer['locale'] ?? app()->getLocale());

            $this->dispatcher->announce(
                $kind,
                array_filter([
                    'email' => $order->buyer['email'] ?? null,
                    'phone' => $order->buyer['phone'] ?? null,
                    'handle' => $order->buyer['handle'] ?? null,
                ]),
                $this->variables($order, $locale) + $extra,
                $locale,
                ['event_id' => $order->event_id, 'order_id' => $order->id],
            );
        } catch (Throwable $e) {
            Log::warning('Order message could not be sent', [
                'order' => $order->external_order_id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
