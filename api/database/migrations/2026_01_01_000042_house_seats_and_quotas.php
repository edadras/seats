<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways of keeping part of a house back, and neither of them is "blocked".
 *
 * **House seats** are the producer's guests, the press, the artist's family, the two on the aisle
 * the venue never sells because the sound desk is behind them. Every venue has them, and until now
 * the only way to express one here was to block the seat — which is a blunt instrument, because a
 * blocked seat cannot be sold by anybody, and the whole point of a house seat is that the box
 * office can hand it over on the night.
 *
 * So a house seat is a blocked seat *with a label saying who it is being kept for*. That is not a
 * trick: it is exactly what makes the change safe. Every public path already excludes blocked
 * seats, so no page, no picker, no availability count and no embed response can accidentally start
 * offering these — the public behaviour is unchanged by construction, and one path, the counter,
 * opts in to selling them.
 *
 * **A channel quota** is the other shape of the same wish: not "these seats", but "this many". An
 * agent gets four hundred, the website gets the rest, and neither can take the other's. A channel
 * is an API client — the hosted site has one, a WooCommerce shop has one, the box office has one —
 * so the quota hangs off that and nothing new has to be invented to name a channel.
 *
 * What a channel has taken is never stored. It is counted: live holds plus live allocations, under
 * the same advisory lock on the event that the seats themselves are sold under, because a quota
 * kept in a column is a quota two simultaneous baskets both fit inside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_seat_overrides', function (Blueprint $table) {
            /*
             * Who this seat is being kept for: "Production", "Press", "Sound desk".
             *
             * Only meaningful on a blocked seat, and that is the invariant below. A label on a
             * seat that is on public sale would be a note nobody reads; a blocked seat with a
             * label is a house seat, and the box office may sell it.
             */
            $table->string('held_for', 60)->nullable()->after('blocked');
            $table->index(['event_id', 'held_for']);
        });

        // A house seat is a blocked seat with a label. Said by the database, because "these two
        // columns mean something together" is exactly what an application forgets under a deadline.
        DB::statement(<<<'SQL'
            ALTER TABLE event_seat_overrides ADD CONSTRAINT event_seat_overrides_held_for_is_blocked
            CHECK (held_for IS NULL OR blocked = true)
        SQL);

        /*
         * How much of one night a channel may sell.
         *
         * Per event and per API client, because that is what a channel is here. A row is a promise
         * an organiser made to somebody — an agent, a partner shop, their own box office — and the
         * absence of a row is the ordinary case: no limit at all.
         */
        Schema::create('channel_quotas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('api_client_id')->constrained()->cascadeOnDelete();

            // Places, not seats: a standing area sold four at a time is four places, and a quota
            // that counted it as one would let an agent sell a stadium.
            $table->unsignedInteger('places');
            $table->string('note', 160)->nullable();
            $table->timestamps();

            // One promise per channel per night. Two would be two numbers, and somebody would have
            // to decide which one meant it.
            $table->unique(['event_id', 'api_client_id']);
            $table->index(['tenant_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_quotas');

        DB::statement('ALTER TABLE event_seat_overrides DROP CONSTRAINT IF EXISTS event_seat_overrides_held_for_is_blocked');

        Schema::table('event_seat_overrides', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'held_for']);
            $table->dropColumn('held_for');
        });
    }
};
