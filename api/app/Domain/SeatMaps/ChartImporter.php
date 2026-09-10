<?php

namespace App\Domain\SeatMaps;

/**
 * Turns a seating plan exported from somewhere else into one of our charts.
 *
 * The reason this is not a two-hour scripting job is a difference in how the two models think. Most
 * export formats — seats.io's among them — write **one coordinate pair per seat**: a plan is 3,700
 * independent dots that happen to line up. Our chart stores a row as an anchor, a rotation, a curve
 * and a seat pitch, and works the seats out from those (see {@see RowGeometry}), because that is
 * what makes "this row now has 17 seats" a thing an organiser can type rather than a thing they
 * have to redraw.
 *
 * So importing is not translation, it is **fitting**: for every row of loose dots, recover the line
 * they were sitting on. That is done here rather than in the browser because it has to be exact and
 * checkable — the importer re-runs our own geometry over what it produced and reports the largest
 * distance any seat moved, so nobody has to take "it looks about right" on trust.
 *
 * What is deliberately *not* imported: which seats were sold, and which the house was holding back.
 * Those belong to a performance, not to a plan of the room — the same room is sold many times, and
 * a map that remembered last night's sales would be wrong for tonight.
 */
class ChartImporter
{
    /** How far a seat may sit off the straight line through a row's ends before it is called a curve. */
    private const STRAIGHT_TOLERANCE = 1.0;

    /** Blank space left round the outside of the imported plan, in chart units. */
    private const MARGIN = 80.0;

    /** How far outside its seats a section's outline is drawn. */
    private const SECTION_PADDING = 26.0;

    /**
     * @param  array  $export  A decoded foreign export.
     * @return array{chart: array, report: array}
     */
    public function import(array $export, ?string $name = null): array
    {
        $seats = $this->list($export, ['seats', 'places', 'objects']);

        if ([] === $seats) {
            throw new \InvalidArgumentException('This file has no seats in it.');
        }

        $shapes = $this->list($export, ['shapes']);
        $texts = $this->list($export, ['texts', 'labels']);
        $lines = $this->list($export, ['lines']);

        // Everything is shifted so the top-left of the drawing is at the margin. Exports routinely
        // carry negative coordinates — this one puts the stage surround at y = -210 — and a canvas
        // starts at zero, so without this the stage would simply be off the map.
        $bounds = $this->bounds($seats, $shapes, $texts, $lines);
        $offsetX = self::MARGIN - $bounds['minX'];
        $offsetY = self::MARGIN - $bounds['minY'];

        $categories = $this->categories($seats, $bounds);
        $grouped = [];

        foreach ($seats as $seat) {
            $section = $this->text($seat, ['sectionName', 'section', 'sectionLabel'], '');
            $row = $this->text($seat, ['rowName', 'row', 'rowLabel', 'rowId'], '');
            $grouped[$section][$row][] = $seat;
        }

        $objects = [];
        $report = [
            'seats' => count($seats), 'rows' => 0, 'sections' => 0,
            'categories' => count($categories), 'shapes' => 0, 'texts' => count($texts),
            'gaps_filled' => 0, 'curved_rows' => 0, 'max_placement_error' => 0.0,
            'ignored' => [],
        ];

        foreach ($grouped as $sectionName => $rows) {
            $built = [];
            $points = [];

            foreach ($rows as $rowName => $rowSeats) {
                $row = $this->fitRow((string) $rowName, $rowSeats, $offsetX, $offsetY, $categories);

                $report['rows']++;
                $report['gaps_filled'] += $row['gaps'];
                $report['curved_rows'] += 0.0 === (float) $row['object']['curve'] ? 0 : 1;
                $report['max_placement_error'] = max($report['max_placement_error'], $row['error']);

                $built[] = $row['object'];

                foreach ($rowSeats as $seat) {
                    $points[] = [$this->number($seat, ['x']) + $offsetX, $this->number($seat, ['y']) + $offsetY];
                }
            }

            if ('' === (string) $sectionName) {
                // A plan with no section names is one big room; its rows sit straight on the floor.
                $objects = array_merge($objects, $built);

                continue;
            }

            $report['sections']++;
            $objects[] = [
                'type' => 'section',
                'key' => $this->key('section-'.$sectionName),
                'layer' => 'interactive',
                'label' => (string) $sectionName,
                'labeling' => [
                    'label' => (string) $sectionName, 'displayedLabel' => null,
                    'visible' => true, 'fontSize' => 26, 'locked' => false,
                ],
                'polygon' => $this->outline($points),
                'categoryKey' => null,
                'color' => null,
                'entrance' => null,
                'objects' => $built,
            ];
        }

        foreach ($shapes as $shape) {
            $objects[] = $this->shape($shape, $offsetX, $offsetY);
            $report['shapes']++;
        }

        foreach ($lines as $line) {
            $objects[] = $this->line($line, $offsetX, $offsetY);
            $report['shapes']++;
        }

        foreach ($texts as $text) {
            $objects[] = $this->label($text, $offsetX, $offsetY);
        }

        foreach (['status', 'price'] as $field) {
            foreach ($seats as $seat) {
                if (array_key_exists($field, $seat)) {
                    // Said out loud rather than dropped in silence: an organiser who exported a
                    // half-sold plan needs to know the sales did not come with it.
                    $report['ignored'][] = $field;
                    break;
                }
            }
        }

        $canvas = [
            'width' => round($bounds['maxX'] - $bounds['minX'] + self::MARGIN * 2, 2),
            'height' => round($bounds['maxY'] - $bounds['minY'] + self::MARGIN * 2, 2),
            'background' => null,
        ];

        $report['canvas'] = $canvas;
        $report['offset'] = ['x' => round($offsetX, 2), 'y' => round($offsetY, 2)];
        $report['max_placement_error'] = round($report['max_placement_error'], 3);

        $chart = [
            'version' => 2,
            'name' => $name ?: $this->text($export, ['venue', 'name'], 'Imported plan'),
            'focalPoint' => $this->focalPoint($shapes, $seats, $offsetX, $offsetY),
            'categories' => array_values($categories),
            'floors' => [[
                'key' => '1',
                'name' => $this->text($export, ['floorName'], 'Level 1'),
                'canvas' => $canvas,
                'objects' => $objects,
            ]],
        ];

        return ['chart' => $chart, 'report' => $report];
    }

