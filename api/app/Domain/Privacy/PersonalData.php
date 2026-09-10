<?php

namespace App\Domain\Privacy;

use App\Models\ExternalOrder;
use App\Models\MessageDelivery;
use App\Models\QuestionAnswer;
use App\Models\Ticket;
use App\Models\TicketTransfer;
use App\Models\WaitingListEntry;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Everything this platform holds about one buyer, and how to take it away.
 *
 * Two obligations that pull against each other. A person may ask for a copy of what is held about
 * them, and may ask for it to be erased — and an organiser still has to be able to say what their
 * takings were last March, to their accountant and to a tax authority who will not accept "somebody
 * asked us to delete it".
 *
 * So erasure here is not deletion of the record. It is the removal of the person *from* the record:
 * the name, the address, the phone number and everything they typed go; the amounts, the dates, the
 * seats and the tickets stay, attached to nobody. The books still add up and the person is gone
 * from them.
 *
 * A buyer is an email address (see CustomerDirectory: there is no accounts table), so everything
 * below is "the orders whose buyer email is this one" and what hangs off them.
 */
class PersonalData
{
    /** What replaces a name once somebody has asked to be forgotten. */
    public const REDACTED = '[erased]';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Everything held about one person, as a structure they can be handed.
     *
     * Deliberately not a summary. The point of the exercise is that somebody can see what is held,
     * so this is the rows themselves — every order, every ticket, every message sent to them, every
     * answer they typed — rather than a tidy précis of them.
     */
    public function export(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $orders = $this->ordersOf($email);
        $orderIds = $orders->pluck('id');

        return [
            'exported_at' => now()->toIso8601String(),
            'person' => [
                'email' => $email,
                'names' => $orders->pluck('buyer.name')->filter()->unique()->values()->all(),
                'phones' => $orders->pluck('buyer.phone')->filter()->unique()->values()->all(),
            ],
            /*
             * What they were asked about being written to, and every time the answer changed.
             *
             * Part of the copy rather than a separate screen, because "when did I agree to this,
             * and what was I shown" is one of the two questions a person actually has when they
             * ask what is held about them.
             */
            'marketing' => app(Consents::class)->forEmail($email),
            'orders' => $orders->map(fn (ExternalOrder $order) => [
                'reference' => $order->external_order_id,
                'status' => $order->status,
                'placed_at' => $order->created_at?->toIso8601String(),
                'currency' => $order->currency,
                'total_amount' => (int) $order->total_amount,
                'event' => $order->event?->name,
                'starts_at' => $order->event?->starts_at?->toIso8601String(),
                'seats' => $order->allocations->map(fn ($allocation) => [
                    'seat' => trim($allocation->section_name.' '.$allocation->row_name.' '.$allocation->seat_label),
                    'ticket_type' => $allocation->ticket_type_name,
                    'amount' => (int) $allocation->amount,
                    'status' => $allocation->status,
                    'used_at' => $allocation->ticket?->used_at?->toIso8601String(),
                ])->values()->all(),
                // The arithmetic, because "why was I charged this" is the commonest reason
                // somebody asks for a copy in the first place.
                'totals' => $order->metadata['totals'] ?? null,
                'billing' => $order->metadata['billing'] ?? null,
            ])->values()->all(),
            'answers' => QuestionAnswer::whereIn('external_order_row_id', $orderIds)
                ->orderBy('created_at')
                ->get()
                ->map(fn (QuestionAnswer $answer) => [
                    'question' => $answer->label,
                    'answer' => $answer->value,
                    'at' => $answer->created_at?->toIso8601String(),
                ])->values()->all(),
            // What was sent to them, and whether it arrived. Not the body: a template rendered
            // months ago is not something this platform keeps.
            'messages' => MessageDelivery::where('recipient', $email)
                ->orderByDesc('created_at')
                ->limit(500)
                ->get()
                ->map(fn (MessageDelivery $delivery) => [
                    'kind' => $delivery->kind,
                    'channel' => $delivery->channel,
                    'status' => $delivery->status,
                    'at' => $delivery->created_at?->toIso8601String(),
                ])->values()->all(),
            'waiting_lists' => WaitingListEntry::where('email', $email)
                ->get()
                ->map(fn (WaitingListEntry $entry) => [
                    'event' => $entry->event?->name,
                    'quantity' => $entry->quantity,
                    'status' => $entry->status,
                    'joined_at' => $entry->created_at?->toIso8601String(),
                ])->values()->all(),
            'tickets_given_away' => TicketTransfer::where('from_email', $email)
                ->orWhere('to_email', $email)
                ->get()
                ->map(fn (TicketTransfer $transfer) => [
                    'direction' => $transfer->from_email === $email ? 'given' : 'received',
                    'at' => $transfer->transferred_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * Take the person out of the record and leave the record.
     *
     * Everything they typed or that identifies them goes. The amounts, the dates, the seats and the
     * tickets stay, because an organiser still has to be able to tell a tax authority what last
     * March came to — and "somebody asked us to delete it" is not an answer a tax authority takes.
     *
     * @return array<string, int> what was changed, for the audit entry and the screen
     */
    public function erase(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $orders = $this->ordersOf($email);

        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('id')->all();
        $counts = [];

        DB::transaction(function () use ($email, $orders, $orderIds, &$counts) {
            foreach ($orders as $order) {
                $metadata = $order->metadata ?? [];

                // The invoice details are the person too — a company name and address is how a
                // person is found again just as surely as their own name.
                unset($metadata['billing']);

                $order->forceFill([
                    'buyer' => [
                        'name' => self::REDACTED,
                        // Null, not a placeholder: the directory keys people by their address, and
                        // an erased buyer must fall out of it rather than become one anonymous
                        // person who bought forty times.
                        'email' => null,
                        'phone' => null,
                    ],
                    'metadata' => $metadata,
                ])->save();
            }

            $counts['orders'] = $orders->count();

            $counts['tickets'] = Ticket::whereIn(
                'allocation_id',
                DB::table('allocations')->whereIn('external_order_row_id', $orderIds)->select('id')
            )->update([
                'holder_name' => null,
                'holder_email' => null,
                'updated_at' => now(),
            ]);

            // What they typed at a checkout is theirs, and some of it — a guest's name, a car
            // registration — is somebody else's as well. It goes.
            $counts['answers'] = QuestionAnswer::whereIn('external_order_row_id', $orderIds)->delete();

            $counts['waiting_lists'] = WaitingListEntry::where('email', $email)->delete();

            $counts['transfers'] = TicketTransfer::where('from_email', $email)
                ->orWhere('to_email', $email)
                ->update(['from_email' => '', 'to_email' => '', 'from_name' => null, 'to_name' => self::REDACTED]);

            /*
             * Their answer about being written to goes entirely — log and all.
             *
             * Everything else here is redacted rather than deleted, because an organiser still has
             * to be able to show their takings. This is the exception, and it has to be: keeping
             * "this person once said no" after they have asked to be forgotten would be keeping a
             * record of them in order to honour their wish not to be on record. Somebody who comes
             * back and buys again starts as somebody nobody has asked.
             */
            app(Consents::class)->forget($email);

            // The deliveries stay as a record that something was sent — an organiser has to be
            // able to show they sent a confirmation — with the address taken off them.
            $counts['messages'] = MessageDelivery::where('recipient', $email)->update([
                'recipient' => self::REDACTED,
                'preview' => null,
                'updated_at' => now(),
            ]);
        });

        // The audit entry names no address: an audit log that recorded who was erased would be a
        // list of exactly the people who asked not to be on one.
        $this->audit->record('privacy.erased', null, $counts);

        return $counts;
    }

    /** @return \Illuminate\Support\Collection<int, ExternalOrder> */
    private function ordersOf(string $email)
    {
        return ExternalOrder::query()
            ->with(['event:id,name,starts_at', 'allocations.ticket'])
            ->whereRaw("lower(btrim(external_orders.buyer->>'email')) = ?", [$email])
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();
    }
}
