<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who was on the till, and whether it balanced at the end of the night.
 *
 * The counter has been able to take cash since it existed, and nothing has ever asked the question
 * every venue asks at eleven o'clock: *is the money in the drawer the money that should be in the
 * drawer?* Answering it needs three things this platform did not have — a shift, a way to say that
 * a sale was paid in cash rather than on a card, and somewhere to write down the movements that
 * are not sales at all.
 *
 * **A shift is a person and a drawer, between two times.** It belongs to the member of staff who
 * opened it, not to a terminal: two people sharing a laptop are two shifts, and one person moving
 * to the other end of the foyer is still one.
 *
 * **What the till should hold is counted, never stored** — the opening float, plus the cash sales
 * made during the shift, plus the movements below. The one exception is the moment it is closed:
 * `counted_cash` is a physical observation nothing could recompute, and `expected_cash` is stored
 * beside it because a reconciliation is a photograph of a moment. A refund granted the next
 * morning must not silently rewrite last night's discrepancy into agreement.
 *
 * **Money moves for reasons that are not sales.** A taxi paid for out of the drawer, a float
 * topped up mid-evening, twenty pounds put in to make change: none of them are orders, and a till
 * that ignored them would report every honest evening as short. Those get a row each — the only
 * cash movements written down here, because they are the only ones nothing else knows about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Whose drawer. Not deleted with the person: a reconciliation is a record of what
            // happened, and it has to survive somebody leaving the organisation.
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            // The night they were selling for, where the venue works that way. Null for a foyer
            // desk selling for whatever is on.
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();

            $table->string('currency', 3);
            $table->bigInteger('opening_float')->default(0);

            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();

            // Written once, at the close. See the class note for why the expectation is kept and
            // not recomputed: last night's discrepancy is last night's.
            $table->bigInteger('counted_cash')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->string('note', 300)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'opened_at']);
        });

        /*
         * One open till per person.
         *
         * In the database rather than in the application, because "open a second one" is exactly
         * what a double-clicked button does, and two open shifts would divide one evening's
         * takings between them at random. Partial, on the open ones only: a person has as many
         * closed shifts as they have worked evenings.
         *
         * `unique(user_id, closed_at)` would not do it — PostgreSQL counts two NULLs as different,
         * so a plain unique index over a nullable column permits exactly the row it is meant to
         * forbid.
         */
        DB::statement(
            'CREATE UNIQUE INDEX shifts_one_open_per_person ON shifts (user_id) WHERE closed_at IS NULL'
        );

        Schema::create('shift_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained()->cascadeOnDelete();

            // in: money put into the drawer that was not a sale. out: money taken out that was not
            // a refund. Both need a reason, because a movement without one is the thing an audit
            // asks about first.
            $table->string('kind', 8);
            $table->bigInteger('amount');
            $table->string('reason', 200);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'created_at']);
        });

        Schema::table('external_orders', function (Blueprint $table) {
            // Which till took this. Null on everything sold online, which is almost everything.
            $table->foreignUuid('shift_id')->nullable()->after('api_client_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('external_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });

        Schema::dropIfExists('shift_movements');
        Schema::dropIfExists('shifts');
    }
};
