<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One production, many nights.
 *
 * A play that runs for three weeks is twenty-one events on this platform, and it should be: each
 * night has its own hall, its own inventory and its own tickets, and pretending otherwise is how a
 * seat sold on Tuesday turns up as sold on Wednesday.
 *
 * What was missing is the thing that ties them together. Without it a programme page is twenty-one
 * identical cards, and a visitor looking for "which night can I come" has to read all of them.
 *
 * The series holds nothing an event does not: it is a name and a grouping. Everything that decides
 * what a night costs and where people sit stays on the night itself, so one performance can be
 * repriced, sold out, or cancelled without touching the other twenty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('slug', 120);
            $table->timestamps();

            // One slug per account: the series page is addressed by it.
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('series_id')->nullable()->after('venue_id')
                ->constrained('event_series')->nullOnDelete();
            $table->index(['tenant_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('series_id');
        });

        Schema::dropIfExists('event_series');
    }
};
