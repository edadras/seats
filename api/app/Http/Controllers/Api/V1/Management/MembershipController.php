<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Memberships\Memberships;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipScheme;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * A venue's Friends scheme: the rungs, and the people on them.
 *
 * Under `vouchers.manage` with gift credit and agency accounts, because it is the same job at the
 * same desk: somebody at the window takes thirty euros and writes down that a person is now a
 * member. It is deliberately not an account-settings permission — making the membership secretary
 * ask a director to add a member is how a venue ends up keeping the list in a spreadsheet.
 */
class MembershipController extends Controller
{
    public function __construct(
        private readonly Memberships $memberships,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        return response()->json([
            'data' => $this->memberships->schemes()->map(fn (MembershipScheme $scheme) => $this->present($scheme)),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $this->validated($request);
        $scheme = $this->memberships->save(null, $data, (bool) ($data['sell_online'] ?? false));

        $this->audit->record('membership_scheme.created', $scheme, ['name' => $scheme->name]);

        return response()->json($this->present($scheme), 201);
    }

    public function update(Request $request, MembershipScheme $scheme)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $this->validated($request);
        $saved = $this->memberships->save($scheme, $data, (bool) ($data['sell_online'] ?? false));

        $this->audit->record('membership_scheme.updated', $saved, ['name' => $saved->name]);

        return response()->json($this->present($saved));
    }

    /**
     * A scheme goes, and the people in it do not.
     *
     * Refused where anybody is still in it: a membership somebody paid for cannot be deleted
     * because a scheme was tidied up, and cascading would do exactly that. Switch it off instead —
     * the rung stops being offered and everybody in it keeps what they bought until it runs out.
     */
    public function destroy(Request $request, MembershipScheme $scheme)
    {
        $this->authorize($request, 'vouchers.manage');

        if ($scheme->memberships()->count() > 0) {
            throw \App\Exceptions\ApiException::conflict(
                'membership_scheme_in_use',
                'People have joined this scheme. Switch it off instead — they keep what they paid for.'
            );
        }

        $this->audit->record('membership_scheme.deleted', $scheme, ['name' => $scheme->name]);

        $scheme->delete();

        return response()->noContent();
    }

    /** Everybody on the list. */
    public function members(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $request->validate([
            'scheme' => ['sometimes', 'nullable', 'uuid'],
            'state' => ['sometimes', 'nullable', 'in:current,lapsed'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = min(200, (int) ($data['per_page'] ?? 50));

        $found = $this->memberships->members($data, $perPage, $page);

        return response()->json([
            'data' => $found['data'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $found['total'],
                'last_page' => max(1, (int) ceil($found['total'] / $perPage)),
            ],
        ]);
    }

    /** Somebody joins at the window, or the old paper list is brought across. */
    public function join(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $request->validate([
            'scheme_id' => ['required', 'uuid'],
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $scheme = MembershipScheme::findOrFail($data['scheme_id']);

        $membership = $this->memberships->grant(
            $scheme,
            $data['email'],
            $data['name'] ?? null,
            null,
            'granted',
        );

        $this->audit->record('membership.granted', $membership, [
            'scheme' => $scheme->name,
            'email' => $membership->email,
            'until' => $membership->ends_at->toIso8601String(),
        ]);

        return response()->json($this->presentMember($membership->fresh()), 201);
    }

    public function cancel(Request $request, Membership $membership)
    {
        $this->authorize($request, 'vouchers.manage');

        $cancelled = $this->memberships->cancel($membership);

        $this->audit->record('membership.cancelled', $cancelled, ['email' => $cancelled->email]);

        return response()->json($this->presentMember($cancelled));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'currency' => ['required', 'string', 'size:3'],
            'price' => ['required', 'integer', 'min:0'],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'discount_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'presale' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
            // Whether the website offers it beside a ticket. The add-on it is sold as is the
            // platform's to create and keep in step; an organiser only says yes or no.
            'sell_online' => ['sometimes', 'boolean'],
        ]);
    }

    private function present(MembershipScheme $scheme): array
    {
        return [
            'id' => $scheme->id,
            'name' => $scheme->name,
            'description' => $scheme->description,
            'currency' => $scheme->currency,
            'price' => (int) $scheme->price,
            'months' => (int) $scheme->months,
            'discount_percent' => (int) $scheme->discount_percent,
            'presale' => (bool) $scheme->presale,
            'enabled' => (bool) $scheme->enabled,
            'position' => (int) $scheme->position,
            // Whether it is on the website. Read off the add-on rather than stored twice: an
            // add-on somebody hid from the add-ons screen is a scheme that is no longer on sale.
            'sell_online' => (bool) ($scheme->addon?->visible),
            'members' => $scheme->memberships()->current()->count(),
        ];
    }

    private function presentMember(Membership $membership): array
    {
        return [
            'id' => $membership->id,
            'email' => $membership->email,
            'name' => $membership->name,
            'scheme' => [
                'id' => $membership->membership_scheme_id,
                'name' => $membership->scheme?->name,
            ],
            'starts_at' => $membership->starts_at?->toIso8601String(),
            'ends_at' => $membership->ends_at?->toIso8601String(),
            'current' => $membership->isCurrent(),
            'source' => $membership->source,
            'cancelled_at' => $membership->cancelled_at?->toIso8601String(),
        ];
    }
}
