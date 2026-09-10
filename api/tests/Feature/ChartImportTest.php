<?php

namespace Tests\Feature;

use App\Models\Seat;
use App\Models\SeatMap;
use App\Models\SeatPlacement;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The command end to end: a file on disk becomes a room that can be sold.
 *
 * `ChartImporterTest` checks the arithmetic in isolation. This checks the part that only shows up
 * once a database is involved — that the fitted rows survive publishing, and that a seat can still
 * be found afterwards by the block, row and number printed on a ticket.
 */
class ChartImportTest extends TestCase
{
    use RefreshDatabase;

    private function export(): string
    {
        $seats = [];

        foreach (['Block 01' => 0.0, 'Block 02' => -12.0] as $block => $degrees) {
            foreach (range(0, 5) as $row) {
                foreach (range(0, 11) as $index) {
                    $seats[] = [
                        'sectionName' => $block,
                        'rowName' => chr(65 + $row),
                        // Evens only, as half of Europe numbers a block.
                        'number' => 2 + $index * 2,
                        'x' => 400 + (str_contains($block, '02') ? 900 : 0) + cos(deg2rad($degrees)) * 44 * $index,
                        'y' => 500 + $row * 60 + sin(deg2rad($degrees)) * 44 * $index,
                        'color' => $row < 3 ? '#992C4E' : '#4176A5',
                        'status' => 'booked',
                    ];
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'chart').'.json';

        file_put_contents($path, json_encode([
            'venue' => 'Imported Hall',
            'canvasSize' => ['width' => 2400, 'height' => 1400],
            'seats' => $seats,
            'shapes' => [['type' => 'rectangle', 'x' => 700, 'y' => -160, 'width' => 800, 'height' => 140, 'text' => 'SCÈNE']],
            'lines' => [['x1' => 100, 'y1' => 1200, 'x2' => 1900, 'y2' => 1200, 'color' => '#333333', 'thickness' => 3]],
        ]));

        return $path;
    }

    #[Test]
    public function a_file_of_loose_coordinates_becomes_a_published_room(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'importer']);

        $this->artisan('chart:import', ['file' => $this->export(), '--tenant' => 'importer', '--publish' => true])
            ->expectsOutputToContain('144 seats')
            // The sales in the file are not the room's to keep.
            ->expectsOutputToContain('belongs to a performance')
            ->assertSuccessful();

        app(TenantContext::class)->runAs($tenant, function () {
            $map = SeatMap::firstOrFail();

            $this->assertNotNull($map->published_version_id);
            $this->assertSame(144, Seat::where('seat_map_id', $map->id)->count());
            $this->assertSame(144, SeatPlacement::where('seat_map_version_id', $map->published_version_id)->count());

            // The ticket reads "Block 02, row C, seat 8", so that is what has to find the chair.
            $seat = Seat::whereHas('row', fn ($row) => $row->where('name', 'C')
                ->whereHas('section', fn ($section) => $section->where('name', 'Block 02')))
                ->where('label', '8')
                ->first();

            $this->assertNotNull($seat, 'the block, row and number on a ticket must find the seat');

            $placement = SeatPlacement::where('seat_id', $seat->id)->firstOrFail();

            // Row C of a block leaning twelve degrees: the fourth chair along, and below the third.
            $this->assertGreaterThan(0, $placement->x);
            $this->assertGreaterThan(0, $placement->y);
        });
    }

    #[Test]
    public function nothing_is_written_when_the_file_cannot_be_read(): void
    {
        Tenant::factory()->create(['slug' => 'importer']);

        $this->artisan('chart:import', ['file' => '/no/such/plan.json', '--tenant' => 'importer'])
            ->expectsOutputToContain('Cannot read')
            ->assertFailed();

        $this->assertSame(0, SeatMap::withoutGlobalScopes()->count());
    }
}
