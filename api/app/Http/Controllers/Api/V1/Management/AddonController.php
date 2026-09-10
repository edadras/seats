<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Addons\Addons;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Event;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What an event sells beside its tickets.
 *
 * Saved as a whole list, like the ticket types this sits beside and for the same reason: what is
 * on the counter is one decision, and editing a row at a time is how a venue ends up offering last
 * season's programme next to this season's.
 *
 * An add-on somebody has bought is not deleted. The booking it belongs to still points at it, and
 * a deleted row would leave a buyer holding a receipt for something nobody can name — so it is
 * hidden, which stops it being offered and leaves the history intact.
 *
 * Behind `pricing.manage`, because setting a price is what this is.
 */
class AddonController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Addons $addons,
    ) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $rows = Addon::query()
            ->where(fn ($q) => $q->whereNull('event_id')->orWhere('event_id', $event->id))
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        $sold = $this->addons->sold($rows->pluck('id')->all());

        return response()->json([
            'data' => $rows->map(fn (Addon $addon) => $this->present($addon, $sold))->values(),
            // The donation prompt belongs to the event and is edited on the same screen, because
            // "what else can somebody give you money for" is one question to an organiser.
            'donations' => [
                'offered' => (bool) $event->donations,
                'prompt' => $event->donation_prompt,
                'suggested' => $event->donation_suggested,
            ],
            'currency' => $event->currency,
        ]);
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'addons' => ['present', 'array', 'max:30'],
            'addons.*.id' => ['nullable', 'uuid'],
            'addons.*.name' => ['required', 'string', 'max:120'],
            'addons.*.description' => ['nullable', 'string', 'max:400'],
            'addons.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'addons.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'addons.*.max_per_order' => ['nullable', 'integer', 'min:1', 'max:999'],
            'addons.*.per' => ['sometimes', \Illuminate\Validation\Rule::in(Addon::PER)],
            'addons.*.visible' => ['sometimes', 'boolean'],
            'donations' => ['sometimes', 'boolean'],
            'donation_prompt' => ['nullable', 'string', 'max:200'],
            'donation_suggested' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $existing = Addon::where('event_id', $event->id)->get()->keyBy('id');
        $keptIds = array_values(array_filter(array_column($data['addons'], 'id')));
        $sold = $this->addons->sold($existing->keys()->all());

        // Anything left out is being removed. Say which ones cannot go, and change nothing, rather
        // than half-applying the save.
        $blocked = $existing->keys()
            ->diff($keptIds)
            ->filter(fn (string $id) => ($sold[$id] ?? 0) > 0)
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ApiException::conflict(
                'addon_sold',
                'Something that has been bought cannot be removed. Hide it instead.',
                ['addon_ids' => $blocked->all()],
            );
        }

        DB::transaction(function () use ($event, $data, $existing, $keptIds) {
            Addon::where('event_id', $event->id)
                ->whereNotIn('id', $keptIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();

            foreach ($data['addons'] as $position => $row) {
                $attributes = [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'price' => (int) $row['price'],
                    // The event's money, always: a programme priced in euros against a rial event
                    // is a line nobody can add up.
                    'currency' => $event->currency,
                    'stock' => array_key_exists('stock', $row) ? $row['stock'] : null,
                    'max_per_order' => (int) ($row['max_per_order'] ?? 10),
                    'per' => $row['per'] ?? 'order',
                    'position' => $position,
                    'visible' => (bool) ($row['visible'] ?? true),
                ];

                $addon = ($row['id'] ?? null) ? $existing->get($row['id']) : null;

                if ($addon) {
                    $addon->fill($attributes)->save();

                    continue;
                }

                Addon::create($attributes + [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                ]);
            }

            if (array_key_exists('donations', $data)) {
                $event->forceFill([
                    'donations' => (bool) $data['donations'],
                    'donation_prompt' => $data['donation_prompt'] ?? null,
                    'donation_suggested' => $data['donation_suggested'] ?? null,
                ])->save();
            }
        });

        $this->audit->record('event.addons_set', $event, [
            'count' => count($data['addons']),
            'names' => array_column($data['addons'], 'name'),
            'donations' => (bool) ($data['donations'] ?? $event->donations),
        ]);

        return $this->index($request, $event->fresh());
    }

    /** @param  array<string, int>  $sold */
    private function present(Addon $addon, array $sold): array
    {
        $taken = (int) ($sold[$addon->id] ?? 0);

        return [
            'id' => $addon->id,
            'name' => $addon->name,
            'description' => $addon->description,
            'price' => $addon->price,
            'currency' => $addon->currency,
            'stock' => $addon->stock,
            'max_per_order' => $addon->max_per_order,
            'per' => $addon->per,
            'visible' => $addon->visible,
            'sold' => $taken,
            // Counted, not decremented — a cancelled booking gave its programmes back.
            'remaining' => null === $addon->stock ? null : max(0, $addon->stock - $taken),
            // Whether the whole account offers this, rather than this night alone.
            'shared' => null === $addon->event_id,
        ];
    }
}
