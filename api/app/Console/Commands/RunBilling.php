<?php

namespace App\Console\Commands;

use App\Domain\Billing\Billing;
use App\Domain\Billing\Dunning;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Raise what is owed, then try to collect it.
 *
 * Daily rather than monthly, and that is the point: an account's period ends on the day it signed
 * up, so on any given day some accounts are due and most are not. A monthly run would invoice
 * everybody on the first for periods that ended on the eleventh.
 *
 * Both halves are safe to run twice. Raising is held to one invoice per period by a constraint in
 * the database, and collecting is idempotent at the gateway; a redelivered job, a second worker and
 * an operator pressing the button are all the same run.
 */
class RunBilling extends Command
{
    protected $signature = 'billing:run {--tenant= : Only this account, by id or slug}';

    protected $description = 'Raise the platform’s invoices for finished periods and try to collect them';

    public function handle(Billing $billing, Dunning $dunning): int
    {
        $raised = 0;

        foreach ($this->accounts() as $tenant) {
            $raised += count($billing->catchUp($tenant));
        }

        $collected = $dunning->run();

        $this->info(sprintf(
            '%d invoice(s) raised. %d tried: %d paid, %d awaiting transfer, %d refused.',
            $raised,
            $collected['tried'],
            $collected['paid'],
            $collected['waiting'],
            $collected['failed'],
        ));

        // Named rather than acted on: whoever runs the platform decides what happens to an account
        // that has not paid, and a scheduled command is not that person.
        $overdue = $dunning->overdueAccounts();

        if ($overdue) {
            $this->warn(sprintf(
                '%d account(s) past due: %s',
                count($overdue),
                implode(', ', array_map(fn (Tenant $tenant) => $tenant->slug, $overdue)),
            ));
        }

        return self::SUCCESS;
    }

    /** @return iterable<Tenant> */
    private function accounts(): iterable
    {
        $only = (string) ($this->option('tenant') ?: '');

        if ('' !== $only) {
            $tenant = Tenant::where('id', $only)->orWhere('slug', $only)->first();

            return $tenant ? [$tenant] : [];
        }

        // Suspended accounts are still invoiced for the period they used before they were
        // suspended: the software was theirs to use, and a bill that vanishes when an account is
        // switched off is a bill nobody can reconcile.
        return Tenant::whereIn('status', ['active', 'suspended'])->cursor();
    }
}
