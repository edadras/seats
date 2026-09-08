<?php

namespace Tests\Unit;

use App\Domain\SeatMaps\RowGeometry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP and JavaScript row maths must agree exactly.
 *
 * The designer computes seat positions in the browser; the publisher computes them again in PHP to
 * write placements. If the two ever drift, a chart would be sold with its seats in different places
 * from where the organiser drew them — and nothing else in the system would notice.
 *
 * The fixture is generated from the JavaScript implementation
 * (`node tests/js/generate-golden.cjs`), so these tests fail the moment either side changes.
 */
class RowGeometryTest extends TestCase
{
    /** @return array<string, array{0: array, 1: array}> */
    public static function rows(): array
    {
        return self::casesFrom('rows');
    }

    /** @return array<string, array{0: array, 1: array}> */
    public static function tables(): array
    {
        return self::casesFrom('tables');
    }

    private static function casesFrom(string $group): array
    {
        $golden = json_decode(file_get_contents(__DIR__.'/../Fixtures/row-geometry-golden.json'), true);

        $cases = [];

        foreach ($golden[$group] as $name => $case) {
            $cases[$group.':'.$name] = [$case['input'], $case['positions']];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('rows')]
    public function php_row_positions_match_the_designer(array $input, array $expected): void
    {
        // The fixture records the seat *count*; the geometry needs a seat list of that length.
        // array_merge, not `+`, because the left operand's `seats` key would otherwise win.
        $row = array_merge($input, ['seats' => array_fill(0, $input['seats'], ['key' => 's', 'label' => '1'])]);

        $actual = RowGeometry::forRow($row);

        $this->assertCount(count($expected), $actual);

        foreach ($expected as $index => $point) {
            $this->assertEqualsWithDelta($point['x'], $actual[$index]['x'], 0.01, "seat {$index} x");
            $this->assertEqualsWithDelta($point['y'], $actual[$index]['y'], 0.01, "seat {$index} y");
            $this->assertEqualsWithDelta($point['rotation'], $actual[$index]['rotation'], 0.01, "seat {$index} rotation");
        }
    }

    #[Test]
    #[DataProvider('tables')]
    public function php_table_positions_match_the_designer(array $input, array $expected): void
    {
        $table = array_merge($input, ['seats' => array_fill(0, $input['seats'], ['key' => 's', 'label' => '1'])]);

        $actual = RowGeometry::forTable($table);

        $this->assertCount(count($expected), $actual);

        foreach ($expected as $index => $point) {
            $this->assertEqualsWithDelta($point['x'], $actual[$index]['x'], 0.01, "chair {$index} x");
            $this->assertEqualsWithDelta($point['y'], $actual[$index]['y'], 0.01, "chair {$index} y");
        }
    }

    #[Test]
    public function seat_spacing_is_the_gap_between_chairs(): void
    {
        $positions = RowGeometry::forRow([
            'x' => 0, 'y' => 0, 'rotation' => 0, 'curve' => 0, 'seatSpacing' => 10,
            'seats' => [['key' => 'a'], ['key' => 'b']],
        ]);

        $distance = hypot(
            $positions[1]['x'] - $positions[0]['x'],
            $positions[1]['y'] - $positions[0]['y'],
        );

        $this->assertEqualsWithDelta(RowGeometry::SEAT_SIZE + 10, $distance, 0.01);
    }

    #[Test]
    public function a_curved_row_keeps_its_seats_evenly_spaced(): void
    {
        $positions = RowGeometry::forRow([
            'x' => 0, 'y' => 0, 'rotation' => 0, 'curve' => 35, 'seatSpacing' => 5,
            'seats' => array_fill(0, 13, ['key' => 's']),
        ]);

        $gaps = [];

        for ($i = 1; $i < count($positions); $i++) {
            $gaps[] = hypot(
                $positions[$i]['x'] - $positions[$i - 1]['x'],
                $positions[$i]['y'] - $positions[$i - 1]['y'],
            );
        }

        $this->assertEqualsWithDelta(0, max($gaps) - min($gaps), 0.5);
    }

    #[Test]
    public function an_empty_row_has_no_positions(): void
    {
        $this->assertSame([], RowGeometry::forRow(['x' => 0, 'y' => 0, 'seats' => []]));
        $this->assertSame([], RowGeometry::forTable(['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'seats' => []]));
    }
}