    /**
     * Recover the line a row of loose coordinates was sitting on.
     *
     * The two seats furthest apart give the row's direction; everything else is measured against
     * that chord. Seats are then dropped into evenly spaced slots along it, which is what turns
     * "these 23 dots are 45.5 apart, except for a 91 unit hole two thirds along" into a row of 25
     * with two empty places where the aisle is — the hole being an aisle, not a rounding error, is
     * the whole reason the spacing has to stay uniform.
     *
     * @return array{object: array, gaps: int, error: float}
     */
    private function fitRow(string $name, array $seats, float $offsetX, float $offsetY, array $categories): array
    {
        $points = array_map(fn ($seat) => [
            $this->number($seat, ['x']) + $offsetX,
            $this->number($seat, ['y']) + $offsetY,
            $seat,
        ], $seats);

        [$first, $last] = $this->extremes($points);
        $dx = $last[0] - $first[0];
        $dy = $last[1] - $first[1];
        $chord = hypot($dx, $dy);

        if ($chord < 0.001) {
            // A single seat, or several stacked on one spot: there is no direction to recover.
            $dx = 1.0;
            $dy = 0.0;
            $chord = 0.0;
        }

        $ux = $chord > 0 ? $dx / $chord : 1.0;
        $uy = $chord > 0 ? $dy / $chord : 0.0;
        $rotation = rad2deg(atan2($uy, $ux));

        // Along the row, and across it. The across component is what tells a curve from a wobble.
        $along = [];
        $across = [];

        foreach ($points as $index => $point) {
            $along[$index] = ($point[0] - $first[0]) * $ux + ($point[1] - $first[1]) * $uy;
            $across[$index] = -($point[0] - $first[0]) * $uy + ($point[1] - $first[1]) * $ux;
        }

        asort($along);
        $order = array_keys($along);

        $pitch = $this->pitch(array_values($along));
        $slots = [];

        foreach ($order as $index) {
            $slot = $pitch > 0 ? (int) round($along[$index] / $pitch) : count($slots);

            while (isset($slots[$slot])) {
                // Two seats claiming one slot means the source spacing was not uniform after all.
                // Better a row one seat longer than a seat quietly overwritten.
                $slot++;
            }

            $slots[$slot] = $points[$index][2];
        }

        $count = $slots === [] ? 0 : max(array_keys($slots)) + 1;
        $gaps = $count - count($slots);

        // The estimate above was only ever there to work out how many places the row has. Now that
        // the count is known the pitch follows from it exactly, because our rows are built by
        // dividing the chord — and on a curved row the two differ: seats sit evenly along the arc,
        // so their shadows on the chord bunch towards the ends and would drag the estimate short.
        if ($count > 1 && $chord > 0) {
            $pitch = $chord / ($count - 1);
        }

        $curve = 0.0;
        $sagitta = $this->sagitta($across, $along, $chord);

        if (abs($sagitta) > self::STRAIGHT_TOLERANCE && $chord > 0) {
            $curve = round(100 * $sagitta / $chord, 4);
        }

        // Our rows are centred on their anchor, and the anchor must be the centre of the *slots*,
        // not of the seats that are present — a row missing its last two seats to an aisle would
        // otherwise slide half a seat sideways.
        $span = $pitch * max(0, $count - 1);
        $centre = $chord > 0 ? ($span / 2) : 0.0;

        $row = [
            'type' => 'row',
            'key' => $this->key('row-'.$name),
            'layer' => 'interactive',
            'x' => round($first[0] + $ux * $centre, 2),
            'y' => round($first[1] + $uy * $centre, 2),
            'rotation' => round($rotation, 4),
            'curve' => $curve,
            'seatSpacing' => round(max(0.0, $pitch - RowGeometry::SEAT_SIZE), 3),
            'categoryKey' => null,
            'entrance' => null,
            'labeling' => [
                'enabled' => true, 'label' => $name, 'displayedLabel' => null,
                'position' => 'both', 'displayedType' => 'Row', 'locked' => false,
            ],
            'seatLabeling' => ['scheme' => 'numeric', 'displayedType' => 'Seat', 'locked' => false],
            'seats' => [],
        ];

        for ($slot = 0; $slot < $count; $slot++) {
            if (! isset($slots[$slot])) {
                $row['seats'][] = [
                    'type' => 'empty',
                    'key' => $row['key'].'-gap-'.$slot,
                    'label' => '',
                    'categoryKey' => null,
                    'accessible' => false,
                    'entrance' => null,
                ];

                continue;
            }

            $seat = $slots[$slot];
            $label = $this->text($seat, ['label', 'seatLabel', 'number', 'name'], (string) ($slot + 1));

            $row['seats'][] = [
                'type' => 'seat',
                'key' => $row['key'].'-'.$this->slug($label).'-'.$slot,
                'label' => $label,
                'categoryKey' => $categories[$this->text($seat, ['color', 'category'], '')]['key'] ?? null,
                'accessible' => (bool) ($seat['accessible'] ?? false),
                'entrance' => null,
            ];
        }

        $this->hoistCategory($row);

        return ['object' => $row, 'gaps' => $gaps, 'error' => $this->error($row, $slots, $offsetX, $offsetY)];
    }

