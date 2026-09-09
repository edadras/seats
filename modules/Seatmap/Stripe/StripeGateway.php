<?php

namespace Modules\Seatmap\Stripe;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
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

    /* --------------------------------------------------------------------------- internals */

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
