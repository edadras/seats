<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Dunning;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\PlatformInvoice;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The platform's own invoices, from the console.
 *
 * Support may look. Only an operator may say an invoice has been paid, void one, or make the
 * platform try a card again — all three change what somebody owes, and a UI that hid the buttons
 * would not stop a request.
 *
 * Marking paid is the ordinary case rather than the exception: most accounts on most deployments
 * pay by transfer, and somebody reconciling a bank statement is exactly who this endpoint is for.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly Billing $billing,
        private readonly Dunning $dunning,
    ) {}

    /** Every invoice, newest first, or one account's. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'tenant' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:open,paid,uncollectible,void'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $invoices = PlatformInvoice::with('tenant')
            ->when($data['tenant'] ?? null, fn ($query, $tenant) => $query->where('tenant_id', $tenant))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json([
            'data' => $invoices->map(fn (PlatformInvoice $invoice) => $this->billing->present($invoice) + [
                'tenant' => [
                    'id' => $invoice->tenant_id,
                    'name' => $invoice->tenant?->name,
                    'slug' => $invoice->tenant?->slug,
                ],
            ])->values(),
            // What this deployment is owed in total, which is the number somebody running it wants
            // before any of the rows.
            'outstanding' => (int) PlatformInvoice::whereIn('status', ['open', 'uncollectible'])->sum('total'),
            'currency' => (string) config('seatmap.billing.currency', 'EUR'),
            // Named rather than acted on. Suspending an account is a person's decision.
            'past_due' => array_map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ], $this->dunning->overdueAccounts()),
        ]);
    }

    /** Raise whatever this account owes now, rather than waiting for tomorrow's run. */
    public function raise(Request $request, string $tenantId)
    {
        $admin = $this->operator($request);
        $tenant = Tenant::findOr($tenantId, fn () => throw ApiException::notFound('Unknown organiser.', 'unknown_tenant'));

        $made = $this->billing->catchUp($tenant);

        PlatformAuditLog::write($request->user()?->id, 'billing.raised', $tenant->id, [
            'invoices' => array_map(fn (PlatformInvoice $invoice) => $invoice->number, $made),
        ], $request->ip());

        return response()->json([
            'data' => array_map(fn (PlatformInvoice $invoice) => $this->billing->present($invoice), $made),
        ], 201);
    }

    /** The money arrived, by whatever route. */
    public function markPaid(Request $request, string $id)
    {
        $admin = $this->operator($request);
        $invoice = $this->invoice($id);

        $data = $request->validate([
            'method' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:190'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $invoice = $this->billing->markPaid($invoice, $data + ['settled_by' => $admin->id]);

        PlatformAuditLog::write($request->user()?->id, 'billing.marked_paid', $invoice->tenant_id, [
            'invoice' => $invoice->number,
            'total' => $invoice->total,
            'reference' => $invoice->reference,
        ], $request->ip());

        return response()->json($this->billing->present($invoice));
    }

    /** Try the card again now. */
    public function retry(Request $request, string $id)
    {
        $this->operator($request);
        $invoice = $this->invoice($id);

        $invoice = $this->dunning->collect($invoice);

        PlatformAuditLog::write($request->user()?->id, 'billing.retried', $invoice->tenant_id, [
            'invoice' => $invoice->number,
            'status' => $invoice->status,
        ], $request->ip());

        return response()->json($this->billing->present($invoice));
    }

    /**
     * Raised in error.
     *
     * The reason is required: an invoice number that was quoted to somebody and then disappeared is
     * a question a person has to answer from memory months later.
     */
    public function void(Request $request, string $id)
    {
        $this->operator($request);
        $invoice = $this->invoice($id);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $invoice = $this->billing->void($invoice, $data['reason']);

        PlatformAuditLog::write($request->user()?->id, 'billing.voided', $invoice->tenant_id, [
            'invoice' => $invoice->number,
            'reason' => $data['reason'],
        ], $request->ip());

        return response()->json($this->billing->present($invoice));
    }

    /* --------------------------------------------------------------------------- helpers */

    private function invoice(string $id): PlatformInvoice
    {
        return PlatformInvoice::findOr($id, fn () => throw ApiException::notFound(
            'That invoice cannot be found.',
            'unknown_invoice'
        ));
    }

    private function operator(Request $request): PlatformAdmin
    {
        $admin = $request->attributes->get('platform_admin');

        if (! $admin instanceof PlatformAdmin || ! $admin->mayChange()) {
            throw ApiException::denied('support_may_not_change', 'Support accounts can look, not change.');
        }

        return $admin;
    }
}
