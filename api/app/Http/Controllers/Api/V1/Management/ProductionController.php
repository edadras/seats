<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Productions\Productions;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventSeries;
use App\Support\Access\Gate;
use Illuminate\Http\Request;

/**
 * One show across many nights and many towns.
 *
 * Reading is `events.view`, because a production is a way of looking at events. Money is
 * `reports.orders.view` and is withheld as null rather than as nought: nought is a figure, and a
 * figure somebody may not see should not be guessable from a screen.
 */
class ProductionController extends Controller
{
    public function __construct(private readonly Productions $productions) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'events.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json(['data' => $this->productions->all($this->maySeeMoney($request))]);
    }

    public function show(Request $request, EventSeries $production)
    {
        $this->authorize($request, 'events.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json(
            $this->present($production) + $this->productions->summary($production, $this->maySeeMoney($request))
        );
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'events.manage');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        // No tenant_id: the model stamps it from the bound tenant, and a write that forgot one
        // fails loudly rather than creating a row visible to nobody.
        $production = EventSeries::create([
            'name' => $data['name'],
            'slug' => EventSeries::slugFor($data['name']),
            'description' => $data['description'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'category' => $data['category'] ?? null,
        ]);

        return response()->json($this->present($production), 201);
    }

    public function update(Request $request, EventSeries $production)
    {
        $this->authorize($request, 'events.manage');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'image_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        // The slug is the address of the run's page and is left alone by a rename: a poster with a
        // link on it is printed once.
        $production->fill($data)->save();

        return response()->json($this->present($production->fresh()));
    }

    /**
     * Put the show on somewhere else.
     *
     * The night being copied defaults to the last date of the run — the one whose prices somebody
     * last thought about — rather than the first, which may be a year old.
     */
    public function addDate(Request $request, EventSeries $production)
    {
        $this->authorize($request, 'events.manage');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $data = $request->validate([
            'venue_id' => ['required', 'uuid'],
            'seat_map_id' => ['required', 'uuid'],
            'starts_at' => ['required', 'date'],
            'from_event_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /*
         * The night being copied.
         *
         * Any event of this organiser's, not only one already in the run: a tour has to start
         * somewhere, and what somebody has in front of them when they start one is the show they
         * already put on at home. Copying from it puts it in the run, which is the same thing
         * repeating an event does and for the same reason — the first night is a date of the tour.
         *
         * Left unsaid, it is the most recent date already in the run: the one whose prices
         * somebody last thought about, rather than one from a year ago.
         */
        $from = ($data['from_event_id'] ?? null)
            ? Event::findOrFail($data['from_event_id'])
            : Event::where('series_id', $production->id)->orderByDesc('starts_at')->first();

        if (! $from) {
            throw ApiException::unprocessable(
                'production_has_no_dates',
                'Say which night to copy: a tour starts from a show you have already put on.',
            );
        }

        $night = $this->productions->addStop($production, $from, $data);

        return response()->json([
            'id' => $night->id,
            'public_id' => $night->public_id,
            'name' => $night->name,
            'status' => $night->status,
            'starts_at' => $night->starts_at?->toIso8601String(),
            'venue_id' => $night->venue_id,
            'seat_map_id' => $night->seat_map_id,
            'timezone' => $night->timezone,
        ], 201);
    }

    public function destroy(Request $request, EventSeries $production)
    {
        $this->authorize($request, 'events.manage');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        if (Event::where('series_id', $production->id)->exists()) {
            throw ApiException::conflict(
                'production_has_dates',
                'This production still has dates. Move or delete them before deleting the run.',
            );
        }

        $production->delete();

        return response()->json(['deleted' => true]);
    }

    private function maySeeMoney(Request $request): bool
    {
        return app(Gate::class)->allows($request, 'reports.orders.view');
    }

    /** @return array<string, mixed> */
    private function present(EventSeries $production): array
    {
        return [
            'id' => $production->id,
            'name' => $production->name,
            'slug' => $production->slug,
            'description' => $production->description,
            'image_url' => $production->image_url,
            'category' => $production->category,
        ];
    }
}
