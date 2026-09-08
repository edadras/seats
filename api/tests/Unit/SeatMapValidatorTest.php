<?php

namespace Tests\Unit;

use App\Domain\SeatMaps\SeatMapValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

    private function geometry(array $overrides = []): array
    {
        return array_replace_recursive([
            'canvas' => ['width' => 1000, 'height' => 800],
            'sections' => [[
                'key' => 'a', 'name' => 'A',
                'rows' => [[
                    'key' => 'r1', 'name' => 'Row 1',
                    'seats' => [
                        ['key' => 's1', 'label' => '1', 'x' => 100, 'y' => 100],
                        ['key' => 's2', 'label' => '2', 'x' => 140, 'y' => 100],
                    ],
                ]],
            ]],
        ], $overrides);
    }

    #[Test]
    public function it_accepts_a_well_formed_map(): void
    {
        $report = $this->validator()->validate($this->geometry());

        $this->assertTrue($report['valid'], json_encode($report['errors']));
        $this->assertSame(2, $report['seat_count']);
        $this->assertSame([], $report['warnings']);
    }

    #[Test]
    public function it_rejects_duplicate_seat_keys_within_a_row(): void
    {
        $geometry = $this->geometry();
        $geometry['sections'][0]['rows'][0]['seats'][1]['key'] = 's1';

        $report = $this->validator()->validate($geometry);

        $this->assertFalse($report['valid']);
        $this->assertContains('duplicate_seat_key', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function it_rejects_seats_outside_the_canvas(): void
    {
        $geometry = $this->geometry();
        $geometry['sections'][0]['rows'][0]['seats'][0]['x'] = 5000;

        $report = $this->validator()->validate($geometry);

        $this->assertFalse($report['valid']);
        $this->assertContains('seat_off_canvas', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function it_rejects_a_map_with_no_seats(): void
    {
        $geometry = $this->geometry();
        $geometry['sections'][0]['rows'][0]['seats'] = [];

        $report = $this->validator()->validate($geometry);

        $this->assertFalse($report['valid']);
        $this->assertContains('no_seats', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function overlapping_seats_are_a_warning_not_an_error(): void
    {
        // Two chairs a couple of pixels apart is usually a mis-drag, but an organiser may have
        // meant it (a bench, a companion seat). Warn, never block.
        $geometry = $this->geometry();
        $geometry['sections'][0]['rows'][0]['seats'][1]['x'] = 102;

        $report = $this->validator()->validate($geometry);

        $this->assertTrue($report['valid']);
        $this->assertContains('seats_overlap', array_column($report['warnings'], 'code'));
    }

    #[Test]
    public function it_enforces_the_seat_ceiling(): void
    {
        $seats = [];

        for ($i = 0; $i < 101; $i++) {
            $seats[] = ['key' => "s{$i}", 'label' => (string) $i, 'x' => 10 + $i, 'y' => 500];
        }

        $geometry = $this->geometry();
        $geometry['sections'][0]['rows'][0]['seats'] = $seats;

        $report = $this->validator()->validate($geometry);

        $this->assertFalse($report['valid']);
        $this->assertContains('too_many_seats', array_column($report['errors'], 'code'));
    }

    #[Test]
    public function oversized_geometry_is_rejected_without_being_walked(): void
    {
        $validator = new SeatMapValidator([
            'max_seats_per_map' => 100, 'max_sections_per_map' => 5, 'max_rows_per_section' => 5,
            'max_geometry_bytes' => 100, 'max_shapes' => 10, 'max_texts' => 10, 'canvas_max' => 20000,
        ]);

        $report = $validator->validate($this->geometry());

        $this->assertFalse($report['valid']);
        $this->assertSame(['geometry_too_large'], array_column($report['errors'], 'code'));
    }
}
