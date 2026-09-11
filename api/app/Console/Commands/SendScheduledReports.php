<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportSchedules;
use Illuminate\Console\Command;

/**
 * Send the reports that are due.
 *
 * Hourly, because an hour is as fine as a schedule goes: a report is read by a person with a cup of
 * tea, and one that could fire every ten minutes is not a report, it is an alert. Each schedule
 * knows the timezone it was made in, so "eight in the morning" means eight where the venue is and
 * keeps meaning it through a clock change.
 *
 * A schedule that fails is written down against itself and the run carries on — one report whose
 * module has been switched off must not stop the other four hundred.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Send saved reports that are due, to the people who asked for them';

    public function handle(ReportSchedules $schedules): int
    {
        $result = $schedules->run();

        $this->info($result['schedules']
            ? sprintf('%d schedule(s) due, %d message(s) sent.', $result['schedules'], $result['sent'])
            : 'Nothing due.');

        return self::SUCCESS;
    }
}
