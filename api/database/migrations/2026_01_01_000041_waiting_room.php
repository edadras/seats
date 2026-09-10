<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The queue outside the door when a big sale opens.
 *
 * Two thousand people press "buy" in the same second and every one of them starts drawing a seat
 * map, polling availability and taking holds. Without a door the room fills until nobody can get
 * anything, and the people who lose are not the ones who arrived late — they are the ones on the
 * slower connection. A waiting room does not create scarcity; it makes an existing scarcity
 * orderly, and tells each person where they stand.
 *
 * Two decisions are worth stating up front.
 *
 * **Arriving early is not rewarded.** Everybody who reaches the room before the doors open is in a
 * lobby, and when the doors open the lobby is *shuffled* and given places in that order. Refreshing
 * for an hour beforehand buys nothing, which is the point: a first-come queue that opens at ten
 * o'clock is a competition in who can camp on a page, and it punishes the person who was at work.
 * Anybody arriving after the doors are open joins the back, in arrival order, because by then
 * "when you arrived" really is the fair answer.
 *
 * **An admission is a lease, not a right.** Being let in gives somebody a few minutes to choose and
 * pay; when it lapses their place is given away. Without that, one person who walks away from their
 * desk holds a slot open for the rest of the sale.
 *
 * How many people are inside is never stored. It is counted — live admissions against a limit, the
 * same shape as a seat's availability and an add-on's stock — because a stored counter is a
 * read-modify-write and two doors opening in the same second would both read "one short".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Off for almost every night, and rightly: a room in front of a sale nobody is queueing
            // for is a page between a buyer and their ticket for no reason at all.
            $table->boolean('waiting_room')->default(false)->after('on_sale_at');
            // How many people may be in the shop at once. Not how many seats there are — how many
            // simultaneous buyers this venue wants choosing at the same time.
            $table->unsignedInteger('waiting_room_capacity')->default(100)->after('waiting_room');
            // How long an admission lasts before the place is given away.
            $table->unsignedSmallInteger('waiting_room_minutes')->default(10)->after('waiting_room_capacity');
        });

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // What the browser holds. In a cookie rather than a URL: a place in a queue that could
            // be pasted into a message would be a place somebody could give away or sell.
            $table->string('token', 64)->unique();
            $table->string('session_id')->nullable();
            $table->string('ip', 45)->nullable();

            $table->string('status', 10)->default('lobby'); // lobby|queued|admitted|expired|left
            /*
             * Where they stand. Null in the lobby, because before the doors open nobody has a
             * place — that is the whole design — and assigned at the draw or on arrival after it.
             */
            $table->unsignedInteger('place')->nullable();
            $table->timestampTz('joined_at');
            $table->timestampTz('admitted_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status', 'place']);
            $table->index(['event_id', 'status', 'expires_at']);
            $table->index(['tenant_id', 'event_id']);
        });

        /*
         * One place per position, per event.
         *
         * The draw hands out places under an advisory lock, and this is what makes that lock
         * trustworthy rather than merely likely: two admissions racing cannot both take number
         * fourteen, whatever the application believed it was doing.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX queue_tickets_one_place_per_event
            ON queue_tickets (event_id, place)
            WHERE place IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['waiting_room', 'waiting_room_capacity', 'waiting_room_minutes']);
        });
    }
};
