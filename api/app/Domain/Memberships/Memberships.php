<?php

namespace App\Domain\Memberships;

use App\Exceptions\ApiException;
use App\Models\Addon;
use App\Models\ExternalOrder;
use App\Models\Membership;
use App\Models\MembershipScheme;
use App\Models\OrderAddon;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A venue's Friends scheme.
 *
 * The platform already had two things that look like this and are not. Loyalty is earned by coming
 * and cannot be bought; a season ticket is one run of one production. A membership is a fee, a
 * period, and a standing arrangement — and it is the oldest of the three.
 *
 * Two decisions carry the whole of it.
 *
 * **It is sold as an add-on.** Not a second checkout: an add-on already goes through the order, the
 * gateway, the totals, the settlement and the refund path, so a membership bought beside a ticket
 * is an ordinary booking that happens to have granted somebody something. Nothing downstream has to
 * learn a new shape, and the money is reported exactly where the organiser expects to find it.
 *
 * **A renewal extends rather than replaces.** Somebody renewing in March, four months early, keeps
 * those four months — a scheme that took them away would be one that punished paying early, which
 * is the opposite of what a Friends scheme is for.
 */
class Memberships
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return Collection<int, MembershipScheme> */
    public function schemes(bool $enabledOnly = false): Collection
    {
        return MembershipScheme::query()
            ->when($enabledOnly, fn ($q) => $q->where('enabled', true))
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create or change a scheme, and keep the thing it is sold as in step with it.
     *
     * The add-on is the platform's, not the organiser's: it is created when they ask for the scheme
     * to be on sale, repriced when they reprice the scheme, and hidden — never deleted — when they
     * take it off sale, because it is on the bookings of everybody who has already joined.
     */
    public function save(?MembershipScheme $scheme, array $data, bool $sellOnline): MembershipScheme
    {
        $scheme ??= new MembershipScheme(['tenant_id' => $this->tenants->idOrFail()]);

        $scheme->fill([
            'name' => mb_substr(trim((string) $data['name']), 0, 80),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 300),
            'currency' => mb_strtoupper((string) $data['currency']),
            'price' => max(0, (int) ($data['price'] ?? 0)),
            'months' => max(1, min(120, (int) ($data['months'] ?? 12))),
            'discount_percent' => max(0, min(100, (int) ($data['discount_percent'] ?? 0))),
            'presale' => (bool) ($data['presale'] ?? false),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'position' => max(0, (int) ($data['position'] ?? 0)),
        ]);

        $scheme->save();

        $this->syncAddon($scheme->fresh(), $sellOnline);

        return $scheme->fresh();
    }

    /**
     * Somebody joins, or renews.
     *
     * Idempotent per order: a confirm that runs twice — a gateway that sent its webhook and its
     * redirect both — must not buy somebody two years.
     */
    public function grant(
        MembershipScheme $scheme,
        string $email,
        ?string $name = null,
        ?ExternalOrder $order = null,
        string $source = 'granted',
    ): Membership {
        $email = mb_strtolower(trim($email));

        if ('' === $email) {
            throw ApiException::unprocessable('membership_no_address', 'A membership needs an address.');
        }

        if ($order) {
            $already = Membership::where('external_order_row_id', $order->id)
                ->where('membership_scheme_id', $scheme->id)
                ->first();

            if ($already) {
                return $already;
            }
        }

        // From the end of whatever they already hold, so renewing early is not a way to lose time.
        $running = $this->currentFor($email)
            ->firstWhere('membership_scheme_id', $scheme->id);

        $from = $running ? $running->ends_at : now();

        return Membership::create([
            'tenant_id' => $scheme->tenant_id,
            'membership_scheme_id' => $scheme->id,
            'email' => $email,
            'name' => $name ? mb_substr(trim($name), 0, 120) : null,
            'starts_at' => $running ? $running->starts_at : now(),
            'ends_at' => Carbon::parse($from)->addMonths($scheme->months),
            'source' => $source,
            'external_order_row_id' => $order?->id,
        ]);
    }

    /** Somebody leaves, or was granted one by mistake. Kept rather than deleted: it was true. */
    public function cancel(Membership $membership): Membership
    {
        $membership->forceFill(['cancelled_at' => now()])->save();

        return $membership->fresh();
    }

    /**
     * What this booking made somebody a member of.
     *
     * Called after an order confirms, from the same place loyalty is settled. A booking with no
     * membership add-on on it does nothing at all, which is almost all of them.
     *
     * @return list<Membership>
     */
    public function settle(ExternalOrder $order): array
    {
        if ('confirmed' !== $order->status) {
            return [];
        }

        $email = (string) ($order->buyer['email'] ?? '');

        if ('' === $email) {
            return [];
        }

        $bought = OrderAddon::where('external_order_row_id', $order->id)->pluck('addon_id')->filter();

        if ($bought->isEmpty()) {
            return [];
        }

        $granted = [];

        foreach (MembershipScheme::whereIn('addon_id', $bought)->get() as $scheme) {
            $granted[] = $this->grant(
                $scheme,
                $email,
                (string) ($order->buyer['name'] ?? '') ?: null,
                $order,
                'bought',
            );
        }

        return $granted;
    }

    /** Every membership of this address that is running today, best discount first. */
    public function currentFor(?string $email): Collection
    {
        if (! $email) {
            return collect();
        }

        return Membership::with('scheme')
            ->where('email', mb_strtolower(trim($email)))
            ->current()
            ->get()
            ->filter(fn (Membership $one) => (bool) $one->scheme?->enabled)
            ->sortByDesc(fn (Membership $one) => (int) $one->scheme->discount_percent)
            ->values();
    }

    /** The one that counts: the best current membership, or null. */
    public function standing(?string $email): ?Membership
    {
        return $this->currentFor($email)->first();
    }

    /**
     * What a member's standing takes off a subtotal.
     *
     * The seats only — never the fee, the tax or the programme, which is the rule a discount code
     * already follows. Combining with a code is not this method's business: the checkout takes the
     * better of the two, and never both.
     */
    public function discountOn(?string $email, int $tickets): int
    {
        $standing = $this->standing($email);

        return $standing ? $standing->scheme->discountOn($tickets) : 0;
    }

    /** Whether this address may walk past the presale door, where a night lets members in. */
    public function admitsToPresale(?string $email): bool
    {
        return $this->currentFor($email)->contains(fn (Membership $one) => (bool) $one->scheme->presale);
    }

    /**
     * The list a venue's membership secretary reads.
     *
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function members(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        $query = Membership::with('scheme')
            ->when($filters['scheme'] ?? null, fn ($q, $id) => $q->where('membership_scheme_id', $id))
            ->when(($filters['state'] ?? '') === 'current', fn ($q) => $q->current())
            ->when(($filters['state'] ?? '') === 'lapsed', fn ($q) => $q->where(
                fn ($where) => $where->whereNotNull('cancelled_at')->orWhere('ends_at', '<=', now())
            ))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $term)).'%';

                $q->where(fn ($where) => $where->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(name, \'\')) like ?', [$like]));
            })
            ->orderByDesc('ends_at');

        $total = (clone $query)->count();

        return [
            'data' => $query->forPage(max(1, $page), $perPage)->get()
                ->map(fn (Membership $one) => [
                    'id' => $one->id,
                    'email' => $one->email,
                    'name' => $one->name,
                    'scheme' => ['id' => $one->membership_scheme_id, 'name' => $one->scheme?->name],
                    'starts_at' => $one->starts_at?->toIso8601String(),
                    'ends_at' => $one->ends_at?->toIso8601String(),
                    'current' => $one->isCurrent(),
                    'source' => $one->source,
                    'cancelled_at' => $one->cancelled_at?->toIso8601String(),
                ])->all(),
            'total' => $total,
        ];
    }

    /**
     * Memberships running out soon that nobody has renewed.
     *
     * "Nobody has renewed" is the important half: a member who has already paid for next year holds
     * two rows, and writing to them about the one that is ending would be a reminder to do
     * something they have done.
     *
     * @return Collection<int, Membership>
     */
    public function expiring(int $days = 21): Collection
    {
        $window = now()->addDays(max(1, $days));

        return Membership::with('scheme')
            ->whereNull('cancelled_at')
            ->whereBetween('ends_at', [now(), $window])
            ->get()
            ->filter(function (Membership $one) {
                if (! $one->scheme?->enabled) {
                    return false;
                }

                return ! Membership::where('email', $one->email)
                    ->where('membership_scheme_id', $one->membership_scheme_id)
                    ->whereNull('cancelled_at')
                    ->where('ends_at', '>', $one->ends_at)
                    ->exists();
            })
            ->values();
    }

    /**
     * Keep the add-on this scheme is sold as in step with the scheme.
     *
     * Event-wide (`event_id` null) and one per order: joining twice in one booking is not a thing
     * somebody means to do, and a membership is not per ticket.
     */
    private function syncAddon(MembershipScheme $scheme, bool $sellOnline): void
    {
        if (! $sellOnline) {
            if ($scheme->addon) {
                // Hidden, not deleted: it is on the booking of everybody who has already joined.
                $scheme->addon->forceFill(['visible' => false])->save();
            }

            return;
        }

        $addon = $scheme->addon ?: new Addon([
            'tenant_id' => $scheme->tenant_id,
            'event_id' => null,
            'per' => 'order',
        ]);

        $addon->fill([
            'name' => $scheme->name,
            'description' => $scheme->description,
            'price' => $scheme->price,
            'currency' => $scheme->currency,
            'max_per_order' => 1,
            'visible' => (bool) $scheme->enabled,
            'position' => $scheme->position,
        ]);

        $addon->tenant_id = $scheme->tenant_id;
        $addon->save();

        if ($scheme->addon_id !== $addon->id) {
            $scheme->forceFill(['addon_id' => $addon->id])->save();
        }
    }
}
