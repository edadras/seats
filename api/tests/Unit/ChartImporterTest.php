<?php

namespace Tests\Unit;

use App\Domain\SeatMaps\ChartImporter;
use App\Domain\SeatMaps\RowGeometry;
use App\Domain\SeatMaps\SeatMapValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading somebody else's plan.
 *
 * Every test here asks the same question in a different way: after fitting a row to a cloud of
 * loose coordinates, does our own geometry put the seats back where the export had them? A row that
 * imports "nearly" is worse than one that refuses, because nobody notices until a buyer is told
 * they are sitting in the aisle.
 */
class ChartImporterTest extends TestCase
{
    private function importer(): ChartImporter
    {
        return new ChartImporter;
    }

    private function validator(): SeatMapValidator
    {
        return new SeatMapValidator([
            'max_seats_per_map' => 50000,
            'max_sections_per_map' => 500,
            'max_rows_per_section' => 500,
            'max_geometry_bytes' => 8 * 1024 * 1024,
            'max_shapes' => 2000,
            'max_texts' => 2000,
            'canvas_max' => 20000,
        ]);
    }

    /**
     * A straight run of seats at an angle, which is what every block of a fan-shaped hall is.
     *
     * @return list<array<string, mixed>>
     */
    private function straightRow(string $section, string $row, int $seats, float $x, float $y, float $degrees, float $pitch = 45.0): array
    {
        $out = [];

        for ($i = 0; $i < $seats; $i++) {
            $out[] = [
                'sectionName' => $section,
                'rowName' => $row,
                'number' => $i + 1,
                'x' => $x + cos(deg2rad($degrees)) * $pitch * $i,
                'y' => $y + sin(deg2rad($degrees)) * $pitch * $i,
                'color' => '#4176A5',
            ];
        }

        return $out;
    }

    /** Every seat of every row, as the chart itself computes them. */
    private function placed(array $chart): array
    {
        $points = [];

        $walk = function (array $objects) use (&$walk, &$points) {
            foreach ($objects as $object) {
                if ('section' === $object['type']) {
                    $walk($object['objects']);
                } elseif ('row' === $object['type']) {
                    foreach (RowGeometry::forRow($object) as $index => $point) {
                        if ('empty' !== $object['seats'][$index]['type']) {
                            $points[] = $point;
                        }
                    }
                }
            }
        };

        $walk($chart['floors'][0]['objects']);

        return $points;
    }

    #[Test]
    public function it_puts_every_seat_of_a_rotated_row_back_where_the_export_had_it(): void
    {
        $seats = $this->straightRow('Block 02', 'A', 24, 1000, 900, -9.46);

        ['chart' => $chart] = $this->importer()->import(['seats' => $seats, 'canvasSize' => ['width' => 3000, 'height' => 2000]]);

        $points = $this->placed($chart);
        $offset = 80 - min(array_map(fn ($s) => $s['y'], $seats));

        $this->assertCount(24, $points);

        foreach ($seats as $index => $seat) {
            // The x offset is the same for every seat, so comparing the shape is enough: what is
            // being checked is that the row was not stretched, sheared or reversed.
            $this->assertEqualsWithDelta($seat['y'] + $offset, $points[$index]['y'], 0.05);
        }

        $row = $chart['floors'][0]['objects'][0]['objects'][0];

        $this->assertEqualsWithDelta(-9.46, $row['rotation'], 0.01);
        $this->assertEqualsWithDelta(45.0 - RowGeometry::SEAT_SIZE, $row['seatSpacing'], 0.01);
        $this->assertSame(0.0, (float) $row['curve']);
    }

    #[Test]
    public function an_aisle_becomes_empty_places_rather_than_a_shorter_row(): void
    {
        $seats = $this->straightRow('Stalls', 'F', 10, 500, 500, 0);

        // Two seats' worth of nothing, two thirds along: an aisle, not a rounding error.
        foreach ($this->straightRow('Stalls', 'F', 8, 500 + 45 * 12, 500, 0) as $seat) {
            $seats[] = $seat;
        }

        ['chart' => $chart, 'report' => $report] = $this->importer()->import(['seats' => $seats]);

        $row = $chart['floors'][0]['objects'][0]['objects'][0];
        $kinds = array_column($row['seats'], 'type');

        $this->assertSame(20, count($row['seats']));
        $this->assertSame(2, $report['gaps_filled']);
        $this->assertSame(['empty', 'empty'], array_slice($kinds, 10, 2));
        $this->assertEqualsWithDelta(45.0 - RowGeometry::SEAT_SIZE, $row['seatSpacing'], 0.01);
        $this->assertCount(18, $this->placed($chart));
    }

    #[Test]
    public function a_bowed_row_comes_back_as_a_curve_rather_than_as_a_wobble(): void
    {
        $seats = [];
        $radius = 900.0;
        $sweep = 0.6;

        for ($i = 0; $i < 21; $i++) {
            $angle = -$sweep / 2 + ($sweep * $i) / 20;

            $seats[] = [
                'sectionName' => 'Balcony', 'rowName' => 'A', 'number' => $i + 1,
                'x' => 1500 + $radius * sin($angle),
                'y' => 1200 + $radius * cos($angle),
                'color' => '#58B44F',
            ];
        }

        ['chart' => $chart, 'report' => $report] = $this->importer()->import(['seats' => $seats]);

        $row = $chart['floors'][0]['objects'][0]['objects'][0];

        $this->assertSame(1, $report['curved_rows']);
        $this->assertNotSame(0.0, (float) $row['curve']);

        $points = $this->placed($chart);
        $offsetX = 80 - min(array_map(fn ($s) => $s['x'], $seats));
        $offsetY = 80 - min(array_map(fn ($s) => $s['y'], $seats));

        foreach ($seats as $index => $seat) {
            $this->assertEqualsWithDelta($seat['x'] + $offsetX, $points[$index]['x'], 1.5);
            $this->assertEqualsWithDelta($seat['y'] + $offsetY, $points[$index]['y'], 1.5);
        }
    }

