<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people looked, so "how is it selling" can be answered with more than a number sold.
 *
 * Everything else on this platform is derived from what was sold: seats, money, holds, refunds.
 * None of it can answer the question an organiser actually asks a week before the doors open —
 * *is this going badly, or is nobody hearing about it?* Two hundred tickets sold out of a thousand
 * people who looked is a pricing problem. Two hundred out of two hundred and twelve is a marketing
 * one, and they have opposite remedies.
 *
 * So one number is written down that nothing else could reconstruct: how many times an event's
 * page was opened. A count per event, per day, per source — not per visitor. There is no cookie,
 * no identifier, no row that belongs to a person, and nothing here to export or erase under a
 * subject access request, because nothing here is about a subject. A GDPR erasure takes a buyer's
 * orders with it and leaves this table untouched, which is correct: it never knew who they were.
 *
 * Counted rather than sampled, and a `views` column rather than a row per view: a row per view is
 * a table that outgrows the orders it exists to explain, and this platform already refuses to
 * store what it can count. The one thing it cannot count is a request that left no trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            // The day the looking happened, in the account's own timezone: an organiser comparing
            // Tuesday with Wednesday means their Tuesday, not one that ends at midnight UTC.
            $table->date('day');

            // Where they were looking: the organiser's own site, or a picker embedded on somebody
            // else's. Kept apart because they answer different questions — a shop that sends no
            // traffic and a site that converts nothing are not the same problem.
            $table->string('source', 16); // site|embed

            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            // One row per event per day per source. The count is raised in place, which is what
            // makes this a counter and not a log.
            $table->unique(['event_id', 'day', 'source']);
            $table->index(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_views');
    }
};
