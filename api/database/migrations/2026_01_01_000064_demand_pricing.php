<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing by how much is left, not only by when.
 *
 * Timed tiers already answered "what does the room cost this week": early bird until Friday, then
 * full price. What they could not answer is the question every box office actually asks, which is
 * how the night is going. A show that sold out in a morning was sold at the price somebody guessed
 * at in January, and a show with three hundred seats left on the day had no way of saying so.
 *
 * A ladder of steps, keyed on the percentage already sold, and two rails. The rails are the point
 * as much as the ladder: without a floor and a ceiling, a mistyped step is one character away from
 * selling the house at nothing or quoting three hundred euros for a seat in the gods, and neither
 * is a thing anybody notices until a buyer telephones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_demand_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80)->nullable();

            /*
             * The percentage sold at which this step starts.
             *
             * A floor rather than a band: the step in force is the highest one the night has
             * reached. Bands would need to meet exactly at every edge, and an edge somebody typed
             * one out is a percentage with no price at all.
             */
            $table->unsignedTinyInteger('sold_from');

            $table->string('kind', 12)->default('percent'); // percent | amount
            $table->integer('value')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One step per threshold per night: two prices at ninety per cent sold is not a
            // preference to resolve, it is a mistake to refuse.
            $table->unique(['event_id', 'sold_from']);
            $table->index(['tenant_id', 'event_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            /*
             * Off unless somebody asks for it.
             *
             * A price that moves on its own is a decision a house makes deliberately — some of them
             * are forbidden to by their funding — so the steps can exist, be edited and be looked
             * at without a single price changing until this is on.
             */
            $table->boolean('demand_pricing')->default(false);

            // The rails, in minor units. Null on either side means no rail on that side.
            $table->unsignedBigInteger('price_floor')->nullable();
            $table->unsignedBigInteger('price_ceiling')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['demand_pricing', 'price_floor', 'price_ceiling']);
        });

        Schema::dropIfExists('event_demand_steps');
    }
};