    #[Test]
    public function scenery_drawn_above_the_stage_is_moved_onto_the_canvas_rather_than_lost(): void
    {
        ['chart' => $chart] = $this->importer()->import([
            'seats' => $this->straightRow('Block 01', 'A', 6, 400, 600, 0),
            // Negative, as every export of a stage-top plan seems to be.
            'shapes' => [['type' => 'rectangle', 'x' => -200, 'y' => -160, 'width' => 900, 'height' => 180, 'text' => 'SCÈNE', 'backgroundColor' => '#000000']],
            'lines' => [['x1' => -200, 'y1' => -160, 'x2' => 700, 'y2' => -160, 'color' => '#E8E8E9', 'thickness' => 3]],
            'texts' => [['text' => 'Régie', 'x' => 300, 'y' => 1400, 'fontSize' => 25, 'color' => '#323335']],
        ]);

        $objects = $chart['floors'][0]['objects'];
        $stage = array_values(array_filter($objects, fn ($o) => 'shape' === $o['type'] && 'rect' === $o['kind']))[0];
        $line = array_values(array_filter($objects, fn ($o) => 'shape' === $o['type'] && 'line' === $o['kind']))[0];
        $text = array_values(array_filter($objects, fn ($o) => 'text' === $o['type']))[0];

        $this->assertSame(80.0, $stage->{'x'} ?? $stage['x']);
        $this->assertSame(80.0, $stage['y']);
        $this->assertSame('SCÈNE', $stage['label']);
        $this->assertSame([[80.0, 80.0], [980.0, 80.0]], $line['points']);
        $this->assertSame(3, $line['strokeWidth']);
        $this->assertSame('Régie', $text['text']);

        // The stage is the biggest thing with a word on it, so it is what the room faces.
        $this->assertEqualsWithDelta(530.0, $chart['focalPoint']['x'], 0.01);

        foreach ($this->placed($chart) as $point) {
            $this->assertGreaterThan(0, $point['x']);
            $this->assertLessThan($chart['floors'][0]['canvas']['width'], $point['x']);
        }
    }

    #[Test]
    public function seats_that_shared_a_colour_share_a_price_category(): void
    {
        $seats = $this->straightRow('Block 01', 'A', 6, 400, 600, 0);
        $seats[3]['color'] = '#CD254A';

        ['chart' => $chart, 'report' => $report] = $this->importer()->import(['seats' => $seats]);

        $row = $chart['floors'][0]['objects'][0]['objects'][0];

        $this->assertSame(2, $report['categories']);
        $this->assertCount(2, $chart['categories']);
        // The row carries what five of its six seats agree on; only the odd one out overrides it.
        $this->assertNotNull($row['categoryKey']);
        $this->assertNull($row['seats'][0]['categoryKey']);
        $this->assertNotNull($row['seats'][3]['categoryKey']);
        $this->assertNotSame($row['categoryKey'], $row['seats'][3]['categoryKey']);
    }

    #[Test]
    public function last_nights_sales_are_not_imported_with_the_room(): void
    {
        $seats = $this->straightRow('Block 01', 'A', 4, 400, 600, 0);
        $seats[0]['status'] = 'booked';
        $seats[1]['price'] = 4500;

        ['chart' => $chart, 'report' => $report] = $this->importer()->import(['seats' => $seats]);

        $this->assertSame(['status', 'price'], array_values(array_unique($report['ignored'])));
        $this->assertSame(
            [null, null, null, null],
            array_map(fn ($seat) => $seat['status'] ?? null, $chart['floors'][0]['objects'][0]['objects'][0]['seats']),
        );
    }

    #[Test]
    public function what_comes_out_is_a_chart_the_validator_will_publish(): void
    {
        $seats = [];

        foreach (['Block 01' => 0.0, 'Block 02' => -9.46, 'Block 03' => 9.46] as $block => $angle) {
            foreach (range(0, 9) as $index) {
                foreach ($this->straightRow($block, chr(65 + $index), 20, 400 + 900 * $index, 600 + 60 * $index, $angle) as $seat) {
                    $seats[] = $seat;
                }
            }
        }

        ['chart' => $chart, 'report' => $report] = $this->importer()->import(['seats' => $seats], 'Fan');
        $checked = $this->validator()->validate($chart);

        $this->assertSame([], $checked['errors']);
        $this->assertTrue($checked['valid']);
        $this->assertSame(600, $checked['seat_count']);
        $this->assertSame(3, $report['sections']);
        $this->assertLessThan(0.5, $report['max_placement_error']);
        $this->assertSame('Fan', $chart['name']);

        // Every check the designer shows an organiser, green.
        foreach ($checked['checks'] as $check) {
            $this->assertTrue($check['ok'], $check['label']);
        }
    }

    #[Test]
    public function a_file_with_no_seats_in_it_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->importer()->import(['shapes' => [['type' => 'rectangle', 'x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]]);
    }
}
