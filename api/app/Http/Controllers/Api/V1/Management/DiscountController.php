<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Discounts\Discounts;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DiscountCode;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Discount codes, from the organiser's side.
 *
 * A code is not deleted once it has been used: an order that was paid at half price has to keep
 * pointing at the reason, or the box office is left with a total that does not add up. Pausing is
 * the way to stop a code, and deleting is only allowed while it has never been used.
 */
class DiscountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Discounts $discounts,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $codes = DiscountCode::query()
            ->with('event:id,name')
            ->when($request->query('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('code', 'ilike', "%{$term}%")
                    ->orWhere('description', 'ilike', "%{$term}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($codes, fn (DiscountCode $code) => $this->present($code));
    }

    /** A code nobody has to invent. Not reserved — it is a suggestion the form can overwrite. */
    public function suggest(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        do {
            $code = Discounts::suggest();
        } while (DiscountCode::where('code', $code)->exists());

        return response()->json(['code' => $code]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $data = $this->validated($request);

        if (DiscountCode::where('code', $data['code'])->exists()) {
            throw ApiException::conflict('discount_code_taken', 'You already have a code with that name.');
        }

        $code = DiscountCode::create($data + ['created_by' => $request->user()?->id]);

        $this->audit->record('discount.created', $code, [
            'code' => $code->code, 'kind' => $code->kind, 'value' => $code->value,
        ]);

        return response()->json($this->present($code->fresh('event')), 201);
    }

    public function show(Request $request, DiscountCode $discount)
    {
        $this->authorize($request, 'discounts.manage');

        return response()->json($this->present($discount->loadMissing('event')) + [
            'redemptions' => $discount->redemptions()
                ->with('order:id,external_order_id,status,buyer')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($use) => [
                    'id' => $use->id,
                    'amount' => $use->amount,
                    'currency' => $use->currency,
                    'at' => $use->created_at?->toIso8601String(),
                    'order_id' => $use->order?->external_order_id,
                    'order_status' => $use->order?->status,
                    'buyer' => $use->order?->buyer['name'] ?? null,
                ])->all(),
        ]);
    }

    public function update(Request $request, DiscountCode $discount)
    {
        $this->authorize($request, 'discounts.manage');

        // The code itself is not editable once it exists. Renaming it would silently break every
        // poster and every email it has already been printed on, and would leave the redemptions
        // pointing at a name that never existed.
        $data = $this->validated($request, $discount);
        unset($data['code']);

        $discount->fill($data);

        $this->audit->recordChange('discount.updated', $discount);

        $discount->save();

        return response()->json($this->present($discount->fresh('event')));
    }

    public function destroy(Request $request, DiscountCode $discount)
    {
        $this->authorize($request, 'discounts.manage');

        if ($discount->redemptions()->exists()) {
            throw ApiException::conflict(
                'discount_in_use',
                'This code has been used. Pause it instead — deleting it would leave those orders unexplained.'
            );
        }

        $discount->delete();
        $this->audit->record('discount.deleted', $discount, ['code' => $discount->code]);

        return response()->noContent();
    }

    /**
     * @param  DiscountCode|null  $existing  present on update, so `code` is optional there
     */
    private function validated(Request $request, ?DiscountCode $existing = null): array
    {
        $rules = [
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/^[\pL\pN._-]+$/u'],
            'description' => ['nullable', 'string', 'max:160'],
            'event_id' => ['nullable', 'uuid', Rule::exists('events', 'id')],
            'kind' => [$existing ? 'sometimes' : 'required', Rule::in(['percent', 'fixed'])],
            'value' => [$existing ? 'sometimes' : 'required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'min_seats' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ];

        $data = $request->validate($rules);

        $kind = $data['kind'] ?? $existing?->kind;

        // A percentage over 100 is not a discount, it is a payment to the buyer. Checked here
        // rather than in the rules because the ceiling depends on the kind.
        if ('percent' === $kind && isset($data['value']) && $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => __('panel.discounts.percentTooBig')]);
        }

        if (array_key_exists('code', $data)) {
            $data['code'] = DiscountCode::normalise($data['code']);
        }

        if ('fixed' === $kind) {
            // A fixed amount needs to say which money it is. Left empty it would match everything,
            // which is the one behaviour that cannot be right.
            $data['currency'] = strtoupper($data['currency'] ?? $existing?->currency ?? '');

            if (3 !== strlen($data['currency'])) {
                throw ValidationException::withMessages(['currency' => __('panel.discounts.currencyNeeded')]);
            }
        } elseif ('percent' === $kind) {
            $data['currency'] = null;
        }

        return $data;
    }

    private function present(DiscountCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'description' => $code->description,
            'event_id' => $code->event_id,
            'event_name' => $code->event?->name,
            'kind' => $code->kind,
            'value' => $code->value,
            'currency' => $code->currency,
            'starts_at' => $code->starts_at?->toIso8601String(),
            'ends_at' => $code->ends_at?->toIso8601String(),
            'max_uses' => $code->max_uses,
            'used_count' => $code->used_count,
            'min_seats' => $code->min_seats,
            'status' => $code->status,
            'live' => $code->isLive(),
            'created_at' => $code->created_at?->toIso8601String(),
        ];
    }
}
