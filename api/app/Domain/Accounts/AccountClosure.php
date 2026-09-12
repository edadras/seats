<?php

namespace App\Domain\Accounts;

use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\ApiClient;
use App\Models\Event;
use App\Models\PersonalAccessToken;
use App\Models\ReportSchedule;
use App\Domain\Sites\SiteResolver;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Closing an account, and eventually erasing it.
 *
 * A platform nobody can leave is a platform nobody should arrive at, so this exists. What makes it
 * more than a status column is the one thing an organiser cannot be allowed to do on their way out:
 * disappear while strangers are holding tickets for nights that have not happened.
 *
 * So closing is refused while any future night still has a live ticket on it. That is not an
 * obstacle course — the organiser can cancel those nights, which refunds them through the ordinary
 * path, and then close. It is the difference between leaving and vanishing with the takings.
 *
 * After that the account stops selling immediately and stays recoverable for a stated window. Both
 * halves are deliberate. Immediate, because somebody closing an account means it now, and a site
 * still taking money for a fortnight would be a shop with nobody behind it. Recoverable, because a
 * mis-click is a mis-click, and until {@see erase()} runs there is still something to give back.
 *
 * Erasure is a `DELETE` of one row. Every table on this platform carries `tenant_id` with
 * `ON DELETE CASCADE`, which was true before this feature existed and is what makes the promise
 * something the database keeps rather than something a loop hopes it covered.
 */
