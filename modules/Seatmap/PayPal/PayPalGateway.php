<?php

namespace Modules\Seatmap\PayPal;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;
use App\Support\Locale\Money;

/**
 * PayPal, through Orders v2.
 *
 * Three calls: an OAuth token, an order with the amount on it, and a capture when the buyer comes
 * back. PayPal is the one gateway here that wants **decimal** amounts with the currency named, so
 * the platform's minor units are converted through the same exponent table everything else uses —
 * a hard-coded division by a hundred would be wrong for the yen and wrong for the dinar.
 *
 * A capture of an order PayPal has already captured answers 422 with
 * `ORDER_ALREADY_CAPTURED`, and that is a paid order, not a failure — it is exactly what a
 * reloaded return page produces.
 */
class PayPalGateway implements PaymentGateway
{
    private const LIVE = 'https://api-m.paypal.com/';

    private const SANDBOX = 'https://api-m.sandbox.paypal.com/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return __('payments.paypal.label');
    }

    public function description(): string
    {
        return __('payments.paypal.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        $token = $this->token();

        if (! $token) {
            return PaymentIntent::failed(__('payments.errors.not_configured', ['gateway' => $this->label()]));
        }

        $response = $this->http()->postJson($this->base().'v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order->external_order_id,
                'description' => __('payments.orderDescription', ['reference' => $order->external_order_id]),
                'amount' => [
                    'currency_code' => mb_strtoupper($context['currency']),
                    'value' => number_format(
                        Money::toDecimal($context['amount'], $context['currency']),
                        Money::exponent($context['currency']),
                        '.',
                        ''
                    ),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => $context['callback_url'],
                        'cancel_url' => $context['callback_url'].'?cancelled=1',
                        'user_action' => 'PAY_NOW',
                    ],
                ],
            ],
        ], $this->headers($token) + ['PayPal-Request-Id' => 'begin-'.$order->external_order_id]);

        $id = (string) $response->json('id', '');
        $approve = $this->approvalLink($response->json('links', []));

        if (! $response->successful() || '' === $id || null === $approve) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        return PaymentIntent::redirect($approve, $id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        $id = (string) ($order->metadata['payment_reference'] ?? $payload['token'] ?? '');

        if ('' === $id) {
            return PaymentIntent::failed(__('payments.errors.no_reference'));
        }

        $token = $this->token();

        if (! $token) {
            return PaymentIntent::failed(__('payments.errors.not_configured', ['gateway' => $this->label()]));
        }

        // An empty body, which is what a capture takes. The request id makes PayPal's own
        // idempotency agree with ours, so a reloaded return page cannot capture twice.
        $response = $this->http()->postJson(
            $this->base().'v2/checkout/orders/'.$id.'/capture',
            [],
            $this->headers($token) + ['PayPal-Request-Id' => 'capture-'.$order->external_order_id],
        );

        if ('COMPLETED' === (string) $response->json('status', '')) {
            return PaymentIntent::paid('paypal:'.$id);
        }

        // Already captured is paid — a second visit to the return URL, not a second payment.
        if ($this->alreadyCaptured($response->json())) {
            return PaymentIntent::paid('paypal:'.$id);
        }

        return PaymentIntent::failed($this->reason($response->json()));
    }

    /**
     * Send money back, through the capture that took it.
     *
     * PayPal refunds a capture, and what this platform wrote down at the start was the order — so
     * the order is read again to find the capture under it. A partial refund carries the amount;
     * a whole one could omit it, but sending it either way means one path rather than two, and the
     * arithmetic is the platform's already.
     *
     * The request id is the order and the amount together: two seats, then two more, are two
     * refunds and both must go through, while a double-clicked button is one and must not.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        $token = $this->token();

        if (! $token) {
            return RefundOutcome::failed(__('payments.errors.not_configured', ['gateway' => $this->label()]));
        }

        $capture = $this->captureUnder($this->strip($reference), $token);

        if (null === $capture) {
            return RefundOutcome::failed(__('payments.errors.no_reference'));
        }

        $response = $this->http()->postJson(
            $this->base().'v2/payments/captures/'.$capture.'/refund',
            [
                'amount' => [
                    'currency_code' => mb_strtoupper((string) $order->currency),
                    'value' => $this->decimal($amount, (string) $order->currency),
                ],
                'note_to_payer' => __('payments.orderDescription', [
                    'reference' => $order->external_order_id,
                ]),
            ],
            $this->headers($token) + ['PayPal-Request-Id' => 'refund-'.$order->external_order_id.'-'.$amount],
        );

        $status = (string) $response->json('status', '');

        if ('COMPLETED' === $status || 'PENDING' === $status) {
            // PENDING is PayPal saying it has accepted the refund and is moving the money. The
            // seats should go back on sale on the strength of that: it is their answer, not ours.
            return RefundOutcome::sent('paypal:'.(string) $response->json('id', $capture));
        }

        return RefundOutcome::failed($this->reason($response->json()));
    }

    /* --------------------------------------------------------------------------- internals */

    /** The capture under a PayPal order, which is the thing a refund is made against. */
    private function captureUnder(string $orderId, string $token): ?string
    {
        $response = $this->http()->getJson(
            $this->base().'v2/checkout/orders/'.$orderId,
            [],
            $this->headers($token),
        );

        if (! $response->successful()) {
            return null;
        }

        $capture = $response->json('purchase_units.0.payments.captures.0.id');

        return is_string($capture) && '' !== $capture ? $capture : null;
    }

    /**
     * PayPal is paid in the currency's major unit, written out.
     *
     * The rest of this platform counts in the smallest unit, so this is the one place the division
     * happens — and it asks how many places the currency has rather than assuming two, because a
     * yen has none and getting that wrong is a hundredfold error in somebody's refund.
     */
    private function decimal(int $minorUnits, string $currency): string
    {
        $places = \App\Support\Locale\Money::exponent($currency);

        return number_format($minorUnits / (10 ** $places), $places, '.', '');
    }

    /** References are written down with the gateway's name on them; the API wants only the id. */
    private function strip(string $reference): string
    {
        return str_starts_with($reference, 'paypal:') ? substr($reference, 7) : $reference;
    }


    private function http(): OutboundHttp
    {
        return new OutboundHttp($this->context);
    }

    private function base(): string
    {
        return $this->context->setting('sandbox') ? self::SANDBOX : self::LIVE;
    }

    /**
     * An access token, fetched per call rather than cached.
     *
     * Caching it would mean a shared store keyed by tenant and by environment, and getting that
     * subtly wrong means one organiser's token signing another's charges. A token call is cheap.
     */
    private function token(): ?string
    {
        $response = $this->http()->postForm(
            $this->base().'v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            [
                'Authorization' => 'Basic '.base64_encode(
                    (string) $this->context->setting('client_id').':'.
                    (string) $this->context->setting('client_secret')
                ),
            ],
        );

        $token = (string) $response->json('access_token', '');

        return '' === $token ? null : $token;
    }

    private function headers(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ];
    }

    private function approvalLink(mixed $links): ?string
    {
        foreach ((array) $links as $link) {
            if ('payer-action' === ($link['rel'] ?? '') || 'approve' === ($link['rel'] ?? '')) {
                return $link['href'] ?? null;
            }
        }

        return null;
    }

    private function alreadyCaptured(mixed $body): bool
    {
        foreach ((array) ($body['details'] ?? []) as $detail) {
            if ('ORDER_ALREADY_CAPTURED' === ($detail['issue'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private function reason(mixed $body): string
    {
        $code = is_array($body) ? ($body['name'] ?? $body['error'] ?? null) : null;

        return __('payments.errors.declined', [
            'gateway' => $this->label(),
            'code' => null === $code ? '—' : (string) $code,
        ]);
    }
}
