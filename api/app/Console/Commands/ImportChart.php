<?php

namespace App\Console\Commands;

use App\Domain\SeatMaps\ChartImporter;
use App\Domain\SeatMaps\SeatMapPublisher;
use App\Domain\SeatMaps\SeatMapValidator;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\Tenant;
use App\Models\Venue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bring a seating plan in from another platform.
 *
 * A command rather than an upload button, on purpose. Importing is the one operation that creates
 * thousands of rows from a file somebody else wrote, and the honest place for it is a terminal
 * where an operator can read the report, look at the map, and run it again — not a progress bar in
 * a browser tab that has to guess what to do when a row will not fit.
 *
 * Nothing is published by this command unless it is asked for. An imported plan lands as a draft so
 * that the person who owns the venue looks at it first; a map goes on sale when a human says so.
 */
class ImportChart extends Command
{
    protected $signature = 'chart:import
        {file : A chart exported from another platform, as JSON}
        {--tenant= : Tenant id or slug to import into}
        {--venue= : Venue id to attach the map to; a venue is created when this is left out}
        {--name= : What to call the map}
        {--out= : Write the converted chart here instead of saving it}
        {--publish : Publish the imported version straight away}';

    protected $description = 'Convert a seating plan exported from another platform into a seat map';

    public function handle(ChartImporter $importer, SeatMapValidator $validator, TenantContext $tenants): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $export = json_decode((string) file_get_contents($path), true);

        if (! is_array($export)) {
            $this->error('That file is not JSON.');

            return self::FAILURE;
        }

        ['chart' => $chart, 'report' => $report] = $importer->import($export, $this->option('name'));
        $checked = $validator->validate($chart);

        $this->table(['Read', 'Count'], [
            ['Seats', $report['seats']],
            ['Rows', $report['rows']],
            ['Sections', $report['sections']],
            ['Price categories', $report['categories']],
            ['Shapes and lines', $report['shapes']],
            ['Text labels', $report['texts']],
            ['Empty places left for aisles', $report['gaps_filled']],
            ['Curved rows', $report['curved_rows']],
            ['Worst seat moved by', $report['max_placement_error'].' units'],
            ['Canvas', $report['canvas']['width'].' × '.$report['canvas']['height']],
        ]);

        if ([] !== $report['ignored']) {
            // Not a warning about the file: a warning about what a map *is*. Sales belong to a
            // performance, and this plan will be sold many times.
            $this->warn('Not imported, because it belongs to a performance rather than to a room: '
                .implode(', ', array_unique($report['ignored'])));
        }

        foreach ($checked['errors'] as $error) {
            $this->error($error['message']);
        }

        foreach (array_slice($checked['warnings'], 0, 10) as $warning) {
            $this->warn($warning['message']);
        }

        if (! $checked['valid']) {
            $this->error('The imported chart will not validate, so nothing was saved.');

            return self::FAILURE;
        }

        $this->info(sprintf('%d seats over %d bookable places.', $checked['seat_count'], $checked['places']));

        if ($out = $this->option('out')) {
            file_put_contents($out, json_encode($chart, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info("Written to {$out}.");

            return self::SUCCESS;
        }

        $tenant = $this->tenant();

        if (! $tenant) {
            return self::FAILURE;
        }

        return $tenants->runAs($tenant, function () use ($chart, $checked) {
            $venue = $this->venue($chart['name']);

            $map = DB::transaction(function () use ($venue, $chart, $checked) {
                $map = SeatMap::create(['venue_id' => $venue->id, 'name' => $chart['name']]);

                SeatMapVersion::create([
                    'seat_map_id' => $map->id,
                    'version' => 1,
                    'status' => 'draft',
                    'geometry' => $chart,
                    'seat_count' => $checked['seat_count'],
                ]);

                return $map;
            });

            $this->info("Imported as map {$map->id} in venue {$venue->id}.");

            if ($this->option('publish')) {
                $version = app(SeatMapPublisher::class)->publish($map, $map->draftVersion());
                $this->info("Published version {$version->version}.");
            }

            return self::SUCCESS;
        });
    }

    private function tenant(): ?Tenant
    {
        $given = (string) ($this->option('tenant') ?? '');

        if ('' === $given) {
            $tenants = Tenant::query()->limit(2)->get();

            if (1 !== $tenants->count()) {
                $this->error('Say which tenant with --tenant; this installation has more than one.');

                return null;
            }

            return $tenants->first();
        }

        // Postgres refuses to compare a uuid column with a word, so the id is only asked about
        // when the thing given actually looks like one.
        $tenant = Tenant::query()
            ->where('slug', $given)
            ->when(
                (bool) preg_match('/^[0-9a-f-]{36}$/i', $given),
                fn ($query) => $query->orWhere('id', $given),
            )
            ->first();

        if (! $tenant) {
            $this->error("No tenant {$given}.");
        }

        return $tenant;
    }

    private function venue(string $name): Venue
    {
        if ($given = $this->option('venue')) {
            return Venue::findOrFail($given);
        }

        return Venue::create(['name' => $name]);
    }
}
