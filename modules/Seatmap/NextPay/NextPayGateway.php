<?php

namespace Modules\Seatmap\NextPay;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;

/**
 * NextPay (نکست‌پی).
 *
 * A token call returns a `trans_id`; the buyer is sent to the payment page for it; a verify call
 * with the same amount settles it. NextPay's convention is that **-1 means success** on the token
 * call and **0 means success** on verify, with -2 for "already verified" — a set of numbers that
 * is easy to invert, and inverting it either loses paid orders or confirms unpaid ones.
 */
class NextPayGateway implements PaymentGateway
{
    private const BASE = 'https://nextpay.org/nx/gateway/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'nextpay';
    }

    public function label(): string
    {
        return __('payments.nextpay.label');
    }

    public function description(): string
    {
        return __('payments.nextpay.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        if ('IRR' !== mb_strtoupper($context['currency'])) {
            return PaymentIntent::failed(__('payments.errors.currency_not_supported', [
                'gateway' => $this->label(),
                'currency' => $context['currency'],
            ]));
        }

        $response = $this->http()->postJson(self::BASE.'token', [
            'api_key' => (string) $this->context->setting('api_key'),
            'order_id' => $order->external_order_id,
            'amount' => $this->amount($context['amount']),
            'callback_uri' => $context['callback_url'],
            'customer_phone' => $context['buyer']['phone'] ?? null,
            'payer_name' => $context['buyer']['name'] ?? null,
            'payer_mail' => $context['buyer']['email'] ?? null,
        ]);

        $code = (int) $response->json('code', 0);
        $transaction = (string) $response->json('trans_id', '');

        if (-1 !== $code || '' === $transaction) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        return PaymentIntent::redirect(self::BASE.'payment/'.$transaction, $transaction);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        $transaction = (string) ($order->metadata['payment_reference'] ?? $payload['trans_id'] ?? '');

        if ('' === $transaction) {
            return PaymentIntent::failed(__('payments.errors.no_reference'));
        }

        $response = $this->http()->postJson(self::BASE.'verify', [
            'api_key' => (string) $this->context->setting('api_key'),
            'trans_id' => $transaction,
            'amount' => $this->amount((int) $order->total_amount),
        ]);

        $code = (int) $response->json('code', 999);

        // 0: settled now. -2: settled already, which is what a reloaded return page produces.
        if (0 === $code || -2 === $code) {
            return PaymentIntent::paid('nextpay:'.(string) $response->json('Shaparak_Ref_Id', $transaction));
        }

        return PaymentIntent::failed($this->reason($response->json()));
    }

    /* --------------------------------------------------------------------------- internals */

    private function http(): OutboundHttp
    {
        return new OutboundHttp($this->context);
    }

    private function amount(int $minorUnits): int
    {
        return 'toman' === $this->context->setting('amount_unit', 'rial')
            ? $minorUnits * 10
            : $minorUnits;
    }

    private function reason(mixed $body): string
    {
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        return __('payments.errors.declined', [
            'gateway' => $this->label(),
            'code' => null === $code ? '—' : (string) $code,
        ]);
    }
}
