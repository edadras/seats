<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may buy, and when — as against a discount code, which is about what they pay.
 *
 * The two look alike enough that putting them in one table is tempting, and they must not be. A
 * leaked discount costs an organiser money they can calculate; a leaked presale code costs the
 * presale its entire purpose, and no amount of money puts that back. They are also used at
 * different moments: a discount is applied when an order is priced, an access code is spent when a
 * seat is taken out of inventory, because a presale that only checked at the till would be a race
 * anybody could join and only lose at the end. A buyer may hold both at once.
 *
 * The sale window lives on the event, in three instants rather than a status:
 *
 *   before `presale_starts_at`   nobody, however many codes they have;
 *   then until `on_sale_at`      only somebody holding a code that opens this event;
 *   after `on_sale_at`           everybody.
 *
 * Leaving both null is what every event that has never heard of a presale does, and it means "on
 * sale as soon as it is published", which is what those events did before this migration existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // An instant, not a status: a status has to be flipped by somebody at midnight, and
            // nobody is awake at midnight.
            $table->timestampTz('presale_starts_at')->nullable()->after('refund_keeps_fee');
            $table->timestampTz('on_sale_at')->nullable()->after('presale_starts_at');
        });

        Schema::create('access_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            // Null means every event this account sells — a members' code for the whole season.
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();
            // Upper-cased and trimmed on the way in, like a discount code, so " Members " and
            // "members" are one code and not three near-misses somebody has to explain.
            $table->string('code', 40);
            $table->string('label', 160)->nullable();

            /*
             * What holding this opens.
             *
             *   presale  buy during the presale window, before general sale
             *   always   buy at any time, even before the presale opens — the code an organiser
             *            gives their own staff, or a promoter who needs to place a party tonight
             *
             * Ticket types are separate and additive: a code may unlock a members' price without
             * unlocking a presale, and the other way round.
             */
            $table->string('opens', 10)->default('presale');
            $table->jsonb('ticket_type_ids')->nullable();

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // Null: as often as anybody likes. This is the count of *live* uses, not of presses.
            $table->unsignedInteger('max_uses')->nullable();
            // How many seats one use may take. A code emailed to a mailing list that let one
            // person take the front row is a code that did the opposite of what it was for.
            $table->unsignedSmallInteger('max_seats')->nullable();
            $table->string('status', 10)->default('active');   // active|paused
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_id']);
        });

        /*
         * One row per time a code opened a door, and the door it opened.
         *
         * Not a counter on the code: a cap is a sum against a limit, and a read-modify-write
         * oversells under two buyers in the same second. It is recomputed under an advisory lock,
         * exactly as a standing area's capacity and a timed-entry window's are.
         *
         * A use is spent on a hold, and a hold expires. `released_at` is what gives it back — the
         * alternative is a mailing list of a hundred people where the first ninety-nine who opened
         * a basket and wandered off have used the code up.
         */
        Schema::create('access_code_uses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('access_code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('hold_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('external_order_row_id')->nullable()
                ->constrained('external_orders')->nullOnDelete();
            $table->unsignedSmallInteger('seats')->default(0);
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'access_code_id']);
            $table->index(['access_code_id', 'released_at']);
        });

        // One live use per hold. A retried POST must not spend the code twice, and the index says
        // so rather than the application remembering to.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX access_code_uses_one_per_hold
            ON access_code_uses (hold_id)
            WHERE hold_id IS NOT NULL AND released_at IS NULL
        SQL);

        Schema::table('holds', function (Blueprint $table) {
            $table->foreignUuid('access_code_id')->nullable()->after('entry_slot_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_code_id');
        });

        Schema::dropIfExists('access_code_uses');
        Schema::dropIfExists('access_codes');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['presale_starts_at', 'on_sale_at']);
        });
    }
};
