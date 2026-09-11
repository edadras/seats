<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a buyer would actually see from where they are about to sit.
 *
 * A seat plan answers "where" and a price answers "how much"; neither answers the question somebody
 * choosing between the stalls and the balcony is actually asking, which is what the stage looks like
 * from there. Until now they had nothing to look at.
 *
 * Kept out of the chart on purpose. The geometry is immutable and versioned — an event sells against
 * the version it was created with — and a photograph is not part of the seating: changing one must
 * not mean republishing a chart, and republishing a chart must not lose the photographs. So it is
 * its own table, keyed by the section's own stable key, and it follows the map rather than a version
 * of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            // The key the designer gave the section — "stalls", "balcony". Stable across
            // republishing, which is the whole reason for keying on it rather than on an id the
            // geometry regenerates.
            $table->string('section_key', 120);
            $table->string('url', 500);
            $table->string('caption', 200)->default('');
            $table->timestamps();

            // One picture per section. A gallery per section is a different feature and a worse
            // one: somebody choosing a seat wants an answer, not an album.
            $table->unique(['seat_map_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_views');
    }
};