    /**
     * Move the category most of a row's seats share up onto the row itself.
     *
     * A colour per seat is how the export says it, but it is not how a chart is read or edited: an
     * organiser repricing the front stalls changes one row, not forty chairs. So the row carries
     * what its seats agree on and only the odd seat out keeps an override — which is also what
     * stops the designer telling them, forty times over, that a row has no price.
     */
    private function hoistCategory(array &$row): void
    {
        $counts = [];

        foreach ($row['seats'] as $seat) {
            if ('empty' !== $seat['type'] && $seat['categoryKey']) {
                $counts[$seat['categoryKey']] = ($counts[$seat['categoryKey']] ?? 0) + 1;
            }
        }

        if ([] === $counts) {
            return;
        }

        arsort($counts);
        $common = array_key_first($counts);
        $row['categoryKey'] = $common;

        foreach ($row['seats'] as $index => $seat) {
            if ($seat['categoryKey'] === $common) {
                $row['seats'][$index]['categoryKey'] = null;
            }
        }
    }

    /** How far the worst seat ended up from where the export had it. */
    private function error(array $row, array $slots, float $offsetX, float $offsetY): float
    {
        $worst = 0.0;

        foreach (RowGeometry::forRow($row) as $slot => $point) {
            if (! isset($slots[$slot])) {
                continue;
            }

            $worst = max($worst, hypot(
                $point['x'] - ($this->number($slots[$slot], ['x']) + $offsetX),
                $point['y'] - ($this->number($slots[$slot], ['y']) + $offsetY),
            ));
        }

        return $worst;
    }

