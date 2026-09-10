<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A night that is off, or moved.
 *
 * `cancelled` was already a status an event could be set to, which stopped it selling and did
 * nothing whatever about the money already taken or the people holding tickets. These are the
 * columns that make it an event rather than a flag: when it happened, why, and — for a date that
 * moved rather than vanished — what the night used to be.
 *
 * `rescheduled_from` is kept rather than overwritten because it is the answer to the only question
 * anybody asks afterwards: "wasn't this on the Tuesday?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();
            $table->timestampTz('rescheduled_from')->nullable();
            $table->timestampTz('rescheduled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_at', 'cancellation_reason', 'rescheduled_from', 'rescheduled_at',
            ]);
        });
    }
};
