<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a ticket costs today, as opposed to what it will cost next month.
 *
 * Nearly every organiser on this platform already does this by hand: they set an early price, put a
 * note in their calendar, and on the morning of the deadline they open the pricing screen and type
 * new numbers. What that costs them is obvious the first time somebody forgets — a fortnight of
 * early-bird seats sold at a price the show could not afford.
 *
 * Three decisions:
 *
 * **A tier is a window and an adjustment, not a second price list.** An organiser who has priced
 * six zones does not want to price them six more times per tier; they want "everything is twenty
 * per cent less until March". So a tier says how much more or less, and the zone prices stay the
 * one place a price is written. A house that really does want an unrelated number for one zone
 * still has the per-seat and per-zone overrides that were always there.
 *
 * **Which tier is in force is derived from the clock, never stored.** There is no "current tier"
 * column and nothing to switch over at midnight: the price a buyer is quoted is worked out from
 * the tier whose window contains this moment, in the same query that decides whether the seat is
 * free. A missed job cannot sell tomorrow's seats at yesterday's price, because no job exists.
 *
 * **Windows may not overlap.** Two tiers covering one moment is two answers to "what does this
 * cost", and picking one by sort order would be picking one by accident. The domain refuses the
 * overlap when it is saved, which is the only place it can be explained to the person who caused
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_price_tiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);

            // Null at either end means "from the beginning" and "until the doors open" — the two
            // ends an organiser most often means and least often wants to have to type.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            // percent: a signed whole percentage of the zone price. amount: signed minor units.
            $table->string('kind', 16)->default('percent');
            $table->integer('value')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['event_id', 'starts_at']);
            $table->unique(['event_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_price_tiers');
    }
};