    /** The two points furthest apart, which is the row's chord however the seats are ordered. */
    private function extremes(array $points): array
    {
        if (count($points) < 2) {
            return [$points[0], $points[0]];
        }

        $best = -1.0;
        $pair = [$points[0], $points[1]];

        foreach ($points as $i => $a) {
            foreach ($points as $j => $b) {
                if ($j <= $i) {
                    continue;
                }

                $distance = hypot($b[0] - $a[0], $b[1] - $a[1]);

                if ($distance > $best) {
                    $best = $distance;
                    $pair = [$a, $b];
                }
            }
        }

        return $pair;
    }

    /**
     * The seat pitch: the smallest gap between neighbours, taken as a median so one aisle does not
     * decide it.
     */
    private function pitch(array $along): float
    {
        sort($along);
        $gaps = [];

        for ($i = 1; $i < count($along); $i++) {
            $gap = $along[$i] - $along[$i - 1];

            if ($gap > 0.001) {
                $gaps[] = $gap;
            }
        }

        if ([] === $gaps) {
            return RowGeometry::SEAT_SIZE + 8;
        }

        sort($gaps);

        // The smallest quartile: in a row with aisles most gaps are still one seat wide, and taking
        // the median of *all* gaps would be pulled wide by a row that is mostly aisle.
        $take = max(1, (int) floor(count($gaps) / 4));

        return array_sum(array_slice($gaps, 0, $take)) / $take;
    }

    /** The bulge of the row away from its chord, at the middle. */
    private function sagitta(array $across, array $along, float $chord): float
    {
        if ($chord <= 0 || [] === $across) {
            return 0.0;
        }

        $middle = $chord / 2;
        $best = null;
        $closest = INF;

        foreach ($along as $index => $distance) {
            if (abs($distance - $middle) < $closest) {
                $closest = abs($distance - $middle);
                $best = $across[$index];
            }
        }

        return (float) $best;
    }

    /**
     * One price category per colour in the export.
     *
     * Exports carry the colour a seat was drawn in but not what the venue calls that price, so the
     * names are ours: lettered outward from the stage, which is the order a price list is written
     * in. An organiser renames them in the designer; what matters is that seats which shared a
     * colour still share a category afterwards.
     */
    private function categories(array $seats, array $bounds): array
    {
        $groups = [];

        foreach ($seats as $seat) {
            $colour = $this->text($seat, ['color', 'category'], '');
            $groups[$colour][] = hypot(
                $this->number($seat, ['x']) - ($bounds['minX'] + $bounds['maxX']) / 2,
                $this->number($seat, ['y']) - $bounds['minY'],
            );
        }

        $distance = array_map(fn ($values) => array_sum($values) / count($values), $groups);
        asort($distance);

        $categories = [];
        $index = 0;

        foreach (array_keys($distance) as $colour) {
            $letter = chr(65 + ($index % 26)).($index >= 26 ? (string) intdiv($index, 26) : '');

            $categories[$colour] = [
                'key' => 'zone-'.strtolower($letter),
                'label' => 'Zone '.$letter,
                'color' => str_starts_with($colour, '#') ? $colour : '#2d6cdf',
                'accessible' => false,
            ];

            $index++;
        }

        return $categories;
    }

