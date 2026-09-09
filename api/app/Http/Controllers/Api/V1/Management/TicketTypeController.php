<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The kinds of ticket one event sells.
 *
 * Saved as a whole list, like the price zones this sits beside, and for the same reason: a
 * concession set is a decision about the event, and editing one row at a time is how an event ends
 * up with a child rate from last season and a student rate from this one.
 *
 * A type that has sold a ticket is not deleted. The order it paid for still points at it, and a
 * deleted row would leave that order describing a ticket nobody can explain — so it is hidden,
 * which stops it being offered and leaves the history intact.
 */
class TicketTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        return response()->json([
            'data' => TicketType::where('event_id', $event->id)
                ->orderBy('position')
                ->get()
                ->map(fn (TicketType $type) => $this->present($type, $this->soldCounts($event)))
                ->values(),
        ]);
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'pricing.manage');

        $data = $request->validate([
            'types' => ['present', 'array', 'max:20'],
            'types.*.id' => ['nullable', 'uuid'],
            'types.*.name' => ['required', 'string', 'max:80'],
            'types.*.description' => ['nullable', 'string', 'max:200'],
            'types.*.kind' => ['required', Rule::in(['standard', 'percent_off', 'amount_off', 'fixed'])],
            'types.*.value' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'types.*.is_default' => ['sometimes', 'boolean'],
            'types.*.min_per_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'types.*.max_per_order' => ['nullable', 'integer', 'min:1', 'max:999'],
            'types.*.proof_note' => ['nullable', 'string', 'max:160'],
            'types.*.status' => ['sometimes', Rule::in(['active', 'hidden'])],
        ]);

        $sold = $this->soldCounts($event);
        $existing = TicketType::where('event_id', $event->id)->get()->keyBy('id');
        $keptIds = array_values(array_filter(array_column($data['types'], 'id')));

        // Anything the caller left out is being removed. Sold types are not removable; say which
        // ones and change nothing, rather than half-applying the save.
        $removing = $existing->keys()->diff($keptIds);
        $blocked = $removing->filter(fn (string $id) => ($sold[$id] ?? 0) > 0)->values();

        if ($blocked->isNotEmpty()) {
            throw ApiException::conflict(
                'ticket_type_sold',
                'A ticket type that has been sold cannot be removed. Hide it instead.',
                ['ticket_type_ids' => $blocked->all()],
            );
        }

        $types = $this->withOneDefault($data['types']);

        DB::transaction(function () use ($event, $types, $existing, $keptIds) {
            TicketType::where('event_id', $event->id)
                ->whereNotIn('id', $keptIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();

            /*
             * Two passes over the default flag.
             *
             * The database holds "at most one default per event" as a partial unique index, so
             * moving the flag from one row to another in a single pass would collide the moment
             * both rows carried it. Clearing first costs one statement and makes the order of the
             * list irrelevant.
             */
            TicketType::where('event_id', $event->id)->update(['is_default' => false]);

            foreach ($types as $position => $row) {
                $attributes = [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'kind' => $row['kind'],
                    'value' => 'standard' === $row['kind'] ? 0 : (int) ($row['value'] ?? 0),
                    'is_default' => (bool) ($row['is_default'] ?? false),
                    'min_per_order' => (int) ($row['min_per_order'] ?? 0),
                    'max_per_order' => $row['max_per_order'] ?? null,
                    'proof_note' => $row['proof_note'] ?? null,
                    'position' => $position,
                    'status' => $row['status'] ?? 'active',
                ];

                $type = ($row['id'] ?? null) ? $existing->get($row['id']) : null;

                if ($type) {
                    /*
                     * Written as a statement rather than through the model on purpose.
                     *
                     * `$existing` was read before the clearing update above, so its copy of
                     * `is_default` is stale — and a model whose in-memory value already matches
                     * what is being assigned is not dirty, so `save()` would write nothing and the
                     * event would end up with no default at all.
                     */
                    TicketType::whereKey($type->id)->update($attributes + ['updated_at' => now()]);

                    continue;
                }

                TicketType::create($attributes + [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                ]);
            }
        });

        $this->audit->record('event.ticket_types_set', $event, [
            'count' => count($types),
            'names' => array_column($types, 'name'),
        ]);

        return $this->index($request, $event);
    }

    /**
     * Exactly one default, whatever the caller sent.
     *
     * The default is what a seat costs before anybody chooses anything, and what an integration
     * that has never heard of concessions sells at. A list with none would make the first row the
     * default by accident of ordering; a list with two would be rejected by the database with an
     * error nobody could act on.
     */
    private function withOneDefault(array $types): array
    {
        if ($types === []) {
            return [];
        }

        $chosen = null;

        foreach ($types as $index => $row) {
            if (($row['is_default'] ?? false) && null === $chosen) {
                $chosen = $index;
            }

            $types[$index]['is_default'] = false;
        }

        $types[$chosen ?? 0]['is_default'] = true;

        return $types;
    }

    /** @return array<string, int> ticket type id => tickets sold at it */
    private function soldCounts(Event $event): array
    {
        return Allocation::where('event_id', $event->id)
            ->whereNotNull('ticket_type_id')
            ->selectRaw('ticket_type_id, count(*) as sold')
            ->groupBy('ticket_type_id')
            ->pluck('sold', 'ticket_type_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function present(TicketType $type, array $sold): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'description' => $type->description,
            'kind' => $type->kind,
            'value' => $type->value,
            'is_default' => (bool) $type->is_default,
            'min_per_order' => $type->min_per_order,
            'max_per_order' => $type->max_per_order,
            'proof_note' => $type->proof_note,
            'position' => $type->position,
            'status' => $type->status,
            // Why a row cannot be deleted, said on the row rather than in an error after the fact.
            'sold' => $sold[$type->id] ?? 0,
        ];
    }
}
