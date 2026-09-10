<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One show, in twelve towns.
 *
 * A run of a play was already a grouping here — twenty-one nights that know they belong together —
 * and that was enough while every night was in the same building. A tour is not. It is the same
 * production in a different hall every week, with a different chart, different prices and a
 * different door, and the only things it shares are the ones an audience recognises: the name, the
 * picture on the poster and the sentence that says what it is.
 *
 * Two decisions:
 *
 * **The production carries the description and the artwork; the night carries everything else.**
 * Twelve towns should not mean twelve copies of a paragraph, each of which somebody has to
 * remember to change. A night that says nothing of its own shows the production's — and a night
 * that has something to say still wins, because the last performance of a run is sometimes a
 * different evening from the first.
 *
 * **A stop is a copy that knows it is going somewhere else.** Repeating a night in the same hall
 * can copy the blocked seats behind the pillar; the pillar is somewhere else in Glasgow. So a tour
 * date copies what describes the show — the prices whose categories exist in the new chart, the
 * concessions, the fee, the tax — and nothing that describes a room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_series', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->string('category', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('event_series', function (Blueprint $table) {
            $table->dropColumn(['description', 'image_url', 'category']);
        });
    }
};
