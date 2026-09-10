<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Access\AccessCodes;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Models\TicketType;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Presale codes, from the organiser's side.
 *
 * Behind `discounts.manage`, the same permission that owns the other kind of code: both are
 * "somebody may make a promise on this account's behalf", and splitting them would give a venue
 * two permissions to reason about for one job.
 *
 * A code that has opened a door is not deleted, for the same reason a used discount is not: the
 * booking has to keep pointing at why it was allowed. Pausing is how a code is stopped.
 */
class AccessCodeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessCodes $codes,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $codes = AccessCode::query()
            ->with('event:id,name')
            ->when($request->query('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('code', 'ilike', "%{$term}%")
                    ->orWhere('label', 'ilike', "%{$term}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($codes, fn (AccessCode $code) => $this->present($code));
    }

    /** A code nobody has to invent, in an alphabet nobody has to read twice. */
    public function suggest(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        do {
            $code = AccessCode::suggest();
        } while (AccessCode::where('code', $code)->exists());

        return response()->json(['code' => $code]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'discounts.manage');

        $data = $this->validated($request);

        if (AccessCode::where('code', $data['code'])->exists()) {
            throw ApiException::conflict('access_code_taken', 'You already have a code with that name.');
        }

        $code = AccessCode::create($data + ['created_by' => $request->user()?->id]);

        $this->codes->record('access_code.created', $code, ['opens' => $code->opens]);

        return response()->json($this->present($code->fresh('event')), 201);
    }

    public function show(Request $request, AccessCode $accessCode)
    {
        $this->authorize($request, 'discounts.manage');

        return response()->json($this->present($accessCode->loadMissing('event')) + [
            'uses' => $accessCode->uses()
                ->with('order:id,external_order_id,status,buyer')
                ->whereNull('released_at')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($use) => [
                    'id' => $use->id,
                    'seats' => $use->seats,
                    'at' => $use->created_at?->toIso8601String(),
                    'order_id' => $use->order?->external_order_id,
                    'order_status' => $use->order?->status,
                    'buyer' => $use->order?->buyer['name'] ?? null,
                ])->all(),
        ]);
    }

    public function update(Request $request, AccessCode $accessCode)
    {
        $this->authorize($request, 'discounts.manage');

        // The code itself is not editable. It has been emailed to a mailing list by now, and
        // renaming it would break every copy of that email at once.
        $data = $this->validated($request, $accessCode);
        unset($data['code']);

        $accessCode->fill($data);

        $this->audit->recordChange('access_code.updated', $accessCode);

        $accessCode->save();

        return response()->json($this->present($accessCode->fresh('event')));
    }

    public function destroy(Request $request, AccessCode $accessCode)
    {
        $this->authorize($request, 'discounts.manage');

        if ($accessCode->uses()->exists()) {
            throw ApiException::conflict(
                'access_code_in_use',
                'This code has let somebody in. Pause it instead — deleting it would leave those bookings unexplained.'
            );
        }

        $accessCode->delete();
        $this->codes->record('access_code.deleted', $accessCode);

        return response()->noContent();
    }

    /** @param  AccessCode|null  $existing  present on update, so `code` is optional there */
    private function validated(Request $request, ?AccessCode $existing = null): array
    {
        $data = $request->validate([
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/^[\pL\pN._-]+$/u'],
            'label' => ['nullable', 'string', 'max:160'],
            'event_id' => ['nullable', 'uuid', Rule::exists('events', 'id')],
            'opens' => ['sometimes', Rule::in(AccessCode::OPENS)],
            'ticket_type_ids' => ['sometimes', 'nullable', 'array'],
            'ticket_type_ids.*' => ['uuid'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_seats' => ['nullable', 'integer', 'min:1', 'max:999'],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ]);

        if (array_key_exists('code', $data)) {
            $data['code'] = AccessCode::normalise($data['code']);
        }

        if (! empty($data['ticket_type_ids'])) {
            $eventId = $data['event_id'] ?? $existing?->event_id;

            // A code that unlocks a concession belonging to another event unlocks nothing, and
            // would look like a code that works right up until somebody tried it.
            $mine = TicketType::whereIn('id', $data['ticket_type_ids'])
                ->when($eventId, fn ($q) => $q->where('event_id', $eventId))
                ->pluck('id')
                ->all();

            if (count($mine) !== count($data['ticket_type_ids'])) {
                throw ApiException::unprocessable(
                    'unknown_ticket_type',
                    'One of those ticket types does not belong to this event.'
                );
            }
        }

        return $data;
    }

    private function present(AccessCode $code): array
    {
        $used = $this->codes->used($code);

        return [
            'id' => $code->id,
            'code' => $code->code,
            'label' => $code->label,
            'event_id' => $code->event_id,
            'event_name' => $code->event?->name,
            'opens' => $code->opens,
            'ticket_type_ids' => $code->ticket_type_ids ?? [],
            'starts_at' => $code->starts_at?->toIso8601String(),
            'ends_at' => $code->ends_at?->toIso8601String(),
            'max_uses' => $code->max_uses,
            'max_seats' => $code->max_seats,
            // Live uses, not presses: a basket somebody abandoned gave its use back.
            'used_count' => $used,
            'status' => $code->status,
            'live' => $code->isLive() && ! $this->codes->isUsedUp($code),
            'created_at' => $code->created_at?->toIso8601String(),
        ];
    }
}
