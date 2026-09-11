<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\SeatMaps\SeatViews;

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
        $this->authorize($request, 'maps.view');

        $maps = SeatMap::query()
            // Eager loaded, or presenting a page of maps would issue two queries per map — and
            // outside production the lazy-load guard turns that into a 500 rather than a slow page.
            ->with(['publishedVersion', 'versions'])
            ->when($request->query('venue_id'), fn ($q, $id) => $q->where('venue_id', $id))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->paginated($maps, fn (SeatMap $map) => $this->present($map, withGeometry: false));
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'maps.manage');

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

        return response()->json($this->present($map->fresh(['publishedVersion', 'versions'])), 201);
    }

    public function show(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.view');

        $map->load(['publishedVersion', 'versions']);

        return response()->json($this->present($map));
    }

    public function update(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.manage');

        $map->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json($this->present($map->fresh(['publishedVersion', 'versions'])));
    }

    /**
     * What a buyer would see from each section of this chart.
     *
     * Every section comes back whether or not it has a picture, because the screen is a list of
     * places to attach one to rather than a list of pictures. Read from the draft where there is
     * one: an organiser attaching a photograph to a section they have just drawn is exactly the
     * moment they are thinking about it.
     */
    public function views(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.view');

        return response()->json(['data' => app(SeatViews::class)->forMap($map)]);
    }

    public function saveViews(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.manage');

        $data = $request->validate([
            'views' => ['required', 'array', 'max:200'],
            'views.*.section_key' => ['required', 'string', 'max:120'],
            // Empty takes the picture away. `url:http,https` rather than `url`, which would accept
            // javascript: and data: — this address ends up in an `img src` on a page we serve.
            'views.*.url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'views.*.caption' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $saved = app(SeatViews::class)->save($map, $data['views']);

        $this->audit->record('seat_map.views_saved', $map, [
            'name' => $map->name,
            'pictures' => count(array_filter($saved, fn (array $row) => (bool) $row['url'])),
        ]);

        return response()->json(['data' => $saved]);
    }

    public function versions(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.view');

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
        $this->authorize($request, 'maps.manage');

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
        $this->authorize($request, 'maps.manage');

        $data = $request->validate(['geometry' => ['required', 'array']]);

        return response()->json($this->validator->validate($data['geometry']));
    }

    public function publish(Request $request, SeatMap $map)
    {
        $this->authorize($request, 'maps.publish');

        $draft = $map->draftVersion();

        if (! $draft) {
            throw ApiException::conflict('no_draft', 'There is no draft version to publish.');
        }

        $version = $this->publisher->publish($map, $draft, $request->user()?->getKey());

        return response()->json($this->presentVersion($version));
    }

    private function present(SeatMap $map, bool $withGeometry = true): array
    {
        $map->loadMissing(['publishedVersion', 'versions']);

        $published = $map->publishedVersion;
        // Read from the loaded collection rather than querying again, so a listing stays one query.
        $draft = $map->versions->where('status', 'draft')->sortByDesc('version')->first();

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
