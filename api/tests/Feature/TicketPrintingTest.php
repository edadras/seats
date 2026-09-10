<?php

namespace Tests\Feature;

use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Domain\Printing\TicketReceipts;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\Ticket;
use App\Support\Printing\EscPos;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A ticket in somebody's hand, a second after the card clears.
 *
 * Two claims. **Printing re-mints the code**, because a stored ticket cannot reproduce its QR — the
 * database keeps a hash and nothing recovers the code from it — so the only honest answer is a new
 * one, which necessarily stops any earlier copy working. At a counter that is exactly right: a
 * reprint is the live ticket and the one somebody claims to have lost is not.
 *
 * And **the two ways out say the same thing**: the roll printer's bytes and the browser's print
 * view are built from one list of lines, so they cannot disagree about which seat somebody has.
 */
class TicketPrintingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private int $taken = 0;

    /** @return array{order: \App\Models\ExternalOrder, night: array} */
    private function sale(array $night, int $seats = 2): array
    {
        $ids = $night['seats']->slice($this->taken, $seats)->pluck('id')->all();
        $this->taken += $seats;

        $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
        $client = $this->makeApiClient($night['tenant'])['client'];
        $reference = 'ORD-'.strtoupper(uniqid());

        [$order] = app(OrderService::class)->register($client, $reference, $hold->token, [
            'name' => 'Sam Buyer', 'email' => 'sam@example.test',
        ]);

        app(OrderService::class)->confirm($order->fresh());

        return ['order' => $order->fresh(), 'night' => $night];
    }

    #[Test]
    public function a_ticket_prints_what_somebody_is_holding_the_paper_for(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 3500);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night, 1)['order'];
            $allocation = Allocation::where('external_order_row_id', $order->id)->firstOrFail();

            $receipt = app(TicketReceipts::class)->forAllocation($allocation);
            $kinds = array_column($receipt['lines'], 'kind');

            $this->assertContains('seat', $kinds, 'the seat is the line worth shouting');
            $this->assertContains('qr', $kinds, 'and the code the door scans');

            $seat = collect($receipt['lines'])->firstWhere('kind', 'seat')['text'];

            $this->assertStringContainsString($allocation->seat_label, $seat);
            $this->assertStringContainsString($order->external_order_id,
                json_encode($receipt['lines'], JSON_UNESCAPED_UNICODE));
        });
    }

    #[Test]
    public function printing_re_mints_the_code_so_the_reprint_is_the_live_one(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night, 1)['order'];
            $allocation = Allocation::where('external_order_row_id', $order->id)->firstOrFail();

            $first = app(TicketReceipts::class)->forAllocation($allocation);
            $second = app(TicketReceipts::class)->forAllocation($allocation->fresh());

            $this->assertNotSame($first['token'], $second['token']);

            // Only the second one opens the door: two live codes for one chair is two people in it.
            $ticket = Ticket::where('allocation_id', $allocation->id)->firstOrFail();

            $this->assertSame(Ticket::hashToken($second['token']), $ticket->token_hash);
            $this->assertNotSame(Ticket::hashToken($first['token']), $ticket->token_hash);
        });
    }

    #[Test]
    public function a_seat_with_nothing_live_on_it_cannot_be_printed(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night, 1)['order'];
            $allocation = Allocation::where('external_order_row_id', $order->id)->firstOrFail();

            app(OrderService::class)->refund($order);

            $this->expectException(ApiException::class);

            app(TicketReceipts::class)->forAllocation($allocation->fresh());
        });
    }

    #[Test]
    public function a_whole_booking_prints_one_ticket_per_seat_with_a_cut_between(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night, 2)['order'];

            $receipts = app(TicketReceipts::class)->forOrder($order);

            $this->assertCount(2, $receipts);
            $this->assertNotSame($receipts[0]['token'], $receipts[1]['token']);

            $bytes = app(TicketReceipts::class)->escpos($receipts);

            // Two seats on one strip of paper is two people sharing a ticket at the door.
            $this->assertSame(2, substr_count($bytes, "\x1D\x56\x01"));
        });
    }

    #[Test]
    public function the_bytes_are_something_a_roll_printer_understands(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night, 1)['order'];
            $receipts = app(TicketReceipts::class)->forOrder($order);
            $bytes = app(TicketReceipts::class)->escpos($receipts, width: 58);

            $this->assertStringStartsWith("\x1B\x40", $bytes, 'it wakes the printer first');
            // Model, module size, error correction, the data, and the instruction to print it.
            $this->assertStringContainsString("\x1D\x28\x6B\x04\x00\x31\x41\x32\x00", $bytes);
            $this->assertStringContainsString("\x1D\x28\x6B\x03\x00\x31\x51\x30", $bytes);
            $this->assertStringContainsString($receipts[0]['token'], $bytes, 'the code itself is in it');
        });
    }

    #[Test]
    public function a_roll_that_cannot_carry_a_script_drops_it_rather_than_printing_nonsense(): void
    {
        $printer = (new EscPos(32))->start()->line('Grand Amphithéâtre')->line('گرند آمفی‌تئاتر');
        $bytes = $printer->bytes();

        // The accented Latin survives; the Persian does not, because no instruction can put a glyph
        // into hardware that has none — and a row of question marks reads as a broken printer.
        $this->assertStringContainsString(iconv('UTF-8', 'CP1252', 'Grand Amphithéâtre'), $bytes);
        $this->assertStringNotContainsString('?', $bytes);
    }

    #[Test]
    public function the_two_widths_lay_a_line_out_differently(): void
    {
        $narrow = (new EscPos(32))->columnsPair('Total', '35.00')->bytes();
        $wide = (new EscPos(48))->columnsPair('Total', '35.00')->bytes();

        $this->assertSame(33, strlen($narrow), '32 columns and a newline');
        $this->assertSame(49, strlen($wide));
        $this->assertStringEndsWith("35.00\n", $narrow);
        // The value wins when the two will not fit: "Seat" matters less than "F12".
        $this->assertStringEndsWith("F12\n", (new EscPos(4))->columnsPair('Seat', 'F12')->bytes());
    }

    #[Test]
    public function the_counter_can_print_and_a_reader_cannot(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $order = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => $this->sale($night, 1)['order'],
        );

        $seller = $this->makeUser($night['tenant'], 'box_office');
        $reader = $this->makeUser($night['tenant'], 'viewer');

        $this->asMember($seller)
            ->getJson("/v1/orders/{$order->id}/receipts")
            ->assertOk()
            ->assertJsonPath('width', 80)
            ->assertJsonCount(1, 'data');

        // Printing re-mints the code, so it is not something a person who may only look at a
        // booking should be able to do by accident.
        $this->asMember($reader)
            ->getJson("/v1/orders/{$order->id}/receipts")
            ->assertForbidden();
    }

    #[Test]
    public function the_bytes_are_asked_for_rather_than_handed_over(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $order = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => $this->sale($night, 1)['order'],
        );

        $headers = ['Authorization' => 'Bearer '.$this->makeUser($night['tenant'])->createToken('t')->plainTextToken];

        $stream = $this->withHeaders($headers)
            ->get("/v1/orders/{$order->id}/receipts?format=escpos&width=58&cut=0");

        $stream->assertOk();
        $stream->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringStartsWith("\x1B\x40", $stream->getContent());
        // Asked not to cut, so it feeds instead — a printer without a cutter jams on the command.
        $this->assertStringNotContainsString("\x1D\x56\x01", $stream->getContent());
    }
}
