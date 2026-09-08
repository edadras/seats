<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Hold;
use App\Models\HoldItem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The acceptance criterion the whole design exists to satisfy: with many simultaneous requests for
 * one seat, exactly one succeeds.
 *
 * Deliberately *not* RefreshDatabase — that wraps each test in an uncommitted transaction, which
 * the separate processes below could not see. `DatabaseTruncation` commits, so the contenders race
 * over real rows.
 */
#[Group('concurrency')]
class SeatConcurrencyTest extends TestCase
{
    use BuildsSeatingFixtures, DatabaseTruncation;

    private const CONTENDERS = 100;

    #[Test]
    public function exactly_one_of_a_hundred_concurrent_requests_wins_a_seat(): void
    {
        ['event' => $event, 'seats' => $seats] = $this->makeSellableEvent(rows: 1, perRow: 1);
        $seatId = $seats->first()->id;

        $results = $this->race(self::CONTENDERS, $event->id, [$seatId]);

        $winners = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);
        $losers = array_filter($results, fn ($r) => ($r['ok'] ?? false) === false);

        $this->assertCount(self::CONTENDERS, $results, 'Every process must report an outcome.');

        $this->assertCount(1, $winners, sprintf(
            'Expected exactly one winner, got %d. Codes seen: %s',
            count($winners),
            json_encode(array_count_values(array_column($results, 'code'))),
        ));

        // Losing must be a clean, explained rejection — never a crash or a 500.
        $codes = array_unique(array_column($losers, 'code'));
        $this->assertEqualsCanonicalizing(['seat_unavailable'], $codes, sprintf(
            'Losers must all be told the seat is gone; saw %s', json_encode($codes)
        ));

