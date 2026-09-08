<?php

namespace App\Domain\SeatMaps;

use App\Models\SeatMapVersion;
use Illuminate\Support\Facades\DB;

/**
 * The published geometry a buyer's browser gets, with the stable ids folded in.
 *
 * A chart's JSON identifies its seats by a key that is only unique inside the chart. Publishing
 * explodes it into `seats` / `capacity_objects` rows carrying platform-wide ids, and *those* are
 * what a hold is placed against.
 *
 * Without this pairing the client would have to match the geometry against the availability list by
 * position, and nothing guarantees the two share an order — one reshuffle and every buyer selects
 * the wrong chair. So the pairing is done once, here, on the server.
 */
class PublishedGeometry
{
    public function forVersion(SeatMapVersion $version): array
    {
        $geometry = $version->geometry;

        $seatIds = DB::table('seat_placements as sp')
            ->join('seats as s', 's.id', '=', 'sp.seat_id')
            ->where('sp.seat_map_version_id', $version->id)
            ->pluck('sp.seat_id', 's.key');

        $capacityIds = DB::table('capacity_placements as cp')
            ->join('capacity_objects as c', 'c.id', '=', 'cp.capacity_object_id')
            ->where('cp.seat_map_version_id', $version->id)
            ->pluck('cp.capacity_object_id', 'c.key');

        $annotate = function (array &$objects) use (&$annotate, $seatIds, $capacityIds) {
            foreach ($objects as &$object) {
                $type = $object['type'] ?? null;

                if ('section' === $type) {
                    if (isset($object['objects'])) {
                        $annotate($object['objects']);
                    }

                    continue;
                }

                // Iterate the real offset, not `$object['seats'] ?? []` — the null-coalesce would
                // hand the loop a temporary copy and the ids would be written to nothing.
                if (('row' === $type || 'table' === $type) && isset($object['seats'])) {
                    foreach ($object['seats'] as &$seat) {
                        $seat['seat_id'] = $seatIds[$seat['key']] ?? null;
                    }
                    unset($seat);
                }

                if (in_array($type, ['area', 'booth', 'table'], true)) {
                    $object['capacity_object_id'] = $capacityIds[$object['key']] ?? null;
                }
            }
            unset($object);
        };

        foreach (($geometry['floors'] ?? []) as $index => $unusedFloor) {
            if (isset($geometry['floors'][$index]['objects'])) {
                $annotate($geometry['floors'][$index]['objects']);
            }
        }

        return $geometry;
    }
}
