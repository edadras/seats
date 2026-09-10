<?php

namespace App\Domain\Availability;

use App\Exceptions\ApiException;
use App\Models\Event;

/**
 * "Four together, please."
 *
 * The commonest thing anybody asks a box office, and until now the one thing this platform could
 * not do: a buyer had to find four free chairs side by side on a plan themselves, which on a
 * three-quarters-full house is a puzzle rather than a purchase.
 *
 * What "best" means is not this file's opinion. The organiser already ranked their own seats when
 * they priced them — a stalls seat costs more than the balcony because it is a better seat — so
 * the ranking here is the price list, and nothing is invented on top of it. A buyer who says what
 * they want to spend gets the best seat inside that, which is the other half of the same idea.
 *
 * Two rules the arithmetic exists to serve:
 *
 *   1. Together means physically adjacent, not merely in the same row. Two free chairs with a sold
 *      one between them are not two seats together, and offering them as such is how a couple ends
 *      up sitting either side of a stranger.
 *   2. Do not strand a single seat. Taking four from the middle of a run of six leaves a one and a
 *      one, and a lone seat in a row is the last thing in the house to sell. It is a penalty rather
 *      than a refusal — on a nearly full house, an orphan is better than turning somebody away.
 *
 * Wheelchair spaces are never handed out this way. They are a place somebody needs rather than a
 * place somebody prefers, and giving one to a buyer who did not ask takes it from a buyer who
 * cannot sit anywhere else.
 */
