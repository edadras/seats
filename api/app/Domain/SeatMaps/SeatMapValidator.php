<?php

namespace App\Domain\SeatMaps;

/**
 * Validates geometry before it is saved or published.
 *
 * Two severities, and the distinction matters: an **error** blocks publishing because it would
 * produce a map that cannot be sold correctly (duplicate keys, no seats, a seat off-canvas).
 * A **warning** is a layout smell the organiser may well have intended — overlapping seats,
 * a row whose numbering skips — so it is surfaced but never blocks.
 */
class SeatMapValidator
{
    /** Two seats closer than this (canvas units) are almost certainly a mistake. */
    private const OVERLAP_DISTANCE = 8.0;

    public function __construct(private readonly array $limits) {}

    public static function make(): self
    {
        return new self(config('seatmap.limits'));
    }

    /**
     * @return array{valid: bool, seat_count: int, errors: list<array>, warnings: list<array>}
     */
    public function validate(array $geometry): array
    {
        $errors = [];
        $warnings = [];

        $encoded = json_encode($geometry);
        if ($encoded !== false && strlen($encoded) > $this->limits['max_geometry_bytes']) {
            $errors[] = $this->issue('geometry_too_large', sprintf(
                'Geometry is %d bytes; the limit is %d.', strlen($encoded), $this->limits['max_geometry_bytes']
            ), 'geometry');

            // Bail out early: iterating something this large to collect nicer messages is exactly
            // the resource exhaustion we are trying to avoid.
            return ['valid' => false, 'seat_count' => 0, 'errors' => $errors, 'warnings' => []];
        }

        $canvas = $geometry['canvas'] ?? null;
        if (! is_array($canvas) || ! isset($canvas['width'], $canvas['height'])) {
            $errors[] = $this->issue('canvas_missing', 'Geometry must declare a canvas with width and height.', 'canvas');
            $canvas = ['width' => 0, 'height' => 0];
        } else {
            foreach (['width', 'height'] as $dimension) {
                $value = (float) $canvas[$dimension];
                if ($value < 100 || $value > $this->limits['canvas_max']) {
                    $errors[] = $this->issue('canvas_out_of_range', sprintf(
                        'Canvas %s must be between 100 and %d.', $dimension, $this->limits['canvas_max']
                    ), "canvas.{$dimension}");
                }
            }
        }

        $sections = $geometry['sections'] ?? [];
        if (! is_array($sections) || $sections === []) {
            $errors[] = $this->issue('no_sections', 'A seat map needs at least one section.', 'sections');
            $sections = [];
        }

        if (count($sections) > $this->limits['max_sections_per_map']) {
            $errors[] = $this->issue('too_many_sections', sprintf(
                '%d sections exceeds the limit of %d.', count($sections), $this->limits['max_sections_per_map']
            ), 'sections');
        }

        foreach (['shapes' => 'max_shapes', 'texts' => 'max_texts'] as $collection => $limitKey) {
            $items = $geometry[$collection] ?? [];
            if (is_array($items) && count($items) > $this->limits[$limitKey]) {
                $errors[] = $this->issue('too_many_'.$collection, sprintf(
                    '%d %s exceeds the limit of %d.', count($items), $collection, $this->limits[$limitKey]
                ), $collection);
            }
        }

        $seatCount = 0;
        $sectionKeys = [];
        $positions = [];

        foreach ($sections as $sIndex => $section) {
            $path = "sections[{$sIndex}]";

            $sectionKey = $section['key'] ?? null;
            if (! is_string($sectionKey) || $sectionKey === '') {
                $errors[] = $this->issue('section_key_missing', 'Every section needs a stable key.', $path);
                continue;
            }

            if (isset($sectionKeys[$sectionKey])) {
                $errors[] = $this->issue('duplicate_section_key', "Section key '{$sectionKey}' is used more than once.", $path);
            }
            $sectionKeys[$sectionKey] = true;

            if (! is_string($section['name'] ?? null) || $section['name'] === '') {
                $errors[] = $this->issue('section_name_missing', "Section '{$sectionKey}' needs a name.", $path);
            }

            $rows = $section['rows'] ?? [];
            if (! is_array($rows)) {
                $errors[] = $this->issue('rows_invalid', "Section '{$sectionKey}' has an invalid rows list.", $path);
                continue;
            }

            if (count($rows) > $this->limits['max_rows_per_section']) {
                $errors[] = $this->issue('too_many_rows', sprintf(
                    "Section '%s' has %d rows; the limit is %d.",
                    $sectionKey, count($rows), $this->limits['max_rows_per_section']
                ), $path);
            }

            $rowKeys = [];

            foreach ($rows as $rIndex => $row) {
                $rowPath = "{$path}.rows[{$rIndex}]";

                $rowKey = $row['key'] ?? null;
                if (! is_string($rowKey) || $rowKey === '') {
                    $errors[] = $this->issue('row_key_missing', 'Every row needs a stable key.', $rowPath);
                    continue;
                }

                if (isset($rowKeys[$rowKey])) {
                    $errors[] = $this->issue('duplicate_row_key', "Row key '{$rowKey}' is repeated in section '{$sectionKey}'.", $rowPath);
                }
                $rowKeys[$rowKey] = true;

                $seats = $row['seats'] ?? [];
                if (! is_array($seats)) {
                    $errors[] = $this->issue('seats_invalid', "Row '{$rowKey}' has an invalid seats list.", $rowPath);
                    continue;
                }

                $seatKeys = [];
                $labels = [];

                foreach ($seats as $seatIndex => $seat) {
                    $seatPath = "{$rowPath}.seats[{$seatIndex}]";
                    $seatCount++;

                    $seatKey = $seat['key'] ?? null;
                    if (! is_string($seatKey) || $seatKey === '') {
                        $errors[] = $this->issue('seat_key_missing', 'Every seat needs a stable key.', $seatPath);
                        continue;
                    }

                    if (isset($seatKeys[$seatKey])) {
                        $errors[] = $this->issue('duplicate_seat_key', "Seat key '{$seatKey}' is repeated in row '{$rowKey}'.", $seatPath);
                    }
                    $seatKeys[$seatKey] = true;

                    $label = $seat['label'] ?? null;
                    if (! is_string($label) || $label === '') {
                        $errors[] = $this->issue('seat_label_missing', "Seat '{$seatKey}' needs a label.", $seatPath);
                    } elseif (isset($labels[$label])) {
                        $warnings[] = $this->issue('duplicate_seat_label', sprintf(
                            "Row '%s' has two seats labelled '%s'; buyers will not be able to tell them apart.",
                            $rowKey, $label
                        ), $seatPath);
                    } else {
                        $labels[$label] = true;
                    }

                    if (! isset($seat['x'], $seat['y']) || ! is_numeric($seat['x']) || ! is_numeric($seat['y'])) {
                        $errors[] = $this->issue('seat_position_missing', "Seat '{$seatKey}' needs numeric x and y.", $seatPath);
                        continue;
                    }

                    $x = (float) $seat['x'];
                    $y = (float) $seat['y'];

                    if ($x < 0 || $y < 0 || $x > (float) $canvas['width'] || $y > (float) $canvas['height']) {
                        $errors[] = $this->issue('seat_off_canvas', sprintf(
                            "Seat '%s' at (%.1f, %.1f) lies outside the canvas.", $seatKey, $x, $y
                        ), $seatPath);
                    }

                    $positions[] = [$x, $y, "{$sectionKey}/{$rowKey}/{$seatKey}"];
                }
            }
        }

        if ($seatCount === 0 && $errors === []) {
            $errors[] = $this->issue('no_seats', 'A seat map needs at least one seat before it can be published.', 'sections');
        }

        if ($seatCount > $this->limits['max_seats_per_map']) {
            $errors[] = $this->issue('too_many_seats', sprintf(
                '%d seats exceeds the limit of %d.', $seatCount, $this->limits['max_seats_per_map']
            ), 'sections');
        }

        foreach ($this->findOverlaps($positions) as $overlap) {
            $warnings[] = $this->issue('seats_overlap', sprintf(
                "Seats '%s' and '%s' are almost on top of each other.", $overlap[0], $overlap[1]
            ), 'sections');
        }

        return [
            'valid' => $errors === [],
            'seat_count' => $seatCount,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Grid-bucketed neighbour search — comparing every seat with every other seat would be
     * quadratic and unusable at 50k seats.
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $positions
     * @return list<array{0: string, 1: string}>
     */
    private function findOverlaps(array $positions): array
    {
        $buckets = [];
        $overlaps = [];
        $cell = self::OVERLAP_DISTANCE;

        foreach ($positions as [$x, $y, $id]) {
            $bx = (int) floor($x / $cell);
            $by = (int) floor($y / $cell);

            for ($dx = -1; $dx <= 1; $dx++) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    foreach ($buckets[($bx + $dx).':'.($by + $dy)] ?? [] as [$ox, $oy, $oid]) {
                        if (hypot($x - $ox, $y - $oy) < self::OVERLAP_DISTANCE) {
                            $overlaps[] = [$oid, $id];

                            // One example is enough to prompt a look; a thousand is noise.
                            if (count($overlaps) >= 25) {
                                return $overlaps;
                            }
                        }
                    }
                }
            }

            $buckets[$bx.':'.$by][] = [$x, $y, $id];
        }

        return $overlaps;
    }

    private function issue(string $code, string $message, string $path): array
    {
        return ['code' => $code, 'message' => $message, 'path' => $path];
    }
}
