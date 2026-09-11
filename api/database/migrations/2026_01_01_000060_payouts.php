<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A period, settled once.
 *
 * The settlement report already answered "what is this organiser owed" for any window you cared to
 * ask about. What it could not answer is "and have we paid it" — so the same month could be paid
 * twice, a fortnight could be missed between two payouts nobody lined up, and a refund in March
 * quietly changed what February appeared to have been worth long after the money left.
 *
 * A payout is the answer frozen. The figures are written down at the moment it is made and never
 * recomputed; the per-event breakdown is kept with them so the statement can be reprinted exactly
 * as it was sent. What the report says about that window afterwards is a different question with a
 * different answer, and both are true.
 *
 * The "once" is a database constraint, not a check in a controller. Two operators clicking at the
 * same moment, a retried request, a script run twice — all of them arrive as two inserts, and only
 * one of them can win. Postgres does that with an exclusion constraint over the period as a range;
 * a unique index cannot, because overlapping is not equality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Its own row per currency. An account selling in euro and in rial is owed two
            // different amounts of two different things, and one column holding their sum would
            // be a number nobody could pay.
            $table->string('currency', 3);

            // Whole days, inclusive at both ends. A payout is agreed in days — "March" — not in
            // instants, and a period that ended at 23:59:59.999 would be a period with a gap in it.
            $table->date('period_from');
            $table->date('period_to');

            // Frozen at the moment of settling. Every one of these is derivable from the orders
            // today and will be a different number tomorrow, which is exactly why they are here.
            $table->unsignedBigInteger('charged');
            $table->unsignedBigInteger('refunded');
            $table->unsignedBigInteger('kept');
            $table->unsignedBigInteger('tax_kept');
            $table->unsignedBigInteger('commission');
            // Signed, alone among them: a period where more went back out than came in leaves the
            // organiser owing the platform, which is a real outcome and not one to clamp away.
            $table->bigInteger('payable');
            $table->unsignedInteger('commission_rate');
            $table->unsignedInteger('orders')->default(0);

            // The per-event breakdown as it stood, so the statement is reprintable rather than
            // recomputable. Recomputing it is how a document changes after it was sent.
            $table->jsonb('events')->default(DB::raw("'[]'::jsonb"));

            // recorded — agreed and owed. paid — the money has left. void — undone, and the
            // period is free again.
            $table->string('status', 16)->default('recorded');
            $table->string('reference', 190)->nullable();
            $table->string('method', 40)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignUuid('voided_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'period_from']);
        });

        /*
         * The rule itself.
         *
         * One period per account per currency, and no two of them overlapping. A voided payout is
         * out of the way — that is what voiding it is for — so the constraint ignores those.
         *
         * `[]` on the range because both ends are inclusive days: 1–31 March and 1–30 April do not
         * touch, while 1–31 March and 31 March–5 April do, and the second of those is a day paid
         * twice.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE payouts ADD CONSTRAINT payouts_no_overlapping_period
            EXCLUDE USING gist (
                tenant_id WITH =,
                currency WITH =,
                daterange(period_from, period_to, '[]') WITH &&
            ) WHERE (status <> 'void')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