    /** A convex outline round a section's seats, so the block is clickable before it is zoomed into. */
    private function outline(array $points): array
    {
        if (count($points) < 3) {
            $x = $points[0][0] ?? 0;
            $y = $points[0][1] ?? 0;

            return [[$x - 40, $y - 40], [$x + 40, $y - 40], [$x + 40, $y + 40], [$x - 40, $y + 40]];
        }

        sort($points);
        $hull = [];

        foreach ([array_reverse($points), $points] as $pass) {
            $size = count($hull);

            foreach ($pass as $point) {
                while (count($hull) >= $size + 2 && $this->clockwise($hull[count($hull) - 2], $hull[count($hull) - 1], $point) <= 0) {
                    array_pop($hull);
                }

                $hull[] = $point;
            }

            array_pop($hull);
        }

        $centre = [
            array_sum(array_column($hull, 0)) / count($hull),
            array_sum(array_column($hull, 1)) / count($hull),
        ];

        // Pushed outward from the middle so the outline clears the chairs rather than cutting them.
        return array_map(function ($point) use ($centre) {
            $length = max(0.001, hypot($point[0] - $centre[0], $point[1] - $centre[1]));

            return [
                round($point[0] + ($point[0] - $centre[0]) / $length * self::SECTION_PADDING, 2),
                round($point[1] + ($point[1] - $centre[1]) / $length * self::SECTION_PADDING, 2),
            ];
        }, $hull);
    }

    private function clockwise(array $o, array $a, array $b): float
    {
        return ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    }

    private function shape(array $shape, float $offsetX, float $offsetY): array
    {
        $label = $this->text($shape, ['text', 'label'], '');
        $kind = in_array($this->text($shape, ['type', 'kind'], 'rect'), ['circle', 'ellipse'], true) ? 'ellipse' : 'rect';

        return [
            'type' => 'shape',
            'key' => $this->key('shape-'.($shape['id'] ?? $kind)),
            'layer' => 'background',
            'kind' => $kind,
            'x' => round($this->number($shape, ['x']) + $offsetX, 2),
            'y' => round($this->number($shape, ['y']) + $offsetY, 2),
            'width' => round($this->number($shape, ['width']), 2),
            'height' => round($this->number($shape, ['height']), 2),
            'rotation' => round($this->number($shape, ['rotation']), 2),
            'cornerRadius' => 4,
            'points' => null,
            'fill' => $this->text($shape, ['backgroundColor', 'color', 'fill'], '') ?: null,
            'label' => '' === $label ? null : $label,
        ];
    }

    private function line(array $line, float $offsetX, float $offsetY): array
    {
        $points = [
            [round($this->number($line, ['x1']) + $offsetX, 2), round($this->number($line, ['y1']) + $offsetY, 2)],
            [round($this->number($line, ['x2']) + $offsetX, 2), round($this->number($line, ['y2']) + $offsetY, 2)],
        ];

        return [
            'type' => 'shape',
            'key' => $this->key('line-'.($line['id'] ?? count($points))),
            'layer' => 'background',
            'kind' => 'line',
            'x' => $points[0][0],
            'y' => $points[0][1],
            'width' => 0,
            'height' => 0,
            'rotation' => 0,
            'cornerRadius' => 0,
            'points' => $points,
            'strokeWidth' => max(1, (int) ($line['thickness'] ?? 3)),
            'fill' => $this->text($line, ['color', 'fill'], '') ?: null,
            'label' => null,
        ];
    }

