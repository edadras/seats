<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money that actually reached the bank, and what it does not explain.
 *
 * Every other money figure on this platform is worked out from the orders in this database. That is
 * the right way round — the arithmetic of a booking is frozen when it is paid and never recomputed
 * — but it means the books have never once been compared with the only number that is not ours: the
 * amount a card processor actually transferred.
 *
 * A gateway that declines, reverses, holds back a deposit or charges a fee nobody accounted for
 * leaves the ledger saying one thing and the bank another, and nothing here could notice. So a
 * payout is recorded as the gateway states it — its own reference, its own gross, fees and net —
 * with the transactions it claims to be made of, and both halves are put side by side.
 *
 * Nothing here is derived. It is *testimony*: what somebody else says they paid. That is exactly
 * why it is stored rather than computed, and why the reconciliation is a view over it rather than a
 * column on it — an answer that changes as orders are refunded must not be frozen into the row that
 * is supposed to be the unmoving half of the comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Which processor, in this platform's own key for it — so a venue taking cards through
            // two of them reconciles two sets of books rather than one muddled one.
            $table->string('gateway', 60);
            // The gateway's own name for this transfer, as it appears on the bank statement. This
            // is the handle a finance officer will search for, so it is what the row is found by.
            $table->string('reference', 160);
            $table->char('currency', 3);
            $table->date('paid_on');
            // Stated by the gateway, in minor units. `net` is not computed from the other two: a
            // payout whose own arithmetic does not add up is itself a finding worth reporting.
            $table->bigInteger('gross');
            $table->bigInteger('fees');
            $table->bigInteger('net');
            $table->string('note', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The same statement imported twice is one statement. Without this, a second upload
            // doubles a venue's income and the mistake is invisible until an accountant finds it.
            $table->unique(['tenant_id', 'gateway', 'reference']);
            $table->index(['tenant_id', 'paid_on']);
        });

        Schema::create('gateway_payout_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('gateway_payout_id')->constrained()->cascadeOnDelete();
            // What the gateway calls the payment. Joined against `metadata->payment_reference` on
            // the order, which is the handle written down when the payment began.
            $table->string('reference', 160);
            $table->string('kind', 20);
            // Signed: a refund is negative, because that is how it arrives and because a column
            // that needed a sign convention explained is a column somebody adds up wrongly.
            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->date('occurred_on')->nullable();
            $table->string('description', 300)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reference']);
            $table->index('gateway_payout_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_payout_lines');
        Schema::dropIfExists('gateway_payouts');
    }
};
