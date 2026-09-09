<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The platform's own console: every organiser, every site, every subscription.
 *
 * Everything here reads unscoped, which is exactly why it is behind its own middleware, its own
 * table of operators and its own audit log. An organiser's panel and this console never share a
 * route, a permission or a controller — the moment they did, a bug in one would be a bug in the
 * other, and the blast radius would be everybody.
 *
 * Two levels: support can look, an operator can change things. That distinction is enforced here
 * rather than in the UI, because a UI that hides a button does not stop a request.
 */
class ConsoleController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** The numbers somebody running this asks for first. */
    public function overview(Request $request)
    {
        $month = now()->startOfMonth();

        return response()->json([
            'tenants' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'suspended' => Tenant::where('status', '!=', 'active')->count(),
                'new_this_month' => Tenant::where('created_at', '>=', $month)->count(),
            ],
            'sites' => [
                'total' => Site::count(),
                'live' => Site::where('status', 'live')->count(),
                'domains_verified' => SiteDomain::whereNotNull('verified_at')->count(),
            ],
            'selling' => [
                'events' => Event::where('status', 'published')->count(),
                'tickets_issued' => Ticket::count(),
                'seats_sold_this_month' => Allocation::where('status', 'active')
                    ->where('allocated_at', '>=', $month)->count(),
            ],
            /*
             * Takings are grouped by currency rather than added up. A platform serving Tehran and
             * Berlin has no single number, and inventing one by summing rials into euros is worse
             * than showing two rows.
             */
            'takings_this_month' => ExternalOrder::query()
                ->where('status', 'confirmed')
                ->where('confirmed_at', '>=', $month)
                ->groupBy('currency')
                ->select('currency', DB::raw('sum(total_amount) as total'), DB::raw('count(*) as orders'))
                ->get()
                ->map(fn ($row) => [
                    'currency' => $row->currency,
                    'total' => (int) $row->total,
                    'orders' => (int) $row->orders,
                ])->values(),
            'me' => [
                'level' => $request->attributes->get('platform_admin')->level,
            ],
        ]);
    }

    public function tenants(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $tenants = Tenant::query()
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'ilike', '%'.$search.'%')
                    ->orWhere('slug', 'ilike', '%'.$search.'%');
            }))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return $this->paginated($tenants, fn (Tenant $tenant) => $this->presentTenant($tenant));
    }

    public function tenant(Request $request, string $tenantId)
    {
        $tenant = Tenant::findOr($tenantId, fn () => throw ApiException::notFound('Unknown organiser.', 'unknown_tenant'));

        /*
         * `members` and `websites`, not `people` and `sites`: those two are counts in the summary
         * above, and a field that is a number in one response and a list in another is a field
         * somebody will read as the wrong one.
         */
        return response()->json(array_merge($this->presentTenant($tenant), [
            'members' => TenantUser::where('tenant_id', $tenant->id)
                ->with('user')
                ->get()
                ->map(fn (TenantUser $member) => [
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                    'role' => $member->role,
                    'suspended' => $member->isSuspended(),
                    'last_seen_at' => $member->last_seen_at?->toIso8601String(),
                ])->values(),
            'websites' => Site::where('tenant_id', $tenant->id)
                ->with('domains')
                ->get()
                ->map(fn (Site $site) => $this->presentSite($site))->values(),
            'events' => Event::where('tenant_id', $tenant->id)->count(),
            'tickets' => Ticket::where('tenant_id', $tenant->id)->count(),
        ]));
    }

    /** Suspend, reinstate, or move an organiser to another plan. */
    public function updateTenant(Request $request, string $tenantId)
    {
        $this->assertOperator($request);

        $tenant = Tenant::findOr($tenantId, fn () => throw ApiException::notFound('Unknown organiser.', 'unknown_tenant'));

        $data = $request->validate([
            'status' => ['sometimes', 'in:active,suspended,cancelled'],
            'plan' => ['sometimes', 'string', 'max:40'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if (isset($data['status'])) {
            $tenant->status = $data['status'];
            $tenant->save();
        }

        if (isset($data['plan'])) {
            $plan = \App\Models\Plan::where('key', $data['plan'])->first();

            if (! $plan) {
                throw ApiException::unprocessable('unknown_plan', 'There is no such plan.');
            }

            $subscription = Subscription::where('tenant_id', $tenant->id)->latest('created_at')->first();

            if ($subscription) {
                $subscription->update(['plan_id' => $plan->id]);
            } else {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                ]);
            }
        }

        PlatformAuditLog::write(
            $request->user()->id,
            'tenant.updated',
            $tenant->id,
            array_filter([
                'status' => $data['status'] ?? null,
                'plan' => $data['plan'] ?? null,
                'reason' => $data['reason'] ?? null,
            ]),
            $request->ip(),
        );

        return response()->json($this->presentTenant($tenant->fresh()));
    }

    /** Every site on the platform, so "who is serving what" is one screen and not a query. */
    public function sites(Request $request)
    {
        $sites = Site::query()
            ->with('domains')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        $tenants = Tenant::whereIn('id', $sites->pluck('tenant_id'))->get()->keyBy('id');

        return $this->paginated($sites, fn (Site $site) => $this->presentSite($site) + [
            'tenant' => $tenants->get($site->tenant_id)?->name,
            'tenant_id' => $site->tenant_id,
        ]);
    }

    public function audit(Request $request)
    {
        $entries = PlatformAuditLog::query()
            ->with('user')
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        $tenants = Tenant::whereIn('id', $entries->pluck('tenant_id')->filter())->get()->keyBy('id');

        return $this->paginated($entries, fn (PlatformAuditLog $entry) => [
            'id' => $entry->id,
            'action' => $entry->action,
            'operator' => $entry->user?->name,
            'tenant' => $entry->tenant_id ? $tenants->get($entry->tenant_id)?->name : null,
            'context' => $entry->context,
            'ip' => $entry->ip,
            'created_at' => $entry->created_at?->toIso8601String(),
        ]);
    }

    /**
     * A token that acts as an organiser's owner, for an hour, recorded on the way in.
     *
     * Support needs to see what a customer sees; nobody should be able to do that quietly. So it
     * expires by itself, it is written to the platform log *and* to the organiser's own audit log,
     * and it is an operator's power rather than support's.
     */
    public function impersonate(Request $request, string $tenantId)
    {
        $this->assertOperator($request);

        $tenant = Tenant::findOr($tenantId, fn () => throw ApiException::notFound('Unknown organiser.', 'unknown_tenant'));

        $owner = TenantUser::where('tenant_id', $tenant->id)
            ->whereNull('suspended_at')
            ->orderByRaw("case when role = 'owner' then 0 else 1 end")
            ->first();

        if (! $owner) {
            throw ApiException::conflict('no_owner', 'That account has nobody to act as.');
        }

        $user = User::find($owner->user_id);

        $token = $user->createToken(
            'console impersonation by '.$request->user()->email,
            ['*'],
            now()->addHour(),
        );

        PlatformAuditLog::write(
            $request->user()->id,
            'tenant.impersonated',
            $tenant->id,
            ['as' => $user->email, 'expires_at' => now()->addHour()->toIso8601String()],
            $request->ip(),
        );

        // And in the organiser's own log, because it is their account that was entered.
        $this->tenants->runAs($tenant, fn () => app(\App\Support\Audit\AuditLogger::class)->record(
            'platform.impersonation_started',
            null,
            ['operator' => $request->user()->email, 'expires_in_minutes' => 60],
        ));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => now()->addHour()->toIso8601String(),
            'as' => ['name' => $user->name, 'email' => $user->email, 'role' => $owner->role],
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function assertOperator(Request $request): void
    {
        $admin = $request->attributes->get('platform_admin');

        if (! $admin instanceof PlatformAdmin || ! $admin->mayChange()) {
            throw ApiException::denied('support_may_not_change', 'Support accounts can look, not change.');
        }
    }

    private function presentTenant(Tenant $tenant): array
    {
        $subscription = Subscription::with('plan')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->first();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'locale' => $tenant->locale,
            'timezone' => $tenant->timezone,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'plan' => $subscription?->plan?->key,
            'plan_name' => $subscription?->plan?->name,
            'subscription_status' => $subscription?->status,
            'people' => TenantUser::where('tenant_id', $tenant->id)->count(),
            'sites' => Site::where('tenant_id', $tenant->id)->count(),
        ];
    }

    private function presentSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'status' => $site->status,
            'theme' => $site->theme_key,
            'domains' => $site->domains->map(fn (SiteDomain $domain) => [
                'hostname' => $domain->hostname,
                'verified' => null !== $domain->verified_at,
                'primary' => (bool) $domain->is_primary,
            ])->values(),
        ];
    }
}
