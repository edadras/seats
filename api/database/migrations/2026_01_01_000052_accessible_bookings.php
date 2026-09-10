<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chairs a wheelchair user and whoever comes with them actually need.
 *
 * A wheelchair space has been a seat with a flag on it since the map model was built, which is
 * enough to draw it and nothing like enough to sell it. Three things were missing, and every venue
 * on this platform has been working around all three by hand.
 *
 * **A space is useless without the seat beside it.** Somebody in a wheelchair arrives with a
 * companion, and if that chair has been sold to a stranger the booking is worthless. Venues solve
 * this by blocking the neighbouring seat and unblocking it by hand, which fails the week somebody
 * is on holiday. So a seat can be marked as a companion seat, and the rule is enforced where the
 * seats are taken rather than remembered by a person: a companion seat is not sold on its own.
 *
 * **They are usually held back, and then let go.** Most houses keep their accessible seats off
 * general sale so that the people who need them are not racing a bot for them, and release them
 * near the date. That is a deadline, so — like every other deadline here — it is *derived*: the
 * seats become public because the hour arrived, not because a job ran. Until then the box office
 * can still sell them to anybody who rings up, which is the whole point of holding them.
 *
 * **What somebody needs has to reach the door.** A hearing loop, a guide dog, step-free access:
 * asked once at the checkout of an event that asks, written on the booking, and printed on the
 * door list where the person on the door will actually read it. It is not marketing data and does
 * not go near a segment; it leaves with the buyer when they ask to be forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seats', function (Blueprint $table) {
            // A column rather than a JSON attribute because availability filters on it for every
            // seat in the house on every read, and an index cannot help a key inside a document.
            $table->boolean('companion')->default(false)->after('accessible');
        });

        Schema::table('events', function (Blueprint $table) {
            // The same three words the refunds and exchanges use, because an organiser who has
            // learned one vocabulary should not have to learn a second: always on sale, on sale
            // only from a number of hours before, or only ever over the counter.
            $table->string('accessible_sale', 16)->default('always');
            $table->unsignedSmallInteger('accessible_release_hours')->default(0);
            $table->boolean('ask_access_needs')->default(false);
        });

        Schema::table('external_orders', function (Blueprint $table) {
            $table->text('access_needs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seats', fn (Blueprint $table) => $table->dropColumn('companion'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(
            ['accessible_sale', 'accessible_release_hours', 'ask_access_needs']
        ));
        Schema::table('external_orders', fn (Blueprint $table) => $table->dropColumn('access_needs'));
    }
};
