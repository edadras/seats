<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Loyalty\Loyalty;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyProgramme;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The points scheme, from the organiser's side.
 *
 * Behind `vouchers.manage`, because that is what this is: a machine for issuing credit, and the
 * person who may not hand out a gift card should not be able to set up something that hands them
 * out automatically for ever.
 */
class LoyaltyController extends Controller
{
    public function __construct(
        private readonly Loyalty $loyalty,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        return response()->json($this->present($this->loyalty->programme()));
    }

    public function save(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'enabled' => ['required', 'boolean'],
            'currency' => ['required', 'string', 'size:3'],
            // A rate of nought is a scheme that does nothing, which is what `enabled` is for.
            'earn_rate' => ['required', 'integer', 'min:1', 'max:1000'],
            'points_per_unit' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_redeem' => ['required', 'integer', 'min:0', 'max:1000000'],
            'window_months' => ['required', 'integer', 'min:1', 'max:120'],
            // Null is "they do not expire", which is a promise an organiser may make deliberately.
            'inactive_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'tiers' => ['present', 'array', 'max:6'],
            'tiers.*.key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'tiers.*.name' => ['required', 'string', 'max:60'],
            'tiers.*.from_points' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        $programme = $this->loyalty->programme() ?? new LoyaltyProgramme;

        // The override first: `+` keeps the left operand's key, so a currency put after the
        // request's own would be the request's own, lower case and all.
        $programme->forceFill([
            'tenant_id' => app(TenantContext::class)->idOrFail(),
            'currency' => mb_strtoupper($data['currency']),
        ] + $data)->save();

        $this->audit->record('loyalty.saved', $programme, [
            'enabled' => $programme->enabled,
            'earn_rate' => $programme->earn_rate,
            'tiers' => count($programme->ladder()),
        ]);

        return response()->json($this->present($programme->fresh()));
    }

    /** Who has points, and where they stand. */
    public function members(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $page = max(1, (int) $request->query('page', 1));
        $found = $this->loyalty->members(50, $page);

        return response()->json([
            'data' => $found['rows'],
            'total' => $found['total'],
            'page' => $page,
        ]);
    }

    /** One address, and everything that has happened to its points. */
    public function member(Request $request, string $email)
    {
        $this->authorize($request, 'vouchers.manage');

        return response()->json([
            'email' => $email,
            'balance' => $this->loyalty->balance($email),
            'standing' => $this->loyalty->standing($email),
            'history' => $this->loyalty->history($email),
        ]);
    }

    /**
     * Points on or off by hand.
     *
     * Every scheme needs this, and every scheme's worst day is the one where somebody did it
     * without saying why — so the reason travels with the movement and into the audit log.
     */
    public function adjust(Request $request)
    {
        $this->authorize($request, 'vouchers.manage');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:200'],
            'points' => ['required', 'integer', 'min:-1000000', 'max:1000000', 'not_in:0'],
            'why' => ['required', 'string', 'max:200'],
        ]);

        $this->loyalty->adjust($data['email'], (int) $data['points'], $data['why']);

        return response()->json([
            'email' => mb_strtolower(trim($data['email'])),
            'balance' => $this->loyalty->balance($data['email']),
            'standing' => $this->loyalty->standing($data['email']),
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function present(?LoyaltyProgramme $programme): array
    {
        if (! $programme) {
            return ['configured' => false];
        }

        return [
            'configured' => true,
            'name' => $programme->name,
            'enabled' => $programme->enabled,
            'currency' => $programme->currency,
            'earn_rate' => $programme->earn_rate,
            'points_per_unit' => $programme->points_per_unit,
            'min_redeem' => $programme->min_redeem,
            'window_months' => $programme->window_months,
            'inactive_months' => $programme->inactive_months,
            'tiers' => $programme->ladder(),
            /*
             * What a point is worth, worked out here rather than on the screen.
             *
             * The exponent of a currency is the kind of thing a panel gets subtly wrong — a scheme
             * in rials is not a scheme in cents — and this number is the one an organiser checks
             * their scheme against before they turn it on.
             */
            'unit' => 10 ** Money::exponent($programme->currency),
        ];
    }
}
