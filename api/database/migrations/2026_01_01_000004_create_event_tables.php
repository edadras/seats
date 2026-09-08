<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events bind a published map version to a date and a price list.
 *
 * `availability_version` is a monotonic counter bumped on every inventory change, so the widget
 * can poll incrementally with `?since=` instead of refetching every seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('venue_id')->constrained();
            $table->foreignUuid('seat_map_id')->constrained();
            $table->foreignUuid('seat_map_version_id')->nullable()->constrained('seat_map_versions');
            $table->string('public_id')->unique(); // opaque handle used by the embed widget
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft|published|closed|cancelled
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('hold_ttl_seconds')->default(600);
            $table->unsignedSmallInteger('max_extends')->default(2);
            $table->unsignedSmallInteger('max_seats_per_order')->default(10);
            $table->string('refund_policy')->default('release'); // release|hold_back
            $table->bigInteger('availability_version')->default(0);
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('event_price_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->bigInteger('amount'); // minor units; integers only, never floats
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'key']);
        });

        Schema::create('event_seat_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('seat_id')->constrained()->cascadeOnDelete();
            $table->boolean('blocked')->default(false);
            $table->bigInteger('amount')->nullable();
            $table->string('zone_key')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'seat_id']);
            $table->index(['event_id', 'blocked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_seat_overrides');
        Schema::dropIfExists('event_price_zones');
        Schema::dropIfExists('events');
    }
};
