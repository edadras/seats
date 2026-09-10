<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Audience\Segments;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Segment;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saved audiences: "came last season, has not booked this one".
 *
 * Behind `messages.send`, because a segment is what a message is addressed to and there is nothing
 * else on this platform you can do with one. It is not a second customer directory: a segment
 * never returns the people it describes, only how many there are. The addresses themselves are
 * used in exactly one place — the delivery log of a send in progress — and a screen that listed
 * them would be a new way to walk out of the building with an account's mailing list.
 */
class SegmentController extends Controller
{
    public function __construct(
        private readonly Segments $segments,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'messages.send');

        return response()->json([
            'data' => Segment::orderBy('name')->get()->map(
                fn (Segment $segment) => $this->present($segment)
            )->values(),
            // What may be said, so the screen builds its controls from the vocabulary rather than
            // from a copy of it that drifts.
            'clauses' => Segments::CLAUSES,
        ]);
    }

    public function show(Request $request, Segment $segment)
    {
        $this->authorize($request, 'messages.send');

        return response()->json($this->present($segment, withCount: true));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'messages.send');

        $data = $this->validated($request);

        $segment = Segment::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => $this->segments->clean($data['rules'] ?? []),
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record('segment.created', $segment, [
            'name' => $segment->name,
            // The rules in words, not identifiers: an audit line of uuids answers nothing later.
            'rules' => $this->segments->explain($segment->rules ?? []),
        ]);

        return response()->json($this->present($segment, withCount: true), 201);
    }

    public function update(Request $request, Segment $segment)
    {
        $this->authorize($request, 'messages.send');

        $data = $this->validated($request, $segment);

        $segment->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => $this->segments->clean($data['rules'] ?? []),
        ])->save();

        $this->audit->record('segment.updated', $segment, [
            'name' => $segment->name,
            'rules' => $this->segments->explain($segment->rules ?? []),
        ]);

        return response()->json($this->present($segment->fresh(), withCount: true));
    }

    public function destroy(Request $request, Segment $segment)
    {
        $this->authorize($request, 'messages.send');

        $name = $segment->name;
        $segment->delete();

        // The announcements that went to it keep their own record of what they reached; the
        // reference is nulled rather than the history being deleted with the list.
        $this->audit->record('segment.deleted', null, ['name' => $name]);

        return response()->noContent();
    }

    /** How many people this would reach, before anything is saved or sent. */
    public function preview(Request $request)
    {
        $this->authorize($request, 'messages.send');

        $data = $request->validate([
            'rules' => ['present', 'array'],
        ]);

        $rules = $this->segments->clean($data['rules']);

        return response()->json([
            'people' => $this->segments->count($rules),
            'rules' => $this->segments->explain($rules),
        ]);
    }

    /* --------------------------------------------------------------------------- internals */

    private function validated(Request $request, ?Segment $segment = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                // One name, one audience: two lists called "Christmas" is a support conversation.
                Rule::unique('segments', 'name')
                    ->where('tenant_id', app(\App\Support\Tenancy\TenantContext::class)->id())
                    ->ignore($segment?->id),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'rules' => ['sometimes', 'array'],
            'rules.bought_events' => ['sometimes', 'array', 'max:200'],
            'rules.bought_events.*' => ['uuid'],
            'rules.not_bought_events' => ['sometimes', 'array', 'max:200'],
            'rules.not_bought_events.*' => ['uuid'],
            'rules.categories' => ['sometimes', 'array', 'max:40'],
            'rules.categories.*' => ['string', 'max:60'],
            'rules.since' => ['sometimes', 'nullable', 'date'],
            'rules.until' => ['sometimes', 'nullable', 'date'],
            'rules.min_orders' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'rules.min_spend' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rules.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'rules.attended' => ['sometimes', 'boolean'],
        ]);

        /*
         * Spend without a currency is refused rather than assumed.
         *
         * An account selling in euros and rials has no single number for "spent more than a
         * hundred", and adding the two would produce a segment whose whole membership is an
         * arithmetic mistake. The resolver ignores the clause without a currency too; this is the
         * door that says so out loud.
         */
        if (! empty($data['rules']['min_spend']) && empty($data['rules']['currency'])) {
            throw ApiException::unprocessable(
                'spend_needs_currency',
                'Say which currency that amount is in — an account that sells in two of them has two answers.',
            );
        }

        return $data;
    }

    private function present(Segment $segment, bool $withCount = false): array
    {
        $rules = $segment->rules ?? [];

        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'rules' => $rules,
            // The same rules in names rather than identifiers, so a screen can show what a saved
            // audience means without fetching every event to find out.
            'explained' => $this->segments->explain($rules),
            'created_at' => $segment->created_at?->toIso8601String(),
        ] + ($withCount ? ['people' => $this->segments->count($rules)] : []);
    }
}
