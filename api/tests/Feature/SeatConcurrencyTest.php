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
     * Launch N independent processes that all reach for the same seats at once.
     *
     * @return list<array>
     */
    private function race(int $count, string $eventId, array $seatIds): array
    {
        return $this->raceVarying(array_fill(0, $count, $seatIds), $eventId);
    }

    /**
     * @param  list<list<string>>  $seatSets
     * @return list<array>
     */
    private function raceVarying(array $seatSets, string $eventId): array
    {
        $processes = [];
        $pipes = [];

        foreach ($seatSets as $index => $seatIds) {
            $command = sprintf(
                '%s artisan seatmap:attempt-hold %s %s --session=probe-%d',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($eventId),
                escapeshellarg(implode(',', $seatIds)),
                $index,
            );

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
