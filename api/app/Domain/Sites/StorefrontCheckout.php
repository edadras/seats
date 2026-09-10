<?php

namespace App\Domain\Sites;

use App\Domain\Discounts\DiscountOffer;
use App\Domain\Discounts\Discounts;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Exceptions\ApiException;
use App\Models\ApiClient;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\Site;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The hosted site's checkout, expressed as a client of the existing order lifecycle.
 *
 * It would be a mistake to write a second order path. Holds with a signed price snapshot,
 * register/confirm/cancel/refund, exactly-once ticket issuance and the partial unique indexes are
 * the part of this system that has been tested hardest, and they are reached through OrderService
 * with an ApiClient. So a site gets one, of kind `storefront`, and calls the same methods in-process
 * (ADR-0003 §4).
 *
 * The order id is derived from the hold, not generated per attempt: a buyer who double-submits, or
 * whose browser retries the POST, must land on the same order rather than a second one.
 */
class StorefrontCheckout
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly GatewayRegistry $gateways,
        private readonly TenantContext $tenantContext,
        private readonly Discounts $discounts,
        private readonly \App\Domain\Addons\Addons $addons,
    ) {}

    /** The client a site sells through, created on first use so an older site does not need a backfill. */
    public function clientFor(Site $site): ApiClient
    {
        if ($site->api_client_id) {
            $client = ApiClient::find($site->api_client_id);

            if ($client) {
                return $client;
            }
        }

        $client = ApiClient::create([
            'tenant_id' => $site->tenant_id,
            'name' => $site->name.' (site)',
            'kind' => 'storefront',
            'site_url' => $site->canonicalHost() ? $site->url('/') : null,
            'status' => 'active',
        ]);

        $site->forceFill(['api_client_id' => $client->id])->save();

        return $client;
    }

    /**
     * Place an order for a hold, and start payment.
     *
     * Nothing about the amount comes from the request. The hold carries the server's own price
     * snapshot; this reads it and charges that (threat T3).
     */
    public function place(
        Site $site,
        Hold $hold,
        array $buyer,
        string $gatewayKey,
        string $returnUrl,
        ?DiscountOffer $offer = null,
        array $billing = [],
        array $addons = [],
        int $donation = 0,
    ): array {
        if (! $hold->isActive()) {
            throw ApiException::conflict('hold_'.$hold->currentState(), sprintf(
                'Your seats are %s. Please choose again.', $hold->currentState()
            ));
        }

        $gateway = $this->gateways->get($gatewayKey);
        $client = $this->clientFor($site);

        [$order, $registered] = $this->orders->register(
            $client,
            self::referenceFor($hold),
            $hold->token,
            $buyer,
            ['source' => 'hosted_site', 'site_id' => $site->id, 'gateway' => $gateway->key()]
                + ($billing === [] ? [] : ['billing' => $billing]),
        );

        /*
         * The discount is spent here, between registering the order and asking for the money.
         *
         * Before the gateway, because the gateway is told an amount and that amount has to be the
         * one the buyer agreed to. Only on a first registration, because a retried submit lands on
         * the same order, and an order that already carries the discount must not carry it twice.
         */
        if ($offer && $registered && $offer->isAllowed()) {
            if (! $this->discounts->applyTo($order, $offer->code, $offer->amount)) {
                throw ApiException::conflict(
                    'discount_used_up',
                    'That code has just been used for the last time.'
                );
            }

            $order->refresh();
        }

        /*
         * What the booking actually comes to.
         *
         * The hold knows what the seats cost and nothing else. The fee and the tax belong to the
         * event, and the discount has just been spent, so the total is settled here — once, before
         * the gateway is told an amount — and written onto the order with its arithmetic beside it.
         * The confirmation page and the invoice read that back rather than recomputing it, so
         * neither can drift from what was charged.
         */
        if ($registered) {
            /*
             * The programmes and the gift, written before the gateway is told an amount.
             *
             * `attach` takes the advisory lock on anything with a stock and refuses if the last
             * one went while this buyer was choosing — which is a refusal they can act on, since
             * their seats are still theirs. Priced from the organiser's own rows, never from the
             * request: a price the browser could name is a price the browser could argue with.
             */
            $lines = $this->addons->price($hold->event, $addons, self::placesIn($hold));

            DB::transaction(function () use ($order, $lines, $donation) {
                $this->addons->attach($order, $lines);

                $order->forceFill(['donation' => $donation])->save();
            });

            $totals = self::totalsFor(
                $hold,
                (int) (($order->metadata['discount']['amount'] ?? 0)),
                $this->addons->total($lines),
                $donation,
            );

            $order->forceFill([
                'total_amount' => $totals->total,
                'metadata' => ($order->metadata ?? []) + ['totals' => $totals->toArray()],
            ])->save();
        }

        $intent = $gateway->begin($order, [
            // The order's total, not the hold's: a discount has already been taken off one and not
            // the other, and the buyer must be charged what the confirmation will say they paid.
            'amount' => (int) $order->total_amount,
            'currency' => (string) $order->currency,
            // Where the buyer ends up, and where the *gateway* should send them on the way: the
            // second is built here rather than in each module, so five modules cannot have five
            // opinions about what this site's address is.
            'return_url' => $returnUrl,
            'callback_url' => $site->url('/pay/'.$gateway->key().'/return/'.$order->external_order_id),
            'buyer' => $buyer,
            'reference' => $order->external_order_id,
        ]);

        /*
         * Whatever the gateway called this attempt is written down here.
         *
         * A redirect gateway hands back an authority, a session id or a token, and asks for it
         * again when the buyer comes back. Without somewhere to keep it, the return is a stranger
         * holding a receipt for a payment we cannot look up — and the money has already moved.
         */
        if ($intent->reference) {
            $order->forceFill([
                'metadata' => ($order->metadata ?? []) + [
                    'gateway' => $gateway->key(),
                    'payment_reference' => $intent->reference,
                ],
            ])->save();
        }

        if ($intent->hasFailed()) {
            $this->orders->cancel($order, 'payment_failed');

            throw ApiException::conflict('payment_failed', $intent->message ?: 'Payment could not be taken.');
        }

        if ($intent->isPaid()) {
            $order = $this->orders->confirm($order, $buyer, now());
        }

        return [$order, $intent];
    }

    /** Settle a gateway's answer. Safe to call twice: both redirects and webhooks arrive twice. */
    public function settle(ExternalOrder $order, string $gatewayKey, array $payload): ExternalOrder
    {
        $intent = $this->gateways->get($gatewayKey)->settle($order, $payload);

        if ($intent->isPaid()) {
            return $this->orders->confirm($order, $order->buyer ?? [], now());
        }

        if ($intent->hasFailed()) {
            return $this->orders->cancel($order, 'payment_failed');
        }

        return $order;
    }

    /**
     * The booking's arithmetic, from the hold and the event it is for.
     *
     * Public and static because the checkout screen needs the same answer before there is an order
     * to read it from — and it must be the same answer, computed by the same code, or the summary
     * promises a number the payment does not honour.
     */
    public static function totalsFor(
        Hold $hold,
        int $discount = 0,
        int $addons = 0,
        int $donation = 0,
    ): OrderTotals {
        return OrderTotals::for(
            $hold->event ?? $hold->loadMissing('event')->event,
            (int) $hold->total_amount,
            $discount,
            self::placesIn($hold),
            $addons,
            $donation,
        );
    }

    /**
     * How many tickets this hold is for.
     *
     * Read off the signed snapshot rather than counted from the database: it is what was reserved,
     * and a per-ticket fee or a per-ticket add-on has to be charged on that and not on whatever a
     * later query happens to find.
     */
    public static function placesIn(Hold $hold): int
    {
        $snapshot = $hold->price_snapshot['decoded'] ?? [];
        $places = count($snapshot['seats'] ?? []);

        foreach ($snapshot['areas'] ?? [] as $area) {
            $places += max(1, (int) ($area['quantity'] ?? 1));
        }

        return $places;
    }

    /**
     * One order per hold.
     *
     * A hold token is already unique, already tied to one buyer's selection, and already the thing
     * the order is registered against — so deriving from it makes a repeated submit idempotent for
     * free, without a second table of attempt ids.
     */
    public static function referenceFor(Hold $hold): string
    {
        return 'site-'.Str::lower(substr(hash('sha256', $hold->token), 0, 24));
    }
}
