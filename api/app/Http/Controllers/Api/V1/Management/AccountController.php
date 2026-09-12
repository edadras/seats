<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Accounts\AccountClosure;
use App\Domain\Accounts\AccountExporter;
use App\Http\Controllers\Controller;
use App\Models\AccountExport;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Taking an account's data, and closing the account.
 *
 * Both behind `account.manage`, which is the permission that already covers the things only an
 * owner should do. Reading the list of archives is behind it too: an archive is every buyer this
 * venue has ever had, and a colleague who may not see the billing screen has no business fetching
 * one.
 *
 * The two live on one controller because they are one act with a pause in the middle. An organiser
 * leaving takes their history and then shuts the door, and a platform that made those two separate
 * errands would be a platform where somebody shuts the door first.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountExporter $exporter,
        private readonly AccountClosure $closure,
    ) {}

    /**
     * Which calendar this account's staff read and type dates in.
     *
     * Its own endpoint rather than part of a settings screen, because there is no account settings
     * screen and this does not want one: the control sits beside the language picker in the panel's
     * own footer, which is where somebody already goes to change how the panel reads to them.
     *
     * `account.manage`, because it is the venue's decision rather than the reader's. A box-office
     * clerk should not be able to move the whole organisation's dates, and a colleague who prefers
     * another language already has their own setting for that.
     */
    public function calendar(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate(['calendar' => ['required', 'string', 'max:16']]);

        $tenant = app(\App\Support\Tenancy\TenantContext::class)->get();

        // Anything unrecognised becomes `auto` rather than a refusal: the worst outcome is dates in
        // the reader's own calendar, which is where they started.
        $tenant->calendar = \App\Support\Locale\Calendars::clean($data['calendar']) ?? 'auto';

        app(\App\Support\Audit\AuditLogger::class)
            ->record('account.calendar_set', $tenant, ['calendar' => $tenant->calendar]);

        $tenant->save();

        return response()->json(['calendar' => $tenant->calendar]);
    }

    public function exports(Request $request)
    {
        $this->authorize($request, 'account.manage');

        return response()->json([
            'data' => AccountExport::with('requester:id,name')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(fn (AccountExport $export) => $this->present($export))
                ->values(),
            'keeps_days' => $this->exporter->days(),
        ]);
    }

    /**
     * Build one, now.
     *
     * Rate limited at the route rather than here: an archive is expensive to make and cheap to ask
     * for, and the honest place to say "not that often" is in front of the work.
     */
    public function export(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $export = $this->exporter->build(
            $this->tenantOf($request),
            $request->user()?->id,
        );

        return response()->json($this->present($export), 'ready' === $export->status ? 201 : 202);
    }

    public function closure(Request $request)
    {
        $this->authorize($request, 'account.manage');

        return response()->json($this->closure->standing($this->tenantOf($request)));
    }

    /**
     * Close it.
     *
     * The confirmation is the account's own name, typed. Not a checkbox: this is the one action on
     * the platform that a mis-click should not be able to complete, and a word somebody has to
     * read off the screen and write is the cheapest way to be sure they meant it.
     */
    public function close(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $tenant = $this->tenantOf($request);

        $data = $request->validate([
            'confirm' => ['required', 'string', 'max:200'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if (mb_strtolower(trim($data['confirm'])) !== mb_strtolower(trim((string) $tenant->name))) {
            throw \App\Exceptions\ApiException::unprocessable(
                'closure_not_confirmed',
                'Type the account’s name exactly as it appears to confirm.'
            );
        }

        $closed = $this->closure->close($tenant, $data['reason'] ?? null, $request->user()?->id);

        return response()->json($this->closure->standing($closed) + [
            // Handed back with the closure, because after this response nobody from this account
            // can sign in to come and look for it.
            'export' => $this->latestReady($closed),
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function tenantOf(Request $request): Tenant
    {
        return $request->attributes->get('tenant')
            ?? Tenant::findOrFail(app(\App\Support\Tenancy\TenantContext::class)->idOrFail());
    }

    private function latestReady(Tenant $tenant): ?array
    {
        $export = app(\App\Support\Tenancy\TenantContext::class)->runAs(
            $tenant,
            fn () => AccountExport::where('status', 'ready')->orderByDesc('created_at')->first()
        );

        return $export ? $this->present($export) : null;
    }

    private function present(AccountExport $export): array
    {
        return [
            'id' => $export->id,
            'status' => $export->status,
            'bytes' => (int) $export->bytes,
            'contents' => $export->contents ?? [],
            'error' => $export->error,
            'requested_by' => $export->requester?->name,
            'created_at' => $export->created_at?->toIso8601String(),
            'ready_at' => $export->ready_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            /*
             * A signed link rather than an authenticated one.
             *
             * It has to keep working after the account is closed — which is exactly when it is
             * needed — and nobody can sign in to a closed account. The signature carries the whole
             * of the authorisation and expires with the file it points at.
             */
            'link' => $export->isReady()
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'account.export.download',
                    $export->expires_at ?? now()->addDays($this->exporter->days()),
                    ['export' => $export->id],
                )
                : null,
        ];
    }
}
