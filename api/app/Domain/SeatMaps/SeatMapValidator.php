<?php

namespace App\Domain\SeatMaps;

/**
 * Validates a chart before it is saved or published.
 *
 * Deliberately mirrors `Chart.validate` in the designer, and is the authority: the browser's copy
 * exists so the organiser sees a problem while looking at the thing that caused it, but a chart is
 * only allowed to go on sale if this agrees.
 *
 * Two severities, and the distinction is load-bearing. An **error** blocks publishing because the
 * chart could not be sold correctly — a duplicate label means two tickets read the same, an
 * unlabelled object cannot be printed, a seat off the canvas can never be clicked. A **warning** is
 * something an organiser may well have meant: overlapping chairs on a bench, or one price shared
 * between seats and standing. Warnings are surfaced and never block.
 */
class SeatMapValidator
{
    private const OVERLAP_DISTANCE = 8.0;

    /** Object types that carry bookable places. */
    private const BOOKABLE = ['row', 'section', 'area', 'table', 'booth'];

    public function __construct(private readonly array $limits) {}

    public static function make(): self
    {
        return new self(config('seatmap.limits'));
    }

    /**
     * @return array{valid: bool, seat_count: int, places: int, checks: list<array>, errors: list<array>, warnings: list<array>}
     */
    public function validate(array $chart): array
    {
        $encoded = json_encode($chart);

        if ($encoded !== false && strlen($encoded) > $this->limits['max_geometry_bytes']) {
            // Bail out before walking it: iterating something this large to produce nicer messages
            // is exactly the resource exhaustion the limit exists to prevent (threat T11).
            return $this->report(false, 0, 0, [], [$this->issue('geometry_too_large', sprintf(
                'The chart is %d bytes; the limit is %d.', strlen($encoded), $this->limits['max_geometry_bytes']
            ))], []);
        }

        $floors = $chart['floors'] ?? [];

        if (! is_array($floors) || $floors === []) {
            return $this->report(false, 0, 0, [], [$this->issue('no_floors', 'A chart needs at least one floor.')], []);
        }

        $errors = [];
        $warnings = [];
        $seatCount = 0;
        $places = 0;
        $shapes = 0;
        $texts = 0;
        $duplicates = [];
        $unlabeled = 0;
        $uncategorized = 0;
        $typesPerCategory = [];
        $positions = [];
        $seenLabels = [];
        $objectCount = 0;

        foreach ($floors as $floor) {
            $canvas = $floor['canvas'] ?? ['width' => 0, 'height' => 0];

            foreach (['width', 'height'] as $dimension) {
                $value = (float) ($canvas[$dimension] ?? 0);

                if ($value < 100 || $value > $this->limits['canvas_max']) {
                    $errors[] = $this->issue('canvas_out_of_range', sprintf(
                        'Canvas %s must be between 100 and %d.', $dimension, $this->limits['canvas_max']
                    ));
                }
            }

            $this->walk($floor['objects'] ?? [], null, function (array $object, ?array $container) use (
                &$errors, &$warnings, &$seatCount, &$places, &$duplicates, &$unlabeled,
                &$uncategorized, &$typesPerCategory, &$positions, &$seenLabels, &$objectCount,
                &$shapes, &$texts, $canvas
            ) {
                $objectCount++;

                // Scenery. It carries nothing bookable, but it is not free: a plan traced from a
                // floor drawing arrives with a thousand wall segments, and the ceilings on those
                // are the difference between a slow map and a browser that gives up (threat T11).
                if (in_array($object['type'] ?? '', ['shape', 'image', 'icon'], true)) {
                    $shapes++;
                }

                if (($object['type'] ?? '') === 'text') {
                    $texts++;
                }

                if (! in_array($object['type'] ?? '', self::BOOKABLE, true)) {
                    return;
                }

                $label = $this->labelOf($object);

                if ($label === null || $label === '') {
                    $unlabeled++;
                    $errors[] = $this->issue('object_not_labeled', 'An object has no label.', $object['key'] ?? null);
                } else {
                    // Scoped the way a ticket reads: "Stalls A" and "Circle A" are unambiguous, and
                    // every venue has both.
                    $scope = ($container['key'] ?? 'floor').'|'.$object['type'].'|'.$label;

                    if (isset($seenLabels[$scope])) {
                        $duplicates[$label] = true;
                        $errors[] = $this->issue('duplicate_label', "Two objects are both labelled {$label}.");
                    }

                    $seenLabels[$scope] = true;
                }

                $categoryKey = $object['categoryKey'] ?? ($container['categoryKey'] ?? null);

                if (! $categoryKey && ($object['type'] ?? '') !== 'section') {
                    $uncategorized++;
                    $warnings[] = $this->issue(
                        'object_not_categorized',
                        'An object has no category, so it cannot be priced.',
                        $object['key'] ?? null,
                    );
                }

                if ($categoryKey) {
                    $typesPerCategory[$categoryKey][$object['type']] = true;
                }

                switch ($object['type']) {
                    case 'row':
                        $seats = $object['seats'] ?? [];
                        $bookable = array_filter($seats, fn ($seat) => ($seat['type'] ?? 'seat') !== 'empty');
                        $seatCount += count($bookable);
                        $places += count($bookable);

                        $seatLabels = [];

                        foreach ($seats as $seat) {
                            if (isset($seatLabels[$seat['label'] ?? ''])) {
                                $errors[] = $this->issue('duplicate_label', sprintf(
                                    'Row %s has two seats labelled %s.', $label, $seat['label'] ?? ''
                                ), $object['key'] ?? null);
                            }

                            $seatLabels[$seat['label'] ?? ''] = true;
                        }

                        foreach (RowGeometry::forRow($object) as $index => $point) {
                            if (
                                $point['x'] < 0 || $point['y'] < 0 ||
                                $point['x'] > (float) ($canvas['width'] ?? 0) ||
                                $point['y'] > (float) ($canvas['height'] ?? 0)
                            ) {
                                $errors[] = $this->issue('seat_off_canvas', sprintf(
                                    "Seat %s%s at (%.1f, %.1f) lies outside the canvas.",
                                    $label, $seats[$index]['label'] ?? '', $point['x'], $point['y']
                                ), $object['key'] ?? null);
                            }

                            $positions[] = [$point['x'], $point['y'], $label.($seats[$index]['label'] ?? '')];
                        }
                        break;

                    case 'table':
                        $seats = $object['seats'] ?? [];
                        $seatCount += count($seats);
                        // A table sold whole is one place however many chairs are drawn round it.
                        $places += ($object['bookAs'] ?? 'seat') === 'table' ? 1 : count($seats);
                        break;

                    case 'area':
                    case 'booth':
                        $capacity = (int) ($object['capacity']['places'] ?? 0);

                        if ($capacity < 1) {
                            $errors[] = $this->issue('capacity_missing', sprintf(
                                'Area "%s" has no capacity, so nothing can be sold in it.', $label
                            ), $object['key'] ?? null);
                        }

                        $places += $capacity;
                        break;
                }
            });
        }

        if ($shapes > $this->limits['max_shapes']) {
            $errors[] = $this->issue('too_many_shapes', sprintf(
                '%d shapes exceeds the limit of %d.', $shapes, $this->limits['max_shapes']
            ));
        }

        if ($texts > $this->limits['max_texts']) {
            $errors[] = $this->issue('too_many_texts', sprintf(
                '%d text labels exceeds the limit of %d.', $texts, $this->limits['max_texts']
            ));
        }

        if ($objectCount > $this->limits['max_sections_per_map'] * 200) {
            $errors[] = $this->issue('too_many_objects', 'This chart has more objects than a single map may hold.');
        }

        if ($seatCount > $this->limits['max_seats_per_map']) {
            $errors[] = $this->issue('too_many_seats', sprintf(
                '%d seats exceeds the limit of %d.', $seatCount, $this->limits['max_seats_per_map']
            ));
        }

        if ($places === 0) {
            $errors[] = $this->issue('no_places', 'A chart needs at least one bookable place before it can be published.');
        }

        $mixedCategories = array_keys(array_filter($typesPerCategory, fn ($types) => count($types) > 1));

        foreach ($mixedCategories as $key) {
            $warnings[] = $this->issue('category_spans_object_types', sprintf(
                'Category "%s" is used on more than one kind of object, which usually means seats and '.
                'standing places share a price by accident.', $key
            ));
        }

        foreach ($this->findOverlaps($positions) as [$a, $b]) {
            $warnings[] = $this->issue('seats_overlap', "Seats {$a} and {$b} are almost on top of each other.");
        }

        $checks = [
            ['code' => 'no_duplicate_objects', 'label' => 'No duplicate objects', 'ok' => $duplicates === []],
            ['code' => 'all_labeled', 'label' => 'All objects are labeled', 'ok' => $unlabeled === 0],
            ['code' => 'all_categorized', 'label' => 'All objects are categorized', 'ok' => $uncategorized === 0],
            ['code' => 'one_category_per_type', 'label' => 'One category per object type', 'ok' => $mixedCategories === []],
            ['code' => 'focal_point', 'label' => 'Focal point is set', 'ok' => ! empty($chart['focalPoint'])],
        ];

        return $this->report($errors === [], $seatCount, $places, $checks, $errors, $warnings);
    }

