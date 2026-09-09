<?php

namespace Modules\Seatmap\IdPay;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;

/**
 * IDPay (آیدی پی), through its v1.1 API.
 *
 * `payment` returns an id and a link; the buyer follows the link; `payment/verify` says what
 * happened. Verification takes both the id and the order id, and IDPay answers 100 for paid and
 * 101 for already-verified — the second is what a reloaded return page produces, and treating it
 * as a failure would cancel a paid order.
 *
 * The sandbox is a header, not a different host, which is why it is a boolean rather than a URL.
 */
class IdPayGateway implements PaymentGateway
{
    private const BASE = 'https://api.idpay.ir/v1.1/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'idpay';
    }

    public function label(): string
    {
        return __('payments.idpay.label');
    }

    public function description(): string
    {
        return __('payments.idpay.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        if ('IRR' !== mb_strtoupper($context['currency'])) {
            return PaymentIntent::failed(__('payments.errors.currency_not_supported', [
                'gateway' => $this->label(),
                'currency' => $context['currency'],
            ]));
        }

        $response = $this->http()->postJson(self::BASE.'payment', [
            'order_id' => $order->external_order_id,
            'amount' => $this->amount($context['amount']),
            'callback' => $context['callback_url'],
            'name' => $context['buyer']['name'] ?? null,
            'mail' => $context['buyer']['email'] ?? null,
            'phone' => $context['buyer']['phone'] ?? null,
        ], $this->headers());

        $id = (string) $response->json('id', '');
        $link = (string) $response->json('link', '');

        if (! $response->successful() || '' === $id || '' === $link) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        return PaymentIntent::redirect($link, $id);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        $id = (string) ($order->metadata['payment_reference'] ?? $payload['id'] ?? '');

        if ('' === $id) {
            return PaymentIntent::failed(__('payments.errors.no_reference'));
        }

        $response = $this->http()->postJson(self::BASE.'payment/verify', [
            'id' => $id,
            'order_id' => $order->external_order_id,
        ], $this->headers());

        $status = (int) $response->json('status', 0);

        // 100: paid. 101: verified before — which is what a reloaded return page produces.
        if (100 === $status || 101 === $status) {
            return PaymentIntent::paid('idpay:'.(string) $response->json('track_id', $id));
        }

        // Anything under 100 is IDPay saying the payment did not happen: cancelled, failed, or
        // reversed. It is a refusal, not a wait.
        return PaymentIntent::failed($this->reason($response->json()));
    }

    /* --------------------------------------------------------------------------- internals */

    private function http(): OutboundHttp
    {
        return new OutboundHttp($this->context);
    }

    private function headers(): array
    {
        return array_filter([
            'X-API-KEY' => (string) $this->context->setting('api_key'),
            'X-SANDBOX' => $this->context->setting('sandbox') ? '1' : null,
            'Content-Type' => 'application/json',
        ]);
    }

    private function amount(int $minorUnits): int
    {
        return 'toman' === $this->context->setting('amount_unit', 'rial')
            ? $minorUnits * 10
            : $minorUnits;
    }

    private function reason(mixed $body): string
    {
        $code = is_array($body) ? ($body['error_code'] ?? $body['status'] ?? null) : null;

        return __('payments.errors.declined', [
            'gateway' => $this->label(),
            'code' => null === $code ? '—' : (string) $code,
        ]);
    }
}
