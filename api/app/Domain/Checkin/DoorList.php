<?php

namespace App\Domain\Checkin;

use App\Models\Event;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Everybody expected tonight, in one list.
 *
 * The scanner is the normal way in and this is the answer to the night it does not work: a
 * printable, searchable list of who is coming, where they are sitting and whether they have
 * already been through the door.
 *
 * One row per ticket rather than per booking. A family of four arrives one at a time, and a list
 * that could only tick off whole bookings would have somebody standing at the door doing arithmetic
 * while a queue formed behind them.
 */
class DoorList
{
    /** Built once and reused by the screen, the CSV and the printed sheet, so the three agree. */
    public function query(Event $event, array $filters = []): Builder
    {
        $query = DB::table('tickets')
            ->join('allocations', 'allocations.id', '=', 'tickets.allocation_id')
            ->join('external_orders', 'external_orders.id', '=', 'allocations.external_order_row_id')
            ->where('tickets.event_id', $event->id)
            // The event was resolved through a tenant-scoped binding, so this is belt as well as
            // braces — but these are raw queries, outside the global scope that normally makes
            // cross-tenant reads impossible, and failing closed is cheaper than being clever.
            ->where('tickets.tenant_id', $event->tenant_id)
            // A voided ticket belongs to a refund, not to tonight. Somebody holding one at the
            // door is holding a ticket that was cancelled, and the scanner says so.
            ->whereIn('tickets.status', ['issued', 'used'])
            ->select([
                'tickets.id',
                'allocations.id as allocation_id',
                'tickets.status',
                'tickets.used_at',
                'tickets.token_prefix',
                'tickets.holder_name',
                'allocations.section_name',
                'allocations.row_name',
                'allocations.seat_label',
                'allocations.seat_id',
                'allocations.quantity',
                'allocations.ticket_type_name',
                'allocations.entry_starts_at',
                'allocations.entry_ends_at',
                'external_orders.external_order_id as reference',
                DB::raw("coalesce(external_orders.buyer->>'name', '') as buyer_name"),
                DB::raw("coalesce(external_orders.buyer->>'email', '') as buyer_email"),
            ]);

        if (($filters['q'] ?? '') !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($filters['q']))).'%';

            $query->where(function ($where) use ($like) {
                $where->whereRaw("lower(coalesce(external_orders.buyer->>'name', '')) like ?", [$like])
                    ->orWhereRaw("lower(coalesce(external_orders.buyer->>'email', '')) like ?", [$like])
                    ->orWhereRaw('lower(external_orders.external_order_id) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(tickets.holder_name, \'\')) like ?', [$like])
                    // "Where is row F sitting" is a question asked at a door as often as a name is.
                    ->orWhereRaw(
                        "lower(trim(allocations.section_name || ' ' || allocations.row_name || ' ' || allocations.seat_label)) like ?",
                        [$like]
                    );
            });
        }

        if ('in' === ($filters['state'] ?? null)) {
            $query->where('tickets.status', 'used');
        }

        if ('out' === ($filters['state'] ?? null)) {
            $query->where('tickets.status', 'issued');
        }

        if (($filters['entry_slot_id'] ?? '') !== '') {
            $query->where('allocations.entry_slot_id', $filters['entry_slot_id']);
        }

        return $query
            // On a timed-entry event the door reads by arrival window before anything else: the
            // people coming at ten are one list and the people coming at eleven are another.
            ->orderByRaw('allocations.entry_starts_at ASC NULLS FIRST')
            ->orderBy('external_orders.external_order_id')
            ->orderBy('allocations.section_name')
            ->orderBy('allocations.row_name')
            ->orderByRaw("LPAD(allocations.seat_label, 12, '0')");
    }

    /** @return array{expected: int, arrived: int} */
    public function tally(Event $event): array
    {
        $rows = DB::table('tickets')
            ->where('event_id', $event->id)
            ->where('tenant_id', $event->tenant_id)
            ->whereIn('status', ['issued', 'used'])
            ->selectRaw("count(*) as expected, count(*) filter (where status = 'used') as arrived")
            ->first();

        return [
            'expected' => (int) ($rows->expected ?? 0),
            'arrived' => (int) ($rows->arrived ?? 0),
        ];
    }

    /**
     * What each person on tonight's list was asked, and said.
     *
     * Fetched for a page of rows at once rather than per row: a door list of twenty thousand with
     * a query per row is a door list that does not load.
     *
     * @param  list<object>  $rows
     * @return array<string, list<array{label: string, value: string}>> allocation id => answers
     */
    public function answersFor(Event $event, array $rows): array
    {
        $allocationIds = array_values(array_filter(array_map(
            fn (object $row) => $row->allocation_id ?? null,
            $rows,
        )));

        if ($allocationIds === []) {
            return [];
        }

        $answers = [];

        $found = DB::table('question_answers')
            ->where('event_id', $event->id)
            ->whereIn('allocation_id', $allocationIds)
            ->orderBy('created_at')
            ->get(['allocation_id', 'label', 'value']);

        foreach ($found as $row) {
            $answers[$row->allocation_id][] = ['label' => $row->label, 'value' => (string) $row->value];
        }

        return $answers;
    }

    /**
     * One row, shaped the same for every surface that shows it.
     *
     * @param  ?string  $timezone  the venue's own clock, which is what an arrival window is
     *                             written in — a door list that spelled it in UTC would send
     *                             somebody to a door an hour early
     */
    public function present(object $row, array $answers = [], ?string $timezone = null): array
    {
        return [
            'id' => $row->id,
            // The name on the ticket if it was transferred or bought for somebody else; the
            // buyer's otherwise. A door asks for a name and does not care which of the two it is.
            'name' => $row->holder_name ?: $row->buyer_name,
            'buyer_name' => $row->buyer_name,
            'email' => $row->buyer_email,
            'reference' => $row->reference,
            'seat' => $row->seat_id
                ? trim($row->section_name.' '.$row->row_name.' '.$row->seat_label)
                : trim($row->section_name),
            'quantity' => $row->seat_id ? 1 : max(1, (int) $row->quantity),
            'ticket_type' => $row->ticket_type_name,
            // When this person was told to arrive, on a timed-entry event. Null everywhere else,
            // and the door shows nothing rather than an empty column.
            'entry_starts_at' => $row->entry_starts_at,
            'entry_ends_at' => $row->entry_ends_at,
            // The bare clock, not the sentence: this sits under a column already headed "Entry".
            'entry' => $row->entry_starts_at
                ? \App\Domain\Events\EntrySlots::range(
                    new \DateTimeImmutable((string) $row->entry_starts_at),
                    $row->entry_ends_at ? new \DateTimeImmutable((string) $row->entry_ends_at) : null,
                    $timezone,
                )
                : null,
            'code' => $row->token_prefix,
            'arrived' => 'used' === $row->status,
            'arrived_at' => $row->used_at,
            // What this person was asked at checkout — a dietary requirement, a car registration,
            // the name of the guest this seat is for. The door is where those get acted on.
            'answers' => $answers[$row->allocation_id ?? ''] ?? [],
        ];
    }
}
