<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Places that are sold by quantity rather than by name: general admission areas, booths, and tables
 * booked whole.
 *
 * They sit alongside `seats` and follow the same rule — the object belongs to the map and keeps one
 * stable id for its life, while its position in a particular version lives in a placement. What
 * differs is the inventory model: nobody picks a spot, so exclusivity is a running total against a
 * capacity rather than one row per chair.
 *
 * Floors arrive here too. A chart's floors live inside its geometry, but a seat has to know which
 * floor it is on for the widget to show the right level without unpacking the whole chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_objects', function (Blueprint $table) {
            $table->uuid('id')->primary(); // stable for the life of the map, never reused
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('kind'); // area | booth | table
            // How the object is sold: any quantity up to `places`, or the whole thing at once.
            $table->string('capacity_type')->default('generalAdmission'); // generalAdmission | fixed
            $table->unsignedInteger('places')->default(1);
            $table->jsonb('attributes')->default('{}');
            $table->timestamps();
            $table->unique(['seat_map_id', 'key']);
        });

        Schema::create('capacity_placements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_map_version_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('capacity_object_id')->constrained()->cascadeOnDelete();
            $table->string('floor_key')->default('1');
            $table->jsonb('geometry'); // shape as drawn in this version
            $table->timestamps();
            $table->unique(['seat_map_version_id', 'capacity_object_id']);
        });

        Schema::create('event_capacity_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('capacity_object_id')->constrained()->cascadeOnDelete();
            $table->boolean('blocked')->default(false);
            // Null means "use the map's own capacity" — a reduced house for one night is common.
            $table->unsignedInteger('places')->nullable();
            $table->bigInteger('amount')->nullable();
            $table->string('zone_key')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'capacity_object_id']);
        });

        Schema::table('seat_placements', function (Blueprint $table) {
            $table->string('floor_key')->default('1')->after('seat_id');
        });

        // Holds and allocations gain a second shape: a quantity against a capacity object rather
        // than a claim on one named seat. Exactly one of the two columns is set.
        Schema::table('hold_items', function (Blueprint $table) {
            $table->foreignUuid('capacity_object_id')->nullable()->after('seat_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('capacity_object_id');
        });

        Schema::table('allocations', function (Blueprint $table) {
            $table->foreignUuid('capacity_object_id')->nullable()->after('seat_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1)->after('capacity_object_id');
        });

        DB::statement('ALTER TABLE hold_items ALTER COLUMN seat_id DROP NOT NULL');
        DB::statement('ALTER TABLE allocations ALTER COLUMN seat_id DROP NOT NULL');

        // A row must be one thing or the other, never both and never neither. Without this a bug
        // could write a hold that occupies nothing and is invisible to every availability query.
        DB::statement(<<<'SQL'
            ALTER TABLE hold_items ADD CONSTRAINT hold_items_seat_xor_capacity
            CHECK ((seat_id IS NULL) <> (capacity_object_id IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE allocations ADD CONSTRAINT allocations_seat_xor_capacity
            CHECK ((seat_id IS NULL) <> (capacity_object_id IS NULL))
        SQL);

        // The existing partial unique indexes on (event_id, seat_id) keep guarding named seats.
        // Capacity rows carry a NULL seat_id, and Postgres treats NULLs as distinct, so many
        // general-admission holds for one area coexist — which is exactly right. Their limit is a
        // running total, enforced under an advisory lock in HoldService.
        DB::statement(<<<'SQL'
            CREATE INDEX hold_items_capacity_active
            ON hold_items (event_id, capacity_object_id)
            WHERE released_at IS NULL AND capacity_object_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX allocations_capacity_active
            ON allocations (event_id, capacity_object_id)
            WHERE status = 'active' AND capacity_object_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS allocations_capacity_active');
        DB::statement('DROP INDEX IF EXISTS hold_items_capacity_active');
        DB::statement('ALTER TABLE allocations DROP CONSTRAINT IF EXISTS allocations_seat_xor_capacity');
        DB::statement('ALTER TABLE hold_items DROP CONSTRAINT IF EXISTS hold_items_seat_xor_capacity');

        Schema::table('allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('capacity_object_id');
            $table->dropColumn('quantity');
        });

        Schema::table('hold_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('capacity_object_id');
            $table->dropColumn('quantity');
        });

        Schema::table('seat_placements', function (Blueprint $table) {
            $table->dropColumn('floor_key');
        });

        Schema::dropIfExists('event_capacity_overrides');
        Schema::dropIfExists('capacity_placements');
        Schema::dropIfExists('capacity_objects');
    }
};
