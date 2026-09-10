<?php

namespace App\Domain\Vouchers;

use App\Exceptions\ApiException;
use App\Models\ExternalOrder;
use App\Models\Voucher;
use App\Models\VoucherMovement;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Money the organiser has already taken, being spent on a booking.
 *
 * The one rule that shapes everything here: **a voucher is not a discount**. A discount changes
 * what a booking costs, and so changes the fee and the tax computed from it. A voucher changes how
 * an unchanged cost was settled — it is applied to the amount payable, after the arithmetic, and
 * the sale is still a full-price sale for every purpose an accountant cares about.
 *
 * The balance is derived, never stored, for exactly the reason a seat's availability is: a stored
 * balance is a read-modify-write, and two tabs spending the same gift voucher in the same second
 * would both read fifty and both write zero. So the balance is the sum of the movements, and the
 * sum is taken under an advisory lock keyed on the one voucher at the moment it is spent.
 */
class Vouchers
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Price a gift voucher a buyer typed against a booking.
     *
     * Nothing is written. This is the answer the checkout summary shows, and — asked again a
     * moment before the money moves — the answer the payment honours.
     *
     * @param  string  $typed     what the buyer put in the box
     * @param  string  $currency  the booking's currency; a euro voucher does not pay a rial booking
     * @param  int     $payable   what is left to pay after the discount, the fee and the tax
     */
    public function offer(string $typed, string $currency, int $payable): VoucherOffer
    {
        $code = Voucher::normalise($typed);

        if ('' === $code) {
            return VoucherOffer::refused('unknown');
        }

        $voucher = Voucher::where('kind', 'gift')->where('code', $code)->first();

        return $voucher ? $this->weigh($voucher, $currency, $payable) : VoucherOffer::refused('unknown');
    }

    /**
     * What an address has to spend, without anybody typing anything.
     *
     * Credit is named, so a signed-in buyer does not have to be told a code to spend their own
     * money — being them is the proof. Several credit notes for one address are summed into the
     * oldest live one first, which is the order that empties an expiring note before a permanent
     * one: spending the money that is about to stop existing is what the holder would choose.
     */
    public function creditFor(?string $email, string $currency, int $payable): VoucherOffer
    {
        $email = trim(mb_strtolower((string) $email));

        if ('' === $email) {
            return VoucherOffer::refused('unknown');
        }

        $vouchers = Voucher::where('kind', 'credit')
            ->where('email', $email)
            ->where('status', 'active')
            ->where('currency', $currency)
            ->orderByRaw('expires_at is null, expires_at asc')
            ->orderBy('created_at')
            ->get();

        foreach ($vouchers as $voucher) {
            $offer = $this->weigh($voucher, $currency, $payable);

            if ($offer->isAllowed()) {
                return $offer;
            }
        }

        return VoucherOffer::refused('empty');
    }

    /** The shared judgement: live, in the right money, and with something left in it. */
    private function weigh(Voucher $voucher, string $currency, int $payable): VoucherOffer
    {
        if ('void' === $voucher->status) {
            return VoucherOffer::refused('void');
        }

        if (! $voucher->isLive()) {
            return VoucherOffer::refused('expired');
        }

        // Not converted, refused. An exchange rate on a checkout is a rate somebody has to stand
        // behind on the day the voucher is redeemed, and nobody here has agreed to one.
        if ($voucher->currency !== $currency) {
            return VoucherOffer::refused('currency');
        }

        $balance = $this->balance($voucher);

        if ($balance < 1) {
            return VoucherOffer::refused('empty');
        }

        // A voucher never pays more than the booking. The rest of it stays a voucher: this is
        // money that was already paid for, and turning the change into nothing would be theft.
        return VoucherOffer::allowed($voucher, $balance, max(0, min($balance, $payable)));
    }

    /** What is left. The sum of the movements, and nothing else. */
    public function balance(Voucher $voucher): int
    {
        return (int) VoucherMovement::where('voucher_id', $voucher->id)->sum('amount');
    }

    /**
     * Take money off a voucher for a booking.
     *
     * Called inside the caller's transaction, under an advisory lock keyed on the voucher alone —
     * so a rush on one gift card never delays anybody else's checkout — and after the lock the
     * balance is read *again*, because the whole point of the lock is that the answer may have
     * changed while this request was waiting for it.
     *
     * Idempotent per booking: the partial unique index refuses a second spend for the same order,
     * so a browser that posts the checkout twice lands on the same booking having spent once.
     *
     * @return int what was actually taken, which may be less than asked when it is all that is left
     *
     * @throws ApiException when the voucher emptied while this buyer was paying
     */
    public function spend(Voucher $voucher, ExternalOrder $order, int $wanted): int
    {
        if ($wanted < 1) {
            return 0;
        }

        $already = VoucherMovement::where('voucher_id', $voucher->id)
            ->where('external_order_row_id', $order->id)
            ->where('kind', 'spend')
            ->first();

        if ($already) {
            // A replay. The money moved on the first attempt and must not move again.
            return -1 * (int) $already->amount;
        }

        $this->lock($voucher);

        $balance = $this->balance($voucher);

        if ($balance < 1) {
            throw ApiException::conflict(
                'voucher_spent',
                'That voucher has just been used up.',
            );
        }

        $taken = min($balance, $wanted);

        VoucherMovement::create([
            'tenant_id' => $voucher->tenant_id,
            'voucher_id' => $voucher->id,
            'external_order_row_id' => $order->id,
            'kind' => 'spend',
            'amount' => -1 * $taken,
            'currency' => $voucher->currency,
        ]);

        return $taken;
    }

    /**
     * Put back what a cancelled or refunded booking took.
     *
     * The money never belonged to the organiser: it was the holder's before the booking and it is
     * the holder's after it. Giving it back as card money instead would be handing somebody cash
     * for a gift card, which is a thing several countries have an opinion about.
     *
     * Idempotent: a booking refunded twice gives its voucher money back once.
     *
     * @return int what went back
     */
    public function release(ExternalOrder $order): int
    {
        $spends = VoucherMovement::where('external_order_row_id', $order->id)
            ->where('kind', 'spend')
            ->get();

        $back = 0;

        foreach ($spends as $spend) {
            $returned = (int) VoucherMovement::where('voucher_id', $spend->voucher_id)
                ->where('external_order_row_id', $order->id)
                ->where('kind', 'refund')
                ->sum('amount');

            $owed = (-1 * (int) $spend->amount) - $returned;

            if ($owed < 1) {
                continue;
            }

            VoucherMovement::create([
                'tenant_id' => $spend->tenant_id,
                'voucher_id' => $spend->voucher_id,
                'external_order_row_id' => $order->id,
                'kind' => 'refund',
                'amount' => $owed,
                'currency' => $spend->currency,
            ]);

            $back += $owed;
        }

        return $back;
    }

    /**
     * Bring a voucher into existence, worth what it says on it.
     *
     * Issuing is one write and one movement, in a transaction, because a voucher with no `issue`
     * row is a voucher worth nothing and a movement with no voucher is money from nowhere.
     *
     * Audited without exception. Issuing a gift voucher against no payment is an organiser giving
     * money away — a legitimate thing to do, and a thing somebody has to be able to find later.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function issue(array $attributes): Voucher
    {
        return DB::transaction(function () use ($attributes) {
            $voucher = Voucher::create($attributes);

            VoucherMovement::create([
                'tenant_id' => $voucher->tenant_id,
                'voucher_id' => $voucher->id,
                'kind' => 'issue',
                'amount' => (int) $voucher->amount,
                'currency' => $voucher->currency,
            ]);

            $this->audit->record('voucher.issued', $voucher, [
                'kind' => $voucher->kind,
                'amount' => (int) $voucher->amount,
                'currency' => $voucher->currency,
                // The code itself is not written to the log: an audit log has readers, and a gift
                // code is bearer money. Which voucher it was is the id, and that is enough.
                'to' => $voucher->isGift() ? $voucher->recipient : $voucher->email,
                'paid_for' => (bool) $voucher->bought_with_order_id,
            ]);

            return $voucher;
        });
    }

    /**
     * Give an address credit — the ordinary way a refund is taken as money to spend here.
     *
     * Several notes for one address rather than one running account, deliberately: each note keeps
     * why it was given and when it stops being valid, and merging them would lose both.
     */
    public function credit(
        string $tenantId,
        string $email,
        int $amount,
        string $currency,
        ?string $note = null,
        ?ExternalOrder $from = null,
        ?string $by = null,
    ): Voucher {
        return $this->issue([
            'tenant_id' => $tenantId,
            'kind' => 'credit',
            'email' => trim(mb_strtolower($email)),
            'amount' => max(0, $amount),
            'currency' => $currency,
            'note' => $note,
            'created_by' => $by,
            'bought_with_order_id' => $from?->id,
        ]);
    }

    /**
     * Stop a voucher, and write off whatever was left in it.
     *
     * Not a delete. A voucher that has paid for a booking has to keep existing, or the booking is
     * left claiming it was settled out of something that is not there — and the write-off row is
     * the one an organiser's accounts need to see the money leave.
     */
    public function void(Voucher $voucher, ?string $why = null): Voucher
    {
        DB::transaction(function () use ($voucher) {
            $this->lock($voucher);

            $balance = $this->balance($voucher);

            if ($balance > 0) {
                VoucherMovement::create([
                    'tenant_id' => $voucher->tenant_id,
                    'voucher_id' => $voucher->id,
                    'kind' => 'void',
                    'amount' => -1 * $balance,
                    'currency' => $voucher->currency,
                ]);
            }

            $voucher->forceFill(['status' => 'void'])->save();
        });

        $this->audit->record('voucher.voided', $voucher, ['reason' => $why]);

        return $voucher->refresh();
    }

    /**
     * The movements behind a balance, newest first, for the screen that has to explain it.
     *
     * @return list<array<string, mixed>>
     */
    public function history(Voucher $voucher): array
    {
        return VoucherMovement::where('voucher_id', $voucher->id)
            ->with('order:id,external_order_id,status')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (VoucherMovement $movement) => [
                'id' => $movement->id,
                'kind' => $movement->kind,
                'amount' => (int) $movement->amount,
                'at' => $movement->created_at?->toIso8601String(),
                'order_id' => $movement->order?->external_order_id,
                'order_status' => $movement->order?->status,
            ])
            ->all();
    }

    /**
     * Serialise every spender of one voucher behind the others.
     *
     * Keyed on the voucher alone: a couple sharing a gift card in two browsers is the case this
     * exists for, and it must not make anybody else's checkout wait.
     */
    private function lock(Voucher $voucher): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['voucher:'.$voucher->id],
        );
    }
}
