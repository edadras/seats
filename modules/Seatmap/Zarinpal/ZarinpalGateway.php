<?php

namespace Modules\Seatmap\Zarinpal;

use App\Modules\OutboundHttp;
use App\Domain\Sites\Payments\PaymentGateway;
use App\Domain\Sites\Payments\PaymentIntent;
use App\Domain\Sites\Payments\RefundOutcome;
use App\Models\ExternalOrder;
use App\Modules\ModuleContext;

/**
 * Zarinpal (زرین‌پال), through its v4 REST API.
 *
 * Two calls: `payment/request` returns an *authority*, the buyer is sent to StartPay with it, and
 * `payment/verify` says whether the money moved. The authority is written on the order when the
 * payment begins, and verification uses that rather than anything in the return request — a return
 * is a URL the buyer's browser followed, and a URL is something anybody can type.
 *
 * Two details that are easy to get wrong and expensive when you do:
 *
 *   Zarinpal takes **rials**, and Iranian venues price in either rials or tomans. The unit is a
 *   setting, and a toman price is multiplied by ten on the way out — not divided, which is the
 *   mistake that charges a buyer a tenth of the ticket.
 *
 *   Verify answers 100 for "paid" and 101 for "already verified". Treating 101 as a failure is how
 *   a second visit to the return URL cancels an order that was paid for twenty minutes ago.
 */
class ZarinpalGateway implements PaymentGateway
{
    private const LIVE = 'https://payment.zarinpal.com/pg/';

    private const SANDBOX = 'https://sandbox.zarinpal.com/pg/';

    public function __construct(private readonly ModuleContext $context) {}

    public function key(): string
    {
        return 'zarinpal';
    }

    public function label(): string
    {
        return __('payments.zarinpal.label');
    }

    public function description(): string
    {
        return __('payments.zarinpal.description');
    }

    public function begin(ExternalOrder $order, array $context): PaymentIntent
    {
        if ('IRR' !== mb_strtoupper($context['currency'])) {
            return PaymentIntent::failed(__('payments.errors.currency_not_supported', [
                'gateway' => $this->label(),
                'currency' => $context['currency'],
            ]));
        }

        $response = $this->http()->postJson($this->base().'v4/payment/request.json', [
            'merchant_id' => (string) $this->context->setting('merchant_id'),
            'amount' => $this->amount($context['amount']),
            'callback_url' => $context['callback_url'],
            'description' => __('payments.orderDescription', ['reference' => $order->external_order_id]),
            'metadata' => array_filter([
                'email' => $context['buyer']['email'] ?? null,
                'mobile' => $context['buyer']['phone'] ?? null,
            ]),
        ]);

        $authority = (string) $response->json('data.authority', '');
        $code = (int) $response->json('data.code', 0);

        if (! $response->successful() || 100 !== $code || '' === $authority) {
            return PaymentIntent::failed($this->reason($response->json()));
        }

        return PaymentIntent::redirect($this->base().'StartPay/'.$authority, $authority);
    }

    public function settle(ExternalOrder $order, array $payload): PaymentIntent
    {
        $authority = (string) ($order->metadata['payment_reference'] ?? '');

        if ('' === $authority) {
            return PaymentIntent::failed(__('payments.errors.no_reference'));
        }

        // The buyer cancelled at Zarinpal's page. Said plainly rather than left pending, because a
        // pending order holds seats nobody is going to pay for.
        if ('NOK' === mb_strtoupper((string) ($payload['Status'] ?? $payload['status'] ?? ''))) {
            return PaymentIntent::failed(__('payments.errors.cancelled_by_buyer'));
        }

        $response = $this->http()->postJson($this->base().'v4/payment/verify.json', [
            'merchant_id' => (string) $this->context->setting('merchant_id'),
            'amount' => $this->amount((int) $order->total_amount),
            'authority' => $authority,
        ]);

        $code = (int) $response->json('data.code', 0);

        // 100 is paid; 101 is "you already asked, and it was paid". Both are paid.
        if (100 === $code || 101 === $code) {
            return PaymentIntent::paid('zarinpal:'.(string) $response->json('data.ref_id', $authority));
        }

        return PaymentIntent::failed($this->reason($response->json()));
    }

    /**
     * Zarinpal does not give money back through this API.
     *
     * Refunding a Zarinpal payment is done from the merchant's own panel, against a settlement
     * that may not have reached the merchant's account yet, and the endpoint that exists for it is
     * gated on an agreement most venues do not have. Rather than call something that will answer
     * 404 in production and leave a booking half-refunded, this says plainly that the money is
     * owed in person — which is what actually happens at an Iranian box office — and the seats go
     * back on sale.
     *
     * Written as `unsupported` rather than `failed` deliberately: nothing went wrong, and a
     * refusal here would stop a refund that the organiser is perfectly able to complete.
     */
    public function refund(ExternalOrder $order, int $amount, string $reference): RefundOutcome
    {
        return RefundOutcome::unsupported(__('payments.errors.refund_by_hand'));
    }

    /* --------------------------------------------------------------------------- internals */

    private function http(): OutboundHttp
    {
        return new OutboundHttp($this->context);
    }

    private function base(): string
    {
        return $this->context->setting('sandbox') ? self::SANDBOX : self::LIVE;
    }

    /** Zarinpal is paid in rials. A venue that prices in tomans owes ten times the number. */
    private function amount(int $minorUnits): int
    {
        return 'toman' === $this->context->setting('amount_unit', 'rial')
            ? $minorUnits * 10
            : $minorUnits;
    }


    private function reason(mixed $body): string
    {
        $code = is_array($body) ? ($body['errors']['code'] ?? $body['data']['code'] ?? null) : null;

        return __('payments.errors.declined', [
            'gateway' => $this->label(),
            'code' => null === $code ? '—' : (string) $code,
        ]);
    }
}
