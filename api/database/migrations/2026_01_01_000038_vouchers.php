<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money this account owes somebody, and the record of every penny of it moving.
 *
 * A voucher is **not a discount code**, and confusing the two is the mistake this table exists to
 * make impossible. A discount changes what a booking costs; a voucher changes how it was paid for.
 * That difference is not philosophical — it decides the VAT. Half price on a ticket means half the
 * tax; a ticket paid for with a voucher is a full-price sale that was settled out of money the
 * organiser had already taken, and taxing it as though it were half price would understate what
 * they owe. So a voucher is applied last, to the amount payable, after the fee and the tax have
 * been worked out from the goods.
 *
 * Two ways of proving you may spend it, one kind of money:
 *
 *   a **gift voucher** is bearer. It has a code, somebody bought it, and whoever holds the code
 *   spends it. Issuing one without payment is issuing money, which is why issuing is audited.
 *   **account credit** is named. It has no code and belongs to an email address — usually because
 *   a buyer took a refund as credit rather than back to a card.
 *
 * They are one table because the money and its arithmetic are identical; only the question "may
 * this person spend it" differs, and that is one column.
 *
 * The balance is never stored. It is the sum of the movements, exactly as a seat's availability is
 * derived rather than kept: a stored balance is a read-modify-write, and two browsers spending the
 * same voucher in the same second would both succeed against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * `gift` carries a code and is bearer. `credit` carries an email and is not.
             *
             * Exactly one of the two identifying columns is filled, enforced below rather than
             * trusted: a credit note with a code would be bearer money somebody was given.
             */
            $table->string('kind', 10);
            $table->string('code', 40)->nullable();
            $table->string('email', 190)->nullable();

            $table->unsignedBigInteger('amount');           // what it was worth when issued
            $table->string('currency', 3);
            $table->string('note', 200)->nullable();        // "Refund for 12 March", "Raffle prize"
            // Who it was bought for, where somebody bought it as a present. Never used to decide
            // whether a code may be spent — a gift voucher is bearer, and a name on it is a name.
            $table->string('recipient', 160)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('status', 10)->default('active'); // active|void
            $table->uuid('created_by')->nullable();
            // The booking that paid for it, where one did. A voucher issued against no payment is
            // an organiser giving money away, which is a legitimate thing to do and a thing to be
            // able to find afterwards.
            $table->foreignUuid('bought_with_order_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'status']);
        });

        // A gift has a code and no owner; a credit has an owner and no code. Stated by the
        // database, because "which of these two columns is set" is exactly the invariant an
        // application forgets under a deadline.
        DB::statement(<<<'SQL'
            ALTER TABLE vouchers ADD CONSTRAINT vouchers_code_xor_email CHECK (
                (kind = 'gift' AND code IS NOT NULL AND email IS NULL)
                OR (kind = 'credit' AND email IS NOT NULL AND code IS NULL)
            )
        SQL);

        /*
         * Every movement of the money, signed.
         *
         * `issue` is positive and happens once. `spend` is negative. `refund` is positive and puts
         * back what a cancelled booking took. The balance is their sum, and nothing else.
         */
        Schema::create('voucher_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('external_order_row_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->string('kind', 10);                     // issue|spend|refund|void
            $table->bigInteger('amount');                   // signed: minus is money leaving
            $table->string('currency', 3);
            $table->timestamps();

            $table->index(['tenant_id', 'voucher_id']);
            $table->index(['voucher_id', 'kind']);
        });

        /*
         * One spend per voucher per booking.
         *
         * The idempotency that makes a retried submit safe: a browser that posts the checkout
         * twice must land on the same booking having spent the voucher once, and the index says
         * so rather than the application remembering to.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX voucher_movements_one_spend_per_order
            ON voucher_movements (voucher_id, external_order_row_id)
            WHERE kind = 'spend' AND external_order_row_id IS NOT NULL
        SQL);

        Schema::table('external_orders', function (Blueprint $table) {
            // How much of this booking was settled out of a voucher rather than charged. Its own
            // column, outside the totals the fee and the tax were computed from, because it is a
            // way of paying and not a change to what was sold.
            $table->unsignedBigInteger('voucher_amount')->default(0)->after('donation');
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropColumn('voucher_amount');
        });

        Schema::dropIfExists('voucher_movements');
        Schema::dropIfExists('vouchers');
    }
};
