<?php

namespace App\Domain\SeatMaps;

use App\Exceptions\ApiException;
use App\Models\CapacityObject;
use App\Models\CapacityPlacement;
use App\Models\Seat;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\SeatPlacement;
use App\Models\SeatRow;
use App\Models\Section;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a draft chart into the normalised rows the rest of the system sells against, then freezes
 * the version.
 *
 * The load-bearing behaviour is **id carry-forward**. Seats and capacity objects are matched to
 * existing rows by their key, so republishing a map keeps every id. Without it a republish would
 * orphan every allocation ever made against the map (ADR-0002) — which is the difference between
 * "we changed the seating plan" and "every ticket we have sold now points at nothing".
 *
 * Seat positions are computed here rather than read from the chart, because a row stores an anchor,
 * a rotation, a curve and a spacing. `RowGeometry` is a mirror of the designer's maths and the two
 * are pinned together by tests.
 */
class SeatMapPublisher
{
    public function __construct(
        private readonly SeatMapValidator $validator,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    public function publish(SeatMap $map, SeatMapVersion $version, ?string $publishedBy = null): SeatMapVersion
    {
        if ($version->isPublished()) {
            throw ApiException::conflict('already_published', 'This version is already published.');
        }

        $chart = $version->geometry ?? [];
        $report = $this->validator->validate($chart);

        if (! $report['valid']) {
            throw ApiException::unprocessable(
                'invalid_geometry',
                'The seat map cannot be published until its errors are resolved.',
                ['errors' => $report['errors'], 'warnings' => $report['warnings']],
            );
        }

        $this->assertWithinPlanLimits($report['seat_count']);

        return DB::transaction(function () use ($map, $version, $chart, $report, $publishedBy) {
            $flat = $this->flatten($chart);

            $sectionIds = $this->syncSections($map, $flat['sections']);
            $rowIds = $this->syncRows($map, $flat['rows'], $sectionIds);
            $seatIds = $this->syncSeats($map, $flat['rows'], $sectionIds, $rowIds);
            $capacityIds = $this->syncCapacityObjects($map, $flat['capacity'], $sectionIds);

            $this->writeSeatPlacements($version, $flat['rows'], $seatIds);
            $this->writeCapacityPlacements($version, $flat['capacity'], $capacityIds);

            $version->forceFill([
                'status' => 'published',
                'seat_count' => $report['places'],
                'checksum' => hash('sha256', json_encode($chart)),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ])->save();

            $map->forceFill(['published_version_id' => $version->id])->save();

            $this->audit->record('seat_map.published', $version, [
                'seat_map_id' => $map->id,
                'version' => $version->version,
                'seats' => $report['seat_count'],
                'places' => $report['places'],
                'warnings' => count($report['warnings']),
            ]);

            return $version->fresh();
        });
    }

    /**
     * Flatten the chart into the three things that need normalising, remembering which floor and
     * section each came from.
     *
     * A row on a floor and a row inside a section are the same kind of thing to everything
     * downstream; only the section it belongs to differs. Sections that exist only on the floor
     * plan get a synthetic entry so every row has a parent.
     *
     * @return array{sections: list<array>, rows: list<array>, capacity: list<array>}
     */
    private function flatten(array $chart): array
    {
        $sections = [];
        $rows = [];
        $capacity = [];

        foreach ($chart['floors'] ?? [] as $floor) {
            $floorKey = (string) ($floor['key'] ?? '1');

            // Everything drawn directly on a floor still needs a section to belong to.
            $defaultKey = 'floor-'.$floorKey;
            $sections[$defaultKey] = ['key' => $defaultKey, 'name' => $floor['name'] ?? ('Level '.$floorKey), 'color' => null];

            $this->collect($floor['objects'] ?? [], $defaultKey, $floorKey, $sections, $rows, $capacity);
        }

        return ['sections' => array_values($sections), 'rows' => $rows, 'capacity' => $capacity];
    }

    private function collect(
        array $objects,
        string $sectionKey,
        string $floorKey,
        array &$sections,
        array &$rows,
        array &$capacity,
    ): void {
        foreach ($objects as $object) {
            $type = $object['type'] ?? null;

            if ($type === 'section') {
                $key = $object['key'];

                $sections[$key] = [
                    'key' => $key,
                    'name' => $this->labelOf($object) ?? $key,
                    'color' => $object['color'] ?? null,
                ];

                $this->collect($object['objects'] ?? [], $key, $floorKey, $sections, $rows, $capacity);

                continue;
            }

            if ($type === 'row') {
                $rows[] = ['object' => $object, 'section' => $sectionKey, 'floor' => $floorKey];

                continue;
            }

            if ($type === 'area' || $type === 'booth') {
                $capacity[] = [
                    'object' => $object,
                    'section' => $sectionKey,
                    'floor' => $floorKey,
                    'kind' => $type,
                    'places' => (int) ($object['capacity']['places'] ?? 0),
                    'capacity_type' => $object['capacity']['type'] ?? 'generalAdmission',
                ];

                continue;
            }

            if ($type === 'table') {
                // A table sold whole is one capacity object; sold by the chair it is a row of seats
                // laid out around the table instead.
                if (($object['bookAs'] ?? 'seat') === 'table') {
                    $capacity[] = [
                        'object' => $object,
                        'section' => $sectionKey,
                        'floor' => $floorKey,
                        'kind' => 'table',
                        'places' => max(1, count($object['seats'] ?? [])),
                        'capacity_type' => 'fixed',
                    ];

                    continue;
                }

                $rows[] = ['object' => $object, 'section' => $sectionKey, 'floor' => $floorKey, 'table' => true];
            }
        }
    }

    /** @return array<string, string> section key => id */
    private function syncSections(SeatMap $map, array $sections): array
    {
        $existing = Section::where('seat_map_id', $map->id)->get()->keyBy('key');
        $ids = [];

        foreach (array_values($sections) as $order => $section) {
            $model = $existing->get($section['key']);

            if ($model) {
                $model->forceFill(['name' => $section['name'], 'color' => $section['color'], 'sort_order' => $order])->save();
            } else {
                $model = Section::create([
                    'seat_map_id' => $map->id,
                    'key' => $section['key'],
                    'name' => $section['name'],
                    'color' => $section['color'],
                    'sort_order' => $order,
                ]);
            }

            $ids[$section['key']] = $model->id;
        }

        return $ids;
    }

    /** @return array<string, string> row key => id */
    private function syncRows(SeatMap $map, array $rows, array $sectionIds): array
    {
        $existing = SeatRow::where('seat_map_id', $map->id)->get()->keyBy('key');
        $ids = [];

        foreach ($rows as $order => $entry) {
            $object = $entry['object'];
            $model = $existing->get($object['key']);
            $name = $this->labelOf($object) ?? $object['key'];

            if ($model) {
                $model->forceFill([
                    'section_id' => $sectionIds[$entry['section']],
                    'name' => $name,
                    'sort_order' => $order,
                ])->save();
            } else {
                $model = SeatRow::create([
                    'seat_map_id' => $map->id,
                    'section_id' => $sectionIds[$entry['section']],
                    'key' => $object['key'],
                    'name' => $name,
                    'sort_order' => $order,
                ]);
            }

            $ids[$object['key']] = $model->id;
        }

        return $ids;
    }

    /** @return array<string, string> seat key => stable id */
    private function syncSeats(SeatMap $map, array $rows, array $sectionIds, array $rowIds): array
    {
        $existing = Seat::where('seat_map_id', $map->id)->get()->keyBy('key');
        $ids = [];
        $insert = [];
        $now = now();

        foreach ($rows as $entry) {
            $object = $entry['object'];
            $rowId = $rowIds[$object['key']];
            $sectionId = $sectionIds[$entry['section']];

            foreach ($object['seats'] ?? [] as $seat) {
                if (($seat['type'] ?? 'seat') === 'empty') {
                    continue; // A placeholder holds a gap in the row; it is not sellable.
                }

                $model = $existing->get($seat['key']);

                if ($model) {
                    // The same chair, possibly relabelled or moved to another row. Keep the id —
                    // allocations depend on it.
                    $model->forceFill([
                        'section_id' => $sectionId,
                        'seat_row_id' => $rowId,
                        'label' => $seat['label'],
                        'accessible' => (bool) ($seat['accessible'] ?? false),
                    ])->save();

                    $ids[$seat['key']] = $model->id;

                    continue;
                }

                $id = (string) Str::uuid();

                $insert[] = [
                    'id' => $id,
                    'tenant_id' => $this->tenantContext->idOrFail(),
                    'seat_map_id' => $map->id,
                    'section_id' => $sectionId,
                    'seat_row_id' => $rowId,
                    'key' => $seat['key'],
                    'label' => $seat['label'],
                    'accessible' => (bool) ($seat['accessible'] ?? false),
                    'attributes' => json_encode(array_filter([
                        'category_key' => $seat['categoryKey'] ?? null,
                        'entrance' => $seat['entrance'] ?? null,
                    ])),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $ids[$seat['key']] = $id;
            }
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            Seat::insert($chunk);
        }

        return $ids;
    }

    /** @return array<string, string> capacity object key => stable id */
    private function syncCapacityObjects(SeatMap $map, array $capacity, array $sectionIds): array
    {
        $existing = CapacityObject::where('seat_map_id', $map->id)->get()->keyBy('key');
        $ids = [];

        foreach ($capacity as $entry) {
            $object = $entry['object'];
            $model = $existing->get($object['key']);

            $attributes = [
                'section_id' => $sectionIds[$entry['section']],
                'label' => $this->labelOf($object) ?? $object['key'],
                'kind' => $entry['kind'],
                'capacity_type' => $entry['capacity_type'],
                'places' => $entry['places'],
                'attributes' => array_filter(['category_key' => $object['categoryKey'] ?? null]),
            ];

            if ($model) {
                $model->forceFill($attributes)->save();
            } else {
                $model = CapacityObject::create($attributes + [
                    'seat_map_id' => $map->id,
                    'key' => $object['key'],
                ]);
            }

            $ids[$object['key']] = $model->id;
        }

        return $ids;
    }

    private function writeSeatPlacements(SeatMapVersion $version, array $rows, array $seatIds): void
    {
        SeatPlacement::where('seat_map_version_id', $version->id)->delete();

        $insert = [];
        $now = now();

        foreach ($rows as $entry) {
            $object = $entry['object'];
            $positions = ! empty($entry['table'])
                ? RowGeometry::forTable($object)
                : RowGeometry::forRow($object);

            foreach ($object['seats'] ?? [] as $index => $seat) {
                if (! isset($seatIds[$seat['key']], $positions[$index])) {
                    continue;
                }

                $insert[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $this->tenantContext->idOrFail(),
                    'seat_map_version_id' => $version->id,
                    'seat_id' => $seatIds[$seat['key']],
                    'floor_key' => $entry['floor'],
                    'x' => $positions[$index]['x'],
                    'y' => $positions[$index]['y'],
                    'rotation' => $positions[$index]['rotation'],
                    'shape' => 'circle',
                    'zone_key' => $seat['categoryKey'] ?? $object['categoryKey'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            SeatPlacement::insert($chunk);
        }
    }

    private function writeCapacityPlacements(SeatMapVersion $version, array $capacity, array $capacityIds): void
    {
        CapacityPlacement::where('seat_map_version_id', $version->id)->delete();

        $insert = [];
        $now = now();

        foreach ($capacity as $entry) {
            $object = $entry['object'];

            if (! isset($capacityIds[$object['key']])) {
                continue;
            }

            $shape = $this->shapeOf($object);

            $insert[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenantContext->idOrFail(),
                'seat_map_version_id' => $version->id,
                'capacity_object_id' => $capacityIds[$object['key']],
                'floor_key' => $entry['floor'],
                'geometry' => json_encode([
                    'shape' => $shape,
                    'label' => $this->labelOf($object),
                    'zone_key' => $object['categoryKey'] ?? null,
                    'bounds' => RowGeometry::shapeBounds($shape),
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            CapacityPlacement::insert($chunk);
        }
    }

    /**
     * The drawable shape of a capacity object.
     *
     * Areas and booths carry a shape object. A table does not — its `shape` is the word "round" or
     * "rectangular", and its geometry is the width and height around its centre — so one is built
     * for it here rather than letting a string reach code expecting a shape.
     */
    private function shapeOf(array $object): array
    {
        if (isset($object['shape']) && is_array($object['shape'])) {
            return $object['shape'];
        }

        $width = (float) ($object['width'] ?? 0);
        $height = (float) ($object['height'] ?? 0);

        return [
            'kind' => ($object['shape'] ?? 'round') === 'round' ? 'ellipse' : 'rect',
            'x' => (float) ($object['x'] ?? 0) - $width / 2,
            'y' => (float) ($object['y'] ?? 0) - $height / 2,
            'width' => $width,
            'height' => $height,
            'rotation' => (float) ($object['rotation'] ?? 0),
            'cornerRadius' => 4,
            'points' => null,
        ];
    }

    private function labelOf(array $object): ?string
    {
        if (isset($object['labeling'])) {
            $displayed = $object['labeling']['displayedLabel'] ?? null;

            return $displayed !== null && $displayed !== '' ? $displayed : ($object['labeling']['label'] ?? null);
        }

        return $object['label'] ?? null;
    }

    private function assertWithinPlanLimits(int $seatCount): void
    {
        $plan = $this->tenantContext->get()?->subscription?->plan;
        $limit = $plan?->limit('max_seats_per_map');

        if ($limit !== null && $seatCount > $limit) {
            throw ApiException::unprocessable('plan_limit_reached', sprintf(
                'Your plan allows %d seats per map; this map has %d.', $limit, $seatCount
            ), ['limit' => $limit, 'seat_count' => $seatCount]);
        }
    }
}
