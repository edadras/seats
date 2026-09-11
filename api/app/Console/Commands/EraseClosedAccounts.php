<?php

namespace App\Console\Commands;

use App\Domain\Accounts\AccountClosure;
use App\Domain\Accounts\AccountExporter;
use Illuminate\Console\Command;

/**
 * Accounts that were closed long enough ago, and archives nobody fetched.
 *
 * The window between closing and erasing is the only thing that makes closing survivable: an
 * organiser who mis-clicked has until this command comes round to ask for the account back. After
 * it, there is nothing to ask for, which is the other half of the same promise — "take your data
 * and leave" is not kept by a platform that quietly keeps a copy for ever.
 *
 * Both jobs in one command because they are one clock. An archive outliving the account it
 * describes would be a copy of a venue's whole history sitting in storage belonging to nobody.
 */
class EraseClosedAccounts extends Command
{
    protected $signature = 'accounts:erase';

    protected $description = 'Erase accounts whose closing window has run out, and sweep expired archives';

    public function handle(AccountClosure $closure, AccountExporter $exports): int
    {
        $erased = 0;

        foreach ($closure->dueForErasure() as $tenant) {
            $this->line(sprintf('  %s (closed %s)', $tenant->name, $tenant->closed_at?->toDateString()));

            $closure->erase($tenant);
            $erased++;
        }

        $swept = $exports->sweep();

        $this->info(sprintf(
            '%d account(s) erased, %d archive(s) swept.',
            $erased,
            $swept,
        ));

        return self::SUCCESS;
    }
}
