<?php

namespace App\Domain\Seasons;

use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\StorefrontCheckout;
use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\SeasonBooking;
use App\Models\SeasonPass;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One payment, several nights' orders.
 *
 * The whole of this class is the answer to one question: how does a buyer pay once for twelve
 * bookings without the platform losing track of which night took which money? The answer is that
 * it does not group the money — it groups the *purchase*. Each night is registered, priced and
 * confirmed through the ordinary {@see OrderService}, carrying its own share of the season's
 * saving; the group row only remembers that they were bought together and holds the gateway
 * conversation, which needs one order to attach to and gets the first night's.
 *
 * Nothing downstream is special-cased. Availability, the door list, per-event settlement, calling
 * a night off and refunding one night all see ordinary orders, because that is what they are.
 */
class SeasonCheckout
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly GatewayRegistry $gateways,
        private readonly StorefrontCheckout $storefront,
        private readonly Seasons $seasons,
    ) {}

    /**
     * What the run comes to, night by night.
     *
     * Public and computed from the holds alone, because the checkout page needs this answer before
     * there is anything written to read it from — and it must be the same answer the payment uses,
     * or the summary promises a number the charge does not honour.
     *
     * @param  list<Hold>  $holds
     * @return array{nights: list<array<string, mixed>>, tickets: int, discount: int, total: int}
     */
    public function quote(SeasonPass $pass, array $holds): array
    {
        $tickets = array_map(fn (Hold $hold) => (int) $hold->total_amount, $holds);
        $off = $pass->discountOn(array_sum($tickets));
        // Split across the nights rather than kept on the group, so each night's order adds up on
        // its own and the nights sum to exactly what the card is charged.
        $shares = $this->seasons->apportion($off, $tickets);

        $nights = [];
        $total = 0;

        foreach ($holds as $index => $hold) {
            $totals = OrderTotals::for(
                $hold->event ?? $hold->loadMissing('event')->event,
                $tickets[$index],
                $shares[$index],
                $this->seasons->placesIn($hold),
            );

            $nights[] = [
                'hold' => $hold,
                'event' => $hold->event,
                'tickets' => $tickets[$index],
                'discount' => $shares[$index],
                'totals' => $totals,
            ];

            $total += $totals->total;
        }

        return [
            'nights' => $nights,
            'tickets' => array_sum($tickets),
            'discount' => $off,
            'total' => $total,
        ];
    }

    /**
     * Buy the run.
     *
     * Idempotent on the first night's hold, the same way an ordinary site booking is: a browser
     * that retries the POST lands on the purchase it already made rather than a second one, and
     * the orders under it are registered against references derived from the same token.
     *
     * @param  list<Hold>  $holds  every night, the buyer's own first
     * @return array{0: SeasonBooking, 1: PaymentIntent}
     */
    public function place(
        Site $site,
        SeasonPass $pass,
        array $holds,
        array $buyer,
        string $gatewayKey,
        string $returnUrl,
    ): array {
        foreach ($holds as $hold) {
            if (! $hold->isActive()) {
                throw ApiException::conflict('hold_'.$hold->currentState(), sprintf(
                    'Your seats are %s. Please choose again.', $hold->currentState()
                ));
            }
        }

        $reference = self::referenceFor($holds[0]);
        $quote = $this->quote($pass, $holds);
        $client = $this->storefront->clientFor($site);
        $gateway = $quote['total'] > 0 ? $this->gateways->get($gatewayKey) : null;

        $booking = SeasonBooking::where('reference', $reference)->first();

        if (! $booking) {
            $booking = SeasonBooking::create([
                'tenant_id' => $site->tenant_id,
                'season_pass_id' => $pass->id,
                'series_id' => $pass->series_id,
                'site_id' => $site->id,
                'reference' => $reference,
                'buyer' => $buyer,
                'currency' => $pass->currency,
                'total_amount' => $quote['total'],
                'discount_amount' => $quote['discount'],
                'seats' => $this->seasons->placesIn($holds[0]),
                'nights' => count($holds),
                'gateway' => $gateway?->key() ?? 'none',
                'metadata' => ['pass' => $pass->name],
            ]);
        }

        /*
         * One order per night, each registered against its own hold.
         *
         * `register` is already idempotent per (client, external order id), so a retry re-reaches
         * the same rows rather than making more — which is why the id is derived from the group's
         * reference and the night's place in the run rather than generated.
         */
        $orders = [];

        foreach ($quote['nights'] as $index => $night) {
            [$order, $registered] = $this->orders->register(
                $client,
                $reference.'-'.($index + 1),
                $night['hold']->token,
                $buyer,
                [
                    'source' => 'hosted_site',
                    'site_id' => $site->id,
                    'gateway' => $gateway?->key() ?? 'none',
                    // Why this night cost what it cost. Read back by the panel and the invoice
                    // rather than recomputed, so a pass edited later cannot rewrite a past sale.
                    'season' => [
                        'reference' => $reference,
                        'pass' => $pass->name,
                        'nights' => count($holds),
                        'discount' => $night['discount'],
                    ],
                ],
            );

            if ($registered) {
                $order->forceFill([
                    'season_booking_id' => $booking->id,
                    'total_amount' => $night['totals']->total,
                    'metadata' => ($order->metadata ?? []) + ['totals' => $night['totals']->toArray()],
                ])->save();
            }

            $orders[] = $order;
        }

        if (! $booking->lead_order_id) {
            $booking->forceFill(['lead_order_id' => $orders[0]->id])->save();
        }

        if ($quote['total'] < 1) {
            // A run given away, or discounted to nothing. There is no gateway conversation to have
            // and pretending to have one would be inventing a payment that never happened.
            return [$this->confirm($booking, $orders, $buyer), PaymentIntent::paid('season')];
        }

        $intent = $gateway->begin($orders[0], [
            // The whole run, once. Each night's order keeps its own total; this is their sum, and
            // it is the only number the buyer's card ever sees.
            'amount' => $quote['total'],
            'currency' => (string) $booking->currency,
            'return_url' => $returnUrl,
            'callback_url' => $site->url('/pay/'.$gateway->key().'/season/'.$reference),
            'buyer' => $buyer,
            'reference' => $reference,
        ]);

        if ($intent->reference) {
            $booking->forceFill(['payment_reference' => $intent->reference])->save();
        }

        if ($intent->hasFailed()) {
            $this->cancel($booking, 'payment_failed');

            throw ApiException::conflict('payment_failed', $intent->message ?: 'Payment could not be taken.');
        }

        if ($intent->isPaid()) {
            $booking = $this->confirm($booking, $orders, $buyer);
        }

        return [$booking, $intent];
    }

    /**
     * Settle whatever the gateway said. Safe to call twice: redirects and webhooks both arrive twice.
     */
    public function settle(SeasonBooking $booking, string $gatewayKey, array $payload): SeasonBooking
    {
        $lead = $booking->leadOrder;

        if (! $lead) {
            throw ApiException::conflict('season_not_payable', 'This season ticket has no payment to settle.');
        }

        $intent = $this->gateways->get($gatewayKey)->settle($lead, $payload);

        if ($intent->isPaid()) {
            return $this->confirm($booking, $booking->orders()->get()->all(), $booking->buyer ?? []);
        }

        if ($intent->hasFailed()) {
            return $this->cancel($booking, 'payment_failed');
        }

        return $booking;
    }

    /**
     * Confirm every night, then the purchase.
     *
     * Each night goes through the ordinary confirm, which is what issues its allocations and its
     * ticket exactly once. The group is marked afterwards, so a crash halfway leaves a purchase
     * that still says "pending" over orders that are already good — which is the recoverable way
     * round. The reverse would be a purchase claiming tickets that were never issued.
     *
     * @param  list<ExternalOrder>  $orders
     */
    public function confirm(SeasonBooking $booking, array $orders, array $buyer): SeasonBooking
    {
        $confirmed = [];

        foreach ($orders as $order) {
            $confirmed[] = $this->orders->confirm($order, $buyer, now());
        }

        if (! $booking->isConfirmed()) {
            $booking->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();
        }

        $booking->refresh();

        /*
         * The confirmed orders, handed back on the booking rather than left to be re-queried.
         *
         * A ticket's plaintext code exists exactly once, on the model that just issued it — the
         * database keeps only a hash — so a caller that fetched these rows again would get tickets
         * with no codes in them, and a confirmation page with nothing to show.
         */
        $booking->setRelation('orders', collect($confirmed));

        return $booking;
    }

    /** Cancel every night, then the purchase. Nothing was charged, so nothing goes back. */
    public function cancel(SeasonBooking $booking, string $reason = 'cancelled'): SeasonBooking
    {
        DB::transaction(function () use ($booking, $reason) {
            foreach ($booking->orders()->get() as $order) {
                $this->orders->cancel($order, $reason);
            }

            $booking->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        });

        return $booking->refresh();
    }

    /**
     * One purchase per basket.
     *
     * Derived from the first night's hold token for the same reason a site booking's reference is
     * derived from its hold: the token is already unique, already this buyer's, and already what
     * the orders are registered against, so a repeated submit is idempotent without a second table
     * of attempt ids.
     */
    public static function referenceFor(Hold $first): string
    {
        return 'sea-'.Str::lower(substr(hash('sha256', $first->token), 0, 20));
    }
}