    private function label(array $text, float $offsetX, float $offsetY): array
    {
        $body = $this->text($text, ['text', 'label'], '');

        return [
            'type' => 'text',
            'key' => $this->key('text-'.($text['id'] ?? $body)),
            'layer' => 'foreground',
            'text' => $body,
            'x' => round($this->number($text, ['x']) + $offsetX, 2),
            'y' => round($this->number($text, ['y']) + $offsetY, 2),
            'fontSize' => (float) ($text['fontSize'] ?? 18),
            'color' => $this->text($text, ['color', 'fontColor'], '') ?: null,
            'rotation' => round($this->number($text, ['rotation']), 2),
        ];
    }

    /**
     * What the room faces.
     *
     * The largest shape carrying a label is the stage in every export seen so far — it is drawn big
     * and it has the word on it. Failing that, the middle of the row nearest the top edge, which is
     * where a plan puts the front.
     */
    private function focalPoint(array $shapes, array $seats, float $offsetX, float $offsetY): array
    {
        $best = null;
        $area = 0.0;

        foreach ($shapes as $shape) {
            $size = $this->number($shape, ['width']) * $this->number($shape, ['height']);

            if ($size > $area && '' !== $this->text($shape, ['text', 'label'], '')) {
                $area = $size;
                $best = $shape;
            }
        }

        if ($best) {
            return [
                'x' => round($this->number($best, ['x']) + $this->number($best, ['width']) / 2 + $offsetX, 2),
                'y' => round($this->number($best, ['y']) + $this->number($best, ['height']) / 2 + $offsetY, 2),
            ];
        }

        $xs = array_map(fn ($seat) => $this->number($seat, ['x']), $seats);
        $ys = array_map(fn ($seat) => $this->number($seat, ['y']), $seats);

        return [
            'x' => round((min($xs) + max($xs)) / 2 + $offsetX, 2),
            'y' => round(min($ys) + $offsetY, 2),
        ];
    }

    /** @return array{minX: float, minY: float, maxX: float, maxY: float} */
    private function bounds(array $seats, array $shapes, array $texts, array $lines): array
    {
        $xs = [];
        $ys = [];

        foreach ($seats as $seat) {
            $xs[] = $this->number($seat, ['x']);
            $ys[] = $this->number($seat, ['y']);
        }

        foreach ($texts as $text) {
            $xs[] = $this->number($text, ['x']);
            $ys[] = $this->number($text, ['y']);
        }

        foreach ($shapes as $shape) {
            $xs[] = $this->number($shape, ['x']);
            $ys[] = $this->number($shape, ['y']);
            $xs[] = $this->number($shape, ['x']) + $this->number($shape, ['width']);
            $ys[] = $this->number($shape, ['y']) + $this->number($shape, ['height']);
        }

        foreach ($lines as $line) {
            $xs[] = $this->number($line, ['x1']);
            $xs[] = $this->number($line, ['x2']);
            $ys[] = $this->number($line, ['y1']);
            $ys[] = $this->number($line, ['y2']);
        }

        return ['minX' => min($xs), 'minY' => min($ys), 'maxX' => max($xs), 'maxY' => max($ys)];
    }

    /** @param list<string> $keys */
    private function list(array $source, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_array($source[$key])) {
                return array_values(array_filter($source[$key], 'is_array'));
            }
        }

        return [];
    }

    /** @param list<string> $keys */
    private function text(array $source, array $keys, string $fallback): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && (is_string($source[$key]) || is_numeric($source[$key]))) {
                return (string) $source[$key];
            }
        }

        return $fallback;
    }

    /** @param list<string> $keys */
    private function number(array $source, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return (float) $source[$key];
            }
        }

        return 0.0;
    }

    private array $taken = [];

    private function key(string $base): string
    {
        $key = $this->slug($base);
        $candidate = $key;
        $suffix = 2;

        while (isset($this->taken[$candidate])) {
            $candidate = $key.'-'.$suffix++;
        }

        $this->taken[$candidate] = true;

        return $candidate;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '');

        return trim($slug, '-') ?: 'x';
    }
}
