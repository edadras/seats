<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Domain\SeatMaps\SeatMapValidator;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\Venue;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatMapController extends Controller
{
    public function __construct(
        private readonly SeatMapValidator $validator,
        private readonly SeatMapPublisher $publisher,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $maps = SeatMap::query()
            ->when($request->query('venue_id'), fn ($q, $id) => $q->where('venue_id', $id))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($maps, fn (SeatMap $map) => $this->present($map, withGeometry: false));
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'venue_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        // Resolved through the tenant scope: a venue belonging to another tenant is simply not
        // found, which is also what stops it being used as an existence oracle.
        $venue = Venue::findOrFail($data['venue_id']);

        $map = DB::transaction(function () use ($data, $venue) {
            $map = SeatMap::create([
                'venue_id' => $venue->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            SeatMapVersion::create([
                'seat_map_id' => $map->id,
                'version' => 1,
                'status' => 'draft',
                'geometry' => $this->emptyGeometry(),
                'seat_count' => 0,
            ]);

            return $map;
        });

        $this->audit->record('seat_map.created', $map, ['name' => $map->name]);

        return response()->json($this->present($map->fresh()), 201);
    }

    public function show(SeatMap $map)
    {
        return response()->json($this->present($map));
    }

    public function update(Request $request, SeatMap $map)
    {
        $this->authorizeWrite($request);

        $map->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json($this->present($map->fresh()));
    }

    public function versions(SeatMap $map)
    {
        return response()->json([
            'data' => $map->versions()->get()->map(fn (SeatMapVersion $v) => $this->presentVersion($v, false)),
        ]);
    }

    /**
     * Save the working draft.
     *
     * If the latest version is published, a new draft is forked from it rather than the published
     * one being edited — published geometry is immutable because orders point at it (ADR-0002).
     */
    public function saveVersion(Request $request, SeatMap $map)
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'geometry' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $report = $this->validator->validate($data['geometry']);

        $version = DB::transaction(function () use ($map, $data, $report) {
            $draft = $map->draftVersion();

            if (! $draft) {
                $next = ((int) $map->versions()->max('version')) + 1;

                $draft = SeatMapVersion::create([
                    'seat_map_id' => $map->id,
                    'version' => $next,
                    'status' => 'draft',
                    'geometry' => $data['geometry'],
                    'seat_count' => $report['seat_count'],
                    'notes' => $data['notes'] ?? null,
                ]);

                return $draft;
            }

            $draft->forceFill([
                'geometry' => $data['geometry'],
                'seat_count' => $report['seat_count'],
                'notes' => $data['notes'] ?? $draft->notes,
            ])->save();

            return $draft;
        });

        return response()->json(
            $this->presentVersion($version->fresh()) + ['validation' => $report],
            201
        );
    }

    public function validateGeometry(Request $request, SeatMap $map)
    {
        $data = $request->validate(['geometry' => ['required', 'array']]);

        return response()->json($this->validator->validate($data['geometry']));
    }

    public function publish(Request $request, SeatMap $map)
    {
        $this->authorizeWrite($request);

        $draft = $map->draftVersion();

        if (! $draft) {
            throw ApiException::conflict('no_draft', 'There is no draft version to publish.');
        }

        $version = $this->publisher->publish($map, $draft, $request->user()?->getKey());

        return response()->json($this->presentVersion($version));
    }

    private function present(SeatMap $map, bool $withGeometry = true): array
    {
        $published = $map->publishedVersion;
        $draft = $map->draftVersion();

        return [
            'id' => $map->id,
            'venue_id' => $map->venue_id,
            'name' => $map->name,
            'description' => $map->description,
            'published_version' => $published ? $this->presentVersion($published, $withGeometry) : null,
            'draft_version' => $draft ? $this->presentVersion($draft, $withGeometry) : null,
        ];
    }

    private function presentVersion(SeatMapVersion $version, bool $withGeometry = true): array
    {
        return array_filter([
            'id' => $version->id,
            'seat_map_id' => $version->seat_map_id,
            'version' => $version->version,
            'status' => $version->status,
            'seat_count' => $version->seat_count,
            'published_at' => $version->published_at?->toIso8601String(),
            'geometry' => $withGeometry ? $version->geometry : null,
        ], fn ($v) => $v !== null);
    }

    /** A new map starts as one empty floor, ready for the designer to draw on. */
    private function emptyGeometry(): array
    {
        return [
            'version' => 2,
            'name' => 'Untitled chart',
            'focalPoint' => null,
            'categories' => [],
            'floors' => [[
                'key' => '1',
                'name' => 'Level 1',
                'canvas' => ['width' => 1200, 'height' => 900, 'background' => null],
                'objects' => [],
            ]],
        ];
    }
}
