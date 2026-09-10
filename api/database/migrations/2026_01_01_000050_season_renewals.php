<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First refusal on the same seats, for the people who sat in them last season.
 *
 * This is how a subscription house actually sells: before anything goes on general sale, everybody
 * who had seats last year is offered the same chairs for the new run, and they have until a date to
 * say yes. It is the single most valuable thing a theatre does with its inventory and the thing
 * this platform, until now, had no way to express at all — an organiser could only block the seats
 * by hand and hope to remember which of them belonged to whom.
 *
 * Three decisions, and everything here follows from them:
 *
 * **A renewal is an offer, not a booking.** Nothing is sold, nothing is charged, and no allocation
 * exists until a subscriber accepts and pays like anybody else. So the offer's whole job is to keep
 * the seats out of everybody else's reach until the deadline, and to stop doing that afterwards.
 *
 * **The seats are kept by being *derived* as held, not by writing a block somewhere.** Availability
 * and the hold service both ask this table (see their SQL), so a round that closes, or a deadline
 * that simply passes, puts the seats back on sale with nothing to sweep and nothing to forget. A
 * blocked-seat row per subscriber per night would be thousands of rows somebody has to clean up
 * correctly, and the day they do not is the day a subscriber's seat is sold to a stranger.
 *
 * **Accepting is the ordinary subscription path with the seats already chosen.** It makes one hold
 * on the first night, puts the pass and the nights in the session, and hands the buyer to the
 * season checkout that already exists. Nothing about paying for a season is reimplemented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_rounds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // Where the subscribers come from, and what they are being offered.
            $table->foreignUuid('from_series_id')->constrained('event_series')->cascadeOnDelete();
            $table->foreignUuid('to_series_id')->constrained('event_series')->cascadeOnDelete();
            // How the new run is priced for them. A renewal is a season ticket, so it is sold on a
            // season pass like any other — there is no second kind of subscription here.
            $table->foreignUuid('season_pass_id')->constrained('season_passes')->cascadeOnDelete();

            $table->string('name', 160);
            // The moment the seats go back on general sale. Read live rather than swept: the
            // guarantee is "not after this", and a guarantee that depends on a cron job having run
            // is a guarantee that fails quietly at three in the morning.
            $table->timestampTz('deadline');
            $table->string('state', 10)->default('open'); // open|closed
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'state']);
            $table->index(['to_series_id', 'state']);
        });

        /*
         * One round per pair of runs while it is open.
         *
         * Two open rounds offering the same new season would offer the same chair to two people,
         * which is the one thing this feature exists to prevent.
         */
        DB::statement(
            'CREATE UNIQUE INDEX renewal_rounds_one_open_per_run ON renewal_rounds (to_series_id) '.
            "WHERE state = 'open'"
        );

        Schema::create('renewal_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('round_id')->constrained('renewal_rounds')->cascadeOnDelete();

            // Who sat there. By address, because that is who a booking belongs to everywhere else
            // in this system, and a subscriber who bought under two references is one person.
            $table->string('email', 190);
            $table->string('name', 190)->nullable();

            // offered|accepted|declined|lapsed. `lapsed` is written when a round closes, for the
            // panel's sake; the seats stop being kept the moment the deadline passes, whether or
            // not anybody has written it down.
            $table->string('state', 10)->default('offered');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            // The subscription they bought when they accepted, where they did.
            $table->foreignUuid('season_booking_id')->nullable()
                ->constrained('season_bookings')->nullOnDelete();
            $table->timestamps();

            // One offer per person per round: two would be two claims on the same chairs.
            $table->unique(['round_id', 'email']);
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('renewal_offer_seats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('offer_id')->constrained('renewal_offers')->cascadeOnDelete();
            // The round, carried here as well as on the offer, so "one claim per chair per round"
            // is a plain unique index rather than a rule somebody has to remember.
            $table->foreignUuid('round_id')->constrained('renewal_rounds')->cascadeOnDelete();
            // The chair itself, not a chair on a night: a subscription is the same seat all season,
            // which is what makes "the same seats as last year" a sentence at all.
            $table->foreignUuid('seat_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['offer_id', 'seat_id']);
            /*
             * One claim per chair per round, said by the database.
             *
             * The service checks it too, but two offers in one round holding the same seat is
             * exactly the failure that ends with two subscribers arriving for row D seat 12, so it
             * is written where it cannot be got round.
             */
            $table->unique(['round_id', 'seat_id']);
            $table->index('seat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_offer_seats');
        Schema::dropIfExists('renewal_offers');
        Schema::dropIfExists('renewal_rounds');
    }
};
