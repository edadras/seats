<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        $venues = Venue::query()
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'ilike', "%{$term}%"))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($venues, fn (Venue $venue) => $this->present($venue));
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ]);

        $venue = Venue::create($data + ['timezone' => $data['timezone'] ?? 'UTC']);

        $this->audit->record('venue.created', $venue, ['name' => $venue->name]);

        return response()->json($this->present($venue), 201);
    }

    public function show(Venue $venue)
    {
        return response()->json($this->present($venue));
    }

    public function update(Request $request, Venue $venue)
    {
        $this->authorizeWrite($request);

        $venue->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]));

        $this->audit->record('venue.updated', $venue);

        return response()->json($this->present($venue->fresh()));
    }

    public function destroy(Request $request, Venue $venue)
    {
        $this->authorizeWrite($request);

        if ($venue->events()->exists()) {
            throw ApiException::conflict(
                'venue_in_use',
                'This venue still has events. Delete or move them first.'
            );
        }

        $venue->delete();
        $this->audit->record('venue.deleted', $venue);

        return response()->noContent();
    }

    private function present(Venue $venue): array
    {
        return [
            'id' => $venue->id,
            'name' => $venue->name,
            'address' => $venue->address,
            'city' => $venue->city,
            'country' => $venue->country,
            'timezone' => $venue->timezone,
            'created_at' => $venue->created_at?->toIso8601String(),
        ];
    }
}