        // And the database must agree: one live hold item for that seat, not 100.
        $this->asTenant($event->tenant, function () use ($seatId) {
            $this->assertSame(1, HoldItem::where('seat_id', $seatId)->whereNull('released_at')->count());
            $this->assertSame(1, Hold::where('status', 'active')->count());
        });
    }

    #[Test]
    public function concurrent_requests_for_overlapping_seat_sets_do_not_deadlock(): void
    {
        // Overlapping multi-seat requests are the classic deadlock shape: two transactions grabbing
        // the same pair of rows in opposite orders. HoldService sorts seat ids before locking, so
        // every contender takes them in the same order and one simply waits.
        ['event' => $event, 'seats' => $seats] = $this->makeSellableEvent(rows: 1, perRow: 4);

        $a = [$seats[0]->id, $seats[1]->id];
        $b = [$seats[1]->id, $seats[0]->id]; // same pair, reversed
        $c = [$seats[1]->id, $seats[2]->id];

        $results = $this->raceVarying([
            ...array_fill(0, 10, $a),
            ...array_fill(0, 10, $b),
            ...array_fill(0, 10, $c),
        ], $event->id);

        $this->assertCount(30, $results);

        $unexpected = array_filter($results, fn ($r) => ($r['code'] ?? null) === 'unexpected');
        $this->assertSame([], $unexpected, sprintf(
            'No contender may fail with a deadlock or unhandled error: %s', json_encode($unexpected)
        ));

        $winners = array_values(array_filter($results, fn ($r) => ($r['ok'] ?? false) === true));

        // Seat 1 is in every request, so at most one contender can ever win.
        $this->assertCount(1, $winners, 'Only one request can hold the seat common to all of them.');
    }

    #[Test]
    public function a_confirmed_seat_cannot_be_held_by_a_later_race(): void
    {
        ['event' => $event, 'seats' => $seats, 'tenant' => $tenant] = $this->makeSellableEvent(rows: 1, perRow: 1);
        $seatId = $seats->first()->id;

        // Sell the seat outright, then let a crowd try to hold it.
        $this->asTenant($tenant, fn () => Allocation::create([
            'event_id' => $event->id,
            'seat_id' => $seatId,
            'api_client_id' => $this->makeApiClient($tenant)['client']->id,
            'external_order_id' => 'wc_sold',
            'status' => 'active',
            'amount' => 2500,
            'currency' => 'EUR',
            'seat_map_version_id' => $event->seat_map_version_id,
            'section_name' => 'Stalls',
            'row_name' => 'Row A',
            'seat_label' => '1',
            'allocated_at' => now(),
        ]));

        $results = $this->race(20, $event->id, [$seatId]);

        $this->assertSame([], array_filter($results, fn ($r) => ($r['ok'] ?? false) === true),
            'A sold seat must never be re-held.');
    }

    /**
     * Standing room cannot be guarded by a unique index — its invariant is a sum against a limit,
     * not one row per chair. It is serialised with an advisory lock instead, and this is the test
     * that the lock actually holds: a hundred buyers reaching for a twenty-place pit must sell
     * twenty places, not more.
     */
    #[Test]
    public function a_general_admission_area_never_oversells(): void
    {
        $ctx = $this->makeSellableEvent(chart: $this->geometryWithStandingArea(20));
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $results = $this->raceCapacity(60, $ctx['event']->id, $areaId, 1);

        $winners = array_filter($results, fn ($r) => ($r['ok'] ?? false) === true);

        $this->assertCount(20, $winners, sprintf(
            'Expected exactly 20 winners for 20 places, got %d. Codes: %s',
            count($winners),
            json_encode(array_count_values(array_column($results, 'code'))),
        ));

        $codes = array_unique(array_column(array_filter($results, fn ($r) => ($r['ok'] ?? false) === false), 'code'));
        $this->assertEqualsCanonicalizing(['capacity_unavailable'], $codes, sprintf(
            'Losers must be told the area is full; saw %s', json_encode($codes)
        ));

        // And the database agrees: the running total is exactly the capacity, never over it.
        $this->asTenant($ctx['tenant'], function () use ($areaId) {
            $held = HoldItem::where('capacity_object_id', $areaId)->whereNull('released_at')->sum('quantity');

            $this->assertSame(20, (int) $held);
        });
    }

    #[Test]
    public function concurrent_requests_for_different_quantities_never_exceed_capacity(): void
    {
        // Mixed sizes are the harder case: a naive check-then-insert can let a large request slip
        // in behind a small one and take the total past the limit.
        $ctx = $this->makeSellableEvent(chart: $this->geometryWithStandingArea(30));
        $areaId = $ctx['areas']->firstWhere('key', 'pit')->id;

        $quantities = [];

        for ($i = 0; $i < 40; $i++) {
            $quantities[] = 1 + ($i % 5);
        }

        $results = $this->raceCapacityVarying($quantities, $ctx['event']->id, $areaId);

        $sold = 0;

        foreach ($results as $index => $result) {
            if (($result['ok'] ?? false) === true) {
                $sold += $quantities[$index];
            }
        }

        $this->assertLessThanOrEqual(30, $sold, 'the area sold more places than it has');

        $this->asTenant($ctx['tenant'], function () use ($areaId, $sold) {
            $held = (int) HoldItem::where('capacity_object_id', $areaId)->whereNull('released_at')->sum('quantity');

            $this->assertSame($sold, $held);
            $this->assertLessThanOrEqual(30, $held);
        });
    }

    /**
     * Launch N independent processes that all reach for the same seats at once.
     *
     * @return list<array>
     */
    private function race(int $count, string $eventId, array $seatIds): array
    {
        return $this->raceVarying(array_fill(0, $count, $seatIds), $eventId);
    }

    /** @return list<array> */
    private function raceCapacity(int $count, string $eventId, string $areaId, int $quantity): array
    {
        return $this->raceCapacityVarying(array_fill(0, $count, $quantity), $eventId, $areaId);
    }

    /**
     * @param  list<int>  $quantities
     * @return list<array>
     */
    private function raceCapacityVarying(array $quantities, string $eventId, string $areaId): array
    {
        return $this->spawn(array_map(fn (int $quantity, int $index) => sprintf(
            '%s artisan seatmap:attempt-hold %s - --area=%s --quantity=%d --session=ga-%d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($eventId),
            escapeshellarg($areaId),
            $quantity,
            $index,
        ), $quantities, array_keys($quantities)));
    }

    /**
     * @param  list<list<string>>  $seatSets
     * @return list<array>
     */
    private function raceVarying(array $seatSets, string $eventId): array
    {
        return $this->spawn(array_map(fn (array $seatIds, int $index) => sprintf(
            '%s artisan seatmap:attempt-hold %s %s --session=probe-%d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($eventId),
            escapeshellarg(implode(',', $seatIds)),
            $index,
        ), $seatSets, array_keys($seatSets)));
    }

    /**
     * Run the given commands as simultaneous OS processes and collect their reported outcomes.
     *
     * @param  list<string>  $commands
     * @return list<array>
     */
    private function spawn(array $commands): array
    {
        $processes = [];
        $pipes = [];

        foreach ($commands as $index => $command) {
            $process = proc_open(
                $command,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $processPipes,
                base_path(),
                // Children must target the same database this test is using.
                ['APP_ENV' => 'testing', 'DB_DATABASE' => 'seatmap_test'] + getenv(),
            );

            if (! is_resource($process)) {
                $this->fail('Could not launch contender process '.$index);
            }

            stream_set_blocking($processPipes[1], false);
            stream_set_blocking($processPipes[2], false);

            $processes[$index] = $process;
            $pipes[$index] = $processPipes;
        }

        $results = [];

        foreach ($processes as $index => $process) {
            $stdout = '';
            $stderr = '';

            // Drain as they finish; a full pipe buffer would block the child forever.
            while (true) {
                $stdout .= stream_get_contents($pipes[$index][1]);
                $stderr .= stream_get_contents($pipes[$index][2]);

                $status = proc_get_status($process);

                if (! $status['running']) {
                    $stdout .= stream_get_contents($pipes[$index][1]);
                    $stderr .= stream_get_contents($pipes[$index][2]);
                    break;
                }

                usleep(2000);
            }

            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            proc_close($process);

            $results[$index] = $this->parse($stdout, $stderr, $index);
        }

        ksort($results);

        return array_values($results);
    }

    private function parse(string $stdout, string $stderr, int $index): array
    {
        foreach (array_reverse(preg_split('/\R/', trim($stdout)) ?: []) as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return [
            'ok' => false,
            'code' => 'unexpected',
            'message' => sprintf('Process %d produced no result. stdout=%s stderr=%s', $index, $stdout, $stderr),
        ];
    }
}
