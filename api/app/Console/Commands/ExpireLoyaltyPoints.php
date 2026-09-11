<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\Loyalty;
use App\Models\LoyaltyProgramme;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Points that have gone quiet for as long as the scheme said they could.
 *
 * Once a day, which is as fine as "two years of silence" needs. The whole balance goes at once
 * rather than point by point: "use it or lose it" is a rule somebody can check against their own
 * receipts, and first-in-first-out expiry of individual points is a rule nobody can — a scheme
 * whose arithmetic a customer cannot reproduce is a scheme they write to the box office about.
 *
 * Accounts that set no expiry are skipped entirely, because "they never expire" is a promise an
 * organiser may have made deliberately and this command is not the place to go back on it.
 */
class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire';

    protected $description = 'Empty points balances that have gone quiet for longer than their scheme allows';

    public function handle(TenantContext $tenants, Loyalty $loyalty): int
    {
        $emptied = 0;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $emptied += $tenants->runAs($tenant, function () use ($loyalty) {
                $programme = LoyaltyProgramme::first();

                if (! $programme || ! $programme->isLive() || ! $programme->inactive_months) {
                    return 0;
                }

                return $loyalty->expireQuiet($programme);
            });
        }

        $this->info($emptied
            ? "Emptied {$emptied} quiet balance(s)."
            : 'Nothing had gone quiet.');

        return self::SUCCESS;
    }
}
