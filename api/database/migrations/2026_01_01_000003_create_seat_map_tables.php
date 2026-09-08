<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venues and seat maps.
 *
 * Sections, rows and seats hang off the *map*, not off a version, so a seat keeps one UUID for
 * the life of the map (ADR-0002). Per-version geometry lives in `seat_placements`: a seat that
 * disappears from a later version simply has no placement in it, while old allocations pointing
 * at that seat stay meaningful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone')->default('UTC');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('seat_maps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('venue_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('published_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'venue_id']);
        });

        Schema::create('seat_map_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft'); // draft|published
            $table->jsonb('geometry'); // layout only — never availability (ADR-0002)
            $table->unsignedInteger('seat_count')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestamps();
            $table->unique(['seat_map_id', 'version']);
            $table->index(['seat_map_id', 'status']);
        });

        Schema::create('sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['seat_map_id', 'key']);
        });

        // `seat_rows`, not `rows`: ROWS is a reserved word in several SQL dialects and the
        // quoting is easy to get wrong in a raw statement later.
        Schema::create('seat_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('sections')->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['section_id', 'key']);
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->uuid('id')->primary(); // stable for the life of the map, never reused
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignUuid('seat_row_id')->constrained('seat_rows')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->boolean('accessible')->default(false);
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();
            $table->unique(['seat_row_id', 'key']);
            $table->index(['seat_map_id']);
        });

        Schema::create('seat_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_id')->constrained()->cascadeOnDelete();
            $table->double('x');
            $table->double('y');
            $table->double('rotation')->default(0);
            $table->string('shape', 20)->default('circle');
            $table->string('zone_key')->nullable();
            $table->timestamps();
            $table->unique(['seat_map_version_id', 'seat_id']);
            $table->index(['seat_map_version_id', 'zone_key']);
        });

        Schema::table('seat_maps', function (Blueprint $table) {
            $table->foreign('published_version_id')->references('id')->on('seat_map_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('seat_maps', function (Blueprint $table) {
            $table->dropForeign(['published_version_id']);
        });
        Schema::dropIfExists('seat_placements');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('seat_rows');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('seat_map_versions');
        Schema::dropIfExists('seat_maps');
        Schema::dropIfExists('venues');
    }
};
