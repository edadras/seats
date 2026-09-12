<?php

namespace App\Domain\Tickets;

use App\Domain\Sites\Themes;
use App\Models\Event;
use App\Models\TicketDesign;

/**
 * Reading and writing a night's ticket design.
 *
 * The whole of the validation lives here rather than in a form request, because the same rules have
 * to hold for a design that arrives from the panel and for one copied from last year's night — and
 * because what it is protecting against is not a malicious organiser so much as an impossible
 * document: a field at 140% of the page, a colour that is a `javascript:` URL, a key nobody prints.
 *
 * Everything is clamped rather than refused. A form that says "that is invalid, try again" for a
 * field somebody dragged two pixels past the edge is a form that makes people give up on the
 * feature; a field pulled back to the edge is what they meant anyway.
 */
class TicketDesigns
{
    /** Points. Below six nothing is readable; above seventy-two nothing fits. */
    private const MIN_SIZE = 6.0;

    private const MAX_SIZE = 72.0;

    public function for(Event $event): ?TicketDesign
    {
        return TicketDesign::where('event_id', $event->id)->first();
    }

    /**
     * Store what the organiser laid out.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(Event $event, array $input): TicketDesign
    {
        $design = TicketDesign::firstOrNew(['event_id' => $event->id]);

        $design->background_url = Themes::url($input['background_url'] ?? null);
        $design->page_size = isset(TicketDesign::PAGES[$input['page_size'] ?? ''])
            ? $input['page_size']
            : 'A5';
        $design->orientation = 'portrait' === ($input['orientation'] ?? '') ? 'portrait' : 'landscape';
        $design->fields = $this->fields($input['fields'] ?? []);
        $design->save();

        return $design;
    }

    public function forget(Event $event): void
    {
        TicketDesign::where('event_id', $event->id)->delete();
    }

    /**
     * The fields, cleaned.
     *
     * @param  mixed  $input
     * @return list<array<string, mixed>>
     */
    public function fields($input): array
    {
        $fields = [];
        $seen = [];

        foreach ((array) $input as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = (string) ($field['key'] ?? '');

            // Unknown keys are dropped rather than printed. A field nobody declared would set its
            // own name on a document somebody hands over at a door.
            if (! TicketFields::has($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $fields[] = [
                'key' => $key,
                'x' => $this->percent($field['x'] ?? 0),
                'y' => $this->percent($field['y'] ?? 0),
                // A width of nought is a field nobody can read; a width past the edge is one the
                // page clips. Both are somebody's slip rather than their intention.
                'width' => max(4.0, $this->percent($field['width'] ?? 40)),
                'size' => $this->clamp((float) ($field['size'] ?? 11), self::MIN_SIZE, self::MAX_SIZE),
                'weight' => 'bold' === ($field['weight'] ?? '') ? 'bold' : 'normal',
                'align' => in_array($field['align'] ?? '', ['start', 'center', 'end'], true)
                    ? $field['align']
                    : 'start',
                'colour' => Themes::colour($field['colour'] ?? null) ?? '#111111',
            ];
        }

        return $fields;
    }

    /**
     * A layout for somebody who has just uploaded a picture and does not want to start from an
     * empty page.
     *
     * Down the left with the QR at the right, which is where a ticket's barcode has been since
     * tickets had barcodes — and readable on a light background, which is what a poster usually is.
     *
     * @return list<array<string, mixed>>
     */
    public function starter(): array
    {
        return $this->fields([
            ['key' => 'event', 'x' => 6, 'y' => 10, 'width' => 58, 'size' => 20, 'weight' => 'bold'],
            ['key' => 'when', 'x' => 6, 'y' => 26, 'width' => 58, 'size' => 11],
            ['key' => 'venue', 'x' => 6, 'y' => 36, 'width' => 58, 'size' => 11],
            ['key' => 'seat', 'x' => 6, 'y' => 52, 'width' => 58, 'size' => 16, 'weight' => 'bold'],
            ['key' => 'holder', 'x' => 6, 'y' => 66, 'width' => 58, 'size' => 11],
            ['key' => 'reference', 'x' => 6, 'y' => 84, 'width' => 58, 'size' => 9],
            ['key' => 'qr', 'x' => 70, 'y' => 18, 'width' => 24],
            ['key' => 'code', 'x' => 68, 'y' => 70, 'width' => 28, 'size' => 8, 'align' => 'center'],
        ]);
    }

    private function percent($value): float
    {
        return $this->clamp((float) $value, 0.0, 100.0);
    }

    private function clamp(float $value, float $low, float $high): float
    {
        return round(max($low, min($high, $value)), 2);
    }
}
