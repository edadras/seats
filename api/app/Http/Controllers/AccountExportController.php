<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountExporter;
use App\Models\AccountExport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The archive behind an account export, fetched from a signed link.
 *
 * Signed rather than signed in, and that is the whole point of it: the link has to keep working
 * after the account is closed, which is precisely when somebody needs it, and nobody can sign in to
 * a closed account. So the signature carries the authorisation, and it expires on the same instant
 * the file does — one clock for one promise.
 *
 * A wrong or stale signature is a 404 rather than a refusal. "Wrong signature" would confirm that
 * the archive exists, and what is inside it is every buyer a venue has ever had.
 */
class AccountExportController extends Controller
{
    public function __construct(
        private readonly AccountExporter $exports,
        private readonly TenantContext $tenants,
    ) {}

    public function __invoke(Request $request, string $export): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 404);

        // Unscoped on purpose and narrow on purpose: nobody is signed in, so there is no account to
        // scope by, and the id came out of a signature this platform wrote itself.
        $row = $this->tenants->runUnscoped(
            fn () => AccountExport::withoutGlobalScopes()->find($export)
        );

        abort_unless($row instanceof AccountExport, 404);

        $file = $this->exports->fileFor($row);

        abort_unless($file, 404);

        $tenant = $this->tenants->runUnscoped(
            fn () => \App\Models\Tenant::withTrashed()->find($row->tenant_id)
        );

        // Deliberately no check that the account is still open. A closed account's own history is
        // the one thing its organiser is most entitled to, and refusing here would make the
        // platform's promise depend on the organiser not having taken it up.
        $name = Str::slug((string) ($tenant->name ?? 'account')) ?: 'account';

        return response()->download($file, $name.'-'.$row->created_at->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }
}
