<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Settlement\Payouts;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Paying organisers, from the platform's own console.
 *
 * Here rather than in the panel because of who does it. The settlement report is the organiser's —
 * it answers what they are owed — but the payout is the platform's side of the same sentence, and
 * an account that could record its own payouts could record one that never happened. Support may
 * look; only an operator may send money or undo it.
 *
 * The organiser sees every payout on their own settlement screen, read-only, through
 * `SettlementController::payouts`. Nothing here is hidden from them: it is their money.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly Payouts $payouts) {}

    /** Every payout for one organiser, newest period first. */
    public function index(Request $request, string $tenantId)
    {
        $tenant = $this->tenant($tenantId);

        return response()->json([
            'data' => $this->payouts->forTenant($tenant),
            'next_from' => $this->payouts->nextFrom($tenant),
        ]);
    }

    /**
     * What settling this window would pay — before anything is written.
     *
     * The same figures the payout will freeze, so an operator about to send money is looking at
     * what is about to be recorded rather than at a report they filtered separately.
     */
    public function preview(Request $request, string $tenantId)
    {
        $tenant = $this->tenant($tenantId);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return response()->json($this->payouts->preview($tenant, $data['from'], $data['to']));
    }

    /** Settle the period: freeze the figures, one payout per currency. */
    public function store(Request $request, string $tenantId)
    {
        $admin = $this->operator($request);
        $tenant = $this->tenant($tenantId);

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:190'],
            'method' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:2000'],
            // Given when the money has already left, so the payout is written as paid rather than
            // recorded and then marked a second time.
            'paid_at' => ['nullable', 'date'],
        ]);

        $made = $this->payouts->settle($tenant, $data['from'], $data['to'], $data, $admin);

        PlatformAuditLog::write($request->user()?->id, 'payout.settled', $tenant->id, [
            'from' => $data['from'],
            'to' => $data['to'],
            'payouts' => array_map(fn (Payout $payout) => [
                'id' => $payout->id,
                'currency' => $payout->currency,
                'payable' => $payout->payable,
            ], $made),
        ], $request->ip());

        return response()->json([
            'data' => array_map(fn (Payout $payout) => $this->payouts->present($payout), $made),
        ], 201);
    }

    /** The money has left. */
    public function markPaid(Request $request, string $payoutId)
    {
        $admin = $this->operator($request);
        $payout = $this->payout($payoutId);

        $data = $request->validate([
            'reference' => ['nullable', 'string', 'max:190'],
            'method' => ['nullable', 'string', 'max:40'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $payout = $this->payouts->markPaid($payout, $data);

        PlatformAuditLog::write($request->user()?->id, 'payout.paid', $payout->tenant_id, [
            'payout' => $payout->id,
            'currency' => $payout->currency,
            'payable' => $payout->payable,
            'reference' => $payout->reference,
        ], $request->ip());

        return response()->json($this->payouts->present($payout));
    }

    /**
     * Undo one, and free its days.
     *
     * The reason is required rather than optional, for the same reason a declined refund's is: a
     * period reopened with no explanation is a question somebody has to answer from memory months
     * later.
     */
    public function void(Request $request, string $payoutId)
    {
        $admin = $this->operator($request);
        $payout = $this->payout($payoutId);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $payout = $this->payouts->void($payout, $data['reason'], $admin);

        PlatformAuditLog::write($request->user()?->id, 'payout.voided', $payout->tenant_id, [
            'payout' => $payout->id,
            'currency' => $payout->currency,
            'payable' => $payout->payable,
            'reason' => $data['reason'],
        ], $request->ip());

        return response()->json($this->payouts->present($payout));
    }

    /* --------------------------------------------------------------------------- helpers */

    private function tenant(string $id): Tenant
    {
        return Tenant::findOr($id, fn () => throw ApiException::notFound('Unknown organiser.', 'unknown_tenant'));
    }

    private function payout(string $id): Payout
    {
        return Payout::findOr($id, fn () => throw ApiException::notFound('Unknown payout.', 'unknown_payout'));
    }

    /**
     * Sending money is not something support does.
     *
     * Enforced here rather than by hiding a button: a UI that hides one does not stop a request,
     * and this is the request that moves money out of the platform.
     */
    private function operator(Request $request): PlatformAdmin
    {
        $admin = $request->attributes->get('platform_admin');

        if (! $admin instanceof PlatformAdmin || ! $admin->mayChange()) {
            throw ApiException::denied('support_may_not_change', 'Support accounts can look, not change.');
        }

        return $admin;
    }
}
