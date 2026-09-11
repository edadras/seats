<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The states a place in the queue was always supposed to move through.
 *
 * Two of them never happened. The original design said plainly — in the migration, in the model,
 * and in the domain — that somebody whose turn ran out "stays on the list and comes round again,
 * rather than being thrown off for being asleep". Nothing did that: the notifier only ever read
 * rows marked `waiting`, so a person told once and asleep at three in the morning sat at
 * `notified` for ever and was never told again. And `converted` was a status the API would let you
 * filter by that nothing in the platform ever set, so an organiser asking who on their list
 * actually bought a seat got an empty answer every time.
 *
 * These columns are what makes those two real: how many turns somebody has had, when they bought,
 * and when the platform stopped writing to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waiting_list_entries', function (Blueprint $table) {
            /*
             * How many times this person has been told a seat was free.
             *
             * Coming round again cannot be unlimited. One person on a list, one seat free and
             * nobody buying it would otherwise be an email every two hours until the doors open,
             * which is not a waiting list, it is a nuisance. So a turn is counted, and after a few
             * unanswered ones the platform stops writing — the entry stays, and the organiser can
             * see exactly why it went quiet.
             */
            $table->unsignedSmallInteger('times_told')->default(0);

            $table->timestampTz('converted_at')->nullable();
            $table->timestampTz('lapsed_at')->nullable();
        });

        /*
         * Rows already stuck at `notified`.
         *
         * Written before this migration existed and unreachable by the notifier ever since. The
         * ones whose window has closed go back into the queue where they belong, counted as having
         * had the one turn they were given; the ones still inside their window are left alone,
         * because their turn is live and interrupting it would be the same bug in the other
         * direction.
         */
        DB::table('waiting_list_entries')
            ->where('status', 'notified')
            ->whereNotNull('claim_expires_at')
            ->where('claim_expires_at', '<=', now())
            ->update(['status' => 'waiting', 'times_told' => 1, 'claim_expires_at' => null]);

        DB::table('waiting_list_entries')
            ->where('status', 'notified')
            ->update(['times_told' => 1]);
    }

    public function down(): void
    {
        Schema::table('waiting_list_entries', function (Blueprint $table) {
            $table->dropColumn(['times_told', 'converted_at', 'lapsed_at']);
        });
    }
};