class AccountClosure
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly AccountExporter $exports,
    ) {}

    /** How long a closed account waits before it is gone. */
    public function keepDays(): int
    {
        return max(1, (int) config('seatmap.accounts.keep_days', 30));
    }

    /**
     * What stands between this account and the door.
     *
     * Read before the button is offered, so the screen can say "seven nights have tickets on them"
     * rather than refusing after somebody has typed their account's name to confirm.
     */
    public function standing(Tenant $tenant): array
    {
        return $this->tenants->runAs($tenant, function () use ($tenant) {
            $nights = $this->futureNightsWithTickets();

            return [
                'status' => $tenant->status,
                'closed_at' => $tenant->closed_at?->toIso8601String(),
                'erase_after' => $tenant->erase_after?->toIso8601String(),
                'keep_days' => $this->keepDays(),
                'can_close' => 0 === $nights->count(),
                'nights' => $nights->map(fn ($night) => [
                    'id' => $night->id,
                    'name' => $night->name,
                    'starts_at' => $night->starts_at?->toIso8601String(),
                    'tickets' => (int) $night->live_tickets,
                ])->values()->all(),
                // Not a refusal, and said anyway: an organiser leaving should know whether the
                // platform is going to invoice them for the days they have already used.
                'owed' => $this->owed($tenant),
            ];
        });
    }

    /**
     * Close the account.
     *
     * Everything switched off here is switched off because leaving it on would be a promise the
     * account can no longer keep: a site that sells, a key that works, a report that arrives by
     * email every Monday to an organiser who has gone.
     */
    public function close(Tenant $tenant, ?string $reason = null, ?string $userId = null): Tenant
    {
        if ('cancelled' === $tenant->status && $tenant->closed_at) {
            return $tenant;
        }

        $this->tenants->runAs($tenant, function () use ($tenant, $reason, $userId) {
            $nights = $this->futureNightsWithTickets();

            if ($nights->count() > 0) {
                throw ApiException::conflict(
                    'closure_has_live_tickets',
                    'People are holding tickets for nights that have not happened. Cancel those nights first — that refunds them — and then close the account.',
                    [
                        'nights' => $nights->count(),
                        'tickets' => (int) $nights->sum('live_tickets'),
                    ],
                );
            }

            DB::transaction(function () use ($tenant, $reason, $userId) {
                $tenant->forceFill([
                    'status' => 'cancelled',
                    'closed_at' => now(),
                    'close_reason' => $reason ? mb_substr($reason, 0, 200) : null,
                    'erase_after' => now()->addDays($this->keepDays()),
                ])->save();

                // A shop with nobody behind it is worse than no shop: the sites come down in the
                // same breath, not when somebody remembers.
                Site::query()->update(['status' => 'suspended']);

                /*
                 * And they come down now rather than in five minutes.
                 *
                 * Host-to-site resolution is cached, so a status column changed on its own would
                 * leave every already-resolved address selling tickets until the cache expired.
                 * That is the difference between an account that closed and an account that will
                 * close shortly, and only one of them is what somebody pressed the button for.
                 */
                foreach (SiteDomain::query()->pluck('hostname') as $hostname) {
                    SiteResolver::forget($hostname);
                }
                ApiClient::query()->update(['status' => 'disabled']);
                ReportSchedule::query()->update(['paused' => true]);

                Subscription::query()->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                /*
                 * Every session, everywhere.
                 *
                 * Not for the organiser's sake — they asked for this — but because a colleague's
                 * laptop in a foyer somewhere is still signed in to an account that is supposed to
                 * be shut, and "shut" has to mean it on every screen at once.
                 */
                $this->revokeTokens($tenant);
            });

            $this->audit->record('account.closed', $tenant, [
                'reason' => $reason,
                'erase_after' => $tenant->erase_after?->toIso8601String(),
                'by' => $userId,
            ]);
        });

        return $tenant->fresh();
    }

    /**
     * Open it again, inside the window.
     *
     * Only the platform can do this, for the obvious reason: nobody can sign in to a closed
     * account, which is the point of closing one. The sites stay down — they are put back up
     * deliberately, by somebody who has decided to sell again, rather than by a status flipping.
     */
    public function reopen(Tenant $tenant): Tenant
    {
        $tenant->forceFill([
            'status' => 'active',
            'closed_at' => null,
            'close_reason' => null,
            'erase_after' => null,
        ])->save();

        $this->tenants->runAs($tenant, fn () => $this->audit->record('account.reopened', $tenant, []));

        return $tenant->fresh();
    }

    /**
     * Erase the account.
     *
     * One `DELETE`, and then the files. The row goes first: if the archives were removed and the
     * delete then failed, an organiser would be left with an account whose export they can no
     * longer fetch, which is the one state worse than either.
     */
    public function erase(Tenant $tenant): void
    {
        $id = $tenant->id;

        DB::transaction(function () use ($id) {
            /*
             * Who worked here, read before the account goes and deleted after it.
             *
             * Before, because the membership rows that say so cascade away with the account and
             * there would be nothing left to ask. After, because a person is only nobody's
             * colleague once this account is gone.
             */
            $staff = DB::table('tenant_users')->where('tenant_id', $id)->pluck('user_id');

            DB::table('tenants')->where('id', $id)->delete();

            $this->forgetStaff($staff);
        });

        $directory = storage_path('app/private/account-exports/'.$id);

        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        $this->forgetPictures($id);
    }

    /**
     * The posters, the photographs of the view, the trailer.
     *
     * The rows go with the account — they carry its id and cascade — but the bytes are on a disk
     * and would stay there for ever. An account that left should not leave its artwork on somebody
     * else's server, and this is the reason every uploaded file's path starts with the account's
     * own id: one prefix, removable without reading a database.
     *
     * Outside the transaction and last, deliberately. A file that cannot be deleted is a tidiness
     * problem; a transaction rolled back because of one would leave the account half-erased, which
     * is not.
     */
    private function forgetPictures(string $id): void
    {
        try {
            Storage::disk(config('media.disk'))->deleteDirectory($id);
        } catch (\Throwable $e) {
            logger()->warning('An erased account left its pictures behind.', [
                'tenant_id' => $id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The people whose only reason to be on this platform has just been erased.
     *
     * Leaving them would make the promise a half-measure: the venue's history would be gone and a
     * list of its staff's names and addresses would still be here, belonging to nobody and
     * reachable by nothing. Anybody still on another account keeps their account, and so does
     * anybody who works for the platform — those are memberships this erasure was never about.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $staff
     */
    private function forgetStaff($staff): void
    {
        if ($staff->isEmpty()) {
            return;
        }

        $keep = DB::table('tenant_users')->whereIn('user_id', $staff)->pluck('user_id')
            ->merge(DB::table('platform_admins')->whereIn('user_id', $staff)->pluck('user_id'));

        $gone = $staff->diff($keep)->values();

        if ($gone->isNotEmpty()) {
            DB::table('users')->whereIn('id', $gone)->delete();
        }
    }

    /**
     * The accounts whose window has run out.
     *
     * @return \Illuminate\Support\Collection<int, Tenant>
     */
    public function dueForErasure()
    {
        return Tenant::withTrashed()
            ->where('status', 'cancelled')
            ->whereNotNull('erase_after')
            ->where('erase_after', '<=', now())
            ->get();
    }

    /* --------------------------------------------------------------------------- helpers */

    /**
     * Nights that have not happened, with tickets somebody can still turn up holding.
     *
     * Live allocations rather than tickets issued: a refunded seat is not somebody standing at a
     * door, and a night whose every ticket has been handed back is a night nobody is waiting for.
     */
    private function futureNightsWithTickets()
    {
        return Event::query()
            ->whereIn('status', ['published', 'closed'])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>', now())
            ->where('is_rehearsal', false)
            /*
             * `whereExists` rather than a count in a `HAVING`.
             *
             * Postgres will not let a select alias be used in `HAVING` — and it is right not to:
             * the two are evaluated in the wrong order for it. The existence test is also the
             * cheaper question, because it stops at the first live seat.
             */
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('allocations')
                ->whereColumn('allocations.event_id', 'events.id')
                ->where('allocations.status', 'active'))
            ->addSelect(['live_tickets' => Allocation::selectRaw('count(*)')
                ->whereColumn('allocations.event_id', 'events.id')
                ->where('allocations.status', 'active')])
            ->orderBy('starts_at')
            ->limit(50)
            ->get(['id', 'name', 'starts_at']);
    }

    /** What the platform has invoiced this account and not been paid. */
    private function owed(Tenant $tenant): array
    {
        $rows = DB::table('platform_invoices')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['open', 'uncollectible'])
            ->groupBy('currency')
            ->selectRaw('currency, sum(total) as total')
            ->get();

        return $rows->map(fn ($row) => [
            'currency' => $row->currency,
            'amount' => (int) $row->total,
        ])->all();
    }

    /**
     * Sign everybody out of this account, and only out of this one.
     *
     * A token on this platform belongs to a person rather than to an account, and a person can be
     * on two of them — a freelance box-office manager works for three venues. So only the people
     * whose sole membership was this account lose their tokens: the rest keep the session they are
     * using at somebody else's window, and the closed account refuses them at the door instead,
     * where the refusal is about the account rather than about them.
     */
    private function revokeTokens(Tenant $tenant): void
    {
        $userIds = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('user_id', fn ($query) => $query->from('tenant_users')
                ->where('tenant_id', '!=', $tenant->id)
                ->select('user_id'))
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        PersonalAccessToken::where('tokenable_type', \App\Models\User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }
}
