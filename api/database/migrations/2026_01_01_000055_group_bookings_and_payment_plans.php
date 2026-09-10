<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A party of forty, a deposit today and the balance in March.
 *
 * A school, a coach party or a company night out does not buy the way one person buys. They ask
 * for the seats now, pay a deposit to hold them, and settle the rest by a date somebody agreed on
 * the telephone. Every box office in the world runs this on a spreadsheet and a diary, and every
 * one of them has lost a night's seats to a booking nobody chased.
 *
 * Three decisions:
 *
 * **The schedule is rows, not a formula.** "Twenty per cent now, then three payments" is how the
 * conversation goes, but what has to be chased is a date and an amount, and a date somebody moved
 * because a treasurer is on holiday is a fact about that booking rather than a new formula. So a
 * plan is expanded into instalments the moment it is agreed, and each one is a row that can be
 * paid, moved, or looked at by somebody who has to ring a school in the morning.
 *
 * **The seats go now and the tickets go when it is paid for.** Those are two different promises,
 * and running them together is how a party turns up with forty codes they never paid for. An
 * allocation is written at the deposit — the chairs are gone, nobody else can have them — and the
 * ticket, which is the thing that gets somebody through a door, is minted with the last payment.
 *
 * **What is owed is derived.** The balance is the unpaid instalments and nothing else; whether a
 * booking is overdue is a comparison against the clock at the moment somebody asks. There is no
 * column to go stale and no job that has to run at midnight for a plan to become late.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            /*
             * Who the party is, as against who signed for it.
             *
             * The buyer on a school booking is a teacher, and the useful line on a door list is
             * the school. Stored beside the buyer rather than instead of them: somebody still has
             * to be rung when the coach is late.
             */
            $table->string('group_name', 160)->nullable();
        });

        Schema::create('order_instalments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();

            // 0 is the deposit, taken at the counter as the booking is made. The rest follow it.
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 20)->default('instalment');

            // Minor units of the order's currency, like every other amount on the platform.
            $table->unsignedInteger('amount');
            $table->date('due_on');

            $table->timestampTz('paid_at')->nullable();
            $table->string('method', 20)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestampsTz();

            // One row per step of one plan: a payment recorded twice by two clerks on the
            // telephone must be one payment, and the database is the only thing that can promise
            // it while both are still in flight.
            $table->unique(['external_order_row_id', 'sequence']);

            // The chase list: what is unpaid, oldest first, across every booking.
            $table->index(['tenant_id', 'paid_at', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_instalments');

        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropColumn('group_name');
        });
    }
};
