<?php

namespace App\Domain\SeatMaps;

use App\Exceptions\ApiException;
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
 * Turns a draft version's geometry into normalised sections, rows, seats and placements, then
 * freezes the version.
 *
 * The important behaviour is **id carry-forward**: seats are matched to existing rows by
 * `(section.key, row.key, seat.key)`, so republishing a map keeps every seat's UUID. Without this,
 * a republish would orphan every allocation ever made against the map (ADR-0002).
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

        $report = $this->validator->validate($version->geometry ?? []);

        if (! $report['valid']) {
            throw ApiException::unprocessable(
                'invalid_geometry',
                'The seat map cannot be published until its errors are resolved.',
                ['errors' => $report['errors'], 'warnings' => $report['warnings']],
            );
        }

        $this->assertWithinPlanLimits($report['seat_count']);

        return DB::transaction(function () use ($map, $version, $report, $publishedBy) {
            $geometry = $version->geometry;

            $sectionIds = $this->syncSections($map, $geometry['sections']);
            $rowIds = $this->syncRows($map, $geometry['sections'], $sectionIds);
            $seatIds = $this->syncSeats($map, $geometry['sections'], $sectionIds, $rowIds);

            $this->writePlacements($version, $geometry['sections'], $seatIds);

            $version->forceFill([
                'status' => 'published',
                'seat_count' => $report['seat_count'],
                'checksum' => hash('sha256', json_encode($geometry)),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ])->save();

            $map->forceFill(['published_version_id' => $version->id])->save();

            $this->audit->record('seat_map.published', $version, [
                'seat_map_id' => $map->id,
                'version' => $version->version,
                'seat_count' => $report['seat_count'],
                'warnings' => count($report['warnings']),
            ]);

            return $version->fresh();
        });
    }

    /** @return array<string, string> section key => section id */
    private function syncSections(SeatMap $map, array $sections): array
    {
        $existing = Section::where('seat_map_id', $map->id)->get()->keyBy('key');
        $ids = [];

        foreach (array_values($sections) as $order => $section) {
            $model = $existing->get($section['key']);

            if ($model) {
                $model->forceFill([
                    'name' => $section['name'],
                    'color' => $section['color'] ?? null,
                    'sort_order' => $order,
                ])->save();
            } else {
                $model = Section::create([
                    'seat_map_id' => $map->id,
                    'key' => $section['key'],
                    'name' => $section['name'],
                    'color' => $section['color'] ?? null,
                    'sort_order' => $order,
                ]);
            }

            $ids[$section['key']] = $model->id;
        }

        return $ids;
    }

    /** @return array<string, string> "sectionKey/rowKey" => row id */
    private function syncRows(SeatMap $map, array $sections, array $sectionIds): array
    {
        $existing = SeatRow::where('seat_map_id', $map->id)->get()
            ->keyBy(fn (SeatRow $row) => $row->section_id.'/'.$row->key);

        $ids = [];

        foreach ($sections as $section) {
            $sectionId = $sectionIds[$section['key']];

            foreach (array_values($section['rows'] ?? []) as $order => $row) {
                $model = $existing->get($sectionId.'/'.$row['key']);

                if ($model) {
                    $model->forceFill(['name' => $row['name'], 'sort_order' => $order])->save();
                } else {
                    $model = SeatRow::create([
                        'seat_map_id' => $map->id,
                        'section_id' => $sectionId,
                        'key' => $row['key'],
                        'name' => $row['name'],
                        'sort_order' => $order,
                    ]);
                }

                $ids[$section['key'].'/'.$row['key']] = $model->id;
            }
        }

        return $ids;
    }

    /** @return array<string, string> "sectionKey/rowKey/seatKey" => seat id (stable across versions) */
    private function syncSeats(SeatMap $map, array $sections, array $sectionIds, array $rowIds): array
    {
        $existing = Seat::where('seat_map_id', $map->id)->get()
            ->keyBy(fn (Seat $seat) => $seat->seat_row_id.'/'.$seat->key);

        $ids = [];
        $insert = [];
        $now = now();

        foreach ($sections as $section) {
            $sectionId = $sectionIds[$section['key']];

            foreach ($section['rows'] ?? [] as $row) {
                $rowId = $rowIds[$section['key'].'/'.$row['key']];

                foreach ($row['seats'] ?? [] as $seat) {
                    $composite = $section['key'].'/'.$row['key'].'/'.$seat['key'];
                    $model = $existing->get($rowId.'/'.$seat['key']);

                    if ($model) {
                        // Same chair, possibly relabelled. Keep the id — allocations depend on it.
                        $model->forceFill([
                            'label' => $seat['label'],
                            'accessible' => (bool) ($seat['accessible'] ?? false),
                        ])->save();

                        $ids[$composite] = $model->id;

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
                        'attributes' => '{}',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $ids[$composite] = $id;
                }
            }
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            Seat::insert($chunk);
        }

        return $ids;
    }

    private function writePlacements(SeatMapVersion $version, array $sections, array $seatIds): void
    {
        SeatPlacement::where('seat_map_version_id', $version->id)->delete();

        $rows = [];
        $now = now();

        foreach ($sections as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                foreach ($row['seats'] ?? [] as $seat) {
                    $composite = $section['key'].'/'.$row['key'].'/'.$seat['key'];

                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $this->tenantContext->idOrFail(),
                        'seat_map_version_id' => $version->id,
                        'seat_id' => $seatIds[$composite],
                        'x' => (float) $seat['x'],
                        'y' => (float) $seat['y'],
                        'rotation' => (float) ($seat['rotation'] ?? 0),
                        'shape' => $seat['shape'] ?? 'circle',
                        'zone_key' => $seat['zone_key'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            SeatPlacement::insert($chunk);
        }
    }

    private function assertWithinPlanLimits(int $seatCount): void
    {
        $tenant = $this->tenantContext->get();
        $plan = $tenant?->subscription?->plan;
        $limit = $plan?->limit('max_seats_per_map');

        if ($limit !== null && $seatCount > $limit) {
            throw ApiException::unprocessable('plan_limit_reached', sprintf(
                'Your plan allows %d seats per map; this map has %d.', $limit, $seatCount
            ), ['limit' => $limit, 'seat_count' => $seatCount]);
        }
    }
}
