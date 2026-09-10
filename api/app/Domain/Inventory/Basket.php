<?php

namespace App\Domain\Inventory;

use App\Models\Hold;

/**
 * What a hold is made of, in the shape {@see HoldService::create} wants it back in.
 *
 * Read off the signed snapshot rather than re-queried, always. The snapshot is what the buyer
 * chose and what the server priced at that moment; a fresh query against hold items would answer a
 * slightly different question and would answer nothing at all once the hold had been swept away.
 *
 * Two features need this and neither should own it: a season ticket repeats one night's basket
 * across a run, and a recovery link tries to take the same seats again an hour later. Both are
 * "make that basket again, somewhere else", and one reading of the snapshot is enough.
 */
final class Basket
{
    private function __construct(
        /** @var list<string> */
        public readonly array $seats,
        /** @var array<string, int> capacity object id => places */
        public readonly array $capacity,
        /** @var array<string, string> seat id => ticket type id */
        public readonly array $seatTypes,
        /** @var array<string, array<string, int>> capacity object id => ticket type id => places */
        public readonly array $areaTypes,
    ) {}

    public static function of(Hold $hold): self
    {
        return self::fromSnapshot($hold->price_snapshot['decoded'] ?? []);
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $seats = [];
        $seatTypes = [];
        $capacity = [];
        $areaTypes = [];

        foreach ($snapshot['seats'] ?? [] as $seat) {
            $seats[] = (string) $seat['seat_id'];

            if (! empty($seat['ticket_type_id'])) {
                $seatTypes[(string) $seat['seat_id']] = (string) $seat['ticket_type_id'];
            }
        }

        foreach ($snapshot['areas'] ?? [] as $area) {
            $id = (string) $area['capacity_object_id'];
            $quantity = max(1, (int) ($area['quantity'] ?? 1));
            $capacity[$id] = ($capacity[$id] ?? 0) + $quantity;

            if (! empty($area['ticket_type_id'])) {
                $type = (string) $area['ticket_type_id'];
                $areaTypes[$id][$type] = ($areaTypes[$id][$type] ?? 0) + $quantity;
            }
        }

        return new self($seats, $capacity, $seatTypes, $areaTypes);
    }

    /** How many people this basket is for. A standing area is its quantity, not one row. */
    public function places(): int
    {
        return count($this->seats) + array_sum($this->capacity);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->places();
    }
}
