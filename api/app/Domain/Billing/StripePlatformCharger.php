<?php

namespace App\Domain\Billing;

use App\Models\BillingMethod;
use App\Models\PlatformInvoice;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A card on file, charged through the platform's own Stripe account.
 *
 * Two separate things, and both hosted at Stripe's end rather than ours:
 *
 * A **setup** is a Checkout Session in `setup` mode. The organiser goes to Stripe's page, types
 * their card there, and comes back with a session id; reading that session back gives a customer
 * and a payment method, which is all that is stored. No card number touches this server, exactly as
 * with an organiser's own checkout — the platform has no more business holding one than they do.
 *
 * A **charge** is a PaymentIntent created off-session against that saved method, confirmed in the
 * same call. Off-session is the whole difficulty: nobody is at a keyboard, so a card whose bank
 * wants a challenge simply refuses, and the honest response is to write to the organiser rather
 * than to retry the same refusal four times. Stripe says which of those it is, and this reads it.
 *
 * The idempotency key is the invoice. A dunning retry, a redelivered job and an operator pressing
 * the button are the same charge, and none of them may take the money twice.
 */
class StripePlatformCharger implements PlatformCharger
{
    private const BASE = 'https://api.stripe.com/v1/';

    private const TIMEOUT_SECONDS = 15;

    public function key(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->secret();
    }

    public function setupUrl(BillingMethod $method, string $returnUrl): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $body = [
            'mode' => 'setup',
            'success_url' => $returnUrl.'?session={CHECKOUT_SESSION_ID}',
            'cancel_url' => $returnUrl.'?cancelled=1',
            'client_reference_id' => $method->tenant_id,
            'metadata[tenant]' => $method->tenant_id,
        ];

        // Reuse the customer we already know, so an account that replaces its card does not end up
        // as two customers at Stripe with one invoice history each.
        if ($method->customer_reference) {
            $body['customer'] = $method->customer_reference;
        } elseif ($method->billing_email) {
            $body['customer_email'] = $method->billing_email;
        }

        $response = $this->post('checkout/sessions', $body);

        $url = (string) $response->json('url', '');

        return $response->successful() && '' !== $url ? $url : null;
    }

    public function collectSetup(BillingMethod $method, array $payload): ?array
    {
        $session = (string) ($payload['session'] ?? '');

        if (! $this->isConfigured() || '' === $session) {
            return null;
        }

        $read = $this->get('checkout/sessions/'.$session);

        if (! $read->successful()) {
            return null;
        }

        $customer = (string) $read->json('customer', '');
        $intent = (string) $read->json('setup_intent', '');

        if ('' === $intent) {
            return null;
        }

        $setup = $this->get('setup_intents/'.$intent);
        $paymentMethod = (string) $setup->json('payment_method', '');

        if (! $setup->successful() || '' === $paymentMethod) {
            return null;
        }

        $card = $this->get('payment_methods/'.$paymentMethod);

        /*
         * Made the default for future charges at Stripe's end as well as ours.
         *
         * Without it an off-session PaymentIntent that names no method falls back to whatever
         * Stripe thinks the customer's default is, which after a card is replaced is the old one.
         */
        if ('' !== $customer) {
            $this->post('customers/'.$customer, [
                'invoice_settings[default_payment_method]' => $paymentMethod,
            ]);
        }

        return [
            'gateway' => 'stripe',
            'customer_reference' => '' === $customer ? null : $customer,
            'method_reference' => $paymentMethod,
            'brand' => $card->json('card.brand'),
            'last4' => $card->json('card.last4'),
            'exp_month' => $card->json('card.exp_month'),
            'exp_year' => $card->json('card.exp_year'),
        ];
    }

    public function charge(PlatformInvoice $invoice, BillingMethod $method): ChargeOutcome
    {
        if (! $this->isConfigured() || ! $method->isCard()) {
            return ChargeOutcome::unsupported(__('billing.errors.no_card_on_file'));
        }

        $response = $this->post('payment_intents', array_filter([
            'amount' => $invoice->total,
            'currency' => mb_strtolower((string) $invoice->currency),
            'customer' => $method->customer_reference,
            'payment_method' => $method->method_reference,
            'off_session' => 'true',
            'confirm' => 'true',
            'description' => __('billing.invoiceDescription', ['number' => $invoice->number]),
            'metadata[invoice]' => $invoice->number,
            'metadata[tenant]' => $invoice->tenant_id,
        ], fn ($value) => null !== $value && '' !== $value), [
            'Idempotency-Key' => 'platform-invoice-'.$invoice->id,
        ]);

        $status = (string) $response->json('status', '');

        if ('succeeded' === $status) {
            return ChargeOutcome::paid('stripe:'.(string) $response->json('id', ''));
        }

        /*
         * The bank wants the cardholder, and the cardholder is not here.
         *
         * Reported as its own sentence rather than as a decline, because the two need different
         * things from the organiser: a decline is "this card has no money on it", and this is
         * "come and approve it". Retrying it four times on a ladder would achieve nothing.
         */
        if ('requires_action' === $status
            || 'authentication_required' === (string) $response->json('error.code', '')) {
            return ChargeOutcome::failed(__('billing.errors.needs_the_cardholder'));
        }

        return ChargeOutcome::failed($this->reason($response));
    }

    /* --------------------------------------------------------------------------- internals */

    private function reason(Response $response): string
    {
        $message = (string) $response->json('error.message', '');
        $code = (string) $response->json('error.decline_code', $response->json('error.code', ''));

        // Stripe's own sentence when there is one: "your card has insufficient funds" is more use
        // to the person reading it than anything this platform could write about a code.
        if ('' !== $message) {
            return $message;
        }

        return __('billing.errors.card_refused', ['code' => '' === $code ? '—' : $code]);
    }

    private function secret(): string
    {
        return (string) config('seatmap.billing.stripe.secret_key', '');
    }

    private function post(string $path, array $body, array $headers = []): Response
    {
        return $this->client($headers)->asForm()->post(self::BASE.$path, $body);
    }

    private function get(string $path): Response
    {
        return $this->client()->get(self::BASE.$path);
    }

    private function client(array $headers = [])
    {
        return Http::withHeaders($headers + [
            'Authorization' => 'Bearer '.$this->secret(),
            'Stripe-Version' => '2024-06-20',
            'Accept' => 'application/json',
        ])
            ->timeout(self::TIMEOUT_SECONDS)
            // One retry, and only for a connection-level failure: a gateway that answered "no"
            // answered, and asking again is how somebody gets charged twice.
            ->retry(2, 500, throw: false);
    }
}
