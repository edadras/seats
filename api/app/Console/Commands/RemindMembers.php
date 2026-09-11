<?php

namespace App\Console\Commands;

use App\Domain\Memberships\Memberships;
use App\Domain\Messaging\MessageDispatcher;
use App\Models\Membership;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Locale\Dates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Tell a Friend their membership is running out, while there is still time to renew.
 *
 * Three weeks, once. A renewal notice that arrives the day after somebody's presale privilege ended
 * is an apology rather than a reminder, and one that arrives every day for three weeks is why
 * people stop reading a venue's email.
 *
 * Nobody who has already renewed is written to: they hold a second row that runs past the one
 * ending, and telling them to do a thing they have done is how a scheme looks broken.
 */
class RemindMembers extends Command
{
    protected $signature = 'memberships:remind {--days=21 : How far ahead to look}';

    protected $description = 'Write to members whose membership is about to run out';

    public function handle(TenantContext $tenants, MessageDispatcher $messages): int
    {
        $days = max(1, (int) $this->option('days'));
        $written = 0;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $tenants->runAs($tenant, function () use ($tenant, $days, $messages, &$written) {
                $site = Site::where('status', 'live')->orderBy('created_at')->first();

                foreach (app(Memberships::class)->expiring($days) as $membership) {
                    /** @var Membership $membership */
                    if ($this->alreadyTold($membership)) {
                        continue;
                    }

                    $messages->send(
                        'membership.expiring',
                        'email',
                        $membership->email,
                        [
                            'buyer' => $membership->name ?: $membership->email,
                            'scheme' => (string) $membership->scheme?->name,
                            'ends' => Dates::longWhen($membership->ends_at, $tenant->locale),
                            'site' => $site?->name ?? $tenant->name,
                            // Where to renew: the site's own membership page if it has one, and
                            // otherwise the front door, which is better than a dead link.
                            'link' => $site?->url('/') ?? '',
                        ],
                        $tenant->locale,
                    );

                    $written++;
                }
            });
        }

        $this->info(sprintf('%d member(s) written to.', $written));

        return self::SUCCESS;
    }

    /**
     * Whether this membership has already had its notice.
     *
     * Read off the delivery log rather than a column: the log is where "did we write to them" is
     * answered everywhere else in this system, and a second source of truth for the same question
     * is a second thing to be wrong.
     */
    private function alreadyTold(Membership $membership): bool
    {
        return \App\Models\MessageDelivery::where('kind', 'membership.expiring')
            ->where('recipient', $membership->email)
            ->where('created_at', '>=', $membership->ends_at->copy()->subMonths(2))
            ->exists();
    }
}
