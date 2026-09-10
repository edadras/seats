<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Printing\TicketReceipts;
use App\Http\Controllers\Controller;
use App\Models\Allocation;
use App\Models\ExternalOrder;
use App\Support\Audit\AuditLogger;
use App\Support\Qr\QrRenderer;
use Illuminate\Http\Request;

/**
 * Tickets, as a roll printer wants them.
 *
 * Behind `orders.sell` rather than `tickets.view`: printing re-mints the code, which stops any
 * earlier copy working. That is the right behaviour at a counter and the wrong thing for somebody
 * who may only look at a booking to do by accident.
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly TicketReceipts $receipts,
        private readonly AuditLogger $audit,
    ) {}

    public function forOrder(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.sell');

        $data = $this->options($request);
        $receipts = $this->receipts->forOrder($order);

        $this->audit->record('order.printed', $order, [
            'reference' => $order->external_order_id,
            'tickets' => count($receipts),
        ]);

        return $this->answer($request, $receipts, $data, $order->external_order_id);
    }

    public function forSeat(Request $request, Allocation $allocation)
    {
        $this->authorize($request, 'orders.sell');

        $data = $this->options($request);
        $receipt = $this->receipts->forAllocation($allocation);

        $this->audit->record('ticket.printed', $allocation, [
            'seat' => trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label),
        ]);

        return $this->answer($request, [$receipt], $data, (string) $allocation->id);
    }

    /** @param  list<array{lines: list<array<string, string>>, token: string}>  $receipts */
    private function answer(Request $request, array $receipts, array $options, string $name)
    {
        /*
         * JSON is the default and the bytes are asked for.
         *
         * The panel draws its own print view from the lines — it has to, because a browser can lay
         * out Persian and a printer's code page cannot — and only a local print agent wants the
         * stream. Handing back bytes by default would mean the commoner caller parsing a binary
         * blob it has no use for.
         */
        if ('escpos' !== ($options['format'] ?? 'json')) {
            return response()->json([
                'width' => $options['width'],
                'data' => array_map(
                    fn ($receipt) => ['lines' => array_map($this->drawn(...), $receipt['lines'])],
                    $receipts
                ),
            ]);
        }

        $bytes = $this->receipts->escpos($receipts, $options['width'], $options['cut']);

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '', $name).'.bin"',
        ]);
    }

    /**
     * A line, with the QR drawn for anything that cannot draw one itself.
     *
     * The browser is one of those: the panel prints through the operating system's own driver
     * precisely because a roll printer's code page cannot set Persian, and a page that has to
     * fetch a second endpoint per ticket would print before the codes arrived.
     *
     * @param  array<string, string>  $line
     * @return array<string, string>
     */
    private function drawn(array $line): array
    {
        if ('qr' !== ($line['kind'] ?? '')) {
            return $line;
        }

        return $line + ['image' => app(QrRenderer::class)->dataUri($line['text'] ?? '', 240)];
    }

    /** @return array{format: string, width: int, cut: bool} */
    private function options(Request $request): array
    {
        $data = $request->validate([
            'format' => ['sometimes', 'in:json,escpos'],
            // The two roll widths anybody actually buys.
            'width' => ['sometimes', 'integer', 'in:58,80'],
            'cut' => ['sometimes', 'boolean'],
        ]);

        return [
            'format' => $data['format'] ?? 'json',
            'width' => (int) ($data['width'] ?? 80),
            'cut' => (bool) ($data['cut'] ?? true),
        ];
    }
}
