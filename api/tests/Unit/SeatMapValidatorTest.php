<?php

namespace Tests\Unit;

use App\Domain\SeatMaps\SeatMapValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server-side validator, which is the authority on whether a chart may go on sale.
 *
 * The designer runs the same checks in the browser for immediate feedback, but only these decide.
 */
class SeatMapValidatorTest extends TestCase
{
    private function validator(): SeatMapValidator
    {
        return new SeatMapValidator([
            'max_seats_per_map' => 100,
            'max_sections_per_map' => 5,
            'max_rows_per_section' => 5,
            'max_geometry_bytes' => 1024 * 1024,
            'max_shapes' => 10,
            'max_texts' => 10,
            'canvas_max' => 20000,
        ]);
    }

    private function row(string $label, int $seats, array $overrides = []): array
    {
        $list = [];

        for ($i = 1; $i <= $seats; $i++) {
            $list[] = ['type' => 'seat', 'key' => $label.'-'.$i, 'label' => (string) $i];
        }

        return array_replace([
            'type' => 'row',
            'key' => 'row-'.$label,
            'x' => 300,
            'y' => 300,
            'rotation' => 0,
            'curve' => 0,
            'seatSpacing' => 4,
            'categoryKey' => 'standard',
            'labeling' => ['enabled' => true, 'label' => $label, 'displayedLabel' => null, 'position' => 'both'],
            'seats' => $list,
        ], $overrides);
    }

    private function chart(array $objects, array $overrides = []): array
    {
        return array_replace([
            'version' => 2,
            'focalPoint' => ['x' => 400, 'y' => 100],
            'categories' => [['key' => 'standard', 'label' => 'Standard', 'color' => '#2d6cdf']],
            'floors' => [[
                'key' => '1',
                'canvas' => ['width' => 1000, 'height' => 800],
                'objects' => $objects,
            ]],
        ], $overrides);
    }

    #[Test]
    public function it_accepts_a_well_formed_chart(): void
    {
        $report = $this->validator()->validate($this->chart([$this->row('A', 5)]));

        $this->assertTrue($report['valid'], json_encode($report['errors']));
        $this->assertSame(5, $report['seat_count']);
        $this->assertSame(5, $report['places']);
        $this->assertSame([], $report['warnings']);
    }

    #[Test]
    public function the_checklist_matches_the_designer_panel(): void
    {
        $report = $this->validator()->validate($this->chart([$this->row('A', 3)]));

        $this->assertSame([
            'No duplicate objects',
            'All objects are labeled',
            'All objects are categorized',
            'One category per object type',
            'Focal point is set',
        ], array_column($report['checks'], 'label'));

        $this->assertTrue(collect($report['checks'])->every(fn ($check) => $check['ok']));
    }

