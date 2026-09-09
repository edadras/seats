<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timed entry: the same day sold in windows, each with its own capacity.
 *
 * A museum, an exhibition, a Christmas market — anything where the constraint is not a chair but
 * how many people may be inside at once. The event runs all day; a buyer picks the half-hour they
 * will arrive, and that window fills up on its own while the ones on either side stay open.
 *
 * A slot's capacity is a number of people, not a set of rows: it is checked as a sum under a lock,
 * the same way a standing area is, because there is no chair to make unique. A slot with no
 * capacity of its own is a window that limits nothing and exists only to be printed on a ticket —
 * useful for a run with staggered arrival times and one hall.
 *
 * The slot rides on the hold rather than on each item. A booking is one arrival: a family that
 * buys four places arrives together, and a model that let one of the four come at a different time
 * would be a model that has to answer what the other three are doing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            // Optional: most windows are named by their own clock, and "10:00 – 10:30" is a label
            // the reader's locale writes better than an organiser typing it six times.
            $table->string('label', 80)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->index(['event_id', 'starts_at']);
        });

        Schema::table('holds', function (Blueprint $table) {
            $table->foreignUuid('entry_slot_id')->nullable()->constrained('entry_slots')->nullOnDelete();
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignUuid('entry_slot_id')->nullable()->constrained('entry_slots')->nullOnDelete();
            // Denormalised at sale time, like the section name beside it: an organiser who
            // rewrites tomorrow's timetable must not change what a ticket already sold says.
            $table->timestampTz('entry_starts_at')->nullable();
            $table->timestampTz('entry_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_slot_id');
            $table->dropColumn(['entry_starts_at', 'entry_ends_at']);
        });

        Schema::table('holds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('entry_slot_id');
        });

        Schema::dropIfExists('entry_slots');
    }
};
