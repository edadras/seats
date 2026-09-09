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
            'event' => (string) ($event?->name ?? ''),
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

    private function announce(string $kind, ExternalOrder $order): void
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
                $this->variables($order, $locale),
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
