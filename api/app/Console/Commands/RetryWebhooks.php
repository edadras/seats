<?php

namespace App\Console\Commands;

use App\Domain\Webhooks\Webhooks;
use Illuminate\Console\Command;

/**
 * Pick up the deliveries the queue lost, and throw away the log nobody will read.
 *
 * Every retry in this subsystem is a delayed job, and a delayed job lives in the queue rather than
 * in the database. A worker restarted at the wrong second, a Redis flushed by an operator, and a
 * delivery sits `pending` with its moment in the past for ever — with the receiving shop never told
 * about a refund and nothing anywhere saying so. The table is the record of what is owed; this is
 * what makes that true.
 */
class RetryWebhooks extends Command
{
    protected $signature = 'webhooks:retry {--limit=500 : How many to pick up in one pass}';

    protected $description = 'Re-queue webhook deliveries whose retry was lost, and prune the log';

    public function handle(Webhooks $webhooks): int
    {
        $picked = $webhooks->sweep(max(1, (int) $this->option('limit')));
        $pruned = $webhooks->prune();

        $this->info(sprintf('%d delivery(s) re-queued, %d pruned from the log.', $picked, $pruned));

        return self::SUCCESS;
    }
}
