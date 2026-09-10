<?php

namespace App\Domain\Invoicing;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issuing an invoice for an order, once.
 *
 * Numbering is the whole difficulty. A number must be unique, must not repeat, and must not be
 * handed out twice under load — so it is claimed with an insert against a unique index on
 * (site, year, sequence) and retried on collision, rather than read-then-written. That is the same
 * shape as every other counter in this system and for the same reason.
 *
 * Issued lazily, on the first request. An order that was never paid for never gets a number, which
 * is what stops a sequence full of gaps that somebody then has to explain.
 */
class InvoiceIssuer
{
    private const ATTEMPTS = 5;

    public function find(ExternalOrder $order): ?Invoice
    {
        return Invoice::where('external_order_row_id', $order->id)->first();
    }

    /** Whether this order could have one at all. */
    public function isEligible(Site $site, ExternalOrder $order): bool
    {
        return $site->offersInvoices() && 'confirmed' === $order->status;
    }

    public function issue(Site $site, ExternalOrder $order): Invoice
    {
        $existing = $this->find($order);

        if ($existing) {
            return $existing;
        }

        $order->loadMissing(['allocations', 'event', 'addonLines']);

        $payload = [
            'tenant_id' => $order->tenant_id,
            'site_id' => $site->id,
            'external_order_row_id' => $order->id,
            'issued_at' => now(),
            'currency' => $order->currency,
            'issuer' => [
                'name' => $site->legal_name ?: $site->name,
                'tax_number' => $site->tax_number,
                'address' => $site->billing_address,
                'footer' => $site->invoice_footer,
            ],
            'buyer' => $this->buyerOf($order),
            'totals' => $order->metadata['totals'] ?? [
                'tickets' => (int) $order->total_amount,
                'discount' => 0,
                'fee' => 0,
                'tax' => 0,
                'total' => (int) $order->total_amount,
                'tax_rate' => 0,
                'tax_included' => true,
            ],
            'lines' => $this->linesOf($order),
        ];

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $year = (int) now()->format('Y');
            $sequence = $this->nextSequence($site, $year);

            try {
                return DB::transaction(fn () => Invoice::create($payload + [
                    'year' => $year,
                    'sequence' => $sequence,
                    'number' => $this->format($site, $year, $sequence),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Either somebody else took this number a moment ago, or this order was invoiced
                // by a concurrent request. The second is the answer if it exists.
                $taken = $this->find($order);

                if ($taken) {
                    return $taken;
                }
            }
        }

        throw new \RuntimeException('Could not allocate an invoice number for order '.$order->id);
    }

    private function nextSequence(Site $site, int $year): int
    {
        return 1 + (int) Invoice::where('site_id', $site->id)
            ->where('year', $year)
            ->max('sequence');
    }

    /** INV-2026-0007. The prefix is the organiser's; the shape is not negotiable. */
    private function format(Site $site, int $year, int $sequence): string
    {
        $prefix = trim((string) $site->invoice_prefix) ?: 'INV';

        return sprintf('%s-%d-%04d', $prefix, $year, $sequence);
    }

    private function buyerOf(ExternalOrder $order): array
    {
        $billing = $order->metadata['billing'] ?? [];

        return [
            // The company if they gave one, and the person otherwise: an invoice made out to
            // nobody is an invoice that gets sent back.
            'name' => $billing['company'] ?? ($order->buyer['name'] ?? ''),
            'contact' => $order->buyer['name'] ?? null,
            'email' => $order->buyer['email'] ?? null,
            'tax_number' => $billing['tax_number'] ?? null,
            'address' => $billing['address'] ?? null,
        ];
    }

    /**
     * One line per kind of thing bought, not one per seat.
     *
     * An invoice for a coach party of forty is not forty lines of "Stalls A 12, €25"; it is
     * "40 × Stalls, €1,000". Grouped by what was bought and at what price, which is how the same
     * document would be written by hand.
     */
    private function linesOf(ExternalOrder $order): array
    {
        $lines = [];

        foreach ($order->allocations as $allocation) {
            if ('active' !== $allocation->status) {
                continue;
            }

            $key = implode('|', [
                $allocation->section_name,
                $allocation->ticket_type_name ?? '',
                (int) $allocation->amount,
            ]);

            $lines[$key] ??= [
                'description' => trim($allocation->section_name.
                    ($allocation->ticket_type_name ? ' · '.$allocation->ticket_type_name : '')),
                'unit_amount' => (int) $allocation->amount,
                'quantity' => 0,
                'amount' => 0,
            ];

            $quantity = $allocation->seat_id ? 1 : max(1, (int) $allocation->quantity);

            $lines[$key]['quantity'] += $quantity;
            $lines[$key]['amount'] += (int) $allocation->amount;
        }

        /*
         * The programmes and the parking, under the seats.
         *
         * On the invoice because they are part of what was sold, and an accounts department
         * reconciling a booking against a bank statement needs every line of it. A donation is
         * not here: it is not a sale, and it sits outside the fee and the tax the totals below
         * are computed from.
         */
        foreach ($order->addonLines as $addon) {
            if ($addon->refunded_at) {
                continue;
            }

            $lines[] = [
                'description' => $addon->name,
                'unit_amount' => (int) $addon->unit_price,
                'quantity' => (int) $addon->quantity,
                'amount' => (int) $addon->amount,
            ];
        }

        return array_values($lines);
    }

    /** The filename a buyer will find again in six months. */
    public static function filename(Invoice $invoice): string
    {
        // The number is already ASCII by construction, but a filename that reaches a Content-
        // Disposition header must not be able to carry a quote or a newline into it.
        return 'invoice-'.preg_replace('/[^A-Za-z0-9._-]/', '', $invoice->number).'.pdf';
    }
}
