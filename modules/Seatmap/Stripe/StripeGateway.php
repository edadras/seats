<?php

namespace Modules\Seatmap\Stripe;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;

/**
 * Stripe, through Checkout Sessions.
 *
 * A hosted session rather than card fields on our page: card details never touch this server,
 * which is the difference between a PCI questionnaire and a PCI audit. The session is created with
 * the amount the hold already fixed, the buyer is sent to Stripe, and settlement asks Stripe for
 * the session again and believes `payment_status`.
 *
 * The REST API is form-encoded and nests with brackets — `line_items[0][price_data][currency]` —
 * which is why this builds a flat array by hand rather than posting JSON.
 *
 * Amounts are in the currency's smallest unit, which is exactly what this platform stores, so
 * nothing is divided anywhere. Zero-decimal currencies work by the same rule and for the same
 * reason.
 */
class StripeGateway implements PaymentGateway
{
    private const BASE = 'https://api.stripe.com/v1/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return __('payments.stripe.label');
    }

    public function description(): string
    {
        return __('payments.stripe.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        $descriptor = (string) $this->context->setting('statement_descriptor', '');

        $body = [
            'mode' => 'payment',
            'success_url' => $context['callback_url'].'?session={CHECKOUT_SESSION_ID}',
            'cancel_url' => $context['callback_url'].'?cancelled=1',
            'client_reference_id' => $order->external_order_id,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => mb_strtolower($context['currency']),
            'line_items[0][price_data][unit_amount]' => $context['amount'],
            'line_items[0][price_data][product_data][name]' => __('payments.orderDescription', [
                'reference' => $order->external_order_id,
            ]),
            // Idempotent at Stripe's end as well as ours: a double-submitted checkout must not
            // become two sessions and two charges.
            'metadata[order]' => $order->external_order_id,
        ];

        if (isset($context['buyer']['email'])) {
            $body['customer_email'] = $context['buyer']['email'];
        }

        if ('' !== $descriptor) {
            $body['payment_intent_data[statement_descriptor_suffix]'] = mb_substr($descriptor, 0, 22);
        }

        $response = $this->http()->postForm(self::BASE.'checkout/sessions', $body, $this->headers([
            'Idempotency-Key' => 'begin-'.$order->external_order_id,
        ]));

        $id = (string) $response->json('id', '');
        $url = (string) $response->json('url', '');

        if (! $response->successful() || '' === $id || '' === $url) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        return PaymentIntent::redirect($url, $id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        $session = (string) ($order->metadata['payment_reference'] ?? $payload['session'] ?? '');

        if ('' === $session) {
            return PaymentIntent::failed(__('payments.errors.no_reference'));
        }

        $response = $this->http()->getJson(self::BASE.'checkout/sessions/'.$session, [], $this->headers());

        if (! $response->successful()) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        $status = (string) $response->json('payment_status', '');

        if ('paid' === $status || 'no_payment_required' === $status) {
            return PaymentIntent::paid('stripe:'.(string) $response->json('payment_intent', $session));
        }

        // `expired` is Stripe saying nobody is going to pay this; `unpaid` on an open session means
        // the buyer is still deciding, and a pending order is the honest answer to that.
        if ('expired' === (string) $response->json('status', '')) {
            return PaymentIntent::failed(__('payments.errors.cancelled_by_buyer'));
        }

        return PaymentIntent::pending($session);
    }

    /**
     * Send money back, through the payment behind the checkout session.
     *
     * Stripe refunds a PaymentIntent, and what this platform wrote down at the start was the
     * Checkout Session — so the session is read again to find the payment under it. That is the
     * same request `settle()` makes, for the same reason: the session is the handle a buyer's
     * return carries, and the payment is the thing money moved through.
     *
     * The idempotency key is the order and the amount together. A clerk who refunds two seats,
     * then two more, is making two different refunds and both must go through; a double-clicked
     * button is the same refund twice and must not.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        $session = $this->strip($reference);
        $payment = $this->paymentBehind($session);

        if (null === $payment) {
            return RefundOutcome::failed(__('payments.errors.no_reference'));
        }

        $response = $this->http()->postForm(self::BASE.'refunds', [
            'payment_intent' => $payment,
            'amount' => $amount,
            'metadata[order]' => $order->external_order_id,
        ], $this->headers([
            'Idempotency-Key' => 'refund-'.$order->external_order_id.'-'.$amount,
        ]));

        if ($response->successful()) {
            return RefundOutcome::sent('stripe:'.(string) $response->json('id', $payment));
        }

        /*
         * Stripe's own word for "there is nothing left to give back".
         *
         * Reported as sent rather than as a failure: the money is already where the refund was
         * trying to put it, and refusing here would leave a booking that can never be closed.
         */
        if ('charge_already_refunded' === (string) $response->json('error.code', '')) {
            return RefundOutcome::sent('stripe:'.$payment);
        }

        return RefundOutcome::failed($this->reason($response->json()));
    }

    /* --------------------------------------------------------------------------- internals */

    /** The PaymentIntent under a Checkout Session, or null if Stripe will not say. */
    private function paymentBehind(string $session): ?string
    {
        // Already a payment intent — a booking settled by a webhook rather than by a return.
        if (str_starts_with($session, 'pi_')) {
            return $session;
        }

        $response = $this->http()->getJson(self::BASE.'checkout/sessions/'.$session, [], $this->headers());

        if (! $response->successful()) {
            return null;
        }

        $payment = (string) $response->json('payment_intent', '');

        return '' === $payment ? null : $payment;
    }

    /** References are written down with the gateway's name on them; the API wants only the id. */
    private function strip(string $reference): string
    {
        return str_starts_with($reference, 'stripe:') ? substr($reference, 7) : $reference;
    }


    private function http(): OutboundHttp
    {
        return new OutboundHttp($this->context);
    }

    private function headers(array $extra = []): array
    {
        return $extra + [
            'Authorization' => 'Bearer '.(string) $this->context->setting('secret_key'),
            'Stripe-Version' => '2024-06-20',
        ];
    }

    private function reason(mixed $body): string
    {
        $code = is_array($body) ? ($body['error']['code'] ?? $body['error']['type'] ?? null) : null;

        return __('payments.errors.declined', [
            'gateway' => $this->label(),
            'code' => null === $code ? '—' : (string) $code,
        ]);
    }
}
