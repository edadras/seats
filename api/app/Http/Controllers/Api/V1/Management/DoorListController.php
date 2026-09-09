<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Checkin\DoorList;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Dates;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The list the door works from when the scanner does not.
 *
 * Every event eventually has the night the Wi-Fi is down, the tablet is flat, or the queue is
 * moving faster than anybody can scan. This is the answer: who is expected, where they are sitting,
 * and whether they have already come in — on a screen that can be searched, and as a file that can
 * be printed before the doors open.
 *
 * Behind `checkins.view` and nothing more. The door needs names and seats; it does not need to know
 * what the evening took, and this returns no money at all.
 */
class DoorListController extends Controller
{
    public function __construct(
        private readonly DoorList $list,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'checkins.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:in,out'],
            'entry_slot_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $rows = $this->list->query($event, $filters)
            ->paginate(min(200, (int) ($filters['per_page'] ?? 50)));

        $answers = $this->list->answersFor($event, $rows->items());

        return response()->json([
            'data' => collect($rows->items())
                ->map(fn ($row) => $this->list->present($row, $answers, $event->timezone))
                ->values(),
            'meta' => [
                'page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ] + $this->list->tally($event),
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ],
            // The windows to read the night by, on an event that has any. Empty everywhere else,
            // and the screen offers no filter rather than an empty one.
            'entry_slots' => app(\App\Domain\Events\EntrySlots::class)->forEvent($event),
        ]);
    }

    /**
     * The same list as a file.
     *
     * Not paginated: a door list that stops at fifty is a door list that turns people away. The
     * whole thing is streamed, in the order it will be read.
     */
    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize($request, 'checkins.view');

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'in:in,out'],
            'entry_slot_id' => ['nullable', 'uuid'],
        ]);

        $query = $this->list->query($event, $filters);
        $list = $this->list;

        $this->audit->record('door_list.exported', $event, [
            'event' => $event->name,
            'state' => $filters['state'] ?? 'all',
        ]);

        $headings = [
            __('panel.doorList.name'),
            __('panel.doorList.seat'),
            __('panel.doorList.ticketType'),
            __('panel.doorList.entry'),
            __('panel.doorList.reference'),
            __('panel.doorList.email'),
            __('panel.doorList.code'),
            __('panel.doorList.arrived'),
            __('panel.doorList.arrivedAt'),
            // One column for everything they were asked, rather than a column per question: the
            // questions differ per event, and a file whose shape changes with the event is a file
            // nobody can build a spreadsheet against.
            __('panel.doorList.answers'),
        ];

        $filename = 'door-list-'.preg_replace('/[^A-Za-z0-9-]+/', '-', mb_strtolower($event->name)).'.csv';

        return response()->streamDownload(function () use ($query, $list, $headings, $event) {
            $handle = fopen('php://output', 'wb');

            // The same BOM the other exports write: a spreadsheet on Windows opens Persian and
            // Arabic headings as text rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings);

            // Chunked, because a sold-out arena is twenty thousand rows and holding them all in
            // memory to write a file is how an export becomes an outage.
            $query->orderBy('tickets.id')->chunk(500, function ($rows) use ($handle, $list, $event) {
                $answers = $list->answersFor($event, $rows->all());

                foreach ($rows as $raw) {
                    $row = $list->present($raw, $answers, $event->timezone);

                    fputcsv($handle, [
                        $row['name'],
                        $row['seat'],
                        $row['ticket_type'] ?? '',
                        $row['entry'] ?? '',
                        $row['reference'],
                        $row['email'],
                        $row['code'],
                        $row['arrived'] ? __('panel.doorList.yes') : __('panel.doorList.no'),
                        $row['arrived_at'] ? Dates::shortWhen(new \DateTimeImmutable($row['arrived_at'])) : '',
                        implode('; ', array_map(
                            fn (array $answer) => $answer['label'].': '.$answer['value'],
                            $row['answers'],
                        )),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
