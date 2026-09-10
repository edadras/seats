<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer who got as far as their own name and then stopped.
 *
 * There is a version of this feature that harvests: capture the email box on the checkout as it is
 * typed, and write to anybody who abandons. This is not that. A row is only ever written here for
 * somebody who **submitted the checkout** — who gave their address in order to buy tickets, and
 * whose payment then never came back. That is a service message about their own unfinished
 * purchase, with a consent trail, and it is the only kind this table can express: there is no
 * column for an address nobody submitted, so there is no way for a later change to start keeping
 * one by accident.
 *
 * What it holds is a record of the *recovery*, not of the basket. The basket is already on the
 * order: its hold, its signed price snapshot, its seats. Copying those here would be a second copy
 * of a cart to drift from the first one, so the row points at the order and nothing else.
 *
 * The seats, of course, are long gone by the time anybody reads the message — a hold lasts minutes
 * and this is written an hour later. So `resume` does not restore a basket; it reads what was in
 * one and tries to take the same seats again, and says plainly when it cannot. A link that quietly
 * put somebody in different seats would be worse than a link that fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basket_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            // The unfinished purchase. One recovery per order, enforced below: a buyer who is
            // written to twice about the same basket is a buyer who unsubscribes.
            $table->foreignUuid('external_order_row_id')->constrained('external_orders')->cascadeOnDelete();

            // Copied from the order at the moment the recovery is opened, because the order can be
            // erased under a GDPR request and this row has to be erasable with it rather than
            // becoming the copy that survives. `basket_recoveries` is deleted by that cascade.
            $table->string('email', 190);
            $table->string('name', 160)->nullable();
            $table->string('locale', 12)->nullable();
            $table->string('currency', 3);
            $table->bigInteger('total_amount')->default(0);
            $table->unsignedSmallInteger('seats')->default(0);

            // The link in the message. Long and random: it puts seats in somebody's basket.
            $table->string('token', 64)->unique();

            $table->string('status', 14)->default('waiting'); // waiting|sent|recovered|expired|declined
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('recovered_at')->nullable();
            $table->foreignUuid('recovered_order_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_id']);
        });

        // One recovery per unfinished order. Said by the database rather than by a `firstOrCreate`,
        // because two runs of the sweeper overlapping is exactly how a buyer gets written to twice.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX basket_recoveries_one_per_order
            ON basket_recoveries (external_order_row_id)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('basket_recoveries');
    }
};