class BestAvailable
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  array{max_amount?: ?int, zone_key?: ?string, section_key?: ?string, prefer?: ?string}  $filters
     * @return list<array{seat_id: string, section: string, row: string, label: string, amount: int}>
     */
    public function find(Event $event, int $quantity, array $filters = []): array
    {
        $quantity = max(1, $quantity);
        $cheapest = 'cheapest' === ($filters['prefer'] ?? null);
        $best = null;
        $bestScore = null;

        foreach ($this->rows($event, $filters) as $row) {
            foreach ($this->windows($row, $quantity) as [$window, $score]) {
                // A cheaper-first buyer wants the same arithmetic with the price term flipped:
                // still together, still not stranding anybody, but reaching for the low end.
                $score[0] = $cheapest ? -$score[0] : $score[0];

                if (null === $bestScore || $this->beats($score, $bestScore)) {
                    $bestScore = $score;
                    $best = $window;
                }
            }
        }

        if (null === $best) {
            return [];
        }

        return array_map(fn (array $seat) => [
            'seat_id' => $seat['seat_id'],
            'section' => $seat['section_name'],
            'row' => $seat['row_name'],
            'label' => $seat['label'],
            'amount' => (int) $seat['amount'],
        ], $best);
    }

    /**
     * The same answer, or a refusal that says what could be had instead.
     *
     * "No" on its own sends a buyer back to the plan to work out for themselves whether three
     * together exist. This says so.
     */
    public function findOrFail(Event $event, int $quantity, array $filters = []): array
    {
        $seats = $this->find($event, $quantity, $filters);

        if ([] !== $seats) {
            return $seats;
        }

        throw ApiException::unprocessable(
            'no_seats_together',
            'There is no run of that many seats side by side.',
            ['largest_together' => $this->largest($event, $quantity, $filters)],
        );
    }

    /**
     * Which of two groups wins, compared one term at a time rather than as a sum.
     *
     * A weighted total cannot be written down honestly here: a price is in minor units, and 5000
     * means five pounds in one currency and five rial in another, so any constant balancing it
     * against "two seats from the middle" would be right in one country and nonsense in the next.
     * In order: the better price band, then not stranding anybody, then nearer the middle of the
     * row — which is what a box office does when it has two equally good pairs to give away.
     *
     * @param  array{0: int, 1: int, 2: float}  $score
     * @param  array{0: int, 1: int, 2: float}  $against
     */
    private function beats(array $score, array $against): bool
    {
        if ($score[0] !== $against[0]) {
            return $score[0] > $against[0];
        }

        if ($score[1] !== $against[1]) {
            return $score[1] < $against[1];
        }

        return $score[2] < $against[2];
    }

    /** The biggest group that could be had, so a refusal can offer a number. */
    private function largest(Event $event, int $wanted, array $filters): int
    {
        for ($size = $wanted - 1; $size >= 1; $size--) {
            if ([] !== $this->find($event, $size, $filters)) {
                return $size;
            }
        }

        return 0;
    }

    /* --------------------------------------------------------------------------- the seating */

    /**
     * Every row of the house, its seats in the order somebody walking along it would meet them.
     *
     * Ordering by the x coordinate alone is wrong the moment a row is rotated — a row running up
     * the side of a hall would come out shuffled — so each row is sorted along its own axis: the
     * line between its two furthest-apart seats. A straight row, a rotated one and a gently curved
     * one all come out in the order they are sat in.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function rows(Event $event, array $filters): array
    {
        $byRow = [];

        foreach ($this->availability->placedSeats($event) as $seat) {
            if (null === $seat['amount']) {
                // No price, no sale. Nothing downstream can hold a seat that costs nothing known.
                continue;
            }

            if ($filters['section_key'] ?? null) {
                if ($seat['section_key'] !== $filters['section_key']) {
                    continue;
                }
            }

            if (($filters['zone_key'] ?? null) && $seat['zone_key'] !== $filters['zone_key']) {
                continue;
            }

            if (($filters['max_amount'] ?? null) !== null && $seat['amount'] > $filters['max_amount']) {
                continue;
            }

            /*
             * A wheelchair space is a need, not a preference, and is never handed out by a machine
             * choosing on somebody's behalf — nor is the chair beside it.
             *
             * The companion half is not merely tidy: that chair cannot be held without the space
             * next to it, so offering it here would be offering a group of seats that the hold
             * immediately refuses, and "four together" would fail for reasons a buyer cannot see.
             */
            if ($seat['accessible'] || $seat['companion']) {
                continue;
            }

            $byRow[$seat['row_id']][] = $seat;
        }

        return array_map(fn (array $seats) => $this->alongTheRow($seats), array_values($byRow));
    }

    /** @param  list<array<string, mixed>>  $seats */
    private function alongTheRow(array $seats): array
    {
        if (count($seats) < 2) {
            return $seats;
        }

        // The row's own direction: the line between the two seats furthest apart in it.
        $from = $seats[0];
        $to = $seats[0];
        $span = -1.0;

        foreach ($seats as $a) {
            foreach ($seats as $b) {
                $distance = ($a['x'] - $b['x']) ** 2 + ($a['y'] - $b['y']) ** 2;

                if ($distance > $span) {
                    $span = $distance;
                    $from = $a;
                    $to = $b;
                }
            }
        }

        $dx = $to['x'] - $from['x'];
        $dy = $to['y'] - $from['y'];

        // Which of the two ends the axis started from is an accident of iteration order, and a row
        // read backwards would offer "6, 5, 4" as a group. Point it one way — rightwards, or
        // downwards for a row running up the side of a hall — so every row reads the same.
        if (abs($dx) >= abs($dy) ? $dx < 0 : $dy < 0) {
            $dx = -$dx;
            $dy = -$dy;
        }

        usort($seats, fn (array $a, array $b) => ($a['x'] * $dx + $a['y'] * $dy)
            <=> ($b['x'] * $dx + $b['y'] * $dy));

        return $seats;
    }

    /**
     * Every run of adjacent free seats in one row, scored.
     *
     * "Adjacent" is decided by the seats between them, not by distance: a window is only offered
     * when every chair from one end of it to the other is free. That is what makes it a group
     * rather than a coincidence.
     *
     * @param  list<array<string, mixed>>  $row
     * @return list<array{0: list<array<string, mixed>>, 1: float}>
     */
    private function windows(array $row, int $quantity): array
    {
        $count = count($row);
        $middle = ($count - 1) / 2;
        $windows = [];
        $start = null;

        for ($i = 0; $i <= $count; $i++) {
            $free = $i < $count && 'available' === $row[$i]['state'];

            if ($free) {
                $start ??= $i;

                continue;
            }

            if (null === $start) {
                continue;
            }

            $run = $i - $start;

            for ($at = $start; $at + $quantity <= $start + $run; $at++) {
                $windows[] = [
                    array_slice($row, $at, $quantity),
                    $this->score(
                        array_slice($row, $at, $quantity),
                        $at,
                        $middle,
                        before: $at - $start,
                        after: $run - ($at - $start) - $quantity,
                    ),
                ];
            }

            $start = null;
        }

        return $windows;
    }

    /**
     * What a group of seats is worth, as one number.
     *
     * The price the organiser set is the whole of "how good is this seat"; the rest is tie-breaking
     * between equals — the middle of a row beats its edges — and the penalty for what the choice
     * would leave behind.
     *
     * @param  list<array<string, mixed>>  $window
     * @param  int  $at      where the group starts along the row
     * @param  int  $before  free seats left on the near side
     * @param  int  $after   free seats left on the far side
     * @return array{0: int, 1: int, 2: float} price, orphans stranded, distance from the middle
     */
    private function score(array $window, int $at, float $middle, int $before, int $after): array
    {
        $amount = 0;

        foreach ($window as $seat) {
            $amount += (int) $seat['amount'];
        }

        return [
            intdiv($amount, max(1, count($window))),
            (1 === $before ? 1 : 0) + (1 === $after ? 1 : 0),
            abs(($at + (count($window) - 1) / 2) - $middle),
        ];
    }
}