    /** Walk objects depth first, descending into sections. */
    private function walk(array $objects, ?array $container, callable $callback): void
    {
        foreach ($objects as $object) {
            if (! is_array($object) || ! isset($object['type'])) {
                continue;
            }

            $callback($object, $container);

            if ($object['type'] === 'section') {
                $this->walk($object['objects'] ?? [], $object, $callback);
            }
        }
    }

    private function labelOf(array $object): ?string
    {
        if ($object['type'] === 'row') {
            $labeling = $object['labeling'] ?? [];
            $displayed = $labeling['displayedLabel'] ?? null;

            return $displayed !== null && $displayed !== '' ? $displayed : ($labeling['label'] ?? null);
        }

        if (isset($object['labeling'])) {
            $displayed = $object['labeling']['displayedLabel'] ?? null;

            return $displayed !== null && $displayed !== '' ? $displayed : ($object['labeling']['label'] ?? null);
        }

        return $object['label'] ?? null;
    }

    /**
     * Grid-bucketed neighbour search — comparing every seat with every other would be quadratic and
     * unusable at 50,000 seats.
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

                            // One example prompts a look; a thousand is noise.
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

    private function issue(string $code, string $message, ?string $objectKey = null): array
    {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'object_key' => $objectKey,
        ], fn ($value) => $value !== null);
    }

    private function report(bool $valid, int $seatCount, int $places, array $checks, array $errors, array $warnings): array
    {
        return [
            'valid' => $valid,
            // Kept as `seat_count` because it is what the plan limit and the version row measure.
            'seat_count' => $seatCount,
            'places' => $places,
            'checks' => $checks,
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
        ];
    }
}
