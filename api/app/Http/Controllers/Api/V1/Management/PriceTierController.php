<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Pricing\PriceTiers;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPriceTier;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * When a price changes, decided in advance.
 *
 * Behind `pricing.manage`, both ways round, exactly as the individual seat prices are: this is the
 * same decision about the same numbers, made earlier, and somebody who may not set a price has no
 * business reading the schedule for changing it either.
 */
class PriceTierController extends Controller
{
    public function __construct(private readonly PriceTiers $tiers, private readonly AuditLogger $audit) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        return response()->json([
            'data' => $this->tiers->forEvent($event)->map(fn (EventPriceTier $tier) => $this->present($tier))->all(),
            // Said separately from the list: an organiser looking at four windows wants to know
            // which one is live now without doing the arithmetic themselves.
            'active' => $this->tiers->describe($event),
        ]);
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'tiers' => ['present', 'array', 'max:20'],
            'tiers.*.name' => ['required', 'string', 'max:120'],
            'tiers.*.starts_at' => ['nullable', 'date'],
            'tiers.*.ends_at' => ['nullable', 'date'],
            'tiers.*.kind' => ['required', Rule::in(['percent', 'amount'])],
            // A hundred per cent off is free and a hundred per cent on is double; beyond that is
            // a typo, and a typo in a price is a night sold at the wrong number.
            'tiers.*.value' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $saved = $this->tiers->replace($event, $data['tiers']);

        $this->audit->record('event.price_tiers_set', $event, ['tiers' => $saved->count()]);

        return response()->json([
            'data' => $saved->map(fn (EventPriceTier $tier) => $this->present($tier))->all(),
            'active' => $this->tiers->describe($event->fresh()),
        ]);
    }

    private function present(EventPriceTier $tier): array
    {
        return [
            'id' => $tier->id,
            'name' => $tier->name,
            'starts_at' => $tier->starts_at?->toIso8601String(),
            'ends_at' => $tier->ends_at?->toIso8601String(),
            'kind' => $tier->kind,
            'value' => (int) $tier->value,
            'active' => $tier->coversNow(),
        ];
    }
}
