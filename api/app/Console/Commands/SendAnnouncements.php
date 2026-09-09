<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Announcements\AnnouncementSender;
use App\Models\Announcement;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Finish the announcements that are still going out.
 *
 * The request that pressed send does the first batch, so a small announcement is done by the time
 * the screen comes back; anything bigger is finished here, a bounded number of messages per pass.
 * Same method either way — two ways of sending one announcement is how two of them start
 * disagreeing about who has been written to.
 */
class SendAnnouncements extends Command
{
    protected $signature = 'messages:announce {--batches=8 : Batches to send per account, per run}';

    protected $description = 'Send the next messages of any announcement still going out';

    public function handle(TenantContext $tenants, AnnouncementSender $sender): int
    {
        $batches = max(1, (int) $this->option('batches'));
        $sent = 0;

        foreach (Tenant::all() as $tenant) {
            $tenants->runAs($tenant, function () use ($sender, $batches, &$sent) {
                foreach (Announcement::where('status', 'sending')->get() as $announcement) {
                    for ($pass = 0; $pass < $batches; $pass++) {
                        $attempted = $sender->sendBatch($announcement);
                        $sent += $attempted;

                        if (0 === $attempted) {
                            break;
                        }
                    }
                }
            });
        }

        $this->info($sent ? "Sent {$sent} announcement messages." : 'Nothing waiting.');

        return self::SUCCESS;
    }
}
