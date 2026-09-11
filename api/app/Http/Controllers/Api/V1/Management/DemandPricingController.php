<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Pricing\DemandPricing;
use App\Domain\Pricing\Prices;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDemandStep;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pricing by how much is left.
 *
 * Behind `pricing.manage`, both ways round, exactly as the timed tiers and the individual seat
 * prices are: this is the same decision about the same numbers made on a different trigger, and
 * somebody who may not set a price has no business reading the rules for changing it either.
 *
 * The switch, the ladder and the rails are saved together on purpose. Turning demand pricing on
 * without a ceiling is the mistake this feature is most able to make, and a screen that let
 * somebody do it in two steps would let them stop after the first.
 */
class DemandPricingController extends Controller
{
    public function __construct(
        private readonly DemandPricing $demand,
        private readonly Prices $prices,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        return response()->json($this->state($event));
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'demand_pricing' => ['required', 'boolean'],
            'price_floor' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'price_ceiling' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'steps' => ['present', 'array', 'max:10'],
            'steps.*.name' => ['nullable', 'string', 'max:80'],
            'steps.*.sold_from' => ['required', 'integer', 'min:0', 'max:100'],
            'steps.*.kind' => ['required', Rule::in(['percent', 'amount'])],
            // The same range the timed tiers allow, and for the same reason: a hundred per cent on
            // is double, and beyond that is a typo that sells a night at the wrong number.
            'steps.*.value' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $floor = $data['price_floor'] ?? null;
        $ceiling = $data['price_ceiling'] ?? null;

        if (null !== $floor && null !== $ceiling && $floor > $ceiling) {
            /*
             * Refused rather than resolved.
             *
             * The two together say something that cannot be true, and quietly picking one would
             * leave an organiser looking at a screen that agreed with them while selling at a
             * number they did not choose.
             */
            throw ApiException::unprocessable(
                'rails_crossed',
                'The lowest price you will sell at is above the highest.',
                ['floor' => $floor, 'ceiling' => $ceiling],
            );
        }

        $event->forceFill([
            'demand_pricing' => (bool) $data['demand_pricing'],
            'price_floor' => $floor,
            'price_ceiling' => $ceiling,
        ])->save();

        $saved = $this->demand->replace($event, $data['steps']);

        $this->audit->record('event.demand_pricing_set', $event, [
            'on' => (bool) $data['demand_pricing'],
            'steps' => $saved->count(),
            'floor' => $floor,
            'ceiling' => $ceiling,
        ]);

        return response()->json($this->state($event->fresh()));
    }

    /* --------------------------------------------------------------------------- helpers */

    private function state(Event $event): array
    {
        return [
            'demand_pricing' => (bool) $event->demand_pricing,
            'price_floor' => $event->price_floor,
            'price_ceiling' => $event->price_ceiling,
            'data' => $this->demand->forEvent($event)
                ->map(fn (EventDemandStep $step) => [
                    'id' => $step->id,
                    'name' => $step->name,
                    'sold_from' => $step->sold_from,
                    'kind' => $step->kind,
                    'value' => $step->value,
                ])->all(),
            /*
             * How the night is actually going, said even when the switch is off.
             *
             * That is the number an organiser needs in order to decide whether to turn it on at
             * all, and a screen that only showed it afterwards would be asking somebody to set a
             * ladder in the dark.
             */
            'sold_percent' => $this->demand->soldPercent($event),
            'capacity' => $this->demand->capacity($event),
            'in_force' => $this->prices->describe($event),
        ];
    }
}
