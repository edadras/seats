<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Everybody who came last season and has not bought this one."
 *
 * Until now an announcement went to everybody who ever bought, or to everybody coming to one
 * night. Those are the two easy audiences and neither is the one an organiser actually wants when
 * they have something to sell: the people who already love the work and have not yet booked.
 *
 * A segment is that question, written down and named, so it can be asked again next season.
 *
 * **The rules are a closed vocabulary, not a query language.** Seven kinds of clause, listed in
 * `App\Domain\Audience\Segments`, each of which the application knows how to turn into one bounded
 * SQL fragment. It is tempting to store something more general — a little expression tree, a
 * saved query — and it is the wrong trade twice over: an open query over buyer data is a way to
 * write, by accident, both the query that takes an hour and the one that reaches into a table
 * nobody meant to expose. A closed vocabulary can be indexed, explained in a sentence on the
 * screen, and translated into six languages.
 *
 * **A segment holds no people.** It is resolved when it is used and never stored as a list, for
 * the same reason availability is never stored: a list of buyers goes stale the moment somebody
 * buys, and a stale audience is somebody being written to about a show they already have tickets
 * for. The one place a resolved audience *is* written down is the delivery log of a send in
 * progress, which is deliberate and explained in AnnouncementSender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();

            // The clauses, in the closed vocabulary. Validated on the way in by the controller and
            // again by the resolver, which ignores anything it does not recognise rather than
            // guessing: a rule nobody can read is a rule that quietly widens an audience.
            $table->jsonb('rules')->default('{}');

            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // One name, one audience. Two lists called "Christmas" is a support conversation.
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('announcements', function (Blueprint $table) {
            // Which saved audience this went to, where it went to one. Kept as a reference rather
            // than a copy of the rules: an announcement is a thing that happened, and what it
            // reached is already written down as its deliveries.
            $table->foreignUuid('segment_id')->nullable()->after('event_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('segment_id');
        });

        Schema::dropIfExists('segments');
    }
};