    #[Test]
    public function a_chart_with_no_floors_is_rejected(): void
    {
        $report = $this->validator()->validate(['version' => 2, 'floors' => []]);

        $this->assertFalse($report['valid']);
        $this->assertContains('no_floors', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function two_seats_in_a_row_may_not_share_a_label(): void
    {
        $row = $this->row('A', 3);
        $row['seats'][1]['label'] = '1';

        $report = $this->validator()->validate($this->chart([$row]));

        $this->assertFalse($report['valid']);
        $this->assertContains('duplicate_label', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function a_row_pushed_off_the_canvas_is_rejected(): void
    {
        $report = $this->validator()->validate($this->chart([$this->row('A', 5, ['x' => 9000])]));

        $this->assertFalse($report['valid']);
        $this->assertContains('seat_off_canvas', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function a_chart_with_nothing_to_sell_is_rejected(): void
    {
        $report = $this->validator()->validate($this->chart([]));

        $this->assertFalse($report['valid']);
        $this->assertContains('no_places', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function an_unlabelled_row_is_rejected(): void
    {
        $row = $this->row('A', 3);
        $row['labeling']['label'] = '';

        $report = $this->validator()->validate($this->chart([$row]));

        $this->assertFalse($report['valid']);
        $this->assertContains('object_not_labeled', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function overlapping_seats_warn_without_blocking(): void
    {
        // Two rows drawn on top of each other. An organiser may have meant it — a bench, a
        // companion seat — so it is surfaced and never blocks a publish.
        $report = $this->validator()->validate($this->chart([
            $this->row('A', 4),
            $this->row('B', 4, ['key' => 'row-B', 'y' => 301]),
        ]));

        $this->assertTrue($report['valid'], json_encode($report['errors']));
        $this->assertContains('seats_overlap', array_column($report['warnings'], 'code'));
    }

    #[Test]
    public function an_uncategorised_row_is_a_warning_not_a_blocker(): void
    {
        // A chart mid-build is routinely uncategorised; pricing is checked when an event is put on
        // sale, not when the map is drawn.
        $report = $this->validator()->validate($this->chart([$this->row('A', 3, ['categoryKey' => null])]));

        $this->assertTrue($report['valid']);
        $this->assertContains('object_not_categorized', array_column($report['warnings'], 'code'));
        $this->assertFalse(collect($report['checks'])->firstWhere('code', 'all_categorized')['ok']);
    }

    #[Test]
    public function the_same_row_label_in_two_sections_is_fine(): void
    {
        $report = $this->validator()->validate($this->chart([
            ['type' => 'section', 'key' => 'stalls', 'labeling' => ['label' => 'Stalls'],
                'polygon' => [[0, 0], [500, 0], [500, 500], [0, 500]], 'objects' => [$this->row('A', 4)]],
            ['type' => 'section', 'key' => 'circle', 'labeling' => ['label' => 'Circle'],
                'polygon' => [[500, 0], [900, 0], [900, 500], [500, 500]],
                'objects' => [$this->row('A', 4, ['key' => 'row-circle-A', 'y' => 500])]],
        ]));

        // "Stalls A" and "Circle A" are unambiguous on a ticket, and every venue has both.
        $this->assertTrue($report['valid'], json_encode($report['errors']));
        $this->assertTrue(collect($report['checks'])->firstWhere('code', 'no_duplicate_objects')['ok']);
    }

    #[Test]
    public function a_general_admission_area_contributes_its_capacity(): void
    {
        $report = $this->validator()->validate($this->chart([
            $this->row('A', 5),
            [
                'type' => 'area', 'key' => 'pit', 'categoryKey' => 'standard',
                'labeling' => ['label' => 'Pit'],
                'shape' => ['kind' => 'rect', 'x' => 100, 'y' => 600, 'width' => 300, 'height' => 100],
                'capacity' => ['type' => 'generalAdmission', 'places' => 250],
            ],
        ]));

        // Places counts everything sellable; seat_count stays the number of named chairs, which is
        // what the plan limit measures.
        $this->assertSame(255, $report['places']);
        $this->assertSame(5, $report['seat_count']);
    }

    #[Test]
    public function an_area_with_no_capacity_is_rejected(): void
    {
        $report = $this->validator()->validate($this->chart([
            [
                'type' => 'area', 'key' => 'pit', 'categoryKey' => 'standard',
                'labeling' => ['label' => 'Pit'],
                'shape' => ['kind' => 'rect', 'x' => 100, 'y' => 600, 'width' => 300, 'height' => 100],
                'capacity' => ['type' => 'generalAdmission', 'places' => 0],
            ],
        ]));

        $this->assertFalse($report['valid']);
        $this->assertContains('capacity_missing', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function a_table_sold_whole_counts_as_one_place(): void
    {
        $seats = array_map(fn ($i) => ['type' => 'seat', 'key' => 't-'.$i, 'label' => (string) $i], range(1, 10));

        $whole = $this->validator()->validate($this->chart([[
            'type' => 'table', 'key' => 'gala', 'categoryKey' => 'standard', 'bookAs' => 'table',
            'labeling' => ['label' => 'Table 1'], 'x' => 300, 'y' => 300,
            'width' => 120, 'height' => 120, 'shape' => 'round', 'seats' => $seats,
        ]]));

        $this->assertSame(1, $whole['places']);

        $byChair = $this->validator()->validate($this->chart([[
            'type' => 'table', 'key' => 'club', 'categoryKey' => 'standard', 'bookAs' => 'seat',
            'labeling' => ['label' => 'Table 1'], 'x' => 300, 'y' => 300,
            'width' => 120, 'height' => 120, 'shape' => 'round', 'seats' => $seats,
        ]]));

        $this->assertSame(10, $byChair['places']);
    }

    #[Test]
    public function one_category_across_seats_and_standing_is_flagged(): void
    {
        $report = $this->validator()->validate($this->chart([
            $this->row('A', 4),
            [
                'type' => 'area', 'key' => 'pit', 'categoryKey' => 'standard',
                'labeling' => ['label' => 'Pit'],
                'shape' => ['kind' => 'rect', 'x' => 100, 'y' => 600, 'width' => 300, 'height' => 100],
                'capacity' => ['type' => 'generalAdmission', 'places' => 50],
            ],
        ]));

        $this->assertFalse(collect($report['checks'])->firstWhere('code', 'one_category_per_type')['ok']);
        $this->assertTrue($report['valid'], 'some venues really do price them alike, so it must not block');
    }

    #[Test]
    public function a_missing_focal_point_shows_in_the_checklist_without_blocking(): void
    {
        $chart = $this->chart([$this->row('A', 3)]);
        $chart['focalPoint'] = null;

        $report = $this->validator()->validate($chart);

        $this->assertTrue($report['valid']);
        $this->assertFalse(collect($report['checks'])->firstWhere('code', 'focal_point')['ok']);
    }

    #[Test]
    public function it_enforces_the_seat_ceiling(): void
    {
        $report = $this->validator()->validate($this->chart([$this->row('A', 101, ['seatSpacing' => 0])]));

        $this->assertFalse($report['valid']);
        $this->assertContains('too_many_seats', array_column($report['errors'], 'code'));
    }

    /**
     * The scenery ceilings, which were written into the configuration when the validator was built
     * and then never read by it. An imported plan is what finds this: tracing a floor drawing turns
     * every wall into its own line object, and a thousand of them arrive at once.
     */
    #[Test]
    public function it_enforces_the_ceiling_on_scenery(): void
    {
        $scenery = [];

        for ($i = 0; $i < 11; $i++) {
            $scenery[] = ['type' => 'shape', 'key' => 'wall-'.$i, 'kind' => 'line', 'x' => $i, 'y' => 0];
        }

        $report = $this->validator()->validate($this->chart(array_merge([$this->row('A', 3)], $scenery)));

        $this->assertFalse($report['valid']);
        $this->assertContains('too_many_shapes', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function it_enforces_the_ceiling_on_text_labels(): void
    {
        $labels = [];

        for ($i = 0; $i < 11; $i++) {
            $labels[] = ['type' => 'text', 'key' => 'text-'.$i, 'text' => 'Exit', 'x' => $i, 'y' => 0];
        }

        $report = $this->validator()->validate($this->chart(array_merge([$this->row('A', 3)], $labels)));

        $this->assertFalse($report['valid']);
        $this->assertContains('too_many_texts', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function scenery_under_the_ceiling_passes(): void
    {
        $report = $this->validator()->validate($this->chart([
            $this->row('A', 3),
            ['type' => 'shape', 'key' => 'stage', 'kind' => 'rect', 'x' => 10, 'y' => 10, 'width' => 100, 'height' => 40],
            ['type' => 'text', 'key' => 'text-exit', 'text' => 'Exit', 'x' => 10, 'y' => 10],
        ]));

        $this->assertTrue($report['valid']);
        // Scenery is not a place, and counting it as one would oversell the room.
        $this->assertSame(3, $report['places']);
    }

    #[Test]
    public function oversized_geometry_is_rejected_without_being_walked(): void
    {
        $validator = new SeatMapValidator([
            'max_seats_per_map' => 100, 'max_sections_per_map' => 5, 'max_rows_per_section' => 5,
            'max_geometry_bytes' => 100, 'max_shapes' => 10, 'max_texts' => 10, 'canvas_max' => 20000,
        ]);

        $report = $validator->validate($this->chart([$this->row('A', 5)]));

        $this->assertFalse($report['valid']);
        $this->assertSame(['geometry_too_large'], array_column($report['errors'], 'code'));
    }
}
