<?php

namespace App\Domain\Checkin;

use App\Models\Event;
use App\Models\Ticket;

/**
 * Every ticket for one night, in a form a phone can check against with no signal at all.
 *
 * Not {@see \App\Domain\Checkin\DoorList}, which is the sheet a volunteer reads names off in the
 * panel. This is the same night seen by a machine: no names to scan down, no money, no ordering —
 * a lookup table keyed by a hash, and four possible answers.
 *
 * The scanner has always kept working offline in the sense that it did not lose anything: a scan
 * taken with no connection went into a queue and was sent later. What it could not do was *decide*.
 * Everybody was admitted and the forgeries were discovered the next morning, which at a door is the
 * same as having no check at all.
 *
 * So the device takes a copy of this before the house opens, and can then answer for itself.
 *
 * **Hashes, never codes.** What travels is `sha256` of each ticket's token — precisely what the
 * server already stores, and what it already compares a scan against. A ticket token is 160 bits of
 * randomness, so the hash cannot be walked back to a code: a scanner left in a taxi is a list of
 * names and seat numbers, which is what a printed door list has always been, and not a machine for
 * minting tickets.
 *
 * **A list is a moment, not a fact.** It carries the time it was taken, and the scanner says so on
 * screen, because the difference between "this is not a ticket" and "this was not a ticket at six
 * o'clock" is somebody who bought at seven standing outside in the rain.
 */
class ScannerDoorList
{
    /**
     * The list itself.
     *
     * Void tickets are in it rather than absent from it. A refunded ticket that simply failed to
     * appear would be read as a forgery, and the conversation at the door — "you were refunded on
     * Tuesday" — is a different one from "this is not ours".
     *
     * @return array<string, mixed>
     */
    public function for(Event $event): array
    {
        $rows = [];

        Ticket::with('allocation')
            ->where('event_id', $event->id)
            ->orderBy('id')
            ->chunk(500, function ($tickets) use (&$rows) {
                foreach ($tickets as $ticket) {
                    $rows[] = $this->row($ticket);
                }
            });

        return [
            'event_id' => $event->id,
            'name' => $event->nameFor(),
            'taken_at' => now()->toIso8601String(),
            // What the device compares against to find out whether a fresh copy is worth the
            // download. Derived from the rows themselves, so it moves when a ticket is sold,
            // refunded or scanned and stays put when nothing has happened.
            'version' => $this->version($rows),
            'count' => count($rows),
            'tickets' => $rows,
        ];
    }

    /** The same stamp the response is tagged with, without building the list to find it out. */
    public function version(array $rows): string
    {
        return substr(hash('sha256', json_encode($rows)), 0, 16);
    }

    /**
     * One ticket.
     *
     * Short keys: this is a file of several thousand rows going over a venue's wifi, and the
     * difference between `h` and `hash` across a large house is a second of somebody's evening.
     * The scanner reads it in exactly one place, which is what makes the terseness affordable.
     *
     * @return array<string, mixed>
     */
    private function row(Ticket $ticket): array
    {
        $allocation = $ticket->allocation;

        $row = [
            'h' => $ticket->token_hash,
            's' => $this->state($ticket),
        ];

        if ($ticket->holder_name) {
            $row['n'] = $ticket->holder_name;
        }

        // A general admission place has no seat, and an empty seat object on two thousand rows is
        // two thousand pairs of brackets nobody reads.
        $seat = array_values(array_filter([
            $allocation?->section_name,
            $allocation?->row_name,
            $allocation?->seat_label,
        ], fn ($part) => null !== $part && '' !== $part));

        if ($seat) {
            $row['p'] = $seat;
        }

        if ($allocation?->ticket_type_name) {
            $row['k'] = $allocation->ticket_type_name;
        }

        if ('used' === $row['s'] && $ticket->used_at) {
            $row['t'] = $ticket->used_at->toIso8601String();
        }

        return $row;
    }

    /**
     * What the door should say about this ticket.
     *
     * The same four answers `CheckinService` gives, worked out from the same two columns — so a
     * device deciding offline and a server deciding online cannot disagree about a ticket neither
     * of them has changed.
     */
    private function state(Ticket $ticket): string
    {
        if ('void' === $ticket->status) {
            return $ticket->allocation && 'released' === $ticket->allocation->status
                ? 'refunded'
                : 'cancelled';
        }

        return 'used' === $ticket->status ? 'used' : 'issued';
    }
}
