<?php

namespace App\Domain\SeatMaps;

/**
 * Where the seats in a row or around a table actually sit.
 *
 * A deliberate mirror of `rowSeatPositions` and `tableSeatPositions` in
 * `public/editor/js/chart.js`. Duplicating maths across two languages is a real risk, so the two
 * are pinned to the same golden values by tests on both sides — if either drifts, the designer and
 * the published map would disagree about where a chair is.
 *
 * The reason positions are computed rather than stored is that a row is defined by an anchor, a
 * rotation, a curve and a seat spacing. Typing 17 into "Number of seats" has to rearrange the row,
 * which is impossible if each seat carries its own coordinates.
 */
class RowGeometry
{
    /** Seat diameter in chart units; spacing is the gap, so pitch = size + spacing. */
    public const SEAT_SIZE = 18.0;

    /**
     * @return list<array{x: float, y: float, rotation: float}>
     */
    public static function forRow(array $row): array
    {
        $count = count($row['seats'] ?? []);

        if ($count === 0) {
            return [];
        }

        $pitch = self::SEAT_SIZE + (float) ($row['seatSpacing'] ?? 0);
        $chord = ($count - 1) * $pitch;
        $theta = deg2rad((float) ($row['rotation'] ?? 0));
        $cos = cos($theta);
        $sin = sin($theta);
        $curve = (float) ($row['curve'] ?? 0);

        $local = [];

        if ($curve === 0.0 || $count === 1) {
            for ($i = 0; $i < $count; $i++) {
                $local[] = ['x' => $i * $pitch - $chord / 2, 'y' => 0.0, 'rotation' => 0.0];
            }
        } else {
            // A circular arc through both ends, with the sagitta given as a percentage of the
            // chord. Seats are spread evenly *along the arc*, which is what keeps neighbours the
            // same distance apart; equal angular steps would bunch them at the ends.
            $h = ($curve / 100) * $chord;
            $radius = abs($h) / 2 + ($chord * $chord) / (8 * abs($h));
            $sweep = 2 * asin(min(1.0, $chord / (2 * $radius)));
            $sign = $h < 0 ? -1 : 1;

            for ($i = 0; $i < $count; $i++) {
                $angle = -$sweep / 2 + ($sweep * $i) / ($count - 1);

                $local[] = [
                    'x' => $radius * sin($angle),
                    // Measured from the chord, so a curve of zero and a curve approaching zero agree.
                    'y' => $sign * ($radius * cos($angle) - $radius * cos($sweep / 2)),
                    // Seats on an arc face its centre.
                    'rotation' => -$sign * rad2deg($angle),
                ];
            }
        }

        return self::place($local, (float) $row['x'], (float) $row['y'], (float) ($row['rotation'] ?? 0), $cos, $sin);
    }

    /**
     * @return list<array{x: float, y: float, rotation: float}>
     */
    public static function forTable(array $table): array
    {
        $count = count($table['seats'] ?? []);

        if ($count === 0) {
            return [];
        }

        $theta = deg2rad((float) ($table['rotation'] ?? 0));
        $cos = cos($theta);
        $sin = sin($theta);
        $margin = self::SEAT_SIZE * 0.9;
        $width = (float) $table['width'];
        $height = (float) $table['height'];
        $local = [];

        if (($table['shape'] ?? 'round') === 'round') {
            $radius = max($width, $height) / 2 + $margin;

            for ($i = 0; $i < $count; $i++) {
                $angle = (2 * M_PI * $i) / $count - M_PI / 2;

                $local[] = [
                    'x' => $radius * cos($angle),
                    'y' => $radius * sin($angle),
                    'rotation' => rad2deg($angle) + 90,
                ];
            }
        } else {
            // Walk the perimeter at constant spacing so chairs do not bunch at the corners.
            $perimeter = 2 * ($width + $height);

            for ($i = 0; $i < $count; $i++) {
                $local[] = self::perimeterPoint(
                    ($perimeter * $i) / $count,
                    $width,
                    $height,
                    $width / 2 + $margin,
                    $height / 2 + $margin,
                );
            }
        }

        return self::place($local, (float) $table['x'], (float) $table['y'], (float) ($table['rotation'] ?? 0), $cos, $sin);
    }

    /** @return array{x: float, y: float, rotation: float} */
    private static function perimeterPoint(float $distance, float $width, float $height, float $w, float $h): array
    {
        if ($distance < $width) {
            return ['x' => -$width / 2 + $distance, 'y' => -$h, 'rotation' => 0.0];
        }

        $distance -= $width;

        if ($distance < $height) {
            return ['x' => $w, 'y' => -$height / 2 + $distance, 'rotation' => 90.0];
        }

        $distance -= $height;

        if ($distance < $width) {
            return ['x' => $width / 2 - $distance, 'y' => $h, 'rotation' => 180.0];
        }

        $distance -= $width;

        return ['x' => -$w, 'y' => $height / 2 - $distance, 'rotation' => 270.0];
    }

    /**
     * @param  list<array{x: float, y: float, rotation: float}>  $local
     * @return list<array{x: float, y: float, rotation: float}>
     */
    private static function place(array $local, float $x, float $y, float $rotation, float $cos, float $sin): array
    {
        return array_map(fn (array $point) => [
            'x' => round($x + $point['x'] * $cos - $point['y'] * $sin, 2),
            'y' => round($y + $point['x'] * $sin + $point['y'] * $cos, 2),
            'rotation' => round($rotation + $point['rotation'], 2),
        ], $local);
    }

    /** The axis-aligned box an area, booth or shape occupies. */
    public static function shapeBounds(array $shape): array
    {
        if (! empty($shape['points'])) {
            $xs = array_column($shape['points'], 0);
            $ys = array_column($shape['points'], 1);

            return [
                'x' => min($xs), 'y' => min($ys),
                'width' => max($xs) - min($xs), 'height' => max($ys) - min($ys),
            ];
        }

        return [
            'x' => (float) ($shape['x'] ?? 0),
            'y' => (float) ($shape['y'] ?? 0),
            'width' => (float) ($shape['width'] ?? 0),
            'height' => (float) ($shape['height'] ?? 0),
        ];
    }
}
