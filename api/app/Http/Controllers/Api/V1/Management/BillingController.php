<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Chargers;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BillingMethod;
use App\Models\PlatformInvoice;
use App\Models\Subscription;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * What this account owes the platform, and how it pays.
 *
 * Behind `account.manage` — the permission that already governs the account itself rather than any
 * money permission, because this is not the organiser's takings. It is what they pay for the
 * software, and the person who signed up for it is the person who should see it. A box office
 * manager who can refund a booking has no business seeing the account's card.
 *
 * Read-only about the figures: an invoice is raised by the platform and frozen, and nothing here
 * can change one. What an organiser can do is put a card on file, take it off, and say they would
 * rather be invoiced — which is a first-class answer and not the absence of one.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly Billing $billing,
        private readonly Chargers $chargers,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $tenant = $this->tenants->idOrFail();
        $subscription = Subscription::with('plan')->latest('created_at')->first();
        $method = $this->method($tenant);

        return response()->json([
            'plan' => $subscription?->plan ? [
                'key' => $subscription->plan->key,
                'name' => $subscription->plan->name,
                'price_amount' => (int) $subscription->plan->price_amount,
                'currency' => $subscription->plan->currency,
                'interval' => $subscription->plan->interval,
                'commission_rate' => (int) $subscription->plan->commission_rate,
            ] : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                // Said out loud rather than inferred from a list of unpaid invoices: an account in
                // trouble should not need arithmetic to find out.
                'past_due_since' => $subscription->past_due_since?->toIso8601String(),
            ] : null,
            'method' => $method ? [
                'kind' => $method->kind,
                'gateway' => $method->gateway,
                'brand' => $method->brand,
                'last4' => $method->last4,
                'exp_month' => $method->exp_month,
                'exp_year' => $method->exp_year,
                'expiring' => $method->isExpiring(),
                'billing_name' => $method->billing_name,
                'billing_email' => $method->billing_email,
                'billing_address' => $method->billing_address,
                'vat_number' => $method->vat_number,
            ] : null,
            // Whether this deployment can take a card at all. A button that cannot work is worse
            // than a sentence saying why there is no button.
            'takes_cards' => $this->chargers->takesCards(),
            'currency' => (string) config('seatmap.billing.currency', 'EUR'),
            'owed' => $this->billing->owed($tenant),
            'invoices' => $this->billing->invoicesFor($tenant),
        ]);
    }

    /**
     * Where to go to put a card on file.
     *
     * A URL at the gateway's own hosted page, never fields on ours: the platform has no more
     * business holding a card number than an organiser does. What comes back afterwards is two
     * opaque handles and four digits.
     */
    public function startSetup(Request $request)
    {
        $this->authorize($request, 'account.manage');

        if (! $this->chargers->takesCards()) {
            throw ApiException::unprocessable(
                'cards_not_taken',
                'This platform does not take cards. Invoices are paid by transfer.'
            );
        }

        $data = $request->validate([
            'return_url' => ['required', 'url', 'max:400'],
            'billing_name' => ['nullable', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
        ]);

        $method = $this->methodOrNew($this->tenants->idOrFail(), array_filter([
            'kind' => 'card',
            'billing_name' => $data['billing_name'] ?? null,
            'billing_email' => $data['billing_email'] ?? null,
        ]));

        $url = $this->chargers->current()->setupUrl($method, $data['return_url']);

        if (! $url) {
            throw ApiException::unprocessable(
                'card_setup_unavailable',
                'The card could not be set up just now. Try again shortly.'
            );
        }

        return response()->json(['url' => $url]);
    }

    /** They came back from the gateway's page; read what they set up and write down the handles. */
    public function finishSetup(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'session' => ['required', 'string', 'max:255'],
        ]);

        $tenant = $this->tenants->idOrFail();
        $method = $this->methodOrNew($tenant, ['kind' => 'card']);

        $fields = $this->chargers->current()->collectSetup($method, $data);

        if (! $fields) {
            throw ApiException::unprocessable(
                'card_setup_unfinished',
                'That card was not finished. Start again and complete the page at the end.'
            );
        }

        $method->forceFill($fields + ['kind' => 'card', 'is_default' => true])->save();

        $this->audit->record('billing.card_added', null, [
            'brand' => $method->brand,
            'last4' => $method->last4,
        ]);

        return response()->json([
            'kind' => 'card',
            'brand' => $method->brand,
            'last4' => $method->last4,
            'exp_month' => $method->exp_month,
            'exp_year' => $method->exp_year,
        ]);
    }

    /**
     * Take the card off, and say how the bills get paid instead.
     *
     * Not a delete of the row: the account still has a billing relationship, it has just chosen to
     * be invoiced. Leaving nothing behind would make an account that pays by transfer look like an
     * account that has not got round to adding a card, and it would be nagged for ever.
     */
    public function payByInvoice(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate([
            'billing_name' => ['nullable', 'string', 'max:190'],
            'billing_email' => ['nullable', 'email', 'max:190'],
            'billing_address' => ['nullable', 'string', 'max:600'],
            'vat_number' => ['nullable', 'string', 'max:40'],
        ]);

        $method = $this->methodOrNew($this->tenants->idOrFail(), ['kind' => 'invoice']);

        $method->forceFill($data + [
            'kind' => 'invoice',
            // The handles go. Keeping a payment method we have undertaken not to use is keeping
            // something we have no reason to hold.
            'customer_reference' => null,
            'method_reference' => null,
            'brand' => null,
            'last4' => null,
            'exp_month' => null,
            'exp_year' => null,
        ])->save();

        $this->audit->record('billing.pays_by_invoice', null, []);

        return response()->json(['kind' => 'invoice']);
    }

    /** One invoice, with its lines — the thing somebody forwards to their book-keeper. */
    public function invoice(Request $request, string $id)
    {
        $this->authorize($request, 'account.manage');

        $invoice = PlatformInvoice::where('tenant_id', $this->tenants->idOrFail())
            ->where('id', $id)
            ->first();

        if (! $invoice) {
            throw ApiException::notFound('That invoice cannot be found.', 'unknown_invoice');
        }

        return response()->json($this->billing->present($invoice) + [
            'vat_number' => (string) config('seatmap.billing.vat_number', ''),
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function method(string $tenantId): ?BillingMethod
    {
        return BillingMethod::where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->first();
    }

    private function methodOrNew(string $tenantId, array $attributes): BillingMethod
    {
        $method = $this->method($tenantId);

        if ($method) {
            $method->forceFill($attributes)->save();

            return $method;
        }

        return BillingMethod::create($attributes + [
            'tenant_id' => $tenantId,
            'kind' => 'invoice',
            'is_default' => true,
        ]);
    }
}
