<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Seasons\Seasons;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EventSeries;
use App\Models\SeasonPass;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Season tickets, from the organiser's side.
 *
 * Behind `discounts.manage`, with the other decision of the same shape: what a buyer pays and what
 * a promise costs the account. A season pass is not an inventory decision — it takes no seats out
 * of sale and reserves nothing — so it does not belong with the seat map or the box office.
 *
 * A pass that somebody has already bought is paused rather than deleted, for the same reason a
 * spent discount is: those bookings have to keep pointing at why they cost what they cost.
 */
class SeasonPassController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Seasons $seasons,
    ) {}

    /**
     * The runs a season ticket could be sold for, with how many nights are in each.
     *
     * A run of one is not offered: a "season" of one night is a night, and a saving on it is a
     * discount code wearing a different hat.
     */
    public function series(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $counts = DB::table('events')
            ->whereNotNull('series_id')
            ->where('status', 'published')
            ->groupBy('series_id')
            ->selectRaw('series_id, count(*) as nights')
            ->pluck('nights', 'series_id');

        return response()->json([
            'data' => EventSeries::orderBy('name')->get()
                ->map(fn (EventSeries $series) => [
                    'id' => $series->id,
                    'name' => $series->name,
                    'slug' => $series->slug,
                    'nights' => (int) ($counts[$series->id] ?? 0),
                ])
                ->filter(fn (array $row) => $row['nights'] > 1)
                ->values()
                ->all(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $passes = SeasonPass::query()
            ->with('series:id,name')
            ->when($request->query('series_id'), fn ($q, $id) => $q->where('series_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'ilike', "%{$term}%"))
            ->orderBy('position')
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($passes, fn (SeasonPass $pass) => $this->present($pass));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $data = $this->validated($request);

        if (SeasonPass::where('series_id', $data['series_id'])->where('name', $data['name'])->exists()) {
            throw ApiException::conflict(
                'season_pass_named',
                'That run already has a season ticket with that name.'
            );
        }

        $pass = SeasonPass::create($data + ['created_by' => $request->user()?->id]);

        $this->audit->record('season_pass.created', $pass, [
            'series_id' => $pass->series_id,
            'kind' => $pass->kind,
        ]);

        return response()->json($this->present($pass->fresh('series')), 201);
    }

    public function show(Request $request, SeasonPass $seasonPass)
    {
        $this->authorize($request, 'discounts.manage');

        return response()->json($this->present($seasonPass->loadMissing('series')) + [
            // What was actually bought on it, which is the only question an organiser asks about
            // a pass after they have made one.
            'bookings' => $seasonPass->bookings()
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($booking) => [
                    'id' => $booking->id,
                    'reference' => $booking->reference,
                    'buyer' => $booking->buyer['name'] ?? null,
                    'seats' => $booking->seats,
                    'nights' => $booking->nights,
                    'total' => (int) $booking->total_amount,
                    'discount' => (int) $booking->discount_amount,
                    'status' => $booking->status,
                    'at' => $booking->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function update(Request $request, SeasonPass $seasonPass)
    {
        $this->authorize($request, 'discounts.manage');

        $data = $this->validated($request, $seasonPass);
        // The run is not editable. A pass moved to another series would leave its bookings
        // pointing at nights it no longer covers, which is a lie nobody could untangle later.
        unset($data['series_id']);

        $seasonPass->fill($data);

        $this->audit->recordChange('season_pass.updated', $seasonPass);

        $seasonPass->save();

        return response()->json($this->present($seasonPass->fresh('series')));
    }

    public function destroy(Request $request, SeasonPass $seasonPass)
    {
        $this->authorize($request, 'discounts.manage');

        if ($seasonPass->bookings()->exists()) {
            throw ApiException::conflict(
                'season_pass_sold',
                'Somebody has bought this season ticket. Pause it instead — deleting it would leave those bookings unexplained.'
            );
        }

        $seasonPass->delete();
        $this->audit->record('season_pass.deleted', $seasonPass);

        return response()->noContent();
    }

    /** @param  SeasonPass|null  $existing  present on update, so `series_id` is optional there */
    private function validated(Request $request, ?SeasonPass $existing = null): array
    {
        $data = $request->validate([
            'series_id' => [$existing ? 'sometimes' : 'required', 'uuid', Rule::exists('event_series', 'id')],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:400'],
            'kind' => ['sometimes', Rule::in(SeasonPass::KINDS)],
            'nights' => ['nullable', 'integer', 'min:2', 'max:365'],
            'discount_kind' => ['sometimes', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['sometimes', 'integer', 'min:0'],
            'currency' => [$existing ? 'sometimes' : 'required', 'string', 'size:3'],
            'max_seats' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'on_sale_at' => ['nullable', 'date'],
            'off_sale_at' => ['nullable', 'date', 'after:on_sale_at'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        if (array_key_exists('currency', $data)) {
            $data['currency'] = mb_strtoupper($data['currency']);
        }

        $kind = $data['kind'] ?? $existing?->kind ?? 'all';

        // The database says the same thing with a CHECK constraint. Said here too, so the answer
        // is a sentence in the organiser's language rather than a driver exception.
        if ('choose' === $kind && ($data['nights'] ?? $existing?->nights) === null) {
            throw ApiException::unprocessable(
                'season_needs_nights',
                'A pass the buyer chooses nights for has to say how many.'
            );
        }

        // And the other way: a pass for the whole run carries no number for anybody to wonder at.
        $data['nights'] = 'choose' === $kind ? ($data['nights'] ?? $existing?->nights) : null;

        if ('percent' === ($data['discount_kind'] ?? $existing?->discount_kind ?? 'percent')
            && ($data['discount_value'] ?? $existing?->discount_value ?? 0) > 100) {
            throw ApiException::unprocessable(
                'discount_over_100',
                'A percentage cannot be more than 100.'
            );
        }

        return $data;
    }

    private function present(SeasonPass $pass): array
    {
        $nights = $this->seasons->nightsIn($pass);

        return [
            'id' => $pass->id,
            'series_id' => $pass->series_id,
            'series_name' => $pass->series?->name,
            'name' => $pass->name,
            'description' => $pass->description,
            'kind' => $pass->kind,
            'nights' => $pass->nights,
            // How many nights it would actually sell today — published, mapped and still ahead.
            // The number an organiser needs is this one, not how many the run has ever had.
            'nights_on_sale' => $nights->count(),
            'discount_kind' => $pass->discount_kind,
            'discount_value' => (int) $pass->discount_value,
            'currency' => $pass->currency,
            'max_seats' => (int) $pass->max_seats,
            'on_sale_at' => $pass->on_sale_at?->toIso8601String(),
            'off_sale_at' => $pass->off_sale_at?->toIso8601String(),
            'status' => $pass->status,
            'live' => $pass->isLive() && $nights->count() > 1,
            'sold' => $pass->bookings()->where('status', 'confirmed')->count(),
            'position' => (int) $pass->position,
            'created_at' => $pass->created_at?->toIso8601String(),
        ];
    }
}
